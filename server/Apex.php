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
     * 2. Xboard schema 适配: 把节点 protocol_settings.tls 嵌套对象平铺到
     *    $server['tls_settings']，让父类 buildAnyTLS / buildTuic / buildHysteria2
     *    读 tls_settings 时能取到 server_name / allow_insecure。
     *
     *    背景: 部分 Xboard fork 的 ClashMeta::buildAnyTLS 还是 v2board 风格（读
     *    $server['tls_settings']），但 Xboard 节点把 tls 嵌套在 protocol_settings.tls
     *    下，导致 sni 字段全空，客户端 TLS ClientHello 缺 SNI → 握手失败（EOF）。
     *
     *    三种部署都安全:
     *      • v2board 原版面板：节点没 protocol_settings 字段，foreach 跳过
     *      • cedar2025/Xboard master：父类用 data_get 读嵌套，多加的字段不读，零影响
     *      • 老 Xboard fork / 魔改 fork：兜住 sni 不为空
     */
    public function __construct($user, $servers)
    {
        // 1. flag 大小写规范化 — 不管机场主填什么大小写都能匹配
        $this->flag  = strtolower((string) $this->flag);
        $this->flags = array_map(
            fn($f) => strtolower((string) $f),
            (array) $this->flags
        );

        // 2. tls_settings 嵌套字段平铺
        foreach ($servers as $i => $s) {
            if (isset($s['protocol_settings']['tls'])
                && is_array($s['protocol_settings']['tls'])
                && empty($s['tls_settings'])) {
                $servers[$i]['tls_settings'] = $s['protocol_settings']['tls'];
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
        if (is_object($result)
            && method_exists($result, 'getContent')
            && method_exists($result, 'setContent')) {
            $result->setContent($this->encrypt((string) $result->getContent()));
            return $result;
        }

        return $this->encrypt((string) $result);
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
