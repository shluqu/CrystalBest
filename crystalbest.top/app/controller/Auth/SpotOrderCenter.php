<?php

namespace app\controller\Auth;

use app\BaseController;
use app\service\Asset\AssetException;
use app\service\Spot\SpotOrderDashboardService;
use think\facade\Log;

final class SpotOrderCenter extends BaseController
{
    public function page()
    {
        $auth = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($auth->cookieName(), '');
        try {
            $me = $auth->me($cookie);
            $context = (new SpotOrderDashboardService($this->request))->context();
        } catch (AuthException $exception) {
            return redirect('/login?next=/dashboard/spot-orders');
        } catch (AssetException $exception) {
            Log::warning('Spot order dashboard unavailable: ' . $exception->getErrorCode() . ' ' . $exception->getMessage());
            return redirect('/dashboard');
        } catch (\Throwable $exception) {
            Log::error('Spot order dashboard error: ' . get_class($exception) . ' ' . $exception->getMessage());
            return redirect('/dashboard');
        }

        return view('auth/spot_orders', [
            'currentUser' => $me['user'],
            'spotOrders' => $context,
            'activeGroup' => 'trading',
            'activeItem' => 'spot-orders',
        ]);
    }
}
