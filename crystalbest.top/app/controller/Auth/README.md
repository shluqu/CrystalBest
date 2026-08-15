# CrystalBest Auth V4

本模块继续使用现有 10 张用户表，不增加验证码/重置密码 MySQL 表。短期验证码与 ticket 使用 ThinkPHP Cache；正式登录态继续使用 `cex_user_sessions + cex_session`。

## 功能

- 注册：邮箱 -> ThinkCaptcha -> Resend 邮箱验证码 -> 验证 ticket -> 密码 + 再次 ThinkCaptcha -> 创建账户并登录。
- 登录：账号/邮箱 + 密码、邮箱验证码、Google、Microsoft。
- 忘记密码：邮箱 + ThinkCaptcha -> Resend 验证码 -> reset ticket -> 新密码 + ThinkCaptcha -> 撤销旧会话。
- 邮件：只使用 Resend Email API，不使用 SMTP。

## 必装依赖

```bash
composer require topthink/think-captcha:^3.0
```

安装后确认：

```bash
php think service:discover
php -m | grep -Ei 'gd|curl|openssl|pdo_mysql'
```

`think-captcha` 使用 ThinkPHP Session 保存图形验证码，因此本版本在 `app/middleware.php` 开启了 `SessionInit`。这只服务于图形验证码；账户登录状态仍然是自有 `cex_user_sessions`，不会改回 ThinkPHP Session 登录。

## Resend

服务器 `.env` 至少配置：

```ini
[RESEND]
API_KEY = re_xxxxxxxxx
FROM_EMAIL = no-reply@crystalbest.top
FROM_NAME = CrystalBest
REPLY_TO =
TIMEOUT_SECONDS = 10
```

在 Resend 后台先验证发件域名。`FROM_EMAIL` 默认只需要一个地址；代码不会使用 SMTP。

## API

### 注册

- `POST /api/auth/register/email/send-code`
- `POST /api/auth/register/email/verify-code`
- `POST /api/auth/register`

### 登录

- `POST /api/auth/login` 账号/邮箱 + 密码
- `POST /api/auth/login/email/send-code`
- `POST /api/auth/login/email/verify`
- `GET /auth/google`
- `GET /auth/microsoft`

### 重置密码

- `POST /api/auth/password/forgot`
- `POST /api/auth/password/verify-code`
- `POST /api/auth/password/reset`

## CAPTCHA IDs

- `register-email`
- `register-final`
- `login-email`
- `password-reset-start`
- `password-reset-final`

图形验证码地址：`GET /captcha/<id>`。
