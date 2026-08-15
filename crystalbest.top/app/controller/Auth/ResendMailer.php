<?php

namespace app\controller\Auth;

/**
 * Minimal Resend Email API client.
 *
 * No SMTP and no extra SDK dependency: requests go directly to
 * https://api.resend.com/emails with the server-side API key.
 */
class ResendMailer
{
    private $apiKey;
    private $fromEmail;
    private $fromName;
    private $replyTo;
    private $timeout;

    public function __construct()
    {
        $this->apiKey = trim((string) env('resend.api_key', ''));
        $this->fromEmail = trim((string) env('resend.from_email', 'no-reply@crystalbest.top'));
        $this->fromName = trim((string) env('resend.from_name', 'CrystalBest'));
        $this->replyTo = trim((string) env('resend.reply_to', ''));
        $this->timeout = max(3, min(30, (int) env('resend.timeout_seconds', 10)));

        if ($this->apiKey === '') {
            throw new AuthException('Resend 邮件服务尚未配置', 500, 'RESEND_CONFIG_MISSING');
        }
        if (!filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new AuthException('Resend 发件邮箱配置无效', 500, 'RESEND_CONFIG_INVALID');
        }
        if (!function_exists('curl_init')) {
            throw new AuthException('服务器缺少 PHP cURL 扩展', 500, 'CURL_EXTENSION_MISSING');
        }
    }

    public function sendVerificationCode(string $to, string $code, string $purpose, int $ttlSeconds): string
    {
        $purposeMap = [
            'register' => ['title' => '验证你的注册邮箱', 'action' => '完成 CrystalBest 账户注册'],
            'login' => ['title' => '邮箱登录验证码', 'action' => '登录你的 CrystalBest 账户'],
            'password_reset' => ['title' => '重置密码验证码', 'action' => '重置你的 CrystalBest 登录密码'],
            'security_totp_enable' => ['title' => '启用 Google 身份验证器', 'action' => '继续启用 Google Authenticator'],
            'security_totp_disable' => ['title' => '关闭 Google 身份验证器', 'action' => '继续关闭 Google Authenticator'],
            'security_password_change' => ['title' => '密码安全验证', 'action' => '继续设置或修改登录密码'],
            'security_revoke_others' => ['title' => '登录设备安全验证', 'action' => '继续退出其他登录设备'],
            'security_email_change' => ['title' => '修改安全邮箱验证', 'action' => '继续修改你的 CrystalBest 安全邮箱'],
            'security_email_change_new' => ['title' => '验证新的安全邮箱', 'action' => '确认新的 CrystalBest 安全邮箱'],
        ];
        $copy = $purposeMap[$purpose] ?? ['title' => '邮箱验证码', 'action' => '完成账户验证'];
        $minutes = max(1, (int) ceil($ttlSeconds / 60));

        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $safeAction = htmlspecialchars($copy['action'], ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($copy['title'], ENT_QUOTES, 'UTF-8');

        $html = '<!doctype html><html><body style="margin:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#101828">'
            . '<div style="max-width:560px;margin:0 auto;padding:34px 18px">'
            . '<div style="background:#fff;border:1px solid rgba(183,192,208,.22);border-radius:18px;overflow:hidden">'
            . '<div style="padding:26px 30px 16px"><div style="font-size:20px;font-weight:700">CrystalBest</div></div>'
            . '<div style="padding:8px 30px 30px">'
            . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.35">' . $safeTitle . '</h1>'
            . '<p style="margin:0;color:#667085;font-size:14px;line-height:1.8">请输入下面的验证码以' . $safeAction . '。验证码仅用于本次操作。</p>'
            . '<div style="margin:24px 0;padding:18px;border-radius:14px;background:#f1f5ff;text-align:center;font-size:34px;font-weight:800;letter-spacing:10px;color:#2f6bff">' . $safeCode . '</div>'
            . '<p style="margin:0;color:#667085;font-size:13px;line-height:1.7">验证码将在 ' . $minutes . ' 分钟后失效。请勿将验证码提供给任何人。若非本人操作，可忽略此邮件。</p>'
            . '</div></div>'
            . '<p style="margin:14px 0 0;text-align:center;color:#98a2b3;font-size:12px">© CrystalBest · Automated security email</p>'
            . '</div></body></html>';

        $text = $copy['title'] . "\n\n验证码：" . $code
            . "\n有效期：" . $minutes . " 分钟"
            . "\n\n请勿将验证码提供给任何人。若非本人操作，请忽略此邮件。";

        $idempotency = 'crystalbest-' . $purpose . '-' . hash('sha256', strtolower($to) . '|' . $code);

        return $this->send($to, 'CrystalBest · ' . $copy['title'], $html, $text, $idempotency);
    }

    public function sendSecurityNotice(string $to, string $title, string $message): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $time = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s T');
        $safeTime = htmlspecialchars($time, ENT_QUOTES, 'UTF-8');

        $html = '<!doctype html><html><body style="margin:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#101828">'
            . '<div style="max-width:560px;margin:0 auto;padding:34px 18px">'
            . '<div style="background:#fff;border:1px solid rgba(183,192,208,.22);border-radius:18px;overflow:hidden">'
            . '<div style="padding:26px 30px 16px"><div style="font-size:20px;font-weight:700">CrystalBest</div></div>'
            . '<div style="padding:8px 30px 30px">'
            . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.35">' . $safeTitle . '</h1>'
            . '<p style="margin:0;color:#667085;font-size:14px;line-height:1.8">' . $safeMessage . '</p>'
            . '<div style="margin-top:20px;padding:14px 16px;border-radius:12px;background:#f8fafc;color:#667085;font-size:13px">操作时间：' . $safeTime . '</div>'
            . '<p style="margin:20px 0 0;color:#667085;font-size:13px;line-height:1.7">若非本人操作，请立即登录 CrystalBest 检查安全中心、修改密码并退出其他设备。</p>'
            . '</div></div>'
            . '<p style="margin:14px 0 0;text-align:center;color:#98a2b3;font-size:12px">© CrystalBest · Security notice</p>'
            . '</div></body></html>';

        $text = $title . "\n\n" . $message . "\n\n操作时间：" . $time
            . "\n\n若非本人操作，请立即检查账户安全。";
        $idempotency = 'crystalbest-security-notice-' . hash('sha256', strtolower($to) . '|' . $title . '|' . $time);

        return $this->send($to, 'CrystalBest · ' . $title, $html, $text, $idempotency);
    }

    private function send(string $to, string $subject, string $html, string $text, string $idempotencyKey): string
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new AuthException('收件邮箱无效', 422, 'INVALID_EMAIL');
        }

        $payload = [
            'from' => $this->fromName !== '' ? ($this->fromName . ' <' . $this->fromEmail . '>') : $this->fromEmail,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];
        if ($this->replyTo !== '' && filter_var($this->replyTo, FILTER_VALIDATE_EMAIL)) {
            $payload['reply_to'] = $this->replyTo;
        }

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: CrystalBest-Auth/4.0 (+https://crystalbest.top)',
                'Idempotency-Key: ' . substr($idempotencyKey, 0, 240),
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            throw new \RuntimeException('Resend network error: ' . ($error !== '' ? $error : ('curl errno ' . $errno)));
        }

        $decoded = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['id'])) {
            $message = is_array($decoded) && !empty($decoded['message']) ? (string) $decoded['message'] : 'HTTP ' . $status;
            throw new \RuntimeException('Resend API error: ' . $message);
        }

        return (string) $decoded['id'];
    }
}
