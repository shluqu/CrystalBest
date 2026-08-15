<?php

namespace app\controller\Auth;

use app\BaseController;
use app\service\Market\MarketFavoriteService;
use think\facade\Log;

final class FavoriteCenter extends BaseController
{
    public function page()
    {
        $auth = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($auth->cookieName(), '');

        try {
            $me = $auth->me($cookie);
            $session = $auth->authenticatedSession($cookie, false);
            $favorites = (new MarketFavoriteService())->listForUser((int) $session['user_id'], 'all', '', 1, 200);
        } catch (AuthException $exception) {
            return redirect('/login?next=/dashboard/favorites');
        } catch (\Throwable $exception) {
            Log::error('Favorite dashboard error: ' . get_class($exception) . ' ' . $exception->getMessage());
            return redirect('/dashboard');
        }

        $spotCount = 0;
        $perpetualCount = 0;
        foreach ($favorites['items'] as $item) {
            if (($item['market_type'] ?? '') === 'spot') {
                $spotCount++;
            } elseif (($item['market_type'] ?? '') === 'perpetual') {
                $perpetualCount++;
            }
        }

        return view('auth/favorites', [
            'currentUser' => $me['user'],
            'favorites' => $favorites['items'],
            'favoriteSummary' => [
                'total' => count($favorites['items']),
                'spot' => $spotCount,
                'perpetual' => $perpetualCount,
            ],
            'activeGroup' => 'trading',
            'activeItem' => 'favorites',
        ]);
    }
}
