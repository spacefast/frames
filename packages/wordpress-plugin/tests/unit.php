<?php
declare(strict_types=1);

define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
define('SPACEFAST_FRAMES_VERSION', 'test');
$options = array();
$transients = array();
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['options'][$name] = $value; }
function delete_option($name) { unset($GLOBALS['options'][$name]); }
function set_transient($name, $value, $ttl) { $GLOBALS['transients'][$name] = $value; }
function get_transient($name) { return $GLOBALS['transients'][$name] ?? false; }
function delete_transient($name) { unset($GLOBALS['transients'][$name]); }
function untrailingslashit($value) { return rtrim($value, '/'); }
function esc_url_raw($value) { return $value; }
function admin_url($path) { return 'https://wordpress.example/wp-admin/' . $path; }
function is_wp_error($value) { return false; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_request($url, $args) { return ($GLOBALS['transport'])($url, $args); }
function wp_remote_post($url, $args) { return wp_remote_request($url, $args); }

function __($message, $domain = null) { return $message; }
function home_url($path = '') { return 'https://wordpress.example' . $path; }
function sanitize_text_field($value) { return trim((string) $value); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_salt($scheme = 'auth') { return 'spacefast-wordpress-unit-test-' . $scheme; }

require_once dirname(__DIR__) . '/includes/class-spacefast-frames-plugin.php';
require_once dirname(__DIR__) . '/includes/class-spacefast-frames-api.php';

function call_private(string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod(Spacefast_Frames_Plugin::class, $method);
    return $reflection->invoke(null, ...$arguments);
}

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

same('/', call_private('page_path', 'index.html'), 'Root index maps to the home route.');
same('/guide', call_private('page_path', 'guide/index.html'), 'Nested indexes map to clean routes.');
same('/about', call_private('page_path', 'about.html'), 'Flat HTML maps to an extensionless route.');
same(null, call_private('page_path', 'assets/app.js'), 'Assets do not appear in the page picker.');
same('/guide/start', call_private('normalize_path', '/guide/next/../start'), 'Paths are canonicalized.');

$refused = false;
try {
    call_private('normalize_path', '/../../secret');
} catch (InvalidArgumentException) {
    $refused = true;
}
same(true, $refused, 'Root-escaping paths are refused.');

$grant = call_private('mint_grant', 'spc_one', 'lnk_one', '/docs');
same(
    array('spaceId' => 'spc_one', 'linkId' => 'lnk_one', 'path' => '/docs'),
    call_private('verify_grant', $grant),
    'A block grant is bound to its Space, Frame Link, and path.'
);

$tampered = substr($grant, 0, -1) . (str_ends_with($grant, 'a') ? 'b' : 'a');
$refused = false;
try {
    call_private('verify_grant', $tampered);
} catch (UnexpectedValueException) {
    $refused = true;
}
same(true, $refused, 'A modified block grant is refused.');


$challenge = '';
$GLOBALS['transport'] = function ($url, $args) use (&$challenge) {
    if (str_ends_with($url, '/register')) {
        $registration = json_decode($args['body'], true);
        same('none', $registration['token_endpoint_auth_method'], 'Frames registers a public client for person authority.');
        same(array(admin_url('options-general.php?page=spacefast-frames&spacefast_oauth=callback')), $registration['redirect_uris'], 'The complete callback query is registered.');
        $body = array('client_id' => 'frames-test-client');
    } elseif (str_ends_with($url, '/token')) {
        $fields = $args['body'];
        same(false, array_key_exists('client_secret', $fields), 'Person grants do not send a client secret.');
        same('frames-test-client', $fields['client_id'], 'The registered client owns the token request.');
        if ($fields['grant_type'] === 'authorization_code') {
            same($challenge, rtrim(strtr(base64_encode(hash('sha256', $fields['code_verifier'], true)), '+/', '-_'), '='), 'The exchange proves the authorize request PKCE challenge.');
            same(admin_url('options-general.php?page=spacefast-frames&spacefast_oauth=callback'), $fields['redirect_uri'], 'The exchange preserves the registered callback.');
            $body = array('access_token' => 'initial-test-token', 'refresh_token' => 'test-refresh', 'expires_in' => 900);
        } else {
            same('test-refresh', $fields['refresh_token'], 'Refresh uses the stored token.');
            $body = array('access_token' => 'refreshed-test-token', 'expires_in' => 900);
        }
    } else {
        same('Bearer refreshed-test-token', $args['headers']['Authorization'], 'API calls use the refreshed person credential.');
        $body = array('data' => array('id' => 'frame-test-session'));
    }
    return array('response' => array('code' => 200), 'body' => json_encode($body));
};

$authorize = Spacefast_Frames_API::start_oauth();
parse_str(parse_url($authorize, PHP_URL_QUERY), $parameters);
same(admin_url('options-general.php?page=spacefast-frames&spacefast_oauth=callback'), $parameters['redirect_uri'], 'Authorize keeps the callback query inside redirect_uri.');
same(false, array_key_exists('spacefast_oauth', $parameters), 'Callback parameters cannot escape into authorize parameters.');
$challenge = $parameters['code_challenge'];
Spacefast_Frames_API::finish_oauth('test-code', $parameters['state']);
same(false, array_key_exists('client_secret', Spacefast_Frames_API::connection()), 'The connection stores no confidential client authority.');
$options['spacefast_frames_connection']['expires_at'] = time() - 1;
same(array('data' => array('id' => 'frame-test-session')), Spacefast_Frames_API::request('POST', '/v1/spaces/spc_test/frame-session', array('path' => '/')), 'An expired person credential refreshes before launching a Frame.');

$options['spacefast_frames_connection']['client_secret'] = 'old-confidential-connection';
$refused = false;
try {
    Spacefast_Frames_API::request('GET', '/v1/spaces');
} catch (RuntimeException $error) {
    same('Reconnect Spacefast to continue.', $error->getMessage(), 'An old app credential asks the administrator to reconnect.');
    $refused = true;
}
same(true, $refused, 'Existing confidential connections cannot launch Frames with app authority.');
echo "WordPress unit tests passed.\n";
