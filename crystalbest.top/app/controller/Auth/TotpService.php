<?php

namespace app\controller\Auth;

use Endroid\QrCode\QrCode;
use PragmaRX\Google2FA\Google2FA;

class TotpService
{
    /** @var Google2FA */
    private $google2fa;

    public function __construct()
    {
        if (!class_exists(Google2FA::class)) {
            throw new AuthException('Google Authenticator 依赖尚未安装，请执行 composer install', 500, 'TOTP_LIBRARY_MISSING');
        }
        if (!class_exists(QrCode::class)) {
            throw new AuthException('二维码依赖尚未安装，请执行 composer install', 500, 'QRCODE_LIBRARY_MISSING');
        }

        $this->google2fa = new Google2FA();
    }

    public function createEnrollment(string $accountLabel): array
    {
        $issuer = trim((string) env('auth.totp_issuer', 'CrystalBest'));
        if ($issuer === '') {
            $issuer = 'CrystalBest';
        }

        // Google2FA v9 默认即为 32 字符；显式传入便于兼容配置和审计。
        $secret = $this->google2fa->generateSecretKey(32);
        $uri = $this->google2fa->getQRCodeUrl($issuer, $accountLabel, $secret);

        $qr = new QrCode($uri);
        $qr->setWriterByName('svg');
        $qr->setSize(260);
        $qr->setMargin(12);
        $qr->setEncoding('UTF-8');
        $qr->setValidateResult(false);

        return [
            'secret' => $secret,
            'issuer' => $issuer,
            'account_label' => $accountLabel,
            'otpauth_uri' => $uri,
            'qr_data_uri' => $qr->writeDataUri(),
            'digits' => 6,
            'period' => 30,
            'algorithm' => 'SHA1',
        ];
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', trim($code));
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        // 仅接受当前时间片前后各 1 个窗口（约 ±30 秒），避免默认窗口过宽。
        return $this->google2fa->verifyKey($secret, $code, 1) !== false;
    }
}
