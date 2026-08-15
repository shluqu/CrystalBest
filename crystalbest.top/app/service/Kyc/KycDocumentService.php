<?php

namespace app\service\Kyc;

use app\controller\Auth\AuthException;
use app\controller\Auth\R2Storage;
use think\file\UploadedFile;

final class KycDocumentService
{
    public function isConfigured(): bool
    {
        // KYC reuses the same R2 bucket/API credentials as avatars, but never
        // uses PUBLIC_BASE_URL. Reads/writes go through the signed S3 API only.
        if (!$this->hasSeparatedPrefix()) {
            return false;
        }
        return (new R2Storage(null, ''))->isApiConfigured();
    }

    public function upload(UploadedFile $file, string $uid, string $caseId, string $slot): array
    {
        $this->assertConfigured();
        if (!$file->isValid()) {
            throw new AuthException('证件图片上传失败，请重新选择图片', 422, 'KYC_DOCUMENT_UPLOAD_INVALID');
        }

        $maxBytes = max(1048576, (int) config('kyc.document.max_bytes', 10485760));
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > $maxBytes) {
            throw new AuthException('单张证件图片大小不能超过 10MB', 422, 'KYC_DOCUMENT_TOO_LARGE');
        }
        if (!extension_loaded('gd')) {
            throw new AuthException('服务器缺少 GD 扩展，无法处理证件图片', 500, 'KYC_GD_MISSING');
        }

        $path = $file->getPathname();
        $info = @getimagesize($path);
        if (!is_array($info) || empty($info[0]) || empty($info[1]) || empty($info['mime'])) {
            throw new AuthException('请选择有效的 JPG、PNG 或 WEBP 证件图片', 422, 'KYC_DOCUMENT_IMAGE_INVALID');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $mime = strtolower((string) $info['mime']);
        $maxPixels = max(1000000, (int) config('kyc.document.max_pixels', 36000000));
        if ($width < 300 || $height < 300 || ($width * $height) > $maxPixels) {
            throw new AuthException('证件图片尺寸无效，请上传清晰完整的证件照片', 422, 'KYC_DOCUMENT_DIMENSIONS_INVALID');
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new AuthException('证件图片仅支持 JPG、PNG、WEBP', 422, 'KYC_DOCUMENT_MIME_NOT_ALLOWED');
        }

        $source = $this->createSource($path, $mime);
        if (!$source) {
            throw new AuthException('证件图片无法解析', 422, 'KYC_DOCUMENT_DECODE_FAILED');
        }
        if ($mime === 'image/jpeg') {
            $source = $this->applyJpegOrientation($source, $path);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $maxSide = max(800, (int) config('kyc.document.max_side', 2400));
        $scale = min(1, $maxSide / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$target) {
            imagedestroy($source);
            throw new AuthException('证件图片处理失败', 500, 'KYC_DOCUMENT_PROCESS_FAILED');
        }
        $white = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $white);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        $extension = 'webp';
        $contentType = 'image/webp';
        if (function_exists('imagewebp')) {
            imagewebp($target, null, 88);
        } else {
            $extension = 'jpg';
            $contentType = 'image/jpeg';
            imagejpeg($target, null, 92);
        }
        $body = (string) ob_get_clean();
        imagedestroy($target);
        if ($body === '') {
            throw new AuthException('证件图片编码失败', 500, 'KYC_DOCUMENT_ENCODE_FAILED');
        }

        $slot = strtolower(trim($slot));
        if (!in_array($slot, ['front', 'back'], true)) {
            throw new AuthException('证件图片位置无效', 500, 'KYC_DOCUMENT_SLOT_INVALID');
        }
        $prefix = trim((string) config('kyc.document.prefix', 'private/kyc-documents'), '/');
        if ($prefix === '') {
            $prefix = 'private/kyc-documents';
        }
        $safeUid = preg_replace('/[^A-Z0-9]/', '', strtoupper($uid));
        $safeCase = preg_replace('/[^A-Z0-9]/', '', strtoupper($caseId));
        if ($safeUid === '' || $safeCase === '') {
            throw new AuthException('身份认证存储路径生成失败', 500, 'KYC_STORAGE_KEY_INVALID');
        }
        $key = $prefix . '/' . $safeUid . '/' . $safeCase . '/' . $slot . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
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
        if (!$this->hasSeparatedPrefix()) {
            throw new AuthException('身份认证文件目录必须与头像目录完全分离', 500, 'KYC_STORAGE_PREFIX_CONFLICT');
        }
        if (!(new R2Storage(null, ''))->isApiConfigured()) {
            throw new AuthException('身份认证文件存储尚未配置', 503, 'KYC_STORAGE_NOT_CONFIGURED');
        }
    }

    private function hasSeparatedPrefix(): bool
    {
        $kyc = trim((string) config('kyc.document.prefix', 'private/kyc-documents'), '/');
        $avatar = trim((string) env('r2.avatar_prefix', 'user-avatars'), '/');
        if ($kyc === '' || $avatar === '') {
            return false;
        }
        if ($kyc === $avatar) {
            return false;
        }
        // Disallow parent/child overlap as well, e.g. `user-avatars/kyc`.
        return strpos($kyc . '/', $avatar . '/') !== 0
            && strpos($avatar . '/', $kyc . '/') !== 0;
    }

    private function assertManagedKey(string $key): void
    {
        $key = trim($key, '/');
        $prefix = trim((string) config('kyc.document.prefix', 'private/kyc-documents'), '/');
        if ($prefix === '' || strpos($key, $prefix . '/') !== 0) {
            throw new AuthException('身份认证文件路径无效', 500, 'KYC_STORAGE_KEY_INVALID');
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

    private function applyJpegOrientation($image, string $path)
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;
        if ($orientation === 3) {
            $rotated = imagerotate($image, 180, 0);
        } elseif ($orientation === 6) {
            $rotated = imagerotate($image, -90, 0);
        } elseif ($orientation === 8) {
            $rotated = imagerotate($image, 90, 0);
        } else {
            return $image;
        }
        if ($rotated) {
            imagedestroy($image);
            return $rotated;
        }
        return $image;
    }
}
