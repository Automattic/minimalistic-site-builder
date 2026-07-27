<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Recover one standalone Gutenberg block document from model wrapper text.
 *
 * Complete blocks use parser-recorded spans; credible truncated containers
 * retain their whole tail for MarkupSalvage. Nested children are never
 * promoted past a complete or credible outer frame, and separate block runs
 * are rejected as ambiguous instead of guessed between.
 */
final class BlockDocumentRecovery
{
    /**
     * Blocks whose saved HTML is only a structural shell around InnerBlocks.
     * Other blocks legitimately mix owned text with children (quote cites,
     * details summaries, nested list-item text), so prose-like edge checks
     * must never be applied to every parent generically.
     */
    private const BLOCK_ONLY_CONTAINERS = [
        'group', 'columns', 'column', 'buttons',
    ];

    /** Elements whose bodies are content, never structural wrapper markup. */
    private const NON_WRAPPER_ELEMENTS = [
        'script', 'style', 'textarea', 'title', 'xmp',
        'iframe', 'object', 'applet', 'noembed', 'noframes', 'noscript',
        'template', 'code', 'pre', 'plaintext',
    ];

    /** @param list<string> $notes out-param for recoveries worth reporting */
    public static function recover(string $text, array &$notes = []): string
    {
        if ($text === '') {
            throw new \RuntimeException('is not block markup');
        }

        $delimiterView = HtmlBlockContext::delimiterView($text);
        $doc = BlockMarkup::parse($text, $delimiterView);
        if ($doc->indices() === []) {
            throw new \RuntimeException('is not block markup');
        }

        /** @var array<int,array{start:int,end:int}> $closed */
        $closed = [];
        foreach ($doc->indices() as $idx) {
            $end = $doc->endOffset($idx);
            if ($end !== null) {
                $closed[$idx] = [
                    'start' => $doc->openingOffset($idx),
                    'end'   => $end,
                ];
            }
        }

        // An unsafe outer frame blocks a healthy child from being promoted.
        // It becomes a salvage candidate only when it is still open at EOF;
        // crossed or otherwise malformed frames remain blockers and fail.
        $blocking = [];
        $salvageable = [];
        foreach ($doc->indices() as $idx) {
            if ($doc->endOffset($idx) !== null
                || !self::isPotentialOuterFrame($doc, $text, $idx)
            ) {
                continue;
            }
            $blocking[$idx] = true;
            if (self::hasCleanInterChildGaps($doc, $text, $idx)) {
                $salvageable[$idx] = true;
            }
        }

        // A complete container with narrative/fences anywhere in its direct
        // child zone is not a safe document boundary either. Keep it as an
        // ancestor blocker, but do not publish the contaminated outer frame.
        $contaminatedClosed = [];
        $contaminated = [];
        foreach (array_keys($closed) as $idx) {
            if (self::isBlockOnlyContainer($doc, $idx)
                && $doc->children($idx) !== []
                && !self::hasCleanCompleteChildZone($doc, $text, $idx)
            ) {
                $contaminatedClosed[$idx] = true;
                $blocking[$idx] = true;
                $contaminated[] = "wp:{$doc->name($idx)} at offset {$doc->openingOffset($idx)}";
            }
        }

        /** @var list<array{start:int,end:int,complete:bool}> $components */
        $components = [];
        foreach ($closed as $idx => $span) {
            if (array_key_exists($idx, $contaminatedClosed)
                || self::hasAncestorInSet($doc, $idx, $closed)
                || self::hasAncestorInSet($doc, $idx, $blocking)
            ) {
                continue;
            }
            $span['complete'] = true;
            $components[] = $span;
        }
        foreach ($doc->unclosedIndices() as $idx) {
            if (!array_key_exists($idx, $salvageable)
                || !self::isLineStart($text, $doc->openingOffset($idx))
                || self::hasAncestorInSet($doc, $idx, $blocking)
            ) {
                continue;
            }
            $components[] = [
                'start'    => $doc->openingOffset($idx),
                'end'      => strlen($text),
                'complete' => false,
            ];
        }

        // A model can finish one root and hit EOF while beginning the next.
        // Keep an adjacent, top-level open tail with no complete child in the
        // run so MarkupSalvage can drop it. It is not itself a publishable
        // candidate, and prose separating it from a prior root will still
        // create a second (ambiguous) run.
        foreach ($doc->unclosedIndices() as $idx) {
            if ($doc->parent($idx) !== null
                || array_key_exists($idx, $salvageable)
                || self::hasCompleteDirectChild($doc, $idx)
                // A backticked lone opener is Markdown for "here is an
                // example". Admitting it here made it a second run, so the
                // exemption below at the malformed/unclosed checks never ran.
                || self::isInlineCodeDelimiter($doc, $text, $idx)
            ) {
                continue;
            }
            $components[] = [
                'start'    => $doc->openingOffset($idx),
                'end'      => strlen($text),
                'complete' => false,
            ];
        }

        // The same applies when EOF cuts the next opening delimiter itself,
        // before BlockMarkup can create a node for it.
        $dangling = self::danglingBlockDelimiterOffset($delimiterView);
        if ($dangling !== null) {
            $components[] = [
                'start'    => $dangling,
                'end'      => strlen($text),
                'complete' => false,
            ];
        }

        usort($components, static function (array $a, array $b): int {
            return $a['start'] <=> $b['start'] ?: $b['end'] <=> $a['end'];
        });
        ['runs' => $runs, 'skipped' => $skipped] = self::documentRuns($text, $components);
        if ($runs === []) {
            // Name what disqualified the candidates. Without it the build log
            // says only that a section failed, with nothing to grep for.
            throw new \RuntimeException(
                'does not contain a standalone block document'
                . ($contaminated !== []
                    ? ' (non-block content in the child zone of '
                        . implode(', ', array_slice($contaminated, 0, 3)) . ')'
                    : ' (response begins: ' . self::snippet($text, 0, strlen($text)) . ')')
            );
        }
        if (count($runs) !== 1) {
            throw new \RuntimeException(
                'contains ambiguous block markup (' . count($runs) . ' block documents; '
                . 'the second begins at offset ' . $runs[1]['start'] . ': '
                . self::snippet($text, $runs[1]['start'], $runs[1]['end']) . ')'
            );
        }

        // Components before the first line-standing root are examples and are
        // dropped by design. A *complete* one may still be real content the
        // model prefixed with a stray character, and a trailing extra root
        // throws where this one vanishes — so at least say it happened.
        foreach ($skipped as $component) {
            if ($component['complete']
                && !self::isBacktickWrapped($text, $component['start'], $component['end'])
            ) {
                $notes[] = 'dropped a complete block before the recovered document: '
                    . self::snippet($text, $component['start'], $component['end']);
            }
        }

        $run = $runs[0];

        // A line-standing malformed/open frame before the selected run is not
        // harmless prose: it may be the intended root. Refuse to discard it
        // and silently publish one of its healthy descendants.
        foreach ($doc->malformedDelimiterOffsets() as $offset) {
            if ($offset < $run['start'] || $offset >= $run['end']) {
                throw new \RuntimeException(
                    'contains ambiguous or malformed block markup outside the recovered document'
                );
            }
        }

        foreach ($doc->mismatchedDelimiterOffsets() as $offset) {
            $outside = $offset < $run['start'] || $offset >= $run['end'];
            if ($outside && self::isLineStart($text, $offset)) {
                throw new \RuntimeException(
                    'contains ambiguous or malformed block markup outside the recovered document'
                );
            }
        }

        foreach ($doc->indices() as $idx) {
            if ($doc->endOffset($idx) !== null) {
                continue;
            }
            $offset = $doc->openingOffset($idx);
            $outside = $offset < $run['start'] || $offset >= $run['end'];
            if ($outside && !self::isInlineCodeDelimiter($doc, $text, $idx)) {
                throw new \RuntimeException(
                    'contains ambiguous or malformed block markup outside the recovered document'
                );
            }
        }

        return substr($text, $run['start'], $run['end'] - $run['start']);
    }

    /** Require a document to consist only of complete top-level blocks. */
    public static function assertComplete(string $markup): void
    {
        $delimiterView = HtmlBlockContext::delimiterView($markup);
        $hidden = HtmlBlockContext::hiddenDelimiterOffsets($markup, $delimiterView);
        if ($hidden !== []) {
            throw new \RuntimeException(
                'contains block delimiters hidden inside HTML context (offset '
                . $hidden[0] . ': ' . self::snippet($markup, $hidden[0], strlen($markup)) . ')'
            );
        }

        $doc = BlockMarkup::parse($markup, $delimiterView);
        if ($doc->indices() === []
            || $doc->unclosedIndices() !== []
            || $doc->hasMismatchedDelimiters()
            || $doc->hasMalformedDelimiters()
        ) {
            throw new \RuntimeException('is not a complete standalone block document');
        }

        $roots = array_values(array_filter(
            $doc->indices(),
            static fn (int $idx): bool => $doc->parent($idx) === null,
        ));
        if ($roots === []) {
            throw new \RuntimeException('is not a complete standalone block document');
        }

        foreach ($doc->indices() as $idx) {
            if (self::isBlockOnlyContainer($doc, $idx)
                && $doc->children($idx) !== []
                && !self::hasCleanCompleteChildZone($doc, $markup, $idx)
            ) {
                throw new \RuntimeException(
                    "contains non-block content in the child zone of wp:{$doc->name($idx)}"
                    . ' at offset ' . $doc->openingOffset($idx)
                );
            }
        }

        $cursor = 0;
        foreach ($roots as $idx) {
            $start = $doc->openingOffset($idx);
            $end = $doc->endOffset($idx);
            if ($end === null
                || !HtmlBlockContext::isInsignificant(
                    substr($markup, $cursor, $start - $cursor)
                )
            ) {
                throw new \RuntimeException('has content outside its top-level blocks');
            }
            $cursor = $end;
        }
        if (!HtmlBlockContext::isInsignificant(substr($markup, $cursor))) {
            throw new \RuntimeException('has content outside its top-level blocks');
        }
    }

    /**
     * A possible outer frame either has a real saved-HTML container prefix,
     * or is a naked dynamic wrapper at the very start of the response. Text,
     * fences, and tags mentioned only in comments/style bodies are not a
     * container signal.
     */
    private static function isPotentialOuterFrame(BlockMarkup $doc, string $text, int $idx): bool
    {
        $own = $doc->ownHtml($idx);
        if (HtmlBlockContext::isWhitespace($own)) {
            return $doc->children($idx) !== []
                && HtmlBlockContext::isInsignificant(
                    substr($text, 0, $doc->openingOffset($idx))
                );
        }
        return self::isCleanContainerPrefix($own);
    }

    private static function isBlockOnlyContainer(BlockMarkup $doc, int $idx): bool
    {
        return in_array($doc->name($idx), self::BLOCK_ONLY_CONTAINERS, true);
    }

    /**
     * Validate a closed parent from its opening HTML through its closing HTML.
     * Static parents must form one balanced HTML wrapper around their direct
     * children; dynamic parents may own only HTML whitespace.
     */
    private static function hasCleanCompleteChildZone(
        BlockMarkup $doc,
        string $text,
        int $idx
    ): bool {
        $children = $doc->children($idx);
        if ($children === []) {
            return true;
        }

        $prefix = $doc->ownHtml($idx);
        $dynamic = HtmlBlockContext::isWhitespace($prefix);
        $container = !$dynamic && self::isCleanContainerPrefix($prefix);
        if (!$dynamic && !$container) {
            return false;
        }

        $stack = $dynamic
            ? []
            : self::advanceCleanWrapperStack($prefix, [], false);
        if (!$dynamic && ($stack === null || $stack === [])) {
            return false;
        }
        $previousEnd = null;
        foreach ($children as $position => $child) {
            $childEnd = $doc->endOffset($child);
            if ($childEnd === null) {
                return false;
            }

            if ($position > 0) {
                $start = $doc->openingOffset($child);
                $gap = substr($text, (int) $previousEnd, $start - (int) $previousEnd);
                if ($dynamic) {
                    if (!HtmlBlockContext::isInsignificant($gap)) {
                        return false;
                    }
                } else {
                    $stack = self::advanceCleanWrapperStack($gap, $stack, false);
                    if ($stack === null || $stack === []) {
                        return false;
                    }
                }
            }

            $previousEnd = $childEnd;
        }

        $innerEnd = $doc->innerEndOffset($idx);
        if ($previousEnd === null || $previousEnd > $innerEnd) {
            return false;
        }
        $suffix = substr($text, $previousEnd, $innerEnd - $previousEnd);
        if ($dynamic) {
            return HtmlBlockContext::isInsignificant($suffix);
        }
        $stack = self::advanceCleanWrapperStack($suffix, $stack, true);
        return $stack === [];
    }

    /**
     * Ensure prose/fences cannot sit between the complete children of an open
     * frame. Its suffix may be truncated and is intentionally left for
     * MarkupSalvage to discard.
     */
    private static function hasCleanInterChildGaps(BlockMarkup $doc, string $text, int $idx): bool
    {
        $children = $doc->children($idx);
        if (count($children) < 2) {
            return true;
        }

        $prefix = $doc->ownHtml($idx);
        $dynamic = HtmlBlockContext::isWhitespace($prefix);
        $stack = $dynamic
            ? []
            : self::advanceCleanWrapperStack($prefix, [], false);
        if (!$dynamic && ($stack === null || $stack === [])) {
            return false;
        }
        $previousEnd = $doc->endOffset($children[0]);
        if ($previousEnd === null) {
            return false;
        }

        for ($i = 1; $i < count($children); $i++) {
            $start = $doc->openingOffset($children[$i]);
            $gap = substr($text, $previousEnd, $start - $previousEnd);
            if ($dynamic) {
                if (!HtmlBlockContext::isInsignificant($gap)) {
                    return false;
                }
            } else {
                $stack = self::advanceCleanWrapperStack($gap, $stack, false);
                if ($stack === null || $stack === []) {
                    return false;
                }
            }

            $previousEnd = $doc->endOffset($children[$i]);
            if ($previousEnd === null && $i !== count($children) - 1) {
                return false;
            }
        }
        return true;
    }

    private static function isCleanContainerPrefix(string $html): bool
    {
        $stack = self::advanceCleanWrapperStack($html, [], false);
        return $stack !== null && $stack !== [];
    }

    /**
     * @param list<string> $stack
     * @return list<string>|null
     */
    private static function advanceCleanWrapperStack(
        string $html,
        array $stack,
        bool $allowRootClose
    ): ?array {
        if (self::hasNonWrapperElement($html)) {
            return null;
        }
        return MarkupSalvage::advanceStrictWrapperStack(
            $html,
            $stack,
            $allowRootClose,
        );
    }

    private static function hasNonWrapperElement(string $html): bool
    {
        return HtmlBlockContext::removeTags($html, self::NON_WRAPPER_ELEMENTS) !== $html;
    }

    /** @param array<int,mixed> $set */
    private static function hasAncestorInSet(BlockMarkup $doc, int $idx, array $set): bool
    {
        for ($parent = $doc->parent($idx); $parent !== null; $parent = $doc->parent($parent)) {
            if (array_key_exists($parent, $set)) {
                return true;
            }
        }
        return false;
    }

    private static function hasCompleteDirectChild(BlockMarkup $doc, int $idx): bool
    {
        foreach ($doc->children($idx) as $child) {
            if ($doc->endOffset($child) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * A lone opener shown as Markdown inline code is an example, not an
     * intended outer frame. Plain inline openers remain suspicious: labels
     * such as "Actual: <!-- wp:group -->" must not let a child be promoted.
     */
    private static function isInlineCodeDelimiter(
        BlockMarkup $doc,
        string $text,
        int $idx
    ): bool {
        $start = $doc->openingOffset($idx);
        $end = $start + $doc->openingLength($idx);
        return $start > 0
            && $end < strlen($text)
            && $text[$start - 1] === '`'
            && $text[$end] === '`';
    }

    /** Offset of a final Gutenberg opening comment cut before `-->`. */
    private static function danglingBlockDelimiterOffset(string $delimiterView): ?int
    {
        $offset = strrpos($delimiterView, '<!--');
        if ($offset === false || str_contains(substr($delimiterView, $offset), '-->')) {
            return null;
        }
        return preg_match(
            '/\A<!--[\x09\x0A\x0C\x0D\x20]*wp:/',
            substr($delimiterView, $offset),
        ) === 1 ? $offset : null;
    }

    /**
     * Coalesce adjacent roots separated only by ASCII whitespace. Components
     * before the first line-standing root are inline examples and ignored.
     * Once a document starts, every later non-adjacent block is a second run,
     * even when prose shares its line; silently dropping it could select the
     * wrong answer.
     *
     * @param list<array{start:int,end:int,complete:bool}> $components
     * @return array{runs:list<array{start:int,end:int,complete:bool}>,
     *               skipped:list<array{start:int,end:int,complete:bool}>}
     */
    private static function documentRuns(string $text, array $components): array
    {
        $runs = [];
        $skipped = [];
        $current = null;
        foreach ($components as $component) {
            if ($current === null) {
                if (self::isLineStart($text, $component['start'])) {
                    $current = $component;
                } else {
                    $skipped[] = $component;
                }
                continue;
            }

            if ($component['start'] < $current['end']) {
                $current['end'] = max($current['end'], $component['end']);
                continue;
            }

            $gap = substr($text, $current['end'], $component['start'] - $current['end']);
            if (HtmlBlockContext::isInsignificant($gap)) {
                $current['end'] = $component['end'];
                continue;
            }

            $runs[] = $current;
            $current = $component;
        }
        if ($current !== null) {
            $runs[] = $current;
        }
        return ['runs' => $runs, 'skipped' => $skipped];
    }

    /** A one-line, length-capped excerpt of a span, for a log note. */
    private static function snippet(string $text, int $start, int $end): string
    {
        $excerpt = (string) preg_replace(
            '/\s+/',
            ' ',
            substr($text, $start, min($end - $start, 120)),
        );
        return $end - $start > 120 ? $excerpt . '…' : $excerpt;
    }

    /** Whether a span sits inside a pair of Markdown inline-code backticks. */
    private static function isBacktickWrapped(string $text, int $start, int $end): bool
    {
        return $start > 0
            && $end < strlen($text)
            && $text[$start - 1] === '`'
            && $text[$end] === '`';
    }

    /** Whether the offset is preceded only by whitespace on its logical line. */
    private static function isLineStart(string $text, int $offset): bool
    {
        if ($offset === 0) {
            return true;
        }
        $before = substr($text, 0, $offset);
        $lf = strrpos($before, "\n");
        $cr = strrpos($before, "\r");
        $lineBreak = max($lf === false ? -1 : $lf, $cr === false ? -1 : $cr);
        return HtmlBlockContext::isInsignificant(substr($before, $lineBreak + 1));
    }
}
