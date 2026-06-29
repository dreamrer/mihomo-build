<?php

/*
|==============================================================================
|  Apex 协议处理器（双面板兼容：Xboard / V2Board）
|==============================================================================
|  机场主部署需要改 1~2 处：
|
|  【必改 1】加密密钥
|     ★ 第 41 行 ★   private $encryptKey = '';
|     去 Telegram 打包机器人 → 选你的配置 → 「查看加密密钥」复制粘贴。
|     不填或填错 → 客户端解不出节点列表为空。
|
|  【必改 2，仅当客户端用了自定义 UA】协议 flag
|     ★ 第 37 行 ★   public $flag  = 'apex';
|     ★ 第 38 行 ★   public $flags = ['apex'];
|     这两处必须**同时改成同一个值**，且与打包机器人里 APEX_FLAG 一致。
|     - 默认 UA（`Apex/v{版本号}`）   → 都填 'apex'，已填好不动
|     - 自定义 UA `MyVPN/v1.0`        → 都填 'myvpn'
|     - 不知道填什么 → 打包机器人「查看加密密钥」也会同时显示当前 flag 值
|
|     ✅ **大小写无关**：本类构造函数会自动把 $flag / $flags 强制 lowercase,
|        机场主写成 'Apex' / 'MYVPN' / 'MyVpn' 等任意大小写都能正常匹配,
|        不会再因为大小写问题导致 fallback 到通用 base64 订阅.
|        (老版本机场主必须严格小写, 现在不用了.)
|
|     flag 不匹配 → 服务端 fallback 到通用订阅（base64 URI 列表），客户端
|     拉到非加密内容报错，节点为空。
|
|------------------------------------------------------------------------------
*/

namespace App\Protocols;

class Apex extends ClashMeta
{
    // Xboard 的 ProtocolManager 用 $flags 数组；V2Board 的 ClientController 用 $flag 字符串。两个都设。
    // 大小写都行: 构造函数会自动 strtolower 让 V2Board 的 str_contains 命中.
    public $flag  = 'apex';
    public $flags = ['apex'];

    // 必填：加密密钥（必须与客户端打包时 XOR_KEY 完全一致）
    private $encryptKey = '';

    /**
     * 构造函数做两件事:
     *
     * 1. $flag / $flags 大小写规范化 (新加)
     *    -----
     *    V2Board ClientController.php:95-96:
     *      $flag = $request->input('flag') ?? $userAgent;
     *      $flag = strtolower($flag);                        ← 客户端 flag 强制小写
     *      ...
     *      if (str_contains($flag, $class->flag)) { ... }    ← 区分大小写比对
     *
     *    机场主在第 37 行写 $flag = 'Apex' (含大写) 时:
     *      str_contains('apex', 'Apex') === false → 永远匹配不上 → 服务端走
     *      fallback 通用订阅 → 客户端解不出节点空白.
     *
     *    解决方法: 构造函数里把本类的 $flag / $flags 全部小写化, 机场主
     *    无论填什么大小写都安全.
     *
     *    Xboard 的 ProtocolManager 用 stripos 本来就是不区分大小写的, 这里
     *    的规范化对它无影响 (Xboard 用 newInstanceWithoutConstructor 跳过
     *    构造函数, 直接读未规范化的属性值, stripos 自己已经包容大写).
     *
     * 2. Xboard schema 适配: 把节点 protocol_settings.* 嵌套对象平铺到顶层
     *    扁平字段，让老 fork ClashMeta::buildXxx 能读到。
     *
     *    背景: 部分 Xboard fork / 魔改 fork 的 ClashMeta::buildAnyTLS /
     *    buildHysteria2 / buildTuic 还是 v2board 风格（读 $server['xxx']
     *    顶层平字段），但 Xboard 现代 schema 把所有协议参数嵌套在
     *    protocol_settings 下，导致：
     *      - TLS 字段缺 → SNI 空 → 握手 EOF
     *      - hy2 obfs 字段缺 → 服务端要 obfs 但 mihomo 不发 → 验证失败/超时
     *      - hy2 带宽字段缺 → 走默认拥塞控制 → brutal 触发不了 → 速度极慢
     *      - hy2 ports 字段缺 → port hopping 失效 → 单端口被识别拦
     *    现象：FlClash 直连 ClashMeta.php（已带 protocol_settings 嵌套）一切正常，
     *    本协议被老 fork 解析时缺字段 → 节点 timeout / slow。
     *
     *    兼容策略：**只补不改** —— 顶层字段已有值就不覆盖；嵌套不存在就跳过。
     *    三种部署都安全:
     *      • v2board 原版面板：节点没 protocol_settings，foreach 跳过
     *      • cedar2025/Xboard master：父类用 data_get 读嵌套，新加的顶层字段不读，零影响
     *      • 老 Xboard fork / 魔改 fork：顶层补齐字段才能正常出 yaml
     */
    public function __construct($user, $servers)
    {
        // 1. flag 大小写规范化 — 不管机场主填什么大小写都能匹配
        $this->flag  = strtolower((string) $this->flag);
        $this->flags = array_map(
            fn($f) => strtolower((string) $f),
            (array) $this->flags
        );

        // 2. schema 兼容层 — 把 protocol_settings.* 嵌套字段平铺到顶层
        foreach ($servers as $i => $s) {
            // [a] TLS 嵌套字段平铺
            //     parent::buildAnyTLS / buildTuic / buildHysteria2 / buildTrojan
            //     如果是老 v2board 风格，会读 $server['tls_settings']
            if (isset($s['protocol_settings']['tls'])
                && is_array($s['protocol_settings']['tls'])
                && empty($s['tls_settings'])) {
                $servers[$i]['tls_settings'] = $s['protocol_settings']['tls'];
            }

            // [b] Hysteria2 obfs 平铺 — 修 hy2 节点用 salamander 混淆时超时
            //     Xboard 现代 schema:
            //       protocol_settings.obfs = { open: true, type: 'salamander', password: 'xxx' }
            //     老 fork ClashMeta 读:
            //       $server['obfs'] (type 字符串)
            //       $server['obfs_password'] 或 $server['obfs-password']
            //
            //     mihomo 只识别 `obfs-password` (中划线), 但部分 fork 父类只读
            //     $server['obfs_password'] (下划线) 再 emit 成中划线。两个都填,
            //     谁的 emit 风格都能命中。
            //
            //     开关语义:
            //       - 有 `open` 字段 → 严格听 open 的 (避免面板 UI 关 obfs 但
            //         type 字段残留导致客户端强带 obfs 反握手失败)
            //       - 没 `open` 字段 (老 schema) → 看 type 是否有值
            //
            //     V2bX-mirror fallback (audit-fix, 见 patchHysteria2Yaml 大注释):
            //       leaderen/V2bX 服务端 obfs.password 为空时把 obfs.type 字符串
            //       当 password 用 (else if 分支)。客户端必须镜像同款行为, 否则
            //       两端密码不匹配 → hy2 timeout。
            if (isset($s['protocol_settings']['obfs'])
                && is_array($s['protocol_settings']['obfs'])) {
                $obfs = $s['protocol_settings']['obfs'];
                $isOn = array_key_exists('open', $obfs)
                    ? !empty($obfs['open'])
                    : !empty($obfs['type']);
                if ($isOn) {
                    $type = (string) ($obfs['type'] ?? 'salamander');
                    if (empty($s['obfs'])) {
                        $servers[$i]['obfs'] = $type;
                    }
                    $pwd = (string) ($obfs['password'] ?? '');
                    if ($pwd === '' && $type !== '') {
                        // V2bX-mirror: 服务端走 fallback 用 type 当 password,
                        // 客户端也用 type 字符串作 password 保持一致
                        $pwd = $type;
                    }
                    if ($pwd !== '') {
                        if (empty($s['obfs_password']))  { $servers[$i]['obfs_password']  = $pwd; }
                        if (empty($s['obfs-password']))  { $servers[$i]['obfs-password']  = $pwd; }
                    }
                }
            }

            // [d] Hysteria2 端口跳跃 (port hopping) 平铺
            //     Xboard 现代 schema：
            //       protocol_settings.ports = '443,4433-4499'   (mihomo 接受这种字符串)
            //     如果只写了 port (单端口) 而 ports 没填 → 老 fork emit 会漏 ports
            //     → port hopping 失效。mihomo 支持 port + ports 共存，无害。
            if (isset($s['protocol_settings']['ports'])
                && !empty($s['protocol_settings']['ports'])
                && empty($s['ports'])) {
                $servers[$i]['ports'] = (string) $s['protocol_settings']['ports'];
            }
        }

        parent::__construct($user, $servers);
    }

    public function handle()
    {
        if ($this->encryptKey === '') {
            throw new \RuntimeException(
                'Apex.php: $encryptKey 未配置。打开 Telegram 打包机器人 → '
                . '「查看加密密钥」，把那串值粘到 Apex.php 的 '
                . "private \$encryptKey = ''; 空引号里。"
            );
        }

        $result = parent::handle();

        // Xboard：parent 返回 Laravel Response（带 headers）→ 替换 body，保留 headers
        // V2Board：parent 返回 string（headers 已通过 header() 全局发出）→ 直接加密返回
        $isResponse = is_object($result)
            && method_exists($result, 'getContent')
            && method_exists($result, 'setContent');
        $yaml = $isResponse ? (string) $result->getContent() : (string) $result;

        // hy2 emit 后处理 — 修父类 ClashMeta::buildHysteria 的两个 yaml 输出 bug。
        // 详见 patchHysteria2Yaml 方法注释，根因都在父类、Apex 无法 override。
        $yaml = $this->patchHysteria2Yaml($yaml);

        $encrypted = $this->encrypt($yaml);
        if ($isResponse) {
            $result->setContent($encrypted);
            return $result;
        }
        return $encrypted;
    }

    /**
     * 修补面板侧 emit + 节点后端 V2bX 多个 hy2 bug, 让客户端 yaml 跟节点后端
     * 实际期望对齐。
     *
     * 为什么不直接 override buildHysteria:
     *   Xboard 父类 ClashMeta 用 `self::buildHysteria(...)` 分发协议 (line 174),
     *   不是 `static::`。PHP 早期绑定意味着子类 override 永远不会被
     *   parent::handle() 调用到。唯一干涉点是 parent::handle() 返回之后、
     *   encrypt() 之前, 对 yaml 字符串做正则修补。
     *
     * Bug 1: 'up' / 'down' 没单位 (Xboard ClashMeta line 597-598)
     *     'up' => data_get($protocol_settings, 'bandwidth.up')   // 比如 100
     *   → yaml `up: 100` (raw int, 没 Mbps 单位)
     *   → mihomo StringToBps 解析失败 → brutal 拥塞控制不启用 → 速度极慢
     *   修法: 正则匹配纯数字 up/down 行, 补 ' Mbps' 后缀。
     *
     * Bug 2+3: obfs / obfs-password 配对一致性
     *
     *   两端都有问题, 必须一起修:
     *
     *   面板侧 (Xboard ClashMeta line 621-624):
     *     if (data_get($protocol_settings, 'obfs.open')) {
     *         $array['obfs'] = data_get('obfs.type');               // 可能 null
     *         $array['obfs-password'] = data_get('obfs.password');  // 可能 null
     *     }
     *   面板 UI 关 obfs 但 type 字段残留, 或开 obfs 但 password 没填时,
     *   yaml 出 `obfs: ~` / `obfs-password: ~`。
     *
     *   节点后端 leaderen/V2bX (core/sing/node.go line 369-382):
     *     case "hysteria2":
     *         if ObfsType != "" && ObfsPassword != "" {
     *             obfs = {Type: ObfsType, Password: ObfsPassword}      // 正常
     *         } else if ObfsType != "" {                                // ⚠️ bug
     *             obfs = {Type: "salamander", Password: ObfsType}      // type 当 password
     *         }
     *   服务端面板返 obfs="salamander"/obfs-password="" 时, V2bX 实际跑
     *   `obfs salamander, password="salamander"` (拿 type 字符串作密码), 而
     *   客户端 yaml 按面板真实数据是 `obfs: salamander, obfs-password: null`,
     *   mihomo 拿空 password 连 → 两端密码不匹配 → QUIC 握手 silent drop →
     *   客户端 timeout。这是用户报 "hy2 节点 timeout" 的真凶。
     *
     *   修法 (配对处理, 紧邻两行同时观察):
     *     - obfs 空 + password 空 → 两行都删, mihomo 走 plain
     *     - obfs 有 + password 空 → password = obfs 值 (镜像 V2bX fallback,
     *                                让两端密码一致)
     *     - obfs 空 + password 有 → 两行都删 (光有密码没类型, 没意义)
     *     - obfs 有 + password 有 → 原样不动
     *
     *   配对正则: ClashMeta.php 父类 emit 时两行总是紧邻 (line 622-623),
     *   多行模式抓 "前行 obfs: x\n紧跟 obfs-password: y\n" 一起处理。
     *
     *   清理: 配对正则没命中的孤立 null 行 (理论上不应出现, 防御性兜底)
     *   也单独删掉。
     *
     * 兼容性:
     *   - v2board 父类 ClashMeta::buildHysteria2 (line 468-498) 不读 bandwidth,
     *     yaml 本来就没 up/down, Bug 1 正则不命中无操作。
     *   - 配对正则只命中 obfs/obfs-password 紧邻成对的情形; 父类不动的前提下成立。
     *   - 正常完整字段 ("up: 100 Mbps" / "obfs: salamander" + "obfs-password: xxx") 都不动。
     */
    private function patchHysteria2Yaml(string $yaml): string
    {
        // Bug 1: 给纯数字的 up/down 补 ' Mbps' 单位
        //   匹配 "  up: 100\n" 或 "  down: 500\n"（行首缩进 + key + 纯数字值）
        //   不匹配 "  up: 100 Mbps"、"  up: '100 Mbps'"、"  up: 100Mbit/s"
        $yaml = preg_replace(
            '/^(\h*(?:up|down)):\s+(\d+)\s*$/m',
            '$1: $2 Mbps',
            $yaml
        );

        // Bug 2+3: obfs / obfs-password 配对处理 (镜像 V2bX 的 fallback bug)
        $isNullish = function ($v) {
            $v = trim($v);
            return $v === '' || $v === '~' || $v === 'null'
                || $v === "''" || $v === '""';
        };
        $yaml = preg_replace_callback(
            '/^(?P<indent>\h*)obfs:\h*(?P<vObfs>.*?)\h*\r?\n'
            . '(?P=indent)obfs-password:\h*(?P<vPwd>.*?)\h*\r?\n/m',
            function ($m) use ($isNullish) {
                $obfsEmpty = $isNullish($m['vObfs']);
                $pwdEmpty  = $isNullish($m['vPwd']);
                if ($obfsEmpty && $pwdEmpty) {
                    return ''; // 都空 → 整对删, mihomo plain
                }
                if ($obfsEmpty) {
                    return ''; // 光有 password 没 type → 删 (mihomo 拒绝无 type 的 obfs)
                }
                if ($pwdEmpty) {
                    // 镜像 V2bX 的 fallback: 把 obfs 类型字符串当 password 用
                    return $m['indent'] . 'obfs: '          . $m['vObfs'] . "\n"
                         . $m['indent'] . 'obfs-password: ' . $m['vObfs'] . "\n";
                }
                return $m[0]; // 都正常 → 原样
            },
            $yaml
        );

        // 防御性兜底: 配对正则没命中的孤立 null 行 (父类 emit 行为未来可能
        // 变化, 这一步保证不会有 `obfs: ~` 类垃圾留在 yaml 里)
        $yaml = preg_replace(
            "/^\h*(?:obfs|obfs-password)\s*:\s*(?:~|null|''|\"\"|)\s*\r?\n/m",
            '',
            $yaml
        );

        return $yaml;
    }

    private function encrypt(string $content): string
    {
        $inner = base64_encode($content);
        $key = $this->encryptKey;
        $klen = strlen($key);
        $xor = '';
        for ($i = 0, $n = strlen($inner); $i < $n; $i++) {
            $xor .= chr(ord($inner[$i]) ^ ord($key[$i % $klen]));
        }
        return base64_encode($xor);
    }
}
