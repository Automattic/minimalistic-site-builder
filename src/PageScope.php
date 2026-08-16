<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The class WordPress puts on `<body>` so a delivered page can be told apart
 * from its siblings in CSS.
 *
 * A design page is `site.css` plus that page's own `<style data-page-css>`
 * chunk. Delivering every chunk in one global stylesheet lets one page's
 * element rules restyle every other page, so `page-styles` scopes each chunk
 * with this class and `finalize-theme` publishes it through `body_class`.
 * The key is the page slug, which is also the `post_name` the content plugin
 * creates the page with.
 */
final class PageScope
{
    public const CLASS_PREFIX = 'blocks-engine-page-';

    public static function bodyClass(string $pageSlug): string
    {
        return self::CLASS_PREFIX . ProjectStore::slugify($pageSlug);
    }
}
