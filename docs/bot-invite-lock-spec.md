# 打包机器人 · 代理专用固定邀请码 · 锁定规格

> 面向做机器人的实现方. 客户端 + GHA workflow 侧已经就绪, 本文只讲机器人需要的改动.

## 1. 背景

有的机场主要给自己的**代理**打专用客户端 — 用户装了这个 APK 注册, 会自动挂在该代理的邀请码链下. 有的机场主不需要这个功能.

这个功能已经在 [Apex 客户端](https://github.com/dreamrer/app) + [mihomo-build workflow](https://github.com/dreamrer/mihomo-build) 落地:

- **workflow 侧**: `.github/workflows/build.yaml` 有 `fixed_invite_code` input, 默认空
- **打包脚本**: `setup.dart` 把它编成 `--dart-define=FIXED_INVITE_CODE=...`
- **客户端**: `XBoardConfig.fixedInviteCode` 优先读 dart-define, 回落 OSS `contact.fixed_invite_code`, 都空则用户手填. 注册页有固定码时输入框自动预填且不可编辑

机器人侧要做的**唯一**事情: 把机场主填的 `fixed_invite_code` 值传给 workflow_dispatch 的对应 input.

**风险**: 如果机器人对这个字段完全不设限, 一个授权机场主可以在 Bot 里挨个换代理的邀请码, 一次授权无限出包给不同代理, 绕开 admin (你) 的业务控制. 所以要加**锁定 + 白名单**机制.

## 2. 核心模型: 首次自助 + append-only 白名单

### 语义

| 场景 | 行为 |
|---|---|
| 机场主不填这个字段 | 正常打普通包, 不受限, 跟老流程一模一样 |
| 机场主**首次**填一个邀请码 | **自助免审批**, 自动锁定入他自己的白名单 |
| 机场主填的邀请码在白名单里 | 直接放行 (相当于历史复用) |
| 机场主填的邀请码不在白名单里 | 拒绝, 要求先让 admin 授权 |
| admin 授权一个新码 | append 到该机场主的白名单末尾, 之后自由复用 |

### 数据模型

```
authorized_users (机场主)
  tg_id                     12345          # Telegram user id
  role                      operator
  granted_at                2026-07-01
  invite_code_whitelist     ['AGENT_A', 'AGENT_B']   # append-only, 只 admin 可增删
  invite_first_set_at       2026-07-02     # 首次自助锁定时间, 审计用
```

`invite_code_whitelist` 是**append-only** — 首次由机场主自己写一个进去, 之后**只有 admin** 能追加. 撤销代理关系时 admin 可以移除, 但机场主自己没有任何编辑口.

### 判定逻辑 (伪码)

```python
def gate_invite_code(operator: AuthorizedUser, requested_code: str) -> Decision:
    # 情况 1: 不填 → 普通包
    if not requested_code:
        return Decision.OK

    # 情况 2: 已在白名单 → 直接放行
    if requested_code in operator.invite_code_whitelist:
        return Decision.OK

    # 情况 3: 白名单空 → 首次自助, 免审批但落库
    if not operator.invite_code_whitelist:
        operator.invite_code_whitelist.append(requested_code)
        operator.invite_first_set_at = now()
        db.commit()
        return Decision.OK_FIRST_TIME  # UI 上提示"首次自助锁定"

    # 情况 4: 白名单非空 + 新码 → 拒绝
    return Decision.REJECT_NEEDS_ADMIN_APPROVAL
```

## 3. UX 场景速查

| # | 机场主输入 | 白名单状态 | Bot 反馈 |
|---|---|---|---|
| 1 | 不填 | 任意 | ✅ 普通包, 开始构建 |
| 2 | `AGENT_A` | `[]` (空) | ✅ **首次自助锁定** `AGENT_A`, 已入白名单. 之后换码需 @admin 授权. 开始构建 |
| 3 | `AGENT_A` | `[AGENT_A]` | ✅ 使用已锁定 `AGENT_A`, 开始构建 |
| 4 | `AGENT_B` | `[AGENT_A]` | ❌ 你的白名单只有 `AGENT_A`, 新增 `AGENT_B` 需 @admin 授权. 联系 @admin 用 `/admin approve_invite 12345 AGENT_B` |
| 5 | `AGENT_B` | `[AGENT_A, AGENT_B]` | ✅ 使用已锁定 `AGENT_B`, 开始构建 |
| 6 | `AGENT_C` (admin 刚 approve 完) | `[AGENT_A, AGENT_B, AGENT_C]` | ✅ 使用已锁定 `AGENT_C`, 开始构建 |

## 4. 命令 spec

### 机场主 (operator) 侧

打包命令按机器人现有交互模式加一个可选参数. 举例 (具体命令名以机器人现有风格为准):

```
/build <brand> [--fixed-invite=<code>]
```

- 不带 `--fixed-invite` → 情况 1
- 带 `--fixed-invite=<code>` → 走 gate_invite_code 判定, OK 就构建, REJECT 就报错

**必须堵死的**: 机器人任何 UI/命令**不能**给机场主提供直接编辑 `invite_code_whitelist` 的能力. 白名单只能通过打包时"首次自助"或 admin 命令增删.

### admin (你) 侧

```
/admin approve_invite <tg_id> <code>
    给某机场主白名单 append 一个邀请码 (你的收费/审批卡点)

/admin revoke_invite <tg_id> <code>
    从白名单移除 (撤销代理关系时用)

/admin list_invites <tg_id>
    查看某机场主完整白名单 + 首次锁定时间

/admin transfer_invite <from_tg_id> <to_tg_id> <code>
    可选. 一个代理关系从机场主 A 转移到 B (罕见, 但比"revoke 再 approve"少个中间态)
```

## 5. 触发 workflow_dispatch 时的 payload

判定通过后, 机器人正常构造 workflow_dispatch inputs 时把这个字段填进去 (跟 `xor_key` / `app_name` 等同级):

```json
{
  "ref": "main",
  "inputs": {
    "app_name":          "…",
    "xor_key":           "…",
    "oss_url_1":         "…",
    "fixed_invite_code": "AGENT_A",
    "…":                 "…"
  }
}
```

- 情况 1 (不填): 传 `""` 或省略 (workflow input 有 `default: ''`)
- 情况 2/3/5/6: 传对应的 code
- 情况 4: 根本不触发 workflow_dispatch, 直接给机场主报错

## 6. 迁移与兼容

- 老的 `authorized_users` 记录没有 `invite_code_whitelist` 字段, schema 新增字段时默认 `[]`
- 老机场主用不到这功能就完全无感, 打老流程包不受任何影响
- 想开始给代理打包时, **第一次**触发就是"自助锁定", 之后需要新代理时联系 admin

## 7. 审计 / 通知 (可选, 建议做)

关键操作打 admin 私聊通知, 事后能追溯:

| 事件 | 通知内容 |
|---|---|
| 机场主首次自助锁定 | `@Bob (12345) 首次锁定邀请码 AGENT_A` |
| 机场主用已锁定码触发打包 | (静默, 不通知) |
| 机场主用非白名单码被拒 | `@Bob (12345) 尝试用 AGENT_C 打包被拒, 白名单: [AGENT_A]. 需要审批?` |
| admin approve 新码 | `已给 @Bob 白名单加入 AGENT_C, 现有: [AGENT_A, AGENT_C]` |

## 8. 相关代码位置 (客户端 + workflow 已完成)

| 层 | 位置 |
|---|---|
| workflow input 定义 | `.github/workflows/build.yaml` `inputs.fixed_invite_code` |
| workflow env 转发 | 同上, `Build` + `Setup iOS project` 两步 `env.FIXED_INVITE_CODE` |
| 打包脚本 dart-define | `dreamrer/app` `setup.dart` `_dartDefinesArgs` / `_dartDefineList` |
| 客户端读取优先级 | `dreamrer/app` `lib/xboard/config/xboard_config.dart` `XBoardConfig.fixedInviteCode` (dart-define > OSS > 空) |
| 注册页 UI 锁定 | `dreamrer/app` `lib/xboard/features/auth/pages/register_page.dart` (`enabled: !_hasFixedInviteCode`) |
| OSS 层 fallback 字段名 | `contact.fixed_invite_code` (在 `lib/xboard/config/internal/xboard_config_accessor.dart` 里 parse) |

机器人只要把 workflow_dispatch 的 `inputs.fixed_invite_code` 填对, 端到端整条链就通了.
