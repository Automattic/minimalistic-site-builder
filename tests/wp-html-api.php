<?php
declare(strict_types=1);

/**
 * Load WordPress's HTML API into the test process.
 *
 * The seeder plugin sanitizes with WP_HTML_Processor rather than a hand-ported
 * tokenizer, so covering it means having the real classes here. They ship only
 * inside WordPress, and this pipeline deliberately carries no WordPress
 * dependency — so the tests locate a copy instead of vendoring one, and skip
 * loudly when there is none.
 *
 * Point SITEBUILD_WP_PATH at a WordPress root to choose a specific copy.
 */

/** @return string|null the html-api directory, or null when none was found */
function wp_html_api_path(): ?string
{
    $roots = [];
    $configured = getenv('SITEBUILD_WP_PATH');
    if (is_string($configured) && $configured !== '') {
        $roots[] = rtrim($configured, '/');
    }
    $home = getenv('HOME') ?: '';
    foreach ([
        $home . '/.wp-now/wordpress-versions/*',
        $home . '/.wp-env/*/WordPress',
        dirname(__DIR__) . '/node_modules/@wp-playground/*/wordpress',
    ] as $pattern) {
        foreach (glob($pattern) ?: [] as $match) {
            $roots[] = $match;
        }
    }

    foreach ($roots as $root) {
        foreach (["{$root}/wp-includes/html-api", "{$root}/html-api"] as $dir) {
            if (is_file($dir . '/class-wp-html-processor.php')) {
                return $dir;
            }
        }
    }
    return null;
}

/** Load the HTML API and the handful of core functions it leans on. */
function load_wp_html_api(): bool
{
    if (class_exists('WP_HTML_Processor')) {
        return true;
    }
    $dir = wp_html_api_path();
    if ($dir === null) {
        return false;
    }

    // The decoder resolves named references (&amp;, &colon;) through a
    // WP_Token_Map built from this table; without either, every entity decode
    // dereferences null.
    $tokenMap = dirname($dir) . '/class-wp-token-map.php';
    if (is_file($tokenMap)) {
        require_once $tokenMap;
    }
    $references = $dir . '/html5-named-character-references.php';
    if (is_file($references)) {
        require_once $references;
    }
    foreach ([
        'span', 'text-replacement', 'attribute-token', 'decoder',
        'doctype-info', 'tag-processor', 'token', 'open-elements',
        'active-formatting-elements', 'stack-event', 'processor-state',
        'unsupported-exception', 'processor',
    ] as $class) {
        $file = $dir . '/class-wp-html-' . $class . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
    // kses.php judges a URL's scheme with array_any(), a PHP 8.4 builtin that
    // WordPress polyfills here. Real WordPress always loads compat.php; without
    // it wp_kses_bad_protocol() fatals on PHP 8.1, and the seeder catches that
    // Throwable and stores an empty page.
    $compat = dirname($dir) . '/compat.php';
    if (is_file($compat)) {
        require_once $compat;
    }
    // kses.php supplies wp_kses_uri_attributes() and wp_kses_bad_protocol(),
    // which the seeder uses to judge URL schemes.
    $kses = dirname($dir) . '/kses.php';
    if (is_file($kses)) {
        require_once $kses;
    }

    return class_exists('WP_HTML_Processor');
}

// The HTML API calls exactly these core functions. Declared before it loads so
// the real definitions (if a full WordPress is ever bootstrapped) win.
if (!function_exists('__')) {
    function __(string $text, ?string $domain = null): string { return $text; }
}
if (!function_exists('_doing_it_wrong')) {
    function _doing_it_wrong($function, $message, $version): void {}
}
if (!function_exists('wp_has_noncharacters')) {
    function wp_has_noncharacters(string $value): bool { return false; }
}
if (!function_exists('esc_url')) {
    function esc_url(string $url): string { return $url; }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, $value) { return $value; }
}
if (!function_exists('wp_allowed_protocols')) {
    function wp_allowed_protocols(): array
    {
        return [
            'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'gopher',
            'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'sms', 'svn', 'tel',
            'fax', 'xmpp', 'webcal', 'urn',
        ];
    }
}
