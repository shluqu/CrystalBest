<?php

namespace app\controller\Auth;

use think\facade\Db;
use think\facade\Log;
use think\Request;

final class AuditLog
{
    public static function record(
        Request $request,
        string $action,
        ?int $userId,
        int $result = 1,
        ?string $resourceType = null,
        ?string $resourceId = null,
        array $metadata = []
    ): void {
        try {
            $action = substr(trim($action), 0, 128);
            if ($action === '') {
                return;
            }

            $safeMetadata = self::redact($metadata);
            Db::table('cex_user_audit_logs')->insert([
                'request_id' => Ulid::generate(),
                'user_id' => $userId,
                'actor_type' => 1,
                'actor_id' => $userId,
                'action' => $action,
                'resource_type' => $resourceType !== null ? substr($resourceType, 0, 64) : null,
                'resource_id' => $resourceId !== null ? substr($resourceId, 0, 128) : null,
                'result' => in_array($result, [1, 2, 3], true) ? $result : 3,
                'ip_address' => ClientContext::packedIp($request),
                'user_agent' => self::userAgent($request),
                'metadata_json' => empty($safeMetadata)
                    ? null
                    : json_encode($safeMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'occurred_at' => Clock::now(),
            ]);
        } catch (\Throwable $exception) {
            // Audit must never break authentication/security actions.
            Log::error('Audit log write failed action=' . $action . ' message=' . $exception->getMessage());
        }
    }

    private static function userAgent(Request $request): ?string
    {
        $ua = trim((string) $request->header('user-agent', ''));
        return $ua === '' ? null : substr($ua, 0, 1024);
    }

    private static function redact(array $metadata): array
    {
        $result = [];
        foreach ($metadata as $key => $value) {
            $name = strtolower((string) $key);
            if (preg_match('/password|passwd|secret|token|code|captcha|authorization|cookie|email|phone/', $name)) {
                continue;
            }
            if (is_array($value)) {
                $result[$key] = self::redact($value);
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
