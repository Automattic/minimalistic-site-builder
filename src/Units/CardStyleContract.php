<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\CardStyle;
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
 * drift is delivered byte-for-byte and reported for the later repair pass.
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

    /**
     * @param null|callable(string):BlockMarkup $parser injectable only so the
     *        otherwise-total BlockMarkup parser's operational failure boundary
     *        remains regression-testable
     * @return array{markup:string,repairs:list<array<string,mixed>>,warnings:list<string>}
     */
    public static function enforce(
        string $markup,
        string $assignedStyle,
        string $part,
        ?callable $parser = null,
    ): array {
        if (!in_array($assignedStyle, self::STYLES, true)) {
            throw new \InvalidArgumentException('assigned card style must be normalized');
        }

        $repairs = [];
        $warnings = [];
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

        usort(
            $candidates,
            static fn (int $left, int $right): int =>
                $document->openingOffset($right) <=> $document->openingOffset($left),
        );
        foreach ($candidates as $index) {
            try {
                $inspection = self::inspect($document, $index);
                $issues = self::anatomyIssues($inspection, $assignedStyle);
                if ($issues !== []) {
                    $warnings[] = self::warning($part, $inspection, $assignedStyle, $issues);
                    continue;
                }

                $authoredHtmlClasses = self::htmlClasses($document, $index);
                $cssResetWasActive = is_array($authoredHtmlClasses)
                    && in_array('card-flush', $authoredHtmlClasses, true);
                $repair = self::classRepair($document, $inspection, $assignedStyle);
                if ($repair === null) {
                    $warnings[] = self::warning(
                        $part,
                        $inspection,
                        $assignedStyle,
                        [self::issue(
                            'saved_wrapper',
                            self::wrapperEvidence($document, $inspection),
                            'one normal non-void opening tag with a single parseable class attribute',
                            'the saved HTML wrapper could not be updated transactionally',
                        )],
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

    /** @return list<int> */
    private static function candidateIndices(BlockMarkup $document): array
    {
        $candidates = [];
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'group') {
                continue;
            }
            $classes = self::nodeClasses($document, $index);
            $explicit = self::treatmentMarkers($classes) !== []
                || in_array('card-flush', $classes, true);
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
        $candidateSet = array_fill_keys($candidates, true);

        // A misplaced outer-card marker on the text body is owned by the
        // surrounding card repair. Treating that body as a second card emits a
        // stale warning before its parent removes the marker, and can make two
        // candidate transactions contend for the same opening bytes.
        return array_values(array_filter(
            $candidates,
            static fn (int $index): bool => !self::isBodyOwnedByCandidate(
                $document,
                $index,
                $candidateSet,
            ),
        ));
    }

    /**
     * @return array{
     *   index:int,path:string,attrs:array<mixed>,classes:list<string>,markers:list<string>,
     *   direct:list<int>,directNames:list<string>,image:?int,imageAttrs:array<mixed>,imageRadius:mixed,
     *   body:?int,bodyAttrs:array<mixed>,bodyClasses:list<string>,box:bool,padding:mixed,blockGap:mixed
     * }
     */
    private static function inspect(BlockMarkup $document, int $index): array
    {
        $attrs = $document->attrs($index) ?? [];
        $direct = $document->children($index);
        $image = self::firstDirectImage($document, $index);
        $groups = array_values(array_filter(
            $direct,
            static fn (int $child): bool => $document->name($child) === 'group',
        ));
        $body = count($groups) === 1 ? $groups[0] : null;
        $bodyAttrs = $body === null ? [] : ($document->attrs($body) ?? []);
        $imageAttrs = $image === null ? [] : ($document->attrs($image) ?? []);
        $classes = self::nodeClasses($document, $index);

        return [
            'index' => $index,
            'path' => self::blockPath($document, $index),
            'attrs' => $attrs,
            'classes' => $classes,
            'markers' => self::treatmentMarkers($classes),
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
            'bodyClasses' => $body === null ? [] : self::nodeClasses($document, $body),
            'box' => self::hasVisualBox($attrs),
            'padding' => self::nested($attrs, ['style', 'spacing', 'padding']),
            'blockGap' => self::nested($attrs, ['style', 'spacing', 'blockGap']),
        ];
    }

    /**
     * @param array<string,mixed> $inspection
     * @return list<array{field:string,authored:mixed,required:mixed,message:string}>
     */
    private static function anatomyIssues(array $inspection, string $style): array
    {
        $issues = [];
        $direct = $inspection['direct'];
        $image = $inspection['image'];
        $directImageCount = count(array_filter(
            $inspection['directNames'],
            static fn (string $name): bool => $name === 'wp:image',
        ));
        if ($image === null) {
            return [self::issue(
                'direct_children',
                $inspection['directNames'],
                'a direct wp:image child',
                'the marked card has no direct wp:image child',
            )];
        }
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

        if (in_array($style, ['flush', 'overlap'], true)) {
            if (!$inspection['box']) {
                $issues[] = self::issue(
                    'outer_box',
                    self::boxEvidence($inspection['attrs']),
                    'background, border, radius, gradient, or shadow box',
                    'the outer card has no visual box',
                );
            }
            if (!self::isZero($inspection['blockGap'])) {
                $issues[] = self::issue(
                    'outer_block_gap',
                    $inspection['blockGap'],
                    '0',
                    'outer card blockGap is not zero',
                );
            }
            if (count($direct) !== 2 || $inspection['body'] === null) {
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
        }

        if ($style === 'overlap' && $inspection['body'] !== null) {
            if (!self::hasBackground($inspection['bodyAttrs'])) {
                $issues[] = self::issue(
                    'body_background',
                    self::boxEvidence($inspection['bodyAttrs']),
                    'an explicit text-panel background',
                    'the overlap text panel has no own background',
                );
            }
            $left = self::nested($inspection['bodyAttrs'], ['style', 'spacing', 'margin', 'left']);
            $right = self::nested($inspection['bodyAttrs'], ['style', 'spacing', 'margin', 'right']);
            if ($left !== '1rem' || $right !== '1rem') {
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
            $hasBodyTop = is_array($bodyMargin)
                && array_key_exists('top', $bodyMargin)
                && !self::isAbsent($bodyMargin['top']);
            $bodyTop = $hasBodyTop ? $bodyMargin['top'] : null;
            $topConflicts = $style === 'overlap'
                ? $hasBodyTop
                : !self::isZeroOrAbsent($bodyTop);
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
                if (self::hasBackground($inspection['bodyAttrs'])) {
                    $issues[] = self::issue(
                        'body_background',
                        self::boxEvidence($inspection['bodyAttrs']),
                        'removed; only overlap uses its own text-panel background',
                        "the {$style} text body retains a stale overlap-panel background",
                    );
                }
                $left = is_array($bodyMargin) ? ($bodyMargin['left'] ?? null) : null;
                $right = is_array($bodyMargin) ? ($bodyMargin['right'] ?? null) : null;
                if (!self::isZeroOrAbsent($left) || !self::isZeroOrAbsent($right)) {
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
            if (!$inspection['box']) {
                $issues[] = self::issue(
                    'outer_box',
                    self::boxEvidence($inspection['attrs']),
                    'a visual card box',
                    'the framed card has no visual box',
                );
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
            if (!self::isZeroOrAbsent($cardRadius)) {
                if (self::isZeroOrAbsent($inspection['imageRadius'])) {
                    $issues[] = self::issue(
                        'image_radius',
                        $inspection['imageRadius'],
                        'max(card radius - uniform padding, 2px)',
                        'the rounded framed card image has no concentric inner radius',
                    );
                } else {
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
                                'units' => 'uniform px values',
                                'formula' => 'max(card radius - uniform padding, 2px)',
                            ],
                            'the framed card uses non-pixel, per-corner, or non-uniform values whose concentric radius cannot be verified safely',
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
            }
        }

        if ($style === 'borderless') {
            if ($inspection['box']) {
                $issues[] = self::issue(
                    'outer_box',
                    self::boxEvidence($inspection['attrs']),
                    'removed',
                    'the borderless card still carries a background, border, radius, gradient, or shadow',
                );
            }
            if (!self::isZeroOrAbsent($inspection['padding'])) {
                $issues[] = self::issue(
                    'outer_padding',
                    $inspection['padding'],
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
        if (in_array($style, ['flush', 'overlap'], true) && $inspection['body'] !== null) {
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
        if (in_array($style, ['flush', 'overlap'], true) && $issues !== []) {
            if (!self::isZeroOrAbsent($inspection['padding'])) {
                $issues[] = self::issue(
                    'outer_padding',
                    $inspection['padding'],
                    '0 via a safely delivered card-flush hook',
                    'outer card padding remains inset because the CSS-backed repair was unsafe',
                );
            }
            if (!self::isZeroOrAbsent($inspection['imageRadius'])) {
                $issues[] = self::issue(
                    'image_radius',
                    $inspection['imageRadius'],
                    '0 via a safely delivered card-flush hook',
                    'the direct image radius remains because the CSS-backed repair was unsafe',
                );
            }
        }

        return $issues;
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
    private static function postconditionIssues(BlockMarkup $document, int $index, string $style): array
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

        $inspection = self::inspect($document, $index);
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
            'flush' => ['card-body'],
            'overlap' => ['card-body', 'overlap-up'],
            default => [],
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
     * @param array<string,mixed> $inspection
     * @return list<array{field:string,authored:mixed,required:mixed,message:string}>
     */
    private static function cssResetEvidence(array $inspection, string $style): array
    {
        if (!in_array($style, ['flush', 'overlap'], true)) {
            return [];
        }
        $evidence = [];
        if (!self::isZeroOrAbsent($inspection['padding'])) {
            $evidence[] = self::issue(
                'outer_padding',
                $inspection['padding'],
                '0 via .card-flush padding:0!important',
                'outer card padding is neutralized by the verified behavior hook',
            );
        }
        if (!self::isZeroOrAbsent($inspection['imageRadius'])) {
            $evidence[] = self::issue(
                'image_radius',
                $inspection['imageRadius'],
                '0 via the direct .card-flush image !important reset',
                'the direct image radius is neutralized by the verified behavior hook',
            );
        }
        return $evidence;
    }

    /** @return array{field:string,authored:mixed,required:mixed,message:string} */
    private static function issue(string $field, mixed $authored, mixed $required, string $message): array
    {
        return compact('field', 'authored', 'required', 'message');
    }

    /** @param array<string,mixed> $inspection @return array<string,mixed> */
    private static function wrapperEvidence(BlockMarkup $document, array $inspection): array
    {
        $body = $inspection['body'] ?? null;
        return [
            'card' => $document->ownHtml($inspection['index']),
            'body' => is_int($body) ? $document->ownHtml($body) : null,
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
        if (in_array($style, ['flush', 'overlap'], true)) {
            $out[] = 'card-body';
            if ($style === 'overlap') {
                $out[] = 'overlap-up';
            }
        }
        return array_values(array_unique($out));
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
        if (preg_match('~/\s*>\z~', $tag) === 1
            || preg_match('~\A<(?<name>[a-z][a-z0-9:-]*)~i', $tag, $name) !== 1
            || in_array(strtolower($name['name']), [
                'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
                'link', 'meta', 'param', 'source', 'track', 'wbr',
            ], true)
        ) {
            return null;
        }
        return [
            'tag' => $tag,
            'offset' => $document->openingOffset($index)
                + $document->openingLength($index)
                + $match['tag'][1],
        ];
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

    /** @param array<int,true> $candidateSet */
    private static function isBodyOwnedByCandidate(
        BlockMarkup $document,
        int $index,
        array $candidateSet,
    ): bool {
        $parent = $document->parent($index);
        if ($parent === null
            || !isset($candidateSet[$parent])
            || $document->name($parent) !== 'group'
        ) {
            return false;
        }
        $groups = array_values(array_filter(
            $document->children($parent),
            static fn (int $child): bool => $document->name($child) === 'group',
        ));
        return $groups === [$index] && self::firstDirectImage($document, $parent) !== null;
    }

    /** @param array<mixed> $attrs */
    private static function hasVisualBox(array $attrs): bool
    {
        foreach (['backgroundColor', 'gradient', 'borderColor'] as $field) {
            if (!self::isZeroOrAbsent($attrs[$field] ?? null)) {
                return true;
            }
        }
        foreach ([
            ['style', 'color', 'background'],
            ['style', 'color', 'gradient'],
            ['style', 'border'],
            ['style', 'shadow'],
        ] as $path) {
            if (!self::isZeroOrAbsent(self::nested($attrs, $path))) {
                return true;
            }
        }
        return false;
    }

    /** @param array<mixed> $attrs @return array<string,mixed> */
    private static function boxEvidence(array $attrs): array
    {
        return array_filter([
            'backgroundColor' => $attrs['backgroundColor'] ?? null,
            'gradient' => $attrs['gradient'] ?? null,
            'borderColor' => $attrs['borderColor'] ?? null,
            'style.color.background' => self::nested($attrs, ['style', 'color', 'background']),
            'style.color.gradient' => self::nested($attrs, ['style', 'color', 'gradient']),
            'style.border' => self::nested($attrs, ['style', 'border']),
            'style.shadow' => self::nested($attrs, ['style', 'shadow']),
        ], static fn (mixed $value): bool => !self::isZeroOrAbsent($value));
    }

    /** @param array<mixed> $attrs */
    private static function hasBackground(array $attrs): bool
    {
        foreach (['backgroundColor', 'gradient'] as $field) {
            if (!self::isZeroOrAbsent($attrs[$field] ?? null)) {
                return true;
            }
        }
        return !self::isZeroOrAbsent(self::nested($attrs, ['style', 'color', 'background']))
            || !self::isZeroOrAbsent(self::nested($attrs, ['style', 'color', 'gradient']));
    }

    private static function hasAllSidePadding(mixed $padding): bool
    {
        if (!is_array($padding)) {
            return false;
        }
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if (self::isZeroOrAbsent($padding[$side] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** @return array{expected:string,matches:bool}|null */
    private static function comparableFramedRadii(
        mixed $cardRadius,
        mixed $padding,
        mixed $imageRadius,
    ): ?array {
        $card = self::pixelValue($cardRadius);
        $image = self::pixelValue($imageRadius);
        if ($card === null || $image === null || !is_array($padding)) {
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
        if (!is_string($value)
            || preg_match('/^(?<value>[0-9]+(?:\.[0-9]+)?)px$/i', trim($value), $match) !== 1
        ) {
            return null;
        }
        return (float) $match['value'];
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

    private static function isZero(mixed $value): bool
    {
        return $value === 0
            || $value === 0.0
            || (is_string($value) && preg_match('/^0(?:\.0+)?(?:px|rem|em|%)?$/i', trim($value)) === 1);
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
                'box' => false,
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
                'box' => null,
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
            . "; delivered=unchanged card markup; disposition=assigned card_style '{$style}' could not be "
            . 'applied safely (' . implode('; ', $messages) . '); the card and its sibling content were retained '
            . 'for the warnings.json repair pass';
    }
}
