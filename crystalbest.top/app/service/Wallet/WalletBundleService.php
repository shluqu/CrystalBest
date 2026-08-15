<?php

namespace app\service\Wallet;

use app\controller\Auth\Ulid;
use app\controller\Auth\UtcClock;
use app\service\Asset\AssetException;
use think\facade\Db;

final class WalletBundleService
{
    public const BUNDLE_ACTIVE = 1;
    public const BUNDLE_RETIRED = 2;
    public const BUNDLE_DISABLED = 3;

    public const WALLET_TYPE_DEPOSIT_EDGE = 5;
    public const CUSTODY_BACKEND_INTERNAL_API = 4;
    public const ADDRESS_TYPE_USER_DEPOSIT = 1;

    private $client;

    public function __construct(?WalletCustodyClient $client = null)
    {
        $this->client = $client ?: new WalletCustodyClient();
    }

    public function clientConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function findActiveBundle(int $accountId): ?array
    {
        $bundle = Db::table('cex_wallet_custody_bundles')
            ->where('account_id', $accountId)
            ->where('status', self::BUNDLE_ACTIVE)
            ->field('id,bundle_no,external_bundle_id,account_id,provider_code,backend_code,keyset_version,allocated_at')
            ->find();
        if (!$bundle) {
            return null;
        }
        $bundle['addresses'] = $this->bundleAddresses((int) $bundle['id']);
        $bundle['existing'] = true;
        return $bundle;
    }

    /**
     * Allocate once from 10.0.0.1 and persist only public data in 10.0.0.2.
     * Network calls happen before the DB transaction; the remote endpoint MUST be
     * idempotent for the supplied account_ref/idempotency_key.
     */
    public function ensureBundle(array $account, string $userUid): array
    {
        $accountId = (int) ($account['id'] ?? 0);
        $accountPublicId = (string) ($account['public_id'] ?? '');
        if ($accountId <= 0) {
            throw new AssetException('业务账户不存在', 500, 'BUSINESS_ACCOUNT_INVALID');
        }

        $existing = $this->findActiveBundle($accountId);
        if ($existing) {
            $this->assertBundleComplete($existing);
            return $existing;
        }

        $networks = array_values((array) config('wallet.bundle_networks', []));
        $remote = $this->client->allocateBundle($accountPublicId, $userUid, $networks);

        return Db::transaction(function () use ($accountId, $accountPublicId, $remote, $networks) {
            // Serialize persistence for one business account without holding a lock
            // during the network request.
            $lockedAccount = Db::table('cex_account_accounts')
                ->where('id', $accountId)
                ->where('account_kind', 1)
                ->field('id,public_id,status')
                ->lock(true)
                ->find();
            if (!$lockedAccount || (int) $lockedAccount['status'] !== 1 || (string) $lockedAccount['public_id'] !== $accountPublicId) {
                throw new AssetException('业务账户当前不可用', 409, 'BUSINESS_ACCOUNT_UNAVAILABLE');
            }

            $existing = Db::table('cex_wallet_custody_bundles')
                ->where('account_id', $accountId)
                ->where('status', self::BUNDLE_ACTIVE)
                ->field('id,bundle_no,external_bundle_id,account_id,provider_code,backend_code,keyset_version,allocated_at')
                ->lock(true)
                ->find();
            if ($existing) {
                $existing['addresses'] = $this->bundleAddresses((int) $existing['id']);
                $existing['existing'] = true;
                $this->assertBundleComplete($existing);
                return $existing;
            }

            $now = UtcClock::now();
            $bundleId = (int) Db::table('cex_wallet_custody_bundles')->insertGetId([
                'bundle_no' => Ulid::generate(),
                'external_bundle_id' => (string) $remote['external_bundle_id'],
                'account_id' => $accountId,
                'provider_code' => 'INTERNAL',
                'backend_code' => 'WALLET_API',
                'keyset_version' => $remote['keyset_version'] ?? null,
                'status' => self::BUNDLE_ACTIVE,
                'allocation_request_id' => (string) $remote['request_id'],
                'metadata_json' => json_encode([
                    'bundle_version' => $remote['bundle_version'] ?? null,
                    'network_count' => count($networks),
                    'private_material_stored' => false,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'allocated_at' => $now,
            ]);

            $networkRows = Db::table('cex_asset_networks')
                ->whereIn('code', $networks)
                ->field('id,code,name')
                ->select()
                ->toArray();
            $networkMap = [];
            foreach ($networkRows as $row) {
                $networkMap[(string) $row['code']] = $row;
            }
            if (count($networkMap) !== count($networks)) {
                throw new AssetException('本地网络配置不完整，无法保存 Wallet Bundle', 500, 'WALLET_NETWORK_CONFIG_INCOMPLETE');
            }

            foreach ($networks as $networkCode) {
                $remoteAddress = $remote['addresses'][$networkCode] ?? null;
                if (!$remoteAddress) {
                    throw new AssetException('Wallet Bundle 地址集合不完整', 502, 'WALLET_BUNDLE_ADDRESSES_INCOMPLETE');
                }
                $networkId = (int) $networkMap[$networkCode]['id'];
                $walletId = $this->ensureDepositEdgeWallet($networkId, $networkCode);
                $address = (string) $remoteAddress['address'];
                $addressHash = WalletAddressNormalizer::hash($networkCode, $address);

                $collision = Db::table('cex_wallet_addresses')
                    ->where('network_id', $networkId)
                    ->where('address_hash', $addressHash)
                    ->field('id,account_id,custody_bundle_id')
                    ->lock(true)
                    ->find();
                if ($collision) {
                    throw new AssetException('钱包服务返回了已被使用的充值地址，已拒绝分配', 502, 'WALLET_ADDRESS_COLLISION');
                }

                Db::table('cex_wallet_addresses')->insert([
                    'wallet_id' => $walletId,
                    'custody_bundle_id' => $bundleId,
                    'network_id' => $networkId,
                    'account_id' => $accountId,
                    'address_type' => self::ADDRESS_TYPE_USER_DEPOSIT,
                    'address' => $address,
                    'address_hash' => $addressHash,
                    'memo' => null,
                    'memo_hash' => str_repeat("\0", 32),
                    'derivation_path' => $remoteAddress['derivation_path'] ?? null,
                    'custody_address_ref' => (string) $remoteAddress['address_ref'],
                    'status' => 1,
                    'assigned_at' => $now,
                ]);
            }

            $bundle = Db::table('cex_wallet_custody_bundles')
                ->where('id', $bundleId)
                ->field('id,bundle_no,external_bundle_id,account_id,provider_code,backend_code,keyset_version,allocated_at')
                ->find();
            $bundle['addresses'] = $this->bundleAddresses($bundleId);
            $bundle['existing'] = false;
            $this->assertBundleComplete($bundle);
            return $bundle;
        });
    }

    public function addressForNetwork(int $accountId, string $networkCode): ?array
    {
        $networkCode = strtoupper(trim($networkCode));
        $row = Db::table('cex_wallet_addresses')->alias('wa')
            ->join('cex_asset_networks n', 'n.id = wa.network_id')
            ->join('cex_wallet_custody_bundles b', 'b.id = wa.custody_bundle_id')
            ->where('wa.account_id', $accountId)
            ->where('wa.address_type', self::ADDRESS_TYPE_USER_DEPOSIT)
            ->where('wa.status', 1)
            ->where('b.status', self::BUNDLE_ACTIVE)
            ->where('n.code', $networkCode)
            ->field('wa.id,wa.address,wa.memo,wa.derivation_path,wa.custody_address_ref,wa.assigned_at,n.id AS network_id,n.code AS network_code,n.name AS network_name,b.bundle_no')
            ->find();
        return $row ?: null;
    }

    private function bundleAddresses(int $bundleId): array
    {
        $rows = Db::table('cex_wallet_addresses')->alias('wa')
            ->join('cex_asset_networks n', 'n.id = wa.network_id')
            ->where('wa.custody_bundle_id', $bundleId)
            ->where('wa.address_type', self::ADDRESS_TYPE_USER_DEPOSIT)
            ->where('wa.status', 1)
            ->field('wa.id,wa.address,wa.memo,wa.derivation_path,wa.custody_address_ref,wa.assigned_at,n.id AS network_id,n.code AS network_code,n.name AS network_name')
            ->order('n.id', 'asc')
            ->select()
            ->toArray();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['network_code']] = $row;
        }
        return $result;
    }

    private function ensureDepositEdgeWallet(int $networkId, string $networkCode): int
    {
        $walletCode = 'CB_DEPOSIT_EDGE_' . $networkCode;
        $row = Db::table('cex_wallet_wallets')->where('wallet_code', $walletCode)->field('id,status')->find();
        if (!$row) {
            try {
                $id = (int) Db::table('cex_wallet_wallets')->insertGetId([
                    'wallet_code' => $walletCode,
                    'network_id' => $networkId,
                    'wallet_type' => self::WALLET_TYPE_DEPOSIT_EDGE,
                    'custody_backend' => self::CUSTODY_BACKEND_INTERNAL_API,
                    'signing_key_ref' => null,
                    'status' => 1,
                    'metadata_json' => json_encode([
                        'purpose' => 'USER_DEPOSIT_EDGE',
                        'private_keys_on_web_server' => false,
                        'custody_api' => '10.0.0.1',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                return $id;
            } catch (\Throwable $exception) {
                $row = Db::table('cex_wallet_wallets')->where('wallet_code', $walletCode)->field('id,status')->find();
                if (!$row) {
                    throw $exception;
                }
            }
        }
        if ((int) $row['status'] !== 1) {
            throw new AssetException('充值边缘钱包当前不可用', 409, 'DEPOSIT_EDGE_WALLET_UNAVAILABLE');
        }
        return (int) $row['id'];
    }

    private function assertBundleComplete(array $bundle): void
    {
        $expected = array_values((array) config('wallet.bundle_networks', []));
        $addresses = isset($bundle['addresses']) && is_array($bundle['addresses']) ? $bundle['addresses'] : [];
        foreach ($expected as $network) {
            if (!isset($addresses[$network])) {
                throw new AssetException('当前 Wallet Bundle 数据不完整，请暂停充值并检查钱包服务', 503, 'WALLET_BUNDLE_LOCAL_INCOMPLETE');
            }
        }
    }
}
