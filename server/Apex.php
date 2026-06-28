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
            //     Xboard 现代 schema：
            //       protocol_settings.obfs = { open: true, type: 'salamander', password: 'xxx' }
            //     老 fork ClashMeta 读：
            //       $server['obfs'] (type 字符串)
            //       $server['obfs_password'] 或 $server['obfs-password']
            //
            //     mihomo 只识别 `obfs-password` (中划线), 但部分 fork 父类只读
            //     $server['obfs_password'] (下划线) 再 emit 成中划线。两个都填,
            //     谁的 emit 风格都能命中。
            //
            //     开关语义 (audit-fix):
            //       - 有 `open` 字段 → 严格听 open 的 (开 = 平铺, 关 = 跳过,
            //         哪怕 type/password 还在残留也不平铺, 避免面板 UI 关 obfs
            //         但 type 字段没清空导致客户端强带 obfs 反握手失败)。
            //       - 没 `open` 字段 (老 schema) → 看 type 是否有值
            if (isset($s['protocol_settings']['obfs'])
                && is_array($s['protocol_settings']['obfs'])) {
                $obfs = $s['protocol_settings']['obfs'];
                $isOn = array_key_exists('open', $obfs)
                    ? !empty($obfs['open'])
                    : !empty($obfs['type']);
                if ($isOn) {
                    if (empty($s['obfs'])) {
                        $servers[$i]['obfs'] = (string) ($obfs['type'] ?? 'salamander');
                    }
                    $pwd = (string) ($obfs['password'] ?? '');
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
     * 修补 Xboard ClashMeta::buildHysteria 在 yaml 输出阶段引入的两个 bug。
     *
     * 为什么不直接 override buildHysteria：
     *   Xboard 父类 ClashMeta 用 `self::buildHysteria(...)` 分发协议（line 174
     *   等位置），不是 `static::`。PHP 早期绑定意味着子类 override 永远不会被
     *   parent::handle() 调用到。唯一干涉点就是 parent::handle() 返回之后、
     *   encrypt() 之前，对 yaml 字符串做正则修补。
     *
     * Bug 1: 'up' / 'down' 没单位
     *   父类 line 597-598：
     *     'up' => data_get($protocol_settings, 'bandwidth.up'),    // 比如 100
     *     'down' => data_get($protocol_settings, 'bandwidth.down'), // 比如 500
     *   →  yaml 输出 `up: 100` `down: 500`（raw int 没单位后缀）
     *   →  mihomo StringToBps 解析失败 → brutal 拥塞控制不启用
     *   →  实际表现：能连通，但速度跑不起来（hy2 节点的 brutal 是核心卖点）。
     *
     *   修法：正则匹配仅有数字、无单位后缀的 up/down 行，补 ' Mbps' 后缀。
     *   单位有就跳过（操作员手填 "100 mbit/s" 之类不动）。
     *
     * Bug 2: obfs/obfs-password 是 null (yaml 里的 `~`/`''`)
     *   父类 line 621-624：
     *     if (data_get($protocol_settings, 'obfs.open')) {
     *         $array['obfs'] = data_get($protocol_settings, 'obfs.type');           // 可能 null
     *         $array['obfs-password'] = data_get($protocol_settings, 'obfs.password'); // 可能 null
     *     }
     *   面板 UI 把 obfs 打开但没填 type/password（或前端清空过）时:
     *   →  yaml 输出 `obfs: ~` 和 `obfs-password: ~`
     *   →  mihomo 当 "obfs 启用但 type 异常" → QUIC 握手失败
     *   →  实际表现：节点直接 timeout，是这次用户反馈的真凶之一。
     *
     *   修法：删除 obfs 值为 null/~/'' 的整行。让 mihomo 当作 obfs 未启用，
     *   恢复明文 hy2，至少能用。
     *
     * 兼容性：
     *   - v2board 父类 ClashMeta::buildHysteria2 (line 468-498) 不读 bandwidth，
     *     所以 yaml 里本来就没 up/down，正则不命中无操作。
     *   - 修补只影响异常行；正常完整字段（如 'up: 100 Mbps'）不动。
     *   - obfs 删行也只删 null 值，正常 `obfs: salamander` 不动。
     */
    private function patchHysteria2Yaml(string $yaml): string
    {
        // Bug 1: 给纯数字的 up/down 补 ' Mbps' 单位
        //   匹配："  up: 100\n" 或 "  down: 500\n"（行首缩进 + key + 纯数字值）
        //   不匹配："  up: 100 Mbps"、"  up: '100 Mbps'"、"  up: 100Mbit/s"
        $yaml = preg_replace(
            '/^(\h*(?:up|down)):\s+(\d+)\s*$/m',
            '$1: $2 Mbps',
            $yaml
        );

        // Bug 2: 删 obfs / obfs-password 是 null 的整行
        //   匹配 yaml 的几种 null 写法: `~`、`null`、`''`、`""`、空值
        //   m 模式让 ^/$ 配行边界，删整行（含结尾换行）。
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
