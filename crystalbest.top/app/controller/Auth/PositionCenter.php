<?php

namespace app\controller\Auth;

use app\BaseController;
use app\service\Asset\AssetException;
use app\service\Position\PositionDashboardService;
use think\facade\Log;

final class PositionCenter extends BaseController
{
    public function page()
    {
        $auth = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($auth->cookieName(), '');

        try {
            $me = $auth->me($cookie);
            $context = (new PositionDashboardService($this->request))->context();
        } catch (AuthException $exception) {
            return redirect('/login?next=/dashboard/positions');
        } catch (AssetException $exception) {
            Log::warning('Position dashboard unavailable: ' . $exception->getErrorCode() . ' ' . $exception->getMessage());
            return redirect('/dashboard');
        } catch (\Throwable $exception) {
            Log::error(
                'Position dashboard error: ' . get_class($exception)
                . ' ' . $exception->getMessage()
                . ' file=' . $exception->getFile()
                . ' line=' . $exception->getLine()
            );
            return redirect('/dashboard');
        }

        return view('auth/positions', [
            'currentUser' => $me['user'],
            'positionDashboard' => $context,
            'activeGroup' => 'trading',
            'activeItem' => 'positions',
        ]);
    }
}
