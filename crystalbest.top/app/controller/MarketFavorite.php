<?php

namespace app\controller;

use app\BaseController;
use app\controller\Auth\AuthException;
use app\controller\Auth\AuthService;
use app\service\Market\MarketFavoriteService;

final class MarketFavorite extends BaseController
{
    public function index()
    {
        $userId = $this->optionalUserId();
        $page = max(1, (int) $this->request->get('page', 1));
        $pageSize = max(1, min(200, (int) $this->request->get('page_size', 100)));
        $type = trim((string) $this->request->get('type', 'all'));
        $keyword = trim((string) $this->request->get('keyword', ''));

        if ($userId === null) {
            return json([
                'code' => 0,
                'message' => 'success',
                'data' => [
                    'authenticated' => false,
                    'items' => [],
                    'keys' => [],
                    'pagination' => [
                        'page' => $page,
                        'page_size' => $pageSize,
                        'total' => 0,
                        'total_pages' => 0,
                        'has_more' => false,
                        'next_page' => null,
                    ],
                ],
            ]);
        }

        $result = (new MarketFavoriteService())->listForUser($userId, $type, $keyword, $page, $pageSize);
        $keys = [];
        foreach ($result['items'] as $item) {
            $keys[] = (string) $item['market_type'] . ':' . strtoupper((string) $item['symbol']);
        }

        return json([
            'code' => 0,
            'message' => 'success',
            'data' => array_merge($result, [
                'authenticated' => true,
                'keys' => $keys,
            ]),
        ]);
    }

    public function set()
    {
        $userId = $this->requiredUserId();
        if ($userId === null) {
            return json([
                'code' => 40101,
                'message' => '请先登录后使用自选市场',
                'data' => ['authenticated' => false],
            ], 401);
        }

        $payload = $this->payload();
        $type = trim((string) ($payload['market_type'] ?? ''));
        $symbol = trim((string) ($payload['symbol'] ?? ''));
        $active = filter_var($payload['active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
            $active = true;
        }

        try {
            $result = (new MarketFavoriteService())->setFavorite($userId, $type, $symbol, $active);
        } catch (\InvalidArgumentException $exception) {
            return json([
                'code' => 42201,
                'message' => $exception->getMessage(),
                'data' => null,
            ], 422);
        }

        return json([
            'code' => 0,
            'message' => $result['active'] ? '已加入自选' : '已从自选移除',
            'data' => array_merge($result, ['authenticated' => true]),
        ]);
    }

    private function optionalUserId(): ?int
    {
        $auth = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($auth->cookieName(), '');
        if ($cookie === '') {
            return null;
        }
        try {
            $session = $auth->authenticatedSession($cookie, false);
            return (int) ($session['user_id'] ?? 0) ?: null;
        } catch (AuthException $exception) {
            return null;
        }
    }

    private function requiredUserId(): ?int
    {
        return $this->optionalUserId();
    }

    private function payload(): array
    {
        $contentType = strtolower((string) $this->request->header('content-type', ''));
        if (strpos($contentType, 'application/json') !== false) {
            $raw = (string) $this->request->getInput();
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return $this->request->post();
    }
}
