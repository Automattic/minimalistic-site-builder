<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Reconcile generated dynamic tagline blocks with the exact header contract. */
final class HeaderTagline
{
    /**
     * @param array<string,mixed> $header
     * @return array{markup:string,repairs:list<array<string,mixed>>,warnings:list<string>}
     */
    public static function ensure(string $markup, array $header, string $part = 'header'): array
    {
        $repairs = [];
        $warnings = [];
        $document = BlockMarkup::parse($markup);
        $taglines = self::blocks($document, $markup, 'site-tagline');
        $incomplete = count(array_filter(
            $document->indices(),
            static fn (int $index): bool => $document->name($index) === 'site-tagline'
                && $document->endOffset($index) === null,
        ));
        $displays = ($header['displays_tagline'] ?? false) === true;

        // An incomplete tagline can contain apparently complete descendants.
        // Treat the whole header as one transaction for either contract state;
        // editing a nested candidate would mutate the retained incomplete
        // ancestor and make paths/warnings drift on the next pass.
        if ($incomplete > 0) {
            return [
                'markup' => $markup,
                'repairs' => [],
                'warnings' => [self::incompleteWarning($part, $displays, $incomplete, $header)],
            ];
        }

        if (!$displays) {
            if ($taglines !== []) {
                $result = self::removeTaglines(
                    $markup,
                    $document,
                    $taglines,
                    $part,
                    'the authoritative header contract does not display a tagline, so the stray dynamic tagline '
                        . 'was removed without touching title, logo, navigation, or sibling blocks',
                );
                $markup = $result['markup'];
                array_push($warnings, ...$result['warnings']);
            }
            return ['markup' => $markup, 'repairs' => [], 'warnings' => $warnings];
        }

        $titles = self::blocks($document, $markup, 'site-title');
        if (count($taglines) > 1) {
            $preferred = self::preferredTagline($document, $titles[0] ?? null, $taglines);
            $duplicates = array_values(array_filter(
                $taglines,
                static fn (array $tagline): bool => $tagline['index'] !== $preferred['index'],
            ));
            $result = self::removeTaglines(
                $markup,
                $document,
                $duplicates,
                $part,
                'one contract-owned dynamic tagline remains delivered; this duplicate identity row was removed '
                    . 'at its complete block boundary',
            );
            $markup = $result['markup'];
            array_push($warnings, ...$result['warnings']);
            $document = BlockMarkup::parse($markup);
            $taglines = self::blocks($document, $markup, 'site-tagline');
            $titles = self::blocks($document, $markup, 'site-title');
            if (count($taglines) !== 1) {
                return ['markup' => $markup, 'repairs' => [], 'warnings' => $warnings];
            }
        }

        if ($titles === []) {
            if ($taglines !== []) {
                $result = self::removeTaglines(
                    $markup,
                    $document,
                    $taglines,
                    $part,
                    'the generated header had no complete wp:site-title to own this identity line, so the orphan '
                        . 'tagline was removed and the final contract must narrow its text-shape facts',
                );
                $markup = $result['markup'];
                array_push($warnings, ...$result['warnings']);
            } else {
                $warnings[] = self::missingWarning($part, $header);
            }
            return ['markup' => $markup, 'repairs' => [], 'warnings' => $warnings];
        }

        if ($taglines === []) {
            $title = $titles[0];
            $taglineMarkup = '<!-- wp:site-tagline {"fontSize":"caption"} /-->';
            $markup = substr_replace($markup, $taglineMarkup, $title['end'], 0);
            $repairs[] = [
                'code' => 'header-tagline-restored',
                'part' => $part,
                'path' => $title['path'] . ' after',
                'delivered' => 'wp:site-tagline',
                'disposition' => 'repaired',
            ];
            $document = BlockMarkup::parse($markup);
            $titles = self::blocks($document, $markup, 'site-title');
            $taglines = self::blocks($document, $markup, 'site-tagline');
        }

        if ($titles === [] || count($taglines) !== 1) {
            $warnings[] = self::missingWarning($part, $header);
            return ['markup' => $markup, 'repairs' => $repairs, 'warnings' => $warnings];
        }

        $stack = self::stackIdentity($markup, $document, $titles[0], $taglines[0], $part);
        $markup = $stack['markup'];
        array_push($repairs, ...$stack['repairs']);
        array_push($warnings, ...$stack['warnings']);
        return ['markup' => $markup, 'repairs' => $repairs, 'warnings' => $warnings];
    }

    /**
     * Keep the tagline which can already serve as the chosen title's identity
     * line. A dedicated pair is strongest, while any shared group parent can
     * be wrapped or normalized without moving a leaf across structural
     * branches. Document order remains the deterministic tie-breaker.
     *
     * @param array{index:int,start:int,end:int,path:string,markup:string}|null $title
     * @param non-empty-list<array{index:int,start:int,end:int,path:string,markup:string}> $taglines
     * @return array{index:int,start:int,end:int,path:string,markup:string}
     */
    private static function preferredTagline(
        BlockMarkup $document,
        ?array $title,
        array $taglines,
    ): array {
        // Never choose a malformed raw-payload boundary as the authoritative
        // survivor when a clean dynamic tagline is available. Otherwise the
        // clean title-paired block could be deleted while media or structural
        // HTML hidden inside the preferred boundary escapes removal review.
        $clean = array_values(array_filter(
            $taglines,
            static fn (array $tagline): bool => $document->children($tagline['index']) === []
                && !self::taglineHasRawSurvivor($document, $tagline['index']),
        ));
        $pool = $clean !== [] ? $clean : $taglines;
        $preferred = $pool[0];
        $bestScore = 0;
        if ($title === null) {
            return $preferred;
        }

        $titleParent = $document->parent($title['index']);
        foreach ($pool as $tagline) {
            $parent = $document->parent($tagline['index']);
            if ($titleParent === null
                || $parent !== $titleParent
                || $document->name($parent) !== 'group'
                || self::overlap($title, $tagline)
            ) {
                continue;
            }

            $children = $document->children($parent);
            $score = count($children) === 2
                && in_array($title['index'], $children, true)
                && in_array($tagline['index'], $children, true)
                ? 2
                : 1;
            if ($score > $bestScore) {
                $preferred = $tagline;
                $bestScore = $score;
            }
        }
        return $preferred;
    }

    /**
     * @param array{index:int,start:int,end:int,path:string,markup:string} $title
     * @param array{index:int,start:int,end:int,path:string,markup:string} $tagline
     * @return array{markup:string,repairs:list<array<string,mixed>>,warnings:list<string>}
     */
    private static function stackIdentity(
        string $markup,
        BlockMarkup $document,
        array $title,
        array $tagline,
        string $part,
    ): array {
        if (self::overlap($title, $tagline)) {
            return [
                'markup' => $markup,
                'repairs' => [],
                'warnings' => [
                    "file='theme/parts/{$part}.html'; block='header identity stack'; authored="
                        . self::value([$title['path'], $tagline['path']])
                        . '; delivered="original nested block bytes"; disposition=wp:site-title and '
                        . 'wp:site-tagline had overlapping generated boundaries, so their reading order was '
                        . 'retained and the unsafe identity stack was queued for later repair',
                ],
            ];
        }

        $parent = $document->parent($title['index']);
        $sameParent = $parent !== null && $parent === $document->parent($tagline['index']);
        if ($sameParent
            && $document->name($parent) === 'group'
            && $document->children($parent) === [$title['index'], $tagline['index']]
        ) {
            $rawShell = self::identityShellResidual($document, $parent);
            if ($rawShell !== null) {
                return [
                    'markup' => $markup,
                    'repairs' => [],
                    'warnings' => [
                        "file='theme/parts/{$part}.html'; block='"
                            . self::blockPath($document, $parent)
                            . " header identity stack'; authored="
                            . self::value([
                                'identity_blocks' => [$title['path'], $tagline['path']],
                                'raw_shell' => $rawShell,
                            ])
                            . '; delivered="original identity group bytes"; disposition=the generated '
                            . 'title/tagline group also contains visible raw or structural payload outside its '
                            . 'two dynamic blocks; the complete group was retained transactionally and its '
                            . 'text-row topology was queued for later repair',
                    ],
                ];
            }
            $attrs = $document->attrs($parent) ?? [];
            $style = $attrs['style'] ?? [];
            $spacing = is_array($style) ? ($style['spacing'] ?? []) : null;
            $layout = $attrs['layout'] ?? [];
            if (is_array($style) && is_array($spacing) && is_array($layout)) {
                $gap = $spacing['blockGap'] ?? null;
                $layoutType = $layout['type'] ?? null;
                $vertical = $layoutType === null
                    || $layoutType === 'constrained'
                    || ($layoutType === 'flex' && ($layout['orientation'] ?? null) === 'vertical');
                if (self::isZero($gap) && $vertical) {
                    return ['markup' => $markup, 'repairs' => [], 'warnings' => []];
                }
                $attrs['style']['spacing']['blockGap'] = '0';
                if (($layout['type'] ?? null) === 'flex') {
                    $attrs['layout']['orientation'] = 'vertical';
                } elseif (($layout['type'] ?? null) !== null
                    && ($layout['type'] ?? null) !== 'constrained'
                ) {
                    $attrs['layout'] = ['type' => 'constrained'];
                }
                $document->setAttrs($parent, $attrs);
                return [
                    'markup' => $document->render(),
                    'repairs' => [[
                        'code' => 'header-tagline-stack-normalized',
                        'part' => $part,
                        'path' => 'wp:group identity stack',
                        'delivered' => 'site title and tagline in one zero-gap vertical identity unit',
                        'disposition' => 'repaired',
                    ]],
                    'warnings' => [],
                ];
            }
        }

        if (!$sameParent || $parent === null || $document->name($parent) !== 'group') {
            return [
                'markup' => $markup,
                'repairs' => [],
                'warnings' => [
                    "file='theme/parts/{$part}.html'; block='header identity stack'; authored="
                        . self::value([$title['path'], $tagline['path']])
                        . '; delivered="original separate identity branches"; disposition=wp:site-title and '
                        . 'wp:site-tagline were generated under different structural parents; moving either leaf '
                        . 'could leave a decorated wrapper empty, so their bytes were retained and the topology '
                        . 'defect was queued for later repair',
                ],
            ];
        }

        $wrapper = '<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"constrained"}} -->'
            . '<div class="wp-block-group">' . $title['markup'] . $tagline['markup'] . '</div><!-- /wp:group -->';
        $first = $title['start'] < $tagline['start'] ? $title : $tagline;
        $last = $title['start'] < $tagline['start'] ? $tagline : $title;
        $markup = substr_replace($markup, '', $last['start'], $last['end'] - $last['start']);
        $markup = substr_replace($markup, $wrapper, $first['start'], $first['end'] - $first['start']);
        return [
            'markup' => $markup,
            'repairs' => [[
                'code' => 'header-tagline-stack-normalized',
                'part' => $part,
                'path' => 'header identity blocks',
                'delivered' => 'site title and tagline in one zero-gap vertical identity unit',
                'disposition' => 'repaired',
            ]],
            'warnings' => [],
        ];
    }

    /** @return list<array{index:int,start:int,end:int,path:string,markup:string}> */
    private static function blocks(BlockMarkup $document, string $markup, string $name): array
    {
        $blocks = [];
        $ordinal = 0;
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== $name) {
                continue;
            }
            $ordinal++;
            $end = $document->endOffset($index);
            if ($end === null) {
                continue;
            }
            $start = $document->openingOffset($index);
            $blocks[] = [
                'index' => $index,
                'start' => $start,
                'end' => $end,
                'path' => "wp:{$name}[{$ordinal}]",
                'markup' => substr($markup, $start, $end - $start),
            ];
        }
        return $blocks;
    }

    /**
     * @param list<array{index:int,start:int,end:int,path:string,markup:string}> $blocks
     * @return array{markup:string,warnings:list<string>}
     */
    private static function removeTaglines(
        string $markup,
        BlockMarkup $document,
        array $blocks,
        string $part,
        string $disposition,
    ): array {
        $targetIndices = array_fill_keys(array_column($blocks, 'index'), true);
        foreach ($blocks as &$block) {
            $block['raw_survivor'] = self::taglineHasRawSurvivor($document, $block['index']);
            $block['safe'] = !$block['raw_survivor']
                && !self::hasNonTargetDescendant($document, $block['index'], $targetIndices);
        }
        unset($block);
        // Nested generated candidates are one transaction. If any member of
        // that containment component owns surviving content, retain ancestors
        // as well as descendants; otherwise a seemingly safe outer tagline
        // could erase the unsafe inner payload at its wider boundary.
        do {
            $changed = false;
            $unsafe = array_column(array_values(array_filter(
                $blocks,
                static fn (array $block): bool => !$block['safe'],
            )), 'index');
            foreach ($blocks as &$block) {
                if (!$block['safe']) {
                    continue;
                }
                foreach ($unsafe as $candidate) {
                    if (self::isDescendantOf($document, $block['index'], $candidate)
                        || self::isDescendantOf($document, $candidate, $block['index'])
                    ) {
                        $block['safe'] = false;
                        $changed = true;
                        break;
                    }
                }
            }
            unset($block);
        } while ($changed);

        $safeIndices = array_fill_keys(array_column(array_values(array_filter(
            $blocks,
            static fn (array $block): bool => $block['safe'],
        )), 'index'), true);
        $spans = [];
        $wrapperRemovals = [];
        $removalDispositions = [];
        $memo = [];
        foreach ($blocks as $block) {
            if (!$block['safe']) {
                continue;
            }
            $wrapper = self::dedicatedRemovalWrapper(
                $document,
                $markup,
                $block['index'],
                $safeIndices,
                $memo,
            );
            $target = $wrapper ?? $block['index'];
            $end = $document->endOffset($target);
            if ($end === null) {
                $target = $block['index'];
                $end = $block['end'];
            }
            $spans[] = [
                'index' => $target,
                'start' => $document->openingOffset($target),
                'end' => $end,
                'path' => $block['path'],
                'markup' => $block['markup'],
            ];
            if ($wrapper !== null && !isset($wrapperRemovals[$wrapper])) {
                $wrapperStart = $document->openingOffset($wrapper);
                $wrapperRemovals[$wrapper] = [
                    'path' => self::blockPath($document, $wrapper),
                    'markup' => substr($markup, $wrapperStart, $end - $wrapperStart),
                ];
            }
            $removalDispositions[$block['index']] = $disposition . ($wrapper !== null
                ? '; its now-empty dedicated group wrapper was removed in the same transaction'
                : '');
        }
        $ranges = self::outermost($spans);
        $markup = self::remove($markup, $ranges);

        $deliveredPaths = self::pathsByOffset(BlockMarkup::parse($markup), 'site-tagline');
        $warnings = [];
        foreach ($blocks as $block) {
            if ($block['safe']) {
                $warnings[] = self::removalWarning(
                    $part,
                    $block,
                    $removalDispositions[$block['index']],
                );
                continue;
            }
            $offset = self::offsetAfterRemovals($block['start'], $ranges);
            $path = $deliveredPaths[$offset] ?? $block['path'];
            $warnings[] = "file='theme/parts/{$part}.html'; block='{$path}'; authored="
                . self::value($block['markup'])
                . '; delivered=' . self::value($block['markup'])
                . '; disposition=the generated tagline boundary contains title, logo, navigation, visible '
                . 'raw/non-block payload, or other content selected to survive; the whole boundary was retained '
                . 'transactionally and queued for later repair';
        }
        foreach ($wrapperRemovals as $wrapper) {
            $warnings[] = "file='theme/parts/{$part}.html'; block='{$wrapper['path']}'; authored="
                . self::value($wrapper['markup'])
                . '; delivered=removed; disposition=the dedicated generated wp:group became empty after its '
                . 'tagline child was removed in the same transaction; its complete painted/layout boundary was '
                . 'removed so it could not leave dead header UI, while sibling blocks were preserved';
        }
        return ['markup' => $markup, 'warnings' => $warnings];
    }

    /** @param array<int,bool> $targetIndices */
    private static function hasNonTargetDescendant(
        BlockMarkup $document,
        int $candidate,
        array $targetIndices,
    ): bool {
        foreach ($document->indices() as $index) {
            if ($index === $candidate || !self::isDescendantOf($document, $index, $candidate)) {
                continue;
            }
            if (!isset($targetIndices[$index])) {
                return true;
            }
        }
        return false;
    }

    /** Inline phrasing tags belong to the tagline leaf rather than a raw survivor. */
    private const INLINE_TAGS = [
        'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'del', 'em', 'i',
        'ins', 'kbd', 'mark', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'small',
        'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr',
    ];

    private static function taglineHasRawSurvivor(BlockMarkup $document, int $tagline): bool
    {
        if ($document->isVoid($tagline)) {
            return false;
        }
        $shell = self::shellWithoutChildBlocks($document, $tagline);
        if ($shell === null) {
            return true;
        }
        $shell = preg_replace('/<!--(?!\s*\/?wp:).*?-->/s', '', $shell) ?? $shell;
        if (trim($shell) === '') {
            return false;
        }
        if (preg_match('/<\s*\/?\s*[a-z][a-z0-9-]*\b/i', $shell) !== 1) {
            return false;
        }
        if (preg_match(
            '/\A\s*<(?<root>p|div|span)\b[^>]*>(?<body>.*)<\/\k<root>>\s*\z/is',
            $shell,
            $match,
        ) !== 1) {
            return true;
        }
        return self::hasNonTextPayload((string) ($match['body'] ?? ''));
    }

    private static function hasNonTextPayload(string $html): bool
    {
        if (!preg_match_all('/<\s*\/?\s*([a-z][a-z0-9-]*)\b[^>]*>/i', $html, $tags)) {
            return false;
        }
        foreach ($tags[1] as $tag) {
            if (!in_array(strtolower($tag), self::INLINE_TAGS, true)) {
                return true;
            }
        }
        return false;
    }

    private static function shellWithoutChildBlocks(BlockMarkup $document, int $index): ?string
    {
        $innerStart = $document->openingOffset($index) + $document->openingLength($index);
        $shell = $document->innerHtml($index);
        $children = $document->children($index);
        for ($position = count($children) - 1; $position >= 0; $position--) {
            $child = $children[$position];
            $end = $document->endOffset($child);
            if ($end === null) {
                return null;
            }
            $start = $document->openingOffset($child);
            $shell = substr_replace($shell, '', $start - $innerStart, $end - $start);
        }
        return $shell;
    }

    /**
     * Return raw/structural payload outside a group's parsed children.
     *
     * The saved-HTML element which owns the block children is structural and
     * expected. Any additional text or element would make a nominal two-row
     * identity group render more than the contract's title and tagline.
     */
    private static function identityShellResidual(BlockMarkup $document, int $group): ?string
    {
        $shell = self::shellWithoutChildBlocks($document, $group);
        if ($shell === null) {
            return 'unreadable generated group shell';
        }
        $visible = preg_replace('/<!--(?!\s*\/?wp:).*?-->/s', '', $shell) ?? $shell;
        if (trim($visible) === '') {
            return null;
        }
        if (preg_match(
            '~\A\s*<(?<tag>div|section|article|main|aside|header|footer|nav)\b[^>]*>\s*</\k<tag>>\s*\z~is',
            $visible,
        ) === 1) {
            return null;
        }
        return trim($shell);
    }

    /** A stable one-based path for a parsed block in the authored artifact. */
    private static function blockPath(BlockMarkup $document, int $index): string
    {
        $name = $document->name($index);
        $ordinal = 0;
        foreach ($document->indices() as $candidate) {
            if ($document->name($candidate) === $name) {
                $ordinal++;
            }
            if ($candidate === $index) {
                break;
            }
        }
        return "wp:{$name}[{$ordinal}]";
    }

    /** @return array<int,string> opening byte offset to delivered block path */
    private static function pathsByOffset(BlockMarkup $document, string $name): array
    {
        $paths = [];
        $ordinal = 0;
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== $name) {
                continue;
            }
            $ordinal++;
            $paths[$document->openingOffset($index)] = "wp:{$name}[{$ordinal}]";
        }
        return $paths;
    }

    /** @param list<array{start:int,end:int}> $removals */
    private static function offsetAfterRemovals(int $offset, array $removals): int
    {
        $shift = 0;
        foreach ($removals as $removal) {
            if ($removal['end'] > $offset) {
                break;
            }
            $shift += $removal['end'] - $removal['start'];
        }
        return $offset - $shift;
    }

    private static function isDescendantOf(BlockMarkup $document, int $index, int $ancestor): bool
    {
        for ($parent = $document->parent($index); $parent !== null; $parent = $document->parent($parent)) {
            if ($parent === $ancestor) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,bool> $targetIndices
     * @param array<int,bool> $memo
     */
    private static function dedicatedRemovalWrapper(
        BlockMarkup $document,
        string $markup,
        int $candidate,
        array $targetIndices,
        array &$memo,
    ): ?int {
        $wrapper = null;
        $top = $document->topLevel();
        for ($parent = $document->parent($candidate); $parent !== null; $parent = $document->parent($parent)) {
            $classes = ($document->attrs($parent) ?? [])['className'] ?? '';
            $objectiveRoot = $parent === $top
                || (is_string($classes) && preg_match('/(?:^|\s)header-archetype--/', $classes) === 1);
            if ($objectiveRoot
                || $document->name($parent) !== 'group'
                || !self::isDedicatedWrapper($document, $markup, $parent, $targetIndices, $memo)
            ) {
                break;
            }
            $wrapper = $parent;
        }
        return $wrapper;
    }

    /**
     * @param array<int,bool> $targetIndices
     * @param array<int,bool> $memo
     */
    private static function isDedicatedWrapper(
        BlockMarkup $document,
        string $markup,
        int $group,
        array $targetIndices,
        array &$memo,
    ): bool {
        if (array_key_exists($group, $memo)) {
            return $memo[$group];
        }
        $children = $document->children($group);
        if ($document->endOffset($group) === null || $children === []) {
            return $memo[$group] = false;
        }
        foreach ($children as $child) {
            if (isset($targetIndices[$child])) {
                continue;
            }
            if ($document->name($child) === 'group'
                && self::isDedicatedWrapper($document, $markup, $child, $targetIndices, $memo)
            ) {
                continue;
            }
            return $memo[$group] = false;
        }

        $innerStart = $document->openingOffset($group) + $document->openingLength($group);
        $shell = $document->innerHtml($group);
        for ($position = count($children) - 1; $position >= 0; $position--) {
            $child = $children[$position];
            $end = $document->endOffset($child);
            if ($end === null) {
                return $memo[$group] = false;
            }
            $shell = substr_replace(
                $shell,
                '',
                $document->openingOffset($child) - $innerStart,
                $end - $document->openingOffset($child),
            );
        }
        $shell = (string) preg_replace('/<!--.*?-->/s', '', $shell);
        return $memo[$group] = preg_match(
            '~\A\s*<(?<tag>div|section|article|aside|nav)\b[^>]*>\s*</\k<tag>>\s*\z~is',
            $shell,
        ) === 1;
    }

    /**
     * @param list<array{index:int,start:int,end:int,path:string,markup:string}> $blocks
     * @return list<array{index:int,start:int,end:int,path:string,markup:string}>
     */
    private static function outermost(array $blocks): array
    {
        usort($blocks, static function (array $left, array $right): int {
            $start = $left['start'] <=> $right['start'];
            return $start !== 0 ? $start : $right['end'] <=> $left['end'];
        });
        $out = [];
        foreach ($blocks as $block) {
            $last = array_key_last($out);
            if ($last === null || $block['start'] >= $out[$last]['end']) {
                $out[] = $block;
                continue;
            }
            if ($block['end'] > $out[$last]['end']) {
                $out[$last]['end'] = $block['end'];
                $out[$last]['markup'] = '';
            }
        }
        return $out;
    }

    /** @param list<array{start:int,end:int}> $blocks */
    private static function remove(string $markup, array $blocks): string
    {
        usort($blocks, static fn (array $left, array $right): int => $right['start'] <=> $left['start']);
        foreach ($blocks as $block) {
            $markup = substr_replace($markup, '', $block['start'], $block['end'] - $block['start']);
        }
        return $markup;
    }

    /** @param array{path:string,markup:string} $block */
    private static function removalWarning(string $part, array $block, string $disposition): string
    {
        return "file='theme/parts/{$part}.html'; block='{$block['path']}'; authored="
            . self::value($block['markup'] !== '' ? $block['markup'] : 'overlapping wp:site-tagline block(s)')
            . "; delivered=removed; disposition={$disposition}";
    }

    /** @param array<string,mixed> $header */
    private static function incompleteWarning(string $part, bool $displays, int $count, array $header): string
    {
        $authored = $displays ? ($header['tagline_text'] ?? 'contract-owned site tagline') : $count;
        return "file='theme/parts/{$part}.html'; block='wp:site-tagline incomplete boundary'; authored="
            . self::value($authored)
            . '; delivered="original header bytes"; disposition=' . ($displays
                ? 'the generated tagline boundary was not structurally complete, so synthesis and deduplication '
                    . 'were abandoned and the final contract must narrow the undelivered text row'
                : 'the contract forbids a tagline, but its incomplete generated boundary could not be isolated; '
                    . 'the original header was retained and the residual block was queued for later repair');
    }

    /** @param array<string,mixed> $header */
    private static function missingWarning(string $part, array $header): string
    {
        return "file='theme/parts/{$part}.html'; block='wp:site-tagline'; authored="
            . self::value($header['tagline_text'] ?? 'contract-owned site tagline')
            . '; delivered=removed; disposition=the generated header had no complete wp:site-title sibling '
            . 'that could safely anchor the missing contract-owned tagline; original header bytes were retained';
    }

    /** @param array{start:int,end:int} $left @param array{start:int,end:int} $right */
    private static function overlap(array $left, array $right): bool
    {
        return $left['start'] < $right['end'] && $right['start'] < $left['end'];
    }

    private static function isZero(mixed $value): bool
    {
        return $value === 0 || (is_string($value) && preg_match('/^0(?:[a-z%]+)?$/i', trim($value)) === 1);
    }

    private static function value(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
