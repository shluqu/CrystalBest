<?php
namespace app\service\Internal;

use app\controller\Auth\ClientContext;
use app\controller\Auth\Clock;
use app\controller\Auth\Ulid;
use think\facade\Db;
use think\facade\Log;
use think\Request;

final class ServiceAuditLog
{
    public static function record(Request $request, string $action, ?int $userId, ?string $resourceType = null, ?string $resourceId = null, array $metadata = []): void
    {
        try {
            Db::table('cex_user_audit_logs')->insert([
                'request_id' => Ulid::generate(),
                'user_id' => $userId,
                'actor_type' => 3,
                'actor_id' => null,
                'action' => substr($action, 0, 128),
                'resource_type' => $resourceType !== null ? substr($resourceType, 0, 64) : null,
                'resource_id' => $resourceId !== null ? substr($resourceId, 0, 128) : null,
                'result' => 1,
                'ip_address' => ClientContext::packedIp($request),
                'user_agent' => substr((string) $request->header('user-agent', 'crystalbest-user-worker'), 0, 1024),
                'metadata_json' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'occurred_at' => Clock::now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Service audit write failed action=' . $action . ' message=' . $e->getMessage());
        }
    }
}
