<?php

namespace app\controller\Auth;

use think\file\UploadedFile;

class AvatarService
{
    private const MAX_BYTES = 5242880; // 5 MiB
    private const OUTPUT_SIZE = 512;
    private const MAX_PIXELS = 25000000;

    public function upload(UploadedFile $file, string $uid): array
    {
        if (!$file->isValid()) {
            throw new AuthException('头像上传失败，请重新选择图片', 422, 'AVATAR_UPLOAD_INVALID');
        }
        if ($file->getSize() <= 0 || $file->getSize() > self::MAX_BYTES) {
            throw new AuthException('头像文件大小必须小于 5MB', 422, 'AVATAR_FILE_TOO_LARGE');
        }
        if (!extension_loaded('gd')) {
            throw new AuthException('服务器缺少 GD 扩展，无法处理头像', 500, 'AVATAR_GD_MISSING');
        }

        $path = $file->getPathname();
        $info = @getimagesize($path);
        if (!is_array($info) || empty($info[0]) || empty($info[1]) || empty($info['mime'])) {
            throw new AuthException('请选择有效的 JPG、PNG 或 WEBP 图片', 422, 'AVATAR_IMAGE_INVALID');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $mime = strtolower((string) $info['mime']);
        if ($width < 64 || $height < 64 || ($width * $height) > self::MAX_PIXELS) {
            throw new AuthException('头像尺寸无效，最小 64×64，且图片像素不能过大', 422, 'AVATAR_DIMENSIONS_INVALID');
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new AuthException('头像仅支持 JPG、PNG、WEBP', 422, 'AVATAR_MIME_NOT_ALLOWED');
        }

        $source = $this->createSource($path, $mime);
        if (!$source) {
            throw new AuthException('头像图片无法解析', 422, 'AVATAR_DECODE_FAILED');
        }

        if ($mime === 'image/jpeg') {
            $source = $this->applyJpegOrientation($source, $path);
            $width = imagesx($source);
            $height = imagesy($source);
        }

        $crop = min($width, $height);
        $srcX = (int) floor(($width - $crop) / 2);
        $srcY = (int) floor(($height - $crop) / 2);
        $target = imagecreatetruecolor(self::OUTPUT_SIZE, self::OUTPUT_SIZE);
        if (!$target) {
            imagedestroy($source);
            throw new AuthException('头像处理失败', 500, 'AVATAR_PROCESS_FAILED');
        }
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 255, 255, 255, 127);
        imagefill($target, 0, 0, $transparent);
        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            $srcX,
            $srcY,
            self::OUTPUT_SIZE,
            self::OUTPUT_SIZE,
            $crop,
            $crop
        );
        imagedestroy($source);

        ob_start();
        $extension = 'webp';
        $contentType = 'image/webp';
        if (function_exists('imagewebp')) {
            imagewebp($target, null, 86);
        } else {
            $extension = 'jpg';
            $contentType = 'image/jpeg';
            imagealphablending($target, true);
            $flattened = imagecreatetruecolor(self::OUTPUT_SIZE, self::OUTPUT_SIZE);
            $white = imagecolorallocate($flattened, 255, 255, 255);
            imagefill($flattened, 0, 0, $white);
            imagecopy($flattened, $target, 0, 0, 0, 0, self::OUTPUT_SIZE, self::OUTPUT_SIZE);
            imagedestroy($target);
            $target = $flattened;
            imagejpeg($target, null, 90);
        }
        $body = (string) ob_get_clean();
        imagedestroy($target);

        if ($body === '') {
            throw new AuthException('头像图片编码失败', 500, 'AVATAR_ENCODE_FAILED');
        }

        $prefix = trim((string) env('r2.avatar_prefix', 'user-avatars'), '/');
        if ($prefix === '') {
            $prefix = 'user-avatars';
        }
        $safeUid = preg_replace('/[^A-Z0-9]/', '', strtoupper($uid));
        if ($safeUid === '') {
            $safeUid = 'USER';
        }
        $key = $prefix . '/' . $safeUid . '/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $extension;

        $storage = new R2Storage();
        $url = $storage->put($key, $body, $contentType);
        return [
            'url' => $url,
            'storage_key' => $key,
            'content_type' => $contentType,
            'bytes' => strlen($body),
        ];
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
