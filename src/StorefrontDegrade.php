<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The frozen block domain cannot execute a cart or checkout. A shop request
 * degrades to a catalog/storefront: pages are rewritten, cart UI is stripped,
 * and the loss is recorded. Never aborts.
 */
final class StorefrontDegrade
{
    private const CART_SLUGS = [
        'cart', 'checkout', 'basket', 'bag', 'shopping-cart', 'mini-cart',
        'woocommerce', 'woocommerce-cart', 'woocommerce-checkout',
    ];

    /**
     * @param array<int,array<string,mixed>> $pages
     * @return array{0:array<int,array<string,mixed>>,1:list<string>}
     */
    public static function pages(array $pages): array
    {
        $warnings = [];
        $used = [];
        foreach ($pages as $page) {
            if (is_array($page) && is_string($page['slug'] ?? null)) {
                $used[$page['slug']] = true;
            }
        }

        $out = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $children = is_array($page['children'] ?? null) ? $page['children'] : [];
            [$children, $childWarnings] = self::pages($children);
            array_push($warnings, ...$childWarnings);

            if (!self::pageLooksLikeCart($page)) {
                $page['children'] = $children;
                $out[] = $page;
                continue;
            }

            $authoredSlug = is_string($page['slug'] ?? null) ? $page['slug'] : '';
            $slug = self::freeCatalogSlug($used);
            $used[$slug] = true;
            $out[] = [
                'title' => 'Shop',
                'slug' => $slug,
                'purpose' => 'Product catalog. Visitors inquire by contact. There is no cart or checkout.',
                'children' => $children,
            ];
            $warnings[] = "file='siteSpec.json'; path=\"pages.{$authoredSlug}\"; authored="
                . Warnings::value($authoredSlug !== '' ? $authoredSlug : ($page['title'] ?? 'cart'))
                . "; delivered={$slug}; disposition shop/cart page rewritten to a catalog storefront";
        }

        return [$out, $warnings];
    }

    /**
     * @return array{0:string,1:list<string>}
     */
    public static function markup(string $markup, string $file = 'theme/parts'): array
    {
        if ($markup === '' || !self::markupLooksLikeCart($markup)) {
            return [$markup, []];
        }

        $warnings = [];
        $original = $markup;

        $markup = (string) preg_replace(
            '/<!--\s+wp:woocommerce\b.*?-->.*?<!--\s+\/wp:woocommerce\b.*?-->/is',
            '',
            $markup,
        );
        $markup = (string) preg_replace(
            '/<!--\s+wp:woocommerce\/[\w-]+\s+.*?\/-->/is',
            '',
            $markup,
        );
        $markup = (string) preg_replace('/<\/?form\b[^>]*>/i', '', $markup);
        $markup = (string) preg_replace('/<(input|textarea|select)\b[^>]*>.*?<\/\1>/is', '', $markup);
        $markup = (string) preg_replace('/<(input|select)\b[^>]*\/?>/i', '', $markup);
        $markup = (string) preg_replace(
            '/(>(?:\s*)Add to cart(?:\s*)<)/i',
            '>Enquire<',
            $markup,
        );
        $markup = (string) preg_replace(
            '/("text"\s*:\s*")Add to cart(")/i',
            '$1Enquire$2',
            $markup,
        );

        if ($markup === $original) {
            return [$original, []];
        }

        $warnings[] = "file='{$file}'; path=\"cart\"; authored=cart/checkout UI; delivered=removed; "
            . 'disposition stripped unbuildable cart constructs and kept a catalog storefront';
        return [$markup, $warnings];
    }

    public static function pageLooksLikeCart(array $page): bool
    {
        $slug = is_string($page['slug'] ?? null) ? strtolower(trim($page['slug'])) : '';
        if (in_array($slug, self::CART_SLUGS, true)) {
            return true;
        }
        $haystack = strtolower(
            (is_string($page['title'] ?? null) ? $page['title'] : '')
            . ' '
            . (is_string($page['purpose'] ?? null) ? $page['purpose'] : ''),
        );
        return preg_match(
            '/\b(?:shopping cart|checkout|add to cart|woocommerce|mini-?cart|basket checkout)\b/',
            $haystack,
        ) === 1;
    }

    public static function markupLooksLikeCart(string $markup): bool
    {
        return preg_match(
            '/woocommerce|add to cart|add-to-cart|shopping cart|\bcheckout\b|\bquantity\b|wp:woocommerce/i',
            $markup,
        ) === 1;
    }

    /** @param array<string,true> $used */
    private static function freeCatalogSlug(array $used): string
    {
        foreach (['shop', 'products', 'catalog'] as $slug) {
            if (!isset($used[$slug])) {
                return $slug;
            }
        }
        return 'catalog';
    }
}
