<?php

namespace app\service\Market;

use think\facade\Db;

final class MarketFavoriteService
{
    private const TYPE_SPOT = 1;
    private const TYPE_PERPETUAL = 2;
    private const MAX_PAGE_SIZE = 200;

    public function listForUser(
        int $userId,
        string $type = 'all',
        string $keyword = '',
        int $page = 1,
        int $pageSize = 100
    ): array {
        $page = max(1, $page);
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $normalizedType = $this->normalizeTypeFilter($type);
        $keyword = mb_strtolower(trim($keyword), 'UTF-8');

        $query = Db::table('cex_user_market_favorites')
            ->where('user_id', $userId)
            ->field('id,user_id,market_type,market_id,created_at')
            ->order('created_at', 'desc')
            ->order('id', 'desc');

        if ($normalizedType === 'spot') {
            $query->where('market_type', self::TYPE_SPOT);
        } elseif ($normalizedType === 'perpetual') {
            $query->where('market_type', self::TYPE_PERPETUAL);
        }

        $favorites = $query->select()->toArray();
        if (!$favorites) {
            return $this->pageResult([], 0, $page, $pageSize);
        }

        $spotIds = [];
        $perpIds = [];
        foreach ($favorites as $favorite) {
            if ((int) $favorite['market_type'] === self::TYPE_SPOT) {
                $spotIds[] = (int) $favorite['market_id'];
            } elseif ((int) $favorite['market_type'] === self::TYPE_PERPETUAL) {
                $perpIds[] = (int) $favorite['market_id'];
            }
        }

        $spotMarkets = $this->spotMarketsByIds($spotIds);
        $perpMarkets = $this->perpetualMarketsByIds($perpIds);
        $items = [];

        foreach ($favorites as $favorite) {
            $marketType = (int) $favorite['market_type'];
            $marketId = (int) $favorite['market_id'];
            $market = $marketType === self::TYPE_SPOT
                ? ($spotMarkets[$marketId] ?? null)
                : ($perpMarkets[$marketId] ?? null);
            if (!$market) {
                continue;
            }

            $item = $this->formatMarket(
                $market,
                $marketType === self::TYPE_SPOT ? 'spot' : 'perpetual',
                (int) $favorite['id'],
                (string) $favorite['created_at']
            );

            if ($keyword !== '' && !$this->matchesKeyword($item, $keyword)) {
                continue;
            }
            $items[] = $item;
        }

        $total = count($items);
        $offset = ($page - 1) * $pageSize;
        $pageItems = array_slice($items, $offset, $pageSize);
        return $this->pageResult($pageItems, $total, $page, $pageSize);
    }

    public function setFavorite(int $userId, string $type, string $symbol, bool $active): array
    {
        $marketType = $this->normalizeMarketType($type);
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            throw new \InvalidArgumentException('市场标识不能为空');
        }

        $market = $marketType === 'spot'
            ? $this->findSpotBySymbol($symbol)
            : $this->findPerpetualBySymbol($symbol);
        if (!$market) {
            throw new \InvalidArgumentException('市场不存在或当前不可用');
        }

        $typeValue = $marketType === 'spot' ? self::TYPE_SPOT : self::TYPE_PERPETUAL;
        $marketId = (int) $market['id'];

        if ($active) {
            Db::execute(
                'INSERT IGNORE INTO `cex_user_market_favorites` (`user_id`,`market_type`,`market_id`,`created_at`) VALUES (?,?,?,CURRENT_TIMESTAMP(3))',
                [$userId, $typeValue, $marketId]
            );
        } else {
            Db::table('cex_user_market_favorites')
                ->where('user_id', $userId)
                ->where('market_type', $typeValue)
                ->where('market_id', $marketId)
                ->delete();
        }

        $favorite = Db::table('cex_user_market_favorites')
            ->where('user_id', $userId)
            ->where('market_type', $typeValue)
            ->where('market_id', $marketId)
            ->field('id,created_at')
            ->find();

        return [
            'active' => (bool) $favorite,
            'key' => $marketType . ':' . strtoupper((string) $market['internal_symbol']),
            'market' => $this->formatMarket(
                $market,
                $marketType,
                $favorite ? (int) $favorite['id'] : 0,
                $favorite ? (string) $favorite['created_at'] : ''
            ),
        ];
    }

    private function normalizeTypeFilter(string $type): string
    {
        $type = strtolower(trim($type));
        if (in_array($type, ['spot'], true)) {
            return 'spot';
        }
        if (in_array($type, ['swap', 'perp', 'perpetual', 'futures'], true)) {
            return 'perpetual';
        }
        return 'all';
    }

    private function normalizeMarketType(string $type): string
    {
        $normalized = $this->normalizeTypeFilter($type);
        if ($normalized === 'all') {
            throw new \InvalidArgumentException('market_type 只能是 spot 或 perpetual');
        }
        return $normalized;
    }

    private function spotMarketsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }

        $rows = Db::table('cex_market_spot_symbols')
            ->alias('market')
            ->join('cex_asset_assets base_asset', 'base_asset.id = market.base_asset_id')
            ->join('cex_asset_assets quote_asset', 'quote_asset.id = market.quote_asset_id')
            ->whereIn('market.id', $ids)
            ->where('market.status', 1)
            ->where('base_asset.status', 1)
            ->where('base_asset.spot_enabled', 1)
            ->where('quote_asset.status', 1)
            ->field('market.id,market.symbol AS internal_symbol,market.price_tick,market.quantity_step,market.min_quantity,market.min_notional,market.maker_fee_rate,market.taker_fee_rate,base_asset.code AS base_code,base_asset.name AS base_name,quote_asset.code AS quote_code,quote_asset.name AS quote_name')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }
        return $map;
    }

    private function perpetualMarketsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }

        $rows = Db::table('cex_market_perpetual_contracts')
            ->alias('market')
            ->join('cex_market_price_indices price_index', 'price_index.id = market.price_index_id')
            ->join('cex_asset_assets base_asset', 'base_asset.id = market.base_asset_id')
            ->join('cex_asset_assets quote_asset', 'quote_asset.id = market.quote_asset_id')
            ->whereIn('market.id', $ids)
            ->where('market.status', 1)
            ->where('price_index.status', 1)
            ->where('base_asset.status', 1)
            ->where('quote_asset.status', 1)
            ->field('market.id,market.symbol AS internal_symbol,market.contract_size,market.price_tick,market.quantity_step,market.min_quantity,market.min_notional,market.maker_fee_rate,market.taker_fee_rate,market.max_leverage,base_asset.code AS base_code,base_asset.name AS base_name,quote_asset.code AS quote_code,quote_asset.name AS quote_name')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }
        return $map;
    }

    private function findSpotBySymbol(string $symbol): ?array
    {
        $row = Db::table('cex_market_spot_symbols')
            ->alias('market')
            ->join('cex_asset_assets base_asset', 'base_asset.id = market.base_asset_id')
            ->join('cex_asset_assets quote_asset', 'quote_asset.id = market.quote_asset_id')
            ->where('market.symbol', $symbol)
            ->where('market.status', 1)
            ->where('base_asset.status', 1)
            ->where('base_asset.spot_enabled', 1)
            ->where('quote_asset.status', 1)
            ->field('market.id,market.symbol AS internal_symbol,market.price_tick,market.quantity_step,market.min_quantity,market.min_notional,market.maker_fee_rate,market.taker_fee_rate,base_asset.code AS base_code,base_asset.name AS base_name,quote_asset.code AS quote_code,quote_asset.name AS quote_name')
            ->find();
        return $row ?: null;
    }

    private function findPerpetualBySymbol(string $symbol): ?array
    {
        $row = Db::table('cex_market_perpetual_contracts')
            ->alias('market')
            ->join('cex_market_price_indices price_index', 'price_index.id = market.price_index_id')
            ->join('cex_asset_assets base_asset', 'base_asset.id = market.base_asset_id')
            ->join('cex_asset_assets quote_asset', 'quote_asset.id = market.quote_asset_id')
            ->where('market.symbol', $symbol)
            ->where('market.status', 1)
            ->where('price_index.status', 1)
            ->where('base_asset.status', 1)
            ->where('quote_asset.status', 1)
            ->field('market.id,market.symbol AS internal_symbol,market.contract_size,market.price_tick,market.quantity_step,market.min_quantity,market.min_notional,market.maker_fee_rate,market.taker_fee_rate,market.max_leverage,base_asset.code AS base_code,base_asset.name AS base_name,quote_asset.code AS quote_code,quote_asset.name AS quote_name')
            ->find();
        return $row ?: null;
    }

    private function formatMarket(array $row, string $marketType, int $favoriteId, string $createdAt): array
    {
        $baseCode = strtoupper((string) $row['base_code']);
        $quoteCode = strtoupper((string) $row['quote_code']);
        $symbol = strtoupper((string) $row['internal_symbol']);
        $pair = $baseCode . '/' . $quoteCode;
        $isPerpetual = $marketType === 'perpetual';
        $slug = strtolower($baseCode . '-' . $quoteCode . ($isPerpetual ? '-swap' : ''));

        $rules = [
            'price_tick' => isset($row['price_tick']) ? (string) $row['price_tick'] : null,
            'quantity_step' => isset($row['quantity_step']) ? (string) $row['quantity_step'] : null,
            'min_quantity' => isset($row['min_quantity']) ? (string) $row['min_quantity'] : null,
            'min_notional' => isset($row['min_notional']) ? (string) $row['min_notional'] : null,
            'maker_fee_rate' => isset($row['maker_fee_rate']) ? (string) $row['maker_fee_rate'] : null,
            'taker_fee_rate' => isset($row['taker_fee_rate']) ? (string) $row['taker_fee_rate'] : null,
        ];
        if ($isPerpetual && isset($row['max_leverage'])) {
            $rules['max_leverage'] = (string) $row['max_leverage'];
        }

        return [
            'favorite_id' => $favoriteId,
            'favorited_at' => $createdAt,
            'id' => (int) $row['id'],
            'market_id' => (int) $row['id'],
            'market_type' => $marketType,
            'mode' => $isPerpetual ? 'swap' : 'spot',
            'symbol' => $symbol,
            'internal_symbol' => $symbol,
            'base_code' => $baseCode,
            'base_name' => (string) $row['base_name'],
            'quote_code' => $quoteCode,
            'quote_name' => (string) $row['quote_name'],
            'base_asset' => ['code' => $baseCode, 'name' => (string) $row['base_name']],
            'quote_asset' => ['code' => $quoteCode, 'name' => (string) $row['quote_name']],
            'pair' => $pair,
            'slug' => $slug,
            'route' => ($isPerpetual ? '/trade-swap/' : '/trade-spot/') . $slug,
            'market_label' => $isPerpetual ? 'USDT 本位永续合约' : '现货交易',
            'rules' => $rules,
            'ticker' => new \stdClass(),
            'sparkline_24h' => [],
        ];
    }

    private function matchesKeyword(array $item, string $keyword): bool
    {
        $haystack = mb_strtolower(implode(' ', [
            (string) ($item['base_code'] ?? ''),
            (string) ($item['base_name'] ?? ''),
            (string) ($item['quote_code'] ?? ''),
            (string) ($item['pair'] ?? ''),
            (string) ($item['symbol'] ?? ''),
            (string) ($item['market_label'] ?? ''),
        ]), 'UTF-8');
        return mb_strpos($haystack, $keyword, 0, 'UTF-8') !== false;
    }

    private function pageResult(array $items, int $total, int $page, int $pageSize): array
    {
        $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 0;
        return [
            'items' => array_values($items),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_more' => $page < $totalPages,
                'next_page' => $page < $totalPages ? $page + 1 : null,
            ],
        ];
    }
}
