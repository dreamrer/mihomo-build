# Apex 协议补丁（SSPanel-Uim / SSPanel-Metron 通用）

给 SSPanel 加上 V2Board 风格的 REST API + 加密订阅，让 Apex 客户端能像连 V2Board 一样登录自动拉订阅。

支持：
- [SSPanel-Uim](https://github.com/Anankke/SSPanel-Uim)（活跃主线，10k+ stars）
- [SSPanel-Metron](https://github.com/BobCoderS9/SSPanel-Metron)（Uim 的 CSS 主题包，后端就是 Uim）
- SSPanel-V3-Mod_Uim 时代的老部署（已停更，API 兼容）

## ⚠️ 协议支持范围

SSPanel 在数据库层面只支持以下协议——这是平台天花板，跟客户端无关：

| 协议 | 是否支持 |
|---|---|
| Shadowsocks | ✅ |
| Shadowsocks-2022 | ✅ |
| TUIC v5 | ✅ |
| VMess (TCP / WS / gRPC / H2) | ✅ |
| Trojan (TCP / WS / gRPC) | ✅ |
| **VLESS / Reality** | ❌ SSPanel 不支持 |
| **Hysteria / Hysteria2** | ❌ SSPanel 不支持 |
| **AnyTLS** | ❌ SSPanel 不支持 |
| **WireGuard** | ❌ SSPanel 不支持 |

需要 Reality / Hy2 / AnyTLS 的机场主，请改用 [V2Board](https://github.com/v2board/v2board) 或 [Xboard](https://github.com/cedar2025/Xboard)（Apex 客户端原生支持，无需补丁）。

## 部署前提

- SSPanel 已经能正常跑（自带 `/sub/{token}/clash` 输出能拉到订阅）
- 知道 Apex 客户端打包时的 **加密密钥**（去 Telegram 打包机器人「查看加密密钥」复制）

## 部署步骤

### 1. 上传 Apex.php

把 [`Apex.php`](Apex.php) 放到 SSPanel 项目目录的 **`src/Apex.php`**：

```bash
# 直接 LNMP / 传统部署
cp Apex.php /www/wwwroot/sspanel/src/Apex.php

# Docker 部署（容器内挂载或拷进容器）
docker cp Apex.php sspanel-container:/var/www/sspanel/src/Apex.php
```

### 2. 改 `Apex.php` 第 51 行的密钥

```php
$encryptKey = '你的XOR_KEY';
```

不填或填错 → 客户端解不出节点列表为空。

### 3. 注册路由：编辑 `app/routes.php`

打开 `app/routes.php`，找到末尾的 `};`（路由 closure 结尾），在它**前面**加一行：

```php
    (require __DIR__ . '/../src/Apex.php')($app);
};
```

完整位置长这样：

```php
return static function (Slim\App $app): void {
    $app->get('/', App\Controllers\HomeController::class . ':index');
    // ... 大量已有路由 ...
    
    (require __DIR__ . '/../src/Apex.php')($app);  // ← 加这行
};
```

### 4. 重新加载 SSPanel

```bash
# Docker
docker compose restart sspanel

# php-fpm + nginx
systemctl restart php-fpm

# 如果用了 OPcache 或 Slim 路由缓存，清一下
php -r 'opcache_reset();'
rm -rf storage/cache/*  # 仅 Slim 4 启用了路由缓存时需要
```

### 5. 验证

```bash
# 应返回 422（说明路由生效了，请求体格式校验起作用）
curl -i -X POST https://你的面板/api/v1/passport/auth/login \
  -H 'Content-Type: application/json' -d '{}'

# 应返回 200 + token，说明全链路打通
curl -i -X POST https://你的面板/api/v1/passport/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"你的邮箱","password":"你的密码"}'
```

如果看到 `{"data":{"token":"...","auth_data":"..."}}` → 部署成功。

## 客户端配置

打 Apex 客户端时，在打包机器人 wizard 里：
- **panel_type**: 选 `sspanel`
- **加密密钥**: 跟 `Apex.php` 第 51 行填的那串完全一致
- **APEX_FLAG**: 跟 `Apex.php` 第 54 行的 `$apexFlag` 一致（默认 `apex`）

## 排查

### 现象：登录返回 500 `Apex.php: $encryptKey 未配置`

`Apex.php` 第 51 行 `$encryptKey` 没填或填了空字符串。改完后 `docker compose restart` 或 `systemctl restart php-fpm`。

### 现象：登录返回 200 但订阅返回 404

`/api/v1/client/subscribe` 用的是 SSPanel `link` 表的 token，登录成功后会自动懒生成。如果还是 404，去 SSPanel 数据库 `link` 表看下用户对应的 token 行有没有创建。

### 现象：订阅前几个字节看着是可读的 yaml

flag 不匹配，服务端返回了原文。检查：
- 客户端打包时的 `APEX_FLAG` 跟 `Apex.php` 第 54 行 `$apexFlag` 是否一致
- 客户端发出的请求 URL 是否带了 `&flag=apex`

### 现象：客户端订阅卡片不显示流量/到期

`Subscription-Userinfo` 响应头依赖 `user.transfer_enable / u / d / class_expire` 字段。SSPanel 这几个字段从 install 起就存在，几乎不会缺。如果还是没数据，curl 看下响应头有没有这一行。

## 卸载

1. 删掉 `app/routes.php` 里加的那行 `(require __DIR__ . '/../src/Apex.php')($app);`
2. 删掉 `src/Apex.php`
3. 重启 / reload

不会影响 SSPanel 自身的任何功能。

## 工作原理简述

补丁包做的事：
1. 在 Slim 4 路由表里追加一个 `/api/v1/*` 的路由组
2. 登录时校验 SSPanel 自己的 `User.pass`，签发随机 token 写到 `User.api_token`（这个字段 SSPanel 自身已经有，但没在用）
3. 用户信息端点把 SSPanel 的字段映射成 V2Board 风格 JSON
4. 订阅端点调 SSPanel 自带的 `Subscribe::getContent($user, 'clash')` 渲染 YAML，套 XOR 加密返回
5. 不修改 SSPanel 任何已有文件、数据库结构、配置项 → SSPanel 升级随时可以
