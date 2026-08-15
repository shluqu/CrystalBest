# CrystalBest加密交易所系统源码

> 文档版本：2026-08-15  
> 系统范围：CrystalBest 用户交易端、行情数据链路、现货执行、永续合约执行、资产账本、钱包/充提、用户异步任务、Ledger 对账、独立管理员后台、C2C 法币交易模块。  
> 本文档按当前代码快照与后续已部署增量整理；如果代码与本文档发生冲突，以生产代码和数据库实际状态为准。

---

#
# 0. 演示登录

<a href="[[https://admin.crystalbest.top/login](https://crystalbest.top/login)](https://crystalbest.top/login)" target="_blank">[[后台登录](https://crystalbest.top/login)](https://crystalbest.top/login)</a>

演示账号：`host@hmailx.com`
演示账号：`Qaz@741852`

---

# 1. 系统定位

CrystalBest 是一套以 **内部双边账本（Double-entry Ledger）为资金核心** 的数字资产交易系统。

当前主要业务包括：

- 数字资产行情展示；
- 现货交易；
- 永续合约交易；
- 账户资产与内部划转；
- 充值与提现；
- 用户安全、KYC、API Key；
- 自动强平与风险状态；
- Ledger 自动对账；
- 独立管理员后台；
- C2C / P2P 法币交易市场。

系统的一个关键设计是：

**外部交易所主要提供公开行情参考数据，CrystalBest 不把用户订单直接发送到 OKX / Binance。**

用户的订单、成交、手续费、持仓、盈亏、保证金、C2C 托管等，均由 CrystalBest 自己的数据库、执行 Worker 和 Ledger 完成内部结算。

---

# 2. 开发语言与基础技术栈

## 2.1 用户交易主站

主要技术：

- **PHP**
- **ThinkPHP 6**
- **ThinkORM**
- **ThinkPHP View**
- HTML
- CSS
- 原生 JavaScript
- MySQL 8
- Cloudflare R2 私有对象存储
- Google Authenticator / TOTP
- Google / Microsoft OIDC
- 图形验证码
- 邮件验证码

主站负责：

- 页面；
- 用户身份；
- 登录注册；
- KYC；
- 资产查询；
- 下单入口；
- 订单取消；
- 用户主动平仓；
- 充值提现；
- C2C；
- OpenAPI；
- 各业务 Controller / Service；
- 将交易任务写入 MySQL，等待对应 Node Worker 执行。

生产部署目标使用 PHP 8.2。  
需要注意：服务器 SSH 中的默认 `php` 命令可能不是网站实际使用的 PHP 版本，因此 ThinkPHP CLI 任务应使用网站对应的 PHP CLI 路径执行。

---

## 2.2 Node.js 后台服务

交易与基础设施 Worker 主要使用：

- **Node.js 17.9.x**
- JavaScript ES Modules
- mysql2
- ws
- Redis / ioredis
- dotenv
- ULID

主要 Node 服务：

1. Market Collector / Market Fabric
2. Market Public Gateway
3. Private Execution Gateway
4. Spot Reference Execution Worker
5. Perp Engine
6. Market Data Synchronization
7. User Worker
8. Ledger Reconciler

---

## 2.3 数据库

主要数据库：

- **MySQL 8**

数据库承担：

- 用户资料；
- 登录安全；
- KYC；
- 资产定义；
- 网络定义；
- Ledger；
- Holds；
- Spot 订单 / Trade / Fill；
- Perp 订单 / Trade / Fill / Position；
- Risk State；
- Liquidation；
- Wallet；
- Deposit / Withdrawal；
- C2C；
- Admin；
- Reconciliation；
- Audit / Outbox。

金额字段以高精度 `DECIMAL` 为主。

业务代码尽量避免使用二进制浮点数直接处理资金。

---

## 2.4 Redis

行情实时链路使用 Redis：

- Redis 地址：私网 `10.0.0.1`
- 实时 Market Fabric 使用独立 Redis DB / Key Prefix；
- Redis 保存行情热状态；
- Redis Pub/Sub 负责标准化行情事件广播。

Redis **不是资金账本**。

用户真实账务最终以 MySQL Ledger 为准。

---

# 3. 服务器与服务拓扑

当前交易相关服务主要分为两部分：

```text
10.0.0.1
├─ Market Collector
│  ├─ OKX WebSocket / REST
│  └─ Binance WebSocket / REST
│
├─ Market Public Gateway :3112
│  └─ 给浏览器 / 前端行情使用
│
└─ Redis
   └─ cex:md:unified:*


10.0.0.2
├─ ThinkPHP 主站
├─ MySQL
├─ Spot Worker
├─ Perp Engine
├─ User Worker
├─ Ledger Reconciler
│
└─ Private Execution Gateway
   └─ 127.0.0.1:3100
```

其中：

**Execution Gateway 固定为：**

```text
127.0.0.1:3100
```

Spot Worker 和 Perp Engine 都通过：

```text
ws://127.0.0.1:3100/ws/v2
```

读取执行所需的行情。

---

# 4. 行情数据是怎么来的

CrystalBest 有两类完全不同的“行情同步”。

---

## 4.1 市场基础资料同步

服务：

```text
cex_market_data_synchronization
```

这是一个 Node.js 服务。

它主要同步：

- 现货交易对；
- 永续合约；
- Asset；
- Network；
- 交易所 Source Mapping；
- Tick Size；
- Quantity Step；
- 市场启用状态；
- 参考市场元数据。

它通过 OKX / Binance 的公开接口获取 **市场元数据**，再规范化写入 CrystalBest MySQL。

它不是实时撮合行情服务。

主要数据库表包括：

```text
cex_market_spot_symbols
cex_market_spot_symbol_sources

cex_market_perpetual_contracts
cex_market_perpetual_contract_sources

cex_asset_assets
cex_asset_networks
cex_asset_asset_networks
cex_asset_asset_sources
cex_asset_network_route_sources
```

因此：

**“有哪些币、有哪些交易对、价格精度是多少”属于 Reference Sync。**

---

## 4.2 实时行情采集

实时行情由 Market Fabric 负责。

核心链路：

```text
OKX / Binance
      ↓
Market Collector
      ↓
统一标准化事件
      ↓
Redis
      ├──────────────────────────────┐
      ↓                              ↓
Public Gateway :3112       Execution Gateway :3100
      ↓                              ↓
浏览器页面                  Spot / Perp Worker
```

---

## 4.3 Collector

Collector 位于 `10.0.0.1`。

它是行情系统中**唯一允许直接连接 OKX / Binance** 的角色。

职责：

- 打开交易所 WebSocket；
- 必要时访问公开 REST；
- 接收 depth；
- ticker；
- mark price；
- index price；
- funding；
- Kline；
- 将不同交易所的数据转换成 CrystalBest 统一事件格式；
- 写入 Redis 热状态；
- 发布 Redis Pub/Sub 事件。

---

## 4.4 Public Gateway

Market Public Gateway 位于：

```text
10.0.0.1:3112
```

主要给：

- 首页；
- 市场页；
- 交易页；
- K 线图；

提供 HTTP / WebSocket。

Public Gateway 自己不直接连接交易所。

浏览器使用同域路径：

```text
/md-api
/md-ws/
/chart-api
/chart-ws
```

实际部署时由站点反向代理转发到 Market Public Gateway。

这样浏览器不需要知道内网 `10.0.0.1:3112`。

---

## 4.5 Private Execution Gateway

服务：

```text
crystalbest-market-execution-gateway
```

固定：

```text
127.0.0.1:3100
```

这个 Gateway：

- 不直接访问 OKX；
- 不直接访问 Binance；
- 不做外部交易所 REST fallback；
- 从 Redis 读取统一行情；
- 只为本机 Spot Worker / Perp Engine 提供 V2 WebSocket / 健康接口。

它是执行服务与外部行情之间的一层隔离。

---

# 5. 行情数据的职责划分

当前 Market Fabric 的价格职责大致为：

## 5.1 Depth / BBO

```text
Binance Depth10
```

主要用于：

- 最优买价 Best Bid；
- 最优卖价 Best Ask；
- 订单簿展示；
- Spot 限价单触发；
- Perp 限价单触发。

重要：

**外部订单簿数量只用于展示/价格参考，不限制 CrystalBest 内部成交数量。**

例如用户买 10 BTC，而 Binance 当前最优卖价数量只有 0.1 BTC：

CrystalBest 当前模型不会因为外部盘口只有 0.1 BTC 就只成交 0.1 BTC。

外部 Depth 的核心作用是：

```text
价格触发参考
```

而不是：

```text
CrystalBest 流动性库存上限
```

---

## 5.2 永续 Mark / Index / Funding

当前设计：

```text
Mark Price:
OKX Primary
Binance Fallback

Index Price:
OKX Primary
Binance Fallback

Funding Reference:
OKX Primary
Binance Fallback
```

Funding 行情可以展示，但是当前 **Funding Settlement 未启用**。

也就是说：

- 可以展示资金费率参考；
- 当前不会周期性从用户账户正式扣/加 Funding Payment。

---

## 5.3 K 线

K 线走 Market Data API V3。

支持周期：

```text
分时
1m
5m
15m
1h
4h
1d
1M
1Y
```

流程：

```text
浏览器
↓
HTTP 加载历史 Kline
↓
WebSocket 订阅实时 Kline
↓
Lightweight Charts / 前端图表
```

前端会先加载 HTTP 历史数据，再使用 WebSocket 增量更新最新 Candle。

---

# 6. 平台“交易数据”是怎么产生的

这里必须区分：

## 外部行情数据

来自：

```text
OKX / Binance
```

包括：

- BBO；
- Depth；
- Ticker；
- Mark；
- Index；
- Funding；
- Kline。

## CrystalBest 自己的交易数据

不是从 OKX / Binance 复制来的。

CrystalBest 自己生成：

```text
Order
Trade
Fill
Position
Realized PnL
Unrealized PnL
Fee
Hold
Ledger
Risk State
Liquidation
```

实际数据保存在 CrystalBest MySQL。

也就是说：

> 外部交易所决定“参考价格”，CrystalBest 自己决定“用户订单和账务状态”。

---

# 7. Ledger：整个资金系统的核心

CrystalBest 所有重要资金动作都应该走双边账本。

核心表：

```text
cex_asset_ledger_accounts
cex_asset_ledger_transactions
cex_asset_ledger_entries
cex_asset_balances
cex_asset_holds
```

---

## 7.1 Ledger Account / 账本科目

例如：

```text
ACC12-A3-S2-B1
```

含义：

```text
ACC12 = Account 12
A3    = Asset 3
S2    = Perpetual / 永续场景
B1    = Available / 可用余额
```

所以它表示：

```text
Account 12
USDT
永续合约
可用余额
```

账本科目不是“用户账号”。

它是：

```text
账户 × 币种 × 资金场景 × 余额类型
```

形成的唯一记账单元。

---

## 7.2 Available / Locked

常见余额类型：

```text
B1 = 可用余额
B2 = 冻结余额
```

下订单时通常：

```text
可用
↓
Hold
↓
冻结
```

订单：

- 成交：冻结资金进入 Settlement；
- 取消：冻结资金释放回可用。

---

## 7.3 Journal / Entry

一次完整资金事件产生：

```text
Ledger Transaction / Journal
        ↓
多个 Ledger Entry
```

所有 Posting 必须平衡。

例如现货买入：

```text
用户冻结 USDT        -
系统流动性 USDT      +

系统流动性 BTC       -
用户 BTC             +
手续费账户 BTC       +
```

每个资产维度都必须平衡。

---

# 8. 现货交易执行逻辑

现货由两部分组成：

```text
ThinkPHP
负责创建 / 取消订单、Hold、页面/API

Spot Worker
负责监听行情并执行成交
```

---

## 8.1 当前订单类型

当前核心执行模型为：

```text
LIMIT 限价单
```

BUY：

```text
Best Ask <= 用户限价
→ 可以成交
```

SELL：

```text
Best Bid >= 用户限价
→ 可以成交
```

---

## 8.2 下单过程

用户在 Spot 页面提交：

```text
交易对
方向
限价
数量
```

ThinkPHP 会检查：

- 用户是否登录；
- 交易对是否启用；
- Asset 是否启用；
- Price Tick；
- Quantity Step；
- 最小数量 / 最小名义价值；
- 用户可用余额；
- 是否存在足够资金。

然后创建：

```text
cex_spot_orders
cex_asset_holds
cex_spot_order_events
```

资金从可用状态进入冻结。

---

## 8.3 Spot Worker 执行

Spot Worker 持续订阅：

```text
ws://127.0.0.1:3100/ws/v2
```

收到 Depth / BBO 后：

```text
检查订单
↓
判断价格条件
↓
重新在 MySQL Transaction 中锁订单
↓
重新检查 Hold
↓
执行 Ledger
↓
写 Trade
↓
写 Fill
↓
订单 FILLED
↓
Hold CONSUMED
```

---

## 8.4 Spot 对手方

现货不是把订单发给 Binance。

当前使用内部系统流动性账户：

```text
PRIMARY_SPOT_LIQUIDITY
```

作为系统对手方。

因此：

```text
用户 BUY
= 系统流动性 SELL

用户 SELL
= 系统流动性 BUY
```

外部 BBO 只是成交价格触发参考。

---

## 8.5 Spot 手续费

用户是 Taker。

手续费按照成交时的规则计算。

Fee 进入：

```text
SYS_TRADING_FEE
```

新版本 Fill 支持保存手续费快照信息，用于保证：

> 后续修改当前手续费率，不会改变历史成交已经发生的手续费事实。

---

# 9. 永续合约执行逻辑

永续合约核心服务：

```text
ThinkPHP
+
Perp Engine v1.9.2
+
Automatic Liquidation 增量
```

---

## 9.1 永续下单

用户通过：

```text
/trade-swap/:symbol
```

创建：

- Buy / Long；
- Sell / Short；
- 限价；
- 数量；
- 杠杆。

主站计算并冻结订单所需保证金。

写入：

```text
cex_perp_orders
cex_asset_holds
cex_perp_order_events
```

---

## 9.2 Perp Engine 价格触发

与 Spot 类似：

BUY LIMIT：

```text
Best Ask <= 用户 Limit
```

SELL LIMIT：

```text
Best Bid >= 用户 Limit
```

当前外部 Depth 数量不限制内部 Full Fill。

---

## 9.3 永续系统对手方

系统账户：

```text
SYS_PERP_LIQUIDITY
```

作为内部 Perp PnL / 流动性对手方。

用户盈利：

```text
SYS_PERP_LIQUIDITY
→ 用户
```

用户亏损：

```text
用户
→ SYS_PERP_LIQUIDITY
```

这属于内部账本结算模型。

---

## 9.4 一次 Perp Fill 会做什么

在同一个 MySQL Transaction 中完成：

- 锁订单；
- 校验 Hold；
- 写 Trade；
- 写 Fill；
- 更新 Order；
- 更新 Position；
- 写 Position Event；
- 写 Order Event；
- 结算手续费；
- 结算已实现 PnL；
- 调整保证金；
- 更新 Risk State；
- 写 Reference Execution Attempt；
- 写 Outbox；
- 提交 Ledger。

这样可以避免：

```text
订单成交了
但 Position 没更新
```

或者：

```text
Position 更新了
但 Ledger 没记账
```

这种跨表半完成状态。

---

# 10. 永续 Position 模型

当前按账户 + 合约维护 Position。

主要字段：

- Position Quantity；
- Entry Price；
- Realized PnL；
- Unrealized PnL；
- Initial Margin；
- Maintenance Margin；
- Mark Price；
- Liquidation Price / Estimate；
- Position Status。

同方向加仓：

```text
重新计算加权 Entry
```

反方向交易：

```text
先减少旧仓
↓
产生 Realized PnL
↓
如果数量超过旧仓
→ Reverse 成反向新仓
```

---

# 11. 永续风险系统

Risk State 表：

```text
cex_perp_account_risk_states
```

核心变量：

```text
Wallet Balance
Unrealized PnL
Equity
Position Initial Margin
Order Initial Margin
Maintenance Margin
Available Margin
Margin Ratio
Risk Status
```

最重要：

```text
Equity
=
Wallet Balance + Unrealized PnL
```

自动强平核心阈值：

```text
Equity <= Maintenance Margin
```

而不是：

```text
Available Margin <= 0
```

所以“可用保证金为 0”并不等于立刻强平。

---

# 12. 自动强平

Automatic Liquidation 已作为 Perp Engine 增量加入。

当前配置模型包括：

```text
AUTO_LIQUIDATION_ENABLED
SCAN_MS
SCAN_BATCH
PRICE_PROTECTION_BPS
MAX_ATTEMPTS
```

当前生产部署曾验证的扫描周期为：

```text
250 ms
```

---

## 12.1 强平触发

账户级扫描：

```text
Equity <= Maintenance Margin
```

进入强平流程。

---

## 12.2 强平处理顺序

```text
风险账户触发
↓
标记 LIQUIDATION_REQUIRED / LIQUIDATING
↓
阻止用户继续增加永续风险
↓
撤销该账户所有未成交 Perp Order
↓
释放对应 Order Hold
↓
重新计算风险
↓
为剩余 Position 创建系统 Reduce-Only 强平订单
↓
按照参考 BBO / 保护价格执行
↓
复用正常 Perp Fill + Settlement
↓
Position 归零
↓
更新 Liquidation Case
```

强平不是直接：

```sql
UPDATE position_quantity = 0
```

而是尽量复用正常订单 / Fill / Ledger Settlement 链路。

---

## 12.3 强平数据表

```text
cex_perp_liquidations
cex_perp_liquidation_positions
```

用于记录：

- 触发权益；
- 触发维持保证金；
- 触发保证金率；
- 参与强平的 Position；
- 系统强平 Order；
- 状态；
- 失败原因；
- 开始/完成时间。

---

## 12.4 强平手续费

当前 V1：

- 不额外增加独立 Liquidation Fee；
- 强平 Fill 仍按正常 Taker Fee 规则收取成交手续费。

历史手续费通过 Fill Snapshot 保存。

---

# 13. Funding

当前：

```text
Funding Reference 行情：有
Funding 数据表：有
Funding Settlement：关闭
```

所以页面可以显示 Funding Rate，但系统目前不执行周期性用户 Funding 收付。

---

# 14. 首页 `/`

首页功能包括：

- CrystalBest 品牌入口；
- 登录 / 注册；
- 市场导航；
- Spot / Perp / C2C 入口；
- 实时热门行情；
- 涨幅榜；
- 成交额榜；
- 永续市场；
- 新币/新市场；
- 新手入口；
- 安全与产品介绍；
- 用户账户快捷入口。

行情：

```text
GET /md-api/pages/markets
+
WebSocket /md-ws/
```

页面首次通过 HTTP 获取完整快照，然后使用 WebSocket 更新。

---

# 15. 市场页 `/markets`

市场页负责整个交易市场浏览。

主要功能：

- 搜索币种/交易对；
- Spot；
- Perpetual；
- 收藏；
- 热门；
- 涨幅；
- 成交额；
- 24H 价格；
- 24H High / Low；
- 24H Volume；
- Best Bid / Ask；
- 跳转交易页面。

这里：

```text
/api/markets
```

主要是平台 DB Market Catalog。

真正实时 ticker 主要由：

```text
/md-api
/md-ws
```

补充。

---

# 16. 现货交易页 `/trade-spot/:symbol`

页面组成：

- 市场选择器；
- 当前价格；
- 24H 涨跌；
- 24H High / Low；
- 24H Volume；
- K 线；
- Depth；
- BBO；
- 买入区；
- 卖出区；
- 当前委托；
- 历史订单；
- 用户资产余额。

行情来源：

```text
/md-api/trade/spot/{symbol}/panel
/md-ws/
/chart-api
/chart-ws
```

交易数据来源：

```text
/api/trade-spot/account
/api/trade-spot/orders
```

下单：

```text
POST /api/trade-spot/orders
```

取消：

```text
POST /api/trade-spot/orders/:order/cancel
```

---

# 17. 永续交易页 `/trade-swap/:symbol`

页面组成：

- 合约选择；
- Mark；
- Index；
- Funding Reference；
- BBO；
- Depth；
- K 线；
- Buy / Sell；
- 杠杆；
- 保证金估算；
- 当前委托；
- 历史委托；
- 当前仓位；
- Entry；
- Mark；
- Unrealized PnL；
- Initial Margin；
- Maintenance Margin；
- Estimated Liquidation Price；
- 平仓。

接口：

```text
GET  /api/trade-swap/account
GET  /api/trade-swap/orders
GET  /api/trade-swap/positions

POST /api/trade-swap/orders
POST /api/trade-swap/orders/:order/cancel

POST /api/trade-swap/leverage
POST /api/trade-swap/positions/:position/close
```

平仓走 Reduce-Only / 正常 Settlement 逻辑，不直接修改 Position。

---

# 18. 登录页 `/login`

支持：

- 邮箱登录；
- 密码；
- Captcha；
- 邮箱二次验证码流程；
- Google 登录；
- Microsoft 登录；
- Remember / Session；
- Device Identity；
- 登录风控。

成功登录后：

```text
创建 cex_user_sessions
↓
设置用户 Session Cookie
↓
进入 Dashboard
```

---

# 19. 注册页 `/register`

流程：

```text
输入邮箱
↓
图形验证码
↓
发送邮箱验证码
↓
验证邮箱
↓
设置登录信息
↓
创建 User
↓
创建 Account
↓
触发 Wallet Allocation 异步任务
```

用户相关数据进入：

```text
cex_user_users
cex_user_credentials
cex_user_security
cex_account_accounts
```

---

# 20. 忘记密码 `/forgot-password`

三阶段：

```text
确认账户
↓
邮箱验证码
↓
验证通过
↓
设置新密码
```

密码不会明文保存。

---

# 21. Dashboard `/dashboard`

用户登录后的总览。

主要显示：

- UID；
- 昵称；
- KYC 状态；
- 安全状态；
- 总资产；
- Spot 资产；
- Perp 资产；
- 快速充值；
- 提现；
- 划转；
- Spot 交易；
- Perp 交易；
- 当前 Position；
- 最近订单；
- 安全入口。

资产估值可以使用实时 Spot Ticker 计算参考价值。

---

# 22. 个人资料 `/dashboard/profile`

功能：

- 查看 UID；
- 查看账户基本信息；
- 修改昵称；
- 上传头像；
- 查看注册信息。

接口：

```text
GET  /api/profile
POST /api/profile/nickname
POST /api/profile/avatar/upload
```

---

# 23. 安全中心 `/dashboard/security`

包含：

- 登录密码修改；
- 安全邮箱；
- 邮箱验证码；
- Google Authenticator；
- Google Social Account；
- Microsoft Social Account；
- 登录 Session；
- 其他设备踢下线。

TOTP：

```text
Setup Secret
↓
用户扫码
↓
输入当前 TOTP
↓
Enable
```

---

# 24. 登录设备 `/dashboard/security/devices`

显示：

- 当前设备；
- 历史登录；
- User-Agent；
- IP；
- 登录时间；
- Session 状态。

支持：

- 撤销指定 Session；
- 撤销其他 Session。

---

# 25. KYC `/dashboard/kyc`

用户提交：

- 法定姓名；
- 证件类型；
- 证件号码；
- 正面文件；
- 反面文件（如果需要）；
- 其他 KYC 字段。

证件不是公开 URL。

文件进入私有 R2。

数据库：

```text
cex_user_kyc
cex_user_kyc_actions
```

状态由管理端审核。

C2C 收款账户姓名会与 KYC 实名姓名匹配。

---

# 26. 资产中心 `/dashboard/assets`

展示：

- 币种；
- Spot 可用；
- Spot 冻结；
- Perp 可用；
- Perp 冻结；
- 总额；
- 估值；
- 充值；
- 提现；
- 划转。

数据核心来自 Ledger Balance Cache。

---

# 27. 充值 `/dashboard/deposit`

流程：

```text
用户选择 Asset
↓
选择 Network
↓
获取/分配充值地址
↓
链上转账
↓
Custody / Scanner 发现交易
↓
内部 deposit-event
↓
Deposit Accounting
↓
Ledger 入账
↓
用户可用余额增加
```

页面展示：

- Asset；
- Network；
- Address；
- QR；
- Memo / Tag（如网络需要）；
- 最近充值记录；
- 确认状态。

---

# 28. 提现 `/dashboard/withdraw`

用户侧流程：

```text
选择币种
↓
选择网络
↓
填写地址
↓
填写数量
↓
费用计算
↓
提交提现
↓
创建 Hold / Withdrawal
↓
等待管理员审核
```

用户在允许状态下可以取消。

取消时必须释放相应 Hold，不直接改 Balance。

---

# 29. 内部划转 `/dashboard/transfer`

当前重点是同一用户账户内部资金场景转换，例如：

```text
USDT Spot Available
↔
USDT Perpetual Available
```

使用 Ledger Journal 完成。

不是简单 UPDATE 两个 Balance。

---

# 30. 现货订单 `/dashboard/spot-orders`

展示：

- Spot Order；
- Symbol；
- Buy / Sell；
- Limit Price；
- Quantity；
- Executed Quantity；
- Average Price；
- Status；
- Created / Completed；
- Fee / Fill 信息。

可以结合 Current / History 分类。

---

# 31. 永续订单 `/dashboard/perpetual-orders`

展示：

- 合约；
- Side；
- Limit；
- Quantity；
- Leverage；
- Reduce Only；
- Executed；
- Average Price；
- Status；
- Created / Completed。

历史订单利润显示由 Fill / Position Cycle 数据计算，而不是单纯用当前 Position 的累计 Realized PnL 误代某个订单利润。

---

# 32. 交易历史 `/dashboard/trade-history`

聚合：

- Spot Fill；
- Perp Fill；
- 成交时间；
- Price；
- Quantity；
- Fee；
- Realized PnL；
- Symbol；
- Side。

历史手续费应优先使用成交时保存的 Fee Snapshot / Fee Amount。

---

# 33. 当前仓位 `/dashboard/positions`

显示当前 Perp Position：

- Symbol；
- Long / Short；
- Quantity；
- Entry；
- Mark；
- Unrealized PnL；
- Realized PnL；
- Initial Margin；
- Maintenance Margin；
- Liquidation Price；
- Risk；
- Close。

---

# 34. 收藏 `/dashboard/favorites`

用户可收藏市场。

数据库：

```text
cex_user_market_favorites
```

接口：

```text
GET  /api/market-favorites
POST /api/market-favorites/set
```

首页、市场页、交易市场选择器可根据收藏状态展示。

---

# 35. 用户 API `/dashboard/api`

用户可以创建只读 API Key。

OpenAPI V1 目前提供：

```text
/openapi/v1/account/profile
/openapi/v1/account/perpetual-positions
/openapi/v1/account/balances
/openapi/v1/account/deposits
/openapi/v1/account/withdrawals
/openapi/v1/markets/supported
```

特点：

- API Key + HMAC；
- 只读；
- 不提供下单；
- 不提供外部 Market Feed。

当前明确不从 OpenAPI 暴露：

- Ticker；
- Kline；
- Orderbook；
- BBO；
- Mark；
- Index；
- Funding。

行情应该使用 Market Data 服务，而不是用户账户 OpenAPI。

---

# 36. 通知 / 推荐 / 偏好 / 支持页面

通用 Account Center 路由：

```text
/dashboard/notifications
/dashboard/referral
/dashboard/preferences
/dashboard/support
```

这些页面作为账户中心扩展入口。

当前核心资金逻辑不依赖这些模块。

---

# 37. C2C 页面 `/c2c`

C2C 是用户之间直接进行：

```text
CNY
↔
BTC / USDT / ETH
```

交易的市场。

核心原则：

> CrystalBest 托管加密资产，人民币由交易双方使用本人实名支付方式直接支付。

---

# 38. C2C 交易市场

用户可以选择：

```text
我要购买
我要出售
```

资产：

```text
BTC
USDT
ETH
```

可查看广告：

- 商家名称；
- 成交率；
- 单价；
- 可交易数量；
- 单笔限额；
- 支付方式；
- 付款时限。

支付方式：

- 支付宝；
- 微信；
- 银行卡。

---

# 39. C2C 商家申请

用户需要：

```text
KYC 已通过
+
缴纳商家保证金
```

V1 默认配置：

```text
1000 USDT
```

该金额是配置项，可以调整。

保证金不是数据库随便扣一个数字。

Ledger：

```text
用户 USDT 现货可用
↓
SYS_C2C_MERCHANT_DEPOSIT
```

业务类型：

```text
C2C_MERCHANT_DEPOSIT
```

成功后 Merchant 进入 ACTIVE。

---

# 40. C2C 收款方式

用户与商家都可以添加实名收款方式。

支持：

## 支付宝

- 本人实名；
- 上传二维码；
- QR 存私有 R2；
- 只有订单相关方经过授权读取。

## 微信支付

同支付宝。

## 银行卡

保存：

- 实名姓名；
- 卡号/收款账号；
- 银行名称；
- 开户支行。

敏感信息加密保存。

展示使用脱敏卡号。

银行卡不要求上传 QR。

---

# 41. C2C 发布广告

只有 ACTIVE 商家可以发布。

字段：

- BUY / 商家买币；
- SELL / 商家卖币；
- BTC / USDT / ETH；
- CNY 固定单价；
- 总数量；
- 可用数量；
- 最小法币金额；
- 最大法币金额；
- 付款时限；
- 支持付款方式；
- 备注。

广告可以：

- 上线；
- 下架；
- 售罄；
- 取消。

---

# 42. C2C 用户购买加密货币

例如：

```text
商家 SELL BTC
用户 BUY BTC
```

流程：

```text
用户选择广告
↓
输入 CNY 金额
↓
计算 BTC Quantity
↓
创建订单
↓
商家 BTC
→ SYS_C2C_ESCROW
↓
用户根据商家实名收款方式支付 CNY
↓
用户点“我已付款”
↓
商家检查真实到账
↓
商家点“确认收款并放币”
↓
SYS_C2C_ESCROW
→ 用户 BTC
↓
订单 COMPLETED
```

CrystalBest 不托管这笔 CNY。

---

# 43. C2C 用户出售加密货币

例如：

```text
商家 BUY USDT
用户 SELL USDT
```

流程：

```text
用户创建订单
↓
用户 USDT
→ SYS_C2C_ESCROW
↓
商家向用户实名收款账户支付 CNY
↓
商家点“我已付款”
↓
用户确认人民币真实到账
↓
用户点“确认收款并放币”
↓
SYS_C2C_ESCROW
→ 商家 USDT
↓
订单 COMPLETED
```

---

# 44. C2C 取消 / 超时

订单尚未付款时：

```text
取消
或
支付时间到期
↓
Escrow Refund
↓
加密资产返回原卖方
```

Ledger Business Type：

```text
C2C_ESCROW_REFUND
```

超时任务：

```text
php think c2c:expire
```

生产建议每分钟运行。

---

# 45. C2C 申诉

已付款后产生纠纷：

```text
订单
↓
APPEAL
```

此时：

```text
加密资产继续留在 SYS_C2C_ESCROW
```

不会自动放给买家，也不会自动退给卖家。

当前 C2C V1 已预留申诉表和订单状态。

**管理员申诉裁决工作流属于后续功能，当前 V1 不应假装已经自动解决。**

---

# 46. C2C 数据表

C2C 增加：

```text
cex_c2c_merchants
cex_c2c_merchant_deposits
cex_c2c_payment_methods
cex_c2c_ads
cex_c2c_ad_payment_types
cex_c2c_orders
cex_c2c_appeals
```

系统账户：

```text
SYS_C2C_ESCROW
SYS_C2C_MERCHANT_DEPOSIT
```

---

# 47. Wallet / Custody

Wallet 数据主要包括：

```text
cex_wallet_wallets
cex_wallet_addresses
cex_wallet_custody_bundles
cex_wallet_custody_events
cex_wallet_chain_transactions
cex_wallet_deposits
cex_wallet_withdrawals
cex_wallet_withdrawal_actions
```

钱包系统负责链上地址和链上事件；

Ledger 负责用户平台内账。

两者不是同一个概念。

---

# 48. User Worker

服务：

```text
crystalbest-user-worker
```

Node.js。

当前重要职责：

- 处理用户注册后的异步 Wallet Allocation；
- 调用主站受签名保护的 Internal API；
- 将耗时/外部依赖任务从注册 HTTP 请求中拆出去。

这样注册接口不需要同步等待整个钱包基础设施完成。

---

# 49. Ledger Reconciler

服务：

```text
crystalbest-ledger-reconciler
```

Node.js。

核心原则：

```text
只读检查
不自动修改资金
```

支持：

```text
DAILY
MANUAL
```

两种独立 Cursor。

主要记录：

```text
cex_audit_reconciliation_runs
cex_audit_reconciliation_items
cex_audit_reconciliation_cursors
cex_audit_reconciliation_repair_actions
```

---

## 49.1 Reconciler 检查什么

包括：

- Ledger Entry Chain；
- Balance Cache；
- Entry Before / After 连续性；
- Journal 平衡；
- Hold / 资金相关一致性；
- 业务实体与 Ledger 关系；
- 手续费等可核验数据。

发生差异：

```text
记录 Issue
```

而不是：

```text
自动 UPDATE 钱
```

管理员可以查看 Repair Preview / Repair Proposal。

真正修改历史资金必须使用 Correction Journal 思路，而不是直接篡改旧 Entry。

---

# 50. 独立管理员后台

管理员站点与用户交易端分开。

例如：

```text
crystalbest.top
= 用户交易站

admin.crystalbest.top
= 管理后台
```

管理员登录 Session 与用户登录 Session 独立。

管理员账号当前保存于：

```text
cex_admin_users_accounts
```

不是 `runtime/admin_panel/admin_users.json`。

runtime 仍可用于：

- 临时 Session；
- 登录失败限制；
- 管理员审计日志缓存/文件。

---

# 51. 管理后台首页 `/`

首页主要回答：

- 用户数量；
- 今日新增；
- 待审核提现；
- 现货成交；
- 永续成交；
- 手续费；
- 当前持仓；
- 风险账户；
- 强平；
- Reconciler；
- 系统健康。

---

# 52. 管理后台全局搜索 `/search`

支持从一个搜索框查：

- UID；
- Account；
- Order；
- Fill；
- Journal；
- Withdrawal；
- Symbol。

目的是避免管理员为了查一笔资金不断手写 SQL。

---

# 53. 管理后台用户 `/users`

用户列表：

- UID；
- 邮箱；
- Account；
- 状态；
- KYC；
- 风险；
- 资产摘要；
- 最后登录等。

用户详情：

```text
/users/:id
```

可以查看：

- 用户资料；
- 资产；
- Ledger Account；
- Risk State；
- Position；
- Order；
- Withdrawal。

---

# 54. 管理后台资产 `/assets`

页面使用中文展示：

- 账户；
- 账户类型；
- 币种；
- 资金场景；
- 余额类型；
- 余额；
- 账本科目；
- 最后 Ledger Entry；
- 更新时间。

系统账户会翻译成：

- 交易手续费账户；
- 永续流动性账户；
- 充值清算账户；
- 提现清算账户；
- 舍入账户；
- 管理员人工调账账户；
- C2C 托管账户等。

---

# 55. 管理员人工入账

入口：

```text
/assets/manual-credit
```

例如管理员给用户：

```text
+1 BTC
```

不能直接：

```sql
UPDATE cex_asset_balances
```

正确流程：

```text
SYS_ADMIN_ADJUSTMENT BTC
-1 BTC

用户 BTC Spot Available
+1 BTC
```

Ledger Business Type：

```text
ADMIN_MANUAL_CREDIT
```

所以 Reconciler 仍然可以核对。

调账记录：

```text
/asset-adjustments
```

显示：

- 用户；
- Asset；
- Amount；
- Before；
- After；
- Journal；
- Admin；
- Note；
- 时间。

---

# 56. 管理后台 Ledger `/ledger`

可以查看 Ledger Journal：

- Journal No；
- Business Type；
- Business ID；
- Status；
- Occurred；
- Posted。

详情：

```text
/ledger/:id
```

展开：

- Ledger Account；
- Asset；
- Direction；
- Amount；
- Balance Before；
- Balance After。

并检查：

```text
Debit / Credit 是否平衡
```

---

# 57. 管理后台 Holds `/holds`

查看：

- Hold No；
- Account；
- Asset；
- Business Type；
- Business ID；
- Original Amount；
- Remaining Amount；
- Status；
- Released At。

用于排查：

- 订单冻结没释放；
- 提现冻结异常；
- C2C 等业务资金卡住。

---

# 58. 管理后台现货

## `/spot/orders`

查看：

- 用户 Spot Order；
- Side；
- Limit；
- Quantity；
- Executed；
- Hold；
- Status。

## `/spot/fills`

查看：

- Fill；
- Price；
- Quantity；
- Quote；
- Fee；
- Ledger Transaction；
- 时间。

---

# 59. 管理后台永续

## `/perp/positions`

查看所有 Position。

## `/perp/orders`

查看 Perp Order。

## `/perp/fills`

查看 Fill、Fee Snapshot、Realized PnL、Position Before/After、Ledger。

## `/perp/risk`

查看：

- Wallet；
- Equity；
- Unrealized；
- IM；
- Order IM；
- MM；
- Available；
- Equity Buffer；
- Margin Ratio；
- Risk Status。

---

# 60. 管理后台自动强平

```text
/liquidations
/liquidations/:id
```

查看：

- Liquidation Case；
- Account；
- Trigger Equity；
- Trigger Maintenance；
- Trigger Ratio；
- Positions；
- System Liquidation Order；
- Failure；
- Triggered / Completed。

用于完整追踪一次强平。

---

# 61. 管理后台对账中心

```text
/reconciliation/runs
/reconciliation/issues
```

Runs：

- DAILY；
- MANUAL；
- Period；
- Checked；
- Differences；
- Status。

管理员可以触发：

```text
POST /reconciliation/run-manual
```

后台调用 Ledger Reconciler CLI。

如果 PHP 环境禁用 `proc_open`，按钮无法启动 Node CLI，但历史 Runs 仍可以从数据库查看。

---

# 62. 管理后台提现

```text
/withdrawals
/withdrawals/:id
```

当前管理员提现流程是 **人工打款模式**。

不要求 Admin 再调用主站 `127.0.0.1:3181` Internal API。

状态大致：

```text
待审核
↓
批准
↓
人工打款处理中
↓
登记真实 Tx Hash
↓
确认完成
```

失败路径：

```text
拒绝
→ Ledger Refund

人工打款失败
→ Ledger Refund
```

当前页面还会做 Preflight：

- 用户状态；
- Account 状态；
- Hold；
- Perp Risk；
- 最近 Reconciliation；
- 重复提现。

---

# 63. 管理后台 KYC

```text
/kyc
/kyc/:id
```

支持：

- 查看待审核；
- 查看用户信息；
- 查看私有证件；
- 开始审核；
- 通过；
- 拒绝。

KYC 文件不公开暴露。

---

# 64. 管理后台钱包

## `/wallet/deposits`

查看：

- Deposit；
- Asset；
- Network；
- Amount；
- Address；
- Chain Transaction；
- Ledger；
- Status。

## `/wallet/chain`

查看链上交易和确认状态。

---

# 65. 管理后台市场配置 `/market`

查看：

- Spot Symbol；
- Perpetual Contract；
- Asset；
- Status；
- Source Mapping；
- 市场配置。

注意：

这里主要是平台 Market Catalog，不取代实时 Market Fabric。

---

# 66. 管理后台手续费 `/fees`

查看：

- Spot Fee；
- Perp Fee；
- Maker / Taker；
- 当前配置。

必须区分：

```text
当前手续费配置
```

和：

```text
历史 Fill Fee Snapshot
```

修改当前费率不能反算并篡改历史成交手续费。

---

# 67. 管理后台系统健康 `/system`

主要展示：

- MySQL；
- Execution Gateway；
- Perp Runtime；
- Auto Liquidation；
- LIVE Settlement；
- Market WS URL；
- 服务进程检查；
- Gateway Health JSON。

Execution Gateway：

```text
127.0.0.1:3100
```

是最重要的本地执行行情入口之一。

进程名称检测只能作为运维辅助，不应该比服务自身 `/health` / `doctor` 更可信。

---

# 68. 管理员账号 `/admins`

管理员账号已数据库化：

```text
cex_admin_users_accounts
```

保存：

- username；
- display_name；
- password_hash；
- role；
- status；
- last_login_at；
- last_login_ip。

当前实际运营可以只使用 `superadmin`。

---

# 69. 管理员审计 `/audit`

记录管理员动作，例如：

- 登录；
- KYC；
- 提现；
- 人工入账；
- 对账；
- 管理员修改。

目的是保证后台资金操作可以追溯。

---

# 70. 数据库主要业务域

## 用户

```text
cex_user_users
cex_user_credentials
cex_user_security
cex_user_sessions
cex_user_oauth_identities
cex_user_kyc
cex_user_kyc_actions
cex_user_api_keys
cex_user_market_favorites
cex_user_audit_logs
cex_user_restrictions
```

## Account / Ledger

```text
cex_account_accounts
cex_asset_balances
cex_asset_holds
cex_asset_ledger_accounts
cex_asset_ledger_transactions
cex_asset_ledger_entries
cex_asset_internal_transfers
```

## Spot

```text
cex_spot_orders
cex_spot_order_events
cex_spot_trades
cex_spot_fills
cex_spot_match_sequences
cex_spot_reference_execution_attempts
cex_spot_liquidity_accounts
```

## Perp

```text
cex_perp_orders
cex_perp_order_events
cex_perp_trades
cex_perp_fills
cex_perp_positions
cex_perp_position_events
cex_perp_account_risk_states
cex_perp_account_contract_settings
cex_perp_liquidations
cex_perp_liquidation_positions
cex_perp_reference_execution_attempts
cex_perp_liquidity_accounts
```

## Wallet

```text
cex_wallet_wallets
cex_wallet_addresses
cex_wallet_custody_bundles
cex_wallet_custody_events
cex_wallet_chain_transactions
cex_wallet_deposits
cex_wallet_withdrawals
cex_wallet_withdrawal_actions
```

## Reconciliation

```text
cex_audit_reconciliation_runs
cex_audit_reconciliation_items
cex_audit_reconciliation_cursors
cex_audit_reconciliation_repair_actions
```

## C2C

```text
cex_c2c_merchants
cex_c2c_merchant_deposits
cex_c2c_payment_methods
cex_c2c_ads
cex_c2c_ad_payment_types
cex_c2c_orders
cex_c2c_appeals
```

## Admin

```text
cex_admin_users_accounts
```

---

# 71. Outbox

系统存在：

```text
cex_system_outbox_events
```

用于将“数据库 Transaction 已经成功提交的事实”与后续异步处理解耦。

典型思路：

```text
业务 Transaction
↓
写业务数据
+
写 Outbox
↓
COMMIT
↓
后台异步消费
```

避免：

```text
数据库成功
但消息发送失败
```

造成事实丢失。

---

# 72. 安全设计

当前系统重要安全逻辑包括：

- 密码 Hash；
- 图形 Captcha；
- 邮箱验证码；
- TOTP；
- Google / Microsoft OIDC；
- Session 管理；
- Device Identity；
- Session Revoke；
- KYC；
- 私有 R2；
- Internal API Signature；
- API Key HMAC；
- Admin 独立 Session；
- 财务操作 Ledger 化；
- Hold；
- DB Transaction；
- MySQL Row Lock；
- Idempotency Key；
- Singleton Engine Lock；
- Market Freshness；
- Automatic Liquidation Retry；
- Ledger Reconciliation。

---

# 73. 订单与资金为什么不能直接 UPDATE

CrystalBest 的核心约束：

**业务状态可以更新，但不能绕过 Ledger 直接改钱。**

错误示例：

```sql
UPDATE cex_asset_balances
SET balance = balance + 1
```

正确：

```text
Business Event
↓
Ledger Transaction
↓
Balanced Entries
↓
Update Balance Cache
```

这样才能做到：

- 可追溯；
- 可审计；
- 可对账；
- 可恢复；
- 可定位历史问题。

---

# 74. 当前系统明确没有做什么

为了避免后续开发人员误解，当前边界包括：

## 外部成交

当前用户 Spot / Perp 订单：

**不会直接发送到 OKX / Binance。**

OKX / Binance 是行情参考源。

---

## 永续 Funding Settlement

当前关闭。

---

## Perp Market Order

当前核心 Engine 的正式结算路径仍以 LIMIT 为主。

---

## TP / SL / Conditional Order

尚未作为当前稳定核心交易链实现。

---

## C2C 申诉管理员裁决

V1 已预留 Appeal，但完整 Admin 裁决流程仍属于后续。

---

## C2C 商家保证金退出 / 扣罚

V1 当前重点是缴纳与激活。

退还、扣罚、退出审批需要后续独立工作流。

---

## C2C 聊天 / 评价 / 拉黑 / 浮动广告价格

当前 V1 未实现。

---

# 75. 一笔现货交易的完整数据链

```text
用户
↓
ThinkPHP Spot API
↓
验证余额 / Market / Precision
↓
创建 Order + Hold
↓
MySQL
↓
Spot Worker 读取 OPEN Order
↓
3100 Execution Gateway
↓
Redis Market Fabric
↓
获得 Binance BBO Reference
↓
价格达到限价
↓
MySQL Transaction
├─ Lock Order
├─ Lock Hold
├─ Ledger Settlement
├─ Trade
├─ Fill
├─ Fee
├─ Order Event
├─ Attempt
└─ Hold Consumed
↓
Commit
↓
前端查询到 FILLED
```

---

# 76. 一笔永续交易的完整数据链

```text
用户
↓
ThinkPHP Perp API
↓
保证金 / 风险 / 杠杆检查
↓
Order + Hold
↓
Perp Engine
↓
3100 Market Gateway
↓
BBO
↓
触发
↓
MySQL Transaction
├─ Order
├─ Hold
├─ Trade
├─ Fill
├─ Position
├─ Position Event
├─ Fee
├─ Realized PnL
├─ Margin
├─ Ledger
├─ Risk State
├─ Attempt
└─ Outbox
↓
Commit
↓
Mark Feed 持续更新 UPnL / MM / Risk
```

---

# 77. 一次自动强平完整数据链

```text
Mark 更新
↓
Risk State
↓
Equity <= Maintenance Margin
↓
Automatic Liquidation Scanner
↓
Account Lock / Idempotency
↓
LIQUIDATING
↓
取消账户所有未成交永续订单
↓
释放订单 Hold
↓
重新评估
↓
创建 Reduce-Only Liquidation Order
↓
正常 Perp Settlement
↓
Fill
↓
Realized PnL
↓
Fee Snapshot
↓
Ledger
↓
Position -> 0
↓
Liquidation Completed
↓
Ledger Reconciler 检查
```

---

# 78. 一笔 C2C 买币完整数据链

```text
用户选择商家 SELL 广告
↓
创建 C2C Order
↓
商家 Crypto
→ SYS_C2C_ESCROW
↓
用户人民币直接支付给商家
↓
用户标记已付款
↓
商家真实确认到账
↓
SYS_C2C_ESCROW
→ 用户 Crypto
↓
Order Completed
↓
Ledger 可审计
```

---

# 79. 一笔提现完整数据链

```text
用户申请
↓
地址/网络/金额检查
↓
创建 Withdrawal
↓
创建 Hold
↓
管理员 Preflight
↓
批准
↓
人工链外/链上打款流程
↓
登记真实 Tx Hash
↓
链上确认
↓
管理员确认完成
```

失败：

```text
未广播前失败
↓
Ledger Refund
↓
用户资金恢复
```

---

# 80. 运行与排障建议

市场行情问题优先检查：

```text
Collector
Redis
Public Gateway :3112
Execution Gateway :3100
```

现货不成交优先检查：

```text
Order Status
Hold
Market Freshness
Best Bid / Ask
Spot Worker
Execution Attempt
```

永续不成交优先检查：

```text
Order
Hold
Leverage
Risk
Perp Engine
3100 Gateway
Reference Attempt
```

强平问题优先检查：

```text
Risk State
Equity
Maintenance Margin
Liquidation Case
Liquidation Position
System Order
Fill
Ledger
```

账务问题优先：

```text
Ledger Transaction
Ledger Entries
Balance Cache
Hold
Reconciler Run
Reconciler Issue
```

---

# 81. 核心设计原则总结

CrystalBest 当前架构可以概括为：

```text
外部交易所
= 行情来源

Market Fabric
= 行情标准化与分发

ThinkPHP
= 用户业务入口

Spot / Perp Worker
= 内部执行

MySQL Ledger
= 资金事实

System Liquidity Account
= 内部交易对手方

Risk / Liquidation
= 永续风险控制

Reconciler
= 账务一致性检查

Admin
= 人工运营与审核

C2C Escrow
= 法币交易中的加密资产托管
```

最重要的底层原则：

1. **行情和资金分离。**
2. **外部盘口是价格参考，不是 CrystalBest 的实际库存。**
3. **订单成交由 CrystalBest 内部 Worker 产生。**
4. **所有资金变更应通过双边 Ledger。**
5. **Hold 用于控制订单、提现等已占用资金。**
6. **历史 Fill 保存成交事实和手续费事实。**
7. **永续风险按账户级 Equity 与 Maintenance Margin 管理。**
8. **自动强平复用正常交易结算链，而不是直接篡改 Position。**
9. **Reconciler 只检查，不自动改钱。**
10. **C2C 只托管 Crypto，法币由双方直接支付。**
11. **管理员人工操作也必须留下 Ledger / Audit。**
12. **任何外部行情异常不能直接变成不可追溯的资金修改。**

---

# 82. 当前主要服务清单

| 服务 | 技术 | 主要职责 |
|---|---|---|
| crystalbest.top | PHP / ThinkPHP 6 | 用户站、API、资产、订单、KYC、钱包、C2C |
| Market Collector | Node.js | 唯一直接连接 OKX/Binance 的实时行情采集 |
| Market Public Gateway | Node.js | 浏览器行情 HTTP/WS |
| Execution Gateway | Node.js | 本机 3100 执行行情 Gateway |
| Market Data Synchronization | Node.js | 市场/资产/网络 Reference Metadata 同步 |
| Spot Worker | Node.js | Spot BBO 触发与内部 Settlement |
| Perp Engine | Node.js | Perp Fill / Position / PnL / Risk / Liquidation |
| User Worker | Node.js | 用户异步任务、Wallet Allocation |
| Ledger Reconciler | Node.js | DAILY / MANUAL 账务一致性检查 |
| admin.crystalbest.top | PHP / ThinkPHP 6 | 独立管理员后台 |
| MySQL | MySQL 8 | 业务与 Ledger 持久化 |
| Redis | Redis | 实时行情热状态 / PubSub |

---

# 83. 文档维护规则

以后每次重大升级，建议同步更新本文件，至少记录：

```text
版本号
日期
新增页面
新增数据库表
新增服务
新增 Ledger Business Type
交易规则变化
手续费变化
风险规则变化
C2C 状态变化
Admin 操作变化
已停用功能
```

特别是以下功能修改必须同步文档：

- Spot Settlement；
- Perp Settlement；
- Liquidation；
- Ledger；
- Withdrawal；
- C2C Escrow；
- Fee；
- Market Data Source；
- Admin Manual Credit；
- Reconciliation。

---

## 附：当前关键 Ledger Business Type 示例

系统中实际业务会有多个 Business Type，以下是当前架构中的关键类别：

```text
SPOT_REFERENCE_FILL
PERP Fill / Settlement 相关 Journal
PERP_LIQUIDATION_ORDER_RELEASE

ADMIN_MANUAL_CREDIT

C2C_MERCHANT_DEPOSIT
C2C_ESCROW_LOCK
C2C_ESCROW_RELEASE
C2C_ESCROW_REFUND

Wallet Deposit / Withdrawal / Refund 相关 Journal
```

Business Type 的作用是：

> 让一笔 Ledger Transaction 不只是“金额变了”，而是可以知道“为什么变”。

---

## 附：当前重要系统账户示例

```text
SYS_TRADING_FEE
交易手续费账户

SYS_PERP_LIQUIDITY
永续系统流动性 / PnL 对手账户

PRIMARY_SPOT_LIQUIDITY
现货系统流动性对手账户

SYS_DEPOSIT_CLEARING
充值清算账户

WITHDRAW_CLEARING / SYS_WITHDRAW_CLEARING
提现清算用途账户

SYS_ADMIN_ADJUSTMENT
管理员人工调账账户

SYS_C2C_ESCROW
C2C Crypto 托管账户

SYS_C2C_MERCHANT_DEPOSIT
C2C 商家保证金账户

SYS_ROUNDING
舍入调整账户

SYS_INSURANCE_FUND
保险基金预留账户

SYS_FUNDING_CLEARING
Funding 清算预留账户
```

其中部分账户属于当前使用功能，部分属于已预留的业务域。  
是否实际启用，以对应服务开关和业务代码为准。

---

# 结语

CrystalBest 当前不是一个“网页直接调交易所 API 下单”的简单站点，而是一套已经拆分为：

```text
行情层
业务层
执行层
账本层
风险层
钱包层
对账层
管理员运营层
C2C 托管层
```

的多服务交易系统。

理解这套系统时，建议始终按照以下顺序排查和开发：

```text
用户动作
→ 业务状态
→ Hold
→ 执行
→ Fill
→ Ledger
→ Position / Wallet
→ Risk
→ Reconciliation
```

这样可以最大程度避免资金、订单和持仓之间出现不可追溯的不一致。
