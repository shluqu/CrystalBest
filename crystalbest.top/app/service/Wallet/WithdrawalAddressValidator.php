<?php

namespace app\service\Wallet;

use app\service\Asset\AssetException;

final class WithdrawalAddressValidator
{
    public function validate(string $networkCode, string $address, bool $memoRequired, ?string $memo): array
    {
        $networkCode = strtoupper(trim($networkCode));
        $address = trim($address);
        $memo = $memo === null ? null : trim($memo);

        if ($address === '' || strlen($address) > 255 || !preg_match('/^[\x21-\x7E]+$/', $address)) {
            throw new AssetException('请输入有效的提币地址', 422, 'WITHDRAW_ADDRESS_INVALID');
        }

        $valid = false;
        switch ($networkCode) {
            case 'BITCOIN':
                $valid = (bool) preg_match('/^(?:bc1[ac-hj-np-z02-9]{11,71}|[13][1-9A-HJ-NP-Za-km-z]{25,34})$/i', $address);
                break;
            case 'ETHEREUM':
                $valid = (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', $address);
                break;
            case 'TRON':
                $valid = (bool) preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address);
                break;
            case 'DOGECOIN':
                $valid = (bool) preg_match('/^[DA9][1-9A-HJ-NP-Za-km-z]{25,34}$/', $address);
                break;
            case 'SOLANA':
                $valid = (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address);
                break;
            default:
                throw new AssetException('当前网络暂不支持提币', 409, 'WITHDRAW_NETWORK_UNSUPPORTED');
        }

        if (!$valid) {
            throw new AssetException('提币地址格式与所选网络不匹配，请重新核对', 422, 'WITHDRAW_ADDRESS_FORMAT_MISMATCH');
        }

        if ($memoRequired && ($memo === null || $memo === '')) {
            throw new AssetException('该网络提币必须填写 Memo / Tag', 422, 'WITHDRAW_MEMO_REQUIRED');
        }
        if ($memo !== null && $memo !== '') {
            if (strlen($memo) > 255 || !preg_match('/^[\x20-\x7E]+$/', $memo)) {
                throw new AssetException('Memo / Tag 格式无效', 422, 'WITHDRAW_MEMO_INVALID');
            }
        } else {
            $memo = null;
        }

        $hashAddress = $networkCode === 'ETHEREUM' ? strtolower($address) : $address;

        return [
            'address' => $address,
            'address_hash' => hash('sha256', $hashAddress, true),
            'memo' => $memo,
            'memo_hash' => $memo === null ? str_repeat("\0", 32) : hash('sha256', $memo, true),
        ];
    }
}
