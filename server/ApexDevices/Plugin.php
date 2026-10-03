<?php

namespace Plugin\ApexDevices;

use App\Services\Plugin\AbstractPlugin;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 登录设备识别。
 *
 * Xboard 每次登录建一条 Sanctum 令牌,name 字段存的是 Str::random(20),没有任何设备信息
 * (AuthService::generateAuthData),所以客户端的「登录设备管理」只能显示"会话 #编号"。
 * 本插件在令牌**创建时**把 `apexdev1|<IP>|<User-Agent>` 写进 name;对启用前就存在的令牌,
 * 在它下一次被使用(Sanctum 更新 last_used_at)时顺手补上。
 *
 * 客户端(Apex 4.9.6+)的面板请求 UA 形如
 *   myvpn/v4.9.6 (Android 14; Xiaomi 2201123C; id=0a1b2c3d)
 * 解析出机型/系统,并用 id= 认出本机。其它客户端 / 网页端登录的会话同样记下它们的 UA。
 *
 * ⚠️ Octane 下插件 boot() 每个请求都会跑,而 Eloquent 模型事件挂在 worker 进程级的
 * 静态分发器上:监听器只注册一次(static 守卫);是否生效看**本请求**里 boot 有没有
 * 在容器上打过标记(见 boot 里的说明) —— 插件被停用后 boot 不再跑,标记不在,监听器自动成为空操作,不用重启 worker。
 */
class Plugin extends AbstractPlugin
{
    private const PREFIX = 'apexdev1|';
    private const MARK = 'apex_devices.on';
    private const MARK_IP = 'apex_devices.ip';
    private static bool $registered = false;

    public function boot(): void
    {
        // 标记挂在本次请求的容器上,不挂在 request 对象上:App 走路径混淆时,加密插件解密后用
        // app()->handle() 在面板内部重放一个子请求,登录等接口都在子请求里跑;子请求是新的
        // request 对象,而插件本轮已初始化过、不会再 boot → 挂在 request 上的标记子请求看不到,
        // App 的会话就一条都记不上(2026-09-29 真面板实测)。容器子请求共用;Octane 每个请求
        // 一份容器副本(Worker 每请求 clone app 做 sandbox,结束 flush),插件停用后下个请求就没有
        // 这个标记。boot 之所以每请求都跑:PluginManager 是 scoped 绑定,OperationTerminated 的
        // FlushTemporaryContainerInstances 在基础 app 上 forgetScopedInstances → 下个请求重建
        // (pluginsInitialized=false),不是靠什么"每请求重新 boot"的约定。
        app()->instance(self::MARK, true);
        app()->instance(
            self::MARK_IP,
            filter_var($this->getConfig('record_ip', true), FILTER_VALIDATE_BOOLEAN)
        );

        // 在线设备数(节点上报的在线 IP,约 5 分钟窗口 —— 设备数上限数的就是它)。
        // 原版 /user/getSubscribe 只给 device_limit 不给在线数,客户端「登录设备管理」要显示
        // 「正在使用节点 2 台 / 上限 3 台」。钩子表每个请求都会清空,所以每次 boot 都注册。
        // 原版查用户时没 select id,用户 id 从当前登录用户取。
        $this->filter('user.subscribe.response', static function ($user) {
            try {
                $uid = request()->user()?->id;
                if ($uid && class_exists(\App\Services\DeviceStateService::class)) {
                    $user['online_count'] = app(\App\Services\DeviceStateService::class)->getDeviceCount((int) $uid);
                }
            } catch (\Throwable $e) {
                // 只是展示用,取不到就不给,别影响订阅信息本身
            }
            return $user;
        });

        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // 新登录:建令牌时直接写。
        PersonalAccessToken::creating(static function (PersonalAccessToken $token): void {
            $label = self::labelForCurrentRequest();
            if ($label !== null) {
                $token->name = $label;
            }
        });

        // 老令牌:启用插件前就登录的会话,在它下一次访问面板时补上(Sanctum 每次鉴权
        // 都会更新 last_used_at,走 save() → updating)。只改还是随机串的,改过的不再动。
        PersonalAccessToken::updating(static function (PersonalAccessToken $token): void {
            if (str_starts_with((string) $token->name, self::PREFIX)) {
                return;
            }
            if (!preg_match('/^[A-Za-z0-9]{20}$/', (string) $token->name)) {
                return; // 不是 Xboard 默认生成的随机名,可能别的插件在用,别碰
            }
            $label = self::labelForCurrentRequest();
            if ($label !== null) {
                $token->name = $label;
            }
        });
    }

    /** 本请求的设备标签;插件没在本请求里启用 / 不在 HTTP 请求里 → null。 */
    private static function labelForCurrentRequest(): ?string
    {
        if (!app()->bound('request')) {
            return null;
        }
        $request = request();
        if (!app()->bound(self::MARK) || !app(self::MARK)) {
            return null;
        }
        $ua = self::clean((string) $request->userAgent());
        if ($ua === '') {
            $ua = 'unknown';
        }
        $ip = app()->bound(self::MARK_IP) && app(self::MARK_IP) ? self::clientIp($request) : '';
        // name 列是 varchar(255)。UA 放最后,超长时截掉的是 UA 尾巴,前缀和 IP 始终完整。
        return mb_strcut(self::PREFIX . $ip . '|' . $ua, 0, 255, 'UTF-8');
    }

    /**
     * 真实客户端 IP:CDN / 反代后面 $request->ip() 往往是代理地址。只用于展示给用户本人看,
     * 不做任何鉴权,所以信任这几个常见头是可以接受的。
     */
    private static function clientIp($request): string
    {
        foreach (['CF-Connecting-IP', 'X-Real-IP'] as $h) {
            $v = trim((string) $request->header($h));
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) {
                return $v;
            }
        }
        $xff = (string) $request->header('X-Forwarded-For');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        $ip = (string) $request->ip();
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /** 只留 ASCII 可打印字符,去掉 | (本格式的分隔符)。 */
    private static function clean(string $s): string
    {
        $s = preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
        return trim(str_replace('|', '/', $s));
    }
}
