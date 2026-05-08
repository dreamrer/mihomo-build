<?php

/*
|==============================================================================
|  Apex 协议补丁包（SSPanel-Uim / SSPanel-Metron 通用）
|==============================================================================
|  给 SSPanel 加上 V2Board 风格的 REST API + 加密订阅，让 Apex 客户端能像
|  连 V2Board 一样登录自动拉订阅。
|
|  机场主部署需要改 1~2 处：
|
|  【必改 1】加密密钥
|     ★ 第 51 行 ★   $encryptKey = '';
|     去 Telegram 打包机器人 → 选你的配置 → 「查看加密密钥」复制粘贴。
|     不填或填错 → 客户端解不出节点列表为空。
|
|  【必改 2，仅当客户端用了自定义 UA】协议 flag
|     ★ 第 54 行 ★   $apexFlag = 'apex';
|     与打包机器人里 APEX_FLAG 一致即可。
|     默认 UA `Apex/v{版本号}` → 不动；自定义 UA `MyVPN/v1.0` → 改成 'myvpn'。
|
|------------------------------------------------------------------------------
|
|  部署：
|     1) 把本文件放到 SSPanel 项目目录的 src/Apex.php
|     2) 在 app/routes.php 末尾的 closure 里加一行：
|
|          (require __DIR__ . '/../src/Apex.php')($app);
|
|     3) 重启 / 容器 reload 让 Slim 路由表重新加载
|     4) curl 你的面板 https://你的面板/api/v1/passport/auth/login 验证 200
|
|==============================================================================
*/

namespace App;

use App\Models\Link;
use App\Models\User;
use App\Services\Subscribe;
use App\Utils\Hash;
use App\Utils\Tools;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {

    // 必填：加密密钥（必须与客户端打包时 XOR_KEY 完全一致）
    $encryptKey = '';

    // 协议 flag（必须与打包机器人 APEX_FLAG 一致；默认 'apex'）
    $apexFlag = 'apex';

    if ($encryptKey === '') {
        // 不抛异常，只是登录会拒绝；机场主装完文件没填 key 时容易自查
        error_log('[Apex.php] 警告：$encryptKey 未配置，登录会被拒绝。');
    }

    $app->group('/api/v1', static function (RouteCollectorProxy $group) use ($encryptKey, $apexFlag): void {

        // ── 登录：POST /api/v1/passport/auth/login ──────────────────────
        $group->post('/passport/auth/login', static function (Request $req, Response $res) use ($encryptKey): Response {
            if ($encryptKey === '') {
                return jsonResponse($res, 500, ['message' => 'Apex.php: $encryptKey 未配置']);
            }
            $body = (array) $req->getParsedBody();
            $email = strtolower(trim((string) ($body['email'] ?? '')));
            $password = (string) ($body['password'] ?? '');
            if ($email === '' || $password === '') {
                return jsonResponse($res, 422, ['message' => 'email 或密码不能为空']);
            }

            $user = (new User())->where('email', $email)->first();
            // Hash::checkPassword 参数顺序是 (hashed, plaintext)，跟 password_verify 反着
            if ($user === null || ! Hash::checkPassword($user->pass, $password)) {
                return jsonResponse($res, 401, ['message' => '邮箱或密码错误']);
            }

            // 生成 32 字符 api_token 写入 user.api_token（NOT NULL DEFAULT ''，覆盖即可）
            $token = Tools::genRandomChar(32);
            $user->api_token = $token;
            $user->save();

            // 同步生成订阅 link 行（懒生成，已存在则复用）
            ensureLinkToken($user);

            return jsonResponse($res, 200, [
                'data' => [
                    'token' => $token,
                    'auth_data' => $token,
                ],
            ]);
        });

        // ── 注册：占位（SSPanel 自身有完整注册流程，引导用户走面板网页注册） ──
        $group->post('/passport/auth/register', static function (Request $req, Response $res): Response {
            return jsonResponse($res, 403, [
                'message' => '请前往面板网页注册账号后再登录客户端',
            ]);
        });

        // ── 用户信息：GET /api/v1/user/info ────────────────────────────
        $group->get('/user/info', static function (Request $req, Response $res): Response {
            $user = bearerUser($req);
            if ($user === null) {
                return jsonResponse($res, 401, ['message' => 'token 无效或已过期，请重新登录']);
            }

            $expiredAt = null;
            if (! empty($user->class_expire) && $user->class_expire !== '0000-00-00 00:00:00') {
                $expiredAt = strtotime($user->class_expire) ?: null;
            }

            return jsonResponse($res, 200, [
                'data' => [
                    'email' => $user->email,
                    'transfer_enable' => (int) $user->transfer_enable,
                    'u' => (int) $user->u,
                    'd' => (int) $user->d,
                    'last_login_at' => null,
                    'created_at' => isset($user->reg_date) ? strtotime((string) $user->reg_date) ?: null : null,
                    'banned' => $user->enable == 1 ? 0 : 1,
                    'remind_expire' => 1,
                    'remind_traffic' => 1,
                    'expired_at' => $expiredAt,
                    'balance' => (int) round(((float) $user->money) * 100),
                    'commission_balance' => (int) round(((float) ($user->aff_money ?? 0)) * 100),
                    'plan_id' => (int) $user->class,
                    'discount' => null,
                    'commission_rate' => null,
                    'telegram_id' => $user->telegram_id ? (string) $user->telegram_id : null,
                    'uuid' => (string) ($user->uuid ?? ''),
                    'avatar_url' => '',
                ],
            ]);
        });

        // ── 订阅：GET /api/v1/client/subscribe?token=xxx&flag=apex ─────
        $group->get('/client/subscribe', static function (Request $req, Response $res) use ($encryptKey, $apexFlag): Response {
            if ($encryptKey === '') {
                return $res->withStatus(500)->withHeader('Content-Type', 'text/plain')
                    ->withBody(streamFor('Apex.php: $encryptKey 未配置'));
            }
            $params = $req->getQueryParams();
            $subToken = (string) ($params['token'] ?? '');
            $flag = strtolower((string) ($params['flag'] ?? ''));
            if ($subToken === '') {
                return $res->withStatus(400)->withHeader('Content-Type', 'text/plain')
                    ->withBody(streamFor('missing token'));
            }

            $link = (new Link())->where('token', $subToken)->first();
            if ($link === null) {
                return $res->withStatus(404)->withHeader('Content-Type', 'text/plain')
                    ->withBody(streamFor('subscription token not found'));
            }
            $user = (new User())->where('id', $link->userid)->first();
            if ($user === null) {
                return $res->withStatus(404)->withHeader('Content-Type', 'text/plain')
                    ->withBody(streamFor('user not found'));
            }

            // 调 SSPanel 自带的 Clash 渲染器
            $yaml = Subscribe::getContent($user, 'clash');

            // 修补 SSPanel-Uim Clash.php case 14 (Trojan) 的已知 bug：
            // 当节点 custom_config.header.type === 'none' 时，渲染输出
            // network: none，被 mihomo 拒绝解析（只认 tcp/ws/grpc/h2/http）。
            // V2Ray 里 header.type: none 的语义就是"TCP 不伪装 HTTP" = 纯 tcp，
            // 这里直接归正。漏掉这步会导致单节点静默丢失。
            $yaml = preg_replace('/^(\s*network:\s*)none\s*$/m', '${1}tcp', $yaml);

            // flag 匹配才加密；否则返回原文（机场主调试用）
            $body = ($flag === $apexFlag)
                ? apexEncrypt($yaml, $encryptKey)
                : $yaml;

            // Subscription-Userinfo 头：客户端订阅卡片靠这个显示已用/总流量/到期
            $upload = (int) $user->u;
            $download = (int) $user->d;
            $total = (int) $user->transfer_enable;
            $expire = (! empty($user->class_expire) && $user->class_expire !== '0000-00-00 00:00:00')
                ? (strtotime($user->class_expire) ?: 0)
                : 0;
            $userInfoHeader = sprintf(
                'upload=%d; download=%d; total=%d; expire=%d',
                $upload, $download, $total, $expire
            );

            return $res
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('Subscription-Userinfo', $userInfoHeader)
                ->withHeader('Profile-Update-Interval', '24')
                ->withBody(streamFor($body));
        });

        // ── 占位：未实现的 V2Board 端点（订单 / 工单 / 邀请等） ─────
        // 客户端遇到 404/501 会优雅降级（订单页空、邀请页空等），不会崩
        $group->any('/user/order/{wildcard:.*}', static function (Request $req, Response $res): Response {
            return jsonResponse($res, 501, ['message' => 'SSPanel 不支持该端点，请前往面板网页操作']);
        });
        $group->any('/user/ticket/{wildcard:.*}', static function (Request $req, Response $res): Response {
            return jsonResponse($res, 501, ['message' => 'SSPanel 不支持该端点，请前往面板网页操作']);
        });
        $group->any('/user/invite/{wildcard:.*}', static function (Request $req, Response $res): Response {
            return jsonResponse($res, 501, ['message' => 'SSPanel 不支持该端点，请前往面板网页操作']);
        });
    });
};

// ─── helpers ──────────────────────────────────────────────────────────────

/**
 * 通过 Bearer header 解析 User。
 * SSPanel 的 User.api_token 是 NOT NULL DEFAULT ''；空字符串不算有效凭证。
 */
function bearerUser(Request $req): ?User
{
    $auth = $req->getHeaderLine('Authorization');
    if ($auth === '') {
        return null;
    }
    $token = '';
    if (stripos($auth, 'Bearer ') === 0) {
        $token = trim(substr($auth, 7));
    } else {
        // 兜底：客户端没加 Bearer 前缀直接发 token（V2Board 老协议）
        $token = trim($auth);
    }
    if ($token === '') {
        return null;
    }
    return (new User())->where('api_token', $token)->first();
}

/**
 * 懒生成 link 行，确保用户有订阅 token 可用。
 * 复制 Subscribe::getUniversalSubLink 的核心逻辑。
 */
function ensureLinkToken(User $user): string
{
    $link = (new Link())->where('userid', $user->id)->first();
    if ($link === null) {
        $link = new Link();
        $link->userid = $user->id;
        $link->token = Tools::genSubToken();
        $link->save();
    }
    return $link->token;
}

/**
 * Apex XOR 加密：base64( xor( base64(content), key ) )
 * 跟 V2Board 版的 Apex.php 加密格式完全一致，客户端共用一套解密。
 */
function apexEncrypt(string $content, string $key): string
{
    $inner = base64_encode($content);
    $klen = strlen($key);
    $xor = '';
    for ($i = 0, $n = strlen($inner); $i < $n; $i++) {
        $xor .= chr(ord($inner[$i]) ^ ord($key[$i % $klen]));
    }
    return base64_encode($xor);
}

function jsonResponse(Response $res, int $status, array $data): Response
{
    $res->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
    return $res->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
}

function streamFor(string $content)
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $content);
    rewind($stream);
    return new \GuzzleHttp\Psr7\Stream($stream);
}
