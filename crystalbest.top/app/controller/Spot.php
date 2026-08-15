<?php

namespace app\controller;

use app\BaseController;
use think\facade\Db;

class Spot extends BaseController
{
    private const PAGE_SIZE = 10;

    public function index(string $symbol = '')
    {
        $currentMarket = $this->findCurrentMarket($symbol);
        if (!$currentMarket) {
            abort(404, '现货交易对不存在');
        }

        $initialPage = $this->paginateMarkets(1, self::PAGE_SIZE, '');
        $marketList = $this->ensureCurrentMarket($initialPage['items'], $currentMarket);

        return view('trade/index', [
            'tradeMode' => 'spot',
            'marketList' => $marketList,
            'marketPagination' => $initialPage['pagination'],
            'currentMarket' => $currentMarket,
            'marketListLabel' => '现货市场列表',
            'searchLabel' => '查询现货交易对',
            'marketEmptyText' => '没有找到相关现货交易对',
            'tradeHeading' => $currentMarket['pair'] . ' 现货交易',
            'pageTitle' => $currentMarket['pair'] . ' 现货交易 — CrystalBest',
            'pageDescription' => 'CrystalBest ' . $currentMarket['pair'] . ' 现货交易页面。',
        ]);
    }

    public function markets()
    {
        $page = max(1, (int) $this->request->get('page', 1));
        $keyword = trim((string) $this->request->get('keyword', ''));

        return json([
            'code' => 0,
            'message' => 'success',
            'data' => $this->paginateMarkets($page, self::PAGE_SIZE, $keyword),
        ]);
    }

    private function paginateMarkets(int $page, int $pageSize, string $keyword): array
    {
        $query = $this->marketQuery($keyword);
        $total = (int) (clone $query)->count();
        $rows = $query
            ->field('market.id,market.symbol AS internal_symbol,market.price_tick,market.quantity_step,market.min_quantity,market.min_notional,market.maker_fee_rate,market.taker_fee_rate,base_asset.code AS base_code,base_asset.name AS base_name,quote_asset.code AS quote_code,quote_asset.name AS quote_name')
            ->order('market.id', 'asc')
            ->limit(($page - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row = $this->formatMarket($row);
        }
        unset($row);

        $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 0;
        return [
            'items' => $rows,
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

    private function findCurrentMarket(string $symbol): ?array
    {
        $rows = $this->marketQuery('')
            ->field('market.id,market.symbol AS internal_symbol,market.price_tick,market.quantity_step,market.min_quantity,market.min_notional,market.maker_fee_rate,market.taker_fee_rate,base_asset.code AS base_code,base_asset.name AS base_name,quote_asset.code AS quote_code,quote_asset.name AS quote_name')
            ->order('market.id', 'asc')
            ->select()
            ->toArray();

        $symbol = strtolower(trim($symbol));
        $default = null;

        foreach ($rows as $row) {
            $market = $this->formatMarket($row);
            if ($symbol !== '' && $market['slug'] === $symbol) {
                return $market;
            }
            if ($market['base_code'] === 'BTC' && $market['quote_code'] === 'USDT') {
                $default = $market;
            }
            if ($default === null) {
                $default = $market;
            }
        }

        return $symbol === '' ? $default : null;
    }

    private function ensureCurrentMarket(array $items, array $currentMarket): array
    {
        foreach ($items as $item) {
            if ($item['internal_symbol'] === $currentMarket['internal_symbol']) {
                return $items;
            }
        }

        array_unshift($items, $currentMarket);
        return array_slice($items, 0, self::PAGE_SIZE);
    }

    private function marketQuery(string $keyword)
    {
        $query = Db::table('cex_market_spot_symbols')
            ->alias('market')
            ->join('cex_asset_assets base_asset', 'base_asset.id = market.base_asset_id')
            ->join('cex_asset_assets quote_asset', 'quote_asset.id = market.quote_asset_id')
            ->where('market.status', 1)
            ->where('base_asset.status', 1)
            ->where('base_asset.spot_enabled', 1)
            ->where('quote_asset.status', 1);

        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->whereRaw(
                '(base_asset.code LIKE :market_keyword OR base_asset.name LIKE :market_keyword OR quote_asset.code LIKE :market_keyword OR market.symbol LIKE :market_keyword)',
                ['market_keyword' => $like]
            );
        }

        return $query;
    }

    private function formatMarket(array $row): array
    {
        $baseCode = strtoupper((string) $row['base_code']);
        $quoteCode = strtoupper((string) $row['quote_code']);
        $slug = strtolower($baseCode . '-' . $quoteCode);

        return [
            'id' => (int) $row['id'],
            'mode' => 'spot',
            'internal_symbol' => (string) $row['internal_symbol'],
            'base_code' => $baseCode,
            'base_name' => (string) $row['base_name'],
            'quote_code' => $quoteCode,
            'quote_name' => (string) $row['quote_name'],
            'pair' => $baseCode . '/' . $quoteCode,
            'slug' => $slug,
            'price_tick' => (string) $row['price_tick'],
            'quantity_step' => (string) $row['quantity_step'],
            'min_quantity' => (string) $row['min_quantity'],
            'min_notional' => (string) $row['min_notional'],
            'maker_fee_rate' => (string) $row['maker_fee_rate'],
            'taker_fee_rate' => (string) $row['taker_fee_rate'],
            'route' => '/trade-spot/' . $slug,
        ];
    }
}
