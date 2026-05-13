# mihomo-build

Multi-platform build pipeline for Apex VPN client. Releases are published here automatically when a new version tag is pushed.

## Platforms

| Platform | Architecture | Format |
|----------|-------------|--------|
| Android | arm64-v8a / armeabi-v7a / universal(v8a+v7a) / emulator(x86_64) | 4× APK |
| Android TV | arm64-v8a + armeabi-v7a (fat APK) | APK |
| Windows | x86_64 | EXE (Inno Setup) |
| macOS | Universal (Apple Silicon + Intel) | DMG |
| Linux | x86_64 | DEB / RPM / AppImage |
| iOS | arm64 | IPA |
| OpenWrt | aarch64_generic / arm_cortex-a7 / x86_64 | ipk |

## OpenWrt

OpenWrt 包内含预编译的 mihomo 核心 + init 脚本 + UCI 配置 + 透明代理 nftables 规则。

### 选包

| 路由器 | 选这个 |
|---|---|
| 红米 AX 系列 / 小米 AX 系列 / GL.iNet Flint / NanoPi R5 / 树莓派 4 | `mihomo_*_aarch64_generic.ipk` |
| 小米 4A 千兆版 / Newifi 3 / 大部分 MT7621 设备 | `mihomo_*_arm_cortex-a7.ipk` |
| N100/N5105 工控机 / PVE/ESXi 虚拟软路由 | `mihomo_*_x86_64.ipk` |

不确定架构？SSH 进路由器跑 `opkg print-architecture` 看输出。

### 安装

```bash
# 装核心（替换成你架构对应的那个 ipk）
opkg install mihomo_*_aarch64_generic.ipk
# 装 LuCI Web UI（架构无关，所有路由通用）
opkg install luci-app-mihomo_*_all.ipk
```

装完打开 LuCI → Services → Mihomo 配置订阅。

或命令行：

```bash
uci set mihomo.config.enabled='1'
uci set mihomo.config.subscription_url='https://your-panel.com/api/v1/client/subscribe?token=xxx'
uci commit mihomo
/etc/init.d/mihomo start
```

### Web 仪表盘

```
http://<router-ip>:9090/ui
```

## Server (Xboard / V2Board)

机场主把 [`server/Apex.php`](server/Apex.php) 放进面板的 `app/Protocols/` 目录，开启加密订阅。同一份文件在 Xboard 和 V2Board 上都能跑。

部署前先去 Telegram 打包机器人 → 选你的配置 → 「查看加密密钥」，复制那串 `XOR_KEY`，待会儿要填进 Apex.php 的 `$encryptKey`。

### 部署方式 1：Docker（裸 docker-compose）

**手动下载 Apex.php**，放到 `compose.yaml` 同目录。打开文件，把 `$encryptKey` 填上你刚才复制的密钥：

```php
private $encryptKey = '你的XOR_KEY';
```

在 `compose.yaml` 的 `volumes` 段加一行：

```yaml
services:
  xboard:
    image: ghcr.io/cedar2025/xboard:latest
    volumes:
      - ./.env:/www/.env
      - ./.docker/.data/:/www/.docker/.data
      - ./storage/logs:/www/storage/logs
      - ./storage/theme:/www/storage/theme
      - ./plugins:/www/plugins
      - redis-data:/data
      - ./Apex.php:/www/app/Protocols/Apex.php:ro
```

应用：

```bash
docker compose up -d
docker compose exec xboard php artisan octane:reload
```

### 部署方式 2：aaPanel + Docker

1. **手动下载 Apex.php**，上传到 aaPanel 上 xboard 项目的目录（一般是 `/www/wwwroot/xboard/`）
2. 编辑里面的 `$encryptKey`
3. aaPanel → 容器编排 → 编辑 xboard 的 compose，`volumes` 段加：

```yaml
- /www/wwwroot/xboard/Apex.php:/www/app/Protocols/Apex.php:ro
```

4. aaPanel 重启容器
5. SSH 终端跑：

```bash
docker exec <容器名> php artisan octane:reload
```

### 部署方式 3：1Panel

1. **手动下载 Apex.php**，上传到 1Panel 上 xboard 项目目录（一般是 `/opt/1panel/apps/xboard/`）
2. 编辑里面的 `$encryptKey`
3. 1Panel → 容器 → 编排 → 编辑 xboard 编排，`volumes` 段加：

```yaml
- /opt/1panel/apps/xboard/Apex.php:/www/app/Protocols/Apex.php:ro
```

4. 1Panel 重启容器
5. SSH 终端跑：

```bash
docker exec <容器名> php artisan octane:reload
```

### 验证

```bash
curl -A 'Apex/v3.5.x' 'https://你的面板/api/v1/client/subscribe?token=xxx&flag=apex' | head -c 30 | xxd
```

看输出前几个字节：

- **看不出意义的二进制**（含 `41 50 45 58` 之类） → 加密成功，Apex.php 生效 ✅
- 开头是 `vless`、`ss://`、`mixed-port` 之类可读字符串 → Apex.php 没生效，被路由 fallback 了 ❌

❌ 排查：检查 `$encryptKey` 是否填了、容器有没有 `octane:reload`、文件权限是否可读。

### 自定义 UA 时改 flag

如果打包机器人配的 `APEX_FLAG` 不是默认的 `apex`（例如客户端 UA 是 `MyVPN/v1.0`），需要把 Apex.php 里这两行改成同一个值：

```php
public $flag  = 'myvpn';
public $flags = ['myvpn'];
```

Telegram 机器人「查看加密密钥」页同时显示当前 flag，复制即可。

### 替换默认 Clash 模板（可选但强烈建议）

V2Board / Xboard 自带的 `default.clash.yaml` 三个问题：

1. DNS 走明文 UDP-53，被 ISP 劫持 → ECH 节点解析不出
2. 故障转移组 interval=7200s（2 小时），节点死了得等 2 小时才重测
3. 几百行硬编码域名规则，过期严重

[`server/default.clash.yaml`](server/default.clash.yaml) 是替代版本：

- DNS 走 DoH/DoT（doh.pub / dns.alidns.com / Cloudflare / Google）
- url-test interval 缩到 300s
- `geosite:cn / geolocation-!cn / category-ads-all` 取代 500 行手写规则
- 保留 `$app_name` 占位符（机场品牌名运行时替换）

**手动下载** [server/default.clash.yaml](server/default.clash.yaml)，替换面板的 `resources/rules/default.clash.yaml`：

- V2Board 路径：`/www/wwwroot/v2board/resources/rules/default.clash.yaml`
- Xboard 路径：`/www/wwwroot/xboard/resources/rules/default.clash.yaml`

替换前先备份原文件。

## Build

Builds are triggered via GitHub Actions:

- **Tag push** (`v*`) - builds all platforms
- **Manual dispatch** - select specific platforms, customize app name / logo / config

## Contact

Purchase & support: [@teka0pu](https://t.me/teka0pu)
