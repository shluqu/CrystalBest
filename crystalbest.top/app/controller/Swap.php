<?php

namespace app\controller;

use app\BaseController;
use think\facade\Db;

class Swap extends BaseController
{
    private const PAGE_SIZE = 10;

    public function index(string $symbol = '')
    {
        $currentMarket = $this->findCurrentMarket($symbol);
        if (!$currentMarket) {
            abort(404, '永续合约交易对不存在');
        }

        $initialPage = $this->paginateMarkets(1, self::PAGE_SIZE, '');
        $marketList = $this->ensureCurrentMarket($initialPage['items'], $currentMarket);

        return view('trade/index', [
            'tradeMode' => 'swap',
            'marketList' => $marketList,
            'marketPagination' => $initialPage['pagination'],
            'currentMarket' => $currentMarket,
            'marketListLabel' => '合约市场列表',
            'searchLabel' => '查询合约',
            'marketEmptyText' => '没有找到相关合约',
            'tradeHeading' => $currentMarket['pair'] . ' USDT 本位永续合约交易',
            'pageTitle' => $currentMarket['pair'] . ' USDT 本位永续合约 — Aster Exchange',
            'pageDescription' => 'Aster Exchange ' . $currentMarket['pair'] . ' USDT 本位永续合约交易页面。',
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
            ->field('market.id,market.symbol AS internal_symbol,market.contract_size,market.price_tick,market.quantity_step,market.min_quantity,market.max_quantity,market.min_notional,market.max_notional,market.maker_fee_rate,market.taker_fee_rate,market.max_leverage,market.initial_margin_rate,market.maintenance_margin_rate,market.liquidation_fee_rate,base_asset.code AS base_code,base_asset.name AS base_name,quote_asset.code AS quote_code,quote_asset.name AS quote_name')
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
            ->field('market.id,market.symbol AS internal_symbol,market.contract_size,market.price_tick,market.quantity_step,market.min_quantity,market.max_quantity,market.min_notional,market.max_notional,market.maker_fee_rate,market.taker_fee_rate,market.max_leverage,market.initial_margin_rate,market.maintenance_margin_rate,market.liquidation_fee_rate,base_asset.code AS base_code,base_asset.name AS base_name,quote_asset.code AS quote_code,quote_asset.name AS quote_name')
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
        $query = Db::table('cex_market_perpetual_contracts')
            ->alias('market')
            ->join('cex_market_price_indices price_index', 'price_index.id = market.price_index_id')
            ->join('cex_asset_assets base_asset', 'base_asset.id = market.base_asset_id')
            ->join('cex_asset_assets quote_asset', 'quote_asset.id = market.quote_asset_id')
            ->where('market.status', 1)
            ->where('price_index.status', 1)
            ->where('base_asset.status', 1)
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
        $slug = strtolower($baseCode . '-' . $quoteCode . '-swap');

        return [
            'id' => (int) $row['id'],
            'mode' => 'swap',
            'internal_symbol' => (string) $row['internal_symbol'],
            'base_code' => $baseCode,
            'base_name' => (string) $row['base_name'],
            'quote_code' => $quoteCode,
            'quote_name' => (string) $row['quote_name'],
            'pair' => $baseCode . '/' . $quoteCode,
            'slug' => $slug,
            'contract_size' => (string) $row['contract_size'],
            'price_tick' => (string) $row['price_tick'],
            'quantity_step' => (string) $row['quantity_step'],
            'min_quantity' => (string) $row['min_quantity'],
            'max_quantity' => $row['max_quantity'] !== null ? (string) $row['max_quantity'] : null,
            'min_notional' => (string) $row['min_notional'],
            'max_notional' => $row['max_notional'] !== null ? (string) $row['max_notional'] : null,
            'maker_fee_rate' => (string) $row['maker_fee_rate'],
            'taker_fee_rate' => (string) $row['taker_fee_rate'],
            'max_leverage' => (string) $row['max_leverage'],
            'initial_margin_rate' => (string) $row['initial_margin_rate'],
            'maintenance_margin_rate' => (string) $row['maintenance_margin_rate'],
            'liquidation_fee_rate' => (string) $row['liquidation_fee_rate'],
            'route' => '/trade-swap/' . $slug,
        ];
    }
}
