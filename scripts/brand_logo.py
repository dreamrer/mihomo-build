"""机场主上传的 logo → 各平台图标。

一条规则对所有机场主:**先把任何上传图整理成一张"铺满的方图"**(背景色画满整张、
没有留白、没有自带圆角),各平台再从这张方图按自己的规矩生成 —— 形状交给平台:

  · 安卓自适应图标:方图占画布 68%(刚好盖住启动器可见区,圆形遮罩也基本不裁内容),
    背景取方图边缘色;启动器自己按圆 / 圆角 / 水滴裁。
  · macOS:苹果模板(1024 画布里 824 的圆角方块,圆角 185,四周透明)。macOS 不会替你裁。
  · Windows / iOS / Play 封面 / App 内:直接用方图(App 内 logo 控件自己加 26% 圆角)。

整理规则(normalize):
  · 四角颜色各不相同 = 本来就是满幅作品 → 原样(非正方形补成正方形)。
  · 四角是透明或同一种颜色 → 从四角往里"漫水",和四角连通的底色 = 背景
    (色块内部的白字、白色图形不算背景):
      - 剩下的内容近似正方形且几乎填满外框(≥85%)= 圆角色块 → 取出色块,色块圆角外和
        四周用色块边缘色补满 → 方图。(之前按"透明底 + 自己做好圆角"要求上传的都是这种;
        以前安卓上会多出一圈白边。)
      - 否则是"图形":背景是某种实色 → 本来就是满幅方图,原样;背景透明 → 垫底色
        (品牌主色,没有就白色),图形太大 / 太小时缩放到安全区内。

纯 PIL,无其他依赖。本地自测:python3 scripts/brand_logo.py preview <logo...>
"""
from PIL import Image, ImageChops, ImageDraw, ImageFilter

SIDE = 1024
ANDROID_FG_SCALE = 0.68     # 自适应图标:方图占前景画布比例
TILE_FILL_MIN = 0.75        # 内容占外框 ≥75% 且近似正方形 = 色块(含圆形 π/4≈0.785、大圆角)
GRAPHIC_MAX = 0.70          # 透明底图形:最大边不超过方图 70%(安全区)
GRAPHIC_MIN = 0.45          # 太小的图形放大到至少 45%


def _corners(im):
    w, h = im.size
    return [im.getpixel((0, 0)), im.getpixel((w - 1, 0)),
            im.getpixel((0, h - 1)), im.getpixel((w - 1, h - 1))]


def _corner_kind(im):
    """'transparent' / (r,g,b) 同色底 / None 四角不同色(满幅作品)。"""
    cs = _corners(im)
    if all(c[3] <= 16 for c in cs):
        return 'transparent'
    a = cs[0]
    if all(c[3] > 200 and all(abs(a[i] - c[i]) <= 24 for i in range(3)) for c in cs):
        return (a[0], a[1], a[2])
    return None


def _content_mask(im, kind):
    """和四角连通的底 = 背景(0),其余 = 内容(255)。

    先按"像不像底"做成黑白二值图,再从四角漫水。不用"把透明像素涂成某个哨兵色再按 RGB
    漫水"的做法:内容恰好是那个颜色(纯亮绿 / 品红)时会被一起淹成背景,整个 logo 被抹掉
    。透明底只把 alpha>128 算内容:设计软件导出的半透明投影不算色块本体。"""
    w, h = im.size
    if kind == 'transparent':
        binary = im.getchannel('A').point(lambda v: 255 if v > 128 else 0)
    else:
        rgb = im.convert('RGB')
        diff = ImageChops.difference(rgb, Image.new('RGB', im.size, kind))
        r, g, b = diff.split()
        summed = ImageChops.add(ImageChops.add(r, g), b)
        binary = summed.point(lambda v: 255 if v > 60 else 0)
        alpha_bg = im.getchannel('A').point(lambda v: 255 if v > 16 else 0)
        binary = ImageChops.multiply(binary, alpha_bg)
    binary = binary.copy()
    for xy in [(0, 0), (w - 1, 0), (0, h - 1), (w - 1, h - 1)]:
        if binary.getpixel(xy) == 0:
            ImageDraw.floodfill(binary, xy, 128, thresh=0)
    return binary.point(lambda v: 0 if v == 128 else 255)


def edge_color(img, mask=None, inset=0.04):
    """外缘一圈(内缩 4%)的平均色;有 mask 时只统计内容像素。"""
    im = img.convert('RGBA')
    w, h = im.size
    d = max(1, int(min(w, h) * inset))
    d = min(d, max(0, (min(w, h) - 1) // 2))   # 1 像素宽的图也别越界
    px = im.load()
    mp = mask.load() if mask is not None else None
    acc, n = [0, 0, 0], 0
    pts = [(x, y) for x in range(w) for y in (d, h - 1 - d)] + \
          [(x, y) for y in range(h) for x in (d, w - 1 - d)]
    for (x, y) in pts:
        if mp is not None and mp[x, y] <= 128:
            continue
        p = px[x, y]
        if p[3] <= 16:
            continue
        acc = [acc[i] + p[i] for i in range(3)]
        n += 1
    return tuple(int(v / n) for v in acc) if n else (255, 255, 255)


def _fill_toward_center(tile, mask, work=512):
    """色块圆角外的缝隙:沿着指向中心的方向找到最近的色块像素,用它的颜色补。
    渐变色块也能自然接上(用平均色补会在原圆角处留一圈色差弧线)。

    色块边上那圈抗锯齿像素常混着原底色(白底上发白),先把色块边缘往里收约 1%,
    这圈也当缝隙一起补,否则圆角处 / 整圈边上会留一道浅色细线。"""
    w, h = tile.size
    k = max(3, (min(w, h) // 80) | 1)
    inner = mask.point(lambda v: 255 if v > 200 else 0).filter(ImageFilter.MinFilter(k))
    small = tile.convert('RGBA').resize((work, work), Image.LANCZOS)
    sm = inner.resize((work, work), Image.NEAREST)
    px, mp = small.load(), sm.load()
    filled = small.copy()
    fp = filled.load()
    c = (work - 1) / 2.0
    for y in range(work):
        for x in range(work):
            if mp[x, y] > 128:
                continue
            dx, dy = c - x, c - y
            dist = max(abs(dx), abs(dy))
            if dist == 0:
                continue
            sx, sy = dx / dist, dy / dist
            fx, fy = float(x), float(y)
            for _ in range(int(dist)):
                fx += sx
                fy += sy
                ix, iy = int(round(fx)), int(round(fy))
                if mp[ix, iy] > 128:
                    p = px[ix, iy]
                    fp[x, y] = (p[0], p[1], p[2], 255)
                    break
    out = filled.resize((w, h), Image.LANCZOS)
    out.paste(tile, (0, 0), inner)
    flat = Image.new('RGBA', (w, h), edge_color(tile, inner) + (255,))
    flat.alpha_composite(out)
    return flat


def _square(im, fill):
    w, h = im.size
    if w == h:
        return im
    s = max(w, h)
    out = Image.new('RGBA', (s, s), fill + (255,))
    out.paste(im, ((s - w) // 2, (s - h) // 2), im)
    return out


def _parse_hex(c):
    c = (c or '').strip().lstrip('#')
    if len(c) == 6:
        try:
            return tuple(int(c[i:i + 2], 16) for i in (0, 2, 4))
        except ValueError:
            return None
    return None


def normalize(img, brand_color=None):
    """任何上传图 → (1024 铺满方图 RGBA 不透明, 说明 dict)。"""
    im = img.convert('RGBA')
    if im.size[0] > 2048 or im.size[1] > 2048:   # 漫水是纯 python 循环,大图先缩
        im.thumbnail((2048, 2048), Image.LANCZOS)
    kind = _corner_kind(im)
    if kind is None:
        sq = _square(im, edge_color(im))
        return _finish(sq), {'kind': 'fullbleed'}

    mask = _content_mask(im, kind)
    bb = mask.getbbox()
    if not bb:
        raise ValueError('logo 里找不到任何图形(整张是同一种颜色或全透明),请换一张 logo')
    bw, bh = bb[2] - bb[0], bb[3] - bb[1]
    hist = mask.crop(bb).histogram()
    fill_ratio = sum(hist[128:]) / float(bw * bh)

    covers = max(bw, bh) >= 0.8 * max(im.size)
    if 0.9 <= bw / float(bh) <= 1.1 and fill_ratio >= TILE_FILL_MIN and covers:
        tile, tm = im.crop(bb), mask.crop(bb)
        hole = _pick_background(tile, None)
        solid = Image.new('RGBA', tile.size, hole + (255,))
        solid.alpha_composite(tile.convert('RGBA'))
        col = edge_color(solid, tm)
        sq = _fill_toward_center(solid, tm)
        return _finish(_square(sq, col)), {'kind': 'tile', 'edge': col}

    if kind != 'transparent':
        side = max(im.size)
        ratio = max(bw, bh) / float(side)
        if GRAPHIC_MIN <= ratio <= GRAPHIC_MAX:
            return _finish(_square(im, kind)), {'kind': 'graphic_on_color', 'bg': kind}
        target = min(max(ratio, GRAPHIC_MIN), GRAPHIC_MAX)
        scale = target * SIDE / max(bw, bh)
        g = im.crop(bb).resize((max(1, int(bw * scale)), max(1, int(bh * scale))), Image.LANCZOS)
        sq = Image.new('RGBA', (SIDE, SIDE), kind + (255,))
        sq.alpha_composite(g, ((SIDE - g.size[0]) // 2, (SIDE - g.size[1]) // 2))
        return sq, {'kind': 'graphic_on_color', 'bg': kind}

    graphic = im.crop(bb)
    bg = _pick_background(graphic, _parse_hex(brand_color))
    side = max(im.size)
    ratio = max(bw, bh) / float(side)
    target = min(max(ratio, GRAPHIC_MIN), GRAPHIC_MAX)
    scale = target * SIDE / max(bw, bh)
    g = graphic.resize((max(1, int(bw * scale)), max(1, int(bh * scale))), Image.LANCZOS)
    sq = Image.new('RGBA', (SIDE, SIDE), bg + (255,))
    sq.alpha_composite(g, ((SIDE - g.size[0]) // 2, (SIDE - g.size[1]) // 2))
    return sq, {'kind': 'graphic_transparent', 'bg': bg}


def _luminance(c):
    def ch(v):
        v /= 255.0
        return v / 12.92 if v <= 0.03928 else ((v + 0.055) / 1.055) ** 2.4
    return 0.2126 * ch(c[0]) + 0.7152 * ch(c[1]) + 0.0722 * ch(c[2])


def contrast(a, b):
    la, lb = _luminance(a), _luminance(b)
    hi, lo = max(la, lb), min(la, lb)
    return (hi + 0.05) / (lo + 0.05)


def _pick_background(graphic, brand):
    """透明底图形的垫底色:品牌主色 → 白 → 深灰,取第一个和图形对比度 ≥3 的。
    (品牌色和图形同色时直接垫品牌色,图形就看不见了。)"""
    px = graphic.load()
    w, h = graphic.size
    acc, n = [0, 0, 0], 0
    step = max(1, min(w, h) // 128)
    for y in range(0, h, step):
        for x in range(0, w, step):
            p = px[x, y]
            if p[3] > 128:
                acc = [acc[i] + p[i] for i in range(3)]
                n += 1
    g = tuple(v // n for v in acc) if n else (0, 0, 0)
    for c in [brand, (255, 255, 255), (31, 41, 55)]:
        if c is not None and contrast(c, g) >= 3.0:
            return c
    return (255, 255, 255)


def _finish(sq):
    sq = sq.resize((SIDE, SIDE), Image.LANCZOS)
    flat = Image.new('RGBA', sq.size, edge_color(sq) + (255,))
    flat.alpha_composite(sq)
    return flat



def android_adaptive_foreground(sq, canvas):
    """自适应图标前景:整块画布铺边缘色,方图占 68% 居中。"""
    col = edge_color(sq)
    fg = Image.new('RGBA', (canvas, canvas), col + (255,))
    inner = int(canvas * ANDROID_FG_SCALE)
    t = sq.resize((inner, inner), Image.LANCZOS)
    off = (canvas - inner) // 2
    fg.paste(t, (off, off), t)
    return fg


def android_background_hex(sq):
    return '#%02X%02X%02X' % edge_color(sq)


def macos_icon(sq, px):
    """苹果模板:1024 画布里 824 的圆角方块(圆角 185),四周透明。"""
    s = SIDE
    body, radius = 824, 185
    canvas = Image.new('RGBA', (s, s), (0, 0, 0, 0))
    t = sq.resize((body, body), Image.LANCZOS)
    m = Image.new('L', (body, body), 0)
    ImageDraw.Draw(m).rounded_rectangle((0, 0, body - 1, body - 1), radius=radius, fill=255)
    off = (s - body) // 2
    canvas.paste(t, (off, off), m)
    return canvas.resize((px, px), Image.LANCZOS)


def windows_icon(sq, px=1024):
    """Windows 不替你裁形状(和 macOS 一样):圆角方块、四角透明,不留边距(任务栏图标本来就小)。"""
    return rounded(sq, px, 0.2)


def rounded(sq, px, radius_ratio=0.25):
    t = sq.resize((px, px), Image.LANCZOS)
    m = Image.new('L', (px, px), 0)
    ImageDraw.Draw(m).rounded_rectangle((0, 0, px - 1, px - 1), radius=int(px * radius_ratio), fill=255)
    out = Image.new('RGBA', (px, px), (0, 0, 0, 0))
    out.paste(t, (0, 0), m)
    return out



def _launcher(fg, bg_hex, shape, size=200):
    w = fg.size[0]
    col = tuple(int(bg_hex[i:i + 2], 16) for i in (1, 3, 5))
    base = Image.new('RGBA', (w, w), col + (255,))
    base.alpha_composite(fg)
    v = int(w * 72 / 108)
    o = (w - v) // 2
    c = base.crop((o, o, o + v, o + v)).resize((size, size), Image.LANCZOS)
    m = Image.new('L', (size, size), 0)
    d = ImageDraw.Draw(m)
    if shape == 'circle':
        d.ellipse((0, 0, size - 1, size - 1), fill=255)
    else:
        d.rounded_rectangle((0, 0, size - 1, size - 1), radius=int(size * 0.23), fill=255)
    out = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    out.paste(c, (0, 0), m)
    return out


def preview(items, out_path, font_path=None):
    """items: [(标题, 原图)] → 一行一个 logo:原图 / 整理后 / 安卓圆角 / 安卓圆形 / macOS / App 内。"""
    from PIL import ImageFont
    cols = ['上传原图', '整理后方图', '安卓·圆角', '安卓·圆形', 'macOS', 'App内登录页']
    cell, pad, head = 200, 20, 50
    W = pad + len(cols) * (cell + pad) + 180
    H = head + len(items) * (cell + pad) + pad
    board = Image.new('RGBA', (W, H), (46, 50, 58, 255))
    d = ImageDraw.Draw(board)
    font = ImageFont.truetype(font_path, 20) if font_path else None
    for i, c in enumerate(cols):
        d.text((180 + pad + i * (cell + pad), 15), c, fill=(230, 230, 230), font=font)
    checker = Image.new('RGBA', (cell, cell), (200, 200, 200, 255))
    cd = ImageDraw.Draw(checker)
    for y in range(0, cell, 20):
        for x in range(0, cell, 20):
            if (x + y) // 20 % 2:
                cd.rectangle((x, y, x + 19, y + 19), fill=(160, 160, 160, 255))
    for r, (title, img) in enumerate(items):
        y = head + r * (cell + pad)
        d.text((pad, y + cell // 2 - 10), title, fill=(230, 230, 230), font=font)
        sq, info = normalize(img)
        fg = android_adaptive_foreground(sq, 432)
        bgh = android_background_hex(sq)
        orig = checker.copy()
        o = img.convert('RGBA').copy()
        o.thumbnail((cell, cell), Image.LANCZOS)
        orig.alpha_composite(o, ((cell - o.size[0]) // 2, (cell - o.size[1]) // 2))
        mac = checker.copy()
        mac.alpha_composite(macos_icon(sq, cell))
        tiles = [orig, sq.resize((cell, cell), Image.LANCZOS), _launcher(fg, bgh, 'rr', cell),
                 _launcher(fg, bgh, 'circle', cell), mac, rounded(sq, 132, 0.26)]
        for i, t in enumerate(tiles):
            x = 180 + pad + i * (cell + pad)
            if t.size[0] < cell:
                bgc = Image.new('RGBA', (cell, cell), (255, 255, 255, 255))
                bgc.alpha_composite(t, ((cell - t.size[0]) // 2, (cell - t.size[1]) // 2))
                t = bgc
            board.alpha_composite(t, (x, y))
        d.text((pad, y + cell // 2 + 16), info['kind'], fill=(150, 200, 255), font=font)
    board.save(out_path)


if __name__ == '__main__':
    import sys
    if len(sys.argv) >= 4 and sys.argv[1] == 'preview':
        out = sys.argv[2]
        items = [(p.rsplit('/', 1)[-1][:12], Image.open(p)) for p in sys.argv[3:]]
        preview(items, out, '/System/Library/Fonts/Supplemental/Arial Unicode.ttf')
        print('saved', out)
