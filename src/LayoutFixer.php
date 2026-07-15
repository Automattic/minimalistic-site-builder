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
 * The same drift shows up in vertical rhythm: a model declares a spacing
 * value in both the inline HTML and the comment JSON but at the wrong
 * attribute path (style.margin instead of style.spacing.margin), or only in
 * the HTML — WordPress ignores it either way, re-serialization drops the
 * CSS, and the fix-blocks rhythm gate rejects the whole build (tbilisi24/25
 * eval runs, BIGR-674 case 1). Those spellings are canonicalized here too.
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
        $notes = [];
        $markup = self::repairPrematurelyClosedAttributes($markup, $notes);
        $parsed = self::parse($markup);
        if ($parsed === null) {
            return ['markup' => $markup, 'notes' => $notes];
        }
        [$roots, $all] = $parsed;

        self::canonicalizeSpacingAttributes($all, $notes);
        self::mirrorHtmlOnlyVerticalSpacing($markup, $all, $notes);
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
     * Spacing attributes at the wrong nesting path style nothing: WordPress
     * reads padding/margin from style.spacing.*, so a "padding" or "margin"
     * key written directly under "style" is ignored, and re-serialization
     * drops the matching inline CSS the model duplicated into the HTML —
     * which the fix-blocks rhythm gate turns into a failed build (tbilisi25
     * wrote card margins as style.margin; tbilisi24 wrote style.padding).
     * The intent is unambiguous — the value was declared, only the path is
     * wrong — so move the key to its canonical location. Sides already
     * declared at the canonical path win: on section roots those are owned
     * by SectionRhythm, and this pass must never reintroduce spacing that
     * the page-level rhythm owner deliberately set.
     *
     * @param object[] $all
     * @param string[] $notes
     */
    private static function canonicalizeSpacingAttributes(array $all, array &$notes): void
    {
        foreach ($all as $node) {
            $style = $node->attrs->style ?? null;
            if (!$style instanceof \stdClass) {
                continue;
            }
            $spacing = $style->spacing ?? null;
            foreach (['padding', 'margin'] as $property) {
                if (!property_exists($style, $property)) {
                    continue;
                }
                if ($spacing !== null && !$spacing instanceof \stdClass) {
                    continue; // unrecognizable spacing shape — leave it for the gate
                }
                $misplaced = $style->{$property};
                unset($style->{$property});
                $node->dirty = true;

                if ($spacing === null) {
                    $spacing = $style->spacing = new \stdClass();
                }
                if (!property_exists($spacing, $property)) {
                    $spacing->{$property} = $misplaced;
                    $notes[] = "wp:{$node->name} declared \"{$property}\" directly under \"style\" where WordPress ignores it — moved to style.spacing.{$property}";
                    continue;
                }
                $canonical = $spacing->{$property};
                if ($canonical instanceof \stdClass && $misplaced instanceof \stdClass) {
                    $adopted = [];
                    foreach (['top', 'right', 'bottom', 'left'] as $side) {
                        if (property_exists($misplaced, $side) && !property_exists($canonical, $side)) {
                            $canonical->{$side} = $misplaced->{$side};
                            $adopted[] = $side;
                        }
                    }
                    $notes[] = $adopted === []
                        ? "wp:{$node->name} declared \"{$property}\" directly under \"style\" — dropped it in favor of the existing style.spacing.{$property}"
                        : "wp:{$node->name} declared \"{$property}\" directly under \"style\" where WordPress ignores it — merged " . implode('/', $adopted) . " into style.spacing.{$property}";
                    continue;
                }
                $notes[] = "wp:{$node->name} declared \"{$property}\" directly under \"style\" — dropped it in favor of the existing style.spacing.{$property}";
            }
        }
    }

    /**
     * A vertical padding/margin declaration present ONLY in a container's
     * inline HTML (no attribute anywhere, canonical or misplaced) is deleted
     * by re-serialization and rejected by the rhythm gate. Mirror-copy the
     * declared value into style.spacing.* — the inverse of the Node fixer's
     * sync direction — so the authored rhythm survives (BIGR-674 case 1).
     * Only sides absent from the attributes are copied: a declared attribute
     * (including SectionRhythm's owned root values, which that pass also
     * rewrites into the HTML) stays authoritative. Limited to the container
     * blocks that carry rhythm here and whose spacing support serializes
     * these longhands; a string spacing shorthand cannot gain sides, so it
     * is left alone for the gate to judge.
     *
     * @param object[] $all
     * @param string[] $notes
     */
    private static function mirrorHtmlOnlyVerticalSpacing(string $markup, array $all, array &$notes): void
    {
        foreach ($all as $node) {
            if ($node->selfClosing
                || (!self::is($node, 'group') && !self::is($node, 'columns')
                    && !self::is($node, 'column') && !self::is($node, 'cover'))) {
                continue;
            }
            $style = $node->attrs->style ?? null;
            if ($style !== null && !$style instanceof \stdClass) {
                continue;
            }
            $tagHtml = self::wrapperTag($markup, $node->start + $node->len);
            $styleAttr = $tagHtml === null ? null : self::tagAttribute($tagHtml, 'style');
            if ($styleAttr === null) {
                continue;
            }

            foreach (explode(';', $styleAttr[0]) as $segment) {
                $colon = strpos($segment, ':');
                if ($colon === false
                    || preg_match('/\A(padding|margin)-(top|bottom)\z/', strtolower(trim(substr($segment, 0, $colon))), $m) !== 1) {
                    continue;
                }
                $value = trim(substr($segment, $colon + 1));
                if ($value === '') {
                    continue;
                }
                [, $property, $side] = $m;
                if ($style === null) {
                    $style = $node->attrs->style = new \stdClass();
                }
                $spacing = $style->spacing ??= new \stdClass();
                if (!$spacing instanceof \stdClass) {
                    continue;
                }
                $box = $spacing->{$property} ??= new \stdClass();
                if (!$box instanceof \stdClass || property_exists($box, $side)) {
                    continue;
                }
                $box->{$side} = self::blockSpacingValue($value);
                $node->dirty = true;
                $notes[] = "wp:{$node->name} carried {$property}-{$side} only in its inline HTML — mirrored it into style.spacing.{$property}.{$side} so re-serialization keeps the declared rhythm";
            }
        }
    }

    /**
     * A top-level group with no "layout" attribute is flow, not constrained:
     * no centering, no global padding, so its align:wide children render
     * edge-to-edge at the viewport (tbilisi's "The Cuisine" band). Same
     * contract Units\GeneratedMarkup::constrainedPart enforces for header/footer,
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
     * Structural footer rows under one constrained container must share ONE
     * width. When some rows are wide and their siblings sit at content width,
     * two left edges compete on the same surface (portfolio's site-title
     * lockup at 860px beside 1320px link columns; same in naturaleza).
     * Promote the narrow siblings to wide. A wide constrained wrapper also
     * passes that width to its direct leaf rows: without their own align:wide,
     * site-title/paragraph/separator children still fall back to contentSize.
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
            $isWideWrapper = $node->parent !== null
                && in_array(self::align($node), ['wide', 'full'], true);
            if ($isWideWrapper && !self::widenFooterLeafRows($node, $notes)) {
                continue;
            }
            $rows = array_values(array_filter($node->children, self::isFooterStructuralRow(...)));
            $hasWide = array_filter($rows, static fn (object $c): bool => in_array(self::align($c), ['wide', 'full'], true));
            if (!$isWideWrapper && $hasWide === []) {
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

    /** One block-comment delimiter: closer flag, name, attrs JSON, void flag. */
    private const TOKEN_RE = '/<!--\s*(\/)?wp:([a-z][a-z0-9_\/-]*)\s*(\{.*?\})?\s*(\/)?-->/s';

    /**
     * Repair attribute JSON whose object closes early: a stray `}` splits the
     * opener into a valid prefix and dangling members (tbilisi24 wrote
     * `{"style":{...,"padding":{...}}},"layout":{...}}`), so json_decode fails,
     * this fixer skips the whole file, and the Node fixer erases EVERY
     * attribute of the block — the fatal rhythm drop plus silent losses.
     * The failure is mechanical, so the repair can be too: while the payload
     * is invalid and a closer returns the nesting depth to zero with content
     * still remaining (or goes below zero), delete that one closer and try
     * again. The rewrite is kept only when the result decodes to an object;
     * anything less clear-cut stays untouched for the gate to reject.
     *
     * @param string[] $notes
     */
    private static function repairPrematurelyClosedAttributes(string $markup, array &$notes): string
    {
        if (preg_match_all(self::TOKEN_RE, $markup, $tokens, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return $markup;
        }
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            $t = $tokens[$i];
            if (!isset($t[3]) || $t[3][1] === -1 || $t[3][0] === '') {
                continue;
            }
            $json = $t[3][0];
            if (json_decode($json) instanceof \stdClass) {
                continue;
            }
            $repaired = self::withoutPrematureClosers($json);
            if ($repaired === null) {
                continue;
            }
            $markup = substr_replace($markup, $repaired, $t[3][1], strlen($json));
            $notes[] = "wp:{$t[2][0]} attributes closed their JSON object early — removed the stray closer(s) so the declared attributes parse instead of being erased";
        }
        return $markup;
    }

    /** The repaired JSON payload, or null when no safe single-char fix exists. */
    private static function withoutPrematureClosers(string $json): ?string
    {
        for ($deletions = 0; $deletions < 2; $deletions++) {
            $offset = self::prematureCloserOffset($json);
            if ($offset === null) {
                return null;
            }
            $json = substr_replace($json, '', $offset, 1);
            if (json_decode($json) instanceof \stdClass) {
                return $json;
            }
        }
        return null;
    }

    /**
     * Byte offset of the first closer that returns brace/bracket depth to zero
     * while non-whitespace content remains, or takes it negative. Null when
     * the payload balances (its invalidity is not this shape).
     */
    private static function prematureCloserOffset(string $json): ?int
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
                if ($depth < 0) {
                    return $i;
                }
                if ($depth === 0 && trim(substr($json, $i + 1)) !== '') {
                    return $i;
                }
            }
        }
        return null;
    }

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
        if (preg_match_all(self::TOKEN_RE, $markup, $tokens, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
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
            // `--` must not appear raw inside an HTML comment; escape it the
            // way Gutenberg's serializer (and SectionRhythm::encodeAttrs) does.
            $json = get_object_vars($n->attrs) === []
                ? ''
                : ' ' . str_replace(
                    '--',
                    '\\u002d\\u002d',
                    json_encode($n->attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                );
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

    /** Container-like blocks that establish structural footer rows. */
    private static function isFooterStructuralRow(object $node): bool
    {
        foreach (['group', 'columns', 'separator'] as $name) {
            if (self::is($node, $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A depth-one align:wide group can still constrain its direct children to
     * contentSize. Promote known wide-capable leaf rows without changing the
     * wrapper's layout semantics. An explicit left/center/right child align is
     * treated as an intentional composition, so the entire wrapper is left
     * alone instead of widening only some of its rows.
     *
     * @param string[] $notes
     */
    private static function widenFooterLeafRows(object $wrapper, array &$notes): bool
    {
        foreach ($wrapper->children as $child) {
            if (in_array(self::align($child), ['left', 'center', 'right'], true)) {
                return false;
            }
            if (self::is($child, 'group') || self::is($child, 'columns') || self::is($child, 'cover')
                || self::is($child, 'gallery') || self::is($child, 'media-text')) {
                return false;
            }
        }

        foreach ($wrapper->children as $child) {
            if (!self::isFooterWideLeaf($child) || self::align($child) !== '') {
                continue;
            }
            $child->attrs->align = 'wide';
            $child->dirty = true;
            $notes[] = "footer wp:{$child->name} stayed at content width inside a wide constrained wrapper — set \"align\":\"wide\" so it shares the wrapper edge";
        }
        return true;
    }

    /** Direct footer leaves whose registered block support includes wide. */
    private static function isFooterWideLeaf(object $node): bool
    {
        foreach (['site-title', 'paragraph', 'separator', 'heading', 'navigation', 'buttons'] as $name) {
            if (self::is($node, $name)) {
                return true;
            }
        }
        return false;
    }

    /** Convert a rendered preset variable back to block-attribute syntax. */
    private static function blockSpacingValue(string $value): string
    {
        return preg_match('/^var\(--wp--preset--spacing--([a-z0-9_-]+)\)$/', $value, $match) === 1
            ? "var:preset|spacing|{$match[1]}"
            : $value;
    }

    /**
     * The first HTML element immediately following a block opener (mirrors
     * SectionRhythm::wrapperTag — this class must stay usable on markup that
     * pass rejects, so it keeps its own copy of the scanner).
     */
    private static function wrapperTag(string $markup, int $searchOffset): ?string
    {
        $rest = substr($markup, $searchOffset);
        if (preg_match('/\A\s*<[a-zA-Z][a-zA-Z0-9-]*(?=[\x20\t\r\n\f\/>])/', $rest, $start) !== 1) {
            return null;
        }

        $quote = null;
        $length = strlen($rest);
        for ($i = strlen($start[0]); $i < $length; $i++) {
            $char = $rest[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '>') {
                return substr($rest, 0, $i + 1);
            }
        }
        return null;
    }

    /** @return array{string,int}|null attribute value and its byte offset inside the tag */
    private static function tagAttribute(string $tagHtml, string $name): ?array
    {
        $pattern = '/[\x20\t\r\n\f]' . preg_quote($name, '/')
            . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i';
        if (preg_match($pattern, $tagHtml, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        return ($match[1][1] ?? -1) !== -1 ? $match[1] : $match[2];
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
