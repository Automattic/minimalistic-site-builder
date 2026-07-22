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
 * editing the comment JSON attributes, and leaves the authored HTML
 * untouched — it is meant to run immediately BEFORE the Node block-fixer,
 * which re-serializes every block from its comment attributes and thereby
 * syncs the HTML (align classes etc.) with what this pass wrote. The one
 * exception is inline gap CSS (see mirrorHtmlOnlyGap): blockGap is rendered
 * by the layout-support stylesheet, never re-emitted inline, so the moved
 * declaration must also be deleted from the HTML or the dropped-content
 * diff would still count it as a loss.
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
    public static function fix(string $markup, string $role, ?float $contentSize = null, array $spacingSlugs = []): array
    {
        $notes = [];
        $markup = self::repairPrematurelyClosedAttributes($markup, $notes);
        $parsed = self::parse($markup);
        if ($parsed === null) {
            return ['markup' => $markup, 'notes' => $notes];
        }
        [$roots, $all] = $parsed;

        $htmlEdits = [];
        self::canonicalizeSpacingAttributes($all, $notes);
        self::repairBareSlugSpacing($markup, $all, $notes, $spacingSlugs);
        self::mirrorHtmlOnlyVerticalSpacing($markup, $all, $notes);
        self::mirrorHtmlOnlyGap($markup, $all, $htmlEdits, $notes);
        self::mirrorDynamicChromeSpacing($markup, $all, $htmlEdits, $notes);
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
        return ['markup' => self::render($markup, $all, $htmlEdits), 'notes' => $notes];
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
     * Bare values that are (or could be) legitimate CSS for spacing — never
     * treated as preset slugs even if a theme pathologically names one so.
     */
    private const CSS_SPACING_KEYWORDS = ['0', 'auto', 'inherit', 'initial', 'unset', 'revert', 'revert-layer', 'none', 'normal'];

    /**
     * A spacing value holding a bare preset slug ("top":"sm") renders as the
     * literal `padding-top:sm`, so re-serialization replaces the HTML's real
     * var() declaration and the rhythm gate rejects the build (observed on
     * cards, paragraphs, separators and quotes across demo builds). Rewrite
     * to var:preset|spacing|<slug> when the theme's spacing scale defines the
     * slug — any block type, since the model's intent is unambiguous. Without
     * a scale, fall back to requiring the block's own inline HTML to declare
     * exactly var(--wp--preset--spacing--<slug>) for that side, trusted only
     * for wrapper-classed containers. Anything else stays for the gate.
     *
     * @param object[] $all
     * @param string[] $notes
     * @param string[] $spacingSlugs theme spacing-scale slugs ([] = unknown)
     */
    private static function repairBareSlugSpacing(string $markup, array $all, array &$notes, array $spacingSlugs = []): void
    {
        $slugs = array_diff($spacingSlugs, self::CSS_SPACING_KEYWORDS);
        foreach ($all as $node) {
            $spacing = $node->attrs->style->spacing ?? null;
            if (!$spacing instanceof \stdClass) {
                continue;
            }

            // HTML-confirmation fallback: only wrapper-classed containers are
            // trusted, matching the mirror rules' guard.
            $declared = null;
            $trustsHtml = !$node->selfClosing
                && (self::is($node, 'group') || self::is($node, 'columns')
                    || self::is($node, 'column') || self::is($node, 'cover'));
            if ($trustsHtml) {
                $short = preg_replace('#^core/#', '', $node->name);
                $tagHtml = self::wrapperTag($markup, $node->start + $node->len);
                if ($tagHtml !== null && self::hasClassToken($tagHtml, "wp-block-{$short}")) {
                    $styleAttr = self::tagAttribute($tagHtml, 'style');
                    if ($styleAttr !== null) {
                        $declared = [];
                        foreach (explode(';', $styleAttr[0]) as $segment) {
                            $colon = strpos($segment, ':');
                            if ($colon !== false) {
                                $declared[strtolower(trim(substr($segment, 0, $colon)))] = trim(substr($segment, $colon + 1));
                            }
                        }
                    }
                }
            }

            $bareSlug = static function (mixed $value) use ($slugs): ?string {
                return is_string($value)
                    && preg_match('/^[a-z0-9_-]+$/', $value) === 1
                    && !in_array($value, self::CSS_SPACING_KEYWORDS, true)
                    && in_array($value, $slugs, true)
                    ? $value : null;
            };

            foreach (['padding', 'margin'] as $property) {
                $box = $spacing->{$property} ?? null;
                if (!$box instanceof \stdClass) {
                    continue;
                }
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    $value = $box->{$side} ?? null;
                    if (!is_string($value) || preg_match('/^[a-z0-9_-]+$/', $value) !== 1) {
                        continue;
                    }
                    $scaleConfirms = $bareSlug($value) !== null;
                    $htmlConfirms = ($declared["{$property}-{$side}"] ?? null) === "var(--wp--preset--spacing--{$value})";
                    if (!$scaleConfirms && !$htmlConfirms) {
                        continue;
                    }
                    $box->{$side} = "var:preset|spacing|{$value}";
                    $node->dirty = true;
                    $notes[] = "wp:{$node->name} {$property}.{$side} held the bare slug \"{$value}\" ("
                        . ($scaleConfirms ? 'defined by the theme spacing scale' : 'matching its inline HTML preset')
                        . ") — rewrote it to var:preset|spacing|{$value} so the rendered CSS keeps the declared rhythm";
                }
            }

            // blockGap renders through the layout stylesheet; a bare slug
            // there becomes invisible invalid CSS rather than a gate failure,
            // so only the scale (never HTML) can confirm it.
            $blockGap = $spacing->blockGap ?? null;
            if (is_string($blockGap) && $bareSlug($blockGap) !== null) {
                $spacing->blockGap = "var:preset|spacing|{$blockGap}";
                $node->dirty = true;
                $notes[] = "wp:{$node->name} blockGap held the bare slug \"{$blockGap}\" (defined by the theme spacing scale) — rewrote it to var:preset|spacing|{$blockGap}";
            } elseif ($blockGap instanceof \stdClass) {
                foreach (['top', 'left'] as $side) {
                    $value = $blockGap->{$side} ?? null;
                    if (is_string($value) && $bareSlug($value) !== null) {
                        $blockGap->{$side} = "var:preset|spacing|{$value}";
                        $node->dirty = true;
                        $notes[] = "wp:{$node->name} blockGap.{$side} held the bare slug \"{$value}\" (defined by the theme spacing scale) — rewrote it to var:preset|spacing|{$value}";
                    }
                }
            }
        }
    }

    /**
     * Dynamic chrome leaf blocks: empty save(), so authored HTML never
     * survives re-serialization. All three support spacing block supports,
     * which render style.spacing.* at runtime.
     */
    private const DYNAMIC_CHROME_BLOCKS = ['site-title', 'site-tagline', 'site-logo'];

    /**
     * A dynamic chrome block the model expanded into HTML loses that HTML
     * wholesale at re-serialization, and inline spacing on it trips the
     * rhythm gate even when the attribute already carries the value (block
     * supports render it at runtime). Move inline padding/margin longhands
     * into style.spacing.* and delete the inline copies; a disagreeing
     * attribute is ambiguous and stays for the gate to judge.
     *
     * @param object[] $all
     * @param array{int,int,string}[] $htmlEdits splices for render(): offset, length, replacement
     * @param string[] $notes
     */
    private static function mirrorDynamicChromeSpacing(string $markup, array $all, array &$htmlEdits, array &$notes): void
    {
        foreach ($all as $node) {
            $short = preg_replace('#^core/#', '', $node->name);
            if ($node->selfClosing || !in_array($short, self::DYNAMIC_CHROME_BLOCKS, true)) {
                continue;
            }
            $tagStart = $node->start + $node->len;
            $tagHtml = self::wrapperTag($markup, $tagStart);
            if ($tagHtml === null || !self::hasClassToken($tagHtml, "wp-block-{$short}")) {
                continue;
            }
            $styleAttr = self::tagAttribute($tagHtml, 'style');
            if ($styleAttr === null) {
                continue;
            }
            $style = $node->attrs->style ?? null;
            if ($style !== null && !$style instanceof \stdClass) {
                continue;
            }
            $spacing = $style?->spacing ?? null;
            if ($spacing !== null && !$spacing instanceof \stdClass) {
                continue;
            }

            $kept = [];
            $declared = [];
            $unmirrorable = false;
            foreach (explode(';', $styleAttr[0]) as $segment) {
                if (trim($segment) === '') {
                    continue;
                }
                $colon = strpos($segment, ':');
                $prop = $colon === false ? '' : strtolower(trim(substr($segment, 0, $colon)));
                if (preg_match('/\A(padding|margin)-(top|right|bottom|left)\z/', $prop, $m) !== 1) {
                    $kept[] = trim($segment);
                    continue;
                }
                $value = trim(substr($segment, $colon + 1));
                if ($value === '' || self::referencesNonPresetVar($value)) {
                    $unmirrorable = true;
                    break;
                }
                $declared[$m[1]][$m[2]] = self::blockSpacingValue($value);
            }
            if ($unmirrorable || $declared === []) {
                continue;
            }

            $adopt = [];
            $conflict = false;
            foreach ($declared as $property => $sides) {
                $box = $spacing?->{$property} ?? null;
                if ($box !== null && !$box instanceof \stdClass) {
                    $conflict = true; // string shorthand attribute — ambiguous
                    break;
                }
                foreach ($sides as $side => $value) {
                    $current = $box !== null && property_exists($box, $side) ? $box->{$side} : null;
                    if ($current === null) {
                        $adopt[$property][$side] = $value;
                    } elseif ($current !== $value) {
                        $conflict = true;
                        break 2;
                    }
                }
            }
            if ($conflict) {
                continue;
            }

            if ($adopt !== []) {
                $style ??= $node->attrs->style = new \stdClass();
                $spacing ??= $style->spacing = new \stdClass();
                foreach ($adopt as $property => $sides) {
                    $box = $spacing->{$property} ??= new \stdClass();
                    foreach ($sides as $side => $value) {
                        $box->{$side} = $value;
                    }
                }
                $node->dirty = true;
            }
            $htmlEdits[] = [$tagStart + $styleAttr[1], strlen($styleAttr[0]), implode(';', $kept)];
            $notes[] = $adopt !== []
                ? "wp:{$node->name} declared spacing only in HTML that re-serialization deletes (dynamic block, empty save) — moved it into style.spacing so block supports render it"
                : "wp:{$node->name} duplicated its spacing attributes as inline CSS that re-serialization deletes — removed the doomed inline copy";
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
     * is left alone for the gate to judge. The wrapper element is trusted
     * only when its class list carries the block's own wp-block-* token —
     * a bare child element (a lone <p> directly under a wrapperless group)
     * must not donate its spacing to the block, which would let Gutenberg
     * regenerate the block as an empty styled wrapper and delete the child.
     * Values resolved through wrapper-local custom properties are refused
     * too: re-serialization drops the --definition while the mirror would
     * keep the reference alive, shipping an unresolvable value the rhythm
     * gate can no longer see.
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
            if ($tagHtml === null
                || !self::hasClassToken($tagHtml, 'wp-block-' . preg_replace('#^core/#', '', $node->name))) {
                continue;
            }
            $styleAttr = self::tagAttribute($tagHtml, 'style');
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
                if ($value === '' || self::referencesNonPresetVar($value)) {
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
     * A gap declared in a container's inline HTML never survives: WordPress
     * renders child spacing from style.spacing.blockGap through the
     * layout-support stylesheet and its save() output carries no inline gap
     * at all, so re-serialization deletes the declaration whether or not a
     * blockGap attribute exists — and the rhythm gate rejects the build
     * (tbilisi31/naturaleza32 wrote gap:var(--wp--preset--spacing--*) on
     * wp-block-columns). Move the declared value into blockGap and delete
     * the inline copy; when a blockGap attribute already carries the same
     * value the inline copy is a doomed duplicate and is just deleted. A
     * disagreeing blockGap is ambiguous, so the node is left for the gate
     * to judge. Unlike the padding/margin mirror this rule must edit the
     * HTML: Gutenberg will never re-emit the declaration, so an inline copy
     * left behind is itself the dropped-CSS report. The wrapper element is
     * only trusted when its class list carries the block's own wp-block-*
     * token, so a bare child element cannot donate its gap.
     *
     * @param object[] $all
     * @param array{int,int,string}[] $htmlEdits splices for render(): offset, length, replacement
     * @param string[] $notes
     */
    private static function mirrorHtmlOnlyGap(string $markup, array $all, array &$htmlEdits, array &$notes): void
    {
        foreach ($all as $node) {
            if ($node->selfClosing || (!self::is($node, 'group') && !self::is($node, 'columns'))) {
                continue;
            }
            $style = $node->attrs->style ?? null;
            if ($style !== null && !$style instanceof \stdClass) {
                continue;
            }
            $spacing = $style?->spacing ?? null;
            if ($spacing !== null && !$spacing instanceof \stdClass) {
                continue;
            }
            $tagStart = $node->start + $node->len;
            $tagHtml = self::wrapperTag($markup, $tagStart);
            if ($tagHtml === null
                || !self::hasClassToken($tagHtml, 'wp-block-' . preg_replace('#^core/#', '', $node->name))) {
                continue;
            }
            $styleAttr = self::tagAttribute($tagHtml, 'style');
            if ($styleAttr === null) {
                continue;
            }

            $declared = self::declaredGapSides($styleAttr[0], $kept);
            if ($declared === null || $declared === []) {
                continue;
            }

            $blockGap = $spacing?->blockGap ?? null;
            $current = ['top' => null, 'left' => null];
            if (is_string($blockGap)) {
                $current = ['top' => $blockGap, 'left' => $blockGap];
            } elseif ($blockGap instanceof \stdClass) {
                foreach (['top', 'left'] as $side) {
                    $current[$side] = property_exists($blockGap, $side) ? $blockGap->{$side} : null;
                }
            } elseif ($blockGap !== null) {
                continue; // unrecognizable blockGap shape — leave it for the gate
            }

            $adopt = [];
            $conflict = false;
            foreach ($declared as $side => $value) {
                if ($current[$side] === null) {
                    $adopt[$side] = $value;
                } elseif ($current[$side] !== $value) {
                    $conflict = true;
                }
            }
            if ($conflict) {
                continue; // declared attribute disagrees with the inline CSS — ambiguous
            }

            if ($adopt !== []) {
                $style ??= $node->attrs->style = new \stdClass();
                $spacing ??= $style->spacing = new \stdClass();
                if ($blockGap instanceof \stdClass) {
                    foreach ($adopt as $side => $value) {
                        $blockGap->{$side} = $value;
                    }
                } else {
                    $spacing->blockGap = ($adopt['top'] ?? null) === ($adopt['left'] ?? null)
                        ? $adopt['top']
                        : (object) $adopt;
                }
                $node->dirty = true;
            }

            $htmlEdits[] = [$tagStart + $styleAttr[1], strlen($styleAttr[0]), implode(';', $kept)];
            $notes[] = $adopt !== []
                ? "wp:{$node->name} carried its child gap only in inline CSS that re-serialization deletes — moved it into style.spacing.blockGap"
                : "wp:{$node->name} duplicated its blockGap attribute as inline gap CSS that re-serialization deletes — removed the redundant inline copy";
        }
    }

    /**
     * Gap declarations from one style attribute, in blockGap side terms
     * (top = row gap, left = column gap; later CSS declarations win). $kept
     * receives every non-gap segment verbatim. Null when a gap value is
     * present but outside what blockGap can express losslessly (comma'd
     * functions, 3+ value shorthands, references to wrapper-local custom
     * properties whose definitions re-serialization would drop) — the
     * caller must then leave the node for the gate.
     *
     * @param string[] $kept
     * @return array{top?: string, left?: string}|null empty array = no gap declared
     */
    private static function declaredGapSides(string $styleValue, ?array &$kept): ?array
    {
        $kept = [];
        $declared = [];
        foreach (explode(';', $styleValue) as $segment) {
            $colon = strpos($segment, ':');
            $property = $colon === false ? '' : strtolower(trim(substr($segment, 0, $colon)));
            if (!in_array($property, ['gap', 'row-gap', 'column-gap'], true)) {
                if (trim($segment) !== '') {
                    $kept[] = $segment;
                }
                continue;
            }
            $value = trim(substr($segment, $colon + 1));
            $parts = preg_split('/\s+/', $value) ?: [];
            if ($value === '' || str_contains($value, ',') || self::referencesNonPresetVar($value)
                || count($parts) > 2 || ($property !== 'gap' && count($parts) !== 1)) {
                return null;
            }
            if ($property !== 'column-gap') {
                $declared['top'] = self::blockSpacingValue($property === 'gap' ? $parts[0] : $value);
            }
            if ($property !== 'row-gap') {
                $declared['left'] = self::blockSpacingValue($property === 'gap' ? ($parts[1] ?? $parts[0]) : $value);
            }
        }
        return $declared;
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

    /**
     * One block-comment delimiter: closer flag, name, attrs JSON, void flag.
     * The attrs scan must not cross a `-->` boundary: valid serialized JSON
     * escapes `--` as \u002d\u002d so nothing legitimate is cut off, while a
     * malformed opener (say, an unterminated attrs object) fails to match on
     * its own instead of swallowing a later, independently repairable one.
     */
    private const TOKEN_RE = '/<!--\s*(\/)?wp:([a-z][a-z0-9_\/-]*)\s*(\{(?:(?!-->).)*?\})?\s*(\/)?-->/s';

    /**
     * Repair attribute JSON containing a stray closer: an extra `}` splits
     * the opener into a valid prefix and dangling members (tbilisi24 wrote
     * `{"style":{...,"padding":{...}}},"layout":{...}}`), so json_decode fails,
     * this fixer skips the whole file, and the Node fixer erases EVERY
     * attribute of the block — the fatal rhythm drop plus silent losses.
     * The repair stays mechanical by refusing to guess: every single-closer
     * deletion is tried (see withoutPrematureClosers), and the rewrite is
     * kept only when exactly one distinct valid object can result. Anything
     * ambiguous stays untouched for the gate to reject.
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

    /**
     * The repaired JSON payload, or null when no UNAMBIGUOUS closer deletion
     * exists. Every single-closer deletion is enumerated (then two-closer
     * ones, only when no single deletion parses); a candidate counts when it
     * decodes to an object AND declares no same-depth duplicate keys — which
     * json_decode would silently merge, keeping only the last value, so an
     * apparently clean repair could still lose members. The rewrite is kept
     * only when all surviving candidates agree on ONE decoded result: several
     * distinct valid readings mean the authoring error has no mechanical
     * story to recover, and the block is left for the gate to reject.
     */
    private static function withoutPrematureClosers(string $json): ?string
    {
        $payloads = [$json];
        for ($deletions = 0; $deletions < 2; $deletions++) {
            $results = [];
            $stillInvalid = [];
            foreach ($payloads as $payload) {
                foreach (self::closerOffsets($payload) as $offset) {
                    $candidate = substr_replace($payload, '', $offset, 1);
                    $decoded = json_decode($candidate);
                    if (!$decoded instanceof \stdClass) {
                        $stillInvalid[$candidate] = true;
                        continue;
                    }
                    if (!self::hasSameDepthDuplicateKeys($candidate)) {
                        $results[json_encode($decoded)] = $candidate;
                    }
                }
            }
            if ($results !== []) {
                return count($results) === 1 ? reset($results) : null;
            }
            $payloads = array_keys($stillInvalid);
            if (count($payloads) > 1024) {
                return null; // runaway enumeration — not a plausible attrs payload
            }
        }
        return null;
    }

    /** @return int[] byte offsets of every `}` / `]` outside string literals */
    private static function closerOffsets(string $json): array
    {
        $offsets = [];
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
            } elseif ($char === '}' || $char === ']') {
                $offsets[] = $i;
            }
        }
        return $offsets;
    }

    /**
     * Whether a syntactically valid JSON payload declares the same key twice
     * in one object. PHP's json_decode accepts duplicates and keeps the last,
     * so this must be detected on the raw text before trusting a candidate.
     * Assumes valid JSON (candidates are checked with json_decode first).
     */
    private static function hasSameDepthDuplicateKeys(string $json): bool
    {
        $frames = []; // one key-set per open object; null marks an array
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            if ($char === '"') {
                $start = ++$i;
                while ($i < $length && $json[$i] !== '"') {
                    $i += $json[$i] === '\\' ? 2 : 1;
                }
                $next = $i + 1;
                while ($next < $length && trim($json[$next]) === '') {
                    $next++;
                }
                $top = count($frames) - 1;
                if (($json[$next] ?? '') === ':' && $top >= 0 && $frames[$top] !== null) {
                    $key = substr($json, $start, $i - $start);
                    if (isset($frames[$top][$key])) {
                        return true;
                    }
                    $frames[$top][$key] = true;
                }
            } elseif ($char === '{') {
                $frames[] = [];
            } elseif ($char === '[') {
                $frames[] = null;
            } elseif ($char === '}' || $char === ']') {
                array_pop($frames);
            }
        }
        return false;
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
     * Splice every dirty node's re-encoded opening comment — plus any rule's
     * explicit HTML edits — back into the original markup. Apart from those
     * edits the HTML is left as authored; the Node block-fixer re-serializes
     * it from these attributes right after. Comment spans and HTML-edit spans
     * never overlap (edits target attribute values inside wrapper tags), so
     * one descending-offset pass keeps every recorded byte offset valid.
     *
     * @param object[] $all
     * @param array{int,int,string}[] $htmlEdits offset, length, replacement
     */
    private static function render(string $markup, array $all, array $htmlEdits = []): string
    {
        $edits = array_map(
            static fn (array $e): array => ['start' => $e[0], 'len' => $e[1], 'text' => $e[2]],
            $htmlEdits,
        );
        foreach ($all as $n) {
            if (!$n->dirty) {
                continue;
            }
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
            $edits[] = ['start' => $n->start, 'len' => $n->len, 'text' => $comment];
        }
        usort($edits, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($edits as $e) {
            $markup = substr_replace($markup, $e['text'], $e['start'], $e['len']);
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

    /**
     * Whether a CSS value reads a custom property other than a global
     * --wp--preset-- one. A wrapper-local definition (--offset:3rem) is
     * deleted by re-serialization, so mirroring a value that depends on it
     * would ship an unresolvable reference past the rhythm gate.
     */
    private static function referencesNonPresetVar(string $value): bool
    {
        return preg_match('/var\(\s*--(?!wp--preset--)/i', $value) === 1;
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

    /** Whether the tag's class attribute contains $token as a whole word. */
    private static function hasClassToken(string $tagHtml, string $token): bool
    {
        $class = self::tagAttribute($tagHtml, 'class');
        return $class !== null
            && in_array($token, preg_split('/\s+/', trim($class[0])) ?: [], true);
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
