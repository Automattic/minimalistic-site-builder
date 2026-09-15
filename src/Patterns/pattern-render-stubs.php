<?php
/**
 * The WordPress functions a theme's pattern files call, stubbed.
 *
 * A registered pattern is a PHP file whose copy comes from translation calls
 * and whose images come from the theme's own directory. Rendering one outside
 * WordPress needs those to exist; it does not need WordPress. Each stub does
 * what its real counterpart does to the output and nothing else, so the markup
 * produced is the markup the theme registers, in the original language.
 *
 * Escaping matters here and is not skipped: the strings land in markup.
 */

declare(strict_types=1);

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('_x')) {
    function _x(string $text, string $context, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e(string $text, string $domain = 'default'): void
    {
        echo $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html($text);
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return esc_attr($text);
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo esc_html($text);
    }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = 'default'): void
    {
        echo esc_attr($text);
    }
}

if (!function_exists('esc_html_x')) {
    function esc_html_x(string $text, string $context, string $domain = 'default'): string
    {
        return esc_html($text);
    }
}

if (!function_exists('esc_attr_x')) {
    function esc_attr_x(string $text, string $context, string $domain = 'default'): string
    {
        return esc_attr($text);
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $html): string
    {
        return $html;
    }
}

if (!function_exists('pattern_theme_uri')) {
    /**
     * Where the destination site serves this theme's own files from.
     *
     * A pattern points at its theme's images through this, so getting it wrong
     * is a page of broken images that every other check passes. Inside
     * WordPress it is the deployed theme's URL; out here the caller says.
     * Never an absolute URL to this machine, which would be a published page
     * pointing at a laptop.
     */
    function pattern_theme_uri(?string $set = null): string
    {
        static $uri = '';
        if ($set !== null) {
            $uri = rtrim($set, '/');
        }

        return $uri;
    }
}

if (!function_exists('get_template_directory_uri')) {
    function get_template_directory_uri(): string
    {
        return pattern_theme_uri();
    }
}
