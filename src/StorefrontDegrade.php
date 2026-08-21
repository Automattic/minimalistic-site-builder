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
        $used = [];
        self::collectUsedSlugs($pages, $used);
        return self::rewritePages($pages, $used);
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,true>             $used
     * @return array{0:array<int,array<string,mixed>>,1:list<string>}
     */
    private static function rewritePages(array $pages, array &$used): array
    {
        $warnings = [];

        $out = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $children = is_array($page['children'] ?? null) ? $page['children'] : [];
            [$children, $childWarnings] = self::rewritePages($children, $used);
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
                'purpose' => 'Product catalog. Visitors inquire by contact.',
                'children' => $children,
            ];
            $warnings[] = "file='siteSpec.json'; path=\"pages.{$authoredSlug}\"; authored="
                . Warnings::value($authoredSlug !== '' ? $authoredSlug : ($page['title'] ?? 'cart'))
                . '; delivered=' . Warnings::value($slug)
                . '; disposition shop/cart page rewritten to a catalog storefront';
        }

        return [$out, $warnings];
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,true>             $used
     */
    private static function collectUsedSlugs(array $pages, array &$used): void
    {
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            if (is_string($page['slug'] ?? null)) {
                $used[$page['slug']] = true;
            }
            self::collectUsedSlugs(
                is_array($page['children'] ?? null) ? $page['children'] : [],
                $used,
            );
        }
    }

    /**
     * Purchase controls a catalog storefront cannot honor, and the label each
     * one becomes. "Add to cart" was the only one matched before, so
     * "Checkout" and "Buy now" went through untouched.
     */
    private const CTA_LABELS = [
        'proceed to checkout', 'add to cart', 'add to bag', 'add to basket',
        'buy it now', 'buy now', 'order now', 'place order', 'pay now',
        'checkout', 'check out', 'purchase',
    ];

    private const CTA_REPLACEMENT = 'Enquire';

    /** Route fragments that mean "this destination is a cart, not a page". */
    private const CART_ROUTE = '~(?:^|/)(?:cart|checkout|basket|bag|shopping-cart|mini-cart)(?:/|$|[?#])~i';

    /**
     * Degrade cart markup to a catalog storefront.
     *
     * @param string  $markup       the part or template bytes
     * @param string  $file         the project-relative path, for warning rows
     * @param ?string $contactRoute where a relabelled purchase CTA should point
     *        instead, when the build knows of a contact page. Without one the
     *        destination is reported rather than invented.
     * @return array{0:string,1:list<string>}
     */
    public static function markup(string $markup, string $file = 'theme/parts', ?string $contactRoute = null): array
    {
        if ($markup === '' || !self::markupLooksLikeCart($markup)) {
            return [$markup, []];
        }

        $warnings = [];
        $original = $markup;
        $changes = [];

        // Vendor blocks come out by parsed boundary, not by regex. A lazy
        // `.*?` under /s stopped at the FIRST closing comment, so a nested
        // woocommerce block left its outer closer behind and broke the page.
        [$markup, $blockChanges, $blockWarnings] = self::removeVendorBlocks($markup, $file);
        array_push($changes, ...$blockChanges);
        array_push($warnings, ...$blockWarnings);

        $replacements = [
            ['/<\/?form\b[^>]*>/i', '', 'removed a form wrapper'],
            ['/<(input|textarea|select)\b[^>]*>.*?<\/\1>/is', '', 'removed a form control'],
            ['/<(input|select)\b[^>]*\/?>/i', '', 'removed a form control'],
        ];
        foreach ($replacements as [$pattern, $replacement, $label]) {
            $count = 0;
            $replaced = preg_replace($pattern, $replacement, $markup, -1, $count);
            if ($replaced === null) {
                $warnings[] = self::row($file, 'cart', 'cart/checkout UI', 'unchanged')
                    . 'kept the original markup because a cart cleanup pattern failed: '
                    . preg_last_error_msg();
                return [$original, $warnings];
            }
            if ($count > 0) {
                $changes[] = "{$label} ({$count})";
            }
            $markup = $replaced;
        }

        [$markup, $ctaChanges, $ctaWarnings] = self::relabelPurchaseCtas($markup, $file, $contactRoute);
        array_push($changes, ...$ctaChanges);
        array_push($warnings, ...$ctaWarnings);

        if ($markup === $original) {
            return [$original, $warnings];
        }

        // Say what happened, not just that something did. A rename and a
        // removal are different repairs and the old row called both "removed".
        $warnings[] = self::row($file, 'cart', 'cart/checkout UI', 'degraded')
            . 'kept a catalog storefront: ' . implode('; ', $changes);
        return [$markup, $warnings];
    }

    /**
     * Remove vendor cart blocks at their real delimiter boundary.
     *
     * BlockMarkup::endOffset() returns null precisely so a caller can leave
     * bytes alone rather than half-rewrite them, so an unclosed or crossed
     * block is reported and kept whole.
     *
     * @return array{0:string,1:list<string>,2:list<string>}
     */
    private static function removeVendorBlocks(string $markup, string $file): array
    {
        if (stripos($markup, 'wp:woocommerce') === false) {
            return [$markup, [], []];
        }
        $document = BlockMarkup::parse($markup);
        $spans = [];
        $changes = [];
        $warnings = [];
        foreach ($document->indices() as $index) {
            if (!str_starts_with(strtolower($document->name($index)), 'woocommerce/')) {
                continue;
            }
            // A nested vendor block inside one already being removed comes out
            // with its parent; taking both would splice overlapping ranges.
            $parent = $document->parent($index);
            if ($parent !== null && str_starts_with(strtolower($document->name($parent)), 'woocommerce/')) {
                continue;
            }
            if (!$document->isStructurallySafe($index)) {
                $warnings[] = self::row($file, 'cart', $document->name($index), 'unchanged')
                    . 'left the block untouched because its delimiter boundary could not be proven; '
                    . 'removing it would risk an unmatched closing comment';
                continue;
            }
            $start = $document->openingOffset($index);
            $end = $document->endOffset($index);
            $spans[] = [$start, $end];
            $changes[] = 'removed ' . $document->name($index);
        }
        // Back to front, so an earlier removal cannot shift a later offset.
        usort($spans, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($spans as [$start, $end]) {
            $markup = substr($markup, 0, $start) . substr($markup, $end);
        }
        return [$markup, $changes, $warnings];
    }

    /**
     * Relabel every purchase CTA and take its destination off the cart route.
     *
     * A relabelled button that still points at /cart is a button that lies, so
     * the destination moves to the contact page when the build knows one and
     * is reported with its exact value when it does not.
     *
     * @return array{0:string,1:list<string>,2:list<string>}
     */
    private static function relabelPurchaseCtas(string $markup, string $file, ?string $contactRoute): array
    {
        $changes = [];
        $warnings = [];
        $labels = implode('|', array_map(
            static fn (string $label): string => str_replace(' ', '\s+', preg_quote($label, '/')),
            self::CTA_LABELS,
        ));

        foreach ([
            // Visible label of a link or button, not a heading or paragraph
            // whose entire text happens to be "Purchase" or "Checkout".
            '/(<(?:a|button)\b[^>]*>\s*)(?:' . $labels . ')(\s*<\/(?:a|button)>)/i',
            // The same label inside a block attribute payload (core/button).
            '/("text"\s*:\s*")(?:' . $labels . ')(")/i',
        ] as $pattern) {
            $count = 0;
            $replaced = preg_replace($pattern, '$1' . self::CTA_REPLACEMENT . '$2', $markup, -1, $count);
            if ($replaced === null) {
                $warnings[] = self::row($file, 'cart', 'purchase CTA', 'unchanged')
                    . 'kept the label because the CTA pattern failed: ' . preg_last_error_msg();
                return [$markup, $changes, $warnings];
            }
            if ($count > 0) {
                $changes[] = 'relabelled ' . $count . ' purchase CTA(s) to "' . self::CTA_REPLACEMENT . '"';
            }
            $markup = $replaced;
        }

        foreach (self::cartDestinations($markup) as $destination) {
            if ($contactRoute !== null && $contactRoute !== '') {
                $markup = str_replace('"' . $destination . '"', '"' . $contactRoute . '"', $markup);
                $markup = str_replace("'" . $destination . "'", "'" . $contactRoute . "'", $markup);
                $changes[] = "pointed {$destination} at {$contactRoute}";
                continue;
            }
            $warnings[] = self::row($file, 'cart', $destination, $destination)
                . 'relabelled the control but left its destination on a cart route, because this build '
                . 'has no contact page to send an enquiry to';
        }

        return [$markup, $changes, $warnings];
    }

    /**
     * Every distinct cart-route destination the markup links to.
     *
     * @return list<string>
     */
    private static function cartDestinations(string $markup): array
    {
        if (preg_match_all('/(?:href|url)["\']?\s*[:=]\s*["\']([^"\']+)["\']/i', $markup, $found) !== false) {
            $destinations = [];
            foreach ($found[1] ?? [] as $value) {
                if (preg_match(self::CART_ROUTE, $value) === 1 && !in_array($value, $destinations, true)) {
                    $destinations[] = $value;
                }
            }
            return $destinations;
        }
        return [];
    }

    /** One warnings.json row prefix in the project's file/path/authored shape. */
    private static function row(string $file, string $path, string $authored, string $delivered): string
    {
        return "file='{$file}'; path=\"{$path}\"; authored=" . Warnings::value($authored)
            . '; delivered=' . Warnings::value($delivered) . '; disposition ';
    }

    /**
     * Route of this build's contact page, or null when it has none.
     *
     * Slug and title must be the contact token itself (plus optional "us"),
     * so a photography "Contact Sheet" does not steal the enquiry URL.
     * The delivered href is pages.json `path` when present, trailing slash
     * included, so a nested contact page keeps its real route.
     *
     * @param list<mixed> $pages
     */
    public static function contactRouteFromPages(array $pages): ?string
    {
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $slug = strtolower(trim((string) ($page['slug'] ?? '')));
            $title = strtolower(trim((string) ($page['title'] ?? '')));
            if (!self::looksLikeContactPage($slug, $title)) {
                continue;
            }
            $path = trim((string) ($page['path'] ?? ''));
            if ($path !== '') {
                if (!str_starts_with($path, '/')) {
                    $path = '/' . $path;
                }
                if ($path !== '/' && !str_ends_with($path, '/')) {
                    $path .= '/';
                }
                return $path;
            }
            if ($slug !== '') {
                return '/' . ltrim($slug, '/') . '/';
            }
        }
        return null;
    }

    /** Slug `contact` / `contact-us`, title "Contact" / "Contact Us" — not contact-sheet. */
    private static function looksLikeContactPage(string $slug, string $title): bool
    {
        $token = '(?:contact|contacto|contato|kontakt|contatti)';
        return ($slug !== '' && preg_match('/^' . $token . '(?:-us)?$/u', $slug) === 1)
            || ($title !== '' && preg_match('/^' . $token . '(?:\s+us)?$/u', $title) === 1);
    }

    public static function pageLooksLikeCart(array $page): bool
    {
        $slug = is_string($page['slug'] ?? null) ? strtolower(trim($page['slug'])) : '';
        if (in_array($slug, self::CART_SLUGS, true)) {
            return true;
        }
        // Bare "checkout" is a cart signal in a title ("Checkout") or slug,
        // not in purpose copy — a hotel Visit page that mentions checkout
        // time is not a shop.
        $title = strtolower(is_string($page['title'] ?? null) ? $page['title'] : '');
        if (preg_match(
            '/\b(?:shopping cart|checkout|add to cart|woocommerce|mini-?cart|basket checkout)\b/',
            $title,
        ) === 1) {
            return true;
        }
        $purpose = strtolower(is_string($page['purpose'] ?? null) ? $page['purpose'] : '');
        return preg_match(
            '/\b(?:shopping cart|add to cart|woocommerce|mini-?cart|basket checkout)\b/',
            $purpose,
        ) === 1;
    }

    /**
     * Whether a part carries anything the catalog storefront cannot honor.
     *
     * This gates the whole pass, so it has to know the same CTA vocabulary the
     * relabeller does — "Buy now" and "Place order" used to reach neither,
     * because a button carrying one names no cart, no checkout and no quantity.
     */
    public static function markupLooksLikeCart(string $markup): bool
    {
        $labels = implode('|', array_map(
            static fn (string $label): string => str_replace(' ', '\\s+', preg_quote($label, '/')),
            self::CTA_LABELS,
        ));
        return preg_match(
            '/woocommerce|wp:woocommerce|add-to-cart|shopping cart|\bquantity\b|' . $labels . '/i',
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
        $n = 2;
        do {
            $slug = "catalog-{$n}";
            $n++;
        } while (isset($used[$slug]));
        return $slug;
    }
}
