<?php

/*
|==============================================================================
|  Apex 中间件（V2Board）—— 订阅加密 + 路径混淆，合一
|==============================================================================
|
|  ★★★ 必填：两把密钥 ★★★
|
|      第 85 行   private const APEX_SUB_KEY = '';    ← 订阅加密密钥
|      第 95 行   private const APEX_MW_KEY  = '';    ← 路径混淆密钥
|
|  两把**独立**，按需填，可以只填一把：
|      只填 SUB_KEY → 只加密订阅（替代 app/Protocols/Apex.php）
|      只填 MW_KEY  → 只做路径混淆
|      两把都填     → 两个都开
|      两把都空     → 完全不介入，面板行为与没装它逐字节一致
|
|  ⚠️ 两把不能填成同一个值：算法不同（XOR vs XChaCha20），客户端也是两个独立参数。
|  值来自 Telegram 打包机器人：
|      SUB_KEY ← 「查看加密密钥」
|      MW_KEY  ← 「抗封锁中间件」那一步
|  必须一字不差，填错 → 全站伪装 404 且无任何报错。
|
|------------------------------------------------------------------------------
|  ☐ 选填：只有在打包机器人里改过对应项时才动，否则保持原样
|------------------------------------------------------------------------------
|
|      第 124 行  PATH_PREFIX = '/assets/immutable' ← 机器人「混淆前缀」改了才改
|      第 142 行  API_PREFIX  = '/api/v1'           ← OSS/机器人 api_prefix 改了才改
|      第 150 行  OUR_FLAG    = 'apex'              ← 机器人 APEX_FLAG 改了才改
|
|  三处默认值本来就与客户端一致，**都不动就能正常工作**。
|  改了一边没改另一边的后果：
|      PATH_PREFIX 不一致 → 客户端加密的地址本文件不认 → 全站伪装 404
|      API_PREFIX  不一致 → 解出来的后端路径是错的     → 全站伪装 404
|      OUR_FLAG    不一致 → 订阅不加密（被当成第三方客户端）→ 客户端解不开
|
|  混淆路径的**扩展名**不用管：本文件会自动剥掉 .json/.js/.webp 等 13 种，
|  在机器人里改扩展名不需要动这个文件。
|
|------------------------------------------------------------------------------
|  部署四步
|------------------------------------------------------------------------------
|    1. 本文件放到  app/Http/Middleware/ApexMiddleware.php
|    2. 填上面那两行
|    3. app/Http/Kernel.php 的 $middleware 数组末尾加一行：
|           \App\Http\Middleware\ApexMiddleware::class,
|    4. sudo systemctl reload php-fpm
|
|  不用改 routes/web.php、不用改 nginx、不用装 app/Protocols/Apex.php。
|  不需要 artisan route:clear（本文件不注册任何路由）。
|  只有把密钥填进 .env 时才需要 artisan config:clear —— 面板一旦执行过
|  config:cache，.env 就不再加载、env() 全返回 null，密钥会静默读不到
|  （会回落到本文件的常量，不崩，但等于没装）。**推荐直接填常量。**
|
|------------------------------------------------------------------------------
|  ⚠️ 订阅加密是「防爬混淆」不是密码学加密：XOR + 重复 key，Clash 配置开头是
|     固定模板、可被已知明文推 key。能拦掉订阅链接粘进第三方 Clash / 订阅转换
|     网站的伸手党，拦不住有心的人。别对外宣传成「加密安全」。
|------------------------------------------------------------------------------
*/

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class ApexMiddleware
{
    /*
    |==========================================================================
    | 一、两把密钥（独立，按需填）
    |==========================================================================
    | 都留空 = 本中间件完全不介入，面板行为与没装它时**逐字节一致**。
    */

    /**
     * 订阅加密密钥。填了才加密订阅响应。
     * 必须等于打包机器人「查看加密密钥」里的那个值（客户端的 XOR_KEY）。
     * 只想要订阅加密、不要路径混淆 → 只填这一把。
     */
    private const APEX_SUB_KEY = '';

    /**
     * 路径混淆密钥。填了才解密混淆路径。
     * 必须等于打包机器人「抗封锁中间件」那一步的值（客户端的 APEX_MW_AES_KEY）。
     * 只想要路径混淆 → 只填这一把。
     *
     * 注意这**不能**和上面那把填成同一个值：两者算法不同（XOR vs XChaCha20），
     * 客户端也是两个独立的编译期参数。
     */
    private const APEX_MW_KEY = '';

    /*
    |==========================================================================
    | 二、路径设置（要与打包机器人里对应字段一字不差）
    |==========================================================================
    */

    /**
     * 混淆路径前缀。
     *
     * ⚠️ 两条硬约束，违反任一条都会让请求根本进不到 PHP：
     *   1. 不能撞 public/ 下的**真实文件**。目录本身不要紧 —— 默认值就在真实的
     *      assets/ 下，但 /assets/immutable/<随机>.json 命不中任何文件，
     *      try_files 照常回落到 index.php；
     *   2. 混淆 URL 的**扩展名**不能落进 nginx 的静态资源正则。宝塔 / 1Panel 建站
     *      默认带 `location ~ .*\.(js|css)?$`，而正则 location 优先级高于前缀
     *      location /，所以 `.js` 结尾的请求会被接走去磁盘找文件 → 404。
     *      扩展名由客户端决定，本文件只负责剥掉（见 CAMOUFLAGE_EXTS）。
     *
     * 默认 /assets/immutable + .json：前缀与客户端默认一致（老包把它烧死在二进制
     * 里，换掉存量安装全挂），.json 不在宝塔默认静态正则里 —— 不需要改 nginx。
     *
     * 自检（换了前缀/扩展名后务必跑一次）：
     *     curl -si "https://你的面板域名/assets/immutable/test.json" | head -3
     *   · 返回的 404 里带 Server: nginx 且 Content-Length 很小 → 可能是 nginx 静态
     *     规则接走了，换个扩展名再试；
     *   · 面板日志里能看到这次请求 → 说明进到 PHP 了，正常。
     */
    private const PATH_PREFIX = '/assets/immutable';

    /*
    |==========================================================================
    | 三、以下一般不用动
    |==========================================================================
    */

    /** 普通票据的时间窗（秒）。订阅用长期票据，不受此限。 */
    private const MW_TIMESTAMP_WINDOW = 300;

    /**
     * 后台设了自定义订阅路径时在这里补一份（逗号分隔），否则留空。
     * 一般不用填：本文件会自己读 config('v2board.subscribe_path')。
     */
    private const MW_EXTRA_SUB_PATHS = '';

    private const FORMAT_VERSION = 0x03;
    private const API_PREFIX = '/api/v1';
    private const SUB_PREFIX = '/sub';

    /**
     * 我们自己客户端的 flag，用来把「自己人」和第三方客户端区分开。
     * 必须与打包机器人的 APEX_FLAG 一致（客户端订阅 URL 上带的 ?flag=）。
     * v2board 是子串匹配，所以填 apex 时 flag=apex / Apex/1.0 都能命中。
     */
    private const OUR_FLAG = 'apex';

    /** 内部重放标记。子请求带上它，避免再次进入解密分支造成递归。 */
    private const REPLAY_HEADER = 'X-Apex-Mw-Replay';

    private const NOT_FOUND_HTML = "<html>\n<head><title>404 Not Found</title></head>\n<body>\n<center><h1>404 Not Found</h1></center>\n<hr><center>nginx</center>\n</body>\n</html>\n";

    /** 客户端可能给 token 加的伪装扩展名，解码前剥掉。 */
    private const CAMOUFLAGE_EXTS = array(
        '.json', '.js', '.css', '.map', '.png', '.webp',
        '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.woff2', '.woff',
    );

    /**
     * 全局中间件入口。两件事互不依赖，各由自己那把密钥控制：
     *   ① 路径命中 PATH_PREFIX → 解密还原真实路径 → 内部重放；
     *   ② 本次请求就是在拉订阅 → 加密响应体。
     *
     * 重放而不是原地改写 Request：原地改写要同时同步 REQUEST_URI / PATH_INFO /
     * QUERY_STRING / query bag / Laravel 缓存过的 pathInfo，漏任何一项都是偶发
     * 且极难查的故障。重放走完整内核，命中真实路由与它的鉴权中间件，语义确定。
     *
     * 顺序也有讲究：子请求会再次经过本中间件，订阅加密在**子请求那一层**完成
     * （那一层的 path 才是真实订阅路径）。外层拿到的已是密文，不会二次加密。
     */
    public function handle(Request $request, Closure $next)
    {
        $isReplay = $request->headers->has(self::REPLAY_HEADER);

        // ── ① 路径混淆解密（只在最外层做）──────────────────────────────
        if (!$isReplay && $this->mwKey() !== '') {
            $path = '/' . ltrim($request->path(), '/');
            $prefix = $this->matchPrefix($path);
            if ($prefix !== null) {
                if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
                    return $this->decoy();   // 没装 sodium：该 URL 对外就是不存在
                }
                $token = substr($path, strlen($prefix) + 1);
                $target = $this->resolve($token);
                if ($target === null) {
                    return $this->decoy();
                }
                return $this->replay($request, $target[0], $target[1]);
            }
        }

        // ── ② 订阅：改写 flag + 加密响应 ───────────────────────────────
        // **只对我们自己的 flag 生效**，这一点至关重要：
        //   · v2board 按 str_contains($flag, $class->flag) 选协议类。没装 Apex.php 时
        //     ?flag=apex 谁都匹配不上 → 落到 General（base64 URI 列表，不是 Clash 配置）
        //     → 客户端拿到的格式根本不对。所以这里把 apex 改写成内置的 meta。
        //   · 若不看 flag、对所有订阅响应一律加密，用户把订阅链接粘进
        //     Clash / v2rayN / 小火箭（flag=clash、meta、shadowrocket…）会全部拿到
        //     乱码 —— 而旧的 Apex.php 只在 flag=apex 时加密，其它 flag 原样明文。
        //     无差别加密等于把第三方客户端全废掉。
        $isOurSub = $this->subKey() !== ''
            && $this->isSubAllowed('/' . ltrim($request->path(), '/'))
            && $this->isOurFlag($request);

        if ($isOurSub) {
            $this->rewriteFlagToMeta($request);
        }

        $response = $next($request);

        if ($isOurSub) {
            $this->encryptResponse($response);
        }

        return $response;
    }

    /** 用解密出的真实路径构造子请求并走完整内核。保留方法 / 头 / Cookie / Body。 */
    private function replay(Request $request, $realPath, $query)
    {
        $uri = $realPath . ($query !== '' ? '?' . $query : '');
        $sub = Request::create(
            $uri,
            $request->getMethod(),
            // 非 GET 传原请求已解析好的表单 bag:Symfony create() 把第 3 个参数当
            // request bag,传 array() 会让 x-www-form-urlencoded 的表单字段整体丢失
            // (JSON 体不受影响,Laravel 从 content 读)。
            $request->getMethod() === 'GET' ? $this->parseQuery($query) : $request->request->all(),
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all(),
            $request->getContent()
        );
        $sub->headers->replace($request->headers->all());
        $sub->headers->set(self::REPLAY_HEADER, '1');
        $sub->server->set('REQUEST_URI', $uri);
        $sub->server->set('QUERY_STRING', $query);

        $response = app()->handle($sub);
        // 混淆路径下的响应一律禁缓存:面板本身不设 Cache-Control(裸头),而前面若挂
        // CDN,不少默认策略按扩展名缓存(.svg/.ico/.woff2/.webp 常在名单里),
        // 会把已解密的用户 API 响应缓存后发给别人。不赌 CDN 名单,出口钉死。
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }

    /*
    |==========================================================================
    | 订阅加密
    |==========================================================================
    */

    /**
     * 这次订阅请求是不是我们自己的客户端发的。
     *
     * 判据就是 flag。v2board 用 str_contains 做子串匹配（见 ClientController），
     * 所以客户端只要带 ?flag=apex 就能被认出来，且不会误伤 clash / meta /
     * shadowrocket 等第三方 flag。
     *
     * flag 取不到时（有些客户端不传 flag、只带 User-Agent）返回 false —— 宁可
     * 不加密，也不能把第三方客户端打成乱码。
     */
    private function isOurFlag(Request $request)
    {
        $flag = (string) ($request->input('flag') ?? '');
        if ($flag === '') {
            // 与 xboard 插件同款回退：有些客户端不传 flag，只带自定义 User-Agent
            // （打包机器人的 APP_NAME_EN 会进 UA）。少了这一步，那批请求会被当成
            // 第三方而不加密。
            $flag = (string) $request->header('User-Agent', '');
        }
        $flag = strtolower($flag);
        if ($flag === '') {
            return false;
        }
        return strpos($flag, self::OUR_FLAG) !== false;
    }

    /**
     * 把 flag 改写成 v2board 内置的 meta，让面板产出 ClashMeta 配置。
     *
     * 没装 Apex.php 时 ?flag=apex 匹配不到任何协议类，v2board 会落到 General
     * （base64 URI 列表）——那不是 Clash 配置，客户端解密后也用不了。
     * 改写而不是让客户端直接发 flag=meta：flag=apex 是我们区分"自己人"的唯一
     * 标记，改成 meta 就没法把第三方 ClashMeta 用户和我们区分开了。
     */
    private function rewriteFlagToMeta(Request $request)
    {
        $request->query->set('flag', 'meta');
        $request->request->set('flag', 'meta');
        $qs = $request->query->all();
        $request->server->set('QUERY_STRING', http_build_query($qs));
    }

    /**
     * 加密订阅响应体。原来由 app/Protocols/Apex.php 做，挪到这里的好处：
     * 机场主不用再手改 $flag 和 $encryptKey —— 那两个值任一与打包机器人对不上
     * 就是「能登录但没节点」，且报错完全看不出原因。客户端请求内置的
     * ?flag=meta 即可，不需要自定义协议类。
     */
    private function encryptResponse($response)
    {
        // 只处理"内容在内存里"的普通响应。
        // 不能只用 method_exists 判断:StreamedResponse / BinaryFileResponse 都**有**
        // setContent，但前者调用会直接抛 LogicException(内容是回调不是字符串)、
        // 后者会把文件响应打坏 —— 订阅接口哪天改成流式输出就是 500。
        if (!$response instanceof \Symfony\Component\HttpFoundation\Response
            || $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            || $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            return;
        }
        // 只加密成功响应：4xx/5xx 是面板的报错页，加密了客户端只会看到乱码。
        if (method_exists($response, 'getStatusCode') && $response->getStatusCode() !== 200) {
            return;
        }
        $content = (string) $response->getContent();
        if ($content === '') {
            return;
        }
        $response->setContent($this->xorEncrypt($content));
    }

    /** blob = base64( XOR( base64(明文), key ) )。与客户端 board_decrypt_helper 逐字对应，**别改**。 */
    private function xorEncrypt($content)
    {
        $inner = base64_encode($content);
        $key = $this->subKey();
        $klen = strlen($key);
        $out = '';
        for ($i = 0, $n = strlen($inner); $i < $n; $i++) {
            $out .= chr(ord($inner[$i]) ^ ord($key[$i % $klen]));
        }
        return base64_encode($out);
    }

    /*
    |==========================================================================
    | 路径解密
    |==========================================================================
    */

    /**
     * 解密 + 还原真实后端路径。任何异常 / 越权 → null。
     * @return array|null [realPath, query]
     */
    private function resolve($token)
    {
        $token = ltrim($token, '/');
        if ($token === '') {
            return null;
        }
        $token = $this->stripCamouflageExt($token);

        $plain = $this->decrypt($token);
        if ($plain === null || substr($plain['path'], 0, 1) !== '/') {
            return null;
        }

        $p = $plain['path'];
        if (($h = strpos($p, '#')) !== false) {
            $p = substr($p, 0, $h);
        }
        $query = '';
        if (($q = strpos($p, '?')) !== false) {
            $query = substr($p, $q + 1);
            $p = substr($p, 0, $q);
        }
        if ($this->hasControl($p) || $this->hasControl($query) || strpos($p, '..') !== false) {
            return null;
        }

        $isSub = strpos($p, self::SUB_PREFIX . '/') === 0;

        // 长期票据(ts=0)只允许用于订阅，否则等于发了一张永不过期的万能 API 通行证。
        if ($plain['longLived'] && !$isSub) {
            return null;
        }

        if ($isSub) {
            $real = substr($p, strlen(self::SUB_PREFIX));
            if (!$this->isSubAllowed($real)) {
                return null;
            }
            return array($real, $query);   // 订阅路径不拼 API 前缀
        }

        return array(self::API_PREFIX . $p, $query);
    }

    /**
     * XChaCha20-Poly1305 解密 + 解包 + 时间窗 / 防重放。
     * @return array|null ['path' => string, 'longLived' => bool]
     */
    private function decrypt($token)
    {
        $raw = $this->b64decode($token);
        if ($raw === null || strlen($raw) < 24 + 16) { // nonce + mac
            return null;
        }
        $nonce = substr($raw, 0, 24);
        $body = substr($raw, 24);

        $key = hash('sha256', $this->mwKey(), true);
        $inner = @sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($body, '', $nonce, $key);
        if ($inner === false) {
            return null;
        }

        // inner = [0x03] + [uint16 大端 长度] + body + 填充
        if (strlen($inner) < 3 || ord($inner[0]) !== self::FORMAT_VERSION) {
            return null;
        }
        $n = unpack('n', substr($inner, 1, 2))[1];
        if (3 + $n > strlen($inner)) {
            return null;
        }
        $bodyStr = substr($inner, 3, $n);

        // body = "<ts>|<path>"
        $sep = strpos($bodyStr, '|');
        if ($sep === false) {
            return null;
        }
        $ts = substr($bodyStr, 0, $sep);
        $path = substr($bodyStr, $sep + 1);
        if ($path === '' || !preg_match('/^-?\d+$/', $ts)) {
            return null;
        }

        $sec = (int) $ts;
        if ($sec === 0) {
            // 订阅长期票据：跳过时间窗与重放检查（订阅 URL 要能长期复用）。
            return array('path' => $path, 'longLived' => true);
        }

        $window = (int) $this->window();
        if (abs(time() - $sec) > $window) {
            return null; // 过期
        }
        // 同一个 nonce 在窗口内只许用一次 → 防重放。
        // 依赖 Cache 驱动：站点若用 array 驱动(不持久)等于不设防，但也不会误杀。
        // 生产建议 redis/file。
        if (!Cache::add('apexmw:nonce:' . bin2hex($nonce), 1, $window)) {
            return null; // 重放
        }
        return array('path' => $path, 'longLived' => false);
    }

    /**
     * 订阅路径白名单。
     *
     * ⚠️ V2Board 与 Xboard 差别很大，不能照搬 Xboard 插件的写法：
     *   - Xboard：subscribe_path 是**路径段**(默认 s)，订阅是 /s/{token} 短链
     *   - V2Board：subscribe_path 是**完整路径**(如 /cl/dy)，token 走 ?token=，
     *     且一旦设了它，/api/v1/client/subscribe 这条路由**根本不会注册**
     *     (见 app/Http/Routes/V1/ClientRoute.php)
     * 两条都放行即可：没注册的那条，面板自己会 404。
     */
    private function isSubAllowed($p)
    {
        $custom = trim((string) config('v2board.subscribe_path', ''));
        if ($custom !== '' && strpos($p, $custom) === 0) {
            return true;
        }
        if (strpos($p, '/api/v1/client/subscribe') === 0) {
            return true;
        }
        foreach (array_filter(array_map('trim', explode(',', self::MW_EXTRA_SUB_PATHS))) as $a) {
            // 裸 '/' 会恒真 → 长期票据(不校时间窗/不查重放)可打到任意路由,
            // 故只认以 / 开头且有实际路径段的条目。
            if (strlen($a) > 1 && $a[0] === '/' && strpos($p, $a) === 0) {
                return true;
            }
        }
        return false;
    }

    /*
    |==========================================================================
    | 小工具
    |==========================================================================
    */

    /**
     * 归一化混淆前缀，容忍手填的常见形态；判定为危险时返回 '' = 关闭路径混淆。
     *
     * 实测过的坑（这些都不会报错，只会静默出事）：
     *   'assets/immutable'   漏前导斜杠 → 永不命中，路径混淆等于没开
     *   '/assets/immutable/' 多尾部斜杠 → 同上
     *   ''  或  '/'        → 拼出来的前缀是 '/'，**命中所有请求** →
     *                        整个面板变伪装 404，而机场主查不出原因
     * 最后一条最危险：想「清空前缀来关掉混淆」的直觉操作会打死全站。
     *
     * 另外挡掉会吞掉面板自身路由的前缀（/api、/admin 之类）—— 填了它们等于把
     * 对应功能整块变 404。
     */
    /**
     * 这条路径归不归我们管?归我们管返回命中的前缀,否则返回 null。
     *
     * **只比 PATH_PREFIX 这一个值**,没有兼容回退:前缀在客户端是编译期烧死的,
     * 两端必须完全一致。改了 PATH_PREFIX,所有已经装在用户手机上的旧包会立刻
     * 全部登录失败,必须同时打新包并让用户全部更新。
     * 前缀为空(被判危险)时返回 null = 关掉路径混淆,把请求原样交还面板。
     */
    private function matchPrefix($path)
    {
        $prefix = $this->pathPrefix();
        if ($prefix === '') {
            return null;
        }
        return strpos($path, $prefix . '/') === 0 ? $prefix : null;
    }

    private function pathPrefix()
    {
        $p = trim((string) env('APEX_MW_PATH_PREFIX', self::PATH_PREFIX));
        $p = '/' . trim($p, "/ \t\n\r\0\x0B");
        if ($p === '/' || $p === '') {
            return '';   // fail-safe：宁可不生效，也不能把全站打成 404
        }
        // 默认值本身就落在 /assets 下,必须放行 —— 否则"用默认值"这条路被自己堵死,
        // pathPrefix() 返回空串 = 路径混淆静默失效。
        if ($p === self::PATH_PREFIX) {
            return $p;
        }
        $lower = strtolower($p);
        foreach (array('/api', '/admin', '/theme', '/assets') as $reserved) {
            if ($lower === $reserved || strpos($lower, $reserved . '/') === 0) {
                return '';   // 会吞掉面板自身路由或撞 public/ 真实目录
            }
        }
        return $p;
    }

    /** .env 的 APEX_SUB_KEY 优先，其次文件里的常量。 */
    private function subKey()
    {
        $fromEnv = (string) env('APEX_SUB_KEY', '');
        return $fromEnv !== '' ? $fromEnv : (string) self::APEX_SUB_KEY;
    }

    /**
     * .env 优先，其次文件里的常量。
     *
     * 两个环境变量名都认：常量叫 APEX_MW_KEY 而历史上的 .env 名是
     * APEX_MW_AES_KEY，只认一个的话，运营者照着常量名去 .env 里写就会**静默**
     * 读不到（表现是全站伪装 404，查不出原因）。
     */
    private function mwKey()
    {
        $fromEnv = (string) env('APEX_MW_KEY', '');
        if ($fromEnv === '') {
            $fromEnv = (string) env('APEX_MW_AES_KEY', '');
        }
        return $fromEnv !== '' ? $fromEnv : (string) self::APEX_MW_KEY;
    }

    private function window()
    {
        $w = (int) env('APEX_MW_TIMESTAMP_WINDOW', self::MW_TIMESTAMP_WINDOW);
        return $w > 0 ? $w : 300;
    }

    private function stripCamouflageExt($s)
    {
        foreach (self::CAMOUFLAGE_EXTS as $ext) {
            $len = strlen($ext);
            if (strlen($s) > $len && substr($s, -$len) === $ext) {
                return substr($s, 0, -$len);
            }
        }
        return $s;
    }

    private function hasControl($s)
    {
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $c = ord($s[$i]);
            if ($c < 0x20 || $c === 0x7f) {
                return true;
            }
        }
        return false;
    }

    /** 客户端发的是 base64url-nopad，其余变体一并容错。 */
    private function b64decode($s)
    {
        $variants = array(
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
            SODIUM_BASE64_VARIANT_URLSAFE,
            SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING,
            SODIUM_BASE64_VARIANT_ORIGINAL,
        );
        foreach ($variants as $v) {
            try {
                return sodium_base642bin($s, $v);
            } catch (\Throwable $e) {
                // 换下一个变体
            }
        }
        return null;
    }

    private function parseQuery($query)
    {
        if ($query === '') {
            return array();
        }
        parse_str($query, $out);
        return $out;
    }

    /**
     * 伪装 404。所有失败路径都走这里，且与真正的 nginx 404 逐字节一致 ——
     * 让探测者无法从响应差异区分「密钥错」「过期」「越权」「路径不存在」。
     */
    private function decoy()
    {
        return new Response(self::NOT_FOUND_HTML, 404, array(
            'Content-Type' => 'text/html',
            'Server' => 'nginx',
            'Cache-Control' => 'no-store, private',
        ));
    }
}
