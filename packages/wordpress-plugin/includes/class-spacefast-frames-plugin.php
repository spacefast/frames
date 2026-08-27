<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Spacefast_Frames_Plugin
{
    private const GRANT_TTL = 30 * 86400;

    public static function boot(): void
    {
        add_action('init', array(self::class, 'register_block'));
        add_action('rest_api_init', array(self::class, 'register_rest_routes'));
        add_action('admin_menu', array(self::class, 'admin_menu'));
        add_action('admin_init', array(self::class, 'oauth_callback'));
        add_action('admin_post_spacefast_frames_connect', array(self::class, 'connect'));
        add_action('admin_post_spacefast_frames_disconnect', array(self::class, 'disconnect'));
        add_action('admin_post_spacefast_frames_save', array(self::class, 'save_settings'));
        add_filter('plugin_action_links_' . plugin_basename(SPACEFAST_FRAMES_FILE), array(self::class, 'action_links'));
    }

    public static function register_block(): void
    {
        register_block_type(SPACEFAST_FRAMES_DIR . 'build');
        wp_add_inline_script(
            'spacefast-page-editor-script',
            'window.SpacefastFramesBlock=' . wp_json_encode(
                array(
                    'connected' => Spacefast_Frames_API::connected(),
                    'settingsUrl' => admin_url('options-general.php?page=spacefast-frames'),
                )
            ) . ';',
            'before'
        );
    }

    public static function register_rest_routes(): void
    {
        register_rest_route(
            'spacefast/v1',
            '/spaces',
            array(
                'methods' => WP_REST_Server::READABLE,
                'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
                'callback' => array(self::class, 'rest_spaces'),
            )
        );
        register_rest_route(
            'spacefast/v1',
            '/spaces/(?P<space_id>[A-Za-z0-9_-]+)/pages',
            array(
                'methods' => WP_REST_Server::READABLE,
                'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
                'callback' => array(self::class, 'rest_pages'),
            )
        );
        register_rest_route(
            'spacefast/v1',
            '/frame-links',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
                'callback' => array(self::class, 'rest_frame_link'),
            )
        );
        register_rest_route(
            'spacefast/v1',
            '/frame-session',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => '__return_true',
                'callback' => array(self::class, 'rest_frame_session'),
            )
        );
    }

    public static function rest_frame_link(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $space_id = sanitize_text_field((string) $request->get_param('spaceId'));
            $path = self::normalize_path((string) $request->get_param('path'));
            $origin = self::site_origin();
            $response = Spacefast_Frames_API::request('GET', '/v1/spaces/' . rawurlencode($space_id) . '/share-links');
            $links = is_array($response['data'] ?? null) ? $response['data'] : array();
            foreach ($links as $link) {
                if (
                    is_array($link)
                    && !empty($link['active'])
                    && ($link['landingPath'] ?? null) === $path
                    && ($link['constraints']['frameOrigin'] ?? null) === $origin
                    && is_string($link['id'] ?? null)
                ) {
                    return rest_ensure_response(array('linkId' => (string) $link['id']));
                }
            }
            $pattern = $path === '/' ? '/**' : rtrim($path, '/') . '/**';
            $created = Spacefast_Frames_API::request(
                'POST',
                '/v1/spaces/' . rawurlencode($space_id) . '/share-links',
                array(
                    'name' => sprintf(__('WordPress frame on %s', 'spacefast-frames'), (string) wp_parse_url($origin, PHP_URL_HOST)),
                    'landingPath' => $path,
                    'resources' => array('include' => array($pattern), 'exclude' => array()),
                    'capabilities' => array('page.view'),
                    'constraints' => array('frameOrigin' => $origin),
                    'target' => array('kind' => 'live'),
                )
            );
            $link_id = is_string($created['data']['id'] ?? null) ? (string) $created['data']['id'] : '';
            if ($link_id === '') {
                throw new RuntimeException(__('Spacefast did not return a Frame Link.', 'spacefast-frames'));
            }
            return rest_ensure_response(array('linkId' => $link_id));
        } catch (Throwable $error) {
            return self::rest_error($error);
        }
    }

    public static function rest_spaces(): WP_REST_Response|WP_Error
    {
        try {
            $response = Spacefast_Frames_API::request('GET', '/v1/spaces?limit=100');
            $spaces = is_array($response['data'] ?? null) ? $response['data'] : array();
            return rest_ensure_response(array('spaces' => array_map(array(self::class, 'present_space'), $spaces)));
        } catch (Throwable $error) {
            return self::rest_error($error);
        }
    }

    public static function rest_pages(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $space_id = sanitize_text_field((string) $request['space_id']);
            $response = Spacefast_Frames_API::request('GET', '/v1/spaces/' . rawurlencode($space_id) . '/files');
            $data = is_array($response['data'] ?? null) ? $response['data'] : array();
            $files = is_array($data['files'] ?? null) ? $data['files'] : array();
            $pages = array();
            foreach ($files as $file) {
                if (!is_array($file) || !is_string($file['path'] ?? null)) {
                    continue;
                }
                $path = self::page_path((string) $file['path']);
                if ($path !== null) {
                    $pages[$path] = array('path' => $path, 'label' => $path === '/' ? __('Home', 'spacefast-frames') : $path);
                }
            }
            if ($pages === array()) {
                $pages['/'] = array('path' => '/', 'label' => __('Home', 'spacefast-frames'));
            }
            ksort($pages, SORT_NATURAL | SORT_FLAG_CASE);
            return rest_ensure_response(array('pages' => array_values($pages)));
        } catch (Throwable $error) {
            return self::rest_error($error);
        }
    }

    public static function rest_frame_session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $grant = sanitize_text_field((string) $request->get_param('grant'));
            if ($grant !== '') {
                $claims = self::verify_grant($grant);
            } elseif (current_user_can('edit_posts')) {
                $claims = array(
                    'spaceId' => sanitize_text_field((string) $request->get_param('spaceId')),
                    'linkId' => sanitize_text_field((string) $request->get_param('linkId')),
                    'path' => self::normalize_path((string) $request->get_param('path')),
                );
            } else {
                return new WP_Error('spacefast_frame_grant_required', __('This frame grant is missing or expired.', 'spacefast-frames'), array('status' => 403));
            }
            self::rate_limit($grant !== '' ? $grant : (string) get_current_user_id());
            $response = Spacefast_Frames_API::request(
                'POST',
                '/v1/spaces/' . rawurlencode((string) $claims['spaceId']) . '/share-links/' . rawurlencode((string) $claims['linkId']) . '/frame-session',
                array('path' => $claims['path'])
            );
            $data = is_array($response['data'] ?? null) ? $response['data'] : null;
            if ($data === null) {
                throw new RuntimeException(__('Spacefast did not return a frame session.', 'spacefast-frames'));
            }
            $rest = rest_ensure_response($data);
            $rest->header('Cache-Control', 'private, no-store');
            return $rest;
        } catch (OverflowException $error) {
            return new WP_Error('spacefast_frame_rate_limited', $error->getMessage(), array('status' => 429));
        } catch (InvalidArgumentException|UnexpectedValueException $error) {
            return new WP_Error('spacefast_frame_grant_invalid', $error->getMessage(), array('status' => 403));
        } catch (Throwable $error) {
            return self::rest_error($error);
        }
    }

    public static function render_block(array $attributes, string $content = '', ?WP_Block $block = null): string
    {
        $space_id = sanitize_text_field((string) ($attributes['spaceId'] ?? ''));
        $link_id = sanitize_text_field((string) ($attributes['linkId'] ?? ''));
        if ($space_id === '' || $link_id === '') {
            return '';
        }
        try {
            $path = self::normalize_path((string) ($attributes['path'] ?? '/'));
        } catch (Throwable) {
            return '';
        }
        $height = min(1600, max(240, (int) ($attributes['height'] ?? 640)));
        $title = sanitize_text_field((string) ($attributes['title'] ?? __('Spacefast page', 'spacefast-frames')));
        $permissions = self::permissions($attributes['permissions'] ?? array());
        $sandbox = array('allow-modals', 'allow-popups-to-escape-sandbox', 'allow-same-origin', 'allow-scripts');
        if (!empty($attributes['allowForms'])) {
            $sandbox[] = 'allow-forms';
        }
        if (!empty($attributes['allowDownloads'])) {
            $sandbox[] = 'allow-downloads';
        }
        if (!empty($attributes['allowPopups'])) {
            $sandbox[] = 'allow-popups';
        }
        $grant = self::mint_grant($space_id, $link_id, $path);
        $config = array(
            'endpoint' => rest_url('spacefast/v1/frame-session'),
            'grant' => $grant,
            'path' => $path,
            'title' => $title,
            'loading' => ($attributes['loading'] ?? 'lazy') === 'eager' ? 'eager' : 'lazy',
            'permissions' => $permissions,
            'sandbox' => $sandbox,
        );
        $wrapper = get_block_wrapper_attributes(
            array(
                'class' => 'spacefast-frame',
                'style' => '--spacefast-frame-height:' . $height . 'px',
                'data-spacefast-config' => wp_json_encode($config),
            )
        );
        return sprintf(
            '<div %1$s><div data-spacefast-frame-mount></div><p class="spacefast-frame__status" data-spacefast-frame-status hidden></p><noscript><p>%2$s</p></noscript></div>',
            $wrapper,
            esc_html__('JavaScript is required to open this Spacefast page.', 'spacefast-frames')
        );
    }

    public static function admin_menu(): void
    {
        add_options_page(__('Spacefast Frames', 'spacefast-frames'), __('Spacefast Frames', 'spacefast-frames'), 'manage_options', 'spacefast-frames', array(self::class, 'settings_page'));
    }

    public static function settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (isset($_GET['spacefast_error'])) {
            $message = get_transient('spacefast_frames_admin_error_' . get_current_user_id());
            delete_transient('spacefast_frames_admin_error_' . get_current_user_id());
            add_settings_error('spacefast_frames', 'spacefast_frames_error', is_string($message) ? $message : __('Spacefast could not connect.', 'spacefast-frames'), 'error');
        } elseif (isset($_GET['spacefast_connected'])) {
            add_settings_error('spacefast_frames', 'spacefast_frames_connected', __('Spacefast is connected.', 'spacefast-frames'), 'success');
        } elseif (isset($_GET['spacefast_disconnected'])) {
            add_settings_error('spacefast_frames', 'spacefast_frames_disconnected', __('Spacefast is disconnected.', 'spacefast-frames'), 'success');
        } elseif (isset($_GET['spacefast_saved'])) {
            add_settings_error('spacefast_frames', 'spacefast_frames_saved', __('Settings saved.', 'spacefast-frames'), 'success');
        }
        $connected = Spacefast_Frames_API::connected();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Spacefast Frames', 'spacefast-frames'); ?></h1>
            <p><?php esc_html_e('Pick private or public Spacefast pages in the block editor. WordPress keeps your login server-side and gives each visitor a short-lived frame session.', 'spacefast-frames'); ?></p>
            <?php settings_errors('spacefast_frames'); ?>
            <hr>
            <h2><?php esc_html_e('Connection', 'spacefast-frames'); ?></h2>
            <?php if ($connected) : ?>
                <p><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e('Connected to Spacefast.', 'spacefast-frames'); ?></p>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="spacefast_frames_disconnect">
                    <?php wp_nonce_field('spacefast_frames_disconnect'); ?>
                    <?php submit_button(__('Disconnect', 'spacefast-frames'), 'secondary', 'submit', false); ?>
                </form>
            <?php else : ?>
                <p><?php esc_html_e('Connect once, choose the teams Spacefast may access, then add the Spacefast Page block anywhere.', 'spacefast-frames'); ?></p>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="spacefast_frames_connect">
                    <?php wp_nonce_field('spacefast_frames_connect'); ?>
                    <?php submit_button(__('Connect Spacefast', 'spacefast-frames'), 'primary', 'submit', false); ?>
                </form>
            <?php endif; ?>
            <h2><?php esc_html_e('Advanced', 'spacefast-frames'); ?></h2>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="spacefast_frames_save">
                <?php wp_nonce_field('spacefast_frames_save'); ?>
                <label for="spacefast-api-base"><strong><?php esc_html_e('API URL', 'spacefast-frames'); ?></strong></label>
                <p class="description"><?php esc_html_e('Leave this on the production URL unless you run Spacefast locally.', 'spacefast-frames'); ?></p>
                <input id="spacefast-api-base" name="api_base" type="url" class="regular-text code" value="<?php echo esc_attr(Spacefast_Frames_API::api_base()); ?>" required>
                <?php submit_button(__('Save settings', 'spacefast-frames'), 'secondary'); ?>
            </form>
        </div>
        <?php
    }

    public static function connect(): void
    {
        self::require_admin_action('spacefast_frames_connect');
        try {
            $url = Spacefast_Frames_API::start_oauth();
            wp_redirect($url, 302, 'Spacefast Frames'); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- URL is built from the validated configured Spacefast API origin.
            exit;
        } catch (Throwable $error) {
            self::redirect_with_error($error->getMessage());
        }
    }

    public static function oauth_callback(): void
    {
        if (!is_admin() || ($_GET['page'] ?? '') !== 'spacefast-frames' || ($_GET['spacefast_oauth'] ?? '') !== 'callback') {
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You cannot connect Spacefast on this site.', 'spacefast-frames'),
                '',
                array('response' => 403)
            );
        }
        try {
            $error = sanitize_text_field(wp_unslash((string) ($_GET['error'] ?? '')));
            if ($error !== '') {
                throw new RuntimeException(__('Spacefast connection was not approved.', 'spacefast-frames'));
            }
            $code = sanitize_text_field(wp_unslash((string) ($_GET['code'] ?? '')));
            $state = sanitize_text_field(wp_unslash((string) ($_GET['state'] ?? '')));
            if ($code === '' || $state === '') {
                throw new RuntimeException(__('Spacefast returned an incomplete connection.', 'spacefast-frames'));
            }
            Spacefast_Frames_API::finish_oauth($code, $state);
            wp_safe_redirect(admin_url('options-general.php?page=spacefast-frames&spacefast_connected=1'));
            exit;
        } catch (Throwable $caught) {
            self::redirect_with_error($caught->getMessage());
        }
    }

    public static function disconnect(): void
    {
        self::require_admin_action('spacefast_frames_disconnect');
        Spacefast_Frames_API::disconnect();
        wp_safe_redirect(admin_url('options-general.php?page=spacefast-frames&spacefast_disconnected=1'));
        exit;
    }

    public static function save_settings(): void
    {
        self::require_admin_action('spacefast_frames_save');
        try {
            Spacefast_Frames_API::save_api_base((string) wp_unslash($_POST['api_base'] ?? ''));
            wp_safe_redirect(admin_url('options-general.php?page=spacefast-frames&spacefast_saved=1'));
            exit;
        } catch (Throwable $error) {
            self::redirect_with_error($error->getMessage());
        }
    }

    public static function action_links(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=spacefast-frames')) . '">' . esc_html__('Settings', 'spacefast-frames') . '</a>');
        return $links;
    }

    private static function present_space(mixed $space): array
    {
        if (!is_array($space)) {
            return array('id' => '', 'title' => __('Untitled space', 'spacefast-frames'));
        }
        return array(
            'id' => sanitize_text_field((string) ($space['id'] ?? '')),
            'title' => sanitize_text_field((string) ($space['title'] ?? $space['slug'] ?? __('Untitled space', 'spacefast-frames'))),
            'liveUrl' => esc_url_raw((string) ($space['liveUrl'] ?? '')),
        );
    }

    private static function page_path(string $file): ?string
    {
        $path = ltrim(str_replace('\\', '/', $file), '/');
        if ($path === 'index.html') {
            return '/';
        }
        if (str_ends_with($path, '/index.html')) {
            return '/' . substr($path, 0, -strlen('/index.html'));
        }
        if (str_ends_with($path, '.html')) {
            return '/' . substr($path, 0, -strlen('.html'));
        }
        return null;
    }

    private static function normalize_path(string $input): string
    {
        $path = trim($input);
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\\\\?#\x00-\x1f\x7f]/', $path)) {
            throw new InvalidArgumentException(__('Choose a plain absolute Spacefast page path.', 'spacefast-frames'));
        }
        $segments = array();
        foreach (explode('/', rawurldecode($path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === array()) {
                    throw new InvalidArgumentException(__('The Spacefast page path escapes the site root.', 'spacefast-frames'));
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        if (end($segments) === 'index.html') {
            array_pop($segments);
        }
        return $segments === array() ? '/' : '/' . implode('/', $segments);
    }

    private static function permissions(mixed $input): array
    {
        $allowed = array('accelerometer', 'autoplay', 'camera', 'clipboard-read', 'clipboard-write', 'encrypted-media', 'fullscreen', 'geolocation', 'gyroscope', 'microphone', 'picture-in-picture');
        return array_values(array_unique(array_intersect($allowed, is_array($input) ? array_map('sanitize_key', $input) : array())));
    }

    private static function mint_grant(string $space_id, string $link_id, string $path): string
    {
        $payload = wp_json_encode(
            array(
                'v' => 1,
                'spaceId' => $space_id,
                'linkId' => $link_id,
                'path' => $path,
                'origin' => self::site_origin(),
                'exp' => time() + self::GRANT_TTL,
            ),
            JSON_UNESCAPED_SLASHES
        );
        $encoded = self::base64url((string) $payload);
        return $encoded . '.' . self::base64url(hash_hmac('sha256', $encoded, wp_salt('secure_auth'), true));
    }

    private static function verify_grant(string $grant): array
    {
        $parts = explode('.', $grant, 2);
        if (count($parts) !== 2 || !hash_equals(self::base64url(hash_hmac('sha256', $parts[0], wp_salt('secure_auth'), true)), $parts[1])) {
            throw new UnexpectedValueException(__('This frame grant is invalid.', 'spacefast-frames'));
        }
        $decoded = self::base64url_decode($parts[0]);
        $claims = $decoded === null ? null : json_decode($decoded, true);
        if (!is_array($claims) || ($claims['v'] ?? null) !== 1 || (int) ($claims['exp'] ?? 0) < time()) {
            throw new UnexpectedValueException(__('This frame grant has expired.', 'spacefast-frames'));
        }
        $origin = self::site_origin();
        if (!hash_equals($origin, (string) ($claims['origin'] ?? ''))) {
            throw new UnexpectedValueException(__('This frame grant belongs to another WordPress origin.', 'spacefast-frames'));
        }
        return array(
            'spaceId' => sanitize_text_field((string) ($claims['spaceId'] ?? '')),
            'linkId' => sanitize_text_field((string) ($claims['linkId'] ?? '')),
            'path' => self::normalize_path((string) ($claims['path'] ?? '/')),
        );
    }

    private static function site_origin(): string
    {
        $parts = wp_parse_url(home_url('/'));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException(__('WordPress has no valid public origin.', 'spacefast-frames'));
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']) . $port;
    }

    private static function rate_limit(string $subject): void
    {
        $key = 'spacefast_frame_rate_' . substr(hash('sha256', $subject . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 40);
        $count = (int) get_transient($key);
        if ($count >= 60) {
            throw new OverflowException(__('Too many frame sessions. Try again in a minute.', 'spacefast-frames'));
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
    }

    private static function rest_error(Throwable $error): WP_Error
    {
        return new WP_Error('spacefast_frames_error', $error->getMessage(), array('status' => 502));
    }

    private static function require_admin_action(string $nonce): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You cannot manage Spacefast on this site.', 'spacefast-frames'),
                '',
                array('response' => 403)
            );
        }
        check_admin_referer($nonce);
    }

    private static function redirect_with_error(string $message): never
    {
        set_transient('spacefast_frames_admin_error_' . get_current_user_id(), sanitize_text_field($message), MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('options-general.php?page=spacefast-frames&spacefast_error=1'));
        exit;
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64url_decode(string $value): ?string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }
}
