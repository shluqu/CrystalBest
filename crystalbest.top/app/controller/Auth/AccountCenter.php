<?php

namespace app\controller\Auth;

use app\BaseController;

class AccountCenter extends BaseController
{
    public function page(string $section)
    {
        $config = $this->pages();
        if (!isset($config[$section])) {
            return redirect('/dashboard');
        }

        $auth = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($auth->cookieName(), '');
        try {
            $me = $auth->me($cookie);
        } catch (AuthException $exception) {
            return redirect('/login?next=' . rawurlencode('/dashboard/' . $section));
        }

        $page = $config[$section];
        return view('auth/account_page', [
            'currentUser' => $me['user'],
            'activeGroup' => $page['group'],
            'activeItem' => $section,
            'pageTitle' => $page['title'],
            'pageKicker' => $page['kicker'],
            'pageDescription' => $page['description'],
            'pageIcon' => $page['icon'],
            'pageStatus' => $page['status'],
            'pageItems' => $page['items'],
        ]);
    }

    private function pages(): array
    {
        return [
            'notifications' => [
                'group' => 'overview', 'title' => '消息通知', 'kicker' => 'NOTIFICATIONS', 'icon' => 'bi-bell', 'status' => '入口已建立',
                'description' => '统一承载系统通知、安全提醒、交易通知以及运营公告。',
                'items' => ['安全通知', '交易通知', '系统公告', '消息偏好'],
            ],
            'spot-orders' => [
                'group' => 'trading', 'title' => '现货订单', 'kicker' => 'SPOT ORDERS', 'icon' => 'bi-graph-up', 'status' => '已接入真实订单数据',
                'description' => '展示当前现货委托、历史委托与成交结果。',
                'items' => ['当前委托', '历史委托', '成交记录', '订单筛选'],
            ],
            'perpetual-orders' => [
                'group' => 'trading', 'title' => '合约订单', 'kicker' => 'PERPETUAL ORDERS', 'icon' => 'bi-lightning-charge', 'status' => '等待永续合约模块接入',
                'description' => '集中查看永续合约当前委托、条件单、历史委托和资金费相关记录。',
                'items' => ['当前委托', '条件委托', '历史订单', '资金费记录'],
            ],
            'trade-history' => [
                'group' => 'trading', 'title' => '成交历史', 'kicker' => 'TRADE HISTORY', 'icon' => 'bi-clock-history', 'status' => '等待撮合成交数据接入',
                'description' => '统一展示现货与永续合约的用户级成交记录。',
                'items' => ['现货成交', '合约成交', '手续费', '时间与市场筛选'],
            ],
            'positions' => [
                'group' => 'trading', 'title' => '持仓管理', 'kicker' => 'POSITIONS', 'icon' => 'bi-collection', 'status' => '等待永续仓位模块接入',
                'description' => '后续显示全仓永续持仓、保证金、未实现盈亏以及止盈止损状态。',
                'items' => ['当前持仓', '保证金与杠杆', '未实现盈亏', '止盈止损'],
            ],
        ];
    }
}
