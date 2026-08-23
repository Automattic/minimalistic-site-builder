<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Pure section classification and exemplar scoring.
 *
 * page markup + plan -> split by position -> label x shape -> score -> winner
 *                         | count drift
 *                         +-> anchor fallback -> no matches: skip + warn
 */
final class SectionPattern
{
    private const QUALIFIERS = [
        'section', 'grid', 'list', 'cards', 'card', 'overview', 'intro',
        'preview', 'callout', 'snapshot', 'band', 'panel', 'strip', 'row',
        'context', 'opener',
    ];

    private const SYNONYMS = [
        'call-to-action' => 'cta',
        'social-proof' => 'testimonial',
        'trust-proof' => 'testimonial',
        'page-header' => 'hero',
        'contact-form' => 'contact',
        'form' => 'contact',
    ];

    private const TRAILING_HEAD_NOUNS = [
        'hero', 'cta', 'gallery', 'contact', 'testimonial', 'faq', 'pricing', 'team',
    ];

    /** Nouns whose trailing s/ies is not an English plural. */
    private const UNCOUNTABLE_HEADS = ['news', 'series', 'species'];

    /** Past this, a model-authored type/slug is not a label any more. */
    private const MAX_LABEL_LENGTH = 64;

    /**
     * @param list<array<mixed>> $plannedSections
     * @return array{
     *     sections:list<array{index:int, markup:string, slug:string, anchor:?string}>,
     *     warnings:list<string>
     * }
     */
    public static function split(string $pageMarkup, array $plannedSections): array
    {
        $doc = BlockMarkup::parse($pageMarkup);
        $warnings = [];
        $hasStructuralDefect = $doc->hasMalformedDelimiters()
            || $doc->hasMismatchedDelimiters()
            || $doc->unclosedIndices() !== [];

        $topLevel = array_values(array_filter(
            $doc->indices(),
            static fn (int $i): bool => $doc->parent($i) === null,
        ));

        if (count($topLevel) === count($plannedSections)) {
            $sections = [];
            foreach ($plannedSections as $index => $planned) {
                $slug = is_array($planned) ? (string) ($planned['slug'] ?? '') : '';
                $blockIndex = $topLevel[$index];
                $anchor = self::anchor($doc, $blockIndex);
                $section = self::sectionSlice(
                    $doc,
                    $pageMarkup,
                    $blockIndex,
                    $index,
                    $slug,
                    $anchor,
                    $warnings,
                );
                if ($section !== null) {
                    if ($anchor !== null && $anchor !== $slug) {
                        $warnings[] = "Section anchor '{$anchor}' disagrees with positional slug '{$slug}'; kept section.";
                    }
                    $sections[] = $section;
                }
            }
            if ($hasStructuralDefect) {
                $warnings[] = 'Page block markup has a structural delimiter defect; preserved only safe sections.';
            }
            return ['sections' => $sections, 'warnings' => $warnings];
        }

        $warnings[] = sprintf(
            'Top-level block count %d disagrees with planned section count %d; using anchor fallback.',
            count($topLevel),
            count($plannedSections),
        );

        $blocksByAnchor = [];
        foreach ($topLevel as $blockIndex) {
            $anchor = self::anchor($doc, $blockIndex);
            if ($anchor !== null && !isset($blocksByAnchor[$anchor])) {
                $blocksByAnchor[$anchor] = $blockIndex;
            }
        }

        $sections = [];
        foreach ($plannedSections as $index => $planned) {
            $slug = is_array($planned) ? (string) ($planned['slug'] ?? '') : '';
            if (!isset($blocksByAnchor[$slug])) {
                $warnings[] = "Skipped section '{$slug}': count drift left no matching anchor.";
                continue;
            }
            $section = self::sectionSlice(
                $doc,
                $pageMarkup,
                $blocksByAnchor[$slug],
                $index,
                $slug,
                $slug,
                $warnings,
            );
            if ($section !== null) {
                $sections[] = $section;
            }
        }

        if ($sections === []) {
            $warnings[] = 'Skipped page: neither positional nor anchor matching yielded a section.';
        }
        if ($hasStructuralDefect) {
            $warnings[] = 'Page block markup has a structural delimiter defect; preserved only safe sections.';
        }

        return ['sections' => $sections, 'warnings' => $warnings];
    }

    public static function normalizeLabel(string $raw): string
    {
        $normalized = strtolower(trim($raw));
        $normalized = preg_replace('/[^a-z0-9_-]+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-_');
        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace('_', '-', $normalized);
        $tokens = array_values(array_filter(
            explode('-', $normalized),
            static fn (string $token): bool => $token !== '',
        ));
        $withoutQualifiers = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => !in_array($token, self::QUALIFIERS, true),
        ));
        if ($withoutQualifiers !== []) {
            $tokens = $withoutQualifiers;
        }

        $phrase = implode('-', $tokens);
        $last = $tokens[count($tokens) - 1] ?? '';
        $head = in_array($last, self::TRAILING_HEAD_NOUNS, true)
            ? $last
            : ($tokens[0] ?? '');
        if (!in_array($head, self::UNCOUNTABLE_HEADS, true)) {
            if (str_ends_with($head, 'ies') && strlen($head) >= 5) {
                $head = substr($head, 0, -3) . 'y';
            } elseif (
                preg_match('/(?:sses|xes|zes|ches|shes)$/', $head) === 1
                && strlen($head) - 2 >= 3
            ) {
                $head = substr($head, 0, -2);
            } elseif (
                str_ends_with($head, 's')
                && preg_match('/(?:ss|us|is|xs)$/', $head) !== 1
                && strlen($head) - 1 >= 3
            ) {
                $head = substr($head, 0, -1);
            }
        }

        $synonym = self::SYNONYMS[$phrase] ?? self::SYNONYMS[$head] ?? null;
        if ($synonym !== null) {
            return $synonym;
        }
        // type and slug are model-authored and unbounded, and the label
        // becomes the pattern filename. Degrade to the next rung of the
        // ladder rather than failing the write on an over-long name.
        return strlen($head) > self::MAX_LABEL_LENGTH ? '' : $head;
    }

    public static function isGenericSlug(string $slug): bool
    {
        return preg_match(
            '/^(?:section|div|nav|main|wrapper|block|col|row)-?\d*$/',
            strtolower(trim($slug)),
        ) === 1;
    }

    /**
     * Label ladder, first meaningful value wins:
     * type meaningful? -> type; else slug meaningful? -> slug;
     * else role hero/closing? -> role; else null (shape-only).
     * `content` and positional ids fall through.
     *
     * @param array<mixed> $planSection
     */
    public static function label(array $planSection, int $index, int $count): ?string
    {
        $type = (string) ($planSection['type'] ?? '');
        $typeLabel = self::normalizeLabel($type);
        if (
            $typeLabel !== ''
            && $typeLabel !== SectionRole::CONTENT
            && !self::isGenericSlug($type)
        ) {
            return $typeLabel;
        }

        $slug = (string) ($planSection['slug'] ?? '');
        $slugLabel = self::normalizeLabel($slug);
        if ($slugLabel !== '' && !self::isGenericSlug($slug)) {
            return $slugLabel;
        }

        $role = SectionRole::forPosition($index, $count);
        return $role === SectionRole::CONTENT ? null : $role;
    }

    public static function shape(BlockMarkup $doc, int $sectionIndex): string
    {
        $indices = self::subtreeIndices($doc, $sectionIndex);
        $names = array_map(static fn (int $i): string => self::coreName($doc->name($i)), $indices);

        if (self::containsFormName($names)) {
            return 'form';
        }

        $imageCount = count(array_filter($names, static fn (string $name): bool => $name === 'image'));
        $hasGallery = in_array('gallery', $names, true);
        $textLength = mb_strlen(trim(PlainText::fromMarkup($doc->innerHtml($sectionIndex))), 'UTF-8');
        if (($imageCount >= 3 || $hasGallery) && $textLength < 200) {
            return 'gallery';
        }

        $quoteCount = count(array_filter(
            $names,
            static fn (string $name): bool => in_array($name, ['quote', 'pullquote'], true),
        ));
        if ($quoteCount >= 2) {
            return 'quotes';
        }

        $hasHeading = in_array('heading', $names, true);
        if (
            self::coreName($doc->name($sectionIndex)) === 'cover'
            || ($hasHeading && self::hasBackgroundImageGroup($doc, $indices))
            || self::hasLeadingImageBand($doc, $sectionIndex)
        ) {
            return 'cover';
        }

        $widestColumns = self::widestColumns($doc, $indices);
        if ($widestColumns >= 3 || self::largestRepeatedGroupRun($doc, $indices) >= 3) {
            return 'grid';
        }
        if ($widestColumns === 2) {
            return 'split';
        }

        if ($imageCount >= 1 && $hasHeading && !in_array('columns', $names, true)) {
            return 'media-stack';
        }

        return 'stack';
    }

    /**
     * @param array<mixed> $resolvableRoutes
     * @return array{total:int, completeness:int, repetition:int, copy_fit:int, fidelity:int, self_containment:int}
     */
    public static function score(string $sectionMarkup, array $resolvableRoutes): array
    {
        $doc = BlockMarkup::parse($sectionMarkup);
        $indices = $doc->indices();
        $names = array_map(static fn (int $i): string => self::coreName($doc->name($i)), $indices);

        $completeness = 0;
        if (in_array('heading', $names, true)) {
            $completeness += 10;
        }
        if (self::hasBodyCopy($doc, $indices)) {
            $completeness += 10;
        }
        if (self::hasAction($doc, $indices, $sectionMarkup)) {
            $completeness += 10;
        }
        if (array_intersect($names, ['image', 'gallery', 'cover', 'video', 'audio', 'media-text']) !== []) {
            $completeness += 10;
        }

        $widestColumns = self::widestColumns($doc, $indices);
        $repeatUnits = $widestColumns > 0
            ? $widestColumns
            : self::largestRepeatedGroupRun($doc, $indices);
        $repetition = min($repeatUnits, 4) * 5;

        $plainLength = mb_strlen(trim(PlainText::fromMarkup($sectionMarkup)), 'UTF-8');
        $copyFit = self::copyFit($plainLength);

        $fidelity = max(0, 15 - 5 * count(self::degradationMarkers($doc, $sectionMarkup)));

        $routeSet = self::routeSet($resolvableRoutes);
        $anchors = LinkTargets::anchorsIn($sectionMarkup);
        $unresolved = 0;
        foreach (LinkTargets::allTargets($sectionMarkup) as $target) {
            if (!self::targetResolves($target, $routeSet, $anchors)) {
                $unresolved++;
            }
        }
        $selfContainment = max(0, 10 - 2 * $unresolved);

        return [
            'total' => $completeness + $repetition + $copyFit + $fidelity + $selfContainment,
            'completeness' => $completeness,
            'repetition' => $repetition,
            'copy_fit' => $copyFit,
            'fidelity' => $fidelity,
            'self_containment' => $selfContainment,
        ];
    }

    /** @param list<array<mixed>> $candidates @return array<mixed> */
    public static function pickWinner(array $candidates): array
    {
        if ($candidates === []) {
            throw new \InvalidArgumentException('cannot pick a winner from no candidates');
        }

        $winner = $candidates[0];
        foreach (array_slice($candidates, 1) as $candidate) {
            if (self::compareCandidates($candidate, $winner) < 0) {
                $winner = $candidate;
            }
        }
        return $winner;
    }

    /**
     * @param list<string> $warnings
     * @return array{index:int, markup:string, slug:string, anchor:?string}|null
     */
    private static function sectionSlice(
        BlockMarkup $doc,
        string $pageMarkup,
        int $blockIndex,
        int $index,
        string $slug,
        ?string $anchor,
        array &$warnings,
    ): ?array {
        $end = $doc->endOffset($blockIndex);
        if (!$doc->isStructurallySafe($blockIndex) || $end === null) {
            $warnings[] = "Skipped section '{$slug}': block subtree is structurally unsafe or has no closing endpoint.";
            return null;
        }

        $start = $doc->openingOffset($blockIndex);
        return [
            'index' => $index,
            'markup' => substr($pageMarkup, $start, $end - $start),
            'slug' => $slug,
            'anchor' => $anchor,
        ];
    }

    private static function anchor(BlockMarkup $doc, int $index): ?string
    {
        $anchor = $doc->attrs($index)['anchor'] ?? null;
        return is_string($anchor) && $anchor !== '' ? $anchor : null;
    }

    /** @return list<int> */
    private static function subtreeIndices(BlockMarkup $doc, int $root): array
    {
        $out = [];
        $pending = [$root];
        while ($pending !== []) {
            $index = array_pop($pending);
            $out[] = $index;
            foreach (array_reverse($doc->children($index)) as $child) {
                $pending[] = $child;
            }
        }
        return $out;
    }

    private static function coreName(string $name): string
    {
        return str_starts_with($name, 'core/') ? substr($name, 5) : $name;
    }

    private static function isFormName(string $name): bool
    {
        return $name === 'form'
            || $name === 'contact-form'
            || str_ends_with($name, '/form')
            || str_ends_with($name, '/contact-form');
    }

    /** @param list<string> $names */
    private static function containsFormName(array $names): bool
    {
        foreach ($names as $name) {
            if (self::isFormName($name)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<int> $indices */
    private static function hasBackgroundImageGroup(BlockMarkup $doc, array $indices): bool
    {
        foreach ($indices as $index) {
            if (self::coreName($doc->name($index)) !== 'group') {
                continue;
            }
            $attrs = $doc->attrs($index);
            $background = is_array($attrs['style'] ?? null)
                && is_array($attrs['style']['background'] ?? null)
                ? $attrs['style']['background']
                : [];
            if (
                array_key_exists('backgroundImage', $background)
                && $background['backgroundImage'] !== null
                && $background['backgroundImage'] !== ''
                && $background['backgroundImage'] !== []
            ) {
                return true;
            }
        }
        return false;
    }

    private static function hasLeadingImageBand(BlockMarkup $doc, int $sectionIndex): bool
    {
        $children = $doc->children($sectionIndex);
        if ($children === [] || self::coreName($doc->name($children[0])) !== 'image') {
            return false;
        }
        $align = $doc->attrs($children[0])['align'] ?? null;
        if (!in_array($align, ['full', 'wide'], true)) {
            return false;
        }
        foreach (array_slice($children, 1) as $child) {
            if (self::coreName($doc->name($child)) === 'heading') {
                return true;
            }
        }
        return false;
    }

    /** @param list<int> $indices */
    private static function widestColumns(BlockMarkup $doc, array $indices): int
    {
        $widest = 0;
        foreach ($indices as $index) {
            if (self::coreName($doc->name($index)) !== 'columns') {
                continue;
            }
            $count = count(array_filter(
                $doc->children($index),
                static fn (int $child): bool => self::coreName($doc->name($child)) === 'column',
            ));
            $widest = max($widest, $count);
        }
        return $widest;
    }

    /** @param list<int> $indices */
    private static function largestRepeatedGroupRun(BlockMarkup $doc, array $indices): int
    {
        $largest = 0;
        foreach ($indices as $parent) {
            $lastSignature = null;
            $run = 0;
            foreach ($doc->children($parent) as $child) {
                if (self::coreName($doc->name($child)) !== 'group') {
                    $lastSignature = null;
                    $run = 0;
                    continue;
                }
                $signature = implode("\0", array_map(
                    static fn (int $grandchild): string => self::coreName($doc->name($grandchild)),
                    $doc->children($child),
                ));
                if ($lastSignature !== null && $signature === $lastSignature) {
                    $run++;
                } else {
                    $lastSignature = $signature;
                    $run = 1;
                }
                $largest = max($largest, $run);
            }
        }
        return $largest;
    }

    /** @param list<int> $indices */
    private static function hasBodyCopy(BlockMarkup $doc, array $indices): bool
    {
        $bodyNames = ['paragraph', 'list', 'quote', 'pullquote', 'verse', 'table', 'preformatted'];
        foreach ($indices as $index) {
            if (
                in_array(self::coreName($doc->name($index)), $bodyNames, true)
                && trim(PlainText::fromMarkup($doc->innerHtml($index))) !== ''
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param list<int> $indices */
    private static function hasAction(BlockMarkup $doc, array $indices, string $markup): bool
    {
        foreach ($indices as $index) {
            if (in_array(self::coreName($doc->name($index)), ['button', 'navigation-link'], true)) {
                return true;
            }
        }
        return preg_match('/<a\b[^>]*\bhref\s*=/i', $markup) === 1;
    }

    private static function copyFit(int $length): int
    {
        if ($length < 150) {
            return (int) floor(15 * $length / 150);
        }
        if ($length <= 1500) {
            return 15;
        }
        return (int) floor(15 * max(0, 3000 - $length) / 1500);
    }

    /** @return array<string,true> */
    private static function degradationMarkers(BlockMarkup $doc, string $markup): array
    {
        $tokens = [];
        foreach ($doc->indices() as $index) {
            $className = $doc->attrs($index)['className'] ?? null;
            if (is_string($className)) {
                array_push($tokens, ...(preg_split('/\s+/', trim($className)) ?: []));
            }
        }
        if (preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/is', $markup, $matches)) {
            foreach ($matches[2] as $className) {
                array_push($tokens, ...(preg_split('/\s+/', trim($className)) ?: []));
            }
        }

        $markers = [];
        foreach ($tokens as $token) {
            if (
                $token === 'blocks-engine-empty-flex-item'
                || preg_match('/^blocks-engine-synthetic-[a-z0-9_-]+$/', $token) === 1
            ) {
                $markers[$token] = true;
            }
        }
        return $markers;
    }

    /** @param array<mixed> $routes @return array<string,true> */
    private static function routeSet(array $routes): array
    {
        $set = [];
        foreach ($routes as $key => $value) {
            if (is_int($key)) {
                if (is_string($value) && $value !== '') {
                    $set[$value] = true;
                }
                continue;
            }
            if ($value) {
                $set[(string) $key] = true;
            }
        }
        return $set;
    }

    /** @param array<string,true> $routes @param array<string,true> $anchors */
    private static function targetResolves(string $target, array $routes, array $anchors): bool
    {
        $target = html_entity_decode(trim($target), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($target === '' || $target === '#') {
            return true;
        }
        if (LinkTargets::isDangerousScheme($target)) {
            return false;
        }
        if (str_starts_with($target, '#')) {
            return isset($anchors[rawurldecode(substr($target, 1))]);
        }
        if (
            LinkTargets::isSafeAbsoluteTarget($target)
            || str_starts_with($target, 'theme:./assets/')
            || LinkTargets::isThemeAssetPath($target)
        ) {
            return true;
        }
        if (isset($routes[$target])) {
            return true;
        }
        $path = parse_url($target, PHP_URL_PATH);
        return is_string($path) && isset($routes[$path]);
    }

    /** @param array<mixed> $left @param array<mixed> $right */
    private static function compareCandidates(array $left, array $right): int
    {
        $byTotal = ((int) ($right['score']['total'] ?? 0)) <=> ((int) ($left['score']['total'] ?? 0));
        if ($byTotal !== 0) {
            return $byTotal;
        }
        $byMenuOrder = ((int) ($left['menu_order'] ?? 0)) <=> ((int) ($right['menu_order'] ?? 0));
        if ($byMenuOrder !== 0) {
            return $byMenuOrder;
        }
        $byIndex = ((int) ($left['index'] ?? 0)) <=> ((int) ($right['index'] ?? 0));
        if ($byIndex !== 0) {
            return $byIndex;
        }
        $bySlug = strcmp((string) ($left['slug'] ?? ''), (string) ($right['slug'] ?? ''));
        if ($bySlug !== 0) {
            return $bySlug;
        }

        return strcmp(
            serialize(self::canonicalValue($left)),
            serialize(self::canonicalValue($right)),
        );
    }

    private static function canonicalValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $entry) {
            $value[$key] = self::canonicalValue($entry);
        }
        return $value;
    }
}
