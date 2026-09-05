<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;

/**
 * Enforce an explicit design-direction corner language on generated blocks.
 *
 * The theme owns the radius for contained media (core/image, core/cover, the
 * media half of core/media-text) and core/button blocks, so a generated
 * per-block radius must not outrank it. Full-width images are the exception:
 * rounded commitments receive an inline zero radius so the global image rule
 * cannot round full-bleed media; sharp needs no redundant override. Cover and
 * media-text need no inline exception — their committed rules live in the
 * shape kit stylesheet (kitCss()), whose selectors already exempt alignfull
 * blocks, so an authored radius is simply removed. Shape-affecting variation
 * classes are removed from both comment attributes and saved HTML when they
 * conflict with that contract; otherwise the following block-fixer pass could
 * recover the HTML token into className and reintroduce the override.
 *
 * This transform edits comment attributes and the target block's own class
 * attributes only. The block fixer that immediately follows regenerates the
 * rest of each target block's saved HTML from the normalized attributes.
 */
final class ShapeMarkup
{
    /** @var list<string> */
    private const SHAPES = ['sharp', 'soft', 'round'];

    /** @var list<string> */
    private const IMAGE_VARIATIONS = ['is-style-rounded', 'is-style-circle-mask'];

    /** @var list<string> */
    private const SQUARE_BUTTON_VARIATIONS = ['is-style-squared', 'no-border-radius'];

    /** @var array<string,array<string,string>> */
    private const COMMITTED_RADII = [
        'sharp' => [
            'core/image' => '0',
            'core/button' => '0',
            'core/cover' => '0',
            'core/media-text' => '0',
        ],
        'soft' => [
            'core/image' => '0.5rem',
            'core/button' => '0.5rem',
            'core/cover' => '0.5rem',
            'core/media-text' => '0.5rem',
        ],
        'round' => [
            'core/image' => '1.25rem',
            'core/button' => '9999px',
            'core/cover' => '1.25rem',
            'core/media-text' => '1.25rem',
        ],
    ];

    /**
     * The radius scale each corner language executes beyond contained media:
     * marked card shells (`card-style--flush/framed/overlap` groups), panels
     * (rounded bands, closing CTA cards) and pill controls (badges, nav
     * pills). The reference corpus rounds cards at 12-24px and panels at
     * 24-48px; `sharp` keeps every surface square. Published as custom
     * properties by kitCss() so later kits execute the same commitment.
     *
     * @var array<string,array{media:string,card:string,panel:string,pill:string}>
     */
    public const RADIUS_SCALE = [
        'sharp' => ['media' => '0', 'card' => '0', 'panel' => '0', 'pill' => '0'],
        'soft'  => ['media' => '0.5rem', 'card' => '0.75rem', 'panel' => '1.5rem', 'pill' => '9999px'],
        'round' => ['media' => '1.25rem', 'card' => '1.5rem', 'panel' => '2.5rem', 'pill' => '9999px'],
    ];

    /** Card shells whose radius the commitment owns; borderless has no box. */
    private const CARD_SHELL_CLASSES = ['card-style--flush', 'card-style--framed', 'card-style--overlap'];

    /** @var array<string,string> disposition wording per owned target */
    private const TARGET_LABELS = [
        'core/image' => 'image',
        'core/button' => 'button',
        'core/cover' => 'cover',
        'core/media-text' => 'media-text',
    ];

    /**
     * The build-owned stylesheet executing a rounded commitment on contained
     * media surfaces theme.json has no structured path to: the media half of
     * core/media-text and the core/cover canvas (core clips a cover's
     * background at the root radius via its own overflow rule). Full-bleed
     * (alignfull) blocks keep square media, matching the full-width image
     * exemption. `sharp` and unknown shapes ship no kit — those surfaces are
     * square by default and authored overrides are removed in normalize().
     */
    public static function kitCss(?string $shape): ?string
    {
        $shape = is_string($shape) ? strtolower(trim($shape)) : '';
        if (!in_array($shape, self::SHAPES, true)) {
            return null;
        }
        $scale = self::RADIUS_SCALE[$shape];
        // The scale ships for every commitment, sharp included: later kits
        // (rounded bands, badges, glass cards) read these properties, and a
        // sharp site must resolve them to zero rather than to a fallback.
        $css = <<<CSS
            /* Committed '{$shape}' corner language as one radius scale. Marked
               card shells carry the card value in their block attributes (the
               shape pass writes it); panels and pill controls read it from
               here. Written by the build, never by a model. */
            :root {
                --shape-radius-media: {$scale['media']};
                --shape-radius-card: {$scale['card']};
                --shape-radius-panel: {$scale['panel']};
                --shape-radius-pill: {$scale['pill']};
            }

            CSS;
        if ($shape === 'sharp') {
            return $css;
        }
        $radius = self::COMMITTED_RADII[$shape]['core/cover'];
        return $css . <<<CSS
            /* Contained media surfaces theme.json cannot reach: the media half
               of core/media-text and the core/cover canvas. Full-bleed
               (alignfull) rows keep their media square, matching the committed
               image rule. */
            .wp-block-media-text:not(.alignfull) .wp-block-media-text__media,
            .wp-block-media-text:not(.alignfull) .wp-block-media-text__media img,
            .wp-block-media-text:not(.alignfull) .wp-block-media-text__media video {
                border-radius: {$radius};
            }
            /* imageFill media paints as a background on the figure; clip it too. */
            .wp-block-media-text:not(.alignfull) .wp-block-media-text__media {
                overflow: hidden;
            }
            .wp-block-cover:not(.alignfull) {
                border-radius: {$radius};
            }

            CSS;
    }

    /**
     * @return array{
     *     markup:string,
     *     changes:list<array{
     *         blockPath:string,
     *         blockName:string,
     *         property:string,
     *         authored:mixed,
     *         delivered:mixed,
     *         disposition:string
     *     }>
     * }
     */
    public static function normalize(string $markup, ?string $shape): array
    {
        $shape = is_string($shape) ? strtolower(trim($shape)) : '';
        if (!in_array($shape, self::SHAPES, true)) {
            return ['markup' => $markup, 'changes' => []];
        }

        $doc = BlockMarkup::parse($markup);
        $paths = self::blockPaths($doc);
        $changes = [];

        foreach ($doc->indices() as $i) {
            if (!$doc->isStructurallySafe($i)) {
                continue;
            }
            $parsedName = $doc->name($i);
            $name = str_contains($parsedName, '/') ? $parsedName : 'core/' . $parsedName;
            $attrs = $doc->attrs($i) ?? [];
            $changedAttrs = false;
            $path = $paths[$i] ?? (string) $i;
            $target = array_key_exists($name, self::TARGET_LABELS) ? $name : null;

            if ($target === 'core/image') {
                $htmlTargets = self::htmlClassTargets($doc, $i);
                $rootTokens = $htmlTargets['root']['tokens'] ?? [];
                $fullWidth = ($attrs['align'] ?? null) === 'full'
                    || in_array('alignfull', $rootTokens, true);
                self::normalizeRadius(
                    $attrs,
                    $fullWidth,
                    $shape,
                    $path,
                    $name,
                    $changes,
                    $changedAttrs,
                );
                self::removeVariations(
                    $doc,
                    $i,
                    $attrs,
                    self::IMAGE_VARIATIONS,
                    [
                        'is-style-rounded' => $htmlTargets['roundedImage'],
                        'is-style-circle-mask' => array_values(array_filter([
                            $htmlTargets['root'],
                        ])),
                    ],
                    [],
                    $path,
                    $name,
                    $changes,
                    $changedAttrs,
                );
            } elseif ($target === 'core/button') {
                $htmlTargets = self::htmlClassTargets($doc, $i);
                $originalRadius = self::localRadius($attrs);
                self::normalizeRadius(
                    $attrs,
                    false,
                    $shape,
                    $path,
                    $name,
                    $changes,
                    $changedAttrs,
                );
                // These square-button variants agree with `sharp`; they only
                // override an explicitly rounded soft/round commitment.
                if ($shape !== 'sharp') {
                    self::removeVariations(
                        $doc,
                        $i,
                        $attrs,
                        self::SQUARE_BUTTON_VARIATIONS,
                        [
                            'is-style-squared' => array_values(array_filter([
                                $htmlTargets['root'],
                            ])),
                            'no-border-radius' => array_values(array_filter([
                                $htmlTargets['root'],
                                $htmlTargets['buttonLink'],
                            ])),
                        ],
                        $originalRadius['exists'] && self::isCssZero($originalRadius['value'])
                            ? ['no-border-radius']
                            : [],
                        $path,
                        $name,
                        $changes,
                        $changedAttrs,
                    );
                }
            } elseif ($target !== null) {
                // Cover and media-text: the shape kit stylesheet owns their
                // corners and already exempts alignfull blocks, so a local
                // authored radius is removed for every alignment instead of
                // being replaced with an inline exemption.
                self::normalizeRadius(
                    $attrs,
                    false,
                    $shape,
                    $path,
                    $name,
                    $changes,
                    $changedAttrs,
                );
            } elseif ($name === 'core/group' && self::isCardShell($doc, $i, $attrs)) {
                // A marked card shell's radius is the committed card value
                // (frm W4a). Block attributes serialize inline, so writing the
                // value here beats any generated declaration without a kit
                // rule fighting importance; generic groups stay untouched.
                self::normalizeCardShellRadius($attrs, $shape, $path, $changes, $changedAttrs);
            }

            self::normalizeCarriedStyleOverrides(
                $attrs,
                $target,
                $path,
                $name,
                $changes,
                $changedAttrs,
            );

            if ($changedAttrs) {
                $doc->setAttrs($i, $attrs);
            }
        }

        return ['markup' => $doc->render(), 'changes' => $changes];
    }

    /**
     * Remove render-time shape overrides carried outside the reviewed save
     * paths. A target image/button/cover/media-text owns its direct
     * responsive/variation state; every block may also carry
     * `style.elements.button` for descendant controls. Other elements
     * (captions, links, generic card shells) remain outside the commitment.
     *
     * @param array<mixed> $attrs
     * @param list<array{
     *     blockPath:string,blockName:string,property:string,authored:mixed,
     *     delivered:mixed,disposition:string
     * }> $changes
     */
    private static function normalizeCarriedStyleOverrides(
        array &$attrs,
        ?string $target,
        string $path,
        string $name,
        array &$changes,
        bool &$changedAttrs,
    ): void {
        $style = $attrs['style'] ?? null;
        if (!is_array($style) || !self::isObject($style)) {
            return;
        }

        self::normalizeOwnedStyleNode(
            $style,
            'style',
            $target,
            true,
            $path,
            $name,
            $changes,
            $changedAttrs,
        );
        $attrs['style'] = $style;
    }

    /**
     * @param array<mixed> $node
     * @param list<array{
     *     blockPath:string,blockName:string,property:string,authored:mixed,
     *     delivered:mixed,disposition:string
     * }> $changes
     */
    private static function normalizeOwnedStyleNode(
        array &$node,
        string $stylePath,
        ?string $target,
        bool $preserveDirectRadius,
        string $blockPath,
        string $blockName,
        array &$changes,
        bool &$changedAttrs,
    ): void {
        if ($target !== null) {
            self::normalizeOwnedCustomCss(
                $node,
                $stylePath,
                $target,
                $blockPath,
                $blockName,
                $changes,
                $changedAttrs,
            );
            if (!$preserveDirectRadius
                && is_array($node['border'] ?? null)
                && self::isObject($node['border'])
                && array_key_exists('radius', $node['border'])
            ) {
                $authored = $node['border']['radius'];
                unset($node['border']['radius']);
                if ($node['border'] === []) {
                    unset($node['border']);
                }
                $changedAttrs = true;
                $changes[] = [
                    'blockPath' => $blockPath,
                    'blockName' => $blockName,
                    'property' => $stylePath . '.border.radius',
                    'authored' => $authored,
                    'delivered' => null,
                    'disposition' => 'removed carried radius that overrides the authoritative '
                        . self::targetLabel($target) . ' corner language',
                ];
            }
        }

        $elements = $node['elements'] ?? null;
        if (is_array($elements) && self::isObject($elements)) {
            foreach ($elements as $element => &$child) {
                if (!is_string($element) || !is_array($child) || !self::isObject($child)) {
                    continue;
                }
                self::normalizeOwnedStyleNode(
                    $child,
                    $stylePath . '.elements.' . $element,
                    $element === 'button' ? 'core/button' : null,
                    false,
                    $blockPath,
                    $blockName,
                    $changes,
                    $changedAttrs,
                );
            }
            unset($child);
            $node['elements'] = $elements;
        }

        $variations = $node['variations'] ?? null;
        if (is_array($variations) && self::isObject($variations)) {
            foreach ($variations as $variation => &$child) {
                if (!is_string($variation) || !is_array($child) || !self::isObject($child)) {
                    continue;
                }
                self::normalizeOwnedStyleNode(
                    $child,
                    $stylePath . '.variations.' . $variation,
                    $target,
                    false,
                    $blockPath,
                    $blockName,
                    $changes,
                    $changedAttrs,
                );
            }
            unset($child);
            $node['variations'] = $variations;
        }

        foreach ($node as $state => &$child) {
            if (!is_string($state)
                || (!str_starts_with($state, ':')
                    && !str_starts_with($state, '@')
                    && !in_array($state, ['mobile', 'tablet', 'desktop'], true))
                || !is_array($child)
                || !self::isObject($child)
            ) {
                continue;
            }
            self::normalizeOwnedStyleNode(
                $child,
                $stylePath . '.' . $state,
                $target,
                false,
                $blockPath,
                $blockName,
                $changes,
                $changedAttrs,
            );
        }
        unset($child);
    }

    /**
     * Per-block custom CSS is carried through the serializer and interpreted
     * by WordPress at render time. Remove only declarations whose selector is
     * the owned block root/surface; caption/card descendant rules survive.
     *
     * @param array<mixed> $node
     * @param list<array{
     *     blockPath:string,blockName:string,property:string,authored:mixed,
     *     delivered:mixed,disposition:string
     * }> $changes
     */
    private static function normalizeOwnedCustomCss(
        array &$node,
        string $stylePath,
        string $target,
        string $blockPath,
        string $blockName,
        array &$changes,
        bool &$changedAttrs,
    ): void {
        $css = $node['css'] ?? null;
        if (!is_string($css)) {
            return;
        }
        $selectorOwned = static fn (string $selector): bool =>
            CssChecks::selectorTargetsShape($selector)
            || self::selectorTargetsImplicitBlockRoot($selector);
        $owned = CssChecks::shapeAffectingDeclarations(
            $css,
            $selectorOwned,
            true,
            true,
        );
        if ($owned === []) {
            return;
        }

        $unsafe = array_filter(
            $owned,
            static fn (array $declaration): bool => !$declaration['structurallySafe'],
        );
        if ($unsafe !== []) {
            unset($node['css']);
            $changedAttrs = true;
            $changes[] = [
                'blockPath' => $blockPath,
                'blockName' => $blockName,
                'property' => $stylePath . '.css',
                'authored' => $css,
                'delivered' => null,
                'disposition' => 'removed structurally malformed custom CSS containing an owned '
                    . self::targetLabel($target)
                    . ' corner override that could not be isolated safely',
                'warning' => true,
            ];
            return;
        }

        $ownedStarts = array_fill_keys(array_column($owned, 'start'), true);
        [$delivered, $dropped] = CssChecks::dropDeclarations(
            $css,
            static fn (array $declaration): bool => isset($ownedStarts[$declaration['start']]),
            true,
        );

        if (trim($delivered) === '') {
            unset($node['css']);
        } else {
            $node['css'] = $delivered;
        }
        $changedAttrs = true;
        foreach ($dropped as $declaration) {
            $changes[] = [
                'blockPath' => $blockPath,
                'blockName' => $blockName,
                'property' => $stylePath . '.css declaration',
                'authored' => trim($declaration['raw']),
                'delivered' => null,
                'disposition' => 'removed custom CSS that overrides the authoritative '
                    . self::targetLabel($target) . ' corner language',
            ];
        }
    }

    private static function selectorTargetsImplicitBlockRoot(string $selector): bool
    {
        return CssChecks::selectorTargetsSubject($selector, '&');
    }

    /** Whether a group carries one of the owned card-shell markers. */
    private static function isCardShell(BlockMarkup $doc, int $i, array $attrs): bool
    {
        $tokens = is_string($attrs['className'] ?? null)
            ? preg_split('/\s+/', trim($attrs['className']), -1, PREG_SPLIT_NO_EMPTY) ?: []
            : [];
        if (preg_match('/^\s*<[a-z][^>]*\sclass="([^"]*)"/i', $doc->ownHtml($i), $match) === 1) {
            $tokens = array_merge($tokens, preg_split('/\s+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }
        return array_intersect($tokens, self::CARD_SHELL_CLASSES) !== [];
    }

    /**
     * Write the committed card radius onto a marked card shell. An equivalent
     * authored value is canonicalized silently; a different one is replaced
     * and recorded as a repair. Malformed style containers are left to the
     * block fixer, which owns structural recovery.
     *
     * @param array<mixed> $attrs
     * @param list<array{
     *     blockPath:string,blockName:string,property:string,authored:mixed,
     *     delivered:mixed,disposition:string
     * }> $changes
     */
    private static function normalizeCardShellRadius(
        array &$attrs,
        string $shape,
        string $path,
        array &$changes,
        bool &$changedAttrs,
    ): void {
        $committed = self::RADIUS_SCALE[$shape]['card'];
        $style = $attrs['style'] ?? [];
        if (!is_array($style) || !self::isObject($style)) {
            return;
        }
        $border = $style['border'] ?? [];
        if (!is_array($border) || !self::isObject($border)) {
            return;
        }
        $hasRadius = array_key_exists('radius', $border);
        $authored = $hasRadius ? $border['radius'] : null;
        if ($hasRadius && $authored === $committed) {
            return;
        }
        if (!$hasRadius && self::isCssZero($committed)) {
            // Sharp: an unstyled shell is already square; no inline zero.
            return;
        }
        $border['radius'] = $committed;
        $style['border'] = $border;
        $attrs['style'] = $style;
        $changedAttrs = true;
        $changes[] = [
            'blockPath' => $path,
            'blockName' => 'core/group',
            'property' => 'style.border.radius',
            'authored' => $authored,
            'delivered' => $committed,
            'disposition' => $hasRadius && self::radiusEquals($authored, $committed)
                ? "canonicalized an equivalent card shell radius for the committed {$shape} corner language"
                : "card shell radius follows the committed {$shape} corner language",
        ];
    }

    /** Disposition wording for an owned target block. */
    private static function targetLabel(string $target): string
    {
        return self::TARGET_LABELS[$target] ?? $target;
    }

    /**
     * Remove a contained block's local radius, or force a full-width image to
     * zero. Empty containers are pruned so PHP does not serialize a removed
     * JSON object as an invalid [] value.
     *
     * @param array<mixed> $attrs
     * @param list<array{
     *     blockPath:string,blockName:string,property:string,authored:mixed,
     *     delivered:mixed,disposition:string
     * }> $changes
     */
    private static function normalizeRadius(
        array &$attrs,
        bool $fullWidth,
        string $shape,
        string $path,
        string $name,
        array &$changes,
        bool &$changedAttrs,
    ): void {
        $hasStyle = array_key_exists('style', $attrs);
        $style = $hasStyle ? $attrs['style'] : null;
        $styleIsObject = is_array($style) && self::isObject($style);
        $hasBorder = $styleIsObject && array_key_exists('border', $style);
        $border = $hasBorder ? $style['border'] : null;
        $borderIsObject = is_array($border) && self::isObject($border);
        $hasRadius = is_array($border)
            && self::isObject($border)
            && array_key_exists('radius', $border);

        if ($fullWidth) {
            $authoredRadius = $hasRadius ? $attrs['style']['border']['radius'] : null;
            if ($hasRadius && $authoredRadius === '0') {
                return;
            }

            // Sharp's absent global image radius already delivers a missing
            // local value as square. Avoid adding a redundant inline override.
            // A conflicting authored value still gets normalized below.
            if ($shape === 'sharp'
                && !$hasRadius
                && (!$hasStyle || ($styleIsObject && (!$hasBorder || $borderIsObject)))) {
                return;
            }

            if ($hasStyle && !$styleIsObject) {
                $authored = $style;
                $attrs['style'] = ['border' => ['radius' => '0']];
                $changedAttrs = true;
                $changes[] = [
                    'blockPath' => $path,
                    'blockName' => $name,
                    'property' => 'style',
                    'authored' => $authored,
                    'delivered' => ['border' => ['radius' => '0']],
                    'disposition' => 'replaced malformed style container; full-width image delivered square',
                ];
                return;
            }

            if ($hasBorder && !$borderIsObject) {
                $authored = $border;
                $attrs['style']['border'] = ['radius' => '0'];
                $changedAttrs = true;
                $changes[] = [
                    'blockPath' => $path,
                    'blockName' => $name,
                    'property' => 'style.border',
                    'authored' => $authored,
                    'delivered' => ['radius' => '0'],
                    'disposition' => 'replaced malformed border container; full-width image delivered square',
                ];
                return;
            }

            $attrs['style'] ??= [];
            $attrs['style']['border'] ??= [];
            $attrs['style']['border']['radius'] = '0';
            $changedAttrs = true;
            $matchesCommitment = $hasRadius && self::radiusEquals($authoredRadius, '0');
            $changes[] = [
                'blockPath' => $path,
                'blockName' => $name,
                'property' => 'style.border.radius',
                'authored' => $authoredRadius,
                'delivered' => '0',
                'disposition' => $hasRadius
                    ? ($matchesCommitment
                        ? 'canonicalized equivalent zero radius; full-width image remains square'
                        : 'replaced authored radius; full-width image delivered square')
                    : 'added deterministic zero radius; full-width image delivered square',
            ];
            return;
        }

        // A malformed contained style/border cannot carry a usable radius and
        // would make the strict serializer abandon the whole file. Remove the
        // smallest malformed container so this block can inherit the committed
        // theme radius and sibling attributes/styles remain deliverable.
        if ($hasStyle && !$styleIsObject) {
            unset($attrs['style']);
            $changedAttrs = true;
            $changes[] = [
                'blockPath' => $path,
                'blockName' => $name,
                'property' => 'style',
                'authored' => $style,
                'delivered' => null,
                'disposition' => 'removed malformed style container; block inherits the authoritative theme radius',
            ];
            return;
        }
        if ($hasBorder && !$borderIsObject) {
            unset($attrs['style']['border']);
            if ($attrs['style'] === []) {
                unset($attrs['style']);
            }
            $changedAttrs = true;
            $changes[] = [
                'blockPath' => $path,
                'blockName' => $name,
                'property' => 'style.border',
                'authored' => $border,
                'delivered' => null,
                'disposition' => 'removed malformed border container; block inherits the authoritative theme radius',
            ];
            return;
        }

        if (!$hasRadius) {
            return;
        }

        $authored = $attrs['style']['border']['radius'];
        unset($attrs['style']['border']['radius']);
        if ($attrs['style']['border'] === []) {
            unset($attrs['style']['border']);
        }
        if ($attrs['style'] === []) {
            unset($attrs['style']);
        }
        $changedAttrs = true;
        $matchesCommitment = self::radiusEquals(
            $authored,
            self::COMMITTED_RADII[$shape][$name],
        );
        $changes[] = [
            'blockPath' => $path,
            'blockName' => $name,
            'property' => 'style.border.radius',
            'authored' => $authored,
            'delivered' => null,
            'disposition' => $matchesCommitment
                ? 'removed redundant local radius; block inherits the same authoritative theme radius'
                : 'removed local radius; block inherits the authoritative theme radius',
        ];
    }

    /**
     * @param array<mixed> $attrs
     * @param list<string> $variations
     * @param array<string,list<array{kind:string,tokens:list<string>,start:int,end:int}>> $htmlTargets
     * @param list<string> $quietHtmlTokens tokens known to be serializer output
     *        for an authored radius that is already reported separately
     * @param list<array{
     *     blockPath:string,blockName:string,property:string,authored:mixed,
     *     delivered:mixed,disposition:string
     * }> $changes
     */
    private static function removeVariations(
        BlockMarkup $doc,
        int $i,
        array &$attrs,
        array $variations,
        array $htmlTargets,
        array $quietHtmlTokens,
        string $path,
        string $name,
        array &$changes,
        bool &$changedAttrs,
    ): void {
        $className = $attrs['className'] ?? null;
        $jsonTokens = is_string($className)
            ? (preg_split('/\s+/', trim($className), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            : [];

        foreach ($variations as $token) {
            $inJson = in_array($token, $jsonTokens, true);
            $matchedHtmlTargets = array_values(array_filter(
                $htmlTargets[$token] ?? [],
                static fn (array $target): bool => in_array($token, $target['tokens'], true),
            ));
            $inHtml = $matchedHtmlTargets !== [];
            if (!$inJson && !$inHtml) {
                continue;
            }

            if ($inJson) {
                $jsonTokens = array_values(array_filter(
                    $jsonTokens,
                    static fn (string $candidate): bool => $candidate !== $token,
                ));
                if ($jsonTokens === []) {
                    unset($attrs['className']);
                } else {
                    $attrs['className'] = implode(' ', $jsonTokens);
                }
                $changedAttrs = true;
            }
            foreach ($matchedHtmlTargets as $target) {
                $doc->removeClassTokenInOwnHtmlRange(
                    $i,
                    $token,
                    $target['start'],
                    $target['end'],
                );
            }

            $serializerDerived = !$inJson
                && in_array($token, $quietHtmlTokens, true)
                && array_column($matchedHtmlTargets, 'kind') === ['buttonLink'];
            $changes[] = [
                'blockPath' => $path,
                'blockName' => $name,
                'property' => $inJson ? 'className token' : 'saved HTML class token',
                'authored' => $token,
                'delivered' => null,
                'disposition' => $serializerDerived
                    ? 'removed serializer-derived shape class with its local radius'
                    : 'removed shape variation; block follows the authoritative corner language',
            ];
        }
    }

    /** @param array<mixed> $attrs @return array{exists:bool,value:mixed} */
    private static function localRadius(array $attrs): array
    {
        $style = $attrs['style'] ?? null;
        if (!is_array($style) || !self::isObject($style)) {
            return ['exists' => false, 'value' => null];
        }
        $border = $style['border'] ?? null;
        if (!is_array($border)
            || !self::isObject($border)
            || !array_key_exists('radius', $border)) {
            return ['exists' => false, 'value' => null];
        }
        return ['exists' => true, 'value' => $border['radius']];
    }

    /** @return array<int,string> BlockMarkup node index => block-tree path. */
    private static function blockPaths(BlockMarkup $doc): array
    {
        $paths = [];
        $root = 0;
        foreach ($doc->indices() as $i) {
            if ($doc->parent($i) !== null) {
                continue;
            }
            self::collectPaths($doc, $i, (string) $root, $paths);
            $root++;
        }
        return $paths;
    }

    /** @param array<int,string> $paths */
    private static function collectPaths(BlockMarkup $doc, int $i, string $path, array &$paths): void
    {
        $paths[$i] = $path;
        foreach ($doc->children($i) as $childIndex => $child) {
            self::collectPaths($doc, $child, $path . '/' . $childIndex, $paths);
        }
    }

    /**
     * Classes only where the relevant WordPress selectors consume them: the
     * block's first root element, plus the core/button link itself. A caption
     * or RichText descendant that happens to use `alignfull` or a style-like
     * token is unrelated content and must not change the block contract.
     *
     * @return array{
     *     root:?array{kind:string,tokens:list<string>,start:int,end:int},
     *     buttonLink:?array{kind:string,tokens:list<string>,start:int,end:int},
     *     roundedImage:list<array{kind:string,tokens:list<string>,start:int,end:int}>
     * }
     */
    private static function htmlClassTargets(BlockMarkup $doc, int $i): array
    {
        $fragment = HtmlFragment::parse($doc->ownHtml($i));
        $root = $fragment->root()->elementChildren()[0] ?? null;
        $link = $fragment->querySelector('.wp-block-button__link');
        $roundedImage = [];
        foreach ($fragment->querySelectorAll('.is-style-rounded') as $candidate) {
            if ($candidate === $root || $candidate->querySelector('img') instanceof HtmlNode) {
                $roundedImage[] = self::htmlClassTarget($candidate, 'roundedImage');
            }
        }
        return [
            'root' => $root instanceof HtmlNode ? self::htmlClassTarget($root, 'root') : null,
            'buttonLink' => $link instanceof HtmlNode ? self::htmlClassTarget($link, 'buttonLink') : null,
            'roundedImage' => $roundedImage,
        ];
    }

    /** @return array{kind:string,tokens:list<string>,start:int,end:int} */
    private static function htmlClassTarget(HtmlNode $node, string $kind): array
    {
        $tokens = preg_split(
            '/[\x20\t\r\n\f]+/',
            trim($node->attribute('class') ?? ''),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        return [
            'kind' => $kind,
            'tokens' => array_values($tokens),
            'start' => $node->startOffset(),
            'end' => $node->innerStartOffset(),
        ];
    }

    /** Whether two simple CSS radius values are semantically equivalent. */
    private static function radiusEquals(mixed $authored, string $committed): bool
    {
        if (self::isCssZero($committed)) {
            return self::isCssZero($authored);
        }
        if (!is_string($authored)) {
            return false;
        }
        $left = self::cssDimension($authored);
        $right = self::cssDimension($committed);
        return $left !== null
            && $right !== null
            && $left[1] === $right[1]
            && abs($left[0] - $right[0]) < 0.000000001;
    }

    private static function isCssZero(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value === 0.0;
        }
        if (!is_string($value)) {
            return false;
        }
        return preg_match(
            '/^[+-]?(?:0+(?:\.0*)?|\.0+)(?:[a-z%]+)?$/i',
            trim($value),
        ) === 1;
    }

    /** @return array{0:float,1:string}|null numeric value and lower-case unit */
    private static function cssDimension(string $value): ?array
    {
        if (preg_match(
            '/^([+]?(?:\d+(?:\.\d*)?|\.\d+))([a-z%]+)$/i',
            trim($value),
            $match,
        ) !== 1) {
            return null;
        }
        return [(float) $match[1], strtolower($match[2])];
    }

    /** PHP's [] represents either empty JSON object or list; both are safe to extend. */
    private static function isObject(array $value): bool
    {
        return $value === [] || !array_is_list($value);
    }
}
