<?php
declare(strict_types=1);

define('ABSPATH', __DIR__);
function __($message, $domain = null) { return $message; }
function home_url($path = '') { return 'https://wordpress.example' . $path; }
function sanitize_text_field($value) { return trim((string) $value); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_salt($scheme = 'auth') { return 'spacefast-wordpress-unit-test-' . $scheme; }

require_once dirname(__DIR__) . '/includes/class-spacefast-frames-plugin.php';

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
