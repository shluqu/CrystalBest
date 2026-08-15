<?php

namespace app\service\Wallet;

use app\service\Asset\AssetException;

final class WalletAddressNormalizer
{
    public static function normalize(string $networkCode, string $address): string
    {
        $networkCode = strtoupper(trim($networkCode));
        $address = trim($address);
        if ($address === '' || strlen($address) > 255 || preg_match('/\s/', $address)) {
            throw new AssetException('钱包地址格式无效', 422, 'WALLET_ADDRESS_INVALID');
        }

        if ($networkCode === 'ETHEREUM') {
            if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
                throw new AssetException('Ethereum 地址格式无效', 422, 'ETHEREUM_ADDRESS_INVALID');
            }
            return strtolower($address);
        }

        if ($networkCode === 'BITCOIN') {
            if (stripos($address, 'bc1') === 0) {
                return strtolower($address);
            }
            return $address;
        }

        // TRON/Dogecoin/Solana base encodings are case-sensitive.
        return $address;
    }

    public static function hash(string $networkCode, string $address): string
    {
        return hash('sha256', self::normalize($networkCode, $address), true);
    }

    private function __construct()
    {
    }
}
