<?php

namespace app\controller;

use app\BaseController;
use think\facade\Db;

class Market extends BaseController
{
    private const PAGE_SIZE = 10;

    public function index()
    {
        $initialPage = $this->paginateCatalog('coins', 1, self::PAGE_SIZE, '');

        return view('market/index', [
            // 币种价格表保留 10 条 MySQL 目录作为无行情服务时的基础兜底；
            // 热门、最新上线、市场概览与真实行情由 Site Contract V2 前端聚合接口填充。
            'initialMarketRows' => $initialPage['items'],
            'marketPagination' => array_merge($initialPage['pagination'], [
                'total_pages_display' => max(1, (int) $initialPage['pagination']['total_pages']),
            ]),
        ]);
    }

    public function data()
    {
        $type = strtolower(trim((string) $this->request->get('type', 'coins')));
        if (!in_array($type, ['coins', 'spot', 'swap', 'watch'], true)) {
            $type = 'coins';
        }

        $page = max(1, (int) $this->request->get('page', 1));
        $keyword = trim((string) $this->request->get('keyword', ''));
        $result = $this->paginateCatalog($type, $page, self::PAGE_SIZE, $keyword);

        return json([
            'code' => 0,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    private function paginateCatalog(string $type, int $page, int $pageSize, string $keyword): array
    {
        if ($type === 'watch') {
            return $this->pageResult([], 0, $page, $pageSize);
        }

        if ($type === 'spot') {
            $total = $this->countSpotMarkets($keyword);
            $items = $this->querySpotMarkets(($page - 1) * $pageSize, $pageSize, $keyword);
            return $this->pageResult($items, $total, $page, $pageSize);
        }

        if ($type === 'swap') {
            $total = $this->countSwapMarkets($keyword);
            $items = $this->querySwapMarkets(($page - 1) * $pageSize, $pageSize, $keyword);
            return $this->pageResult($items, $total, $page, $pageSize);
        }

        // “币种”当前等于全部现货 + 全部永续，排序保持现货在前、永续在后。
        $spotTotal = $this->countSpotMarkets($keyword);
        $swapTotal = $this->countSwapMarkets($keyword);
        $total = $spotTotal + $swapTotal;
        $offset = ($page - 1) * $pageSize;
        $items = [];

        if ($offset < $spotTotal) {
            $spotLimit = min($pageSize, $spotTotal - $offset);
            $items = $this->querySpotMarkets($offset, $spotLimit, $keyword);
            $remaining = $pageSize - count($items);
            if ($remaining > 0) {
                $items = array_merge($items, $this->querySwapMarkets(0, $remaining, $keyword));
            }
        } else {
            $items = $this->querySwapMarkets($offset - $spotTotal, $pageSize, $keyword);
        }

        return $this->pageResult($items, $total, $page, $pageSize);
    }

    private function pageResult(array $items, int $total, int $page, int $pageSize): array
    {
        $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 0;

        return [
            'items' => $items,
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

    private function countSpotMarkets(string $keyword): int
    {
        return (int) $this->spotQuery($keyword)->count();
    }

    private function countSwapMarkets(string $keyword): int
    {
        return (int) $this->swapQuery($keyword)->count();
    }

    private function querySpotMarkets(int $offset, int $limit, string $keyword): array
    {
        $query = $this->spotQuery($keyword)
            ->field('market.id,market.symbol AS internal_symbol,base_asset.code AS base_code,base_asset.name AS base_name,quote_asset.code AS quote_code,quote_asset.name AS quote_name')
            ->order('market.id', 'asc');

        if ($limit > 0) {
            $query->limit($offset, $limit);
        }

        $rows = $query->select()->toArray();
        foreach ($rows as &$row) {
            $row = $this->formatMarket($row, 'spot');
        }
        unset($row);

        return $rows;
    }

    private function querySwapMarkets(int $offset, int $limit, string $keyword): array
    {
        $query = $this->swapQuery($keyword)
            ->field('market.id,market.symbol AS internal_symbol,base_asset.code AS base_code,base_asset.name AS base_name,quote_asset.code AS quote_code,quote_asset.name AS quote_name')
            ->order('market.id', 'asc');

        if ($limit > 0) {
            $query->limit($offset, $limit);
        }

        $rows = $query->select()->toArray();
        foreach ($rows as &$row) {
            $row = $this->formatMarket($row, 'swap');
        }
        unset($row);

        return $rows;
    }

    private function spotQuery(string $keyword)
    {
        $query = Db::table('cex_market_spot_symbols')
            ->alias('market')
            ->join('cex_asset_assets base_asset', 'base_asset.id = market.base_asset_id')
            ->join('cex_asset_assets quote_asset', 'quote_asset.id = market.quote_asset_id');

        return $this->applyKeyword($query, $keyword);
    }

    private function swapQuery(string $keyword)
    {
        $query = Db::table('cex_market_perpetual_contracts')
            ->alias('market')
            ->join('cex_asset_assets base_asset', 'base_asset.id = market.base_asset_id')
            ->join('cex_asset_assets quote_asset', 'quote_asset.id = market.quote_asset_id');

        return $this->applyKeyword($query, $keyword);
    }

    private function applyKeyword($query, string $keyword)
    {
        if ($keyword === '') {
            return $query;
        }

        $like = '%' . $keyword . '%';
        return $query->whereRaw(
            '(base_asset.code LIKE :keyword_code OR base_asset.name LIKE :keyword_name OR quote_asset.code LIKE :keyword_quote OR market.symbol LIKE :keyword_symbol)',
            [
                'keyword_code' => $like,
                'keyword_name' => $like,
                'keyword_quote' => $like,
                'keyword_symbol' => $like,
            ]
        );
    }

    private function formatMarket(array $row, string $mode): array
    {
        $baseCode = strtoupper((string) $row['base_code']);
        $quoteCode = strtoupper((string) $row['quote_code']);
        $pair = $baseCode . '/' . $quoteCode;
        $slug = strtolower($baseCode . '-' . $quoteCode);

        if ($mode === 'swap') {
            $slug .= '-swap';
        }

        return [
            'id' => (int) $row['id'],
            'mode' => $mode,
            'realtime_type' => $mode === 'swap' ? 'perpetual' : 'spot',
            'internal_symbol' => (string) $row['internal_symbol'],
            'base_code' => $baseCode,
            'base_name' => (string) $row['base_name'],
            'quote_code' => $quoteCode,
            'quote_name' => (string) $row['quote_name'],
            'pair' => $pair,
            'route' => ($mode === 'swap' ? '/trade-swap/' : '/trade-spot/') . $slug,
            'market_label' => $mode === 'swap' ? 'USDT 本位永续合约' : '现货交易',
            'table_primary' => $mode === 'swap' ? $pair : $baseCode,
            'table_secondary' => $mode === 'swap' ? '永续合约' : (string) $row['base_name'],
        ];
    }
}
