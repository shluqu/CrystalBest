<?php

namespace app\service\C2c;

use app\controller\Auth\R2Storage;
use think\file\UploadedFile;

final class C2cPaymentDocumentService
{
    public function isConfigured(): bool
    {
        return (new R2Storage(null, ''))->isApiConfigured();
    }

    public function upload(UploadedFile $file, string $uid, string $paymentNo): array
    {
        $this->assertConfigured();

        if (!$file->isValid()) {
            throw new C2cException('收款二维码上传失败，请重新选择图片', 422, 'C2C_QR_UPLOAD_INVALID');
        }

        $maxBytes = max(1048576, (int) config('c2c.payment_qr.max_bytes', 5242880));
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > $maxBytes) {
            throw new C2cException('收款二维码图片不能超过 5MB', 422, 'C2C_QR_TOO_LARGE');
        }

        if (!extension_loaded('gd')) {
            throw new C2cException('服务器缺少 GD 扩展，无法处理收款二维码', 500, 'C2C_GD_MISSING');
        }

        $path = $file->getPathname();
        $info = @getimagesize($path);
        if (!is_array($info) || empty($info[0]) || empty($info[1]) || empty($info['mime'])) {
            throw new C2cException('请选择有效的 JPG、PNG 或 WEBP 图片', 422, 'C2C_QR_IMAGE_INVALID');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $mime = strtolower((string) $info['mime']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new C2cException('收款二维码仅支持 JPG、PNG、WEBP', 422, 'C2C_QR_MIME_NOT_ALLOWED');
        }
        if ($width < 180 || $height < 180 || ($width * $height) > 20000000) {
            throw new C2cException('收款二维码尺寸无效，请上传清晰完整的二维码', 422, 'C2C_QR_DIMENSIONS_INVALID');
        }

        $source = $this->createSource($path, $mime);
        if (!$source) {
            throw new C2cException('收款二维码图片无法解析', 422, 'C2C_QR_DECODE_FAILED');
        }

        $maxSide = max(600, (int) config('c2c.payment_qr.max_side', 1600));
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxSide / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$target) {
            imagedestroy($source);
            throw new C2cException('收款二维码处理失败', 500, 'C2C_QR_PROCESS_FAILED');
        }

        $white = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $white);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        $contentType = 'image/webp';
        $extension = 'webp';
        if (function_exists('imagewebp')) {
            imagewebp($target, null, 90);
        } else {
            $contentType = 'image/jpeg';
            $extension = 'jpg';
            imagejpeg($target, null, 94);
        }
        $body = (string) ob_get_clean();
        imagedestroy($target);
        if ($body === '') {
            throw new C2cException('收款二维码编码失败', 500, 'C2C_QR_ENCODE_FAILED');
        }

        $prefix = trim((string) config('c2c.payment_qr.prefix', 'private/c2c-payment-qrs'), '/');
        $safeUid = preg_replace('/[^A-Z0-9]/', '', strtoupper($uid));
        $safePayment = preg_replace('/[^A-Z0-9]/', '', strtoupper($paymentNo));
        if ($prefix === '' || $safeUid === '' || $safePayment === '') {
            throw new C2cException('收款二维码存储路径生成失败', 500, 'C2C_QR_KEY_INVALID');
        }

        $key = $prefix . '/' . $safeUid . '/' . $safePayment . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $result = $this->storage()->putPrivate($key, $body, $contentType);
        $result['sha256'] = hash('sha256', $body);
        return $result;
    }

    public function read(string $key): array
    {
        $this->assertConfigured();
        $this->assertManagedKey($key);
        return $this->storage()->getPrivate($key);
    }

    public function delete(string $key): void
    {
        $key = trim($key);
        if ($key === '' || !$this->isConfigured()) {
            return;
        }
        $this->assertManagedKey($key);
        $this->storage()->delete($key);
    }

    private function storage(): R2Storage
    {
        return new R2Storage(null, '');
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new C2cException('C2C 收款二维码私有存储尚未配置', 503, 'C2C_QR_STORAGE_NOT_CONFIGURED');
        }
    }

    private function assertManagedKey(string $key): void
    {
        $key = trim($key, '/');
        $prefix = trim((string) config('c2c.payment_qr.prefix', 'private/c2c-payment-qrs'), '/');
        if ($prefix === '' || strpos($key, $prefix . '/') !== 0) {
            throw new C2cException('收款二维码存储路径无效', 500, 'C2C_QR_KEY_INVALID');
        }
    }

    private function createSource(string $path, string $mime)
    {
        if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
            return @imagecreatefromjpeg($path);
        }
        if ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
            return @imagecreatefrompng($path);
        }
        if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            return @imagecreatefromwebp($path);
        }
        return false;
    }
}
