<?php
namespace app\controller;

use app\BaseController;
use app\service\MarketCatalogService;
use think\facade\Log;

class Trade extends BaseController
{
    public function swap(string $symbol = '')
    {
        return $this->renderTrade('swap', $symbol);
    }

    public function spot(string $symbol = '')
    {
        return $this->renderTrade('spot', $symbol);
    }

    private function renderTrade(string $mode, string $symbol)
    {
        try {
            $catalog = new MarketCatalogService();
            // 按需求展示全部配置记录，不按 status 筛选。
            $marketList = $mode === 'swap'
                ? $catalog->getPerpetualMarkets()
                : $catalog->getSpotMarkets();
        } catch (\Throwable $exception) {
            Log::error('加载交易市场失败：' . $exception->getMessage());
            abort(500, config('app.app_debug') ? $exception->getMessage() : '市场配置暂时无法加载');
        }

        $currentMarket = $catalog->resolveCurrentMarket($marketList, $symbol);
        if (!$currentMarket) {
            abort(404, '交易对不存在');
        }

        $isSwap = $mode === 'swap';
        $marketListLabel = $isSwap ? '合约市场列表' : '现货市场列表';
        $pageModeName = $isSwap ? 'USDT 本位永续合约' : '现货交易';

        return view('trade/index', [
            'tradeMode' => $mode,
            'marketList' => $marketList,
            'currentMarket' => $currentMarket,
            'marketListLabel' => $marketListLabel,
            'searchLabel' => $isSwap ? '查询合约' : '查询现货交易对',
            'marketEmptyText' => $isSwap ? '没有找到相关合约' : '没有找到相关现货交易对',
            'tradeHeading' => $currentMarket['pair'] . ' ' . $pageModeName . '交易',
            'pageTitle' => $currentMarket['pair'] . ' ' . $pageModeName . ' — Aster Exchange',
            'pageDescription' => 'Aster Exchange ' . $currentMarket['pair'] . ' ' . $pageModeName . '交易页面。',
        ]);
    }
}
