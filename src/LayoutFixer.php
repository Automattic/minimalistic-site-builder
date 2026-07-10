<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Deterministic width/rhythm normalization for generated block markup.
 *
 * The section/header/footer prompts describe a width contract (top-level
 * groups declare a constrained layout, grids go wide, footer rows share one
 * width), but the model drifts from it often enough that pages render with
 * broken container widths: a full-align section with no layout attribute
 * spills its columns edge-to-edge at the viewport; a card grid trapped in a
 * narrow contentSize wrapper floats in a wide band; a footer mixes 860px and
 * 1320px rows so two left edges compete on the same surface.
 *
 * This fixer parses the block-comment grammar, repairs those patterns by
 * editing ONLY the comment JSON attributes, and leaves the authored HTML
 * untouched — it is meant to run immediately BEFORE the Node block-fixer,
 * which re-serializes every block from its comment attributes and thereby
 * syncs the HTML (align classes etc.) with what this pass wrote.
 *
 * Every rule is idempotent, so the same pass doubles as a dry-run linter
 * (ThemeValidator::layoutWarnings): on already-normalized markup it reports
 * nothing.
 */
final class LayoutFixer
{
    public const ROLE_HEADER   = 'header';
    public const ROLE_FOOTER   = 'footer';
    public const ROLE_SECTION  = 'section';
    public const ROLE_TEMPLATE = 'template';

    /**
     * Below this fraction of the theme's contentSize, a cover's inner
     * constrained group is considered an accidental squeeze (portfolio2's
     * hero pinned its display headline into a 640px box) rather than a
     * deliberate reading measure, and the override is dropped.
     */
    private const COVER_MEASURE_FLOOR = 0.8;

    /** The role a theme file plays, from its path relative to the theme root. */
    public static function roleFor(string $rel): string
    {
        $base = basename($rel);
        return match (true) {
            $base === 'header.html'               => self::ROLE_HEADER,
            $base === 'footer.html'               => self::ROLE_FOOTER,
            str_starts_with($rel, 'templates/')   => self::ROLE_TEMPLATE,
            default                               => self::ROLE_SECTION,
        };
    }

    /**
     * Normalize one file's markup. $contentSize is the theme.json
     * settings.layout.contentSize in px (null when unknown — the cover
     * measure rule is skipped without it).
     *
     * @return array{markup:string, notes:string[]} notes are human-readable
     *         descriptions of each change; empty notes means markup is
     *         returned unchanged.
     */
    public static function fix(string $markup, string $role, ?float $contentSize = null): array
    {
        $parsed = self::parse($markup);
        if ($parsed === null) {
            return ['markup' => $markup, 'notes' => []];
        }
        [$roots, $all] = $parsed;

        $notes = [];
        self::addMissingRootLayout($roots, $notes);
        self::promoteAlignClassNames($all, $notes);

        if ($role === self::ROLE_FOOTER) {
            self::widenFooterColumns($roots, $all, $notes);
            self::evenOutFooterRows($all, $notes);
        }
        if ($role === self::ROLE_SECTION || $role === self::ROLE_TEMPLATE) {
            self::freeGridsFromNarrowWrappers($roots, $notes);
            self::restoreCoverMeasure($all, $contentSize, $notes);
        }

        if ($notes === []) {
            return ['markup' => $markup, 'notes' => []];
        }
        return ['markup' => self::render($markup, $all), 'notes' => $notes];
    }

    // ── Rules ────────────────────────────────────────────────────────────

    /**
     * A top-level group with no "layout" attribute is flow, not constrained:
     * no centering, no global padding, so its align:wide children render
     * edge-to-edge at the viewport (tbilisi's "The Cuisine" band). Same
     * contract SectionsStep::constrainedPart enforces for header/footer,
     * applied to every file's root groups.
     *
     * @param object[] $roots
     * @param string[] $notes
     */
    private static function addMissingRootLayout(array $roots, array &$notes): void
    {
        foreach ($roots as $node) {
            if (self::is($node, 'group') && !isset($node->attrs->layout)) {
                $node->attrs->layout = (object) ['type' => 'constrained'];
                $node->dirty = true;
                $notes[] = 'top-level wp:group had no "layout" — added {"type":"constrained"} so children get page gutters instead of rendering edge-to-edge';
            }
        }
    }

    /**
     * An alignwide/alignfull CLASS with no matching "align" ATTRIBUTE styles
     * nothing: WordPress computes widths from the attribute (portfolio's
     * footer declared className:"alignwide" and rendered at content width).
     * Promote the class to the real attribute.
     *
     * @param object[] $all
     * @param string[] $notes
     */
    private static function promoteAlignClassNames(array $all, array &$notes): void
    {
        foreach ($all as $node) {
            if (!self::is($node, 'group') && !self::is($node, 'columns') && !self::is($node, 'cover')
                && !self::is($node, 'gallery') && !self::is($node, 'media-text')) {
                continue;
            }
            $className = $node->attrs->className ?? '';
            $classes = is_string($className) ? (preg_split('/\s+/', trim($className)) ?: []) : [];
            $aligns = array_intersect(['alignfull', 'alignwide'], $classes);
            if ($aligns === []) {
                continue;
            }

            if (!isset($node->attrs->align)) {
                $align = in_array('alignfull', $aligns, true) ? 'full' : 'wide';
                $node->attrs->align = $align;
                $notes[] = "wp:{$node->name} carried an align class with no \"align\" attribute — promoted to \"align\":\"{$align}\"";
            } else {
                $notes[] = "wp:{$node->name} carried an align class alongside an \"align\" attribute — removed the conflicting class token";
            }

            $classes = array_values(array_diff($classes, ['alignfull', 'alignwide']));
            if ($classes === []) {
                unset($node->attrs->className);
            } else {
                $node->attrs->className = implode(' ', $classes);
            }
            $node->dirty = true;
        }
    }

    /**
     * A footer row of 3+ columns does not fit the content width (portfolio2 /
     * tbilisi2 squeezed three columns into 860px and email addresses wrapped
     * mid-word). Widen the columns block — and every plain group wrapping it —
     * so the width can actually flow down from the footer band.
     *
     * @param object[] $roots
     * @param object[] $all
     * @param string[] $notes
     */
    private static function widenFooterColumns(array $roots, array $all, array &$notes): void
    {
        $root = $roots[0] ?? null;
        if ($root === null || !self::is($root, 'group')
            || !in_array(self::align($root), ['wide', 'full'], true)) {
            return; // deliberate content-width footer band — widening inside it is a no-op
        }
        foreach ($all as $node) {
            if (!self::is($node, 'columns') || self::columnCount($node) < 3) {
                continue;
            }
            // Only promote through plain group wrappers; anything else
            // (a column cell, a cover) means this isn't a top-level row.
            $chain = self::plainGroupPathTo($node, $root);
            if ($chain === null) {
                continue;
            }

            if (self::align($node) !== 'wide') {
                $node->attrs->align = 'wide';
                $node->dirty = true;
                $notes[] = 'footer wp:columns with ' . self::columnCount($node) . ' columns did not use the canonical row width — set "align":"wide"';
            }
            foreach ($chain as $wrapper) {
                if (self::align($wrapper) !== 'wide') {
                    $wrapper->attrs->align = 'wide';
                    $wrapper->dirty = true;
                    $notes[] = 'widened the wp:group wrapping the footer columns so the wide width flows down';
                }
            }
        }
    }

    /**
     * Structural footer rows (groups, columns, separators) under one
     * constrained container must share ONE width. When some rows are wide and
     * their siblings sit at content width, two left edges compete on the same
     * surface (portfolio's site-title lockup at 860px beside 1320px link
     * columns; same in naturaleza). Promote the narrow siblings to wide.
     *
     * @param object[] $all
     * @param string[] $notes
     */
    private static function evenOutFooterRows(array $all, array &$notes): void
    {
        foreach ($all as $node) {
            if (!self::is($node, 'group') || self::layoutType($node) !== 'constrained') {
                continue;
            }
            // Only the footer band itself and its immediate wrappers hold
            // structural rows; constrained groups deeper down (inside a
            // column cell, say) are content, not rhythm.
            if ($node->parent !== null && $node->parent->parent !== null) {
                continue;
            }
            $rows = array_values(array_filter(
                $node->children,
                static fn (object $c): bool => self::is($c, 'group') || self::is($c, 'columns') || self::is($c, 'separator')
            ));
            $hasWide = array_filter($rows, static fn (object $c): bool => in_array(self::align($c), ['wide', 'full'], true));
            if ($hasWide === []) {
                continue;
            }
            foreach ($rows as $row) {
                if (self::align($row) !== 'wide') {
                    $row->attrs->align = 'wide';
                    $row->dirty = true;
                    $notes[] = "footer wp:{$row->name} did not use the canonical wide row width — set \"align\":\"wide\" so all rows share one edge";
                }
            }
        }
    }

    /**
     * Grid rows stuck at the reading measure inside a wide band. Two shapes
     * of the same drift:
     *
     * 1. A grid row (multi-column wp:columns, wp:gallery, wp:media-text) is a
     *    direct child of the wide/full band with no "align" of its own, so a
     *    constrained band caps it at contentSize (portfolio's "A Decade of
     *    Turning Points" ran its media-text timeline at 860px inside a 1320px
     *    band). Promote the row to align:wide; text siblings keep the measure.
     *
     * 2. One or more nested group wrappers interrupt that wide context.
     *    Widen each group on the path to the grid and drop any explicit
     *    contentSize cap. Paths stop at non-group component boundaries.
     *
     * @param object[] $roots
     * @param string[] $notes
     */
    private static function freeGridsFromNarrowWrappers(array $roots, array &$notes): void
    {
        $root = $roots[0] ?? null;
        if ($root === null || !self::is($root, 'group')
            || !in_array(self::align($root), ['wide', 'full'], true)
            || self::layoutType($root) !== 'constrained') {
            return;
        }
        self::widenGridPaths($root, $notes);
    }

    /**
     * Widen grid rows reachable through group-only wrapper paths. Each group
     * on a matching path must itself be wide or the descendant's align:wide
     * is still resolved inside a content-width box. Other block boundaries
     * (columns, covers, etc.) end the path so component internals stay local.
     *
     * @param string[] $notes
     */
    private static function widenGridPaths(object $container, array &$notes): bool
    {
        $foundGrid = false;
        foreach ($container->children as $child) {
            if ((self::is($child, 'columns') && self::columnCount($child) >= 2)
                || self::is($child, 'gallery') || self::is($child, 'media-text')) {
                $foundGrid = true;
                if (!isset($child->attrs->align)) {
                    $child->attrs->align = 'wide';
                    $child->dirty = true;
                    $notes[] = "wp:{$child->name} grid row sat at content width — set \"align\":\"wide\" inside the wide band";
                }
                continue;
            }
            if (!self::is($child, 'group') || !self::widenGridPaths($child, $notes)) {
                continue;
            }

            $foundGrid = true;
            $changed = false;
            $removedCap = false;
            $layout = self::layout($child);
            if ($layout !== null && isset($layout->contentSize)) {
                unset($layout->contentSize);
                $changed = true;
                $removedCap = true;
            }
            if (!in_array(self::align($child), ['wide', 'full'], true)) {
                $child->attrs->align = 'wide';
                $changed = true;
            }
            if ($changed) {
                $child->dirty = true;
                $notes[] = $removedCap
                    ? 'widened a wp:group on the path to grid content and removed its explicit contentSize cap'
                    : 'widened a wp:group on the path to grid content so the wide width flows down';
            }
        }
        return $foundGrid;
    }

    /**
     * Inside a wp:cover, a constrained group far below the theme's reading
     * measure squeezes the hero headline into a sliver of the band
     * (portfolio2 pinned its display headline into 640px of an 88vh cover).
     * Drop the override so the cover content falls back to the theme measure.
     *
     * @param object[] $all
     * @param string[] $notes
     */
    private static function restoreCoverMeasure(array $all, ?float $contentSize, array &$notes): void
    {
        if ($contentSize === null) {
            return;
        }
        foreach ($all as $cover) {
            if (!self::is($cover, 'cover')) {
                continue;
            }

            // The first direct group is the cover's primary overlay wrapper;
            // utility blocks such as a leading spacer may precede it. Do not
            // scan descendants: nested cards, captions, and badges can carry
            // an intentionally narrower component-local measure.
            $node = null;
            foreach ($cover->children as $child) {
                if (self::is($child, 'group')) {
                    $node = $child;
                    break;
                }
            }
            if ($node === null) {
                continue;
            }
            $layout = self::layout($node);
            $size = $layout->contentSize ?? null;
            if (self::layoutType($node) !== 'constrained'
                || !is_string($size) || preg_match('/^([0-9.]+)px$/', $size, $m) !== 1) {
                continue;
            }
            if ((float) $m[1] >= $contentSize * self::COVER_MEASURE_FLOOR) {
                continue;
            }
            unset($layout->contentSize);
            $node->dirty = true;
            $notes[] = "cover content was capped at {$size} — removed the override so it uses the theme's contentSize";
        }
    }

    // ── Block-grammar parsing / rendering ────────────────────────────────

    /**
     * Parse markup into a tree of block nodes. Nodes carry the byte range of
     * their opening comment so edits can be spliced back without touching the
     * authored HTML. Returns null (fix becomes a no-op) on anything
     * structurally surprising: unbalanced delimiters or undecodable attribute
     * JSON — those are the block-fixer's and validator's problems, and
     * rewriting attributes we couldn't fully read would destroy them.
     *
     * @return array{0: object[], 1: object[]}|null [$roots, $allOpenNodes]
     */
    private static function parse(string $markup): ?array
    {
        $re = '/<!--\s*(\/)?wp:([a-z][a-z0-9_\/-]*)\s*(\{.*?\})?\s*(\/)?-->/s';
        if (preg_match_all($re, $markup, $tokens, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return null;
        }
        $roots = [];
        $all = [];
        $stack = [];
        foreach ($tokens as $t) {
            $isClose = ($t[1][0] ?? '') === '/';
            $name = $t[2][0];
            if ($isClose) {
                $open = array_pop($stack);
                if ($open === null || $open->name !== $name) {
                    return null;
                }
                continue;
            }
            $attrs = new \stdClass();
            if (isset($t[3]) && $t[3][1] !== -1 && $t[3][0] !== '') {
                $attrs = json_decode($t[3][0]);
                if (!$attrs instanceof \stdClass) {
                    return null;
                }
            }
            $node = (object) [
                'name'        => $name,
                'attrs'       => $attrs,
                'selfClosing' => ($t[4][0] ?? '') === '/',
                'start'       => $t[0][1],
                'len'         => strlen($t[0][0]),
                'parent'      => end($stack) ?: null,
                'children'    => [],
                'dirty'       => false,
            ];
            if ($node->parent !== null) {
                $node->parent->children[] = $node;
            } else {
                $roots[] = $node;
            }
            $all[] = $node;
            if (!$node->selfClosing) {
                $stack[] = $node;
            }
        }
        return $stack === [] ? [$roots, $all] : null;
    }

    /**
     * Splice every dirty node's re-encoded opening comment back into the
     * original markup. Only the comment JSON changes; the following HTML is
     * left as authored — the Node block-fixer re-serializes it from these
     * attributes right after.
     *
     * @param object[] $all
     */
    private static function render(string $markup, array $all): string
    {
        $dirty = array_values(array_filter($all, static fn (object $n): bool => $n->dirty));
        usort($dirty, static fn (object $a, object $b): int => $b->start <=> $a->start);
        foreach ($dirty as $n) {
            $json = get_object_vars($n->attrs) === []
                ? ''
                : ' ' . json_encode($n->attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $comment = '<!-- wp:' . $n->name . $json . ($n->selfClosing ? ' /-->' : ' -->');
            $markup = substr_replace($markup, $comment, $n->start, $n->len);
        }
        return $markup;
    }

    // ── Small predicates ─────────────────────────────────────────────────

    /** Block-name match tolerating the optional core/ namespace. */
    private static function is(object $node, string $short): bool
    {
        return $node->name === $short || $node->name === 'core/' . $short;
    }

    private static function columnCount(object $columns): int
    {
        return count(array_filter(
            $columns->children,
            static fn (object $c): bool => self::is($c, 'column')
        ));
    }

    /** Attribute align value, normalized to an empty string when absent. */
    private static function align(object $node): string
    {
        $align = $node->attrs->align ?? '';
        return is_string($align) ? $align : '';
    }

    /** Layout object when the attribute has the expected JSON shape. */
    private static function layout(object $node): ?object
    {
        $layout = $node->attrs->layout ?? null;
        return $layout instanceof \stdClass ? $layout : null;
    }

    private static function layoutType(object $node): string
    {
        $type = self::layout($node)?->type ?? '';
        return is_string($type) ? $type : '';
    }

    /**
     * Group ancestors from $node's parent up to but excluding $root, or null
     * when another block boundary intervenes or the node is under another
     * top-level root.
     *
     * @return object[]|null
     */
    private static function plainGroupPathTo(object $node, object $root): ?array
    {
        $path = [];
        for ($p = $node->parent; $p !== null && $p !== $root; $p = $p->parent) {
            if (!self::is($p, 'group')) {
                return null;
            }
            $path[] = $p;
        }
        return $p === $root ? $path : null;
    }
}
