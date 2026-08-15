<?php

namespace app\service\Kyc;

use app\controller\Auth\AuthException;
use think\Request;

final class KycOpsGuard
{
    public function inspect(Request $request): array
    {
        $allowed = array_values(array_unique(array_filter(array_map('trim', (array) config('kyc.ops.allowed_ips', ['127.0.0.1', '::1'])))));
        $remoteIp = trim((string) $request->server('REMOTE_ADDR', ''));
        if ($remoteIp === '' || !in_array($remoteIp, $allowed, true)) {
            throw new AuthException('身份认证审核接口仅允许主站本机后台调用', 403, 'KYC_OPS_PRIVATE_ONLY');
        }
        return ['remote_ip' => $remoteIp, 'source_service' => 'kyc-ops'];
    }
}
