<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Spacefast_Frames_API
{
    private const OPTION = 'spacefast_frames_connection';
    private const DEFAULT_API = 'https://api.spacefast.com';
    private const SCOPES = 'teams:read spaces:read spaces:write offline_access';

    public static function connection(): array
    {
        $stored = get_option(self::OPTION, array());
        return is_array($stored) ? $stored : array();
    }

    public static function api_base(): string
    {
        $base = (string) (self::connection()['api_base'] ?? self::DEFAULT_API);
        return untrailingslashit(esc_url_raw($base));
    }

    public static function resource(): string
    {
        return self::api_base() . '/v1';
    }

    public static function connected(): bool
    {
        $connection = self::connection();
        return !empty($connection['client_id']) && !empty($connection['access_token']);
    }

    public static function save_api_base(string $value): void
    {
        $url = untrailingslashit(esc_url_raw(trim($value)));
        $parts = wp_parse_url($url);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $scheme = is_array($parts) ? (string) ($parts['scheme'] ?? '') : '';
        $local = in_array($host, array('localhost', '127.0.0.1', '::1'), true);
        if ($host === '' || ($scheme !== 'https' && !($local && $scheme === 'http'))) {
            throw new InvalidArgumentException(__('Use an HTTPS Spacefast API URL. HTTP is allowed only on loopback.', 'spacefast-frames'));
        }
        $current = self::connection();
        if (($current['api_base'] ?? self::DEFAULT_API) !== $url) {
            self::disconnect();
            $current = array();
        }
        $current['api_base'] = $url;
        update_option(self::OPTION, $current, false);
    }

    public static function start_oauth(): string
    {
        $redirect = self::redirect_uri();
        $registration = self::remote_json(
            'POST',
            self::api_base() . '/v1/auth/oauth2/register',
            array(
                'client_name' => sprintf(__('Spacefast for %s', 'spacefast-frames'), wp_parse_url(home_url('/'), PHP_URL_HOST)),
                'client_uri' => 'https://github.com/spacefast/frames',
                'redirect_uris' => array($redirect),
                'token_endpoint_auth_method' => 'client_secret_post',
                'grant_types' => array('authorization_code', 'refresh_token'),
                'response_types' => array('code'),
                'type' => 'web',
                'scope' => self::SCOPES,
                'resources' => array(self::resource()),
            )
        );
        $client_id = self::required_string($registration, 'client_id');
        $client_secret = self::required_string($registration, 'client_secret');
        $state = self::random_token(32);
        $verifier = self::random_token(48);
        $challenge = self::base64url(hash('sha256', $verifier, true));
        set_transient(
            'spacefast_frames_oauth_' . hash('sha256', $state),
            array(
                'client_id' => $client_id,
                'client_secret' => self::seal($client_secret),
                'verifier' => self::seal($verifier),
                'api_base' => self::api_base(),
            ),
            10 * MINUTE_IN_SECONDS
        );
        return add_query_arg(
            array(
                'client_id' => $client_id,
                'redirect_uri' => $redirect,
                'response_type' => 'code',
                'scope' => self::SCOPES,
                'resource' => self::resource(),
                'state' => $state,
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ),
            self::api_base() . '/v1/auth/oauth2/authorize'
        );
    }

    public static function finish_oauth(string $code, string $state): void
    {
        $key = 'spacefast_frames_oauth_' . hash('sha256', $state);
        $pending = get_transient($key);
        delete_transient($key);
        if (!is_array($pending)) {
            throw new RuntimeException(__('That Spacefast connection has expired. Start again.', 'spacefast-frames'));
        }
        $api_base = untrailingslashit((string) ($pending['api_base'] ?? ''));
        $client_id = (string) ($pending['client_id'] ?? '');
        $client_secret = self::unseal((string) ($pending['client_secret'] ?? ''));
        $verifier = self::unseal((string) ($pending['verifier'] ?? ''));
        $tokens = self::token_request(
            $api_base,
            array(
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => self::redirect_uri(),
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'code_verifier' => $verifier,
                'resource' => $api_base . '/v1',
            )
        );
        self::store_tokens(
            array(
                'api_base' => $api_base,
                'client_id' => $client_id,
                'client_secret' => self::seal($client_secret),
            ),
            $tokens
        );
    }

    public static function disconnect(): void
    {
        delete_option(self::OPTION);
    }

    public static function request(string $method, string $path, ?array $body = null, bool $retry = true): array
    {
        $connection = self::fresh_connection();
        $token = self::unseal((string) ($connection['access_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException(__('Connect WordPress to Spacefast first.', 'spacefast-frames'));
        }
        $args = array(
            'method' => strtoupper($method),
            'timeout' => 20,
            'redirection' => 0,
            'headers' => array(
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'X-Spacefast-Client' => 'wordpress-frames/' . SPACEFAST_FRAMES_VERSION,
            ),
        );
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request(self::api_base() . $path, $args);
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 401 && $retry && !empty($connection['refresh_token'])) {
            self::refresh(true);
            return self::request($method, $path, $body, false);
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded)
                ? (string) ($decoded['detail'] ?? $decoded['message'] ?? $decoded['error_description'] ?? '')
                : '';
            throw new RuntimeException($message !== '' ? $message : sprintf(__('Spacefast returned HTTP %d.', 'spacefast-frames'), $status));
        }
        if (!is_array($decoded)) {
            throw new RuntimeException(__('Spacefast returned an invalid response.', 'spacefast-frames'));
        }
        return $decoded;
    }

    private static function fresh_connection(): array
    {
        $connection = self::connection();
        $expires = (int) ($connection['expires_at'] ?? 0);
        if ($expires > 0 && $expires <= time() + 60 && !empty($connection['refresh_token'])) {
            return self::refresh(false);
        }
        return $connection;
    }

    private static function refresh(bool $force): array
    {
        $connection = self::connection();
        if (!$force && (int) ($connection['expires_at'] ?? 0) > time() + 60) {
            return $connection;
        }
        $refresh = self::unseal((string) ($connection['refresh_token'] ?? ''));
        $secret = self::unseal((string) ($connection['client_secret'] ?? ''));
        if ($refresh === '' || $secret === '') {
            throw new RuntimeException(__('Reconnect Spacefast to continue.', 'spacefast-frames'));
        }
        $tokens = self::token_request(
            self::api_base(),
            array(
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh,
                'client_id' => (string) ($connection['client_id'] ?? ''),
                'client_secret' => $secret,
                'resource' => self::resource(),
            )
        );
        self::store_tokens($connection, $tokens);
        return self::connection();
    }

    private static function store_tokens(array $connection, array $tokens): void
    {
        $access = self::required_string($tokens, 'access_token');
        $connection['access_token'] = self::seal($access);
        if (!empty($tokens['refresh_token']) && is_string($tokens['refresh_token'])) {
            $connection['refresh_token'] = self::seal($tokens['refresh_token']);
        }
        $connection['expires_at'] = time() + max(60, (int) ($tokens['expires_in'] ?? 900));
        $connection['connected_at'] = time();
        update_option(self::OPTION, $connection, false);
    }

    private static function token_request(string $api_base, array $fields): array
    {
        $response = wp_remote_post(
            $api_base . '/v1/auth/oauth2/token',
            array(
                'timeout' => 20,
                'redirection' => 0,
                'headers' => array('Accept' => 'application/json'),
                'body' => $fields,
            )
        );
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded)
                ? (string) ($decoded['error_description'] ?? $decoded['message'] ?? '')
                : '';
            throw new RuntimeException($message !== '' ? $message : __('Spacefast could not finish the connection.', 'spacefast-frames'));
        }
        return $decoded;
    }

    private static function remote_json(string $method, string $url, array $body): array
    {
        $response = wp_remote_request(
            $url,
            array(
                'method' => $method,
                'timeout' => 20,
                'redirection' => 0,
                'headers' => array('Accept' => 'application/json', 'Content-Type' => 'application/json'),
                'body' => wp_json_encode($body),
            )
        );
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded)
                ? (string) ($decoded['error_description'] ?? $decoded['message'] ?? '')
                : '';
            throw new RuntimeException($message !== '' ? $message : __('Spacefast could not register this WordPress site.', 'spacefast-frames'));
        }
        return $decoded;
    }

    private static function redirect_uri(): string
    {
        return admin_url('options-general.php?page=spacefast-frames&spacefast_oauth=callback');
    }

    private static function required_string(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf(__('Spacefast did not return %s.', 'spacefast-frames'), $key));
        }
        return $value;
    }

    private static function random_token(int $bytes): string
    {
        return self::base64url(random_bytes($bytes));
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function seal(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $key = hash('sha256', wp_salt('auth') . home_url('/'), true);
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 's1.' . self::base64url($nonce . sodium_crypto_secretbox($value, $nonce, $key));
        }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($ciphertext)) {
            throw new RuntimeException(__('This server cannot protect the Spacefast credential.', 'spacefast-frames'));
        }
        return 'o1.' . self::base64url($iv . $tag . $ciphertext);
    }

    private static function unseal(string $sealed): string
    {
        if ($sealed === '') {
            return '';
        }
        $parts = explode('.', $sealed, 2);
        $encoded = $parts[1] ?? '';
        $padding = strlen($encoded) % 4;
        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }
        $raw = $encoded !== '' ? base64_decode(strtr($encoded, '-_', '+/'), true) : false;
        if (!is_string($raw)) {
            return '';
        }
        $key = hash('sha256', wp_salt('auth') . home_url('/'), true);
        if ($parts[0] === 's1' && function_exists('sodium_crypto_secretbox_open')) {
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $opened = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
            return is_string($opened) ? $opened : '';
        }
        if ($parts[0] === 'o1') {
            $opened = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
            return is_string($opened) ? $opened : '';
        }
        return '';
    }
}
