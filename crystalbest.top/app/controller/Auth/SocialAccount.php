<?php

namespace app\controller\Auth;

use app\BaseController;
use app\controller\Auth\Social\ProviderConfig;
use app\controller\Auth\Social\SocialAuthService;
use think\facade\Log;

class SocialAccount extends BaseController
{
    public function google()
    {
        return $this->start('google');
    }

    public function googleCallback()
    {
        return $this->callback('google');
    }

    public function googleLink()
    {
        return $this->linkStart('google');
    }

    public function microsoft()
    {
        return $this->start('microsoft');
    }

    public function microsoftCallback()
    {
        return $this->callback('microsoft');
    }

    public function microsoftLink()
    {
        return $this->linkStart('microsoft');
    }

    public function providers()
    {
        return json([
            'code' => 0,
            'message' => 'success',
            'data' => ProviderConfig::publicStatus(),
        ]);
    }

    private function start(string $provider)
    {
        try {
            $auth = new AuthService($this->request);
            if ($this->isAuthenticated($auth)) {
                return redirect('/dashboard');
            }

            $service = new SocialAuthService($this->request);
            $next = (string) $this->request->get('next', '/dashboard');
            return redirect($service->authorizationUrl($provider, $next));
        } catch (AuthException $exception) {
            return redirect('/login?social_error=' . rawurlencode(strtolower($exception->getErrorCode())));
        } catch (\Throwable $exception) {
            Log::error(
                'Social auth start failed provider=' . $provider
                . ' exception=' . get_class($exception)
                . ' message=' . $exception->getMessage()
                . ' file=' . $exception->getFile()
                . ' line=' . $exception->getLine()
            );
            return redirect('/login?social_error=social_unavailable');
        }
    }

    private function linkStart(string $provider)
    {
        try {
            $token = trim((string) $this->request->get('token', ''));
            $linkContext = (new SecurityService($this->request))->claimSocialLinkIntent($provider, $token);
            $service = new SocialAuthService($this->request);
            return redirect($service->authorizationUrlForLink($provider, $linkContext, '/dashboard/security'));
        } catch (AuthException $exception) {
            if ($exception->getHttpStatus() === 401) {
                return redirect('/login?next=/dashboard/security');
            }
            return redirect('/dashboard/security?social_error=' . rawurlencode(strtolower($exception->getErrorCode())));
        } catch (\Throwable $exception) {
            Log::error(
                'Social link start failed provider=' . $provider
                . ' exception=' . get_class($exception)
                . ' message=' . $exception->getMessage()
                . ' file=' . $exception->getFile()
                . ' line=' . $exception->getLine()
            );
            return redirect('/dashboard/security?social_error=social_unavailable');
        }
    }

    private function callback(string $provider)
    {
        $mode = 'login';
        try {
            $service = new SocialAuthService($this->request);
            $state = trim((string) $this->request->get('state', ''));
            $mode = $service->callbackMode($state);

            $providerError = trim((string) $this->request->get('error', ''));
            if ($providerError !== '') {
                $service->discardState($state);
                return $mode === 'link'
                    ? redirect('/dashboard/security?social_error=cancelled')
                    : redirect('/login?social_error=cancelled');
            }

            $result = $service->callback(
                $provider,
                trim((string) $this->request->get('code', '')),
                $state
            );

            if (($result['mode'] ?? 'login') === 'link') {
                Log::info('Social link callback success provider=' . $provider);
                return redirect((string) ($result['next'] ?? '/dashboard/security'));
            }

            $cookieValue = $result['session']['cookie_value'];
            $deviceCookieValue = (string) ($result['session']['device_cookie_value'] ?? '');
            $next = (string) ($result['next'] ?? '/dashboard');
            $remember = (bool) ($result['remember'] ?? true);

            Log::info(
                'Social auth callback success provider=' . $provider
                . ' registered=' . ((bool) ($result['registered'] ?? false) ? '1' : '0')
                . ' uid=' . (string) ($result['user']['uid'] ?? '')
            );

            $auth = new AuthService($this->request);
            $response = redirect($next)->cookie(
                $auth->cookieName(),
                $cookieValue,
                $auth->cookieOptions($remember)
            );
            if ($deviceCookieValue !== '') {
                $response->cookie($auth->deviceCookieName(), $deviceCookieValue, $auth->deviceCookieOptions());
            }
            return $response;
        } catch (AuthException $exception) {
            Log::warning(
                'Social callback rejected provider=' . $provider
                . ' mode=' . $mode
                . ' code=' . $exception->getErrorCode()
                . ' message=' . $exception->getMessage()
            );
            $target = $mode === 'link' ? '/dashboard/security?social_error=' : '/login?social_error=';
            return redirect($target . rawurlencode(strtolower($exception->getErrorCode())));
        } catch (\Throwable $exception) {
            Log::error(
                'Social callback failed provider=' . $provider
                . ' mode=' . $mode
                . ' exception=' . get_class($exception)
                . ' message=' . $exception->getMessage()
                . ' file=' . $exception->getFile()
                . ' line=' . $exception->getLine()
            );
            return $mode === 'link'
                ? redirect('/dashboard/security?social_error=social_unavailable')
                : redirect('/login?social_error=social_unavailable');
        }
    }

    private function isAuthenticated(AuthService $service): bool
    {
        $cookie = (string) $this->request->cookie($service->cookieName(), '');
        if ($cookie === '') {
            return false;
        }
        try {
            $service->me($cookie);
            return true;
        } catch (AuthException $exception) {
            return false;
        }
    }
}
