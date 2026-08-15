<?php

namespace app\controller\Auth;

use app\BaseController;
use app\service\Asset\AssetException;
use app\service\History\TradeHistoryService;
use think\facade\Log;

final class TradeHistoryCenter extends BaseController
{
    public function page()
    {
        $auth = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($auth->cookieName(), '');

        try {
            $me = $auth->me($cookie);
            $context = (new TradeHistoryService($this->request))->context();
        } catch (AuthException $exception) {
            return redirect('/login?next=/dashboard/trade-history');
        } catch (AssetException $exception) {
            Log::warning('Trade history unavailable: ' . $exception->getErrorCode() . ' ' . $exception->getMessage());
            return redirect('/dashboard');
        } catch (\Throwable $exception) {
            Log::error('Trade history error: ' . get_class($exception) . ' ' . $exception->getMessage());
            return redirect('/dashboard');
        }

        return view('auth/trade_history', [
            'currentUser' => $me['user'],
            'tradeHistory' => $context,
            'activeGroup' => 'trading',
            'activeItem' => 'trade-history',
        ]);
    }
}
