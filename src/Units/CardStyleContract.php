<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\CardStyle;
use Automattic\SiteBuild\HtmlBlockContext;
use Automattic\SiteBuild\MarkupSanitizer;
use Automattic\SiteBuild\Warnings;

/**
 * Enforce the generated section's site-wide image-card construction contract.
 *
 * The prompt gives every image card a `card-style--*` marker. Older or
 * imperfect responses can omit it, so a deliberately narrow legacy detector
 * also recognizes unambiguous wrappers in the documented equal, staggered,
 * and editorial column-card recipes.
 * A detected card is changed only when its existing anatomy already matches the assigned style:
 * then marker/behavior class normalization is semantics-safe. Structural
 * drift is retained and reported; destructive hooks on blocked roots,
 * ambiguous text wrappers, and quarantined nested groups are removed at the
 * smallest safely parseable unit.
 *
 * This class is project-free so stateless SectionUnit hosts apply the same
 * boundary as SectionsStep. Generated defects never throw from enforce(); an
 * unexpected per-card inspection failure becomes an actionable warning and
 * the card remains untouched.
 */
final class CardStyleContract
{
    public const STYLES = CardStyle::ALL;

    private const MARKER_PREFIX = 'card-style--';
    private const DESTRUCTIVE_HOOKS = ['card-flush', 'overlap-up'];
    private const REQUIRED_COLOR_SLUGS = ['base', 'contrast', 'primary', 'secondary', 'accent', 'band'];
    private const REQUIRED_SPACING_SLUGS = ['xs', 'sm', 'md', 'lg', 'xl', 'xxl'];
    private const CORE_SHADOW_SLUGS = ['natural', 'deep', 'sharp', 'outlined', 'crisp'];
    private const CSS_COLOR_NAMES = [
        'aliceblue', 'antiquewhite', 'aqua', 'aquamarine', 'azure', 'beige',
        'bisque', 'black', 'blanchedalmond', 'blue', 'blueviolet', 'brown',
        'burlywood', 'cadetblue', 'chartreuse', 'chocolate', 'coral',
        'cornflowerblue', 'cornsilk', 'crimson', 'cyan', 'darkblue',
        'darkcyan', 'darkgoldenrod', 'darkgray', 'darkgreen', 'darkgrey',
        'darkkhaki', 'darkmagenta', 'darkolivegreen', 'darkorange',
        'darkorchid', 'darkred', 'darksalmon', 'darkseagreen',
        'darkslateblue', 'darkslategray', 'darkslategrey', 'darkturquoise',
        'darkviolet', 'deeppink', 'deepskyblue', 'dimgray', 'dimgrey',
        'dodgerblue', 'firebrick', 'floralwhite', 'forestgreen', 'fuchsia',
        'gainsboro', 'ghostwhite', 'gold', 'goldenrod', 'gray', 'green',
        'greenyellow', 'grey', 'honeydew', 'hotpink', 'indianred', 'indigo',
        'ivory', 'khaki', 'lavender', 'lavenderblush', 'lawngreen',
        'lemonchiffon', 'lightblue', 'lightcoral', 'lightcyan',
        'lightgoldenrodyellow', 'lightgray', 'lightgreen', 'lightgrey',
        'lightpink', 'lightsalmon', 'lightseagreen', 'lightskyblue',
        'lightslategray', 'lightslategrey', 'lightsteelblue', 'lightyellow',
        'lime', 'limegreen', 'linen', 'magenta', 'maroon', 'mediumaquamarine',
        'mediumblue', 'mediumorchid', 'mediumpurple', 'mediumseagreen',
        'mediumslateblue', 'mediumspringgreen', 'mediumturquoise',
        'mediumvioletred', 'midnightblue', 'mintcream', 'mistyrose',
        'moccasin', 'navajowhite', 'navy', 'oldlace', 'olive', 'olivedrab',
        'orange', 'orangered', 'orchid', 'palegoldenrod', 'palegreen',
        'paleturquoise', 'palevioletred', 'papayawhip', 'peachpuff', 'peru',
        'pink', 'plum', 'powderblue', 'purple', 'rebeccapurple', 'red',
        'rosybrown', 'royalblue', 'saddlebrown', 'salmon', 'sandybrown',
        'seagreen', 'seashell', 'sienna', 'silver', 'skyblue', 'slateblue',
        'slategray', 'slategrey', 'snow', 'springgreen', 'steelblue', 'tan',
        'teal', 'thistle', 'tomato', 'turquoise', 'violet', 'wheat', 'white',
        'whitesmoke', 'yellow', 'yellowgreen',
    ];
    private const CSS_SYSTEM_COLORS = [
        'accentcolor', 'accentcolortext', 'activetext', 'buttonborder',
        'buttonface', 'buttontext', 'canvas', 'canvastext', 'field',
        'fieldtext', 'graytext', 'highlight', 'highlighttext', 'linktext',
        'mark', 'marktext', 'selecteditem', 'selecteditemtext', 'visitedtext',
    ];

    /**
     * @param null|callable(string):BlockMarkup $parser injectable only so the
     *        otherwise-total BlockMarkup parser's operational failure boundary
     *        remains regression-testable
     * @param string|array<mixed>|null $themeJson optional self-contained theme
     *        input used to resolve generated gradient and shadow presets
     * @return array{markup:string,repairs:list<array<string,mixed>>,warnings:list<string>}
     */
    public static function enforce(
        string $markup,
        string $assignedStyle,
        string $part,
        ?callable $parser = null,
        string|array|null $themeJson = null,
    ): array {
        if (!in_array($assignedStyle, self::STYLES, true)) {
            throw new \InvalidArgumentException('assigned card style must be normalized');
        }

        $repairs = [];
        $warnings = [];
        $paintPresets = self::paintPresets($themeJson);
        try {
            $document = $parser === null ? BlockMarkup::parse($markup) : $parser($markup);
            if (!$document instanceof BlockMarkup) {
                throw new \RuntimeException('card contract parser returned no block document');
            }
        } catch (\Throwable $error) {
            return [
                'markup' => $markup,
                'repairs' => [],
                'warnings' => [self::documentWarning(
                    $markup,
                    $part,
                    $assignedStyle,
                    'parse the generated block document',
                    $error,
                )],
            ];
        }

        try {
            $candidates = self::candidateIndices($document);
        } catch (\Throwable $error) {
            return [
                'markup' => $markup,
                'repairs' => [],
                'warnings' => [self::documentWarning(
                    $markup,
                    $part,
                    $assignedStyle,
                    'discover card candidates in the generated block document',
                    $error,
                )],
            ];
        }
        if ($candidates === [] && self::hasUnrepresentedCardHook($markup)) {
            return [
                'markup' => $markup,
                'repairs' => [],
                'warnings' => [self::documentWarning(
                    $markup,
                    $part,
                    $assignedStyle,
                    'represent every card-hook group in the bounded block document',
                    new \RuntimeException(
                        'a card hook is present in a group delimiter that the block parser did not represent',
                    ),
                )],
            ];
        }

        usort(
            $candidates,
            static fn (int $left, int $right): int =>
                $document->openingOffset($right) <=> $document->openingOffset($left),
        );
        foreach ($candidates as $index) {
            try {
                $inspection = self::inspect($document, $index, $paintPresets);
                $issues = self::anatomyIssues($inspection, $assignedStyle, $paintPresets);
                if ($issues !== []) {
                    $cleanup = self::stripUnsafeCardHooks(
                        $markup,
                        $document,
                        $inspection,
                        $assignedStyle,
                        $part,
                    );
                    $markup = $cleanup['markup'];
                    array_push($repairs, ...$cleanup['repairs']);
                    array_push($warnings, ...$cleanup['warnings']);

                    // Topology is still intentionally left for a later repair,
                    // but its durable warning must describe the bytes actually
                    // delivered after the smallest harmful hooks were removed.
                    // Reinspection also makes the residual row identical on
                    // pass two, when no further hook-removal transaction runs.
                    if ($cleanup['changed']) {
                        // Class-only edits preserve indices but can shift an
                        // ancestor candidate's later wrapper offsets.
                        $document = BlockMarkup::parse($markup);
                    }
                    $deliveredDocument = $document;
                    $deliveredInspection = self::inspect($deliveredDocument, $index, $paintPresets);
                    $deliveredIssues = self::anatomyIssues(
                        $deliveredInspection,
                        $assignedStyle,
                        $paintPresets,
                    );
                    array_push(
                        $deliveredIssues,
                        ...self::malformedSavedWrapperIssues(
                            $deliveredDocument,
                            $deliveredInspection,
                            $assignedStyle,
                        ),
                    );
                    $warnings[] = self::warning(
                        $part,
                        $deliveredInspection,
                        $assignedStyle,
                        $deliveredIssues,
                    );
                    continue;
                }

                $authoredHtmlClasses = self::htmlClasses($document, $index);
                $cssResetWasActive = is_array($authoredHtmlClasses)
                    && in_array('card-flush', $authoredHtmlClasses, true);
                $repair = self::classRepair($document, $inspection, $assignedStyle);
                if ($repair === null) {
                    $wrapperIssues = self::malformedSavedWrapperIssues(
                        $document,
                        $inspection,
                        $assignedStyle,
                    );
                    if ($wrapperIssues === []) {
                        $wrapperIssues[] = self::issue(
                            'saved_wrapper',
                            self::savedWrapperBytes($document, $inspection['index']),
                            'one normal non-void opening tag with a single parseable class attribute',
                            'the saved HTML wrapper could not be updated transactionally',
                        );
                    }
                    $warnings[] = self::warning(
                        $part,
                        $inspection,
                        $assignedStyle,
                        $wrapperIssues,
                    );
                    continue;
                }

                $transaction = self::applyOperations($markup, $repair['operations']);
                if ($transaction['error'] !== null) {
                    $warnings[] = self::warning(
                        $part,
                        $inspection,
                        $assignedStyle,
                        [self::issue(
                            'class_transaction',
                            $transaction['error'],
                            'bounded non-overlapping operations matching their expected source bytes',
                            'the class repair transaction failed validation',
                        )],
                    );
                    continue;
                }

                try {
                    $deliveredDocument = BlockMarkup::parse($transaction['markup']);
                    $postconditionIssues = self::postconditionIssues(
                        $deliveredDocument,
                        $index,
                        $assignedStyle,
                        $paintPresets,
                    );
                } catch (\Throwable $error) {
                    $postconditionIssues = [self::issue(
                        'postcondition_parse',
                        str_replace(["\r", "\n"], ' ', $error->getMessage()),
                        'a parseable post-repair card document',
                        'the repaired snapshot could not be parsed for postcondition checks',
                    )];
                }
                if ($postconditionIssues !== []) {
                    $warnings[] = self::warning(
                        $part,
                        $inspection,
                        $assignedStyle,
                        $postconditionIssues,
                    );
                    continue;
                }

                // Construct every report row before committing the candidate
                // snapshot. An unexpected bookkeeping failure must roll back
                // this card just as surely as a failed markup postcondition.
                $cardRepairs = [];
                if ($repair['operations'] !== []) {
                    $cardRepairs[] = [
                        'code' => 'card-style-classes-normalized',
                        'part' => $part,
                        'path' => $inspection['path'],
                        'authored' => [
                            'card' => $inspection['classes'],
                            'body' => $inspection['bodyClasses'],
                        ],
                        'delivered' => [
                            'card_style' => $assignedStyle,
                            'marker' => self::MARKER_PREFIX . $assignedStyle,
                            'card_flush' => in_array($assignedStyle, ['flush', 'overlap'], true),
                        ],
                        'disposition' => 'repaired class-only contract drift on already-conforming card anatomy',
                    ];
                }
                $cssResets = self::cssResetEvidence($inspection, $assignedStyle);
                if ($cssResets !== [] && !$cssResetWasActive) {
                    $cardRepairs[] = [
                        'code' => 'card-style-css-reset-bound',
                        'part' => $part,
                        'path' => $inspection['path'],
                        'authored' => array_column($cssResets, 'authored', 'field'),
                        'delivered' => array_column($cssResets, 'required', 'field'),
                        'disposition' => 'repaired by the card-flush !important reset after verified hook delivery',
                    ];
                }
                $nextRepairs = array_merge($repairs, $cardRepairs);
                $markup = $transaction['markup'];
                $repairs = $nextRepairs;
                $document = $deliveredDocument;
            } catch (\Throwable $error) {
                // Generated markup is never allowed to abort the section. This
                // is intentionally scoped to one card: sibling operations and
                // bytes remain eligible for delivery.
                $fallback = self::safeFallbackInspection($document, $index);
                $why = str_replace(["\r", "\n"], ' ', $error->getMessage());
                $warnings[] = self::warning(
                    $part,
                    $fallback,
                    $assignedStyle,
                    [self::issue(
                        'inspection_error',
                        $why,
                        'successful per-card inspection',
                        'card inspection could not complete',
                    )],
                );
            }
        }

        return ['markup' => $markup, 'repairs' => $repairs, 'warnings' => $warnings];
    }

    /**
     * Keep preset resolution local to one stateless unit invocation. Values,
     * rather than slug existence alone, let the contract distinguish a real
     * painted preset from a declared but transparent or malformed one.
     *
     * @param string|array<mixed>|null $themeJson
     * @return array{complete:bool,color:array<string,string>,gradient:array<string,string>,shadow:array<string,string|true>}
     */
    private static function paintPresets(string|array|null $themeJson): array
    {
        $theme = is_array($themeJson) ? $themeJson : null;
        if (is_string($themeJson) && trim($themeJson) !== '') {
            $decoded = json_decode($themeJson, true);
            $theme = is_array($decoded) ? $decoded : null;
        }
        $presets = ['complete' => is_array($theme), 'color' => [], 'gradient' => [], 'shadow' => []];
        if (($theme['settings']['shadow']['defaultPresets'] ?? null) !== false) {
            foreach (self::CORE_SHADOW_SLUGS as $slug) {
                // WordPress's five built-in shadow presets all have visible
                // geometry and non-transparent color.
                $presets['shadow'][$slug] = true;
            }
        }
        foreach (($theme['settings']['color']['palette'] ?? []) as $preset) {
            if (!is_array($preset)
                || !is_string($preset['slug'] ?? null)
                || !is_string($preset['color'] ?? null)
            ) {
                continue;
            }
            $slug = strtolower(trim($preset['slug']));
            if (preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $slug) === 1) {
                $presets['color'][$slug] = $preset['color'];
            }
        }
        foreach (($theme['settings']['color']['gradients'] ?? []) as $preset) {
            if (!is_array($preset)
                || !is_string($preset['slug'] ?? null)
                || !is_string($preset['gradient'] ?? null)
            ) {
                continue;
            }
            $slug = strtolower(trim($preset['slug']));
            if (preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $slug) === 1) {
                $presets['gradient'][$slug] = $preset['gradient'];
            }
        }
        foreach (($theme['settings']['shadow']['presets'] ?? []) as $preset) {
            if (!is_array($preset)
                || !is_string($preset['slug'] ?? null)
                || !is_string($preset['shadow'] ?? null)
            ) {
                continue;
            }
            $slug = strtolower(trim($preset['slug']));
            if (preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $slug) === 1) {
                $presets['shadow'][$slug] = $preset['shadow'];
            }
        }
        return $presets;
    }

    private static function hasUnrepresentedCardHook(string $markup): bool
    {
        $offset = 0;
        while (($start = strpos($markup, '<!-- wp:group', $offset)) !== false) {
            $end = strpos($markup, '-->', $start + 13);
            if ($end === false) {
                $end = min(strlen($markup), $start + 131072);
            }
            $delimiter = substr($markup, $start, $end - $start);
            if (str_contains($delimiter, self::MARKER_PREFIX)
                || str_contains($delimiter, 'card-flush')
                || str_contains($delimiter, 'overlap-up')
                || str_contains($delimiter, 'card-body')
            ) {
                return true;
            }
            $offset = $end + 3;
        }
        return false;
    }

    /** @return list<int> */
    private static function candidateIndices(BlockMarkup $document): array
    {
        $candidates = [];
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'group') {
                continue;
            }
            $classes = self::nodeClasses($document, $index);
            $recoverable = self::recoverableSavedWrapperHooks($document, $index);
            $locatorClasses = is_array($recoverable)
                ? array_values(array_unique(array_merge($classes, $recoverable)))
                : $classes;
            $explicit = self::treatmentMarkers($locatorClasses) !== []
                || in_array('card-flush', $locatorClasses, true);
            $directImage = self::firstDirectImage($document, $index);
            if ($explicit) {
                $candidates[] = $index;
                continue;
            }
            // Inference never promotes the section root itself into a card.
            // Equal grids have an explicit structural card position. The two
            // other column-card recipes are recognizable only when a column
            // consists of one group whose primary image carries a card crop;
            // this keeps generic and masonry media groups out of the domain.
            if ($document->parent($index) === null || $directImage === null) {
                continue;
            }
            if (self::isInEqualCardsColumn($document, $index)
                || self::isInOrdinaryCardColumn($document, $index, $directImage)
            ) {
                $candidates[] = $index;
            }
        }
        $candidates = array_values(array_unique($candidates));
        usort(
            $candidates,
            static fn (int $left, int $right): int =>
                $document->openingOffset($left) <=> $document->openingOffset($right),
        );

        // Suppress a text-wrapper candidate only when its immediate parent is
        // itself retained and can report/repair that wrapper. A marker-only
        // child of an already-suppressed body must remain a candidate so its
        // harmful hooks are warned on the first pass, not discovered only after
        // the parent normalizes. Nested image cards are quarantined by their
        // nearest retained candidate ancestor, which reports the whole list.
        $retained = [];
        $retainedSet = [];
        $quarantinedRoots = [];
        foreach ($candidates as $index) {
            $parent = $document->parent($index);
            $owned = self::isWithinSubtree($document, $index, $quarantinedRoots);
            if (!$owned && self::firstDirectImage($document, $index) === null) {
                $owned = $parent !== null
                    && isset($retainedSet[$parent])
                    && $document->name($parent) === 'group';
            } elseif (!$owned) {
                $cursor = $parent;
                while ($cursor !== null) {
                    if (isset($retainedSet[$cursor])) {
                        $owned = true;
                        break;
                    }
                    $cursor = $document->parent($cursor);
                }
            }
            if (!$owned) {
                $retained[] = $index;
                $retainedSet[$index] = true;
                foreach (self::nestedImageGroups($document, $index) as $nestedRoot) {
                    $quarantinedRoots[$nestedRoot] = true;
                }
            }
        }
        return $retained;
    }

    /**
     * @return array{
     *   index:int,path:string,attrs:array<mixed>,classes:list<string>,markers:list<string>,
     *   attributeClasses:list<string>,htmlClasses:?list<string>,
     *   direct:list<int>,directNames:list<string>,image:?int,imageAttrs:array<mixed>,imageRadius:mixed,
     *   body:?int,bodyAttrs:array<mixed>,bodyClasses:list<string>,
     *   bodyAttributeClasses:list<string>,bodyHtmlClasses:?list<string>,
     *   textGroups:list<array{index:int,path:string,className:mixed,classes:list<string>,attributeClasses:list<string>,
     *     htmlClasses:?list<string>,recoverableHtmlHooks:?list<string>,savedWrapper:?string,
     *     directNames:list<string>}>,
     *   nestedCards:list<array{index:int,path:string,classes:list<string>,attributeClasses:list<string>,
     *     htmlClasses:?list<string>,recoverableHtmlHooks:?list<string>,savedWrapper:?string,
     *     direct_children:list<string>}>,
     *   nestedReservedGroups:list<array{index:int,path:string,attribute_reserved_hooks:list<string>,
     *     html_reserved_hooks:?list<string>,recoverable_html_reserved_hooks:?list<string>,
     *     saved_wrapper:?string}>,
     *   surface:bool,paintedBox:bool,boxResidue:bool,padding:mixed,blockGap:mixed
     * }
     */
    private static function inspect(BlockMarkup $document, int $index, array $paintPresets): array
    {
        $attrs = $document->attrs($index) ?? [];
        $direct = $document->children($index);
        $image = self::firstDirectImage($document, $index);
        $groups = array_values(array_filter(
            $direct,
            static fn (int $child): bool => $document->name($child) === 'group',
        ));
        $textGroupIndices = array_values(array_filter(
            $groups,
            static fn (int $group): bool => self::firstDirectImage($document, $group) === null,
        ));
        $body = count($textGroupIndices) === 1 ? $textGroupIndices[0] : null;
        $textGroups = array_map(
            static function (int $group) use ($document): array {
                $groupAttrs = $document->attrs($group) ?? [];
                return [
                    'index' => $group,
                    'path' => self::blockPath($document, $group),
                    'className' => $groupAttrs['className'] ?? null,
                    'classes' => self::nodeClasses($document, $group),
                    'attributeClasses' => self::attributeClasses($groupAttrs),
                    'htmlClasses' => self::htmlClasses($document, $group),
                    'recoverableHtmlHooks' => self::recoverableSavedWrapperHooks($document, $group),
                    'savedWrapper' => self::savedWrapperBytes($document, $group),
                    'directNames' => array_map(
                        static fn (int $child): string => 'wp:' . $document->name($child),
                        $document->children($group),
                    ),
                ];
            },
            $textGroupIndices,
        );
        $nestedCardIndices = self::nestedImageGroups($document, $index);
        $nestedCards = array_map(
            static function (int $group) use ($document): array {
                $attrs = $document->attrs($group) ?? [];
                return [
                    'index' => $group,
                    'path' => self::blockPath($document, $group),
                    'classes' => self::nodeClasses($document, $group),
                    'attributeClasses' => self::attributeClasses($attrs),
                    'htmlClasses' => self::htmlClasses($document, $group),
                    'recoverableHtmlHooks' => self::recoverableSavedWrapperHooks($document, $group),
                    'savedWrapper' => self::savedWrapperBytes($document, $group),
                    'direct_children' => array_map(
                        static fn (int $child): string => 'wp:' . $document->name($child),
                        $document->children($group),
                    ),
                ];
            },
            $nestedCardIndices,
        );
        $nestedReservedGroups = self::nestedReservedHookGroups(
            $document,
            array_fill_keys($nestedCardIndices, true),
        );
        $bodyAttrs = $body === null ? [] : ($document->attrs($body) ?? []);
        $imageAttrs = $image === null ? [] : ($document->attrs($image) ?? []);
        $classes = self::nodeClasses($document, $index);
        $attributeClasses = self::attributeClasses($attrs);
        $htmlClasses = self::htmlClasses($document, $index);
        $bodyClasses = $body === null ? [] : self::nodeClasses($document, $body);
        $bodyAttributeClasses = self::attributeClasses($bodyAttrs);
        $bodyHtmlClasses = $body === null ? [] : self::htmlClasses($document, $body);

        return [
            'index' => $index,
            'path' => self::blockPath($document, $index),
            'attrs' => $attrs,
            'classes' => $classes,
            'markers' => self::treatmentMarkers($classes),
            'attributeClasses' => $attributeClasses,
            'htmlClasses' => $htmlClasses,
            'direct' => $direct,
            'directNames' => array_map(
                static fn (int $child): string => 'wp:' . $document->name($child),
                $direct,
            ),
            'image' => $image,
            'imageAttrs' => $imageAttrs,
            'imageRadius' => self::nested($imageAttrs, ['style', 'border', 'radius']),
            'body' => $body,
            'bodyAttrs' => $bodyAttrs,
            'bodyClasses' => $bodyClasses,
            'bodyAttributeClasses' => $bodyAttributeClasses,
            'bodyHtmlClasses' => $bodyHtmlClasses,
            'textGroups' => $textGroups,
            'nestedCards' => $nestedCards,
            'nestedReservedGroups' => $nestedReservedGroups,
            'surface' => self::hasPaintedSurface($attrs, $paintPresets),
            'paintedBox' => self::hasPaintedBox($attrs, $paintPresets),
            'boxResidue' => self::hasBoxResidue($attrs, $paintPresets),
            'padding' => self::nested($attrs, ['style', 'spacing', 'padding']),
            'blockGap' => self::nested($attrs, ['style', 'spacing', 'blockGap']),
        ];
    }

    /**
     * @param array<string,mixed> $inspection
     * @return list<array{field:string,authored:mixed,required:mixed,message:string}>
     */
    private static function anatomyIssues(array $inspection, string $style, array $paintPresets): array
    {
        $issues = [];
        $direct = $inspection['direct'];
        $image = $inspection['image'];
        $directImageCount = count(array_filter(
            $inspection['directNames'],
            static fn (string $name): bool => $name === 'wp:image',
        ));
        if ($image === null) {
            $issues[] = self::issue(
                'direct_children',
                $inspection['directNames'],
                'a direct wp:image child',
                'the marked card has no direct wp:image child',
            );
        } else {
            if ($directImageCount !== 1) {
                $issues[] = self::issue(
                    'direct_images',
                    $inspection['directNames'],
                    'exactly one direct wp:image child',
                    'the card does not have exactly one direct primary image',
                );
            }
            if (($direct[0] ?? null) !== $image) {
                $issues[] = self::issue(
                    'direct_children',
                    $inspection['directNames'],
                    ['wp:image', 'wp:group'],
                    'the card image is not its first direct child',
                );
            }
        }
        foreach ($inspection['nestedCards'] as $nestedCard) {
            $issues[] = self::issue(
                'nested_image_card_index',
                $nestedCard['index'],
                'no nested image-card block index',
                'the exact nested image-card block index was retained for repair',
            );
            $issues[] = self::issue(
                'nested_image_card_path',
                $nestedCard['path'],
                'no image-card group nested anywhere inside an outer card subtree',
                'an image-card group is nested inside the outer card subtree',
            );
            $issues[] = self::issue(
                'nested_image_card_classes',
                $nestedCard['classes'],
                'no nested image-card wrapper classes',
                'the nested image-card classes were retained unchanged',
            );
            $issues[] = self::issue(
                'nested_image_card_attribute_reserved_hooks',
                self::reservedHooks($nestedCard['attributeClasses']),
                'removed with the nested card or after it is flattened',
                'the nested image-card comment hooks remain active',
            );
            $issues[] = self::issue(
                'nested_image_card_html_reserved_hooks',
                is_array($nestedCard['htmlClasses'])
                    ? self::reservedHooks($nestedCard['htmlClasses'])
                    : null,
                'removed with the nested card or after it is flattened',
                'the nested image-card saved-HTML hooks remain active or are not safely inspectable',
            );
            if ($nestedCard['htmlClasses'] === null) {
                $issues[] = self::issue(
                    'nested_image_card_recoverable_html_reserved_hooks',
                    $nestedCard['recoverableHtmlHooks'],
                    'removed with the nested card or after it is flattened',
                    'recoverable reserved hooks survive in the malformed nested saved wrapper',
                );
                $issues[] = self::issue(
                    'nested_image_card_saved_wrapper',
                    $nestedCard['savedWrapper'],
                    'one safely parseable nested-card opening tag',
                    'the malformed nested-card root bytes were retained for repair',
                );
            }
            $issues[] = self::issue(
                'nested_image_card_direct_children',
                $nestedCard['direct_children'],
                'the nested card flattened into the outer card content',
                'the nested image-card child structure was retained unchanged',
            );
        }
        foreach ($inspection['nestedReservedGroups'] as $nestedGroup) {
            $issues[] = self::issue(
                'nested_reserved_group_index',
                $nestedGroup['index'],
                'no reserved-hook group index inside the nested card quarantine',
                'the exact reserved-hook block index was retained for repair',
            );
            $issues[] = self::issue(
                'nested_reserved_group_path',
                $nestedGroup['path'],
                'remove or flatten the nested card; no reserved card hooks remain inside the outer card subtree',
                'reserved hooks inside the quarantined nested-card subtree were retained unchanged for repair',
            );
            $issues[] = self::issue(
                'nested_reserved_attribute_hooks',
                $nestedGroup['attribute_reserved_hooks'],
                [],
                'the quarantined nested group comment hooks remain active',
            );
            $issues[] = self::issue(
                'nested_reserved_html_hooks',
                $nestedGroup['html_reserved_hooks'],
                [],
                'the quarantined nested group saved-HTML hooks remain active or are not safely inspectable',
            );
            if ($nestedGroup['html_reserved_hooks'] === null) {
                $issues[] = self::issue(
                    'nested_recoverable_html_reserved_hooks',
                    $nestedGroup['recoverable_html_reserved_hooks'],
                    [],
                    'recoverable hooks survive in the malformed quarantined saved wrapper',
                );
                $issues[] = self::issue(
                    'nested_reserved_saved_wrapper',
                    $nestedGroup['saved_wrapper'],
                    'one safely parseable nested opening tag',
                    'the malformed nested root bytes were retained for repair',
                );
            }
        }
        array_push($issues, ...self::textBodyTopologyIssues($inspection, $style));

        if (in_array($style, ['flush', 'overlap'], true)) {
            if (!$inspection['surface']) {
                array_push($issues, ...self::paintAbsenceIssues(
                    'outer_surface',
                    self::surfaceEvidence($inspection['attrs']),
                    'an actual non-transparent background color or gradient',
                    "the {$style} outer card has no painted background surface",
                ));
            }
            if (!self::isZero($inspection['blockGap'])) {
                $issues[] = self::issue(
                    'outer_block_gap',
                    $inspection['blockGap'],
                    '0',
                    'outer card blockGap is not zero',
                );
            }
            if (count($direct) !== 2
                || ($inspection['body'] === null && $inspection['nestedCards'] === [])
            ) {
                $issues[] = self::issue(
                    'direct_children',
                    $inspection['directNames'],
                    ['wp:image', 'wp:group'],
                    'flush anatomy requires exactly one image followed by one text-body wp:group',
                );
            } elseif (!self::hasAllSidePadding(self::nested(
                $inspection['bodyAttrs'],
                ['style', 'spacing', 'padding'],
            ))) {
                $issues[] = self::issue(
                    'body_padding',
                    self::nested($inspection['bodyAttrs'], ['style', 'spacing', 'padding']),
                    'non-zero top/right/bottom/left padding',
                    'the text-body group does not carry padding on all four sides',
                );
            }
            $importantPadding = self::importantPaddingResidueEvidence($inspection['padding']);
            if ($importantPadding !== []) {
                $issues[] = self::issue(
                    'outer_padding',
                    $importantPadding,
                    'normal-priority padding that the card-flush reset can neutralize, or no padding',
                    'author-important outer padding outranks the card-flush reset',
                );
            }
            $importantRadius = self::importantRadiusResidueEvidence($inspection['imageRadius']);
            if ($importantRadius !== []) {
                $issues[] = self::issue(
                    'image_radius',
                    $importantRadius,
                    'a normal-priority radius that the card-flush reset can neutralize, or no radius',
                    'an author-important image radius outranks the card-flush reset',
                );
            }
        }

        if ($style === 'overlap' && $inspection['body'] !== null) {
            if (!self::hasPaintedSurface($inspection['bodyAttrs'], $paintPresets)) {
                array_push($issues, ...self::paintAbsenceIssues(
                    'body_background',
                    self::surfaceEvidence($inspection['bodyAttrs']),
                    'an explicit text-panel background',
                    'the overlap text panel has no own background',
                ));
            }
            $bodyMargin = self::nested($inspection['bodyAttrs'], ['style', 'spacing', 'margin']);
            $marginSides = self::spacingSideValues($bodyMargin, 'margin');
            $left = $marginSides['left'] ?? null;
            $right = $marginSides['right'] ?? null;
            if (!self::cssLengthEquals($left, 'rem', 1.0)
                || !self::cssLengthEquals($right, 'rem', 1.0)
            ) {
                $issues[] = self::issue(
                    'body_side_margins',
                    ['left' => $left, 'right' => $right],
                    ['left' => '1rem', 'right' => '1rem'],
                    'the overlap text panel does not carry the required 1rem side margins',
                );
            }
        }

        if (in_array($style, ['flush', 'overlap', 'framed', 'borderless'], true)
            && $inspection['body'] !== null
        ) {
            $bodyMargin = self::nested($inspection['bodyAttrs'], ['style', 'spacing', 'margin']);
            $marginSides = self::spacingSideValues($bodyMargin, 'margin');
            $hasBodyTop = $marginSides !== null
                && array_key_exists('top', $marginSides)
                && !self::isAbsent($marginSides['top']);
            $bodyTop = $hasBodyTop ? $marginSides['top'] : null;
            $bodyTopState = self::marginSideState($bodyTop);
            $topConflicts = $style === 'overlap'
                ? ($hasBodyTop && !in_array($bodyTopState, ['invalid', 'underlying'], true))
                : in_array($bodyTopState, ['nonzero', 'unknown'], true);
            if ($topConflicts) {
                $issues[] = self::issue(
                    'body_top_margin',
                    $bodyTop,
                    $style === 'overlap'
                        ? 'absent; overlap position is supplied only by the overlap-up hook'
                        : '0 or absent',
                    $style === 'overlap'
                        ? 'the authored top margin would override the overlap-up behavior hook'
                        : 'the text body carries a stale authored top margin that would imitate overlap',
                );
            }

            if ($style !== 'overlap') {
                $bodySurfaceResidue = self::paintedSurfaceValues(
                    $inspection['bodyAttrs'],
                    true,
                    $paintPresets,
                );
                if ($bodySurfaceResidue !== []) {
                    array_push($issues, ...self::paintAbsenceIssues(
                        'body_background',
                        $bodySurfaceResidue,
                        'removed; only overlap uses its own text-panel background',
                        "the {$style} text body retains a stale overlap-panel background",
                    ));
                }
                $left = $marginSides['left'] ?? null;
                $right = $marginSides['right'] ?? null;
                $leftConflicts = self::boxSideIsImportant($bodyMargin, 'left')
                    && in_array(self::marginSideState($left), ['nonzero', 'unknown'], true);
                $rightConflicts = self::boxSideIsImportant($bodyMargin, 'right')
                    && in_array(self::marginSideState($right), ['nonzero', 'unknown'], true);
                if ($leftConflicts || $rightConflicts) {
                    $issues[] = self::issue(
                        'body_side_margins',
                        ['left' => $left, 'right' => $right],
                        ['left' => '0', 'right' => '0'],
                        "the {$style} text body retains stale overlap-panel side margins",
                    );
                }
            }
        }

        if ($style === 'framed') {
            if (!$inspection['paintedBox']) {
                array_push($issues, ...self::paintAbsenceIssues(
                    'outer_box',
                    self::paintEvidence($inspection['attrs']),
                    'a non-transparent background/gradient, visible shadow, or visible border',
                    'the framed card has no actual visual paint',
                ));
            }
            if (!self::hasAllSidePadding($inspection['padding'])) {
                $issues[] = self::issue(
                    'outer_padding',
                    $inspection['padding'],
                    'non-zero top/right/bottom/left padding',
                    'the framed card does not carry padding on all four sides',
                );
            }
            $cardRadius = self::nested($inspection['attrs'], ['style', 'border', 'radius']);
            $comparable = self::comparableFramedRadii(
                $cardRadius,
                $inspection['padding'],
                $inspection['imageRadius'],
            );
            if ($comparable === null) {
                $issues[] = self::issue(
                    'framed_radii',
                    [
                        'card' => $cardRadius,
                        'padding' => $inspection['padding'],
                        'image' => $inspection['imageRadius'],
                    ],
                    [
                        'card' => 'scalar px',
                        'padding' => 'uniform non-zero scalar px on all four sides',
                        'image' => 'scalar px',
                        'formula' => 'max(card radius - uniform padding, 2px)',
                    ],
                    'the framed card is missing scalar pixel radii or uses per-corner, non-pixel, or non-uniform values whose concentric radius cannot be verified safely',
                );
            } elseif (!$comparable['matches']) {
                $issues[] = self::issue(
                    'framed_radii',
                    [
                        'card' => $cardRadius,
                        'padding' => $inspection['padding'],
                        'image' => $inspection['imageRadius'],
                    ],
                    [
                        'image' => $comparable['expected'],
                        'formula' => 'max(card radius - uniform padding, 2px)',
                    ],
                    'the framed card image radius does not satisfy the concentric pixel formula',
                );
            }
        }

        if ($style === 'borderless') {
            if ($inspection['boxResidue']) {
                foreach (self::boxResidueEvidence(
                    $inspection['attrs'],
                    true,
                    $paintPresets,
                ) as $field => $value) {
                    $issues[] = self::issue(
                        'outer_box_' . str_replace('.', '_', $field),
                        $value,
                        'removed',
                        "the borderless card still carries {$field}",
                    );
                }
            }
            $paddingResidue = self::possiblePaddingResidueEvidence($inspection['padding']);
            if ($paddingResidue !== []) {
                $issues[] = self::issue(
                    'outer_padding',
                    $paddingResidue,
                    '0',
                    'the borderless card still carries outer padding',
                );
            }
        }

        $cardClass = $inspection['attrs']['className'] ?? null;
        if ($cardClass !== null && !is_string($cardClass)) {
            $issues[] = self::issue(
                'outer_className',
                $cardClass,
                'a space-separated string',
                'the outer card className is not a string',
            );
        }
        if ($inspection['body'] !== null) {
            $bodyClass = $inspection['bodyAttrs']['className'] ?? null;
            if ($bodyClass !== null && !is_string($bodyClass)) {
                $issues[] = self::issue(
                    'body_className',
                    $bodyClass,
                    'a space-separated string',
                    'the text-body className is not a string',
                );
            }
        }

        // Outer padding and a direct-image radius are repairable only after a
        // structurally safe card receives the verified card-flush hook. If
        // another defect prevents that transaction, keep their exact authored
        // values in the warning instead of hiding why the old inset rendering
        // survives.
        if ($issues !== []) {
            if (in_array($style, ['flush', 'overlap'], true)) {
                $issueFields = array_column($issues, 'field');
                $paddingResidue = self::paddingResidueEvidence($inspection['padding']);
                if ($paddingResidue !== [] && !in_array('outer_padding', $issueFields, true)) {
                    $issues[] = self::issue(
                        'outer_padding',
                        $paddingResidue,
                        '0 via a safely delivered card-flush hook',
                        'outer card padding remains inset because the CSS-backed repair was unsafe',
                    );
                }
                if (self::hasRadiusResidue($inspection['imageRadius'])
                    && !in_array('image_radius', $issueFields, true)
                ) {
                    $issues[] = self::issue(
                        'image_radius',
                        $inspection['imageRadius'],
                        '0 via a safely delivered card-flush hook',
                        'the direct image radius remains because the CSS-backed repair was unsafe',
                    );
                }
            }
            array_push($issues, ...self::reservedHookDriftIssues($inspection, $style));
        }

        return $issues;
    }

    /**
     * A card may be flat only for framed/borderless. Once any direct text
     * wrapper exists, it must be the sole content child after the primary
     * image and must contain all related text/actions. Ambiguous or mixed
     * shapes retain their structure and content, while reserved behavior hooks
     * are removed separately at the smallest safely parseable wrapper unit.
     *
     * @param array<string,mixed> $inspection
     * @return list<array{field:string,authored:mixed,required:mixed,message:string}>
     */
    private static function textBodyTopologyIssues(array $inspection, string $style): array
    {
        $groups = $inspection['textGroups'];
        if ($groups === []) {
            return [];
        }
        if (!self::hasUnsafeTextBodyTopology($inspection)) {
            return [];
        }

        $requiredShape = in_array($style, ['flush', 'overlap'], true)
            ? ['wp:image', 'one wp:group containing all text/actions']
            : 'flat direct content with no wp:group, or wp:image plus one wp:group containing all text/actions';
        $issues = [self::issue(
            'text_body_topology',
            $inspection['directNames'],
            $requiredShape,
            count($groups) > 1
                ? 'the card has multiple ambiguous direct text wrappers'
                : 'the card mixes a text wrapper with related content outside that wrapper',
        )];
        foreach ($groups as $group) {
            $issues[] = self::issue(
                'text_body_group_index',
                $group['index'],
                'one canonical body-wrapper block index after the topology is repaired',
                'the exact ambiguous text-wrapper block index was retained for repair',
            );
            $issues[] = self::issue(
                'text_body_group_path',
                $group['path'],
                'one canonical body-wrapper location after the topology is repaired',
                'this direct text wrapper remains part of an ambiguous topology',
            );
            $issues[] = self::issue(
                'text_body_group_attribute_reserved_hooks',
                self::reservedHooks($group['attributeClasses']),
                [],
                'ambiguous wrapper comment hooks must remain removed until one canonical body is chosen',
            );
            $issues[] = self::issue(
                'text_body_group_html_reserved_hooks',
                is_array($group['htmlClasses'])
                    ? self::reservedHooks($group['htmlClasses'])
                    : null,
                [],
                'ambiguous wrapper saved-HTML hooks must remain removed until one canonical body is chosen',
            );
            if ($group['htmlClasses'] === null) {
                $issues[] = self::issue(
                    'text_body_group_recoverable_html_reserved_hooks',
                    $group['recoverableHtmlHooks'],
                    [],
                    'recoverable reserved hooks survive in an unsafe saved wrapper',
                );
                $issues[] = self::issue(
                    'text_body_group_saved_wrapper',
                    $group['savedWrapper'],
                    'one safely parseable opening tag without reserved card hooks',
                    'the ambiguous malformed wrapper was retained transactionally',
                );
            }
            if ($group['className'] !== null && !is_string($group['className'])) {
                $issues[] = self::issue(
                    'text_body_group_className',
                    $group['className'],
                    'a safely tokenizable space-separated string',
                    'the ambiguous wrapper comment className remains malformed',
                );
            }
            $issues[] = self::issue(
                'text_body_group_direct_children',
                $group['directNames'],
                'all related text/actions moved into the eventual canonical body',
                'the direct text-wrapper content structure was retained',
            );
        }
        return $issues;
    }

    /** @param array<string,mixed> $inspection */
    private static function hasUnsafeTextBodyTopology(array $inspection): bool
    {
        $groups = $inspection['textGroups'];
        if ($groups === []) {
            return false;
        }
        return count($groups) !== 1
            || count($inspection['direct']) !== 2
            || ($inspection['direct'][0] ?? null) !== $inspection['image']
            || ($inspection['direct'][1] ?? null) !== $groups[0]['index'];
    }

    /** @param array<string,mixed> $inspection */
    private static function stripUnsafeCardHooks(
        string $markup,
        BlockMarkup $document,
        array $inspection,
        string $style,
        string $part,
    ): array {
        /** @var array<int,array{index:int,path:string,roles:list<string>,hooks:list<string>,all:bool}> $targets */
        $targets = [];
        $addTarget = static function (
            int $index,
            string $path,
            string $role,
            bool $all,
        ) use (&$targets, $document): void {
            $available = self::groupReservedHooks($document, $index);
            $hooks = $all
                ? $available
                : array_values(array_intersect($available, self::DESTRUCTIVE_HOOKS));
            if ($hooks === []) {
                return;
            }
            if (!isset($targets[$index])) {
                $targets[$index] = compact('index', 'path', 'hooks') + [
                    'roles' => [$role],
                    'all' => $all,
                ];
                return;
            }
            $targets[$index]['roles'][] = $role;
            $targets[$index]['roles'] = array_values(array_unique($targets[$index]['roles']));
            $targets[$index]['all'] = $targets[$index]['all'] || $all;
            $targets[$index]['hooks'] = $targets[$index]['all']
                ? $available
                : array_values(array_unique(array_merge($targets[$index]['hooks'], $hooks)));
        };

        // An invalid candidate must not retain behavior that resets its box or
        // repositions it. The removal warning remains its durable repair locus
        // even when card-flush was the only class that exposed the candidate.
        $addTarget($inspection['index'], $inspection['path'], 'blocked card root', false);
        if (is_int($inspection['body'])) {
            $addTarget(
                $inspection['body'],
                self::blockPath($document, $inspection['body']),
                'blocked direct body',
                false,
            );
        }
        if (self::hasUnsafeTextBodyTopology($inspection)) {
            foreach ($inspection['textGroups'] as $group) {
                $addTarget($group['index'], $group['path'], 'ambiguous direct text wrapper', true);
            }
        }
        // Normally the blocked outer marker remains the stable quarantine
        // owner on later passes, so nested treatment markers are harmless and
        // must be retained. The one exception is a root discovered only by a
        // safely removable card-flush hook: once that locator is removed, any
        // nested markers would become newly exposed candidates on pass two.
        // Remove those locator hooks in the same transaction set so one pass
        // reaches the fixed point without broadening ordinary quarantine.
        $rootAttrs = $inspection['attrs'];
        $rootHasMarker = self::treatmentMarkers(self::nodeClasses(
            $document,
            $inspection['index'],
        )) !== [];
        $rootCanBeRewritten = (!isset($rootAttrs['className']) || is_string($rootAttrs['className']))
            && is_array(self::htmlClasses($document, $inspection['index']));
        $rootIsStructurallyInferred = $inspection['image'] !== null
            && $document->parent($inspection['index']) !== null
            && (self::isInEqualCardsColumn($document, $inspection['index'])
                || self::isInOrdinaryCardColumn(
                    $document,
                    $inspection['index'],
                    $inspection['image'],
                ));
        $losesCandidateLocator = !$rootHasMarker
            && !$rootIsStructurallyInferred
            && $rootCanBeRewritten
            && in_array(
                'card-flush',
                self::groupReservedHooks($document, $inspection['index']),
                true,
            );
        foreach ($inspection['nestedReservedGroups'] as $group) {
            $addTarget(
                $group['index'],
                $group['path'],
                'quarantined nested-card group',
                $losesCandidateLocator,
            );
        }
        foreach ($inspection['nestedCards'] as $group) {
            $addTarget(
                $group['index'],
                $group['path'],
                'quarantined nested-card root',
                $losesCandidateLocator,
            );
        }

        $groups = array_values($targets);
        usort(
            $groups,
            static fn (array $left, array $right): int =>
                $document->openingOffset($right['index']) <=> $document->openingOffset($left['index']),
        );
        $repairs = [];
        $warnings = [];
        $changed = false;
        foreach ($groups as $group) {
            $index = $group['index'];
            $attrs = $document->attrs($index) ?? [];
            $authored = self::wrapperHookEvidence($document, $index);
            $delivered = $authored;
            $failure = null;
            $removedHooks = $group['hooks'];
            $className = $attrs['className'] ?? null;
            if ($className !== null && !is_string($className)) {
                $failure = 'the comment className is not a safely tokenizable string';
            } elseif (!is_array(self::htmlClasses($document, $index))) {
                $failure = 'the saved wrapper does not have one safely parseable class attribute';
            }
            $operations = null;
            $deliveredDocument = null;
            if ($failure === null && $removedHooks !== []) {
                $attributeClasses = self::attributeClasses($attrs);
                $htmlClasses = self::htmlClasses($document, $index);
                $operations = is_array($htmlClasses)
                    ? self::nodeClassOperations(
                        $document,
                        $index,
                        $attrs,
                        self::withoutHooks($attributeClasses, $removedHooks),
                        self::withoutHooks($htmlClasses, $removedHooks),
                    )
                    : null;
                if ($operations === null) {
                    $failure = 'the wrapper class edits could not be bounded safely';
                } else {
                    $transaction = self::applyOperations($markup, $operations);
                    if ($transaction['error'] !== null) {
                        $failure = $transaction['error'];
                    } else {
                        try {
                            $candidateDocument = BlockMarkup::parse($transaction['markup']);
                            $postcondition = self::groupHooksAreRemoved(
                                $candidateDocument,
                                $index,
                                $removedHooks,
                            );
                        } catch (\Throwable $error) {
                            $postcondition = false;
                            $failure = str_replace(["\r", "\n"], ' ', $error->getMessage());
                        }
                        if (!$postcondition) {
                            $failure ??= 'the wrapper hook-removal postcondition failed';
                        } else {
                            $deliveredDocument = $candidateDocument;
                        }
                    }
                }
            }
            if ($failure === null && is_array($operations) && $operations !== []
                && $deliveredDocument instanceof BlockMarkup
            ) {
                $markup = $transaction['markup'];
                $changed = true;
                $delivered = self::wrapperHookEvidence($deliveredDocument, $index);
                $ambiguous = in_array('ambiguous direct text wrapper', $group['roles'], true);
                $repairs[] = [
                    'code' => $ambiguous
                        ? 'ambiguous-card-wrapper-hooks-removed'
                        : 'unsafe-card-behavior-hooks-removed',
                    'part' => $part,
                    'path' => $group['path'],
                    'authored' => $authored,
                    'delivered' => $delivered,
                    'disposition' => 'removed ' . implode(', ', $removedHooks)
                        . ' from one unsafe card group while preserving unrelated classes, structure, and content',
                ];
            }
            if ($failure !== null || (is_array($operations) && $operations !== [])) {
                $warnings[] = self::cardHookWarning(
                    $part,
                    $group['path'],
                    $index,
                    $style,
                    implode(' / ', $group['roles']),
                    $group['hooks'],
                    $authored,
                    $delivered,
                    $failure,
                );
            }
        }

        return compact('markup', 'repairs', 'warnings', 'changed');
    }

    /** @param list<string> $hooks */
    private static function groupHooksAreRemoved(BlockMarkup $document, int $index, array $hooks): bool
    {
        $attrs = $document->attrs($index) ?? [];
        $htmlClasses = self::htmlClasses($document, $index);
        return $document->name($index) === 'group'
            && (!isset($attrs['className']) || is_string($attrs['className']))
            && array_intersect(self::attributeClasses($attrs), $hooks) === []
            && is_array($htmlClasses)
            && array_intersect($htmlClasses, $hooks) === [];
    }

    /** @return list<string> */
    private static function groupReservedHooks(BlockMarkup $document, int $index): array
    {
        $evidence = self::wrapperHookEvidence($document, $index);
        $hooks = [];
        foreach (['attribute_reserved_hooks', 'html_reserved_hooks', 'recoverable_html_reserved_hooks'] as $field) {
            if (is_array($evidence[$field] ?? null)) {
                array_push($hooks, ...$evidence[$field]);
            }
        }
        return array_values(array_unique($hooks));
    }

    /** @return array<string,mixed> */
    private static function wrapperHookEvidence(BlockMarkup $document, int $index): array
    {
        $attrs = $document->attrs($index) ?? [];
        $html = self::htmlClasses($document, $index);
        $evidence = [
            'attribute_reserved_hooks' => self::reservedHooks(self::attributeClasses($attrs)),
            'html_reserved_hooks' => is_array($html) ? self::reservedHooks($html) : null,
            'saved_wrapper' => self::savedWrapperBytes($document, $index),
        ];
        $className = $attrs['className'] ?? null;
        if ($className !== null && !is_string($className)) {
            $evidence['comment_className'] = $className;
        }
        if ($html === null) {
            $evidence['recoverable_html_reserved_hooks'] = self::recoverableSavedWrapperHooks(
                $document,
                $index,
            );
        }
        return $evidence;
    }

    /** @param array<string,mixed> $authored @param array<string,mixed> $delivered */
    private static function cardHookWarning(
        string $part,
        string $path,
        int $index,
        string $style,
        string $role,
        array $targetedHooks,
        array $authored,
        array $delivered,
        ?string $failure,
    ): string {
        $format = static fn (array $evidence): string => implode(', ', array_map(
            static fn (string $field, mixed $value): string => $field . '=' . Warnings::value($value),
            array_keys($evidence),
            array_values($evidence),
        ));
        $disposition = $failure === null
            ? 'removed targeted hooks; unrelated classes, structure, content, and siblings were retained'
            : 'left this wrapper unchanged because ' . $failure;
        return "file='theme/parts/{$part}.html'; block='{$path}'; block_index={$index}; role="
            . Warnings::value($role) . '; targeted_hooks=' . Warnings::value($targetedHooks)
            . '; authored={' . $format($authored)
            . '}; delivered={' . $format($delivered) . "}; disposition=assigned card_style '{$style}' "
            . 'has unsafe active card hooks: ' . $disposition;
    }

    /**
     * @param array<string,mixed> $inspection
     * @return array{operations:list<array{start:int,length:int,expected:string,replacement:string}>}|null
     */
    private static function classRepair(BlockMarkup $document, array $inspection, string $style): ?array
    {
        $operations = [];
        $card = $inspection['index'];
        $cardAttributeClasses = self::attributeClasses($inspection['attrs']);
        $cardHtmlClasses = self::htmlClasses($document, $card);
        if ($cardHtmlClasses === null) {
            return null;
        }
        $desiredAttributeClasses = self::cardClasses($cardAttributeClasses, $style);
        $desiredHtmlClasses = self::cardClasses($cardHtmlClasses, $style);
        $cardOps = self::nodeClassOperations(
            $document,
            $card,
            $inspection['attrs'],
            $desiredAttributeClasses,
            $desiredHtmlClasses,
        );
        if ($cardOps === null) {
            return null;
        }
        array_push($operations, ...$cardOps);

        $body = $inspection['body'];
        if (in_array($style, ['flush', 'overlap'], true) && !is_int($body)) {
            return null;
        }
        if (is_int($body)) {
            $bodyHtmlClasses = self::htmlClasses($document, $body);
            if ($bodyHtmlClasses === null) {
                return null;
            }
            $bodyAttributeClasses = self::attributeClasses($inspection['bodyAttrs']);
            $desiredBodyAttributeClasses = self::bodyClasses($bodyAttributeClasses, $style);
            $desiredBodyHtmlClasses = self::bodyClasses($bodyHtmlClasses, $style);
            $bodyOps = self::nodeClassOperations(
                $document,
                $body,
                $inspection['bodyAttrs'],
                $desiredBodyAttributeClasses,
                $desiredBodyHtmlClasses,
            );
            if ($bodyOps === null) {
                return null;
            }
            array_push($operations, ...$bodyOps);
        }

        return ['operations' => $operations];
    }

    /**
     * @param array<mixed> $attrs
     * @param list<string> $attributeClasses
     * @param list<string> $htmlClasses
     * @return list<array{start:int,length:int,expected:string,replacement:string}>|null
     */
    private static function nodeClassOperations(
        BlockMarkup $document,
        int $index,
        array $attrs,
        array $attributeClasses,
        array $htmlClasses,
    ): ?array {
        $operations = [];
        $currentAttributeClasses = self::attributeClasses($attrs);
        if ($currentAttributeClasses !== $attributeClasses) {
            if ($attributeClasses === []) {
                unset($attrs['className']);
            } else {
                $attrs['className'] = implode(' ', $attributeClasses);
            }
            $operations[] = [
                'start' => $document->openingOffset($index),
                'length' => $document->openingLength($index),
                'expected' => $document->openingComment($index),
                'replacement' => BlockMarkup::serializeComment(
                    $document->name($index),
                    $attrs,
                    $document->isVoid($index),
                ),
            ];
        }

        $tag = self::openingTag($document, $index);
        if ($tag === null) {
            return null;
        }
        $currentHtmlClasses = self::classesInTag($tag['tag']);
        if ($currentHtmlClasses === null) {
            return null;
        }
        if ($currentHtmlClasses !== $htmlClasses) {
            $replacement = self::tagWithClasses($tag['tag'], $htmlClasses);
            if ($replacement === null) {
                return null;
            }
            $operations[] = [
                'start' => $tag['offset'],
                'length' => strlen($tag['tag']),
                'expected' => $tag['tag'],
                'replacement' => $replacement,
            ];
        }

        return $operations;
    }

    /**
     * @param list<array{start:int,length:int,expected:string,replacement:string}> $operations
     * @return array{markup:string,error:?string}
     */
    private static function applyOperations(string $snapshot, array $operations): array
    {
        $ascending = $operations;
        usort($ascending, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        $byteLength = strlen($snapshot);
        $previousEnd = -1;
        foreach ($ascending as $position => $operation) {
            $start = $operation['start'];
            $length = $operation['length'];
            if ($start < 0 || $length < 0 || $start > $byteLength || $length > $byteLength - $start) {
                return [
                    'markup' => $snapshot,
                    'error' => "operation {$position} range {$start}+{$length} exceeds {$byteLength} bytes",
                ];
            }
            if ($start < $previousEnd) {
                return [
                    'markup' => $snapshot,
                    'error' => "operation {$position} overlaps the preceding range ending at {$previousEnd}",
                ];
            }
            $actual = substr($snapshot, $start, $length);
            if (strlen($operation['expected']) !== $length || $actual !== $operation['expected']) {
                return [
                    'markup' => $snapshot,
                    'error' => "operation {$position} expected " . Warnings::value($operation['expected'])
                        . ' but found ' . Warnings::value($actual),
                ];
            }
            $previousEnd = $start + $length;
        }

        usort($operations, static fn (array $left, array $right): int => $right['start'] <=> $left['start']);
        $candidate = $snapshot;
        foreach ($operations as $operation) {
            $candidate = substr_replace(
                $candidate,
                $operation['replacement'],
                $operation['start'],
                $operation['length'],
            );
        }
        return ['markup' => $candidate, 'error' => null];
    }

    /**
     * @return list<array{field:string,authored:mixed,required:mixed,message:string}>
     */
    private static function postconditionIssues(
        BlockMarkup $document,
        int $index,
        string $style,
        array $paintPresets,
    ): array
    {
        if ($document->name($index) !== 'group') {
            return [self::issue(
                'postcondition_block',
                'wp:' . $document->name($index),
                'wp:group',
                'the repaired card index no longer identifies a group',
            )];
        }
        $marker = self::MARKER_PREFIX . $style;
        $attrs = $document->attrs($index) ?? [];
        $attributeClasses = self::attributeClasses($attrs);
        $htmlClasses = self::htmlClasses($document, $index);
        if ($htmlClasses === null) {
            return [self::issue(
                'postcondition_wrapper',
                $document->ownHtml($index),
                'a normal saved HTML wrapper with synchronized classes',
                'the repaired card wrapper is not safely inspectable',
            )];
        }
        $issues = [];
        $attributeMarkers = self::treatmentMarkers($attributeClasses);
        $htmlMarkers = self::treatmentMarkers($htmlClasses);
        if ($attributeMarkers !== [$marker] || $htmlMarkers !== [$marker]) {
            $issues[] = self::issue(
                'outer_marker',
                ['attribute' => $attributeMarkers, 'html' => $htmlMarkers],
                ['attribute' => [$marker], 'html' => [$marker]],
                'the assigned outer treatment marker was not mirrored exactly once',
            );
        }

        $needsFlush = in_array($style, ['flush', 'overlap'], true);
        $attributeFlush = in_array('card-flush', $attributeClasses, true);
        $htmlFlush = in_array('card-flush', $htmlClasses, true);
        if ($attributeFlush !== $needsFlush || $htmlFlush !== $needsFlush) {
            $issues[] = self::issue(
                'card_flush_hook',
                ['attribute' => $attributeFlush, 'html' => $htmlFlush],
                ['attribute' => $needsFlush, 'html' => $needsFlush],
                'the card-flush behavior hook did not match the assigned treatment',
            );
        }
        foreach (['attribute' => $attributeClasses, 'html' => $htmlClasses] as $source => $classes) {
            $misplacedBodyHooks = array_values(array_intersect($classes, ['card-body', 'overlap-up']));
            if ($misplacedBodyHooks !== []) {
                $issues[] = self::issue(
                    "outer_body_hooks_{$source}",
                    $misplacedBodyHooks,
                    'removed; body behavior hooks belong on the inner text group only',
                    "the {$source} outer card retains inner-only body behavior hooks",
                );
            }
        }

        $inspection = self::inspect($document, $index, $paintPresets);
        $body = $inspection['body'];
        if (!is_int($body)) {
            if ($needsFlush) {
                $issues[] = self::issue(
                    'body_hooks',
                    null,
                    $style === 'overlap' ? ['card-body', 'overlap-up'] : ['card-body'],
                    'the repaired card has no uniquely identifiable body group',
                );
            }
            return $issues;
        }
        $bodyAttributeClasses = self::attributeClasses($document->attrs($body) ?? []);
        $bodyHtmlClasses = self::htmlClasses($document, $body);
        if ($bodyHtmlClasses === null) {
            $issues[] = self::issue(
                'body_wrapper',
                $document->ownHtml($body),
                'a normal saved HTML wrapper with synchronized body hooks',
                'the repaired card body wrapper is not safely inspectable',
            );
            return $issues;
        }
        $expectedHooks = match ($style) {
            'overlap' => ['card-body', 'overlap-up'],
            default => ['card-body'],
        };
        foreach (['attribute' => $bodyAttributeClasses, 'html' => $bodyHtmlClasses] as $source => $classes) {
            $deliveredHooks = array_values(array_intersect($classes, ['card-body', 'overlap-up']));
            if ($deliveredHooks !== $expectedHooks) {
                $issues[] = self::issue(
                    "body_hooks_{$source}",
                    $deliveredHooks,
                    $expectedHooks,
                    "the {$source} body hooks do not match the assigned treatment",
                );
            }
            $bodyMarkers = self::treatmentMarkers($classes);
            if ($bodyMarkers !== []) {
                $issues[] = self::issue(
                    "body_markers_{$source}",
                    $bodyMarkers,
                    'removed; the treatment marker belongs on the outer card only',
                    "the {$source} body retains an outer-card treatment marker",
                );
            }
            if (in_array('card-flush', $classes, true)) {
                $issues[] = self::issue(
                    "body_card_flush_{$source}",
                    true,
                    false,
                    "the {$source} body retains the outer-only card-flush behavior hook",
                );
            }
        }
        return $issues;
    }

    /**
     * Reserved class drift is normally repaired without a warning. If another
     * anatomy defect blocks that transaction, record the exact comment and
     * saved-HTML hook sets that will remain active in the delivered bytes.
     *
     * @param array<string,mixed> $inspection
     * @return list<array{field:string,authored:mixed,required:mixed,message:string}>
     */
    private static function reservedHookDriftIssues(array $inspection, string $style): array
    {
        $issues = [];
        $outerExpected = [self::MARKER_PREFIX . $style];
        if (in_array($style, ['flush', 'overlap'], true)) {
            $outerExpected[] = 'card-flush';
        }
        foreach ([
            'attribute' => $inspection['attributeClasses'],
            'html' => $inspection['htmlClasses'],
        ] as $source => $classes) {
            if (!is_array($classes)) {
                continue;
            }
            $authored = self::reservedHooks($classes);
            if (!self::sameClassSet($authored, $outerExpected)) {
                $issues[] = self::issue(
                    "outer_{$source}_reserved_hooks",
                    $authored,
                    $outerExpected,
                    "the {$source} outer-card reserved hooks remain incorrect because anatomy blocked class repair",
                );
            }
        }

        if (!is_int($inspection['body'])) {
            return $issues;
        }
        $bodyExpected = ['card-body'];
        if ($style === 'overlap') {
            $bodyExpected[] = 'overlap-up';
        }
        foreach ([
            'attribute' => $inspection['bodyAttributeClasses'],
            'html' => $inspection['bodyHtmlClasses'],
        ] as $source => $classes) {
            if (!is_array($classes)) {
                continue;
            }
            $authored = self::reservedHooks($classes);
            if (!self::sameClassSet($authored, $bodyExpected)) {
                $issues[] = self::issue(
                    "body_{$source}_reserved_hooks",
                    $authored,
                    $bodyExpected,
                    "the {$source} text-body reserved hooks remain incorrect because anatomy blocked class repair",
                );
            }
        }
        return $issues;
    }

    /**
     * @param array<string,mixed> $inspection
     * @return list<array{field:string,authored:mixed,required:mixed,message:string}>
     */
    private static function cssResetEvidence(array $inspection, string $style): array
    {
        if (!in_array($style, ['flush', 'overlap'], true)) {
            return [];
        }
        $evidence = [];
        $paddingResidue = self::paddingResidueEvidence($inspection['padding']);
        if ($paddingResidue !== []) {
            $evidence[] = self::issue(
                'outer_padding',
                $paddingResidue,
                '0 via .card-flush padding:0!important',
                'outer card padding is neutralized by the verified behavior hook',
            );
        }
        if (self::hasRadiusResidue($inspection['imageRadius'])) {
            $evidence[] = self::issue(
                'image_radius',
                $inspection['imageRadius'],
                '0 via the direct .card-flush image !important reset',
                'the direct image radius is neutralized by the verified behavior hook',
            );
        }
        return $evidence;
    }

    /**
     * @param array<string,mixed> $evidence
     * @return list<array{field:string,authored:mixed,required:mixed,message:string}>
     */
    private static function paintAbsenceIssues(
        string $prefix,
        array $evidence,
        mixed $required,
        string $message,
    ): array {
        if ($evidence === []) {
            return [self::issue($prefix, [], $required, $message)];
        }
        $issues = [];
        foreach ($evidence as $field => $value) {
            $issues[] = self::issue(
                $prefix . '_' . str_replace('.', '_', $field),
                $value,
                $required,
                $message . " (authored {$field})",
            );
        }
        return $issues;
    }

    /** @return array{field:string,authored:mixed,required:mixed,message:string} */
    private static function issue(string $field, mixed $authored, mixed $required, string $message): array
    {
        return compact('field', 'authored', 'required', 'message');
    }

    /**
     * Anatomy can fail before ordinary class repair validates saved wrappers.
     * Keep malformed root bytes and every independently recoverable hook in
     * separate issue values so neither can hide the other behind warning-value
     * truncation.
     *
     * @param array<string,mixed> $inspection
     * @return list<array{field:string,authored:mixed,required:mixed,message:string}>
     */
    private static function malformedSavedWrapperIssues(
        BlockMarkup $document,
        array $inspection,
        string $style,
    ): array {
        $issues = [];
        $outerExpected = [self::MARKER_PREFIX . $style];
        if (in_array($style, ['flush', 'overlap'], true)) {
            $outerExpected[] = 'card-flush';
        }
        if (($inspection['htmlClasses'] ?? null) === null) {
            array_push($issues, ...self::malformedSavedWrapperIssueSet(
                $document,
                $inspection['index'],
                'outer',
                $outerExpected,
            ));
        }

        $body = $inspection['body'] ?? null;
        if (is_int($body) && ($inspection['bodyHtmlClasses'] ?? null) === null) {
            $bodyExpected = ['card-body'];
            if ($style === 'overlap') {
                $bodyExpected[] = 'overlap-up';
            }
            array_push($issues, ...self::malformedSavedWrapperIssueSet(
                $document,
                $body,
                'body',
                $bodyExpected,
            ));
        }
        return $issues;
    }

    /**
     * @param list<string> $expectedHooks
     * @return list<array{field:string,authored:mixed,required:mixed,message:string}>
     */
    private static function malformedSavedWrapperIssueSet(
        BlockMarkup $document,
        int $index,
        string $role,
        array $expectedHooks,
    ): array {
        $attrs = $document->attrs($index) ?? [];
        return [
            self::issue(
                "{$role}_comment_className",
                $attrs['className'] ?? null,
                'a space-separated string carrying exactly the required reserved hooks',
                "the {$role} comment className was retained while its saved wrapper is malformed",
            ),
            self::issue(
                "{$role}_attribute_reserved_hooks",
                self::reservedHooks(self::attributeClasses($attrs)),
                $expectedHooks,
                "the {$role} comment hooks cannot be reconciled with the malformed saved wrapper",
            ),
            self::issue(
                "{$role}_saved_wrapper",
                self::savedWrapperBytes($document, $index),
                'one normal non-void opening tag with one safely parseable class attribute',
                "the anatomy-blocked {$role} saved wrapper is malformed and remains unchanged",
            ),
            self::issue(
                "{$role}_saved_wrapper_recoverable_reserved_hooks",
                self::recoverableSavedWrapperHooks($document, $index),
                $expectedHooks,
                "the reserved hooks recoverable from the malformed {$role} wrapper remain active",
            ),
        ];
    }

    /** @param list<string> $classes @return list<string> */
    private static function cardClasses(array $classes, string $style): array
    {
        $marker = self::MARKER_PREFIX . $style;
        $out = [];
        $markerInserted = false;
        foreach ($classes as $class) {
            if (str_starts_with($class, self::MARKER_PREFIX)) {
                if (!$markerInserted) {
                    $out[] = $marker;
                    $markerInserted = true;
                }
                continue;
            }
            if ($class === 'card-flush') {
                continue;
            }
            if ($class === 'card-body' || $class === 'overlap-up') {
                continue;
            }
            $out[] = $class;
        }
        if (!$markerInserted) {
            $out[] = $marker;
        }
        if (in_array($style, ['flush', 'overlap'], true)) {
            $out[] = 'card-flush';
        }
        return array_values(array_unique($out));
    }

    /** @param list<string> $classes @return list<string> */
    private static function bodyClasses(array $classes, string $style): array
    {
        $out = array_values(array_filter(
            $classes,
            static fn (string $class): bool => $class !== 'card-body'
                && $class !== 'overlap-up'
                && $class !== 'card-flush'
                && !str_starts_with($class, self::MARKER_PREFIX),
        ));
        $out[] = 'card-body';
        if ($style === 'overlap') {
            $out[] = 'overlap-up';
        }
        return array_values(array_unique($out));
    }

    /** @param list<string> $classes @return list<string> */
    private static function reservedHooks(array $classes): array
    {
        return array_values(array_filter(
            $classes,
            static fn (string $class): bool => str_starts_with($class, self::MARKER_PREFIX)
                || in_array($class, ['card-flush', 'card-body', 'overlap-up'], true),
        ));
    }

    /** @param list<string> $classes @param list<string> $hooks @return list<string> */
    private static function withoutHooks(array $classes, array $hooks): array
    {
        return array_values(array_filter(
            $classes,
            static fn (string $class): bool => !in_array($class, $hooks, true),
        ));
    }

    /** @param list<string> $left @param list<string> $right */
    private static function sameClassSet(array $left, array $right): bool
    {
        return count($left) === count($right)
            && array_diff($left, $right) === []
            && array_diff($right, $left) === [];
    }

    /** @return list<string> */
    private static function nodeClasses(BlockMarkup $document, int $index): array
    {
        return array_values(array_unique(array_merge(
            self::attributeClasses($document->attrs($index) ?? []),
            self::htmlClasses($document, $index) ?? [],
        )));
    }

    /** @param array<mixed> $attrs @return list<string> */
    private static function attributeClasses(array $attrs): array
    {
        return is_string($attrs['className'] ?? null)
            ? self::classTokens($attrs['className'])
            : [];
    }

    /** @return list<string>|null */
    private static function htmlClasses(BlockMarkup $document, int $index): ?array
    {
        $tag = self::openingTag($document, $index);
        return $tag === null ? null : self::classesInTag($tag['tag']);
    }

    /** @return array{tag:string,offset:int}|null */
    private static function openingTag(BlockMarkup $document, int $index): ?array
    {
        $root = self::rootOpeningTag($document, $index);
        if ($root === null) {
            return null;
        }
        $tag = $root['tag'];
        if (preg_match('~/\s*>\z~', $tag) === 1
            || preg_match('~\A<(?<name>[a-z][a-z0-9:-]*)~i', $tag, $name) !== 1
            || in_array(strtolower($name['name']), [
                'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
                'link', 'meta', 'param', 'source', 'track', 'wbr',
            ], true)
        ) {
            return null;
        }
        return $root;
    }

    /** @return array{tag:string,offset:int}|null */
    private static function rootOpeningTag(BlockMarkup $document, int $index): ?array
    {
        if (preg_match(
            '~\A(?:(?:\s+)|(?:<!--(?:(?!-->).)*-->))*(?<tag><[a-z][a-z0-9:-]*(?=[\s>])'
                . '(?:[^>"\']+|"[^"]*"|\'[^\']*\')*>)~is',
            $document->ownHtml($index),
            $match,
            PREG_OFFSET_CAPTURE,
        ) !== 1) {
            return null;
        }
        $tag = $match['tag'][0];
        return [
            'tag' => $tag,
            'offset' => $document->openingOffset($index)
                + $document->openingLength($index)
                + $match['tag'][1],
        ];
    }

    private static function savedWrapperBytes(BlockMarkup $document, int $index): ?string
    {
        $root = self::rootOpeningTag($document, $index);
        if ($root !== null) {
            return $root['tag'];
        }
        $owned = $document->ownHtml($index);
        if ($owned === '') {
            return null;
        }
        $tags = self::recoverableOpeningTags($owned);
        if ($tags === null || $tags === []) {
            return $owned;
        }
        $hookTags = array_values(array_filter(
            $tags,
            static fn (string $tag): bool => self::reservedHooksInOpeningTag($tag) !== [],
        ));
        return implode(' ', $hookTags === [] ? [$tags[0]] : $hookTags);
    }

    /**
     * Evidence-only fallback for malformed own HTML. Unlike rootOpeningTag(),
     * this may look past visible prefix bytes, but its result is never used to
     * authorize a rewrite.
     */
    private static function recoverableOpeningTags(string $html): ?array
    {
        if ($html === '' || strlen($html) > 65536) {
            return null;
        }
        $tags = [];
        try {
            HtmlBlockContext::rewriteOpeningTags(
                $html,
                static function (string $tag) use (&$tags): string {
                    $tags[] = $tag;
                    return $tag;
                },
            );
        } catch (\Throwable) {
            return null;
        }
        return $tags;
    }

    /** @return list<string> */
    private static function reservedHooksInOpeningTag(string $tag): array
    {
        $hooks = [];
        foreach (MarkupSanitizer::openingTagAttributes($tag) as $attribute) {
            if ($attribute['name'] !== 'class'
                || $attribute['valueStart'] === null
                || $attribute['valueEnd'] === null
            ) {
                continue;
            }
            $value = substr(
                $tag,
                $attribute['valueStart'],
                $attribute['valueEnd'] - $attribute['valueStart'],
            );
            array_push($hooks, ...self::reservedHooks(self::classTokens($value)));
        }
        return array_values(array_unique($hooks));
    }

    /**
     * Recover hook tokens from individually parseable class attributes even
     * when duplicates make the wrapper unsafe to rewrite.
     *
     * @return ?list<string>
     */
    private static function recoverableSavedWrapperHooks(
        BlockMarkup $document,
        int $index,
    ): ?array {
        $root = self::rootOpeningTag($document, $index);
        $tags = $root === null
            ? self::recoverableOpeningTags($document->ownHtml($index))
            : [$root['tag']];
        if ($tags === null || $tags === []) {
            return null;
        }
        $hooks = [];
        $sawClass = false;
        $sawUnparseableClass = false;
        foreach ($tags as $tag) {
            foreach (MarkupSanitizer::openingTagAttributes($tag) as $attribute) {
                if ($attribute['name'] !== 'class') {
                    continue;
                }
                $sawClass = true;
                if ($attribute['valueStart'] === null || $attribute['valueEnd'] === null) {
                    $sawUnparseableClass = true;
                    continue;
                }
                $value = substr(
                    $tag,
                    $attribute['valueStart'],
                    $attribute['valueEnd'] - $attribute['valueStart'],
                );
                array_push($hooks, ...self::reservedHooks(self::classTokens($value)));
            }
        }
        $hooks = array_values(array_unique($hooks));
        if ($hooks !== [] || !$sawUnparseableClass) {
            return $hooks;
        }
        return $sawClass ? null : [];
    }

    /** @return list<string>|null null means duplicate/malformed class attributes */
    private static function classesInTag(string $tag): ?array
    {
        $classes = [];
        foreach (MarkupSanitizer::openingTagAttributes($tag) as $attribute) {
            if ($attribute['name'] !== 'class') {
                continue;
            }
            if (
                $attribute['valueStart'] === null
                || $attribute['valueEnd'] === null
                || $classes !== []
            ) {
                return null;
            }
            $classes[] = substr(
                $tag,
                $attribute['valueStart'],
                $attribute['valueEnd'] - $attribute['valueStart'],
            );
        }
        return $classes === [] ? [] : self::classTokens($classes[0]);
    }

    /** @param list<string> $classes */
    private static function tagWithClasses(string $tag, array $classes): ?string
    {
        $classAttributes = array_values(array_filter(
            MarkupSanitizer::openingTagAttributes($tag),
            static fn (array $attribute): bool => $attribute['name'] === 'class',
        ));
        if (count($classAttributes) > 1) {
            return null;
        }
        $encoded = htmlspecialchars(
            implode(' ', $classes),
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $replacement = ' class="' . $encoded . '"';
        if ($classAttributes === []) {
            $at = strrpos($tag, '>');
            return $at === false ? null : substr_replace($tag, $replacement, $at, 0);
        }
        $attribute = $classAttributes[0];
        return substr_replace(
            $tag,
            $replacement,
            $attribute['start'],
            $attribute['end'] - $attribute['start'],
        );
    }

    /** @return list<string> */
    private static function classTokens(string $classes): array
    {
        $classes = html_entity_decode($classes, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return array_values(array_unique(
            preg_split('/[\x09\x0A\x0C\x0D\x20]+/', trim($classes), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ));
    }

    /** @param list<string> $classes @return list<string> */
    private static function treatmentMarkers(array $classes): array
    {
        return array_values(array_filter(
            $classes,
            static fn (string $class): bool => str_starts_with($class, self::MARKER_PREFIX),
        ));
    }

    private static function firstDirectImage(BlockMarkup $document, int $group): ?int
    {
        foreach ($document->children($group) as $child) {
            if ($document->name($child) === 'image') {
                return $child;
            }
        }
        return null;
    }

    private static function isInEqualCardsColumn(BlockMarkup $document, int $group): bool
    {
        $column = $document->parent($group);
        if ($column === null || $document->name($column) !== 'column') {
            return false;
        }
        $columns = $document->parent($column);
        return $columns !== null
            && $document->name($columns) === 'columns'
            && in_array('equal-cards', self::nodeClasses($document, $columns), true);
    }

    private static function isInOrdinaryCardColumn(
        BlockMarkup $document,
        int $group,
        int $image,
    ): bool {
        $column = $document->parent($group);
        if ($column === null || $document->name($column) !== 'column') {
            return false;
        }
        $columns = $document->parent($column);
        if ($columns === null
            || $document->name($columns) !== 'columns'
            || in_array('equal-cards', self::nodeClasses($document, $columns), true)
            || self::hasAncestorClass($document, $group, 'masonry-3')
        ) {
            return false;
        }

        $columnChildren = $document->children($column);
        $columnsChildren = array_values(array_filter(
            $document->children($columns),
            static fn (int $child): bool => $document->name($child) === 'column',
        ));
        $imageClasses = self::nodeClasses($document, $image);
        $hasCardCrop = in_array('card-media', $imageClasses, true)
            || in_array('card-media-tall', $imageClasses, true);

        if (count($columnsChildren) < 2
            || $columnChildren !== [$group]
            || count($document->children($group)) < 2
            || !$hasCardCrop
        ) {
            return false;
        }

        // Staggered/editorial recipes are repeated card compositions: every
        // sibling column owns one image-plus-content group. Requiring the whole
        // row shape avoids promoting a generic image-and-copy half of an
        // ordinary split layout merely because it reused a crop class.
        foreach ($columnsChildren as $siblingColumn) {
            $children = $document->children($siblingColumn);
            if (count($children) !== 1 || $document->name($children[0]) !== 'group') {
                return false;
            }
            $siblingGroup = $children[0];
            $siblingImage = self::firstDirectImage($document, $siblingGroup);
            if ($siblingImage === null
                || ($document->children($siblingGroup)[0] ?? null) !== $siblingImage
                || count($document->children($siblingGroup)) < 2
                || self::hasAncestorClass($document, $siblingGroup, 'masonry-3')
            ) {
                return false;
            }
            $cropClasses = self::nodeClasses($document, $siblingImage);
            if (!in_array('card-media', $cropClasses, true)
                && !in_array('card-media-tall', $cropClasses, true)
            ) {
                return false;
            }
        }
        return true;
    }

    private static function hasAncestorClass(
        BlockMarkup $document,
        int $index,
        string $class,
    ): bool {
        $cursor = $index;
        while ($cursor !== null) {
            if (in_array($class, self::nodeClasses($document, $cursor), true)) {
                return true;
            }
            $cursor = $document->parent($cursor);
        }
        return false;
    }

    /** @return list<int> */
    private static function nestedImageGroups(BlockMarkup $document, int $root): array
    {
        $found = [];
        $pending = array_reverse($document->children($root));
        while ($pending !== []) {
            $candidate = array_pop($pending);
            if ($document->name($candidate) === 'group'
                && self::firstDirectImage($document, $candidate) !== null
            ) {
                $found[] = $candidate;
            }
            foreach (array_reverse($document->children($candidate)) as $child) {
                $pending[] = $child;
            }
        }
        return $found;
    }

    /** @param array<int,true> $roots */
    private static function isWithinSubtree(
        BlockMarkup $document,
        int $index,
        array $roots,
    ): bool {
        $cursor = $index;
        while ($cursor !== null) {
            if (isset($roots[$cursor])) {
                return true;
            }
            $cursor = $document->parent($cursor);
        }
        return false;
    }

    /**
     * @param array<int,true> $roots
     * @return list<array{index:int,path:string,attribute_reserved_hooks:list<string>,html_reserved_hooks:?list<string>,
     *   recoverable_html_reserved_hooks:?list<string>,saved_wrapper:?string}>
     */
    private static function nestedReservedHookGroups(
        BlockMarkup $document,
        array $roots,
    ): array {
        if ($roots === []) {
            return [];
        }
        $groups = [];
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'group'
                || !self::isWithinSubtree($document, $index, $roots)
                || isset($roots[$index])
            ) {
                continue;
            }
            $attributeHooks = self::reservedHooks(self::attributeClasses($document->attrs($index) ?? []));
            $htmlClasses = self::htmlClasses($document, $index);
            $htmlHooks = is_array($htmlClasses) ? self::reservedHooks($htmlClasses) : null;
            if ($attributeHooks === [] && $htmlHooks === []) {
                continue;
            }
            $groups[] = [
                'index' => $index,
                'path' => self::blockPath($document, $index),
                'attribute_reserved_hooks' => $attributeHooks,
                'html_reserved_hooks' => $htmlHooks,
                'recoverable_html_reserved_hooks' => self::recoverableSavedWrapperHooks(
                    $document,
                    $index,
                ),
                'saved_wrapper' => self::savedWrapperBytes($document, $index),
            ];
        }
        return $groups;
    }

    private static function hasPaintedSurface(array $attrs, array $paintPresets = []): bool
    {
        return self::paintedSurfaceValues($attrs, false, $paintPresets) !== [];
    }

    /** @param array<mixed> $attrs @return array<string,mixed> */
    private static function paintedSurfaceValues(
        array $attrs,
        bool $includeUnknown = false,
        array $paintPresets = [],
    ): array
    {
        // StyleEngine serializes custom gradient as `background` and custom
        // color after it as `background-color`. Keep exact contributor fields,
        // including declaration priority, so inert attempts do not become
        // removal warnings merely because a sibling layer paints.
        $background = self::nested($attrs, ['style', 'color', 'background']);
        $gradient = self::nested($attrs, ['style', 'color', 'gradient']);
        $backgroundCss = self::styleEngineString($background);
        $gradientCss = self::styleEngineString($gradient);
        // Gutenberg suppresses the preset background-color class whenever a
        // truthy custom shorthand is present, even if that shorthand later
        // proves invalid CSS. The preset gradient class is not suppressed.
        $paintContext = $attrs;
        $paintContext['__card_style_paint_presets'] = $paintPresets;
        $imageEntries = [[
            'state' => 'transparent', 'important' => false, 'order' => 0,
            'path' => null, 'value' => null,
        ]];
        $colorEntries = $imageEntries;
        if (is_string($attrs['backgroundColor'] ?? null)
            && trim($attrs['backgroundColor']) !== ''
            && !self::styleEngineTruthy($gradient)
        ) {
            $presetState = self::isRequiredColorSlug($attrs['backgroundColor'])
                ? 'painted'
                : self::presetPaintState(
                    $attrs['backgroundColor'],
                    'color',
                    $paintPresets,
                    $paintContext,
                    true,
                );
            if ($presetState !== 'absent') {
                $colorEntries[] = [
                    'state' => $presetState ?? 'unknown',
                    'important' => true, 'order' => 1,
                    'path' => in_array($presetState ?? 'unknown', ['painted', 'unknown'], true)
                        ? 'backgroundColor'
                        : null,
                    'value' => $attrs['backgroundColor'],
                ];
            }
        }
        if (is_string($attrs['gradient'] ?? null) && trim($attrs['gradient']) !== '') {
            $presetStates = self::presetGradientPaintStates(
                $attrs['gradient'],
                $paintPresets,
                $paintContext,
                true,
            );
            if ($presetStates !== null && $presetStates['image'] !== 'absent') {
                $imageEntries[] = [
                    'state' => $presetStates['image'], 'important' => true, 'order' => 2,
                    'path' => in_array($presetStates['image'], ['painted', 'unknown'], true)
                        ? 'gradient'
                        : null,
                    'value' => $attrs['gradient'],
                ];
                // The preset utility uses the complete background shorthand.
                $colorEntries[] = [
                    'state' => $presetStates['color'], 'important' => true, 'order' => 2,
                    'path' => in_array($presetStates['color'], ['painted', 'unknown'], true)
                        ? 'gradient'
                        : null,
                    'value' => $attrs['gradient'],
                ];
            }
        }
        $underlyingImage = self::borderCascadeWinner($imageEntries);
        $underlyingColor = self::borderCascadeWinner($colorEntries);
        if (self::serializedValueExists($gradient) && $gradientCss !== null) {
            $states = self::backgroundShorthandPaintStates(
                $gradientCss,
                $paintContext,
                $paintPresets,
            );
            if ($states['image'] !== 'invalid' && $states['color'] !== 'invalid') {
                $important = self::serializedDeclarationImportant($gradientCss);
                foreach (['image', 'color'] as $channel) {
                    $underlying = $channel === 'image' ? $underlyingImage : $underlyingColor;
                    $state = $states[$channel] === 'underlying'
                        ? $underlying['state']
                        : $states[$channel];
                    $entry = [
                        'state' => $state,
                        'important' => $important,
                        'order' => 3,
                        'path' => $states[$channel] === 'underlying'
                            ? $underlying['path']
                            : (in_array($state, ['painted', 'unknown'], true)
                                ? 'style.color.gradient'
                                : null),
                        'value' => $states[$channel] === 'underlying'
                            ? $underlying['value']
                            : $gradient,
                    ];
                    if ($channel === 'image') {
                        $imageEntries[] = $entry;
                    } else {
                        $colorEntries[] = $entry;
                    }
                }
            }
        } elseif (is_array($gradient) && $gradient !== []) {
            $entry = [
                'state' => 'unknown', 'important' => false, 'order' => 3,
                'path' => 'style.color.gradient', 'value' => $gradient,
            ];
            $imageEntries[] = $entry;
            $colorEntries[] = $entry;
        }
        if (self::serializedValueExists($background) && $backgroundCss !== null) {
            $state = self::colorPaintState($backgroundCss, $paintContext);
            if ($state !== 'invalid') {
                $winnerState = $state === 'underlying'
                    ? $underlyingColor['state']
                    : $state;
                $colorEntries[] = [
                    'state' => $winnerState,
                    'important' => self::serializedDeclarationImportant($backgroundCss),
                    'order' => 4,
                    'path' => $state === 'underlying'
                        ? $underlyingColor['path']
                        : (in_array($winnerState, ['painted', 'unknown'], true)
                            ? 'style.color.background'
                            : null),
                    'value' => $state === 'underlying'
                        ? $underlyingColor['value']
                        : $background,
                ];
            }
        } elseif (is_array($background) && $background !== []) {
            $colorEntries[] = [
                'state' => 'unknown', 'important' => false, 'order' => 4,
                'path' => 'style.color.background', 'value' => $background,
            ];
        }
        $image = self::borderCascadeWinner($imageEntries);
        $color = self::borderCascadeWinner($colorEntries);
        $fields = [];
        foreach ([$image, $color] as $winner) {
            if (($winner['state'] === 'painted'
                    || ($includeUnknown && $winner['state'] === 'unknown'))
                && $winner['path'] !== null
            ) {
                $fields[] = $winner['path'];
            }
        }
        $values = self::surfaceValues($attrs);
        return array_filter(
            array_intersect_key(
                $values,
                array_fill_keys($fields, true),
            ),
            static fn (mixed $value): bool => !self::isAbsent($value),
        );
    }

    private static function hasPaintedBox(array $attrs, array $paintPresets = []): bool
    {
        return self::hasPaintedSurface($attrs, $paintPresets)
            || self::hasVisibleShadow(
                self::nested($attrs, ['style', 'shadow']),
                $attrs,
                $paintPresets,
            )
            || self::hasVisibleBorder($attrs, $paintPresets);
    }

    private static function hasBoxResidue(array $attrs, array $paintPresets = []): bool
    {
        return self::boxResidueEvidence($attrs, true, $paintPresets) !== [];
    }

    /** @param array<mixed> $attrs @return array<string,mixed> */
    private static function surfaceEvidence(array $attrs): array
    {
        return array_filter(
            self::surfaceValues($attrs),
            static fn (mixed $value): bool => !self::isAbsent($value),
        );
    }

    /** @param array<mixed> $attrs @return array<string,mixed> */
    private static function paintedSurfaceEvidence(array $attrs, array $paintPresets = []): array
    {
        return self::paintedSurfaceValues($attrs, false, $paintPresets);
    }

    /** @param array<mixed> $attrs @return array<string,mixed> */
    private static function paintEvidence(array $attrs): array
    {
        $evidence = self::surfaceEvidence($attrs);
        if (!self::isAbsent($attrs['borderColor'] ?? null)) {
            $evidence['borderColor'] = $attrs['borderColor'];
        }
        self::flattenEvidence(
            $evidence,
            'style.border',
            self::nested($attrs, ['style', 'border']),
        );
        $shadow = self::nested($attrs, ['style', 'shadow']);
        if (!self::isAbsent($shadow)) {
            $evidence['style.shadow'] = $shadow;
        }
        return $evidence;
    }

    /** @param array<mixed> $attrs @return array<string,mixed> */
    private static function boxResidueEvidence(
        array $attrs,
        bool $includeUnknown = false,
        array $paintPresets = [],
    ): array
    {
        $evidence = self::paintedSurfaceValues($attrs, $includeUnknown, $paintPresets);
        $evidence += self::visibleBorderEvidence($attrs, $includeUnknown, $paintPresets);
        $radius = self::nested($attrs, ['style', 'border', 'radius']);
        if ($includeUnknown) {
            foreach (self::possibleRadiusResidueEvidence($radius) as $field => $value) {
                $path = $field === 'radius'
                    ? 'style.border.radius'
                    : 'style.border.radius.' . $field;
                $evidence[$path] = $value;
            }
        } else {
            self::flattenBorderResidue(
                $evidence,
                'style.border.radius',
                $radius,
                false,
                1,
            );
        }
        $shadow = self::nested($attrs, ['style', 'shadow']);
        $shadowState = self::shadowPaintState($shadow, $attrs, $paintPresets);
        if ($shadowState === 'painted' || ($includeUnknown && $shadowState === 'unknown')) {
            $evidence['style.shadow'] = $shadow;
        }
        return $evidence;
    }

    /** @param array<mixed> $attrs @return array<string,mixed> */
    private static function surfaceValues(array $attrs): array
    {
        return [
            'backgroundColor' => $attrs['backgroundColor'] ?? null,
            'gradient' => $attrs['gradient'] ?? null,
            'style.color.background' => self::nested($attrs, ['style', 'color', 'background']),
            'style.color.gradient' => self::nested($attrs, ['style', 'color', 'gradient']),
        ];
    }

    /** @param array<string,mixed> $evidence */
    private static function flattenEvidence(array &$evidence, string $path, mixed $value): void
    {
        if (!is_array($value)) {
            if (!self::isAbsent($value)) {
                $evidence[$path] = $value;
            }
            return;
        }
        foreach ($value as $field => $child) {
            self::flattenEvidence($evidence, $path . '.' . $field, $child);
        }
    }

    /** @param array<string,mixed> $evidence */
    private static function flattenBorderResidue(
        array &$evidence,
        string $path,
        mixed $value,
        bool $visibleBorder,
        int $radiusDepth = 0,
    ): void
    {
        if (!is_array($value)) {
            // StyleEngine's box support accepts a scalar radius only when it
            // is a string. Per-corner values pass through ordinary rules and
            // may also be numeric (HtmlSerializer then supplies px). Boolean
            // and other scalar shapes serialize invalid CSS or are dropped.
            $keep = $radiusDepth > 0
                ? self::hasSerializedRadiusResidue($value, $radiusDepth > 1)
                : $visibleBorder && !self::isAbsent($value);
            if ($keep) {
                $evidence[$path] = $value;
            }
            return;
        }
        if ($radiusDepth === 1) {
            foreach (['topLeft', 'topRight', 'bottomLeft', 'bottomRight'] as $corner) {
                if (array_key_exists($corner, $value) && !is_array($value[$corner])) {
                    self::flattenBorderResidue(
                        $evidence,
                        $path . '.' . $corner,
                        $value[$corner],
                        $visibleBorder,
                        2,
                    );
                }
            }
            return;
        }
        foreach ($value as $field => $child) {
            self::flattenBorderResidue(
                $evidence,
                $path . '.' . $field,
                $child,
                $visibleBorder,
                $field === 'radius' ? 1 : $radiusDepth,
            );
        }
    }

    /** @param array<mixed> $attrs */
    private static function hasVisibleBorder(array $attrs, array $paintPresets = []): bool
    {
        return self::visibleBorderEvidence($attrs, false, $paintPresets) !== [];
    }

    /** @param array<mixed> $attrs @return array<string,mixed> */
    private static function visibleBorderEvidence(
        array $attrs,
        bool $includeUnknown = false,
        array $paintPresets = [],
    ): array
    {
        $border = self::nested($attrs, ['style', 'border']);
        $base = is_array($border) ? $border : [];
        $paintContext = $attrs;
        $paintContext['__card_style_paint_presets'] = $paintPresets;
        $baseColorShorthand = self::borderShorthandState(
            'color',
            $base['color'] ?? null,
            $paintContext,
        );
        $baseStyleShorthand = self::borderShorthandState(
            'style',
            $base['style'] ?? null,
            $paintContext,
        );
        $baseWidthShorthand = self::borderShorthandState(
            'width',
            $base['width'] ?? null,
            $paintContext,
        );
        $presetColor = $attrs['borderColor'] ?? null;
        $hasPresetColorClass = self::styleEngineTruthy($presetColor);
        $hasPresetColorSlug = is_string($presetColor)
            && preg_match('/\A[a-z0-9][a-z0-9._-]*\z/i', trim($presetColor)) === 1;
        $presetColorState = self::isRequiredColorSlug($presetColor)
            ? 'painted'
            : ($hasPresetColorSlug
                ? self::presetPaintState(
                    $presetColor,
                    'color',
                    $paintPresets,
                    $paintContext,
                    true,
                )
                : null);
        $evidence = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $sideBorder = is_array($base[$side] ?? null) ? $base[$side] : [];
            $baseColor = $baseColorShorthand['values'][$side] ?? null;
            $baseStyle = $baseStyleShorthand['values'][$side] ?? null;
            $baseWidth = $baseWidthShorthand['values'][$side] ?? null;
            $triggers = [];
            if ($hasPresetColorClass) {
                $triggers['borderColor'] = $presetColor;
            }
            foreach ([
                'style.border.color' => $base['color'] ?? null,
                'style.border.width' => $base['width'] ?? null,
                "style.border.{$side}.color" => $sideBorder['color'] ?? null,
                "style.border.{$side}.width" => $sideBorder['width'] ?? null,
            ] as $path => $value) {
                if (self::styleEngineTruthy($value)) {
                    $triggers[$path] = $value;
                }
            }

            $colors = [[
                'state' => self::currentColorPaintState($paintContext, $paintPresets),
                'important' => false,
                'order' => 0,
                'path' => null,
                'value' => null,
            ]];
            if (in_array($presetColorState, ['painted', 'transparent', 'unknown'], true)) {
                $colors[] = [
                    'state' => $presetColorState, 'important' => true, 'order' => 1,
                    'path' => 'borderColor', 'value' => $presetColor,
                ];
            }
            $underlyingColor = self::borderCascadeWinner($colors);
            foreach ([
                [
                    'style.border.color', $baseColor, 2, $base['color'] ?? null,
                    $baseColorShorthand['state']
                        ?? (is_array($base['color'] ?? null)
                            && $base['color'] !== []
                            && self::styleEngineString($base['color']) === null
                            ? 'unknown'
                            : null),
                ],
                [
                    "style.border.{$side}.color",
                    $sideBorder['color'] ?? null,
                    3,
                    null,
                    is_array($sideBorder['color'] ?? null)
                        && $sideBorder['color'] !== []
                        && self::styleEngineString($sideBorder['color']) === null
                        ? 'unknown'
                        : null,
                ],
            ] as [$path, $value, $order, $authored, $state]) {
                $entry = self::borderEntry(
                    'color', $path, $value, $order, $paintContext, false, $authored, $state,
                );
                if ($entry !== null) {
                    $colors[] = self::resolvedBorderEntry($entry, $underlyingColor);
                }
            }

            $styles = [[
                'state' => $triggers === [] ? 'none' : 'visible',
                'important' => false,
                'order' => 0,
                'path' => null,
                'value' => null,
            ]];
            $underlyingStyle = self::borderCascadeWinner($styles);
            foreach ([
                [
                    'style.border.style', $baseStyle, 2, $base['style'] ?? null,
                    $baseStyleShorthand['state']
                        ?? (is_array($base['style'] ?? null)
                            && $base['style'] !== []
                            && self::styleEngineString($base['style']) === null
                            ? 'unknown'
                            : null),
                ],
                [
                    "style.border.{$side}.style",
                    $sideBorder['style'] ?? null,
                    3,
                    null,
                    is_array($sideBorder['style'] ?? null)
                        && $sideBorder['style'] !== []
                        && self::styleEngineString($sideBorder['style']) === null
                        ? 'unknown'
                        : null,
                ],
            ] as [$path, $value, $order, $authored, $state]) {
                $entry = self::borderEntry(
                    'style',
                    $path,
                    $value,
                    $order,
                    $paintContext,
                    $triggers !== [],
                    $authored,
                    $state,
                );
                if ($entry !== null) {
                    $styles[] = self::resolvedBorderEntry($entry, $underlyingStyle);
                }
            }

            $widths = [[
                'state' => 'positive', 'important' => false, 'order' => 0,
                'path' => null, 'value' => null,
            ]];
            $underlyingWidth = self::borderCascadeWinner($widths);
            foreach ([
                [
                    'style.border.width', $baseWidth, 2, $base['width'] ?? null,
                    $baseWidthShorthand['state']
                        ?? (is_array($base['width'] ?? null)
                            && $base['width'] !== []
                            && self::styleEngineString($base['width']) === null
                            ? 'unknown'
                            : null),
                ],
                [
                    "style.border.{$side}.width",
                    $sideBorder['width'] ?? null,
                    3,
                    null,
                    is_array($sideBorder['width'] ?? null)
                        && $sideBorder['width'] !== []
                        && self::styleEngineString($sideBorder['width']) === null
                        ? 'unknown'
                        : null,
                ],
            ] as [$path, $value, $order, $authored, $state]) {
                $entry = self::borderEntry(
                    'width', $path, $value, $order, $paintContext, false, $authored, $state,
                );
                if ($entry !== null) {
                    $widths[] = self::resolvedBorderEntry($entry, $underlyingWidth);
                }
            }

            $color = self::borderCascadeWinner($colors);
            $style = self::borderCascadeWinner($styles);
            $width = self::borderCascadeWinner($widths);
            if (!in_array($color['state'], $includeUnknown ? ['painted', 'unknown'] : ['painted'], true)
                || !in_array($style['state'], $includeUnknown ? ['visible', 'unknown'] : ['visible'], true)
                || !in_array($width['state'], $includeUnknown ? ['positive', 'unknown'] : ['positive'], true)
            ) {
                continue;
            }
            foreach ([$color, $style, $width] as $winner) {
                if ($winner['path'] !== null) {
                    $evidence[$winner['path']] = $winner['value'];
                }
            }
            if ($style['path'] === null) {
                foreach ($triggers as $path => $value) {
                    if (self::borderTriggerMayPaint(
                        $path,
                        $value,
                        $paintContext,
                        $presetColorState,
                    )) {
                        $evidence[$path] = $value;
                    }
                }
            }
        }
        return $evidence;
    }

    private static function borderTriggerMayPaint(
        string $path,
        mixed $value,
        array $context,
        ?string $presetColorState,
    ): bool {
        $currentColor = self::currentColorPaintState($context);
        if ($path === 'borderColor') {
            return match ($presetColorState) {
                'transparent' => false,
                'painted', 'unknown' => true,
                default => $currentColor !== 'transparent',
            };
        }
        if (str_ends_with($path, '.color')) {
            if (is_array($value)) {
                return $value !== [] || $currentColor !== 'transparent';
            }
            $shorthand = self::borderShorthandState('color', $value, $context);
            $values = $shorthand['values'] ?? [$value];
            foreach ($values as $component) {
                $state = self::borderColorState($component, $context);
                if (in_array($state, ['painted', 'unknown'], true)
                    || ($state === 'invalid' && $currentColor !== 'transparent')
                ) {
                    return true;
                }
            }
            return false;
        }
        if (str_ends_with($path, '.width')) {
            if (is_array($value)) {
                return true;
            }
            $shorthand = self::borderShorthandState('width', $value, $context);
            $values = $shorthand['values'] ?? [$value];
            foreach ($values as $component) {
                $state = self::borderWidthState($component);
                if (!in_array($state, ['zero', 'underlying'], true)) {
                    return true;
                }
            }
            return false;
        }
        return false;
    }

    /**
     * @param array<mixed> $context
     * @return array{state:string,important:bool,order:int,path:string,value:mixed}|null
     */
    private static function borderEntry(
        string $property,
        string $path,
        mixed $value,
        int $order,
        array $context,
        bool $commonBorderStyle = false,
        mixed $authoredValue = null,
        ?string $stateOverride = null,
    ): ?array {
        if (!self::serializedValueExists($value)
            && !($stateOverride !== null
                && self::styleEngineTruthy($authoredValue ?? $value))
        ) {
            return null;
        }
        $cssValue = self::styleEngineString($value) ?? $value;
        $state = $stateOverride ?? match ($property) {
            'color' => self::borderColorState($cssValue, $context),
            'style' => self::borderStyleState($cssValue, $commonBorderStyle),
            default => self::borderWidthState($cssValue),
        };
        if ($state === 'invalid') {
            return null;
        }
        $priorityValue = self::styleEngineString($authoredValue ?? $value);
        return [
            'state' => $state,
            'important' => is_string($priorityValue)
                && self::serializedDeclarationImportant($priorityValue),
            'order' => $order,
            'path' => $path,
            'value' => $authoredValue ?? $value,
        ];
    }

    /**
     * `revert-layer` keeps the reverting declaration's cascade priority but
     * exposes the earlier layer's actual state and contributor evidence.
     *
     * @param array{state:string,important:bool,order:int,path:?string,value:mixed} $entry
     * @param array{state:string,important:bool,order:int,path:?string,value:mixed} $underlying
     * @return array{state:string,important:bool,order:int,path:?string,value:mixed}
     */
    private static function resolvedBorderEntry(array $entry, array $underlying): array
    {
        if ($entry['state'] !== 'underlying') {
            return $entry;
        }
        $entry['state'] = $underlying['state'];
        $entry['path'] = $underlying['path'];
        $entry['value'] = $underlying['value'];
        return $entry;
    }

    /** @param list<array{state:string,important:bool,order:int,path:?string,value:mixed}> $entries */
    private static function borderCascadeWinner(array $entries): array
    {
        $winner = $entries[0];
        foreach (array_slice($entries, 1) as $entry) {
            if (($entry['important'] && !$winner['important'])
                || ($entry['important'] === $winner['important']
                    && $entry['order'] >= $winner['order'])
            ) {
                $winner = $entry;
            }
        }
        return $winner;
    }

    /** @param array<mixed> $context @return 'painted'|'transparent'|'unknown'|'invalid'|'underlying' */
    private static function borderColorState(mixed $value, array $context): string
    {
        if (!is_string($value)) {
            return 'invalid';
        }
        if (self::isRequiredPresetToken($value, 'color', self::REQUIRED_COLOR_SLUGS)) {
            return 'painted';
        }
        $value = self::normalizedCssDeclarationValue($value);
        if ($value === null || $value === '') {
            return 'invalid';
        }
        if (self::isFrozenPresetColorReference($value)) {
            return 'painted';
        }
        if (self::isUnresolvedCssFunction($value)) {
            return 'unknown';
        }
        $keyword = strtolower($value);
        if (in_array($keyword, ['initial', 'unset', 'revert'], true)) {
            return self::currentColorPaintState($context);
        }
        if ($keyword === 'revert-layer') {
            return 'underlying';
        }
        if ($keyword === 'inherit') {
            return 'unknown';
        }
        return self::cssColorState($value, $context);
    }

    /** @return 'visible'|'none'|'unknown'|'invalid'|'underlying' */
    private static function borderStyleState(mixed $value, bool $commonBorderStyle = false): string
    {
        if (!is_string($value)) {
            return 'invalid';
        }
        $value = self::normalizedCssDeclarationValue($value);
        if ($value === null || $value === '') {
            return 'invalid';
        }
        $value = strtolower($value);
        if (self::isUnresolvedCssFunction($value)) {
            return 'unknown';
        }
        if (in_array($value, [
            'dotted', 'dashed', 'solid', 'double', 'groove', 'ridge', 'inset', 'outset',
        ], true)) {
            return 'visible';
        }
        if (in_array($value, [
            'none', 'hidden', 'initial', 'unset', 'revert',
        ], true)) {
            return 'none';
        }
        if ($value === 'revert-layer') {
            return 'underlying';
        }
        return $value === 'inherit' ? 'unknown' : 'invalid';
    }

    /** @return 'positive'|'zero'|'unknown'|'invalid'|'underlying' */
    private static function borderWidthState(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) && $value > 0 ? 'positive' : 'invalid';
        }
        if (!is_string($value)) {
            return 'invalid';
        }
        if (self::isRequiredPresetToken($value, 'spacing', self::REQUIRED_SPACING_SLUGS)) {
            return 'positive';
        }
        $value = self::normalizedCssDeclarationValue($value);
        if ($value === null || $value === '') {
            return 'invalid';
        }
        $value = strtolower($value);
        if (self::isFrozenPresetSpacingReference($value)) {
            return 'positive';
        }
        if (self::cssPresetVariableSlug($value, 'spacing') !== null) {
            return 'invalid';
        }
        if (self::isUnresolvedCssFunction($value)) {
            return 'unknown';
        }
        if (in_array($value, ['thin', 'medium', 'thick'], true)) {
            return 'positive';
        }
        if (in_array($value, ['initial', 'unset', 'revert'], true)) {
            return 'positive';
        }
        if ($value === 'revert-layer') {
            return 'underlying';
        }
        if ($value === 'inherit') {
            return 'unknown';
        }
        $numeric = self::cssNumericState($value);
        if ($numeric['state'] !== 'valid') {
            return $numeric['state'];
        }
        if (!self::isCssLengthUnit($numeric['unit'])
            && !($numeric['unit'] === '' && abs($numeric['value']) <= 0.000000000001)
        ) {
            return 'invalid';
        }
        $function = self::leadingCssFunction($value);
        if ($numeric['value'] < 0 && $function === null) {
            return 'invalid';
        }
        return $numeric['value'] <= 0.000000000001 ? 'zero' : 'positive';
    }

    /**
     * Expand a scalar CSS 1–4-value border shorthand atomically. Any invalid
     * component invalidates the whole declaration; an unresolved component
     * makes every side unknown because substitution happens before parsing.
     *
     * @param array<mixed> $context
     * @return array{values:array{top:mixed,right:mixed,bottom:mixed,left:mixed},state:?string}|null
     */
    private static function borderShorthandState(
        string $property,
        mixed $value,
        array $context,
    ): ?array {
        $coerced = self::styleEngineString($value);
        if ($coerced === null) {
            return null;
        }
        $value = $coerced;
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }
        if (str_starts_with(strtolower($raw), 'var:preset|')) {
            $known = ($property === 'color'
                    && self::isRequiredPresetToken($raw, 'color', self::REQUIRED_COLOR_SLUGS))
                || ($property === 'width'
                    && self::isRequiredPresetToken($raw, 'spacing', self::REQUIRED_SPACING_SLUGS));
            return [
                'values' => array_combine(
                    ['top', 'right', 'bottom', 'left'],
                    array_fill(0, 4, $raw),
                ),
                'state' => $known ? null : 'unknown',
            ];
        }
        $declaration = self::cssDeclaration($raw);
        if ($declaration === null) {
            return null;
        }
        $parts = self::splitCssWhitespace($declaration['value']);
        if ($parts === null || count($parts) > 4) {
            return null;
        }
        $cssWide = ['initial', 'inherit', 'unset', 'revert', 'revert-layer'];
        if (count($parts) > 1
            && array_intersect(array_map('strtolower', $parts), $cssWide) !== []
        ) {
            return null;
        }
        $unknown = false;
        foreach ($parts as $part) {
            $state = match ($property) {
                'color' => self::borderColorState($part, $context),
                'style' => self::borderStyleState($part, true),
                default => self::borderWidthState($part),
            };
            if ($state === 'invalid') {
                return null;
            }
            $unknown = $unknown || $state === 'unknown';
        }
        $values = self::boxSideValues($raw);
        if ($values === null) {
            return null;
        }
        return [
            'values' => $unknown
                ? array_combine(
                    ['top', 'right', 'bottom', 'left'],
                    array_fill(0, 4, $raw),
                )
                : $values,
            'state' => $unknown ? 'unknown' : null,
        ];
    }

    private static function serializedValueExists(mixed $value): bool
    {
        return !($value === null || $value === false || $value === 0 || $value === 0.0
            || $value === '' || $value === []);
    }

    /** Match the JS truthiness checks used by Gutenberg block supports. */
    private static function styleEngineTruthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === 0 || $value === 0.0
            || $value === '');
    }

    /**
     * React style serialization applies JavaScript String() coercion. JSON
     * list arrays can therefore become valid CSS; associative object shapes
     * stringify to an invalid object marker and stay conservatively unknown.
     */
    private static function styleEngineString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            return null;
        }
        $parts = [];
        foreach ($value as $item) {
            if (is_string($item) || is_int($item) || is_float($item)) {
                $parts[] = (string) $item;
            } elseif ($item === null) {
                $parts[] = '';
            } elseif (is_bool($item)) {
                $parts[] = $item ? 'true' : 'false';
            } elseif (is_array($item) && array_is_list($item)) {
                $nested = self::styleEngineString($item);
                if ($nested === null) {
                    return null;
                }
                $parts[] = $nested;
            } else {
                return null;
            }
        }
        return implode(',', $parts);
    }

    private static function isRequiredColorSlug(mixed $value): bool
    {
        return is_string($value)
            && in_array(strtolower(trim($value)), self::REQUIRED_COLOR_SLUGS, true);
    }

    /**
     * Resolve the two preset spellings emitted by block attributes and CSS.
     * A complete theme context makes a missing variable definitely inert;
     * standalone callers without theme_json retain an unknown state instead.
     *
     * @param array<mixed> $paintPresets
     * @param array<mixed> $context
     * @return 'painted'|'transparent'|'unknown'|'absent'|null
     */
    private static function presetPaintState(
        string $value,
        string $type,
        array $paintPresets,
        array $context,
        bool $bareSlug = false,
    ): ?string {
        $raw = trim($value);
        $slug = null;
        $cssVariable = null;
        if ($bareSlug && preg_match('/\A[a-z0-9][a-z0-9._-]*\z/i', $raw) === 1) {
            $slug = strtolower($raw);
        } else {
            if (str_starts_with(strtolower($raw), 'var:preset|')) {
                if (preg_match(
                    '/\Avar:preset\|' . preg_quote($type, '/')
                        . '\|(?<slug>[a-z0-9][a-z0-9._-]*)\z/i',
                    $raw,
                    $match,
                ) === 1) {
                    $slug = strtolower($match['slug']);
                } else {
                    return ($paintPresets['complete'] ?? false) === true
                        ? 'transparent'
                        : 'unknown';
                }
            }
            $declaration = self::cssDeclaration($raw);
            $normalized = $declaration['value'] ?? $raw;
            if ($slug === null && preg_match(
                '/\Avar:preset\|' . preg_quote($type, '/') . '\|(?<slug>[a-z0-9][a-z0-9._-]*)\z/i',
                trim($normalized),
                $match,
            ) === 1) {
                $slug = strtolower($match['slug']);
            }
            $cssVariable = self::cssPresetVariableParts($normalized, $type);
            $slug ??= $cssVariable['slug'] ?? null;
        }
        if ($slug === null) {
            if ($bareSlug && ($paintPresets['complete'] ?? false) === true) {
                return 'absent';
            }
            return null;
        }
        $registry = is_array($paintPresets[$type] ?? null)
            ? $paintPresets[$type]
            : [];
        if (!array_key_exists($slug, $registry)) {
            if ($bareSlug && ($paintPresets['complete'] ?? false) === true) {
                return 'absent';
            }
            if (($paintPresets['complete'] ?? false) === true
                && is_string($cssVariable['fallback'] ?? null)
                && trim($cssVariable['fallback']) !== ''
            ) {
                $fallbackState = match ($type) {
                    'gradient' => self::gradientPaintState(
                        $cssVariable['fallback'],
                        $context,
                        $paintPresets,
                    ),
                    'shadow' => self::shadowPaintState(
                        $cssVariable['fallback'],
                        $context,
                        $paintPresets,
                    ),
                    default => self::cssColorState($cssVariable['fallback'], $context),
                };
                return $fallbackState === 'invalid' ? 'transparent' : $fallbackState;
            }
            return ($paintPresets['complete'] ?? false) === true
                ? 'transparent'
                : 'unknown';
        }
        $definition = $registry[$slug];
        if ($definition === true) {
            return 'painted';
        }
        if (!is_string($definition)) {
            return 'unknown';
        }
        $closedRegistry = [
            'complete' => true,
            'color' => [],
            'gradient' => [],
            'shadow' => [],
        ];
        $state = match ($type) {
            'gradient' => self::gradientPaintState($definition, $context, $closedRegistry),
            'shadow' => self::shadowPaintState($definition, $context, $closedRegistry),
            default => self::cssColorState($definition, $context),
        };
        return $state === 'invalid'
            ? ($bareSlug ? 'absent' : 'transparent')
            : $state;
    }

    /**
     * Gradient presets populate the complete `background` shorthand, so a
     * theme definition may paint its image channel, its color channel, both,
     * or neither. Preserve that distinction through later longhand cascade.
     *
     * @return array{image:string,color:string}|null
     */
    private static function presetGradientPaintStates(
        string $value,
        array $paintPresets,
        array $context,
        bool $bareSlug = false,
    ): ?array {
        $raw = trim($value);
        $slug = null;
        $fallback = null;
        if ($bareSlug) {
            if (preg_match('/\A[a-z0-9][a-z0-9._-]*\z/i', $raw) === 1) {
                $slug = strtolower($raw);
            } else {
                $state = ($paintPresets['complete'] ?? false) === true
                    ? 'absent'
                    : 'unknown';
                return ['image' => $state, 'color' => $state];
            }
        } elseif (str_starts_with(strtolower($raw), 'var:preset|')) {
            if (preg_match(
                '/\Avar:preset\|gradient\|(?<slug>[a-z0-9][a-z0-9._-]*)\z/i',
                $raw,
                $match,
            ) !== 1) {
                $state = ($paintPresets['complete'] ?? false) === true
                    ? 'transparent'
                    : 'unknown';
                return ['image' => $state, 'color' => $state];
            }
            $slug = strtolower($match['slug']);
        } else {
            $declaration = self::cssDeclaration($raw);
            $variable = self::cssPresetVariableParts(
                $declaration['value'] ?? $raw,
                'gradient',
            );
            if ($variable === null) {
                return null;
            }
            $slug = $variable['slug'];
            $fallback = $variable['fallback'];
        }

        $registry = is_array($paintPresets['gradient'] ?? null)
            ? $paintPresets['gradient']
            : [];
        if (!array_key_exists($slug, $registry)) {
            if ($bareSlug && ($paintPresets['complete'] ?? false) === true) {
                return ['image' => 'absent', 'color' => 'absent'];
            }
            if (($paintPresets['complete'] ?? false) === true
                && is_string($fallback)
                && trim($fallback) !== ''
            ) {
                return self::backgroundShorthandPaintStates(
                    $fallback,
                    $context,
                    $paintPresets,
                );
            }
            $state = ($paintPresets['complete'] ?? false) === true
                ? 'transparent'
                : 'unknown';
            return ['image' => $state, 'color' => $state];
        }
        $definition = $registry[$slug];
        if (!is_string($definition)) {
            return ['image' => 'unknown', 'color' => 'unknown'];
        }
        $closedRegistry = [
            'complete' => true,
            'color' => [],
            'gradient' => [],
            'shadow' => [],
        ];
        $states = self::backgroundShorthandPaintStates(
            $definition,
            $context,
            $closedRegistry,
        );
        if ($states['image'] === 'invalid' || $states['color'] === 'invalid') {
            $state = $bareSlug ? 'absent' : 'transparent';
            return ['image' => $state, 'color' => $state];
        }
        return $states;
    }

    /** @param list<string> $slugs */
    private static function isRequiredPresetToken(string $value, string $type, array $slugs): bool
    {
        return preg_match(
            '/\Avar:preset\|' . preg_quote($type, '/') . '\|(?<slug>[a-z0-9][a-z0-9._-]*)\z/i',
            trim($value),
            $match,
        ) === 1 && in_array(strtolower($match['slug']), $slugs, true);
    }

    /** @param array<mixed> $attrs */
    private static function currentColorPaintState(array $attrs, array $paintPresets = []): string
    {
        if ($paintPresets === []
            && is_array($attrs['__card_style_paint_presets'] ?? null)
        ) {
            $paintPresets = $attrs['__card_style_paint_presets'];
        }
        $textColor = $attrs['textColor'] ?? null;
        $presetState = self::isRequiredColorSlug($textColor)
            ? 'painted'
            : (is_string($textColor)
                && preg_match('/\A[a-z0-9][a-z0-9._-]*\z/i', trim($textColor)) === 1
                    ? self::presetPaintState(
                        $textColor,
                        'color',
                        $paintPresets,
                        $attrs,
                        true,
                    )
                    : null);
        $presetApplies = in_array($presetState, ['painted', 'transparent', 'unknown'], true);
        $underlying = $presetApplies ? $presetState : 'painted';
        $custom = self::nested($attrs, ['style', 'color', 'text']);
        if (is_array($custom) && $custom !== []) {
            $coerced = self::styleEngineString($custom);
            if ($coerced === null) {
                return $presetApplies ? $underlying : 'unknown';
            }
            $custom = $coerced;
        }
        if (!self::serializedValueExists($custom) || !is_string($custom)) {
            return $underlying;
        }
        $declaration = self::cssDeclaration($custom);
        if ($declaration === null) {
            return $underlying;
        }
        if ($presetApplies && !$declaration['important']) {
            return $underlying;
        }
        $normalized = $declaration['value'];
        $keyword = strtolower(trim($normalized));
        if ($keyword === 'initial') {
            return 'painted';
        }
        if (in_array($keyword, ['inherit', 'unset', 'revert', 'currentcolor'], true)) {
            return 'unknown';
        }
        if ($keyword === 'revert-layer') {
            return $underlying;
        }
        return match (self::cssColorState($normalized)) {
            'painted' => 'painted',
            'transparent' => 'transparent',
            'unknown' => 'unknown',
            default => 'painted',
        };
    }

    /** @param array<mixed> $context */
    private static function hasVisibleShadow(
        mixed $value,
        array $context = [],
        array $paintPresets = [],
    ): bool
    {
        return self::shadowPaintState($value, $context, $paintPresets) === 'painted';
    }

    /** @param array<mixed> $context @return 'painted'|'transparent'|'invalid'|'unknown' */
    private static function shadowPaintState(
        mixed $value,
        array $context = [],
        array $paintPresets = [],
    ): string
    {
        $context['__card_style_paint_presets'] = $paintPresets;
        if (is_array($value) && $value !== []) {
            $coerced = self::styleEngineString($value);
            if ($coerced === null) {
                return 'unknown';
            }
            $value = $coerced;
        }
        if (!self::serializedValueExists($value) || !is_string($value)) {
            return 'transparent';
        }
        $presetState = self::presetPaintState(
            $value,
            'shadow',
            $paintPresets,
            $context,
        );
        if ($presetState !== null) {
            return $presetState;
        }
        $normalized = self::normalizedCssDeclarationValue($value);
        if ($normalized === null) {
            return 'invalid';
        }
        $normalized = strtolower(trim($normalized));
        if (self::isUnresolvedCssFunction($normalized)) {
            return 'unknown';
        }
        if ($normalized === 'inherit') {
            return 'unknown';
        }
        if (in_array($normalized, [
            'none', 'initial', 'unset', 'revert', 'revert-layer',
        ], true)) {
            return 'transparent';
        }
        $layers = self::splitCssTopLevel($normalized);
        if ($layers === null || $layers === []) {
            return 'invalid';
        }
        $painted = false;
        foreach ($layers as $layer) {
            $state = self::shadowLayerPaintState($layer, $context);
            // One malformed/unknown layer invalidates or makes the complete
            // comma-separated declaration unverifiable at computed-value
            // time, so it cannot certify the framed-box requirement.
            if ($state === 'invalid') {
                return 'invalid';
            }
            if ($state === 'unknown') {
                return 'unknown';
            }
            $painted = $painted || $state === 'painted';
        }
        return $painted ? 'painted' : 'transparent';
    }

    /** @param array<mixed> $context @return 'painted'|'transparent'|'invalid'|'unknown' */
    private static function shadowLayerPaintState(string $layer, array $context): string
    {
        $tokens = self::splitCssWhitespace($layer);
        if ($tokens === null) {
            return 'invalid';
        }
        $lengths = [];
        $color = null;
        $inset = false;
        $uncertain = false;
        foreach ($tokens as $token) {
            if (strtolower($token) === 'inset') {
                if ($inset) {
                    return 'invalid';
                }
                $inset = true;
                continue;
            }
            if (self::isUnresolvedCssFunction($token)) {
                return 'unknown';
            }
            $colorState = self::cssColorState($token, $context);
            if ($colorState !== 'invalid') {
                if ($color !== null) {
                    return 'invalid';
                }
                $color = $colorState;
                continue;
            }
            $lengthState = self::shadowLengthState($token);
            if ($lengthState['state'] === 'invalid') {
                return 'invalid';
            }
            $uncertain = $uncertain || $lengthState['state'] === 'unknown';
            $lengths[] = $lengthState;
        }
        if (count($lengths) < 2 || count($lengths) > 4) {
            return 'invalid';
        }
        // Blur radius (the third length) cannot be negative.
        if (isset($lengths[2])
            && $lengths[2]['state'] === 'valid'
            && $lengths[2]['value'] < 0
            && !$lengths[2]['function']
        ) {
            return 'invalid';
        }
        $currentColor = $color === null
            ? self::currentColorPaintState($context)
            : null;
        if ($uncertain || $color === 'unknown' || $currentColor === 'unknown') {
            return 'unknown';
        }
        if ($color === 'transparent'
            || ($color === null && $currentColor === 'transparent')
        ) {
            return 'transparent';
        }
        $x = abs($lengths[0]['value']);
        $y = abs($lengths[1]['value']);
        $blur = isset($lengths[2]) ? max(0.0, $lengths[2]['value']) : 0.0;
        $spread = $lengths[3]['value'] ?? 0.0;
        $reach = max($x, $y) + $blur;
        if ($spread < 0) {
            $units = [];
            foreach ($lengths as $length) {
                if (abs($length['value']) > 0.000000000001 && $length['unit'] !== '') {
                    $units[$length['unit']] = true;
                }
            }
            if (count($units) > 1) {
                return 'unknown';
            }
            // A blurred negative-spread shadow can retain antialiased pixels
            // beyond the simple offset/spread box. Without a rendered box and
            // UA blur kernel, it cannot be proven swallowed.
            if ($blur > 0.000000000001
                && $reach <= abs($spread) + 0.000000000001
            ) {
                return 'unknown';
            }
        }
        if (($reach <= 0.000000000001 && $spread <= 0.000000000001)
            || ($spread < 0
                && $blur <= 0.000000000001
                && $reach <= abs($spread) + 0.000000000001)
        ) {
            return 'transparent';
        }
        return 'painted';
    }

    /** @return array{state:'valid'|'unknown'|'invalid',value?:float,unit?:string,function?:bool} */
    private static function shadowLengthState(string $value): array
    {
        $value = strtolower(trim($value));
        $numeric = self::cssNumericState($value);
        if ($numeric['state'] !== 'valid') {
            return ['state' => $numeric['state']];
        }
        if (!self::isCssLengthUnit($numeric['unit'])
            && !($numeric['unit'] === '' && abs($numeric['value']) <= 0.000000000001)
        ) {
            return ['state' => 'invalid'];
        }
        return [
            'state' => 'valid',
            'value' => $numeric['value'],
            'unit' => $numeric['unit'],
            'function' => self::leadingCssFunction($value) !== null,
        ];
    }

    /** @return 'painted'|'transparent'|'invalid'|'unknown' */
    private static function cssColorState(string $value, array $context = []): string
    {
        $value = self::normalizedCssDeclarationValue($value);
        if ($value === null) {
            return 'invalid';
        }
        $value = strtolower(trim($value));
        if ($value === 'transparent') {
            return 'transparent';
        }
        if (preg_match('/\A#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})\z/i', $value) === 1) {
            return preg_match('/\A#[0-9a-f]{3}0\z/i', $value) === 1
                || preg_match('/\A#[0-9a-f]{6}00\z/i', $value) === 1
                ? 'transparent'
                : 'painted';
        }
        if ($value === 'currentcolor') {
            return self::currentColorPaintState($context);
        }
        if (in_array($value, self::CSS_COLOR_NAMES, true)
            || in_array($value, self::CSS_SYSTEM_COLORS, true)
            || self::isFrozenPresetColorReference($value)
        ) {
            return 'painted';
        }
        $function = self::leadingCssFunction($value);
        if ($function === null
            || strlen($function['token']) !== strlen($value)
        ) {
            return preg_match('/\A(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|color|color-mix|light-dark|var|env)\s*\(/i', $value) === 1
                ? 'unknown'
                : 'invalid';
        }
        if ($function['name'] === 'color-mix') {
            return self::colorMixPaintState($function['token'], $context);
        }
        if (in_array($function['name'], ['var', 'env'], true)) {
            return 'unknown';
        }
        if ($function['name'] === 'light-dark') {
            $choices = self::splitCssTopLevel(substr(
                $function['token'],
                strpos($function['token'], '(') + 1,
                -1,
            ));
            if ($choices === null || count($choices) !== 2) {
                return 'invalid';
            }
            $states = array_map(
                static fn (string $choice): string => self::cssColorState($choice, $context),
                $choices,
            );
            if (in_array('invalid', $states, true)) {
                return 'invalid';
            }
            if (in_array('unknown', $states, true)) {
                return 'unknown';
            }
            // The frozen theme does not declare color-scheme, so the browser
            // resolves light-dark() through its first (light) branch.
            return $states[0];
        }
        if (!in_array($function['name'], [
            'rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch',
            'color',
        ], true)) {
            return 'invalid';
        }
        $args = substr(
            $function['token'],
            strpos($function['token'], '(') + 1,
            -1,
        );
        $syntax = self::numericColorSyntax($function['name'], $args);
        if ($syntax === null) {
            return 'invalid';
        }
        if ($syntax['uncertain']) {
            return 'unknown';
        }
        return $syntax['alphaZero']
            ? 'transparent'
            : 'painted';
    }

    /** @return 'painted'|'transparent'|'invalid'|'unknown'|'underlying' */
    private static function colorPaintState(string $value, array $context = []): string
    {
        if (self::isRequiredPresetToken($value, 'color', self::REQUIRED_COLOR_SLUGS)) {
            return 'painted';
        }
        $normalized = self::normalizedCssDeclarationValue($value);
        if ($normalized === null) {
            return 'invalid';
        }
        if (strtolower(trim($normalized)) === 'revert-layer') {
            return 'underlying';
        }
        if (strtolower(trim($normalized)) === 'inherit') {
            return 'unknown';
        }
        if (in_array(strtolower(trim($normalized)), [
            'initial', 'unset', 'revert',
        ], true)) {
            return 'transparent';
        }
        return self::cssColorState($normalized, $context);
    }

    /**
     * style.color.gradient is serialized as the complete `background`
     * shorthand. Keep its image and color channels separate because a later
     * background-color declaration can override only the latter.
     *
     * @param array<mixed> $context
     * @return array{image:'painted'|'transparent'|'invalid'|'unknown'|'underlying',color:'painted'|'transparent'|'invalid'|'unknown'|'underlying'}
     */
    private static function backgroundShorthandPaintStates(
        string $value,
        array $context = [],
        array $paintPresets = [],
    ): array {
        $presetStates = self::presetGradientPaintStates(
            $value,
            $paintPresets,
            $context,
        );
        if ($presetStates !== null) {
            return [
                'image' => $presetStates['image'] === 'absent'
                    ? 'transparent'
                    : $presetStates['image'],
                'color' => $presetStates['color'] === 'absent'
                    ? 'transparent'
                    : $presetStates['color'],
            ];
        }
        $value = self::normalizedCssDeclarationValue($value);
        if ($value === null) {
            return ['image' => 'invalid', 'color' => 'invalid'];
        }
        $value = strtolower(trim($value));
        if ($value === 'revert-layer') {
            return ['image' => 'underlying', 'color' => 'underlying'];
        }
        if ($value === 'inherit') {
            return ['image' => 'unknown', 'color' => 'unknown'];
        }
        if (in_array($value, [
            'none', 'initial', 'unset', 'revert',
        ], true)) {
            return ['image' => 'transparent', 'color' => 'transparent'];
        }
        if (self::isFrozenPresetColorReference($value)) {
            return ['image' => 'transparent', 'color' => 'painted'];
        }
        if (self::isUnresolvedCssFunction($value)) {
            return ['image' => 'unknown', 'color' => 'unknown'];
        }
        $color = self::cssColorState($value, $context);
        if (in_array($color, ['painted', 'transparent'], true)) {
            return ['image' => 'transparent', 'color' => $color];
        }
        if ($color === 'unknown') {
            return ['image' => 'unknown', 'color' => 'unknown'];
        }
        $image = self::gradientPaintState($value, $context, $paintPresets);
        if ($image !== 'invalid') {
            return [
                'image' => $image,
                'color' => $image === 'unknown' ? 'unknown' : 'transparent',
            ];
        }
        return self::backgroundShorthandLayerStates($value, $context, $paintPresets)
            ?? ['image' => 'invalid', 'color' => 'invalid'];
    }

    /**
     * Bounded fallback for complete background shorthands that combine an
     * image/color with position, size, repeat, attachment, or box tokens.
     *
     * @param array<mixed> $context
     * @return array{image:string,color:string}|null
     */
    private static function backgroundShorthandLayerStates(
        string $value,
        array $context,
        array $paintPresets = [],
    ): ?array {
        $layers = self::splitCssTopLevel($value);
        if ($layers === null || $layers === []) {
            return null;
        }
        $imagePainted = false;
        $imageUnknown = false;
        $color = 'transparent';
        foreach ($layers as $layerIndex => $layer) {
            $slash = self::splitCssAlpha($layer);
            if ($slash === null) {
                return null;
            }
            $before = self::splitCssWhitespace($slash['base']);
            $after = $slash['alpha'] === null
                ? []
                : self::splitCssWhitespace($slash['alpha']);
            if ($before === null || ($slash['alpha'] !== null && $after === null)) {
                return null;
            }
            $layerImage = null;
            $layerColor = null;
            $position = [];
            $size = [];
            $repeats = [];
            $attachments = 0;
            $boxes = 0;
            $textBoxes = 0;
            $syntaxUnknown = false;
            foreach ([false => $before, true => ($after ?? [])] as $afterSlash => $tokens) {
                foreach ($tokens as $token) {
                    $token = strtolower(trim($token));
                    if ($token === '') {
                        return null;
                    }
                    if (self::isUnresolvedCssFunction($token)) {
                        $syntaxUnknown = true;
                        if ($afterSlash) {
                            $size[] = $token;
                        } else {
                            $position[] = $token;
                        }
                        continue;
                    }
                    $colorState = self::cssColorState($token, $context);
                    if ($colorState !== 'invalid') {
                        if ($colorState === 'unknown') {
                            $syntaxUnknown = true;
                            continue;
                        }
                        if ($layerColor !== null || $layerIndex !== count($layers) - 1) {
                            return null;
                        }
                        $layerColor = $colorState;
                        continue;
                    }
                    if (!$afterSlash && $token === 'none') {
                        if ($layerImage !== null) {
                            return null;
                        }
                        $layerImage = 'transparent';
                        continue;
                    }
                    $function = self::leadingCssFunction($token);
                    if (!$afterSlash
                        && $function !== null
                        && strlen($function['token']) === strlen($token)
                    ) {
                        if ($layerImage !== null) {
                            return null;
                        }
                        if (str_contains($function['name'], 'gradient')) {
                            $state = self::gradientPaintState($token, $context, $paintPresets);
                            if ($state === 'invalid') {
                                return null;
                            }
                            if ($state === 'unknown'
                                && (preg_match('/\b(?:var|env)\s*\(/i', $token) === 1
                                    || strlen($token) > 65536)
                            ) {
                                $syntaxUnknown = true;
                            }
                            $layerImage = $state;
                        } elseif ($function['name'] === 'url'
                            || ($function['name'] === 'image-set'
                                && self::isSimpleValidImageSet($function['token']))
                        ) {
                            $layerImage = 'unknown';
                        } elseif (in_array($function['name'], [
                            'image', 'image-set', 'cross-fade', 'element', 'paint',
                        ], true)) {
                            $syntaxUnknown = true;
                            $layerImage = 'unknown';
                        } else {
                            return null;
                        }
                        continue;
                    }
                    if (in_array($token, [
                        'repeat', 'repeat-x', 'repeat-y', 'space', 'round', 'no-repeat',
                    ], true)) {
                        $repeats[] = $token;
                        continue;
                    }
                    if (in_array($token, ['scroll', 'fixed', 'local'], true)) {
                        if (++$attachments > 1) {
                            return null;
                        }
                        continue;
                    }
                    if (in_array($token, [
                        'border-box', 'padding-box', 'content-box', 'text',
                    ], true)) {
                        if (++$boxes > 2 || ($token === 'text' && ++$textBoxes > 1)) {
                            return null;
                        }
                        continue;
                    }
                    if ($afterSlash) {
                        if (in_array($token, ['cover', 'contain', 'auto'], true)
                            || self::backgroundSizeState($token) !== 'invalid'
                        ) {
                            $size[] = $token;
                            continue;
                        }
                        return null;
                    }
                    if (in_array($token, ['left', 'right', 'top', 'bottom', 'center'], true)
                        || self::gradientLengthPercentageState($token) !== 'invalid'
                    ) {
                        $position[] = $token;
                        continue;
                    }
                    return null;
                }
            }
            if (count($repeats) > 2
                || (count($repeats) > 1
                    && array_intersect($repeats, ['repeat-x', 'repeat-y']) !== [])
            ) {
                return null;
            }
            if ($slash['alpha'] !== null) {
                if ($position === [] || $size === [] || count($size) > 2
                    || (array_intersect($size, ['cover', 'contain']) !== [] && count($size) !== 1)
                ) {
                    return null;
                }
            } elseif ($size !== []) {
                return null;
            }
            if ($position !== []) {
                $positionState = self::gradientPositionPreludeState(implode(' ', $position));
                if ($positionState === 'invalid') {
                    return null;
                }
                $syntaxUnknown = $syntaxUnknown || $positionState === 'unknown';
            }
            foreach ($size as $sizeToken) {
                if (in_array($sizeToken, ['cover', 'contain', 'auto'], true)) {
                    continue;
                }
                $sizeState = self::backgroundSizeState($sizeToken);
                if ($sizeState === 'invalid') {
                    return null;
                }
                $syntaxUnknown = $syntaxUnknown || $sizeState === 'unknown';
            }
            if ($syntaxUnknown) {
                return ['image' => 'unknown', 'color' => 'unknown'];
            }
            $layerImage ??= 'transparent';
            $geometryState = self::backgroundSizeVisibilityState($size);
            if ($geometryState === 'transparent') {
                $layerImage = 'transparent';
            } elseif ($layerImage === 'painted'
                && array_intersect($repeats, ['no-repeat', 'repeat-x', 'repeat-y', 'space']) !== []
                && !self::backgroundPositionIsCertainlyOnCanvas($position)
            ) {
                $layerImage = 'unknown';
            }
            if ($layerImage === 'painted' && $textBoxes > 0) {
                $layerImage = 'unknown';
            }
            $imagePainted = $imagePainted || $layerImage === 'painted';
            $imageUnknown = $imageUnknown || $layerImage === 'unknown';
            if ($layerColor !== null) {
                $color = $layerColor;
            }
        }
        return [
            'image' => $imagePainted ? 'painted' : ($imageUnknown ? 'unknown' : 'transparent'),
            'color' => $color,
        ];
    }

    /** @return 'painted'|'transparent'|'invalid'|'unknown' */
    private static function gradientPaintState(
        string $value,
        array $context = [],
        array $paintPresets = [],
    ): string
    {
        $value = trim($value);
        $presetStates = self::presetGradientPaintStates(
            $value,
            $paintPresets,
            $context,
        );
        if ($presetStates !== null) {
            if (in_array('painted', $presetStates, true)) {
                return 'painted';
            }
            if (in_array('unknown', $presetStates, true)) {
                return 'unknown';
            }
            return 'transparent';
        }
        $value = self::normalizedCssDeclarationValue($value);
        if ($value === null) {
            return 'invalid';
        }
        $value = strtolower(trim($value));
        if (in_array($value, [
            'none', 'initial', 'unset', 'revert', 'revert-layer',
        ], true)) {
            return 'transparent';
        }
        if ($value === 'inherit') {
            return 'unknown';
        }
        if (self::isFrozenPresetColorReference($value)) {
            return 'painted';
        }
        if (self::isUnresolvedCssFunction($value)) {
            return 'unknown';
        }
        // style.color.gradient is emitted as the complete `background`
        // shorthand, not merely background-image. A single valid color is
        // therefore an applying shorthand that either paints or resets every
        // preset layer to transparent.
        $shorthandColor = self::cssColorState($value, $context);
        if (in_array($shorthandColor, ['painted', 'transparent'], true)) {
            return $shorthandColor;
        }
        if ($shorthandColor === 'unknown') {
            return 'unknown';
        }
        if (preg_match('/(?:\A|,)\s*,|,\s*\z/', $value) === 1) {
            return 'invalid';
        }
        $layers = self::splitCssTopLevel($value);
        if ($layers === null || $layers === []) {
            return 'unknown';
        }
        $paints = false;
        $uncertainPaint = false;
        foreach ($layers as $layer) {
            $function = self::leadingCssFunction(trim($layer));
            if ($function === null) {
                return preg_match('/\A(?:repeating-)?(?:linear|radial|conic)-gradient\s*\(/i', trim($layer)) === 1
                    ? 'unknown'
                    : 'invalid';
            }
            if (strlen($function['token']) !== strlen(trim($layer))) {
                return 'invalid';
            }
            if (!in_array($function['name'], [
                    'linear-gradient', 'radial-gradient', 'conic-gradient',
                    'repeating-linear-gradient', 'repeating-radial-gradient',
                    'repeating-conic-gradient',
                ], true)) {
                return 'invalid';
            }
            $family = str_replace('repeating-', '', $function['name']);
            $args = substr(
                $function['token'],
                strpos($function['token'], '(') + 1,
                -1,
            );
            if (preg_match('/(?:\A|,)\s*,|,\s*\z/', $args) === 1) {
                return 'invalid';
            }
            $parts = self::splitCssTopLevel($args);
            if ($parts === null) {
                return 'unknown';
            }
            $stops = 0;
            $previousWasHint = false;
            $colorStops = [];
            $layerComparable = true;
            $radialCollapses = false;
            $gradientGeometryUnknown = false;
            $radialDomainEndsAtBox = true;
            foreach ($parts as $position => $part) {
                $color = self::leadingCssColor($part);
                if ($color !== null) {
                    $tail = trim(substr(ltrim($part), strlen($color)));
                    $tailState = self::gradientPositionState($tail, $family, true);
                    if ($tailState === 'invalid') {
                        return 'invalid';
                    }
                    if ($tailState === 'unknown') {
                        return 'unknown';
                    }
                    $state = self::cssColorState($color, $context);
                    if ($state === 'invalid') {
                        return 'invalid';
                    }
                    if ($state === 'unknown') {
                        return 'unknown';
                    }
                    $positions = self::comparableGradientStopPositions($tail, $family);
                    if ($positions === null) {
                        $layerComparable = false;
                        if ($state === 'painted') {
                            $uncertainPaint = true;
                        }
                        $positionTokens = self::splitCssWhitespace($tail);
                        $positions = array_fill(
                            0,
                            max(1, is_array($positionTokens) ? count($positionTokens) : 1),
                            null,
                        );
                    }
                    foreach ($positions as $stopPosition) {
                        $colorStops[] = ['state' => $state, 'position' => $stopPosition];
                    }
                    $stops += count($positions);
                    $previousWasHint = false;
                    continue;
                }
                if ($position === 0) {
                    $prelude = self::gradientPreludeState($part, $family);
                    if ($prelude === 'valid') {
                        $radialCollapses = $family === 'radial-gradient'
                            && self::radialPreludeHasZeroDimension($part);
                        if ($family === 'radial-gradient') {
                            $radialDomainEndsAtBox = self::radialPreludeEndsAtFarthestCorner($part);
                        }
                        $gradientGeometryUnknown = in_array(
                            $family,
                            ['radial-gradient', 'conic-gradient'],
                            true,
                        ) && !self::gradientCenterIsCertainlyOnCanvas(
                            $part,
                            $family === 'conic-gradient',
                        );
                        $gradientGeometryUnknown = $gradientGeometryUnknown
                            || ($family === 'radial-gradient'
                                && preg_match('/\bclosest-(?:side|corner)\b/i', $part) === 1
                                && !self::gradientCenterIsCertainlyOnCanvas($part, true));
                        continue;
                    }
                    if ($prelude === 'unknown') {
                        return 'unknown';
                    }
                }
                $hint = self::gradientPositionState($part, $family, false);
                if ($hint === 'valid') {
                    if ($stops === 0 || $position === count($parts) - 1 || $previousWasHint) {
                        return 'invalid';
                    }
                    $previousWasHint = true;
                    continue;
                }
                if ($hint === 'unknown') {
                    return 'unknown';
                }
                return 'invalid';
            }
            if ($stops < 2) {
                return 'invalid';
            }
            $layerHasPainted = in_array('painted', array_column($colorStops, 'state'), true);
            $repeating = str_starts_with($function['name'], 'repeating-');
            $layerPaint = $radialCollapses
                ? ($repeating ? 'unknown' : ($colorStops[array_key_last($colorStops)]['state'] ?? 'unknown'))
                : ($layerComparable
                    ? self::gradientStopMeasureState(
                    $colorStops,
                    $repeating,
                )
                    : ($layerHasPainted ? 'unknown' : 'transparent'));
            if ($gradientGeometryUnknown
                && $layerPaint === 'painted'
                && in_array('transparent', array_column($colorStops, 'state'), true)
            ) {
                $layerPaint = 'unknown';
            }
            if ($family === 'radial-gradient'
                && !$radialDomainEndsAtBox
                && !$radialCollapses
                && $layerPaint === 'transparent'
                && $layerHasPainted
            ) {
                $layerPaint = 'unknown';
            }
            $paints = $paints || $layerPaint === 'painted';
            $uncertainPaint = $uncertainPaint || $layerPaint === 'unknown';
        }
        return $paints ? 'painted' : ($uncertainPaint ? 'unknown' : 'transparent');
    }

    /** @return ?list<?float> null means valid but not comparable without layout */
    private static function comparableGradientStopPositions(string $value, string $family): ?array
    {
        if ($value === '') {
            return [null];
        }
        $tokens = self::splitCssWhitespace($value);
        if ($tokens === null) {
            return null;
        }
        $positions = [];
        foreach ($tokens as $token) {
            $numeric = self::cssNumericState($token);
            if ($numeric['state'] !== 'valid') {
                return null;
            }
            if ($family === 'conic-gradient') {
                $positions[] = match ($numeric['unit']) {
                    '%' => $numeric['value'] / 100,
                    'deg' => $numeric['value'] / 360,
                    'grad' => $numeric['value'] / 400,
                    'rad' => $numeric['value'] / (2 * M_PI),
                    'turn' => $numeric['value'],
                    '' => abs($numeric['value']) <= 0.000000000001 ? 0.0 : null,
                    default => null,
                };
            } else {
                $positions[] = $numeric['unit'] === '%'
                    ? $numeric['value'] / 100
                    : ($numeric['unit'] === '' && abs($numeric['value']) <= 0.000000000001
                        ? 0.0
                        : null);
            }
            if (end($positions) === null) {
                return null;
            }
        }
        return $positions;
    }

    private static function radialPreludeHasZeroDimension(string $value): bool
    {
        $interpolation = self::gradientInterpolationParts(strtolower(trim($value)));
        if ($interpolation['state'] !== 'valid') {
            return false;
        }
        $base = $interpolation['base'];
        if (str_starts_with($base, 'at ')) {
            return false;
        }
        if (($at = strpos($base, ' at ')) !== false) {
            $base = trim(substr($base, 0, $at));
        }
        $tokens = self::splitCssWhitespace($base);
        if ($tokens === null) {
            return false;
        }
        foreach ($tokens as $token) {
            if (in_array($token, [
                'circle', 'ellipse', 'closest-side', 'closest-corner',
                'farthest-side', 'farthest-corner',
            ], true)) {
                continue;
            }
            $numeric = self::cssNumericState($token);
            if ($numeric['state'] === 'valid' && $numeric['value'] <= 0.000000000001) {
                return true;
            }
        }
        return false;
    }

    private static function radialPreludeEndsAtFarthestCorner(string $value): bool
    {
        $interpolation = self::gradientInterpolationParts(strtolower(trim($value)));
        if ($interpolation['state'] !== 'valid') {
            return false;
        }
        $base = $interpolation['base'];
        if (str_starts_with($base, 'at ')) {
            $base = '';
        } elseif (($at = strpos($base, ' at ')) !== false) {
            $base = trim(substr($base, 0, $at));
        }
        if ($base === '' || in_array($base, ['circle', 'ellipse'], true)) {
            return true;
        }
        $tokens = self::splitCssWhitespace($base);
        return $tokens !== null
            && in_array('farthest-corner', $tokens, true)
            && count(array_diff($tokens, ['circle', 'ellipse', 'farthest-corner'])) === 0;
    }

    private static function gradientCenterIsCertainlyOnCanvas(string $value, bool $strict = false): bool
    {
        $value = strtolower(trim($value));
        $position = null;
        if (str_starts_with($value, 'at ')) {
            $position = substr($value, 3);
        } elseif (($at = strpos($value, ' at ')) !== false) {
            $position = substr($value, $at + 4);
        }
        if ($position === null) {
            return true;
        }
        $interpolationAt = strpos($position, ' in ');
        if ($interpolationAt !== false) {
            $position = substr($position, 0, $interpolationAt);
        }
        $tokens = self::splitCssWhitespace(trim($position));
        if ($tokens === null || !self::backgroundPositionIsCertainlyOnCanvas($tokens)) {
            return false;
        }
        if (!$strict) {
            return true;
        }
        foreach ($tokens as $token) {
            if (in_array($token, ['left', 'right', 'top', 'bottom'], true)) {
                return false;
            }
            $numeric = self::cssNumericState($token);
            if ($numeric['state'] === 'valid'
                && (($numeric['unit'] === '%' && ($numeric['value'] <= 0 || $numeric['value'] >= 100))
                    || ($numeric['unit'] !== '%' && abs($numeric['value']) <= 0.000000000001))
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Determine whether an opaque stop contributes non-zero measure. CSS stop
     * fix-up clamps backwards positions and evenly distributes omitted ones.
     * Percent/angle domains are normalized to [0,1]; lengths remain unknown
     * because their rendered domain depends on box geometry.
     *
     * @param list<array{state:string,position:?float}> $stops
     * @return 'painted'|'transparent'|'unknown'
     */
    private static function gradientStopMeasureState(array $stops, bool $repeating): string
    {
        if ($stops === []) {
            return 'transparent';
        }
        $hasPainted = false;
        foreach ($stops as $stop) {
            $hasPainted = $hasPainted || $stop['state'] === 'painted';
        }
        if (!$hasPainted) {
            return 'transparent';
        }
        $lastIndex = count($stops) - 1;
        $stops[0]['position'] ??= 0.0;
        $stops[$lastIndex]['position'] ??= 1.0;
        $previous = -INF;
        foreach ($stops as $index => $stop) {
            if ($stop['position'] === null) {
                continue;
            }
            $stops[$index]['position'] = max($previous, $stop['position']);
            $previous = $stops[$index]['position'];
        }
        for ($index = 0; $index <= $lastIndex;) {
            if ($stops[$index]['position'] !== null) {
                $index++;
                continue;
            }
            $runStart = $index;
            while ($index <= $lastIndex && $stops[$index]['position'] === null) {
                $index++;
            }
            if ($index > $lastIndex) {
                return 'unknown';
            }
            $left = $stops[$runStart - 1]['position'];
            $right = $stops[$index]['position'];
            if (!is_float($left) || !is_float($right)) {
                return 'unknown';
            }
            $steps = $index - $runStart + 1;
            for ($offset = 0; $offset < $steps - 1; $offset++) {
                $stops[$runStart + $offset]['position'] = $left
                    + (($right - $left) * ($offset + 1) / $steps);
            }
        }
        if ($repeating) {
            $first = $stops[0]['position'];
            $last = $stops[$lastIndex]['position'];
            if (!is_float($first) || !is_float($last) || $last <= $first) {
                return 'unknown';
            }
            for ($index = 0; $index < $lastIndex; $index++) {
                if (($stops[$index + 1]['position'] - $stops[$index]['position']) > 0.000000000001
                    && ($stops[$index]['state'] === 'painted'
                        || $stops[$index + 1]['state'] === 'painted')
                ) {
                    return 'painted';
                }
            }
            return 'transparent';
        }
        if ($stops[0]['state'] === 'painted' && $stops[0]['position'] > 0.000000000001) {
            return 'painted';
        }
        for ($index = 0; $index < $lastIndex; $index++) {
            $start = max(0.0, $stops[$index]['position']);
            $end = min(1.0, $stops[$index + 1]['position']);
            if ($end - $start > 0.000000000001
                && ($stops[$index]['state'] === 'painted'
                    || $stops[$index + 1]['state'] === 'painted')
            ) {
                return 'painted';
            }
        }
        return $stops[$lastIndex]['state'] === 'painted'
            && $stops[$lastIndex]['position'] < 1.0 - 0.000000000001
            ? 'painted'
            : 'transparent';
    }

    private static function leadingCssColor(string $value): ?string
    {
        $value = ltrim($value);
        if (preg_match('/\A#(?:[0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{4}|[0-9a-f]{3})(?=\s|\z)/i', $value, $match) === 1) {
            return $match[0];
        }
        if (preg_match('/\A[a-z][a-z0-9-]*/i', $value, $match) === 1) {
            $identifier = strtolower($match[0]);
            $next = substr($value, strlen($match[0]), 1);
            if ($next !== '(' && ($identifier === 'transparent'
                || $identifier === 'currentcolor'
                || in_array($identifier, self::CSS_COLOR_NAMES, true)
                || in_array($identifier, self::CSS_SYSTEM_COLORS, true))
            ) {
                return $match[0];
            }
        }
        $function = self::leadingCssFunction($value);
        if ($function !== null && in_array($function['name'], [
            'rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch',
            'color', 'color-mix', 'device-cmyk', 'light-dark', 'var', 'env',
        ], true)) {
            return $function['token'];
        }
        return null;
    }

    /** @return array{name:string,token:string}|null */
    private static function leadingCssFunction(string $value): ?array
    {
        $value = ltrim($value);
        if (preg_match('/\A(?<name>[a-z][a-z0-9-]*)\(/i', $value, $match) !== 1) {
            return null;
        }
        $open = strpos($value, '(');
        if ($open === false) {
            return null;
        }
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($value);
        for ($offset = $open; $offset < $length; $offset++) {
            $char = $value[$offset];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                if (++$depth > 32) {
                    return null;
                }
            } elseif ($char === ')' && --$depth === 0) {
                return [
                    'name' => strtolower($match['name']),
                    'token' => substr($value, 0, $offset + 1),
                ];
            } elseif ($char === ')' && $depth < 0) {
                return null;
            }
        }
        return null;
    }

    private static function isUnresolvedCssFunction(string $value): bool
    {
        $value = trim($value);
        $function = self::leadingCssFunction($value);
        if ($function === null) {
            return preg_match('/\A(?:var|env)\s*\(/i', $value) === 1;
        }
        return in_array($function['name'], ['var', 'env'], true)
            && strlen($function['token']) === strlen($value);
    }

    private static function isFrozenPresetColorReference(string $value): bool
    {
        return self::isFrozenPresetReference($value, 'color', self::REQUIRED_COLOR_SLUGS);
    }

    private static function isFrozenPresetSpacingReference(string $value): bool
    {
        return self::isFrozenPresetReference($value, 'spacing', self::REQUIRED_SPACING_SLUGS);
    }

    /** @param list<string> $slugs */
    private static function isFrozenPresetReference(
        string $value,
        string $type,
        array $slugs,
    ): bool {
        $slug = self::cssPresetVariableSlug($value, $type);
        return $slug !== null && in_array($slug, $slugs, true);
    }

    private static function cssPresetVariableSlug(string $value, string $type): ?string
    {
        return self::cssPresetVariableParts($value, $type)['slug'] ?? null;
    }

    /** @return array{slug:string,fallback:?string}|null */
    private static function cssPresetVariableParts(string $value, string $type): ?array
    {
        $function = self::leadingCssFunction(trim($value));
        if ($function === null
            || $function['name'] !== 'var'
            || strlen($function['token']) !== strlen(trim($value))
        ) {
            return null;
        }
        $args = substr($function['token'], strpos($function['token'], '(') + 1, -1);
        $parts = self::splitCssTopLevel($args);
        if ($parts === null || $parts === []) {
            return null;
        }
        if (preg_match(
            '/\A--wp--preset--' . preg_quote($type, '/')
                . '--(?<slug>[a-z0-9][a-z0-9._-]*)\z/i',
            trim($parts[0]),
            $match,
        ) !== 1) {
            return null;
        }
        return [
            'slug' => strtolower($match['slug']),
            'fallback' => count($parts) > 1
                ? implode(', ', array_slice($parts, 1))
                : null,
        ];
    }

    /**
     * Parse only bounded, constant CSS numeric expressions. `unknown` means a
     * potentially valid expression whose value cannot be proven locally; it
     * must never certify paint or non-zero residue.
     *
     * @return array{state:'valid'|'unknown'|'invalid',value?:float,unit?:string}
     */
    private static function cssNumericState(string $value, int $depth = 0): array
    {
        if ($depth > 16 || strlen($value) > 65536) {
            return ['state' => 'unknown'];
        }
        if (str_contains($value, '/*')
            && preg_match('~/\*.*?\*/\s*[+-]|[+-]\s*/\*.*?\*/~s', $value) === 1
        ) {
            // Comments are removed by the CSS tokenizer; they do not provide
            // calc()'s mandatory whitespace around binary + and - operators.
            return ['state' => 'unknown'];
        }
        $value = self::stripCssComments($value);
        if ($value === null) {
            return ['state' => 'invalid'];
        }
        $value = strtolower(trim($value));
        if (preg_match(
            '/\A(?<number>[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?)'
                . '(?<unit>%|[a-z]+)?\z/i',
            $value,
            $match,
        ) === 1) {
            $number = (float) $match['number'];
            if (!is_finite($number)) {
                return ['state' => 'invalid'];
            }
            return [
                'state' => 'valid',
                'value' => $number,
                'unit' => strtolower($match['unit'] ?? ''),
            ];
        }
        $function = self::leadingCssFunction($value);
        if ($function === null || strlen($function['token']) !== strlen($value)) {
            return [
                'state' => preg_match('/\A(?:calc|min|max|clamp|var|env)\s*\(/i', $value) === 1
                    ? 'unknown'
                    : 'invalid',
            ];
        }
        if (in_array($function['name'], ['var', 'env'], true)) {
            return ['state' => 'unknown'];
        }
        $args = trim(substr(
            $function['token'],
            strpos($function['token'], '(') + 1,
            -1,
        ));
        if ($args === '') {
            return ['state' => 'invalid'];
        }
        if ($function['name'] === 'calc') {
            return self::cssCalcSumState($args, $depth + 1);
        }
        if (!in_array($function['name'], ['min', 'max', 'clamp'], true)) {
            return ['state' => 'invalid'];
        }
        $parts = self::splitCssTopLevel($args);
        $expected = $function['name'] === 'clamp' ? 3 : null;
        if ($parts === null || $parts === []
            || ($expected !== null && count($parts) !== $expected)
        ) {
            return ['state' => 'invalid'];
        }
        $values = [];
        $unit = null;
        foreach ($parts as $part) {
            $state = self::cssNumericState($part, $depth + 1);
            if ($state['state'] === 'invalid') {
                return $state;
            }
            if ($state['state'] === 'unknown') {
                return $state;
            }
            $unit ??= $state['unit'];
            if ($state['unit'] !== $unit) {
                return ['state' => 'unknown'];
            }
            $values[] = $state['value'];
        }
        $computed = match ($function['name']) {
            'min' => min($values),
            'max' => max($values),
            default => max($values[0], min($values[1], $values[2])),
        };
        return ['state' => 'valid', 'value' => $computed, 'unit' => $unit ?? ''];
    }

    /** @return array{state:'valid'|'unknown'|'invalid',value?:float,unit?:string} */
    private static function cssCalcSumState(string $value, int $depth): array
    {
        $parts = preg_split(
            '/\s+([+-])\s+/',
            trim($value),
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );
        if ($parts === false || $parts === []) {
            return ['state' => 'invalid'];
        }
        if (count($parts) === 1) {
            $state = self::cssNumericState($parts[0], $depth + 1);
            if ($state['state'] === 'invalid'
                && preg_match('~[*/]~', $parts[0]) === 1
                && preg_match('~[^0-9eE.+*/\s%a-z()-]~', $parts[0]) !== 1
            ) {
                return ['state' => 'unknown'];
            }
            return $state;
        }
        if (count($parts) % 2 === 0) {
            return ['state' => 'invalid'];
        }
        $first = self::cssNumericState($parts[0], $depth + 1);
        if ($first['state'] !== 'valid') {
            return $first;
        }
        $value = $first['value'];
        $unit = $first['unit'];
        for ($position = 1; $position < count($parts); $position += 2) {
            if (!in_array($parts[$position], ['+', '-'], true)
                || trim($parts[$position + 1]) === ''
            ) {
                return ['state' => 'invalid'];
            }
            $next = self::cssNumericState($parts[$position + 1], $depth + 1);
            if ($next['state'] !== 'valid') {
                return $next;
            }
            if ($next['unit'] !== $unit) {
                return ['state' => 'unknown'];
            }
            $value += $parts[$position] === '+' ? $next['value'] : -$next['value'];
        }
        return ['state' => 'valid', 'value' => $value, 'unit' => $unit];
    }

    private static function stripCssComments(string $value): ?string
    {
        if (strlen($value) > 65536) {
            return null;
        }
        $out = '';
        $quote = null;
        $escaped = false;
        $comment = false;
        $length = strlen($value);
        for ($offset = 0; $offset < $length; $offset++) {
            $char = $value[$offset];
            $next = $offset + 1 < $length ? $value[$offset + 1] : '';
            if ($comment) {
                if ($char === '*' && $next === '/') {
                    $comment = false;
                    $out .= ' ';
                    $offset++;
                }
                continue;
            }
            if ($quote !== null) {
                $out .= $char;
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '/' && $next === '*') {
                $comment = true;
                $offset++;
            } else {
                $out .= $char;
                if ($char === '"' || $char === "'") {
                    $quote = $char;
                }
            }
        }
        return $comment || $quote !== null ? null : $out;
    }

    private static function normalizedCssDeclarationValue(string $value): ?string
    {
        return self::cssDeclaration($value)['value'] ?? null;
    }

    /** @return array{value:string,important:bool}|null */
    private static function cssDeclaration(string $value): ?array
    {
        $value = self::stripCssComments($value);
        if ($value === null) {
            return null;
        }
        $important = preg_match('/\s*!\s*important\s*\z/i', $value) === 1;
        if ($important) {
            $value = preg_replace('/\s*!\s*important\s*\z/i', '', $value) ?? $value;
            if (preg_match('/\s*!\s*important\s*\z/i', $value) === 1) {
                return null;
            }
        }
        return ['value' => trim($value), 'important' => $important];
    }

    private static function cssDeclarationImportant(string $value): bool
    {
        return self::cssDeclaration($value)['important'] ?? false;
    }

    private static function serializedDeclarationImportant(string $value): bool
    {
        return !str_starts_with(strtolower(trim($value)), 'var:preset|')
            && self::cssDeclarationImportant($value);
    }

    /** @return array{base:string,alpha:?string}|null */
    private static function splitCssAlpha(string $value): ?array
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        $slash = null;
        $length = strlen($value);
        for ($offset = 0; $offset < $length; $offset++) {
            $char = $value[$offset];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                if (--$depth < 0) {
                    return null;
                }
            } elseif ($char === '/' && $depth === 0) {
                if ($slash !== null) {
                    return null;
                }
                $slash = $offset;
            }
        }
        if ($depth !== 0 || $quote !== null) {
            return null;
        }
        if ($slash === null) {
            return ['base' => trim($value), 'alpha' => null];
        }
        $base = trim(substr($value, 0, $slash));
        $alpha = trim(substr($value, $slash + 1));
        return $base === '' || $alpha === '' ? null : compact('base', 'alpha');
    }

    /** @return list<string>|null */
    private static function splitCssWhitespace(string $value): ?array
    {
        $parts = [];
        $start = null;
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($value);
        for ($offset = 0; $offset < $length; $offset++) {
            $char = $value[$offset];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $start ??= $offset;
            } elseif ($char === '(') {
                $depth++;
                $start ??= $offset;
            } elseif ($char === ')') {
                if (--$depth < 0) {
                    return null;
                }
            } elseif ($char === ',' && $depth === 0) {
                return null;
            } elseif (preg_match('/\s/', $char) === 1 && $depth === 0) {
                if ($start !== null) {
                    $parts[] = substr($value, $start, $offset - $start);
                    $start = null;
                }
            } else {
                $start ??= $offset;
            }
        }
        if ($depth !== 0 || $quote !== null) {
            return null;
        }
        if ($start !== null) {
            $parts[] = substr($value, $start);
        }
        return $parts === [] ? null : $parts;
    }

    /**
     * @return array{state:'valid'|'unknown'|'invalid',value?:float,unit?:string,none?:bool}
     */
    private static function colorComponentState(
        string $name,
        int $position,
        string $value,
        bool $alpha = false,
    ): array {
        $value = strtolower(trim($value));
        if ($value === 'none') {
            return ['state' => 'valid', 'value' => 0.0, 'unit' => '', 'none' => true];
        }
        $numeric = self::cssNumericState($value);
        if ($numeric['state'] !== 'valid') {
            return $numeric;
        }
        $name = match ($name) {
            'rgba' => 'rgb',
            'hsla' => 'hsl',
            default => $name,
        };
        $allowed = match (true) {
            $alpha => ['', '%'],
            $name === 'rgb' => ['', '%'],
            in_array($name, ['hsl', 'hwb'], true) && $position === 0
                => ['', 'deg', 'grad', 'rad', 'turn'],
            in_array($name, ['hsl', 'hwb'], true) => ['', '%'],
            in_array($name, ['lch', 'oklch'], true) && $position === 2
                => ['', 'deg', 'grad', 'rad', 'turn'],
            in_array($name, ['lab', 'lch', 'oklab', 'oklch', 'color'], true)
                => ['', '%'],
            default => [],
        };
        return in_array($numeric['unit'], $allowed, true)
            ? $numeric
            : ['state' => 'invalid'];
    }

    /** @return array{alphaZero:bool,uncertain:bool}|null */
    private static function numericColorSyntax(string $name, string $args): ?array
    {
        $args = trim($args);
        if ($args === '') {
            return null;
        }
        if (preg_match('/(?:\bfrom\s|\b(?:var|env)\s*\()/i', $args) === 1) {
            return ['alphaZero' => false, 'uncertain' => true];
        }
        $commaParts = self::splitCssTopLevel($args);
        if ($commaParts === null) {
            return null;
        }
        if (count($commaParts) > 1) {
            if (!in_array($name, ['rgb', 'rgba', 'hsl', 'hsla'], true)
                || !in_array(count($commaParts), [3, 4], true)
            ) {
                return null;
            }
            $rgbUnits = [];
            $uncertain = false;
            $alphaZero = false;
            foreach ($commaParts as $position => $component) {
                $alpha = $position === 3;
                $state = self::colorComponentState($name, $position, $component, $alpha);
                if ($state['state'] === 'invalid') {
                    return null;
                }
                if ($state['state'] === 'unknown') {
                    $uncertain = true;
                    continue;
                }
                if ($state['none'] ?? false) {
                    return null;
                }
                if ($alpha) {
                    $alphaZero = $state['value'] <= 0;
                } elseif (in_array($name, ['rgb', 'rgba'], true)) {
                    $rgbUnits[] = $state['unit'];
                } elseif (in_array($name, ['hsl', 'hsla'], true)
                    && $position > 0
                    && $state['unit'] !== '%'
                ) {
                    return null;
                }
            }
            if ($rgbUnits !== [] && count(array_unique($rgbUnits)) !== 1) {
                return null;
            }
            return ['alphaZero' => $alphaZero, 'uncertain' => $uncertain];
        }

        $split = self::splitCssAlpha($args);
        if ($split === null) {
            return null;
        }
        $components = self::splitCssWhitespace($split['base']);
        if ($components === null) {
            return null;
        }
        $expected = $name === 'device-cmyk' ? 4 : 3;
        if ($name === 'color') {
            $space = array_shift($components);
            if ($space === null || preg_match(
                '/\A(?:srgb|srgb-linear|display-p3|a98-rgb|prophoto-rgb|rec2020|xyz|xyz-d50|xyz-d65)\z/i',
                $space,
            ) !== 1) {
                return null;
            }
        }
        if (count($components) !== $expected) {
            return null;
        }
        $uncertain = false;
        foreach ($components as $position => $component) {
            $state = self::colorComponentState($name, $position, $component);
            if ($state['state'] === 'invalid') {
                return null;
            }
            $uncertain = $uncertain || $state['state'] === 'unknown';
        }
        $alphaZero = false;
        if ($split['alpha'] !== null) {
            $alpha = self::colorComponentState($name, 3, $split['alpha'], true);
            if ($alpha['state'] === 'invalid') {
                return null;
            }
            $uncertain = $uncertain || $alpha['state'] === 'unknown';
            if ($alpha['state'] === 'valid') {
                $alphaZero = ($alpha['none'] ?? false) || $alpha['value'] <= 0;
            }
        }
        return ['alphaZero' => $alphaZero, 'uncertain' => $uncertain];
    }

    /** @return 'painted'|'transparent'|'invalid'|'unknown' */
    private static function colorMixPaintState(string $value, array $context = []): string
    {
        $function = self::leadingCssFunction($value);
        if ($function === null
            || $function['name'] !== 'color-mix'
            || strlen($function['token']) !== strlen(trim($value))
        ) {
            return 'invalid';
        }
        $parts = self::splitCssTopLevel(substr(
            $function['token'],
            strpos($function['token'], '(') + 1,
            -1,
        ));
        if ($parts === null || count($parts) !== 3
            || preg_match(
                '/\Ain\s+(?<space>srgb|srgb-linear|display-p3|a98-rgb|prophoto-rgb|rec2020|lab|oklab|xyz|xyz-d50|xyz-d65|hsl|hwb|lch|oklch)'
                    . '(?:\s+(?<method>shorter|longer|increasing|decreasing)\s+hue)?\z/i',
                trim($parts[0]),
                $interpolation,
            ) !== 1
            || (($interpolation['method'] ?? '') !== ''
                && !in_array(strtolower($interpolation['space']), ['hsl', 'hwb', 'lch', 'oklch'], true))
        ) {
            return 'invalid';
        }
        $states = [];
        $weights = [];
        foreach (array_slice($parts, 1) as $component) {
            $color = self::leadingCssColor($component);
            if ($color === null) {
                return 'invalid';
            }
            $state = self::cssColorState($color, $context);
            if ($state === 'invalid') {
                return 'invalid';
            }
            $states[] = $state;
            $weight = trim(substr(ltrim($component), strlen($color)));
            if ($weight === '') {
                $weights[] = null;
                continue;
            }
            $numeric = self::cssNumericState($weight);
            if ($numeric['state'] === 'invalid'
                || ($numeric['state'] === 'valid' && $numeric['unit'] !== '%')
                || ($numeric['state'] === 'valid'
                    && $numeric['value'] < 0
                    && self::leadingCssFunction(strtolower(trim($weight))) === null)
                || ($numeric['state'] === 'valid'
                    && $numeric['value'] > 100
                    && self::leadingCssFunction(strtolower(trim($weight))) === null)
            ) {
                return 'invalid';
            }
            if ($numeric['state'] === 'unknown') {
                return 'unknown';
            }
            $weights[] = self::leadingCssFunction(strtolower(trim($weight))) !== null
                ? max(0.0, min(100.0, $numeric['value']))
                : $numeric['value'];
        }
        if ($weights[0] === null && $weights[1] === null) {
            $weights = [50.0, 50.0];
        } elseif ($weights[0] === null) {
            $weights[0] = 100.0 - $weights[1];
        } elseif ($weights[1] === null) {
            $weights[1] = 100.0 - $weights[0];
        }
        if ($weights[0] < 0 || $weights[1] < 0 || $weights[0] + $weights[1] <= 0) {
            return 'invalid';
        }
        // Custom-property substitution happens before color interpolation. An
        // unresolved component can invalidate the whole declaration even at
        // zero weight, so it cannot be ignored as a non-contributor.
        if (in_array('unknown', $states, true)) {
            return 'unknown';
        }
        $unknown = false;
        $painted = false;
        foreach ($states as $position => $state) {
            if ($weights[$position] <= 0) {
                continue;
            }
            $painted = $painted || $state === 'painted';
            $unknown = $unknown || $state === 'unknown';
        }
        return $unknown ? 'unknown' : ($painted ? 'painted' : 'transparent');
    }

    /** @return 'valid'|'invalid'|'unknown' */
    private static function gradientPreludeState(string $value, string $family): string
    {
        $interpolation = self::gradientInterpolationParts($value);
        if ($interpolation['state'] !== 'valid') {
            return $interpolation['state'];
        }
        $base = $interpolation['base'];
        if ($family === 'linear-gradient') {
            if ($base === '') {
                return $interpolation['present'] ? 'valid' : 'invalid';
            }
            if (preg_match(
                '/\Ato\s+(?<first>left|right|top|bottom)(?:\s+(?<second>left|right|top|bottom))?\z/',
                $base,
                $direction,
            ) === 1) {
                $second = $direction['second'] ?? '';
                if ($second === '') {
                    return 'valid';
                }
                $firstHorizontal = in_array($direction['first'], ['left', 'right'], true);
                $secondHorizontal = in_array($second, ['left', 'right'], true);
                return $firstHorizontal !== $secondHorizontal ? 'valid' : 'invalid';
            }
            return self::gradientAngleState($base);
        }
        if ($family === 'conic-gradient') {
            if ($base === '') {
                return $interpolation['present'] ? 'valid' : 'invalid';
            }
            $position = null;
            if (str_starts_with($base, 'at ')) {
                $position = substr($base, 3);
                $base = '';
            } elseif (($at = strpos($base, ' at ')) !== false) {
                $position = substr($base, $at + 4);
                $base = trim(substr($base, 0, $at));
            }
            if ($position !== null) {
                $state = self::gradientPositionPreludeState($position);
                if ($state !== 'valid') {
                    return $state;
                }
            }
            if ($base === '') {
                return $position !== null ? 'valid' : 'invalid';
            }
            if (!str_starts_with($base, 'from ')) {
                return 'invalid';
            }
            return self::gradientAngleState(substr($base, 5));
        }
        if ($family !== 'radial-gradient') {
            return 'invalid';
        }
        $position = null;
        if (str_starts_with($base, 'at ')) {
            $position = substr($base, 3);
            $base = '';
        } elseif (($at = strpos($base, ' at ')) !== false) {
            $position = substr($base, $at + 4);
            $base = trim(substr($base, 0, $at));
        }
        if ($position !== null) {
            $state = self::gradientPositionPreludeState($position);
            if ($state !== 'valid') {
                return $state;
            }
        }
        if ($base === '') {
            return ($position !== null || $interpolation['present']) ? 'valid' : 'invalid';
        }
        $tokens = self::splitCssWhitespace($base);
        if ($tokens === null || count($tokens) > 3) {
            return 'invalid';
        }
        $shapes = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => in_array($token, ['circle', 'ellipse'], true),
        ));
        $extents = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => in_array($token, [
                'closest-side', 'closest-corner', 'farthest-side', 'farthest-corner',
            ], true),
        ));
        if (count($shapes) > 1 || count($extents) > 1) {
            return 'invalid';
        }
        $sizes = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => !in_array($token, array_merge($shapes, $extents), true),
        ));
        if ($extents !== [] && $sizes !== []) {
            return 'invalid';
        }
        $uncertain = false;
        foreach ($sizes as $size) {
            $state = self::gradientLengthPercentageState($size);
            if ($state === 'invalid') {
                return 'invalid';
            }
            $uncertain = $uncertain || $state === 'unknown';
        }
        if ($sizes !== []) {
            $shape = $shapes[0] ?? (count($sizes) === 1 ? 'circle' : 'ellipse');
            if (($shape === 'circle' && count($sizes) !== 1)
                || ($shape === 'ellipse' && count($sizes) !== 2)
            ) {
                return 'invalid';
            }
            foreach ($sizes as $size) {
                $state = self::cssNumericState($size);
                if ($state['state'] !== 'valid') {
                    continue;
                }
                if ($shape === 'circle' && $state['unit'] === '%') {
                    return 'invalid';
                }
                $normalized = self::stripCssComments($size);
                if ($state['value'] < 0
                    && $normalized !== null
                    && self::leadingCssFunction(strtolower(trim($normalized))) === null
                ) {
                    return 'invalid';
                }
            }
        }
        return $uncertain ? 'unknown' : 'valid';
    }

    /** @return array{state:'valid'|'invalid',base:string,present:bool} */
    private static function gradientInterpolationParts(string $value): array
    {
        $value = strtolower(trim($value));
        $spaces = 'srgb|srgb-linear|display-p3|a98-rgb|prophoto-rgb|rec2020|lab|oklab|xyz|xyz-d50|xyz-d65|hsl|hwb|lch|oklch';
        $pattern = 'in\s+(?<space>' . $spaces . ')'
            . '(?:\s+(?<method>shorter|longer|increasing|decreasing)\s+hue)?';
        $match = [];
        if (preg_match('/\A' . $pattern . '(?:\s+(?<base>.+))?\z/', $value, $match) === 1) {
            $base = trim($match['base'] ?? '');
        } elseif (preg_match('/\A(?<base>.+?)\s+' . $pattern . '\z/', $value, $match) === 1) {
            $base = trim($match['base']);
        } else {
            return preg_match('/(?:\A|\s)in\s+/i', $value) === 1
                ? ['state' => 'invalid', 'base' => '', 'present' => false]
                : ['state' => 'valid', 'base' => $value, 'present' => false];
        }
        if (($match['method'] ?? '') !== ''
            && !in_array($match['space'], ['hsl', 'hwb', 'lch', 'oklch'], true)
        ) {
            return ['state' => 'invalid', 'base' => '', 'present' => false];
        }
        return ['state' => 'valid', 'base' => $base, 'present' => true];
    }

    /** @return 'valid'|'invalid'|'unknown' */
    private static function gradientAngleState(string $value): string
    {
        $numeric = self::cssNumericState($value);
        if ($numeric['state'] !== 'valid') {
            return $numeric['state'];
        }
        return in_array($numeric['unit'], ['deg', 'grad', 'rad', 'turn'], true)
            || ($numeric['unit'] === '' && abs($numeric['value']) <= 0.000000000001)
            ? 'valid'
            : 'invalid';
    }

    /** @return 'valid'|'invalid'|'unknown' */
    private static function gradientPositionPreludeState(string $value): string
    {
        $tokens = self::splitCssWhitespace(strtolower(trim($value)));
        if ($tokens === null || count($tokens) > 4) {
            return 'invalid';
        }
        $named = ['left', 'right', 'top', 'bottom', 'center'];
        if (count($tokens) === 1) {
            return in_array($tokens[0], $named, true)
                ? 'valid'
                : self::gradientLengthPercentageState($tokens[0]);
        }
        $unknown = false;
        for ($split = 1; $split < count($tokens); $split++) {
            $first = array_slice($tokens, 0, $split);
            $second = array_slice($tokens, $split);
            foreach ([['x', 'y'], ['y', 'x']] as [$firstAxis, $secondAxis]) {
                $firstState = self::gradientAxisPositionState($first, $firstAxis);
                $secondState = self::gradientAxisPositionState($second, $secondAxis);
                if ($firstState === 'valid' && $secondState === 'valid') {
                    return 'valid';
                }
                $unknown = $unknown
                    || ($firstState !== 'invalid' && $secondState !== 'invalid'
                        && ($firstState === 'unknown' || $secondState === 'unknown'));
            }
        }
        return $unknown ? 'unknown' : 'invalid';
    }

    /** @param list<string> $tokens @return 'valid'|'invalid'|'unknown' */
    private static function gradientAxisPositionState(array $tokens, string $axis): string
    {
        $edges = $axis === 'x' ? ['left', 'right'] : ['top', 'bottom'];
        if (count($tokens) === 1) {
            if ($tokens[0] === 'center' || in_array($tokens[0], $edges, true)) {
                return 'valid';
            }
            return self::gradientLengthPercentageState($tokens[0]);
        }
        if (count($tokens) !== 2 || !in_array($tokens[0], $edges, true)) {
            return 'invalid';
        }
        return self::gradientLengthPercentageState($tokens[1]);
    }

    /** @return 'valid'|'invalid'|'unknown' */
    private static function gradientLengthPercentageState(string $value): string
    {
        $numeric = self::cssNumericState($value);
        if ($numeric['state'] !== 'valid') {
            return $numeric['state'];
        }
        return $numeric['unit'] === '%'
            || self::isCssLengthUnit($numeric['unit'])
            || ($numeric['unit'] === '' && abs($numeric['value']) <= 0.000000000001)
            ? 'valid'
            : 'invalid';
    }

    /** @return 'valid'|'invalid'|'unknown' */
    private static function backgroundSizeState(string $value): string
    {
        $state = self::gradientLengthPercentageState($value);
        if ($state !== 'valid') {
            return $state;
        }
        $numeric = self::cssNumericState($value);
        return $numeric['value'] < 0 && self::leadingCssFunction(strtolower(trim($value))) === null
            ? 'invalid'
            : 'valid';
    }

    /** @param list<string> $size @return 'painted'|'transparent' */
    private static function backgroundSizeVisibilityState(array $size): string
    {
        foreach ($size as $token) {
            if (in_array($token, ['cover', 'contain', 'auto'], true)) {
                continue;
            }
            $numeric = self::cssNumericState($token);
            if ($numeric['state'] === 'valid' && $numeric['value'] <= 0.000000000001) {
                return 'transparent';
            }
        }
        return 'painted';
    }

    /** @param list<string> $position */
    private static function backgroundPositionIsCertainlyOnCanvas(array $position): bool
    {
        foreach ($position as $token) {
            if (in_array($token, ['left', 'right', 'top', 'bottom', 'center'], true)) {
                continue;
            }
            $numeric = self::cssNumericState($token);
            if ($numeric['state'] !== 'valid') {
                return false;
            }
            if ($numeric['unit'] === '%'
                && $numeric['value'] >= 0
                && $numeric['value'] <= 100
            ) {
                continue;
            }
            if (abs($numeric['value']) <= 0.000000000001) {
                continue;
            }
            return false;
        }
        return true;
    }

    private static function isSimpleValidImageSet(string $function): bool
    {
        $args = substr($function, strpos($function, '(') + 1, -1);
        $items = self::splitCssTopLevel($args);
        if ($items === null || $items === []) {
            return false;
        }
        foreach ($items as $item) {
            $tokens = self::splitCssWhitespace($item);
            if ($tokens === null || $tokens === []) {
                return false;
            }
            $source = self::leadingCssFunction($tokens[0]);
            $quoted = preg_match('/\A(?:"(?:[^"\\]|\\.)*"|\'(?:[^\'\\]|\\.)*\')\z/s', $tokens[0]) === 1;
            if (!$quoted && ($source === null
                    || $source['name'] !== 'url'
                    || strlen($source['token']) !== strlen($tokens[0]))) {
                return false;
            }
            foreach (array_slice($tokens, 1) as $descriptor) {
                if (preg_match('/\A(?:\d+(?:\.\d+)?x|\d+dpi|\d+dpcm|\d+dppx)\z/i', $descriptor) !== 1) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @return 'valid'|'invalid'|'unknown' */
    private static function gradientPositionState(
        string $value,
        string $family,
        bool $allowPair,
    ): string {
        if ($value === '') {
            return 'valid';
        }
        $positions = self::splitCssWhitespace($value);
        if ($positions === null || count($positions) > ($allowPair ? 2 : 1)) {
            return 'invalid';
        }
        $uncertain = false;
        foreach ($positions as $position) {
            $state = $family === 'conic-gradient'
                ? self::gradientConicPositionState($position)
                : self::gradientLengthPercentageState($position);
            if ($state === 'invalid') {
                return 'invalid';
            }
            $uncertain = $uncertain || $state === 'unknown';
        }
        return $uncertain ? 'unknown' : 'valid';
    }

    /** @return 'valid'|'invalid'|'unknown' */
    private static function gradientConicPositionState(string $value): string
    {
        $numeric = self::cssNumericState($value);
        if ($numeric['state'] !== 'valid') {
            return $numeric['state'];
        }
        return $numeric['unit'] === '%'
            || in_array($numeric['unit'], ['deg', 'grad', 'rad', 'turn'], true)
            || ($numeric['unit'] === '' && abs($numeric['value']) <= 0.000000000001)
            ? 'valid'
            : 'invalid';
    }

    private static function isCssLengthUnit(string $unit): bool
    {
        return in_array($unit, [
            'cap', 'ch', 'em', 'ex', 'ic', 'lh', 'rcap', 'rch', 'rem', 'rex', 'ric', 'rlh',
            'cm', 'mm', 'q', 'in', 'pc', 'pt', 'px', 'vb', 'vh', 'vi', 'vmax', 'vmin', 'vw',
            'svb', 'svh', 'svi', 'svmax', 'svmin', 'svw',
            'lvb', 'lvh', 'lvi', 'lvmax', 'lvmin', 'lvw',
            'dvb', 'dvh', 'dvi', 'dvmax', 'dvmin', 'dvw',
            'cqb', 'cqh', 'cqi', 'cqmax', 'cqmin', 'cqw',
        ], true);
    }

    /** @return ?list<string> null means malformed or outside the bounded scanner domain */
    private static function splitCssTopLevel(string $value): ?array
    {
        if (strlen($value) > 65536) {
            return null;
        }
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $escaped = false;
        $comment = false;
        $length = strlen($value);
        for ($offset = 0; $offset < $length; $offset++) {
            $char = $value[$offset];
            $next = $offset + 1 < $length ? $value[$offset + 1] : '';
            if ($comment) {
                if ($char === '*' && $next === '/') {
                    $comment = false;
                    $offset++;
                }
                continue;
            }
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '/' && $next === '*') {
                $comment = true;
                $offset++;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                if (++$depth > 32) {
                    return null;
                }
            } elseif ($char === ')') {
                if (--$depth < 0) {
                    return null;
                }
            } elseif ($char === ',' && $depth === 0) {
                $part = trim(substr($value, $start, $offset - $start));
                if ($part === '') {
                    return null;
                }
                $parts[] = $part;
                $start = $offset + 1;
                if (count($parts) >= 1024) {
                    return null;
                }
            }
        }
        if ($depth !== 0 || $quote !== null || $comment) {
            return null;
        }
        $last = trim(substr($value, $start));
        if ($last === '') {
            return null;
        }
        $parts[] = $last;
        return $parts;
    }

    private static function hasAllSidePadding(mixed $padding): bool
    {
        $sides = self::spacingSideValues($padding, 'padding');
        if ($sides === null) {
            return false;
        }
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if (!self::paddingSidePaints($sides[$side] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private static function paddingResidueEvidence(mixed $padding): array
    {
        $sides = self::spacingSideValues($padding, 'padding');
        if ($sides === null) {
            return [];
        }
        $evidence = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if (array_key_exists($side, $sides) && self::paddingSidePaints($sides[$side])) {
                $evidence[$side] = $sides[$side];
            }
        }
        return is_string($padding) && $evidence !== []
            ? ['shorthand' => $padding]
            : $evidence;
    }

    /** @return array<string,mixed> */
    private static function possiblePaddingResidueEvidence(mixed $padding): array
    {
        $sides = self::spacingSideValues($padding, 'padding');
        if ($sides === null) {
            return [];
        }
        $evidence = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $value = $sides[$side] ?? null;
            if (in_array(self::paddingSideState($value), ['positive', 'unknown'], true)) {
                $evidence[$side] = $value;
            }
        }
        return is_string($padding) && $evidence !== []
            ? ['shorthand' => $padding]
            : $evidence;
    }

    /** @return array<string,mixed> */
    private static function importantPaddingResidueEvidence(mixed $padding): array
    {
        $sides = self::spacingSideValues($padding, 'padding');
        if ($sides === null) {
            return [];
        }
        if (is_string($padding)) {
            $possible = false;
            foreach ($sides as $value) {
                $possible = $possible
                    || in_array(self::paddingSideState($value), ['positive', 'unknown'], true);
            }
            return self::serializedDeclarationImportant($padding)
                && $possible
                ? ['shorthand' => $padding]
                : [];
        }
        $evidence = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $value = $sides[$side] ?? null;
            if (is_string($value)
                && self::serializedDeclarationImportant($value)
                && in_array(self::paddingSideState($value), ['positive', 'unknown'], true)
            ) {
                $evidence[$side] = $value;
            }
        }
        return $evidence;
    }

    /** @return array<string,mixed> */
    private static function importantRadiusResidueEvidence(mixed $radius): array
    {
        if (is_string($radius)) {
            return self::serializedDeclarationImportant($radius)
                && self::radiusMayHaveResidue($radius, false)
                ? ['radius' => $radius]
                : [];
        }
        if (!is_array($radius)) {
            return [];
        }
        $evidence = [];
        foreach (['topLeft', 'topRight', 'bottomLeft', 'bottomRight'] as $corner) {
            $value = $radius[$corner] ?? null;
            if (is_string($value)
                && self::serializedDeclarationImportant($value)
                && self::radiusMayHaveResidue($value, true)
            ) {
                $evidence[$corner] = $value;
            }
        }
        return $evidence;
    }

    /** @return array{top:mixed,right:mixed,bottom:mixed,left:mixed}|null */
    private static function boxSideValues(mixed $value): ?array
    {
        if (is_array($value)) {
            return [
                'top' => $value['top'] ?? null,
                'right' => $value['right'] ?? null,
                'bottom' => $value['bottom'] ?? null,
                'left' => $value['left'] ?? null,
            ];
        }
        if (!is_string($value)) {
            return null;
        }
        if (str_starts_with(strtolower(trim($value)), 'var:preset|')) {
            return array_combine(
                ['top', 'right', 'bottom', 'left'],
                array_fill(0, 4, $value),
            );
        }
        $declaration = self::cssDeclaration($value);
        if ($declaration === null) {
            return null;
        }
        $parts = self::splitCssWhitespace($declaration['value']);
        if ($parts === null || count($parts) > 4) {
            return null;
        }
        return match (count($parts)) {
            1 => array_combine(['top', 'right', 'bottom', 'left'], array_fill(0, 4, $parts[0])),
            2 => [
                'top' => $parts[0], 'right' => $parts[1],
                'bottom' => $parts[0], 'left' => $parts[1],
            ],
            3 => [
                'top' => $parts[0], 'right' => $parts[1],
                'bottom' => $parts[2], 'left' => $parts[1],
            ],
            default => array_combine(['top', 'right', 'bottom', 'left'], $parts),
        };
    }

    /** @return array{top:mixed,right:mixed,bottom:mixed,left:mixed}|null */
    private static function spacingSideValues(mixed $value, string $property): ?array
    {
        $sides = self::boxSideValues($value);
        if ($sides === null || is_array($value)) {
            return $sides;
        }
        $raw = trim($value);
        if (str_starts_with(strtolower($raw), 'var:preset|')) {
            return $sides;
        }
        $declaration = self::cssDeclaration($raw);
        if ($declaration === null) {
            return null;
        }
        $parts = self::splitCssWhitespace($declaration['value']);
        if ($parts === null || count($parts) > 4) {
            return null;
        }
        $cssWide = ['initial', 'inherit', 'unset', 'revert', 'revert-layer'];
        if (count($parts) > 1
            && array_intersect(array_map('strtolower', $parts), $cssWide) !== []
        ) {
            return null;
        }
        foreach ($parts as $part) {
            $part = strtolower(trim($part));
            if (in_array($part, $cssWide, true)
                || ($property === 'margin' && $part === 'auto')
                || self::isUnresolvedCssFunction($part)
            ) {
                continue;
            }
            if (str_starts_with($part, 'var:preset|')) {
                if (!self::isRequiredPresetToken(
                    $part,
                    'spacing',
                    self::REQUIRED_SPACING_SLUGS,
                )) {
                    continue;
                }
                continue;
            }
            $numeric = self::cssNumericState($part);
            if ($numeric['state'] === 'unknown') {
                continue;
            }
            if ($numeric['state'] === 'invalid'
                || (!self::isCssLengthUnit($numeric['unit']) && $numeric['unit'] !== '%'
                    && !($numeric['unit'] === '' && abs($numeric['value']) <= 0.000000000001))
                || ($property === 'padding'
                    && $numeric['value'] < 0
                    && self::leadingCssFunction($part) === null)
            ) {
                return null;
            }
        }
        return $sides;
    }

    private static function boxSideIsImportant(mixed $value, string $side): bool
    {
        if (is_string($value)) {
            return self::serializedDeclarationImportant($value);
        }
        return is_array($value)
            && is_string($value[$side] ?? null)
            && self::serializedDeclarationImportant($value[$side]);
    }

    private static function marginSideHasResidue(mixed $value): bool
    {
        return self::marginSideState($value) === 'nonzero';
    }

    /** @return 'nonzero'|'zero'|'unknown'|'invalid'|'underlying' */
    private static function marginSideState(mixed $value): string
    {
        if (self::isAbsent($value)) {
            return 'zero';
        }
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value)) {
                return 'invalid';
            }
            return abs((float) $value) > 0.000000000001 ? 'nonzero' : 'zero';
        }
        if (!is_string($value)) {
            return 'invalid';
        }
        if (str_starts_with(strtolower(trim($value)), 'var:preset|')) {
            return self::isRequiredPresetToken(
                $value,
                'spacing',
                self::REQUIRED_SPACING_SLUGS,
            ) ? 'nonzero' : 'invalid';
        }
        $value = self::normalizedCssDeclarationValue($value);
        if ($value === null) {
            return 'invalid';
        }
        $value = strtolower(trim($value));
        if (self::isFrozenPresetSpacingReference($value)) {
            return 'nonzero';
        }
        if (self::cssPresetVariableSlug($value, 'spacing') !== null) {
            return 'invalid';
        }
        if (in_array($value, ['initial', 'unset', 'revert'], true)) {
            return 'zero';
        }
        if ($value === 'revert-layer') {
            return 'underlying';
        }
        if ($value === 'inherit' || self::isUnresolvedCssFunction($value)) {
            return 'unknown';
        }
        $numeric = self::cssNumericState($value);
        if ($numeric['state'] !== 'valid') {
            return $numeric['state'];
        }
        if (!self::isCssLengthUnit($numeric['unit']) && $numeric['unit'] !== '%'
            && !($numeric['unit'] === '' && abs($numeric['value']) <= 0.000000000001)
        ) {
            return 'invalid';
        }
        return abs($numeric['value']) > 0.000000000001 ? 'nonzero' : 'zero';
    }

    private static function paddingSidePaints(mixed $value): bool
    {
        return self::paddingSideState($value) === 'positive';
    }

    /** @return 'positive'|'zero'|'unknown'|'invalid' */
    private static function paddingSideState(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value)) {
                return 'invalid';
            }
            return $value > 0 ? 'positive' : 'zero';
        }
        if (!is_string($value)) {
            return 'invalid';
        }
        if (str_starts_with(strtolower(trim($value)), 'var:preset|')) {
            return self::isRequiredPresetToken(
                $value,
                'spacing',
                self::REQUIRED_SPACING_SLUGS,
            ) ? 'positive' : 'invalid';
        }
        $value = self::normalizedCssDeclarationValue($value);
        if ($value === null || $value === '') {
            return 'invalid';
        }
        $value = strtolower(trim($value));
        if (self::isFrozenPresetSpacingReference($value)) {
            return 'positive';
        }
        if (self::cssPresetVariableSlug($value, 'spacing') !== null) {
            return 'invalid';
        }
        if (self::isUnresolvedCssFunction($value) || $value === 'inherit') {
            return 'unknown';
        }
        if (in_array($value, ['initial', 'unset', 'revert', 'revert-layer'], true)) {
            return 'zero';
        }
        $numeric = self::cssNumericState($value);
        if ($numeric['state'] !== 'valid') {
            return $numeric['state'];
        }
        if (!self::isCssLengthUnit($numeric['unit']) && $numeric['unit'] !== '%'
            && !($numeric['unit'] === '' && abs($numeric['value']) <= 0.000000000001)
        ) {
            return 'invalid';
        }
        if ($numeric['value'] < 0 && self::leadingCssFunction($value) === null) {
            return 'invalid';
        }
        return $numeric['value'] > 0.000000000001 ? 'positive' : 'zero';
    }

    /** @return array{expected:string,matches:bool}|null */
    private static function comparableFramedRadii(
        mixed $cardRadius,
        mixed $padding,
        mixed $imageRadius,
    ): ?array {
        $card = self::pixelValue($cardRadius);
        $image = self::pixelValue($imageRadius);
        $padding = self::spacingSideValues($padding, 'padding');
        if ($card === null || $image === null || $padding === null) {
            return null;
        }
        $sides = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $value = self::pixelValue($padding[$side] ?? null);
            if ($value === null) {
                return null;
            }
            $sides[] = $value;
        }
        if (max($sides) - min($sides) > 0.000001) {
            return null;
        }
        $expected = max($card - $sides[0], 2.0);
        return [
            'expected' => self::formatPixels($expected),
            'matches' => abs($image - $expected) <= 0.000001,
        ];
    }

    private static function pixelValue(mixed $value): ?float
    {
        if (!is_string($value)) {
            return null;
        }
        $value = self::normalizedCssDeclarationValue($value);
        if ($value === null) {
            return null;
        }
        $numeric = self::cssNumericState($value);
        if ($numeric['state'] !== 'valid' || $numeric['unit'] !== 'px') {
            return null;
        }
        if ($numeric['value'] < 0) {
            $function = self::leadingCssFunction(strtolower(trim($value)));
            return $function === null ? null : 0.0;
        }
        return $numeric['value'];
    }

    private static function cssLengthEquals(mixed $value, string $unit, float $expected): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $value = self::normalizedCssDeclarationValue($value);
        if ($value === null) {
            return false;
        }
        $numeric = self::cssNumericState($value);
        return $numeric['state'] === 'valid'
            && $numeric['unit'] === $unit
            && abs($numeric['value'] - $expected) <= 0.000001;
    }

    private static function formatPixels(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        return ($formatted === '' ? '0' : $formatted) . 'px';
    }

    private static function isZeroOrAbsent(mixed $value): bool
    {
        if (self::isAbsent($value)) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $nested) {
                if (!self::isZeroOrAbsent($nested)) {
                    return false;
                }
            }
            return true;
        }
        return self::isZero($value);
    }

    private static function isAbsent(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private static function hasSerializedRadiusResidue(mixed $value, bool $numericAllowed): bool
    {
        if (is_int($value) || is_float($value)) {
            return $numericAllowed && is_finite((float) $value) && $value > 0;
        }
        if (!is_string($value) || ($value = self::normalizedCssDeclarationValue($value)) === null) {
            return false;
        }
        $value = strtolower(trim($value));
        if ($value === '' || self::isZero($value)
            || in_array($value, ['initial', 'unset', 'revert', 'revert-layer'], true)
        ) {
            return false;
        }
        if ($value === 'inherit'
            || preg_match('/\Avar:preset\|[a-z0-9|._-]+\z/i', $value) === 1
        ) {
            return false;
        }
        $split = self::splitCssAlpha($value);
        if ($split === null) {
            return false;
        }
        $groups = [$split['base']];
        if ($split['alpha'] !== null) {
            $groups[] = $split['alpha'];
        }
        $hasResidue = false;
        foreach ($groups as $group) {
            $components = self::splitCssWhitespace($group);
            if ($components === null || count($components) > 4) {
                return false;
            }
            foreach ($components as $component) {
                $state = self::radiusComponentState($component);
                if ($state === null) {
                    return false;
                }
                $hasResidue = $hasResidue || $state;
            }
        }
        return $hasResidue;
    }

    private static function hasRadiusResidue(mixed $value): bool
    {
        if (!is_array($value)) {
            return self::hasSerializedRadiusResidue($value, false);
        }
        foreach (['topLeft', 'topRight', 'bottomLeft', 'bottomRight'] as $corner) {
            if (array_key_exists($corner, $value)
                && !is_array($value[$corner])
                && self::hasSerializedRadiusResidue($value[$corner], true)
            ) {
                return true;
            }
        }
        return false;
    }

    private static function radiusMayHaveResidue(mixed $value, bool $numericAllowed): bool
    {
        if (self::hasSerializedRadiusResidue($value, $numericAllowed)) {
            return true;
        }
        if (!is_string($value)) {
            return false;
        }
        if (str_starts_with(strtolower(trim($value)), 'var:preset|')) {
            return self::isRequiredPresetToken(
                $value,
                'spacing',
                self::REQUIRED_SPACING_SLUGS,
            );
        }
        $value = self::normalizedCssDeclarationValue($value);
        if ($value === null) {
            return false;
        }
        $value = strtolower(trim($value));
        if (self::isFrozenPresetSpacingReference($value)) {
            return true;
        }
        if (self::cssPresetVariableSlug($value, 'spacing') !== null) {
            return false;
        }
        if ($value === 'inherit' || self::isUnresolvedCssFunction($value)) {
            return true;
        }
        $parts = self::splitCssAlpha($value);
        if ($parts === null) {
            return false;
        }
        foreach (array_filter([$parts['base'], $parts['alpha']]) as $group) {
            $components = self::splitCssWhitespace($group);
            if ($components === null || count($components) > 4) {
                return false;
            }
            foreach ($components as $component) {
                if (self::cssNumericState($component)['state'] === 'unknown') {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private static function possibleRadiusResidueEvidence(mixed $value): array
    {
        if (!is_array($value)) {
            return self::radiusMayHaveResidue($value, false) ? ['radius' => $value] : [];
        }
        $evidence = [];
        foreach (['topLeft', 'topRight', 'bottomLeft', 'bottomRight'] as $corner) {
            if (array_key_exists($corner, $value)
                && !is_array($value[$corner])
                && self::radiusMayHaveResidue($value[$corner], true)
            ) {
                $evidence[$corner] = $value[$corner];
            }
        }
        return $evidence;
    }

    /** null is invalid, false computes zero, true may compute non-zero. */
    private static function radiusComponentState(string $value): ?bool
    {
        $value = strtolower(trim($value));
        $numeric = self::cssNumericState($value);
        if ($numeric['state'] === 'unknown') {
            return false;
        }
        if ($numeric['state'] === 'invalid'
            || (!self::isCssLengthUnit($numeric['unit']) && $numeric['unit'] !== '%'
                && !($numeric['unit'] === '' && abs($numeric['value']) <= 0.000000000001))
        ) {
            return null;
        }
        $function = self::leadingCssFunction($value);
        if ($numeric['value'] < 0 && $function === null) {
            return null;
        }
        return $numeric['value'] > 0.000000000001;
    }

    private static function isZero(mixed $value): bool
    {
        if ($value === 0 || $value === 0.0) {
            return true;
        }
        if (!is_string($value) || ($value = self::normalizedCssDeclarationValue($value)) === null) {
            return false;
        }
        $numeric = self::cssNumericState($value);
        return $numeric['state'] === 'valid'
            && ($numeric['unit'] === '' || $numeric['unit'] === '%'
                || self::isCssLengthUnit($numeric['unit']))
            && abs($numeric['value']) <= 0.000000000001;
    }

    /** @param array<mixed> $attrs @param list<string> $path */
    private static function nested(array $attrs, array $path): mixed
    {
        $value = $attrs;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    private static function blockPath(BlockMarkup $document, int $index): string
    {
        $segments = [];
        $cursor = $index;
        while (true) {
            $parent = $document->parent($cursor);
            $siblings = $parent === null
                ? array_values(array_filter(
                    $document->indices(),
                    static fn (int $candidate): bool => $document->parent($candidate) === null,
                ))
                : $document->children($parent);
            $ordinal = 0;
            foreach ($siblings as $sibling) {
                if ($document->name($sibling) !== $document->name($cursor)) {
                    continue;
                }
                if ($sibling === $cursor) {
                    break;
                }
                $ordinal++;
            }
            array_unshift($segments, 'wp:' . $document->name($cursor) . '[' . $ordinal . ']');
            if ($parent === null) {
                break;
            }
            $cursor = $parent;
        }
        return implode(' > ', $segments);
    }

    /** @return array<string,mixed> */
    private static function safeFallbackInspection(BlockMarkup $document, int $index): array
    {
        try {
            return [
                'path' => self::blockPath($document, $index),
                'classes' => self::nodeClasses($document, $index),
                'markers' => [],
                'directNames' => [],
                'surface' => false,
                'paintedBox' => false,
                'boxResidue' => false,
                'padding' => null,
                'blockGap' => null,
                'imageRadius' => null,
                'bodyClasses' => [],
            ];
        } catch (\Throwable) {
            // Do not retry the operation that just failed while constructing
            // its warning. The parser's stable integer index is sufficient to
            // isolate this card for a later repair pass.
            return [
                'path' => 'block-index[' . $index . ']',
                'classes' => [],
                'markers' => [],
                'directNames' => [],
                'surface' => null,
                'paintedBox' => null,
                'boxResidue' => null,
                'padding' => null,
                'blockGap' => null,
                'imageRadius' => null,
                'bodyClasses' => [],
            ];
        }
    }

    private static function documentWarning(
        string $markup,
        string $part,
        string $style,
        string $operation,
        \Throwable $error,
    ): string {
        $why = str_replace(["\r", "\n"], ' ', $error->getMessage());
        return "file='theme/parts/{$part}.html'; block='generated section document'; authored="
            . Warnings::value($markup)
            . '; delivered=original section markup; disposition=card_style contract could not '
            . $operation . " for assigned '{$style}' (inspection_error=" . Warnings::value($why)
            . '), so pre-inspection bytes were retained for the warnings.json repair pass';
    }

    /**
     * @param array<string,mixed> $inspection
     * @param list<array{field:string,authored:mixed,required:mixed,message:string}> $issues
     */
    private static function warning(string $part, array $inspection, string $style, array $issues): string
    {
        $authored = [];
        $required = [];
        $messages = [];
        foreach ($issues as $position => $issue) {
            $field = $issue['field']
                . (array_key_exists($issue['field'], $authored) ? '#' . ($position + 1) : '');
            $authored[$field] = $issue['authored'];
            $required[$field] = $issue['required'];
            $messages[] = $issue['message'];
        }
        $authoredEvidence = implode(', ', array_map(
            static fn (string $field, mixed $value): string => $field . '=' . Warnings::value($value),
            array_keys($authored),
            array_values($authored),
        ));
        $requiredEvidence = implode(', ', array_map(
            static fn (string $field, mixed $value): string => $field . '=' . Warnings::value($value),
            array_keys($required),
            array_values($required),
        ));
        return "file='theme/parts/{$part}.html'; block='" . ($inspection['path'] ?? 'unknown card')
            . "'; authored={" . $authoredEvidence . '}; required={' . $requiredEvidence . '}'
            . "; delivered=residual card markup; disposition=assigned card_style '{$style}' could not be "
            . 'applied safely (' . implode('; ', $messages) . '); residual card structure, content, and siblings were retained '
            . 'for the warnings.json repair pass';
    }
}
