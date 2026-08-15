# CrystalBest 加密交易所系统源码

<p align="left">
  <img src="https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white" alt="PHP 8.2">
  <img src="https://img.shields.io/badge/ThinkPHP-6-1BA784" alt="ThinkPHP 6">
  <img src="https://img.shields.io/badge/Node.js-17.9.x-339933?logo=nodedotjs&logoColor=white" alt="Node.js 17.9.x">
  <img src="https://img.shields.io/badge/MySQL-8-4479A1?logo=mysql&logoColor=white" alt="MySQL 8">
  <img src="https://img.shields.io/badge/Redis-Realtime-DC382D?logo=redis&logoColor=white" alt="Redis">
  <img src="https://img.shields.io/badge/Cloudflare-R2-F38020?logo=cloudflare&logoColor=white" alt="Cloudflare R2">
  <img src="https://img.shields.io/badge/Ledger-Double--Entry-4C1" alt="Double-entry Ledger">
</p>

> 一套包含 **现货、永续合约、资产账本、钱包充提、C2C、风险控制、自动强平、对账与独立管理后台** 的数字资产交易系统。
> **上述只包含片段代码**，如需全部功能，请联系 telegram : https://t.me/lonmenhr
> 文档版本：2026-08-15

---

## 在线演示

- 用户站：<https://crystalbest.top/>
- 登录页：<https://crystalbest.top/login>


<summary><b>演示登录信息</b></summary>

```text
host@hmailx.com
Qaz@741852
```



---
## 联系我们
- telegram : https://t.me/lonmenhr

## 网站预览



<table>
  <tr>
    <td width="50%" valign="top">
      <a id="preview-home"></a>
      <b>首页 / 市场</b><br><br>
      <img src="https://media.crystalbest.top/host/1.png" alt="CrystalBest 首页" width="100%">
    </td>
    <td width="50%" valign="top">
      <a id="preview-spot"></a>
      <b>现货交易</b><br><br>
      <img src="https://media.crystalbest.top/host/2.png" alt="CrystalBest 现货交易" width="100%">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <a id="preview-perp"></a>
      <b>永续合约</b><br><br>
      <img src="https://media.crystalbest.top/host/3.png" alt="CrystalBest 永续合约" width="100%">
    </td>
    <td width="50%" valign="top">
      <a id="preview-c2c"></a>
      <b>C2C / P2P</b><br><br>
      <img src="https://media.crystalbest.top/host/5.png" alt="CrystalBest C2C" width="100%">
    </td>
  </tr>
  <tr>
    <td colspan="2" valign="top">
      <a id="preview-admin"></a>
      <b>独立管理后台</b><br><br>
      <img src="https://media.crystalbest.top/host/4.png" alt="CrystalBest 管理后台" width="100%">
    </td>
  </tr>
</table>

建议截图文件：

```text
docs/screenshots/
├─ home.png
├─ spot.png
├─ perpetual.png
├─ c2c.png
└─ admin.png
```

---

## 项目定位

CrystalBest 以 **内部双边账本（Double-entry Ledger）** 为资金核心。

外部 OKX / Binance 主要用于提供公开行情参考；用户的订单、成交、手续费、持仓、盈亏、保证金、C2C 托管等，由 CrystalBest 自己的数据库、执行服务和 Ledger 完成内部结算。

**核心原则：外部交易所提供参考价格，不直接承接 CrystalBest 用户订单。**

---

## 核心功能

| 模块 | 功能 |
|---|---|
| 行情系统 | Ticker、Depth、BBO、Mark、Index、Funding、Kline |
| 现货交易 | LIMIT 限价单、Hold、Trade、Fill、手续费结算 |
| 永续合约 | 杠杆、Position、PnL、保证金、风险状态 |
| 自动强平 | 账户级风险扫描、Reduce-Only 强平、正常 Settlement 复用 |
| 资产账本 | 双边 Ledger、余额、冻结、Journal、Entry |
| 钱包 | 充值地址、链上事件、充值、提现、人工审核 |
| C2C / P2P | 商家、广告、实名收款方式、Crypto 托管、订单流程 |
| 用户中心 | 登录、注册、KYC、TOTP、OAuth、设备与 Session |
| OpenAPI | API Key + HMAC 的只读账户接口 |
| 对账 | Ledger Reconciler 只读检查，不自动修改资金 |
| 管理后台 | 用户、资产、订单、Ledger、风险、提现、KYC、审计 |

---

## 技术栈

### 用户主站

- PHP 8.2
- ThinkPHP 6 / ThinkORM / ThinkPHP View
- HTML / CSS / 原生 JavaScript
- MySQL 8
- Cloudflare R2 私有对象存储
- Google Authenticator / TOTP
- Google / Microsoft OIDC
- Captcha / 邮件验证码

### 后台服务

- Node.js 17.9.x
- JavaScript ES Modules
- mysql2
- ws
- Redis / ioredis
- dotenv
- ULID

### 基础设施

- MySQL：业务数据与资金 Ledger 的最终事实来源
- Redis：实时行情热数据与 Pub/Sub，不作为资金账本
- Cloudflare R2：KYC、头像、C2C 二维码等私有文件

---

## 系统架构

```text
OKX / Binance
      │
      ▼
Market Collector
      │
      ▼
统一行情事件
      │
      ▼
    Redis
   ┌──┴───────────────┐
   ▼                  ▼
Public Gateway   Execution Gateway
   │                  │
   ▼                  ▼
浏览器 / K线       Spot / Perp Worker
                      │
                      ▼
                  MySQL + Ledger
```

### 行情链路

- **Market Collector**：唯一直接连接外部交易所的实时行情采集服务。
- **Public Gateway**：向浏览器提供 HTTP / WebSocket 行情。
- **Execution Gateway**：向 Spot Worker / Perp Engine 提供内部执行行情。
- **Reference Sync**：同步交易对、合约、资产、网络、精度等市场基础资料。

---

## 现货交易

当前核心执行模型为 **LIMIT 限价单**。

```text
BUY  : Best Ask <= Limit Price
SELL : Best Bid >= Limit Price
```

下单后由 ThinkPHP 创建订单与 Hold，Spot Worker 根据 BBO 判断是否触发成交，并在 MySQL Transaction 中完成：

```text
Order Lock
→ Hold Check
→ Ledger Settlement
→ Trade
→ Fill
→ Fee
→ Order FILLED
→ Hold CONSUMED
```

现货系统内部使用 `PRIMARY_SPOT_LIQUIDITY` 作为流动性对手方。

> 外部 Depth / BBO 主要用于价格触发与展示，外部盘口数量不作为 CrystalBest 内部成交数量上限。

---

## 永续合约

Perp Engine 负责：

- Limit Order 触发；
- Position 更新；
- Realized / Unrealized PnL；
- Initial / Maintenance Margin；
- Fee；
- Risk State；
- Liquidation；
- Ledger Settlement。

永续系统内部使用 `SYS_PERP_LIQUIDITY` 作为 PnL / 流动性对手账户。

### 风险模型

```text
Equity = Wallet Balance + Unrealized PnL
```

自动强平核心条件：

```text
Equity <= Maintenance Margin
```

强平会撤销未成交永续订单、释放 Hold、创建 Reduce-Only 系统强平订单，并复用正常 Fill / Settlement / Ledger 链路，而不是直接修改 Position 数量。

---

## Ledger 资金账本

所有重要资金动作都应通过双边账本完成。

核心结构：

```text
Ledger Transaction / Journal
        │
        ├─ Ledger Entry
        ├─ Ledger Entry
        └─ ...
```

核心表：

```text
cex_asset_ledger_accounts
cex_asset_ledger_transactions
cex_asset_ledger_entries
cex_asset_balances
cex_asset_holds
```

关键约束：

- 资金变更不能绕过 Ledger 直接 UPDATE Balance；
- Hold 用于订单、提现、C2C 等已占用资金；
- 每个资金事件需要可追溯、可审计、可对账；
- 历史 Fill 保存成交与手续费事实。

---

## 钱包与充提

### 充值

```text
选择 Asset / Network
→ 分配地址
→ 链上转账
→ Custody / Scanner 发现交易
→ Deposit Accounting
→ Ledger 入账
→ 用户余额增加
```

### 提现

```text
用户申请
→ 创建 Withdrawal + Hold
→ 管理员审核
→ 人工打款
→ 登记真实 Tx Hash
→ 确认完成
```

失败或拒绝时，通过 Ledger Refund 释放资金。

---

## C2C / P2P

C2C 支持 BTC / USDT / ETH 与 CNY 的用户间交易。

核心规则：

- CrystalBest 只托管 Crypto；
- CNY 由交易双方通过实名支付方式直接支付；
- 支持支付宝、微信、银行卡；
- 商家需完成 KYC 并缴纳保证金；
- Crypto 使用 `SYS_C2C_ESCROW` 托管；
- 超时/取消通过 Ledger Refund 退还原卖方。

典型买币流程：

```text
用户选择 SELL 广告
→ 创建订单
→ 商家 Crypto 进入 Escrow
→ 用户线下支付 CNY
→ 用户标记已付款
→ 商家确认到账
→ Escrow 释放 Crypto 给用户
→ COMPLETED
```

---

## 用户与安全

系统包含：

- 邮箱登录 / 注册；
- Captcha 与邮箱验证码；
- Google Authenticator / TOTP；
- Google / Microsoft OAuth；
- Device Identity / Session 管理；
- KYC；
- 私有 R2 文件存储；
- API Key + HMAC；
- Internal API Signature；
- MySQL Transaction / Row Lock；
- Idempotency；
- Admin 独立 Session；
- Ledger Reconciliation。

---

## 用户页面

主要页面：

```text
/
/markets
/trade-spot/:symbol
/trade-swap/:symbol
/c2c
/login
/register
/forgot-password
/dashboard
/dashboard/assets
/dashboard/deposit
/dashboard/withdraw
/dashboard/transfer
/dashboard/spot-orders
/dashboard/perpetual-orders
/dashboard/trade-history
/dashboard/positions
/dashboard/security
/dashboard/kyc
/dashboard/api
```

---

## 管理后台

独立管理后台主要提供：

- Dashboard；
- 用户与 KYC；
- 资产与 Ledger；
- Holds；
- Spot / Perp Order 与 Fill；
- Position / Risk / Liquidation；
- 提现审核与人工打款；
- 市场与手续费配置；
- Reconciliation；
- 管理员账号；
- Audit；
- 系统健康检查。

管理员人工入账同样必须通过 Ledger Journal，不允许直接修改余额。

---

## Ledger Reconciler

`crystalbest-ledger-reconciler` 用于 DAILY / MANUAL 对账检查。

检查内容包括：

- Ledger Entry Chain；
- Balance Cache；
- Journal 平衡；
- Hold 一致性；
- 业务实体与 Ledger 关系；
- 手续费等可核验数据。

> Reconciler 只记录差异和修复建议，不自动 UPDATE 用户资金。

---

## 主要服务

| 服务 | 技术 | 职责 |
|---|---|---|
| CrystalBest 用户站 | PHP / ThinkPHP 6 | 用户、API、资产、订单、KYC、钱包、C2C |
| Market Collector | Node.js | 外部实时行情采集 |
| Market Public Gateway | Node.js | 浏览器行情 HTTP / WS |
| Execution Gateway | Node.js | Spot / Perp 内部执行行情 |
| Market Data Sync | Node.js | 市场与资产 Reference Metadata |
| Spot Worker | Node.js | Spot 触发与内部结算 |
| Perp Engine | Node.js | Fill、Position、PnL、Risk、Liquidation |
| User Worker | Node.js | 用户异步任务、Wallet Allocation |
| Ledger Reconciler | Node.js | 账务一致性检查 |
| Admin | PHP / ThinkPHP 6 | 独立管理后台 |
| MySQL | MySQL 8 | 业务与 Ledger 持久化 |
| Redis | Redis | 实时行情热状态 / PubSub |

---

## 当前功能边界

以下能力当前不是稳定核心功能：

- 用户 Spot / Perp 订单不会直接发送到 OKX / Binance；
- Funding Reference 可展示，但 Funding Settlement 当前关闭；
- Perp 正式结算路径仍以 LIMIT 为主；
- TP / SL / Conditional Order 尚未作为稳定核心交易链；
- C2C Appeal 已预留，但完整管理员裁决工作流仍待完善；
- C2C 商家保证金退出 / 扣罚流程仍待完善；
- C2C 聊天、评价、拉黑、浮动广告价格当前未实现。

---

## 核心设计原则

1. **行情与资金分离。**
2. **外部盘口只提供参考价格，不代表平台实际库存。**
3. **用户订单由 CrystalBest 内部 Worker 执行。**
4. **所有资金变更通过 Double-entry Ledger。**
5. **Hold 控制已占用资金。**
6. **历史 Fill 保留成交与手续费事实。**
7. **永续风险以 Equity / Maintenance Margin 为核心。**
8. **自动强平复用正常交易结算链。**
9. **Reconciler 只检查，不自动改钱。**
10. **C2C 只托管 Crypto，法币由双方直接支付。**
11. **管理员资金操作必须保留 Ledger / Audit。**

---

## 文档维护

涉及以下模块的重大修改，建议同步更新 README：

- Spot / Perp Settlement；
- Liquidation；
- Ledger；
- Withdrawal；
- C2C Escrow；
- Fee；
- Market Data Source；
- Admin Manual Credit；
- Reconciliation。

---

## 免责声明

本文档用于描述当前代码与部署架构。若 README 与实际生产代码、数据库结构或服务配置存在差异，应以实际运行版本为准。
