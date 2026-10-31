#!/usr/bin/env python3
"""把混淆后的 Dart 崩溃堆栈还原成「函数名 + 文件:行号」。

用法:
  python3 tools/symbolize.py <Bugsink 事件/问题链接或 ID>
  python3 tools/symbolize.py <日志文件>          # 文件里有 build_id: '…' 和 #00 abs … 那段
  pbpaste | python3 tools/symbolize.py -
  python3 tools/symbolize.py event.json          # 存下来的事件 JSON

对照表从哪来:构建时按构建号加密存进本仓库发布页 symbols-<年-月>(见 build.yaml 的
archive-symbols);找不到再翻还没过期的 symbols-* 构建产物(老格式也认)。下载解密后缓存在
~/.cache/apex-symbols/<构建号>/。解密要维护者私钥 ~/.config/apex/symbols_key.pem。

依赖:gh(已登录能读本仓库)、openssl、llvm-symbolizer(安卓 NDK 里就有;macOS 的对照表
没有它时退回系统自带的 atos)。
"""
import json
import os
import re
import shutil
import struct
import subprocess
import sys
import tarfile
import tempfile
import urllib.request

REPO = os.environ.get('APEX_SYMBOLS_REPO', 'dreamrer/mihomo-build')
CACHE = os.path.expanduser(os.environ.get('APEX_SYMBOLS_CACHE', '~/.cache/apex-symbols'))
KEY = os.path.expanduser(os.environ.get('APEX_SYMBOLS_KEY', '~/.config/apex/symbols_key.pem'))
BUGSINK = os.environ.get('BUGSINK_URL', 'https://errors.quasarapp.net')
BUGSINK_TOKEN = os.path.expanduser('~/.bugsink_read_token')
UUID_RE = re.compile(r'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')


def die(msg):
    print('❌ ' + msg, file=sys.stderr)
    sys.exit(1)


# ─── 构建号(和 build.yaml 里 sym_ids.py 同一段逻辑,改一边要改另一边) ─────────────

def build_id(path):
    d = open(path, 'rb').read()
    if d[:4] in (b'\xcf\xfa\xed\xfe', b'\xce\xfa\xed\xfe'):  # Mach-O:LC_UUID
        ncmds, = struct.unpack_from('<I', d, 16)
        p = 32 if d[0] == 0xcf else 28
        for _ in range(ncmds):
            cmd, size = struct.unpack_from('<II', d, p)
            if cmd == 0x1b:
                return d[p + 8:p + 24].hex()
            p += size
        return None
    if d[:4] != b'\x7fELF':  # ELF:GNU build-id
        return None
    is64 = d[4] == 2
    le = '<' if d[5] == 1 else '>'
    if is64:
        shoff, = struct.unpack_from(le + 'Q', d, 0x28)
        es, n = struct.unpack_from(le + 'HH', d, 0x3A)
    else:
        shoff, = struct.unpack_from(le + 'I', d, 0x20)
        es, n = struct.unpack_from(le + 'HH', d, 0x2E)
    for i in range(n):
        o = shoff + i * es
        typ, = struct.unpack_from(le + 'I', d, o + 4)
        if typ != 7:
            continue
        off, size = struct.unpack_from(le + ('QQ' if is64 else 'II'), d, o + (0x18 if is64 else 0x10))
        p = off
        while p < off + size:
            nsz, dsz, nt = struct.unpack_from(le + 'III', d, p)
            p += 12
            name = d[p:p + nsz]
            p += (nsz + 3) & ~3
            desc = d[p:p + dsz]
            p += (dsz + 3) & ~3
            if nt == 3 and name.rstrip(b'\0') == b'GNU':
                return desc.hex()
    return None


# ─── 读输入 ──────────────────────────────────────────────────────────────────

def bugsink_get(path):
    if not os.path.exists(BUGSINK_TOKEN):
        die('没有 Bugsink 只读令牌 ' + BUGSINK_TOKEN)
    tok = open(BUGSINK_TOKEN).read().strip()
    req = urllib.request.Request(BUGSINK + '/api/canonical/0' + path, headers={
        'Authorization': 'Bearer ' + tok, 'User-Agent': 'curl/8'})  # 默认 UA 会被 CF 拦
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.load(r)


def load_event(text):
    """Bugsink 链接 / ID → 事件 data。链接里有事件 ID 就用它,否则当问题 ID 取最新一条。"""
    ids = UUID_RE.findall(text.lower())
    for i in reversed(ids):
        try:
            return bugsink_get('/events/%s/' % i)['data']
        except Exception:
            pass
    for i in ids:
        try:
            evs = bugsink_get('/events/?issue=%s' % i)['results']
        except Exception:
            continue
        if evs:
            return bugsink_get('/events/%s/' % evs[0]['id'])['data']
    die('Bugsink 里找不到这个事件/问题: ' + text.strip())


def frames_from_event(ev):
    """→ [(标题, build_id, [(序号, 相对地址 或 None, 原文)])],最新的一层在前。"""
    images = {}
    for im in ((ev.get('debug_meta') or {}).get('images') or []):
        cid = (im.get('code_id') or '').lower().replace('-', '')
        if cid and im.get('image_addr'):
            images[cid] = int(im['image_addr'], 16)
    if not images:
        die('这个事件没有 debug_meta(不是混淆版本,或者不是 Dart 堆栈),不用还原')
    bid, base = next(iter(images.items()))
    out = []
    stacks = []
    for v in ((ev.get('exception') or {}).get('values') or []):
        stacks.append(('%s: %s' % (v.get('type'), (v.get('value') or '')[:200]), v.get('stacktrace')))
    for t in ((ev.get('threads') or {}).get('values') or []):
        stacks.append(('线程 %s' % (t.get('name') or t.get('id') or ''), t.get('stacktrace')))
    for title, st in stacks:
        fr = list(reversed((st or {}).get('frames') or []))  # Sentry 是最早的在前
        rows = []
        for n, f in enumerate(fr):
            if f.get('instruction_addr'):
                rows.append((n, int(f['instruction_addr'], 16) - base, f['instruction_addr']))
            else:
                rows.append((n, None, f.get('function') or f.get('abs_path') or '?'))
        if any(r[1] is not None for r in rows):
            out.append((title, bid, rows))
    if not out:
        die('事件里没有带地址的堆栈')
    return out


FRAME_RE = re.compile(r'#(\d+)\s+abs\s+([0-9a-fA-F]+)(?:\s+virt\s+([0-9a-fA-F]+))?')


def frames_from_text(text):
    """原始 Dart 堆栈(日志里 *** *** *** 开头那段)。一份日志里可能有好几段。"""
    out = []
    cur = None
    for line in text.splitlines():
        m = re.search(r"build_id:\s*'([0-9a-fA-F]+)'", line)
        if m:
            cur = {'bid': m.group(1).lower(), 'base': None, 'rows': []}
            out.append(cur)
            continue
        if cur is None:
            continue
        m = re.search(r'isolate_dso_base:\s*([0-9a-fA-F]+)', line)
        if m:
            cur['base'] = int(m.group(1), 16)
            continue
        m = FRAME_RE.search(line)
        if m:
            n, ab, virt = int(m.group(1)), int(m.group(2), 16), m.group(3)
            rel = int(virt, 16) if virt else (ab - cur['base'] if cur['base'] is not None else None)
            cur['rows'].append((n, rel, line.strip()))
        elif '<asynchronous suspension>' in line:
            cur['rows'].append((len(cur['rows']), None, '<asynchronous suspension>'))
    out = [('堆栈 %d' % (i + 1), s['bid'], s['rows']) for i, s in enumerate(out) if s['rows']]
    if not out:
        die("没找到 build_id: '…' 和 #00 abs … 这样的混淆堆栈")
    return out


# ─── 找对照表 ────────────────────────────────────────────────────────────────

def gh(*args, binary=False):
    r = subprocess.run(['gh'] + list(args), capture_output=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr.decode(errors='replace').strip())
    return r.stdout if binary else r.stdout.decode()


def decrypt(enc, keyenc, dest_dir):
    if not os.path.exists(KEY):
        die('没有私钥 %s,解不开对照表' % KEY)
    ki = subprocess.run(['openssl', 'pkeyutl', '-decrypt', '-inkey', KEY, '-in', keyenc],
                        capture_output=True, check=True).stdout.decode().split()
    tgz = enc + '.tgz'
    subprocess.run(['openssl', 'enc', '-d', '-aes-256-cbc', '-K', ki[0], '-iv', ki[1],
                    '-in', enc, '-out', tgz], check=True)
    with tarfile.open(tgz) as t:
        for m in t.getmembers():  # 只要普通文件,防路径穿越
            if m.isfile() and not m.name.startswith('/') and '..' not in m.name.split('/'):
                t.extract(m, dest_dir)
    os.remove(tgz)


def cached(bid):
    d = os.path.join(CACHE, bid)
    for root, _, files in os.walk(d):
        for f in files:
            if f.endswith('.symbols'):
                return os.path.join(root, f)
    return None


def stash(path):
    """把一份解开的对照表按它自己的构建号放进缓存。"""
    bid = build_id(path)
    if not bid:
        return
    d = os.path.join(CACHE, bid)
    if not cached(bid):
        os.makedirs(d, exist_ok=True)
        shutil.copy2(path, os.path.join(d, os.path.basename(path)))


def fetch_from_releases(bid, tmp):
    rels = json.loads(gh('api', 'repos/%s/releases?per_page=100' % REPO))
    for rel in rels:
        if not rel['tag_name'].startswith('symbols-'):
            continue
        names = {a['name'] for a in rel.get('assets', [])}
        if bid + '.tgz.enc' in names:
            gh('release', 'download', rel['tag_name'], '-R', REPO, '-D', tmp, '--clobber',
               '-p', bid + '.tgz.enc', '-p', bid + '.key.enc')
            out = os.path.join(tmp, 'x')
            decrypt(os.path.join(tmp, bid + '.tgz.enc'), os.path.join(tmp, bid + '.key.enc'), out)
            for root, _, files in os.walk(out):
                for f in files:
                    stash(os.path.join(root, f))
            return True
    return False


def fetch_from_artifacts(tmp):
    """还没存进发布页的(存档失败 / 老构建):翻 symbols-* 产物,全部解开进缓存。"""
    arts = json.loads(gh('api', 'repos/%s/actions/artifacts?per_page=100' % REPO))['artifacts']
    for a in arts:
        if a.get('expired') or not a['name'].startswith('symbols-'):
            continue
        d = os.path.join(tmp, str(a['id']))
        os.makedirs(d, exist_ok=True)
        z = os.path.join(d, 'a.zip')
        open(z, 'wb').write(gh('api', 'repos/%s/actions/artifacts/%d/zip' % (REPO, a['id']), binary=True))
        subprocess.run(['unzip', '-oq', z, '-d', d], check=True)
        pairs = []
        if os.path.exists(os.path.join(d, 'symbols.tgz.enc')):  # 老格式:整个目录一份
            pairs.append(('symbols.tgz.enc', 'symbols.key.enc'))
        pairs += [(f, f[:-len('.tgz.enc')] + '.key.enc') for f in os.listdir(d)
                  if f.endswith('.tgz.enc') and f != 'symbols.tgz.enc']
        for enc, key in pairs:
            out = os.path.join(d, 'x')
            try:
                decrypt(os.path.join(d, enc), os.path.join(d, key), out)
            except Exception as e:
                print('⚠️ 产物 %s 解不开: %s' % (a['name'], e), file=sys.stderr)
                continue
            for root, _, files in os.walk(out):
                for f in files:
                    if f.endswith('.symbols'):
                        stash(os.path.join(root, f))


def symbols_for(bid):
    p = cached(bid)
    if p:
        return p
    with tempfile.TemporaryDirectory() as tmp:
        try:
            fetch_from_releases(bid, tmp)
        except Exception as e:
            print('⚠️ 查发布页失败: %s' % e, file=sys.stderr)
        p = cached(bid)
        if p:
            return p
        try:
            fetch_from_artifacts(tmp)
        except Exception as e:
            print('⚠️ 查构建产物失败: %s' % e, file=sys.stderr)
    return cached(bid)


# ─── 还原 ────────────────────────────────────────────────────────────────────

def find_symbolizer():
    for c in [os.environ.get('LLVM_SYMBOLIZER'), shutil.which('llvm-symbolizer')]:
        if c and os.path.exists(c):
            return c
    roots = [os.path.expanduser('~/Library/Android/sdk/ndk'), os.path.expanduser('~/Android/Sdk/ndk'),
             os.environ.get('ANDROID_NDK_HOME') or '', '/opt/homebrew/opt/llvm/bin', '/usr/local/opt/llvm/bin']
    found = []
    for r in roots:
        for root, _, files in os.walk(r) if r and os.path.isdir(r) else []:
            if 'llvm-symbolizer' in files:
                found.append(os.path.join(root, 'llvm-symbolizer'))
    return sorted(found)[-1] if found else None


STRIP = re.compile(r'^.*?/mihomo-build/mihomo-build/')


def lookup(sym_path, addrs):
    """addrs: 相对地址列表 → 每个地址一组 [(函数, 位置)](内联的在前)。"""
    tool = find_symbolizer()
    if tool:
        r = subprocess.run([tool, '--obj=' + sym_path, '--inlining', '--functions=linkage']
                           + ['0x%x' % a for a in addrs], capture_output=True, text=True)
        blocks = r.stdout.strip('\n').split('\n\n')
        res = []
        for b in blocks:
            ls = [x for x in b.split('\n') if x]
            res.append([(ls[i], STRIP.sub('', ls[i + 1]) if i + 1 < len(ls) else '?')
                        for i in range(0, len(ls), 2)])
        return res + [[('?', '?')]] * (len(addrs) - len(res))
    if open(sym_path, 'rb').read(4) in (b'\xcf\xfa\xed\xfe', b'\xce\xfa\xed\xfe'):
        arch = 'arm64' if 'arm64' in sym_path else 'x86_64'
        r = subprocess.run(['atos', '-o', sym_path, '-arch', arch, '-l', '0', '-i']
                           + ['0x%x' % a for a in addrs], capture_output=True, text=True)
        # atos -i 每个地址可能多行(内联),地址之间没分隔;按一行一个地址近似。
        return [[(x, '')] for x in r.stdout.strip().split('\n')]
    die('找不到 llvm-symbolizer(装 Android NDK 或 brew install llvm,或设 LLVM_SYMBOLIZER)')


def main():
    if len(sys.argv) != 2:
        print(__doc__)
        sys.exit(2)
    arg = sys.argv[1]
    if arg == '-':
        text = sys.stdin.read()
    elif os.path.isfile(arg):
        text = open(arg, encoding='utf-8', errors='replace').read()
    else:
        text = arg
    if text.lstrip().startswith('{'):  # 存下来的事件 JSON(Bugsink 事件详情或其中的 data)
        ev = json.loads(text)
        stacks = frames_from_event(ev.get('data', ev))
    elif "build_id:" in text and FRAME_RE.search(text):
        stacks = frames_from_text(text)
    elif UUID_RE.search(text.lower()):
        stacks = frames_from_event(load_event(text))
    else:
        die('看不懂输入:要么是 Bugsink 链接/ID,要么是带 build_id 和 #00 abs … 的堆栈')

    for title, bid, rows in stacks:
        print('\n=== %s   (构建号 %s)' % (title, bid))
        sym = symbols_for(bid)
        if not sym:
            print('  ❌ 找不到这个构建号的对照表(发布页和未过期产物里都没有)')
            continue
        # 除了最顶上一层,其它都是返回地址,减 1 才落在调用那一行。
        addrs = [(r[1] - 1 if i > 0 else r[1]) for i, r in enumerate(rows) if r[1] is not None]
        res = iter(lookup(sym, addrs))
        for n, rel, raw in rows:
            if rel is None:
                print('      ' + raw)
                continue
            for k, (fn, loc) in enumerate(next(res)):
                print(('  #%02d ' % n if k == 0 else '      ↳ ') + fn + ('  (%s)' % loc if loc else ''))


if __name__ == '__main__':
    main()
