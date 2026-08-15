<?php

namespace app\service\Wallet;

use app\controller\Auth\AuthService;
use app\controller\Auth\BusinessAccountService;
use app\service\Asset\AssetException;
use app\service\Asset\Decimal18;
use think\facade\Db;
use think\Request;

final class DepositService
{
    private $request;
    private $authContext;
    private $businessAccount;
    private $bundleService;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->bundleService = new WalletBundleService();
    }

    public function context(): array
    {
        $account = $this->businessAccount();
        $routes = $this->depositRoutes();
        $bundle = null;
        try {
            $bundle = $this->bundleService->findActiveBundle((int) $account['id']);
        } catch (\Throwable $exception) {
            // A partially deployed migration must not leak a raw SQL error to users.
            $bundle = null;
        }

        foreach ($routes as &$route) {
            $route['address'] = null;
            $route['address_assigned'] = false;
            if ($bundle && isset($bundle['addresses'][$route['network_code']])) {
                $address = $bundle['addresses'][$route['network_code']];
                $route['address'] = [
                    'address' => (string) $address['address'],
                    'memo' => $address['memo'] ?? null,
                    'assigned_at' => $address['assigned_at'] ?? null,
                ];
                $route['address_assigned'] = true;
            }
        }
        unset($route);

        $eligibility = (new WalletEligibilityService())->forUser((int) $this->authContext()['user_id']);
        $allocationState = $this->allocationState($eligibility, $bundle !== null);

        return [
            'reference_provider' => (string) config('wallet.reference_provider', 'OKX'),
            'wallet_api_configured' => $this->bundleService->clientConfigured(),
            'bundle_assigned' => $bundle !== null,
            'allocation_eligibility' => $eligibility,
            'allocation_state' => $allocationState,
            'bundle_no' => $bundle ? (string) $bundle['bundle_no'] : null,
            'routes' => $routes,
            'history' => $this->recentDeposits((int) $account['id'], (int) config('wallet.deposit.history_limit', 30)),
            'policy' => [
                'bundle_networks' => array_values((array) config('wallet.bundle_networks', [])),
                'address_reuse_by_network' => true,
                'ethereum_address_shared_by_eth_and_erc20' => true,
                'private_key_visible_to_web' => false,
                'credit_scope' => 'SPOT_AVAILABLE',
            ],
        ];
    }

    public function ensureAddress(string $routeCode): array
    {
        $account = $this->businessAccount();
        $route = $this->routeByCode($routeCode);
        $this->assertRouteAvailable($route);

        // Phase B.1 policy: users never allocate a Wallet Bundle on demand.
        // Allocation is asynchronous and only occurs after TOTP + approved KYC.
        $bundle = $this->bundleService->findActiveBundle((int) $account['id']);
        if (!$bundle) {
            $eligibility = (new WalletEligibilityService())->forUser((int) $this->authContext()['user_id']);
            if (!(bool) ($eligibility['eligible'] ?? false)) {
                throw new AssetException(
                    (string) ($eligibility['message'] ?? '完成 Google Authenticator 与实名认证后，系统会自动分配充值地址'),
                    409,
                    'WALLET_BUNDLE_NOT_ELIGIBLE'
                );
            }
            throw new AssetException('账户已满足条件，钱包地址正在后台自动分配，请稍后刷新', 409, 'WALLET_BUNDLE_ALLOCATION_PENDING');
        }

        $address = $bundle['addresses'][(string) $route['network_code']] ?? null;
        if (!$address) {
            throw new AssetException('充值地址数据不完整，请暂停充值并联系支持', 503, 'DEPOSIT_ADDRESS_NOT_FOUND');
        }

        return [
            'asset' => [
                'code' => (string) $route['asset_code'],
                'name' => (string) $route['asset_name'],
            ],
            'route_code' => (string) $route['route_code'],
            'network_code' => (string) $route['network_code'],
            'network_name' => (string) $route['network_name'],
            'token_standard' => (string) $route['token_standard'],
            'address' => (string) $address['address'],
            'memo' => $address['memo'] ?? null,
            'min_deposit_amount' => Decimal18::trim((string) $route['effective_min_deposit'], (int) $route['display_decimals']),
            'required_confirmations' => (int) $route['effective_required_confirmations'],
            'bundle_no' => (string) $bundle['bundle_no'],
            'notice' => '仅向该地址充值所选资产和网络；错误网络可能造成不可恢复的资产损失。',
        ];
    }


    private function allocationState(array $eligibility, bool $bundleAssigned): array
    {
        if ($bundleAssigned) {
            return [
                'state' => 'READY',
                'blocked' => false,
                'poll' => false,
                'title' => '充值地址已准备',
                'message' => '选择资产与网络即可直接查看专属充值地址。',
                'missing' => [],
            ];
        }

        if ((bool) ($eligibility['eligible'] ?? false)) {
            return [
                'state' => 'ALLOCATING',
                'blocked' => false,
                'poll' => true,
                'title' => '充值地址自动分配中',
                'message' => '账户已满足条件，后台正在自动分配整组充值地址，无需点击任何按钮。',
                'missing' => [],
            ];
        }

        $missing = array_values((array) ($eligibility['missing'] ?? []));
        $hasKycMissing = false;
        foreach ($missing as $item) {
            if ((string) ($item['code'] ?? '') === 'KYC_REQUIRED') {
                $hasKycMissing = true;
                break;
            }
        }

        return [
            'state' => 'BLOCKED',
            'blocked' => true,
            'poll' => false,
            'title' => $hasKycMissing ? '实名认证尚未完成' : '充值资料尚未完善',
            'message' => (string) ($eligibility['message'] ?? '请先完善账户安全与实名认证信息。'),
            'missing' => $missing,
        ];
    }

    private function depositRoutes(): array
    {
        $allowedAssets = array_values((array) config('assets.overview_assets', ['USDT', 'BTC', 'ETH', 'DOGE', 'SOL']));

        $rows = Db::table('cex_asset_asset_networks')->alias('an')
            ->join('cex_asset_assets a', 'a.id = an.asset_id')
            ->join('cex_asset_networks n', 'n.id = an.network_id')
            ->whereIn('a.code', $allowedAssets)
            ->where('a.status', 1)
            ->field([
                'an.id AS asset_network_id', 'an.route_code', 'an.token_standard', 'an.contract_address',
                'an.required_confirmations AS local_required_confirmations', 'an.min_deposit_amount AS local_min_deposit',
                'an.status AS local_route_status', 'an.asset_decimals_on_chain',
                'a.id AS asset_id', 'a.code AS asset_code', 'a.name AS asset_name', 'a.display_decimals', 'a.deposit_enabled',
                'n.id AS network_id', 'n.code AS network_code', 'n.name AS network_name', 'n.status AS network_status',
            ])
            ->order('a.id', 'asc')
            ->order('an.id', 'asc')
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['asset_network_id'] = (int) $row['asset_network_id'];
            $row['asset_id'] = (int) $row['asset_id'];
            $row['network_id'] = (int) $row['network_id'];
            $row['display_decimals'] = (int) $row['display_decimals'];
            $row['local_route_status'] = (int) $row['local_route_status'];
            $row['network_status'] = (int) $row['network_status'];
            $row['deposit_enabled'] = (bool) $row['deposit_enabled'];
            // Deposit availability and confirmation policy are CrystalBest-local only.
            // OKX reference data is intentionally not joined into this decision path.
            $row['effective_min_deposit'] = Decimal18::normalize((string) $row['local_min_deposit']);
            $row['effective_required_confirmations'] = (int) $row['local_required_confirmations'];

            $reasons = [];
            if (!$row['deposit_enabled']) $reasons[] = '资产充值未启用';
            if ($row['network_status'] !== 1) $reasons[] = '本地网络未启用';
            if ($row['local_route_status'] !== 1) $reasons[] = '本地充值路线未启用';

            $row['available'] = $reasons === [];
            $row['availability_reason'] = $reasons === [] ? '可充值' : implode('；', $reasons);
            $row['effective_min_deposit_display'] = Decimal18::trim($row['effective_min_deposit'], $row['display_decimals']);
        }
        unset($row);

        return $rows;
    }

    private function routeByCode(string $routeCode): array
    {
        $routeCode = strtoupper(trim($routeCode));
        if ($routeCode === '' || strlen($routeCode) > 48 || !preg_match('/^[A-Z0-9\-]+$/', $routeCode)) {
            throw new AssetException('充值网络参数无效', 422, 'DEPOSIT_ROUTE_INVALID');
        }
        foreach ($this->depositRoutes() as $route) {
            if ((string) $route['route_code'] === $routeCode) {
                return $route;
            }
        }
        throw new AssetException('该充值网络不存在', 404, 'DEPOSIT_ROUTE_NOT_FOUND');
    }

    private function assertRouteAvailable(array $route): void
    {
        if (!(bool) ($route['available'] ?? false)) {
            throw new AssetException((string) ($route['availability_reason'] ?? '该充值网络暂不可用'), 409, 'DEPOSIT_ROUTE_UNAVAILABLE');
        }
    }

    private function recentDeposits(int $accountId, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $rows = Db::table('cex_wallet_deposits')->alias('d')
            ->join('cex_asset_asset_networks an', 'an.id = d.asset_network_id')
            ->join('cex_asset_assets a', 'a.id = an.asset_id')
            ->join('cex_asset_networks n', 'n.id = an.network_id')
            ->where('d.account_id', $accountId)
            ->field('d.deposit_no,d.tx_hash,d.event_index,d.amount,d.confirmations,d.required_confirmations_snapshot,d.status,d.detected_at,d.credited_at,d.reversed_at,an.route_code,an.required_confirmations AS route_required_confirmations,a.code AS asset_code,a.display_decimals,n.code AS network_code,n.name AS network_name,n.explorer_tx_url')
            ->order('d.id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        $labels = [
            1 => '已检测',
            2 => '确认中',
            3 => '已到账',
            4 => '已冲正',
            5 => '低于最小充值',
            6 => '人工复核',
        ];
        foreach ($rows as &$row) {
            $row['status'] = (int) $row['status'];
            $row['status_label'] = $labels[$row['status']] ?? '未知';
            $row['confirmations'] = (int) $row['confirmations'];
            $row['required_confirmations'] = $row['required_confirmations_snapshot'] !== null
                ? (int) $row['required_confirmations_snapshot']
                : (int) $row['route_required_confirmations'];
            $row['event_index'] = (int) $row['event_index'];
            $row['amount'] = Decimal18::trim((string) $row['amount'], (int) $row['display_decimals']);
            $row['confirmation_display'] = $row['status'] === 3
                ? max($row['confirmations'], $row['required_confirmations']) . ' / ' . $row['required_confirmations']
                : $row['confirmations'] . ' / ' . $row['required_confirmations'];
            $row['explorer_tx_url'] = $this->explorerTxUrl(
                (string) $row['network_code'],
                (string) $row['tx_hash'],
                $row['explorer_tx_url'] ?? null
            );
            unset($row['required_confirmations_snapshot'], $row['route_required_confirmations']);
        }
        unset($row);
        return $rows;
    }

    private function explorerTxUrl(string $networkCode, string $txHash, $databaseTemplate): ?string
    {
        $template = trim((string) ($databaseTemplate ?? ''));
        if ($template === '') {
            $templates = (array) config('wallet.explorer_tx_urls', []);
            $template = trim((string) ($templates[strtoupper($networkCode)] ?? ''));
        }
        if ($template === '' || strpos($template, '{tx}') === false) {
            return null;
        }

        $url = str_replace('{tx}', rawurlencode($txHash), $template);
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['https'], true)) {
            return null;
        }
        return $url;
    }

    private function authContext(): array
    {
        if ($this->authContext !== null) return $this->authContext;
        $auth = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($auth->cookieName(), '');
        $this->authContext = $auth->authenticatedSession($cookie, true);
        return $this->authContext;
    }

    private function businessAccount(): array
    {
        if ($this->businessAccount !== null) return $this->businessAccount;
        $auth = $this->authContext();
        $userId = (int) $auth['user_id'];
        $row = Db::table('cex_account_accounts')
            ->where('user_id', $userId)
            ->where('account_kind', 1)
            ->field('id,public_id,status,user_id')
            ->find();
        if (!$row) {
            BusinessAccountService::createForUser($userId, (string) $auth['uid']);
            $row = Db::table('cex_account_accounts')
                ->where('user_id', $userId)
                ->where('account_kind', 1)
                ->field('id,public_id,status,user_id')
                ->find();
        }
        if (!$row || (int) $row['status'] !== 1) {
            throw new AssetException('资产账户当前不可用', 409, 'ASSET_ACCOUNT_UNAVAILABLE');
        }
        $this->businessAccount = $row;
        return $row;
    }
}
