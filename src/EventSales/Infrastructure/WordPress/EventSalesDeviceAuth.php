<?php

declare(strict_types=1);

namespace Rishe\EventSales\Infrastructure\WordPress;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

final class EventSalesDeviceAuth
{
    private const COOKIE = 'rishe_event_device';
    private const HEADER_SERVER_KEY = 'HTTP_X_RISHE_EVENT_TOKEN';
    private const META = 'rishe_event_device_tokens';
    private const TTL = 315360000; // 10 years.
    private const MAX_DEVICES = 12;

    public function register(): void
    {
        add_filter('determine_current_user', [$this, 'authenticate'], 30);
        add_filter('rest_authentication_errors', [$this, 'authenticateRest'], 5);
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function authenticate($userId): int
    {
        $resolved = (int) $userId;
        if ($resolved > 0) {
            return $resolved;
        }

        $token = $this->requestToken();
        if ($token === '') {
            return 0;
        }

        return $this->userIdFromToken($token);
    }

    public function authenticateRest($result)
    {
        if ($result !== null) {
            return $result;
        }

        $token = $this->headerToken();
        if ($token === '') {
            return null;
        }

        $userId = $this->userIdFromToken($token);
        if ($userId <= 0) {
            return new WP_Error(
                'rishe_event_device_invalid',
                __('نشست ایونت معتبر نیست. دوباره وارد شوید.', 'rishe'),
                ['status' => 401]
            );
        }

        wp_set_current_user($userId);
        $this->touch($userId, $token);

        // A non-null success result prevents WordPress cookie/nonce authentication
        // from overriding the explicitly authenticated native device token.
        return true;
    }

    public function registerRoutes(): void
    {
        register_rest_route('rishe/v1', '/event-sales/device-login', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'deviceLogin'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('rishe/v1', '/event-sales/device-session', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'deviceSession'],
                'permission_callback' => static fn (): bool => current_user_can('rishe_sell_event')
                    || current_user_can('rishe_manage_sales')
                    || current_user_can('manage_rishe'),
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'revokeDeviceSession'],
                'permission_callback' => static fn (): bool => is_user_logged_in(),
            ],
        ]);
    }

    public function deviceLogin(WP_REST_Request $request): WP_REST_Response
    {
        $username = sanitize_text_field((string) $request->get_param('username'));
        $password = (string) $request->get_param('password');
        if ($username === '' || $password === '') {
            return new WP_REST_Response(['message' => 'نام کاربری و رمز عبور را وارد کنید.'], 400);
        }

        $user = wp_authenticate($username, $password);
        if (!$user instanceof WP_User) {
            return new WP_REST_Response(['message' => 'نام کاربری یا رمز عبور صحیح نیست.'], 401);
        }
        if (!$this->canSell($user)) {
            return new WP_REST_Response(['message' => 'این حساب اجازه فروش ایونت ندارد.'], 403);
        }

        $token = $this->issueToken((int) $user->ID);

        return new WP_REST_Response([
            'active' => true,
            'device_token' => $token,
            'user' => [
                'id' => (int) $user->ID,
                'name' => (string) $user->display_name,
            ],
            'expires_at' => gmdate('c', time() + self::TTL),
        ], 201);
    }

    public function deviceSession(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);
        $userId = get_current_user_id();
        $current = $this->requestToken();

        if ($current !== '' && $this->userIdFromToken($current) === $userId) {
            $this->touch($userId, $current);
            $this->setCookie($current);

            return new WP_REST_Response([
                'active' => true,
                'reused' => true,
                'user_id' => $userId,
                'expires_at' => gmdate('c', time() + self::TTL),
            ]);
        }

        $token = $this->issueToken($userId);
        $this->setCookie($token);

        return new WP_REST_Response([
            'active' => true,
            'reused' => false,
            'user_id' => $userId,
            'expires_at' => gmdate('c', time() + self::TTL),
        ], 201);
    }

    public function revokeDeviceSession(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);
        $userId = get_current_user_id();
        $token = $this->requestToken();
        if ($token !== '') {
            $parts = $this->tokenParts($token);
            if ($parts !== null && $parts['user_id'] === $userId) {
                $tokens = $this->tokensFor($userId);
                unset($tokens[hash('sha256', $parts['secret'])]);
                update_user_meta($userId, self::META, $tokens);
            }
        }

        $this->clearCookie();

        return new WP_REST_Response(['active' => false]);
    }

    private function canSell(WP_User $user): bool
    {
        return user_can($user, 'rishe_sell_event')
            || user_can($user, 'rishe_manage_sales')
            || user_can($user, 'manage_rishe');
    }

    private function issueToken(int $userId): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = $userId . '.' . $secret;
        $hash = hash('sha256', $secret);
        $tokens = $this->tokensFor($userId);
        $now = time();
        $tokens[$hash] = [
            'created_at' => $now,
            'last_seen_at' => $now,
        ];

        if (count($tokens) > self::MAX_DEVICES) {
            uasort($tokens, static fn (array $a, array $b): int => ((int) ($b['last_seen_at'] ?? 0)) <=> ((int) ($a['last_seen_at'] ?? 0)));
            $tokens = array_slice($tokens, 0, self::MAX_DEVICES, true);
        }

        update_user_meta($userId, self::META, $tokens);

        return $token;
    }

    private function userIdFromToken(string $token): int
    {
        $parts = $this->tokenParts($token);
        if ($parts === null) {
            return 0;
        }

        $tokens = $this->tokensFor($parts['user_id']);
        $hash = hash('sha256', $parts['secret']);
        if (!isset($tokens[$hash])) {
            return 0;
        }

        return $parts['user_id'];
    }

    /** @return array{user_id:int,secret:string}|null */
    private function tokenParts(string $token): ?array
    {
        if (!preg_match('/^(\d{1,10})\.([A-Za-z0-9_-]{43})$/', trim($token), $matches)) {
            return null;
        }

        $userId = (int) $matches[1];
        if ($userId <= 0 || get_user_by('id', $userId) === false) {
            return null;
        }

        return ['user_id' => $userId, 'secret' => (string) $matches[2]];
    }

    /** @return array<string,array<string,int>> */
    private function tokensFor(int $userId): array
    {
        $tokens = get_user_meta($userId, self::META, true);

        return is_array($tokens) ? $tokens : [];
    }

    private function touch(int $userId, string $token): void
    {
        $parts = $this->tokenParts($token);
        if ($parts === null) {
            return;
        }
        $tokens = $this->tokensFor($userId);
        $hash = hash('sha256', $parts['secret']);
        if (!isset($tokens[$hash])) {
            return;
        }
        $tokens[$hash]['last_seen_at'] = time();
        update_user_meta($userId, self::META, $tokens);
    }

    private function requestToken(): string
    {
        $header = $this->headerToken();
        if ($header !== '') {
            return $header;
        }

        return $this->cookieToken();
    }

    private function headerToken(): string
    {
        return isset($_SERVER[self::HEADER_SERVER_KEY])
            ? sanitize_text_field(wp_unslash((string) $_SERVER[self::HEADER_SERVER_KEY]))
            : '';
    }

    private function cookieToken(): string
    {
        return isset($_COOKIE[self::COOKIE])
            ? sanitize_text_field(wp_unslash((string) $_COOKIE[self::COOKIE]))
            : '';
    }

    private function setCookie(string $token): void
    {
        setcookie(self::COOKIE, $token, [
            'expires' => time() + self::TTL,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $token;
    }

    private function clearCookie(): void
    {
        setcookie(self::COOKIE, '', [
            'expires' => time() - HOUR_IN_SECONDS,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::COOKIE]);
    }
}
