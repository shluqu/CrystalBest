<?php

namespace app\controller\Auth;

use app\BaseController;
use app\service\Asset\AssetException;
use app\service\Perp\PerpetualOrderDashboardService;
use think\facade\Log;

final class PerpetualOrderCenter extends BaseController
{
    public function page()
    {
        $auth = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($auth->cookieName(), '');
        try {
            $me = $auth->me($cookie);
            $context = (new PerpetualOrderDashboardService($this->request))->context();
        } catch (AuthException $exception) {
            return redirect('/login?next=/dashboard/perpetual-orders');
        } catch (AssetException $exception) {
            Log::warning('Perpetual order dashboard unavailable: ' . $exception->getErrorCode() . ' ' . $exception->getMessage());
            return redirect('/dashboard');
        } catch (\Throwable $exception) {
            Log::error('Perpetual order dashboard error: ' . get_class($exception) . ' ' . $exception->getMessage());
            return redirect('/dashboard');
        }

        return view('auth/perpetual_orders', [
            'currentUser' => $me['user'],
            'perpOrders' => $context,
            'activeGroup' => 'trading',
            'activeItem' => 'perpetual-orders',
        ]);
    }
}
