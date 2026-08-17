<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\Json\JsJsonEncoder;
use Automattic\SiteBuild\BlockSerializer\Json\JsonDecoder;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\Units\GeneratedMarkup;

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
 * untouched — it is meant to run immediately before the PHP block serializer,
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
     * $htmlFirst turns off the three width rules that assume the THEME owns
     * page width via contentSize. On the HTML-first path the carried design
     * CSS owns it, and the transformer deliberately emits no layout attribute
     * on section roots — stamping one back boxes full-bleed heroes. Header and
     * footer keep every rule unless their root carries the exact marker that
     * says delivered CSS owns layout; that marker suppresses only the missing
     * root-layout injection.
     *
     * @return array{markup:string, notes:string[]} notes are human-readable
     *         descriptions of each change; empty notes means markup is
     *         returned unchanged.
     */
    public static function fix(
        string $markup,
        string $role,
        ?float $contentSize = null,
        array $spacingSlugs = [],
        bool $htmlFirst = false,
        array $wideClassTokens = [],
    ): array {
        $notes = [];
        $markup = self::repairMalformedAttributes($markup, $notes);
        $markup = self::mergeDuplicateAttributeKeys($markup, $notes);
        $parsed = self::parse($markup);
        if ($parsed === null) {
            return ['markup' => $markup, 'notes' => $notes];
        }
        [$roots, $all] = $parsed;

        $canonicalMarkup = self::canonicalizeMalformedPresetCss($markup, $all, $notes);
        if ($canonicalMarkup !== $markup) {
            $markup = $canonicalMarkup;
            $parsed = self::parse($markup);
            if ($parsed === null) {
                return ['markup' => $markup, 'notes' => $notes];
            }
            [$roots, $all] = $parsed;
        }

        $htmlEdits = [];
        self::canonicalizeTopLevelSupportKeys($all, $notes);
        self::canonicalizeSpacingAttributes($all, $notes);
        self::repairBareSlugSpacing($markup, $all, $notes, $spacingSlugs);
        self::canonicalizeLayoutVocabulary($all, $notes);
        self::mirrorHtmlOnlyVerticalSpacing($markup, $all, $notes);
        self::mirrorHtmlOnlyGap($markup, $all, $htmlEdits, $notes);
        self::mirrorDynamicChromeSpacing($markup, $all, $htmlEdits, $notes);
        $pageWidth = $role === self::ROLE_SECTION || $role === self::ROLE_TEMPLATE;
        if (!$pageWidth || !$htmlFirst) {
            self::addMissingRootLayout($roots, $notes, $wideClassTokens);
        }
        self::promoteAlignClassNames($all, $notes);

        if ($role === self::ROLE_HEADER) {
            self::widenHeaderRows($roots, $notes);
        }
        if ($role === self::ROLE_FOOTER) {
            self::widenFooterColumns($roots, $all, $notes);
            self::evenOutFooterRows($all, $notes);
        }
        if ($pageWidth && !$htmlFirst) {
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
     * Gutenberg's flex layout uses left/right in comment attributes even
     * though the equivalent CSS vocabulary is flex-start/flex-end. Models
     * occasionally put the CSS value in layout.justifyContent; map only that
     * exact, lossless pair before the serializer's closed-domain guard.
     *
     * @param object[] $all
     * @param string[] $notes
     */
    private static function canonicalizeLayoutVocabulary(array $all, array &$notes): void
    {
        foreach ($all as $node) {
            $layout = self::layout($node);
            $value = $layout?->justifyContent ?? null;
            if ($layout === null || ($layout->type ?? null) !== 'flex'
                || !is_string($value) || !isset(['flex-start' => true, 'flex-end' => true][$value])) {
                continue;
            }
            $layout->justifyContent = $value === 'flex-start' ? 'left' : 'right';
            $node->dirty = true;
            $notes[] = "wp:{$node->name} used CSS layout.justifyContent \"{$value}\" — mapped it to Gutenberg's \"{$layout->justifyContent}\" value";
        }
    }

    /**
     * Repair the invalid rendered spelling var(--wp--spacing--slug) only when
     * the same block explicitly declares var:preset|spacing|slug in its
     * comment attributes. That exact cross-channel agreement proves intent;
     * an HTML-only or disagreeing value remains untouched for the rhythm gate.
     *
     * @param object[] $all
     * @param string[] $notes
     */
    private static function canonicalizeMalformedPresetCss(string $markup, array $all, array &$notes): string
    {
        $edits = [];
        foreach ($all as $node) {
            if ($node->selfClosing) {
                continue;
            }
            $tagStart = $node->start + $node->len;
            $tagHtml = MarkupScan::wrapperTag($markup, $tagStart);
            if ($tagHtml === null) {
                continue;
            }
            $style = MarkupScan::tagAttribute($tagHtml, 'style');
            if ($style === null || !str_contains($style[0], 'var(--wp--spacing--')) {
                continue;
            }
            $repairedSlugs = [];
            $replacement = preg_replace_callback(
                '/var\(--wp--spacing--([a-z0-9_-]+)\)/i',
                static function (array $match) use ($node, &$repairedSlugs): string {
                    $slug = strtolower($match[1]);
                    if (!self::containsValue($node->attrs, "var:preset|spacing|{$slug}")) {
                        return $match[0];
                    }
                    $repairedSlugs[$slug] = true;
                    return "var(--wp--preset--spacing--{$slug})";
                },
                $style[0],
            );
            if ($replacement === null || $replacement === $style[0]) {
                continue;
            }
            $edits[] = [$tagStart + $style[1], strlen($style[0]), $replacement];
            $notes[] = "wp:{$node->name} rendered malformed spacing preset variable(s) "
                . implode(', ', array_keys($repairedSlugs))
                . ' while its attributes declared the matching preset — restored the canonical CSS variable spelling';
        }
        return $edits === [] ? $markup : self::render($markup, [], $edits);
    }

    private static function containsValue(mixed $value, string $expected): bool
    {
        if ($value === $expected) {
            return true;
        }
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $child) {
            if (self::containsValue($child, $expected)) {
                return true;
            }
        }
        return false;
    }

    /** Style-support families a model may hoist beside the style attribute. */
    private const TOP_LEVEL_SUPPORT_KEYS = ['spacing', 'border', 'typography'];

    /**
     * A support family written as a SIBLING of "style" instead of a member
     * of it styles nothing: WordPress reads every style support from
     * style.*, it is not a registered attribute (see TOP_LEVEL_SUPPORT_KEYS),
     * and the serializer's closed comment-attribute domain fails the whole
     * build on the unknown key (atlas wrote {"spacing":{"padding":...}}
     * beside "style" and fix-blocks rejected parts/page-home--trust-builders,
     * BIGR-718). The intent is unambiguous — the value was declared, only
     * the nesting is wrong — so move the family under style. Runs before
     * canonicalizeSpacingAttributes so a folded spacing family and a
     * misplaced style.padding/style.margin both end at style.spacing.*.
     * The pinned registry owns the safety boundary: unregistered blocks keep
     * their attributes byte-for-byte, a block without a registered "style"
     * destination is not touched, and a future real top-level attribute with
     * one of these names takes precedence over this repair. Families already
     * declared at the canonical path win at every conflict; missing object
     * members are adopted recursively. Non-object family/style shapes are
     * left for the gate.
     *
     * @param object[] $all
     * @param string[] $notes
     */
    private static function canonicalizeTopLevelSupportKeys(array $all, array &$notes): void
    {
        foreach ($all as $node) {
            $schemas = self::registeredAttributesFor($node->name);
            if ($schemas === null || !array_key_exists('style', $schemas)) {
                continue;
            }
            $hasStyle = property_exists($node->attrs, 'style');
            $style = $hasStyle ? $node->attrs->style : null;
            if ($hasStyle && !$style instanceof \stdClass) {
                continue; // explicit invalid style shape — leave every family for the gate
            }

            foreach (self::TOP_LEVEL_SUPPORT_KEYS as $key) {
                if (array_key_exists($key, $schemas) || !property_exists($node->attrs, $key)) {
                    continue;
                }
                $misplaced = $node->attrs->{$key};
                if (!$misplaced instanceof \stdClass) {
                    continue; // unrecognizable family shape — leave it for the gate
                }
                unset($node->attrs->{$key});
                $node->dirty = true;

                if (!$hasStyle) {
                    $style = $node->attrs->style = new \stdClass();
                    $hasStyle = true;
                }
                if (!property_exists($style, $key)) {
                    $style->{$key} = $misplaced;
                    $notes[] = "wp:{$node->name} declared \"{$key}\" at the top level of its attributes where WordPress ignores it — moved to style.{$key}";
                    continue;
                }
                $canonical = $style->{$key};
                if ($canonical instanceof \stdClass) {
                    $adopted = self::mergeMissingObjectMembers($canonical, $misplaced);
                    $notes[] = $adopted === []
                        ? "wp:{$node->name} declared \"{$key}\" at the top level of its attributes — dropped it in favor of the existing style.{$key}"
                        : "wp:{$node->name} declared \"{$key}\" at the top level of its attributes where WordPress ignores it — merged " . implode('/', $adopted) . " into style.{$key}";
                    continue;
                }
                $notes[] = "wp:{$node->name} declared \"{$key}\" at the top level of its attributes — dropped it in favor of the existing style.{$key}";
            }
        }
    }

    /**
     * Resolve a serialized block name against the frozen registry. WordPress
     * omits the core/ namespace in block comments, while custom names retain
     * their namespace.
     *
     * @return array<string,array<string,mixed>>|null
     */
    private static function registeredAttributesFor(string $serializedName): ?array
    {
        static $registry = null;
        $registry ??= new BlockRegistry();
        $name = str_contains($serializedName, '/') ? $serializedName : 'core/' . $serializedName;
        return $registry->isRegistered($name) ? $registry->attributes($name) : null;
    }

    /**
     * Merge only absent members, descending when both sides are objects.
     * Existing canonical values win at every scalar or shape conflict.
     *
     * @return string[] dotted paths adopted from $misplaced
     */
    private static function mergeMissingObjectMembers(
        \stdClass $canonical,
        \stdClass $misplaced,
        string $prefix = '',
    ): array
    {
        $adopted = [];
        foreach (get_object_vars($misplaced) as $member => $value) {
            $path = $prefix === '' ? $member : $prefix . '.' . $member;
            if (!property_exists($canonical, $member)) {
                $canonical->{$member} = $value;
                $adopted[] = $path;
                continue;
            }
            if ($canonical->{$member} instanceof \stdClass && $value instanceof \stdClass) {
                $adopted = array_merge(
                    $adopted,
                    self::mergeMissingObjectMembers($canonical->{$member}, $value, $path),
                );
            }
        }
        return $adopted;
    }

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
                $tagHtml = MarkupScan::wrapperTag($markup, $node->start + $node->len);
                if ($tagHtml !== null && self::hasClassToken($tagHtml, "wp-block-{$short}")) {
                    $styleAttr = MarkupScan::tagAttribute($tagHtml, 'style');
                    if ($styleAttr !== null) {
                        $declared = [];
                        foreach (MarkupScan::parseInlineStyle($styleAttr[0]) as $declaration) {
                            if ($declaration['value'] !== null) {
                                $declared[$declaration['property']] = trim($declaration['value']);
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
            $tagHtml = MarkupScan::wrapperTag($markup, $tagStart);
            if ($tagHtml === null || !self::hasClassToken($tagHtml, "wp-block-{$short}")) {
                continue;
            }
            $styleAttr = MarkupScan::tagAttribute($tagHtml, 'style');
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
            foreach (MarkupScan::parseInlineStyle($styleAttr[0]) as $declaration) {
                if (trim($declaration['segment']) === '') {
                    continue;
                }
                if ($declaration['value'] === null
                    || preg_match('/\A(padding|margin)-(top|right|bottom|left)\z/', $declaration['property'], $m) !== 1) {
                    $kept[] = trim($declaration['segment']);
                    continue;
                }
                $value = trim($declaration['value']);
                if ($value === '' || self::referencesNonPresetVar($value)) {
                    $unmirrorable = true;
                    break;
                }
                $declared[$m[1]][$m[2]] = MarkupScan::blockSpacingValue($value);
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
     * declared value into style.spacing.* — the inverse of the serializer's
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
            $tagHtml = MarkupScan::wrapperTag($markup, $node->start + $node->len);
            if ($tagHtml === null
                || !self::hasClassToken($tagHtml, 'wp-block-' . preg_replace('#^core/#', '', $node->name))) {
                continue;
            }
            $styleAttr = MarkupScan::tagAttribute($tagHtml, 'style');
            if ($styleAttr === null) {
                continue;
            }

            foreach (MarkupScan::parseInlineStyle($styleAttr[0]) as $declaration) {
                if ($declaration['value'] === null
                    || preg_match('/\A(padding|margin)-(top|bottom)\z/', $declaration['property'], $m) !== 1) {
                    continue;
                }
                $value = trim($declaration['value']);
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
                $box->{$side} = MarkupScan::blockSpacingValue($value);
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
            $tagHtml = MarkupScan::wrapperTag($markup, $tagStart);
            if ($tagHtml === null
                || !self::hasClassToken($tagHtml, 'wp-block-' . preg_replace('#^core/#', '', $node->name))) {
                continue;
            }
            $styleAttr = MarkupScan::tagAttribute($tagHtml, 'style');
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
        foreach (MarkupScan::parseInlineStyle($styleValue) as $declaration) {
            $property = $declaration['property'];
            if ($declaration['value'] === null
                || !in_array($property, ['gap', 'row-gap', 'column-gap'], true)) {
                if (trim($declaration['segment']) !== '') {
                    $kept[] = $declaration['segment'];
                }
                continue;
            }
            $value = trim($declaration['value']);
            $parts = preg_split('/\s+/', $value) ?: [];
            if ($value === '' || str_contains($value, ',') || self::referencesNonPresetVar($value)
                || count($parts) > 2 || ($property !== 'gap' && count($parts) !== 1)) {
                return null;
            }
            if ($property !== 'column-gap') {
                $declared['top'] = MarkupScan::blockSpacingValue($property === 'gap' ? $parts[0] : $value);
            }
            if ($property !== 'row-gap') {
                $declared['left'] = MarkupScan::blockSpacingValue($property === 'gap' ? ($parts[1] ?? $parts[0]) : $value);
            }
        }
        return $declared;
    }

    /**
     * A top-level group with no "layout" attribute is flow, not constrained:
     * no centering, no global padding, so its align:wide children render
     * edge-to-edge at the viewport (tbilisi's "The Cuisine" band). Same
     * contract Units\GeneratedMarkup::constrainedPart enforces for header/footer,
     * applied to every file's root groups. A root carrying the exact
     * CSS-owned-layout marker stays layout-less; every other repair still runs.
     *
     * @param object[] $roots
     * @param string[] $notes
     * @param list<string> $wideClassTokens classes the design's own CSS gives the wide measure
     */
    private static function addMissingRootLayout(array $roots, array &$notes, array $wideClassTokens = []): void
    {
        foreach ($roots as $node) {
            if (self::is($node, 'group')
                && !isset($node->attrs->layout)
                && !GeneratedMarkup::hasCssOwnedLayoutMarker($node->attrs)
            ) {
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
    /**
     * The header's title/nav rows must share the page's wide band. Sections
     * and footers put their structural rows at align:wide; a header row left
     * at content width plants a competing left edge directly above the fold
     * (naturaleza's fallback title at 860px over 1320px section rows).
     * Promote direct flex-row group children of a constrained header root
     * that carry no explicit alignment of their own.
     *
     * @param object[] $roots
     * @param string[] $notes
     */
    private static function widenHeaderRows(array $roots, array &$notes): void
    {
        $root = $roots[0] ?? null;
        if ($root === null || !self::is($root, 'group')
            || ($root->attrs->layout->type ?? null) !== 'constrained') {
            return;
        }
        foreach ($root->children as $child) {
            if (!self::is($child, 'group')
                || ($child->attrs->layout->type ?? null) !== 'flex'
                || self::align($child) !== '') {
                continue;
            }
            $child->attrs->align = 'wide';
            $child->dirty = true;
            $notes[] = 'header row did not share the canonical wide band — set "align":"wide"';
        }
    }

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
     * this fixer skips the whole file, and block serialization erases every
     * attribute of the block — the fatal rhythm drop plus silent losses.
     * The repair stays mechanical by refusing to guess: every single-closer
     * deletion is tried (see withoutPrematureClosers), and the rewrite is
     * kept only when exactly one distinct valid object can result. Anything
     * ambiguous stays untouched for the gate to reject.
     *
     * @param string[] $notes
     */
    private static function repairMalformedAttributes(string $markup, array &$notes): string
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
            $completed = self::withMissingRootCloser($json);
            if ($completed !== null) {
                $markup = substr_replace($markup, $completed, $t[3][1], strlen($json));
                $notes[] = "wp:{$t[2][0]} attributes omitted their final root closer — restored it so the declared attributes parse instead of being erased";
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
     * Deep-merge duplicate same-depth keys in otherwise valid comment JSON.
     *
     * A model that writes {"style":{...},"style":{...}} meant one object: the
     * saved HTML already carries both declarations' members, but every
     * json_decode downstream (this fixer's own parse/re-render included)
     * keeps only the last duplicate, silently deleting the earlier members —
     * and fix-blocks then fails the whole build because the surviving inline
     * CSS has no attribute mirror (naturaleza, BIGR-719). The merge is
     * deterministic: object values merge member by member, non-object
     * conflicts resolve last-wins exactly as JSON.parse would, so no member
     * is guessed and no last-wins outcome changes — earlier non-conflicting
     * members are simply no longer dropped. Rewriting the delimiter text here
     * means LayoutFixer, the block serializer, and WordPress itself all read
     * the same clean JSON. Idempotent: merged output has no duplicates left.
     *
     * @param string[] $notes
     */
    private static function mergeDuplicateAttributeKeys(string $markup, array &$notes): string
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
            $decoder = new JsonDecoder($json, mergeDuplicateObjectKeys: true);
            try {
                $merged = $decoder->decode();
            } catch (\InvalidArgumentException) {
                continue;
            }
            $mergedKeys = $decoder->mergedDuplicateKeyPaths();
            if (!$merged instanceof JsonObject || $mergedKeys === []) {
                continue;
            }
            $markup = substr_replace(
                $markup,
                JsJsonEncoder::serializeAttributes($merged),
                $t[3][1],
                strlen($json),
            );
            $keys = implode(', ', $mergedKeys);
            $notes[] = "wp:{$t[2][0]} attributes declared \"{$keys}\" more than once — deep-merged the duplicate "
                . 'declarations so re-serialization keeps every member instead of only the last';
        }
        return $markup;
    }

    /**
     * Complete only the narrow, unambiguous truncated form where every nested
     * object/array is already balanced and the sole missing token is the root
     * object's final `}`. A missing nested closer could change which object a
     * later member belongs to, so that broader shape remains fail-closed.
     */
    private static function withMissingRootCloser(string $json): ?string
    {
        $stack = [];
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
                continue;
            }
            if ($char === '{') {
                $stack[] = ['closer' => '}', 'offset' => $i];
            } elseif ($char === '[') {
                $stack[] = ['closer' => ']', 'offset' => $i];
            } elseif ($char === '}' || $char === ']') {
                $frame = array_pop($stack);
                if ($frame === null || $frame['closer'] !== $char) {
                    return null;
                }
            }
        }

        if ($inString || count($stack) !== 1
            || $stack[0]['offset'] !== 0 || $stack[0]['closer'] !== '}') {
            return null;
        }
        $candidate = $json . '}';
        return json_decode($candidate) instanceof \stdClass
            && !self::hasSameDepthDuplicateKeys($candidate)
            ? $candidate
            : null;
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
     * Whether a syntactically valid JSON payload declares the same decoded key
     * twice in one object. JSON key identity is based on the decoded string, so
     * raw spellings such as "style" and "\u0073tyle" are duplicates too.
     * Reuse the same decoder as the canonical merge path so malformed-closer
     * ambiguity checks cannot drift from the repair semantics.
     */
    private static function hasSameDepthDuplicateKeys(string $json): bool
    {
        $decoder = new JsonDecoder($json, mergeDuplicateObjectKeys: true);
        try {
            $decoder->decode();
        } catch (\InvalidArgumentException) {
            return false;
        }
        return $decoder->mergedDuplicateKeyPaths() !== [];
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
     * edits the HTML is left as authored; the PHP block serializer re-serializes
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

    /** Whether the tag's class attribute contains $token as a whole word. */
    private static function hasClassToken(string $tagHtml, string $token): bool
    {
        $class = MarkupScan::tagAttribute($tagHtml, 'class');
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
