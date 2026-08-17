<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\CssChecks;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Units\GeneratedMarkup;

/**
 * Deterministically give every transformed section one inline-axis owner.
 *
 * SectionRhythmStep owns the root group's block-axis spacing. This sibling
 * pass runs immediately afterwards and owns only the inline axis: section roots
 * become constrained, root-authored horizontal padding is removed, and direct
 * cover children, CSS-authored background bands, or CSS-owned out-of-flow
 * children opt into full bleed. In-flow children with an authored width retain
 * their authored start, centre, or end alignment instead of inheriting
 * WordPress centring; start-aligned children keep the root's authored inset.
 * Covers also carry the constrained-layout class bridge their inner container
 * needs. Nested blocks stay outside this ownership boundary.
 *
 * Rewrites are transactional per page. A malformed generated section keeps
 * that page's pre-transformation bytes, records one durable warning, and does
 * not prevent healthy sibling pages from being normalized.
 */
final class SectionLayoutStep implements Step
{
    public const AUTHOR_WIDTH_START_CLASS = 'blocks-engine-author-width-start';

    /**
     * The desktop width the design preview renders at, so a media-scoped rule
     * is judged against the layout the theme is built to reproduce. Matches
     * ThemeJsonStep's content-width reference viewport and bin/screenshot.
     */
    private const RENDER_VIEWPORT_WIDTH = 1366.0;

    /** Inline CSS declarations owned by the theme's root gutter. */
    private const ROOT_INLINE_PROPERTIES = [
        'padding-inline',
        'padding-left',
        'padding-right',
    ];

    public function id(): string
    {
        return 'section-layout';
    }

    public function label(): string
    {
        return 'Normalize section layout';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['pages.json', 'theme/parts/*', 'theme/theme.json', 'design/site.css', 'design/home.html'],
            writes: ['theme/parts/*', 'theme/theme.json', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $writes = [];
        $adjustments = 0;
        $warnings = [];
        $wideSelectors = self::wideSelectorSet($project);
        $fullBleedClasses = self::fullBleedClassSet($project);
        $outOfFlowClasses = self::outOfFlowClassSet($project);
        $authoredWidthDeclarations = self::authoredWidthDeclarations($project);
        $themeWrite = null;
        if ($project->exists('theme/theme.json') && $project->exists('design/site.css')) {
            $authoredTheme = $project->readJson('theme/theme.json');
            [$normalizedTheme, $themeWarnings] = ThemeJsonStep::normalizeLayoutWidths(
                $authoredTheme,
                $project->readText('design/site.css'),
                $project->exists('design/home.html') ? $project->readText('design/home.html') : null,
            );
            array_push($warnings, ...$themeWarnings);
            if ($normalizedTheme !== $authoredTheme) {
                $themeWrite = $normalizedTheme;
            }
        }

        foreach (SectionRhythmStep::pages($project) as $page) {
            $pageSlug = trim((string) ($page['slug'] ?? ''));
            $currentPath = "theme/parts/page-{$pageSlug}--*.html";

            try {
                [$entries, $rels] = SectionRhythmStep::planEntries($project, $page);
                $pageWrites = [];
                $pageAdjustments = 0;
                foreach ($entries as $index => $entry) {
                    $currentPath = 'theme/' . $rels[$index];
                    $rewritten = self::rewriteSection(
                        $entry['markup'],
                        "page '{$pageSlug}', section '{$entry['slug']}'",
                        $wideSelectors,
                        $fullBleedClasses,
                        $outOfFlowClasses,
                        $authoredWidthDeclarations,
                    );
                    $pageWrites[$currentPath] = $rewritten;
                    if ($rewritten !== $entry['markup']) {
                        $pageAdjustments++;
                    }
                }
            } catch (\RuntimeException|\InvalidArgumentException $error) {
                $warnings[] = "file {$currentPath}, block /: authored value top-level wp:group section root; "
                    . "delivered value pre-transformation bytes; disposition section layout skipped for page '{$pageSlug}' "
                    . "({$error->getMessage()})";
                continue;
            }

            foreach ($pageWrites as $path => $markup) {
                $writes[$path] = $markup;
            }
            $adjustments += $pageAdjustments;
        }

        $headerPath = 'theme/parts/header.html';
        if ($wideSelectors !== [] && $project->exists($headerPath)) {
            try {
                $header = self::rewriteWidePart(
                    $project->readText($headerPath),
                    'header part',
                    $wideSelectors,
                );
                if ($header !== $project->readText($headerPath)) {
                    $writes[$headerPath] = $header;
                    $adjustments++;
                }
            } catch (\RuntimeException|\InvalidArgumentException $error) {
                $warnings[] = "file {$headerPath}, block /: authored value transformed header blocks; "
                    . 'delivered value pre-transformation bytes; disposition wide-carrier alignment skipped '
                    . "({$error->getMessage()})";
            }
        }

        foreach ($writes as $path => $markup) {
            $project->writeText($path, $markup);
        }
        if ($themeWrite !== null) {
            $project->writeJson('theme/theme.json', $themeWrite);
        }
        $project->addWarnings($this->id(), $warnings);

        Narrator::write("  section layout: {$adjustments} section adjustment(s)\n");
        if ($warnings !== []) {
            Narrator::write('  [section-layout] warning: ' . count($warnings)
                . " degradation(s) recorded in warnings.json\n");
        }
    }

    /**
     * @param list<list<array{tag:?string,id:?string,classes:list<string>}>> $wideSelectors
     * @param array<string,true> $fullBleedClasses class token => true from design/site.css
     * @param array<string,true> $outOfFlowClasses class token => true from design/site.css
     * @param list<array<string,mixed>> $authoredWidthDeclarations
     */
    private static function rewriteSection(
        string $markup,
        string $label,
        array $wideSelectors = [],
        array $fullBleedClasses = [],
        array $outOfFlowClasses = [],
        array $authoredWidthDeclarations = [],
    ): string
    {
        $doc = self::validatedDocument($markup, $label);
        $root = (int) $doc->topLevel();
        $attrs = $doc->attrs($root) ?? [];
        $cssOwnedRoot = GeneratedMarkup::hasCssOwnedLayoutMarker($attrs);
        $authorWidthTargets = $authoredWidthDeclarations === []
            ? ['start' => [], 'escape' => []]
            : self::authorWidthAlignmentTargets(
                $doc,
                $root,
                $authoredWidthDeclarations,
                $outOfFlowClasses,
            );

        $attrs['layout'] = ['type' => 'constrained'];
        if ($authorWidthTargets['start'] !== []) {
            // WordPress centres ordinary constrained children by default.
            // Start justification restores normal authored flow inside the
            // root's content inset without making those children full bleed.
            $attrs['layout']['justifyContent'] = 'left';
        }
        self::constrainCommentClass($attrs, $label);
        self::toggleCommentClass(
            $attrs,
            self::AUTHOR_WIDTH_START_CLASS,
            $authorWidthTargets['start'] !== [],
        );
        self::stripCommentInlinePadding($attrs);
        $doc->setAttrs($root, $attrs);

        $covers = array_values(array_filter(
            $doc->children($root),
            static fn (int $child): bool => $doc->name($child) === 'cover',
        ));
        if (count($covers) === 1) {
            $coverAttrs = $doc->attrs($covers[0]) ?? [];
            $coverAttrs['align'] = 'full';
            $coverAttrs['layout'] = ['type' => 'constrained'];
            self::constrainCommentClass($coverAttrs, "{$label}, direct cover");
            $doc->setAttrs($covers[0], $coverAttrs);
        }

        if ($cssOwnedRoot && $outOfFlowClasses !== []) {
            foreach ($doc->children($root) as $child) {
                foreach (self::elementClassTokens($doc, $child) as $token) {
                    if (!isset($outOfFlowClasses[$token])) {
                        continue;
                    }
                    $childAttrs = $doc->attrs($child) ?? [];
                    $childAttrs['align'] = 'full';
                    $doc->setAttrs($child, $childAttrs);
                    break;
                }
            }
        }

        if ($fullBleedClasses !== []) {
            foreach ($doc->children($root) as $child) {
                foreach (self::elementClassTokens($doc, $child) as $token) {
                    if (!isset($fullBleedClasses[$token])) {
                        continue;
                    }
                    $childAttrs = $doc->attrs($child) ?? [];
                    if (!isset($childAttrs['align'])) {
                        $childAttrs['align'] = 'full';
                        $doc->setAttrs($child, $childAttrs);
                    }
                    break;
                }
            }
        }

        // A left auto margin expresses end alignment, which constrained start
        // justification cannot represent. Only those authored-width children
        // escape the constrained rule; start-aligned widths keep its inset and
        // two auto margins retain WordPress's default centring.
        if ($authorWidthTargets['escape'] !== []) {
            foreach ($authorWidthTargets['escape'] as $target) {
                $targetAttrs = $doc->attrs($target) ?? [];
                if (!isset($targetAttrs['align']) || $targetAttrs['align'] === 'wide') {
                    $targetAttrs['align'] = 'full';
                    $doc->setAttrs($target, $targetAttrs);
                }
            }
        }

        // A constrained layout re-caps its children at contentSize unless the
        // measure-bearing block opts into wide. Align every outermost block
        // selected by a var(--wide-size) rule. A direct cover already runs
        // full, so its explicit align is never downgraded.
        if ($wideSelectors !== []) {
            foreach (self::outermostWideBlocks($doc, $wideSelectors) as $target) {
                $targetAttrs = $doc->attrs($target) ?? [];
                if (!isset($targetAttrs['align'])) {
                    $targetAttrs['align'] = 'wide';
                    $doc->setAttrs($target, $targetAttrs);
                }
            }
        }

        $rewritten = $doc->render();
        $rendered = self::validatedDocument($rewritten, $label);
        return self::normalizeWrapper($rewritten, $rendered, (int) $rendered->topLevel(), $label);
    }

    /**
     * Apply only CSS-owned wide alignment to a shared theme part. Section root
     * constraints and #261's full-bleed promotion remain page-section concerns.
     *
     * @param list<list<array{tag:?string,id:?string,classes:list<string>}>> $wideSelectors
     */
    private static function rewriteWidePart(string $markup, string $label, array $wideSelectors): string
    {
        $doc = BlockMarkup::parse($markup);
        if ($doc->indices() === []
            || $doc->hasMalformedDelimiters()
            || $doc->hasMismatchedDelimiters()
            || $doc->unclosedIndices() !== []
        ) {
            throw new \RuntimeException("section-layout: {$label} has malformed block delimiters");
        }

        foreach (self::outermostWideBlocks($doc, $wideSelectors, ['header']) as $target) {
            $attrs = $doc->attrs($target) ?? [];
            if (!isset($attrs['align'])) {
                $attrs['align'] = 'wide';
                $doc->setAttrs($target, $attrs);
            }
        }

        return $doc->render();
    }

    /**
     * Require the transformed-section contract before changing any bytes.
     */
    private static function validatedDocument(string $markup, string $label): BlockMarkup
    {
        $doc = BlockMarkup::parse($markup);
        if ($doc->hasMalformedDelimiters()
            || $doc->hasMismatchedDelimiters()
            || $doc->unclosedIndices() !== []
        ) {
            throw new \RuntimeException("section-layout: {$label} has malformed block delimiters");
        }

        $roots = array_values(array_filter(
            $doc->indices(),
            static fn (int $index): bool => $doc->parent($index) === null,
        ));
        if (count($roots) !== 1
            || $doc->name($roots[0]) !== 'group'
            || $doc->isVoid($roots[0])
            || $doc->endOffset($roots[0]) === null
        ) {
            throw new \RuntimeException(
                "section-layout: {$label} must contain exactly one well-formed top-level wp:group"
            );
        }

        $root = $roots[0];
        if (trim(substr($markup, 0, $doc->openingOffset($root))) !== ''
            || trim(substr($markup, (int) $doc->endOffset($root))) !== ''
        ) {
            throw new \RuntimeException(
                "section-layout: {$label} has content outside its top-level wp:group"
            );
        }

        return $doc;
    }

    /** @param array<mixed> $attrs */
    private static function constrainCommentClass(array &$attrs, string $label): void
    {
        if (isset($attrs['className']) && !is_string($attrs['className'])) {
            throw new \RuntimeException("section-layout: {$label} has a non-string root className");
        }
        $tokens = preg_split(
            '/[\x20\t\r\n\f]+/',
            trim((string) ($attrs['className'] ?? '')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $attrs['className'] = self::constrainedClassTokens($tokens);
    }

    /** @param array<mixed> $attrs */
    private static function toggleCommentClass(array &$attrs, string $token, bool $enabled): void
    {
        $tokens = preg_split(
            '/[\x20\t\r\n\f]+/',
            trim((string) ($attrs['className'] ?? '')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $tokens = array_values(array_filter(
            $tokens,
            static fn (string $candidate): bool => $candidate !== $token,
        ));
        if ($enabled) {
            $tokens[] = $token;
        }
        $attrs['className'] = implode(' ', $tokens);
    }

    /** @param array<mixed> $attrs */
    private static function stripCommentInlinePadding(array &$attrs): void
    {
        if (!isset($attrs['style']) || !is_array($attrs['style'])
            || !isset($attrs['style']['spacing']) || !is_array($attrs['style']['spacing'])
            || !isset($attrs['style']['spacing']['padding'])
            || !is_array($attrs['style']['spacing']['padding'])
        ) {
            return;
        }

        $removed = isset($attrs['style']['spacing']['padding']['left'])
            || isset($attrs['style']['spacing']['padding']['right']);
        unset(
            $attrs['style']['spacing']['padding']['left'],
            $attrs['style']['spacing']['padding']['right'],
        );

        if (!$removed || $attrs['style']['spacing']['padding'] !== []) {
            return;
        }
        unset($attrs['style']['spacing']['padding']);
        if ($attrs['style']['spacing'] === []) {
            unset($attrs['style']['spacing']);
        }
        if ($attrs['style'] === []) {
            unset($attrs['style']);
        }
    }

    private static function normalizeWrapper(
        string $markup,
        BlockMarkup $doc,
        int $root,
        string $label,
    ): string {
        $searchOffset = $doc->openingOffset($root) + $doc->openingLength($root);
        $tag = self::wrapperTag($markup, $searchOffset);
        if ($tag === null) {
            return $markup;
        }

        [$tagHtml, $tagLength] = $tag;
        $style = self::tagAttribute($tagHtml, 'style');
        if ($style === null) {
            if (preg_match('/[\x20\t\r\n\f]style\s*=/i', $tagHtml) === 1) {
                throw new \RuntimeException("section-layout: {$label} has an unparseable root style attribute");
            }
            return $markup;
        }

        [$value, $valueOffset] = $style;
        $newValue = self::withoutOwnedDeclarations($value);
        if ($newValue === $value) {
            return $markup;
        }
        $newTag = substr_replace($tagHtml, $newValue, $valueOffset, strlen($value));
        return substr_replace($markup, $newTag, $searchOffset, $tagLength);
    }

    /** @param list<string> $tokens */
    private static function constrainedClassTokens(array $tokens): string
    {
        $layoutTokens = ['is-layout-flow', 'is-layout-flex', 'is-layout-grid', 'is-layout-constrained'];
        $kept = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => !in_array($token, $layoutTokens, true),
        ));
        $kept[] = 'is-layout-constrained';
        return implode(' ', $kept);
    }

    /** @return array{string,int}|null wrapper tag HTML and byte length */
    private static function wrapperTag(string $markup, int $searchOffset): ?array
    {
        $rest = substr($markup, $searchOffset);
        if (preg_match('/\A\s*<[a-zA-Z][a-zA-Z0-9-]*(?=[\x20\t\r\n\f\/>])/', $rest, $start) !== 1) {
            return null;
        }

        $quote = null;
        $length = strlen($rest);
        for ($index = strlen($start[0]); $index < $length; $index++) {
            $character = $rest[$index];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }
            if ($character === '>') {
                $tagLength = $index + 1;
                return [substr($rest, 0, $tagLength), $tagLength];
            }
        }

        return null;
    }

    /** @return array{string,int}|null attribute value and byte offset in tag */
    private static function tagAttribute(string $tagHtml, string $name): ?array
    {
        $pattern = '/[\x20\t\r\n\f]' . preg_quote($name, '/')
            . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i';
        if (preg_match($pattern, $tagHtml, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        return ($match[1][1] ?? -1) !== -1 ? $match[1] : $match[2];
    }

    private static function withoutOwnedDeclarations(string $style): string
    {
        $kept = [];
        foreach (explode(';', $style) as $declaration) {
            $colon = strpos($declaration, ':');
            $property = strtolower(trim($colon === false ? $declaration : substr($declaration, 0, $colon)));
            if (!in_array($property, self::ROOT_INLINE_PROPERTIES, true)) {
                $kept[] = $declaration;
            }
        }
        return implode(';', $kept);
    }

    /**
     * Parsed wide-measure selectors from the design stylesheet.
     *
     * @return list<list<array{tag:?string,id:?string,classes:list<string>}>>
     */
    private static function wideSelectorSet(Project $project): array
    {
        if (!$project->exists('design/site.css')) {
            return [];
        }
        return self::wideSelectors($project->readText('design/site.css'));
    }

    /** The full-bleed class lookup set from the design stylesheet. @return array<string,true> */
    private static function fullBleedClassSet(Project $project): array
    {
        if (!$project->exists('design/site.css')) {
            return [];
        }
        $set = [];
        foreach (self::fullBleedClassTokens($project->readText('design/site.css')) as $token) {
            $set[$token] = true;
        }
        return $set;
    }

    /** The out-of-flow class lookup set from the design stylesheet. @return array<string,true> */
    private static function outOfFlowClassSet(Project $project): array
    {
        if (!$project->exists('design/site.css')) {
            return [];
        }
        $set = [];
        foreach (self::outOfFlowClassTokens($project->readText('design/site.css')) as $token) {
            $set[$token] = true;
        }
        return $set;
    }

    /**
     * Relevant authored declarations with enough selector and cascade facts
     * to decide whether a direct constrained child owns its alignment.
     *
     * @return list<array<string,mixed>>
     */
    private static function authoredWidthDeclarations(Project $project): array
    {
        if (!$project->exists('design/site.css')) {
            return [];
        }

        $relevant = [
            'all',
            'width',
            'max-width',
            'margin',
            'margin-inline',
            'margin-left',
            'margin-right',
            'margin-inline-start',
            'margin-inline-end',
        ];
        $parsed = [];
        foreach (CssChecks::scanDeclarations($project->readText('design/site.css')) as $declaration) {
            $property = strtolower($declaration['property']);
            if ($declaration['kind'] !== 'style'
                || !$declaration['structurallySafe']
                || !in_array($property, $relevant, true)
            ) {
                continue;
            }
            $scope = CssChecks::declarationScopeAtViewport(
                $declaration['ancestors'],
                self::RENDER_VIEWPORT_WIDTH,
            );
            if ($scope === 'inert') {
                // Proven false at the render viewport, so it is not part of
                // this design's cascade at all and cannot bear on alignment.
                continue;
            }
            $priority = CssChecks::splitDeclarationPriority($declaration['value']);
            foreach (CssValueSplitter::splitTopLevel($declaration['context'], [',']) as $selectorText) {
                $selector = self::parseWideSelector(trim($selectorText));
                if ($selector === null) {
                    continue;
                }
                $parsed[] = [
                    'selector' => $selector,
                    'property' => $property,
                    'value' => $priority['value'],
                    'important' => $priority['important'],
                    'specificity' => self::selectorSpecificity($selector),
                    'order' => $declaration['start'],
                    // Static block alignment cannot represent a scope this
                    // build cannot settle by width alone. A matching row makes
                    // that target unprovable and ineligible for promotion.
                    'unprovable' => $scope === 'unprovable',
                ];
            }
        }
        return $parsed;
    }

    /**
     * @param list<array<string,mixed>> $declarations
     * @param array<string,true> $outOfFlowClasses
     * @return array{start:list<int>,escape:list<int>}
     */
    private static function authorWidthAlignmentTargets(
        BlockMarkup $doc,
        int $root,
        array $declarations,
        array $outOfFlowClasses,
    ): array {
        $targets = ['start' => [], 'escape' => []];
        foreach ($doc->children($root) as $child) {
            if (array_intersect_key(
                array_fill_keys(self::elementClassTokens($doc, $child), true),
                $outOfFlowClasses,
            ) !== []) {
                continue;
            }
            $alignment = self::authoredWidthAlignment(
                $declarations,
                static fn (array $declaration): bool => self::selectorMatchesBlock(
                    $doc,
                    $child,
                    $declaration['selector'],
                ),
            );
            if ($alignment !== null) {
                $targets[$alignment][] = $child;
            }
        }
        return $targets;
    }

    /**
     * Resolve the relevant cascade for one block.
     *
     * @param list<array<string,mixed>> $declarations
     * @param callable(array<string,mixed>):bool $matches
     * @return 'start'|'escape'|null
     */
    private static function authoredWidthAlignment(array $declarations, callable $matches): ?string
    {
        $states = [
            'width' => null,
            'max-width' => null,
            'margin-left' => null,
            'margin-right' => null,
        ];
        $unprovable = false;
        foreach ($declarations as $declaration) {
            if (!$matches($declaration)) {
                continue;
            }
            if ($declaration['unprovable']) {
                $unprovable = true;
                continue;
            }
            foreach (self::expandedAlignmentValues($declaration) as $property => $value) {
                $candidate = [
                    'value' => $value,
                    'important' => $declaration['important'],
                    'specificity' => $declaration['specificity'],
                    'order' => $declaration['order'],
                ];
                if (self::alignmentCandidateOutranks($candidate, $states[$property])) {
                    $states[$property] = $candidate;
                }
            }
        }
        if ($unprovable) {
            return null;
        }

        $ownsWidth = ($states['width']['value'] ?? null) === 'bound'
            || ($states['max-width']['value'] ?? null) === 'bound';
        $left = $states['margin-left']['value'] ?? 'non-auto';
        $right = $states['margin-right']['value'] ?? 'non-auto';
        if (!$ownsWidth || $left === 'unknown' || $right === 'unknown'
            || ($left === 'auto' && $right === 'auto')
        ) {
            return null;
        }
        return $left === 'auto' ? 'escape' : 'start';
    }

    /**
     * @param array{property:string,value:string} $declaration
     * @return array<string,'bound'|'none'|'auto'|'non-auto'|'unknown'>
     */
    private static function expandedAlignmentValues(array $declaration): array
    {
        $property = $declaration['property'];
        $value = trim($declaration['value']);
        if ($property === 'width' || $property === 'max-width') {
            return [$property => self::widthValueState($property, $value)];
        }
        if ($property === 'all') {
            return array_fill_keys(
                ['width', 'max-width', 'margin-left', 'margin-right'],
                'unknown',
            );
        }

        if ($property === 'margin-left' || $property === 'margin-inline-start') {
            return ['margin-left' => self::marginValueState($value)];
        }
        if ($property === 'margin-right' || $property === 'margin-inline-end') {
            return ['margin-right' => self::marginValueState($value)];
        }

        $parts = CssValueSplitter::splitTopLevelWhitespace($value);
        if ($property === 'margin-inline') {
            if (count($parts) < 1 || count($parts) > 2) {
                return ['margin-left' => 'unknown', 'margin-right' => 'unknown'];
            }
            return [
                'margin-left' => self::marginValueState($parts[0]),
                'margin-right' => self::marginValueState($parts[1] ?? $parts[0]),
            ];
        }
        if ($property !== 'margin' || count($parts) < 1 || count($parts) > 4) {
            return [];
        }
        [$left, $right] = match (count($parts)) {
            1 => [$parts[0], $parts[0]],
            2, 3 => [$parts[1], $parts[1]],
            4 => [$parts[3], $parts[1]],
        };
        return [
            'margin-left' => self::marginValueState($left),
            'margin-right' => self::marginValueState($right),
        ];
    }

    /** @return 'bound'|'none'|'unknown' */
    private static function widthValueState(string $property, string $value): string
    {
        $keyword = strtolower($value);
        if (in_array($keyword, ['initial', 'unset'], true)
            || ($property === 'width' && $keyword === 'auto')
            || ($property === 'max-width' && $keyword === 'none')
        ) {
            return 'none';
        }
        if ($value === ''
            || in_array($keyword, ['inherit', 'revert', 'revert-layer'], true)
            || preg_match('/\A(?:var|env)\s*\(/i', $value) === 1
        ) {
            return 'unknown';
        }
        return 'bound';
    }

    /** @return 'auto'|'non-auto'|'unknown' */
    private static function marginValueState(string $value): string
    {
        $keyword = strtolower(trim($value));
        if ($keyword === 'auto') {
            return 'auto';
        }
        if ($keyword === ''
            || in_array($keyword, ['inherit', 'revert', 'revert-layer'], true)
            || preg_match('/\A(?:var|env)\s*\(/i', $keyword) === 1
        ) {
            return 'unknown';
        }
        return 'non-auto';
    }

    /** @param list<array{tag:?string,id:?string,classes:list<string>}> $selector */
    private static function selectorSpecificity(array $selector): int
    {
        $specificity = 0;
        foreach ($selector as $compound) {
            $specificity += ($compound['id'] === null ? 0 : 100)
                + count($compound['classes']) * 10
                + ($compound['tag'] === null ? 0 : 1);
        }
        return $specificity;
    }

    /**
     * @param array{value:string,important:bool,specificity:int,order:int} $candidate
     * @param array{value:string,important:bool,specificity:int,order:int}|null $winner
     */
    private static function alignmentCandidateOutranks(array $candidate, ?array $winner): bool
    {
        if ($winner === null) {
            return true;
        }
        if ($candidate['important'] !== $winner['important']) {
            return $candidate['important'];
        }
        if ($candidate['specificity'] !== $winner['specificity']) {
            return $candidate['specificity'] > $winner['specificity'];
        }
        return $candidate['order'] > $winner['order'];
    }

    /**
     * Class selectors in a stylesheet whose horizontal measure references the
     * literal var(--wide-size) token. Token-based only: a max-width/width that
     * resolves to var(--content-size) or an absolute value is content tier and
     * never counted. Comment-stripped; malformed or absent rules yield []. Pure
     * — unit-testable.
     *
     * @return list<string>
     */
    public static function wideClassTokens(string $css): array
    {
        $stripped = preg_replace('~/\*.*?\*/~s', '', $css);
        if (!is_string($stripped)) {
            return [];
        }
        $tokens = [];
        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $stripped, $rules, PREG_SET_ORDER)) {
            foreach ($rules as $rule) {
                if (!self::measuresWideSize($rule[2])) {
                    continue;
                }
                if (preg_match_all('/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', $rule[1], $classes) > 0) {
                    foreach ($classes[1] as $class) {
                        $tokens[$class] = true;
                    }
                }
            }
        }
        return array_keys($tokens);
    }

    /**
     * Simple class, ID, type, and descendant selectors whose declaration owns
     * the wide inline measure. Unsupported selector grammar is ignored rather
     * than approximated, so ancestry can never be silently discarded.
     *
     * @return list<list<array{tag:?string,id:?string,classes:list<string>}>>
     */
    private static function wideSelectors(string $css): array
    {
        $stripped = preg_replace('~/\*.*?\*/~s', '', $css);
        if (!is_string($stripped)) {
            return [];
        }

        $selectors = [];
        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $stripped, $rules, PREG_SET_ORDER) !== false) {
            foreach ($rules as $rule) {
                if (!self::measuresWideSize($rule[2])) {
                    continue;
                }
                foreach (explode(',', $rule[1]) as $selector) {
                    $parsed = self::parseWideSelector(trim($selector));
                    if ($parsed !== null) {
                        $selectors[] = $parsed;
                    }
                }
            }
        }

        return $selectors;
    }

    /**
     * @return list<array{tag:?string,id:?string,classes:list<string>}>|null
     */
    private static function parseWideSelector(string $selector): ?array
    {
        if ($selector === ''
            || str_contains($selector, '\\')
            || preg_match('/[>+~:\[\]]/', $selector) === 1
        ) {
            return null;
        }

        $parts = preg_split('/[\x20\t\r\n\f]+/', $selector, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return null;
        }

        $compounds = [];
        foreach ($parts as $part) {
            if (preg_match(
                '/\A(?:(?<tag>\*|-?[A-Za-z_][A-Za-z0-9_-]*))?'
                    . '(?<tokens>(?:[.#]-?[A-Za-z_][A-Za-z0-9_-]*)*)\z/',
                $part,
                $match,
            ) !== 1) {
                return null;
            }
            $tag = ($match['tag'] ?? '') === '' || ($match['tag'] ?? '') === '*'
                ? null
                : strtolower($match['tag']);
            $id = null;
            $classes = [];
            if (preg_match_all('/([.#])(-?[A-Za-z_][A-Za-z0-9_-]*)/', $match['tokens'], $tokens, PREG_SET_ORDER)) {
                foreach ($tokens as $token) {
                    if ($token[1] === '#') {
                        if ($id !== null) {
                            return null;
                        }
                        $id = $token[2];
                    } else {
                        $classes[] = $token[2];
                    }
                }
            }
            if ($tag === null && $id === null && $classes === []) {
                return null;
            }
            $compounds[] = ['tag' => $tag, 'id' => $id, 'classes' => $classes];
        }

        return $compounds;
    }

    /**
     * Class selectors whose rule paints a background without declaring a
     * max-width. Such a class on a direct section child identifies a band whose
     * own background should span the viewport while its descendants own insets.
     * Comment-stripped; malformed or absent rules yield []. Pure — unit-testable.
     *
     * @return list<string>
     */
    public static function fullBleedClassTokens(string $css): array
    {
        $stripped = preg_replace('~/\*.*?\*/~s', '', $css);
        if (!is_string($stripped)) {
            return [];
        }
        $tokens = [];
        $outOfFlowTokens = [];
        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $stripped, $rules, PREG_SET_ORDER)) {
            foreach ($rules as $rule) {
                if (!self::positionsOutOfFlow($rule[2])) {
                    continue;
                }
                if (preg_match_all('/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', $rule[1], $classes) > 0) {
                    foreach ($classes[1] as $class) {
                        $outOfFlowTokens[$class] = true;
                    }
                }
            }
            foreach ($rules as $rule) {
                if (preg_match(
                    '/(?<!:)(?:::(?:before|after|first-line|first-letter|marker|selection|placeholder)|:(?:before|after))\b/i',
                    $rule[1],
                ) === 1 || !self::paintsUnboundedBackground($rule[2])) {
                    continue;
                }
                if (preg_match_all('/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', $rule[1], $classes) > 0) {
                    foreach ($classes[1] as $class) {
                        $tokens[$class] = true;
                    }
                }
            }
        }
        return array_keys(array_diff_key($tokens, $outOfFlowTokens));
    }

    /**
     * Class selectors whose rule takes the selected element out of normal flow.
     * This is deliberately separate from fullBleedClassTokens(): an overlay is
     * not a painted section band, but as a direct child of a constrained,
     * CSS-owned section it still must escape WordPress's content-width cap.
     *
     * @return list<string>
     */
    public static function outOfFlowClassTokens(string $css): array
    {
        $stripped = preg_replace('~/\*.*?\*/~s', '', $css);
        if (!is_string($stripped)) {
            return [];
        }
        $tokens = [];
        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $stripped, $rules, PREG_SET_ORDER)) {
            foreach ($rules as $rule) {
                if (!self::positionsOutOfFlow($rule[2])) {
                    continue;
                }
                if (preg_match_all('/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', $rule[1], $classes) > 0) {
                    foreach ($classes[1] as $class) {
                        $tokens[$class] = true;
                    }
                }
            }
        }
        return array_keys($tokens);
    }

    /** Whether a rule body's max-width or width references var(--wide-size). */
    private static function measuresWideSize(string $body): bool
    {
        foreach (explode(';', $body) as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                continue;
            }
            $property = strtolower(trim(substr($declaration, 0, $colon)));
            if (in_array($property, ['width', 'max-width', 'inline-size', 'max-inline-size'], true)
                && preg_match('/var\(\s*--wide-size\s*\)/i', substr($declaration, $colon + 1)) === 1
            ) {
                return true;
            }
        }
        return false;
    }

    /** Whether a rule paints a background and leaves its horizontal measure unbounded. */
    private static function paintsUnboundedBackground(string $body): bool
    {
        $paintsBackground = false;
        foreach (explode(';', $body) as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                continue;
            }
            $property = strtolower(trim(substr($declaration, 0, $colon)));
            if ($property === 'max-width') {
                return false;
            }
            if ($property === 'background' || $property === 'background-color') {
                $paintsBackground = true;
            }
        }
        return $paintsBackground;
    }

    /** Whether a rule takes its selected element out of normal flow. */
    private static function positionsOutOfFlow(string $body): bool
    {
        foreach (explode(';', $body) as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false || strtolower(trim(substr($declaration, 0, $colon))) !== 'position') {
                continue;
            }
            if (preg_match('/\A\s*(?:absolute|fixed)\b/i', substr($declaration, $colon + 1)) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<list<array{tag:?string,id:?string,classes:list<string>}>> $selectors
     * @param list<string> $contextTags semantic ancestors supplied by the containing theme part
     * @return list<int>
     */
    private static function outermostWideBlocks(
        BlockMarkup $doc,
        array $selectors,
        array $contextTags = [],
    ): array
    {
        $matches = [];
        foreach ($doc->indices() as $index) {
            foreach ($selectors as $selector) {
                if (self::selectorMatchesBlock($doc, $index, $selector, $contextTags)) {
                    $matches[] = $index;
                    break;
                }
            }
        }

        $matchSet = array_fill_keys($matches, true);
        return array_values(array_filter($matches, static function (int $index) use ($doc, $matchSet): bool {
            for ($parent = $doc->parent($index); $parent !== null; $parent = $doc->parent($parent)) {
                if (isset($matchSet[$parent])) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * @param list<array{tag:?string,id:?string,classes:list<string>}> $selector
     * @param list<string> $contextTags
     */
    private static function selectorMatchesBlock(
        BlockMarkup $doc,
        int $index,
        array $selector,
        array $contextTags = [],
    ): bool
    {
        $last = count($selector) - 1;
        if ($last < 0 || !self::compoundMatchesBlock($doc, $index, $selector[$last])) {
            return false;
        }

        $cursor = $doc->parent($index);
        $contextCursor = count($contextTags) - 1;
        for ($part = $last - 1; $part >= 0; $part--) {
            while ($cursor !== null && !self::compoundMatchesBlock($doc, $cursor, $selector[$part])) {
                $cursor = $doc->parent($cursor);
            }
            if ($cursor === null) {
                $compound = $selector[$part];
                if ($compound['id'] !== null
                    || $compound['classes'] !== []
                    || $compound['tag'] === null
                ) {
                    return false;
                }
                while ($contextCursor >= 0 && $contextTags[$contextCursor] !== $compound['tag']) {
                    $contextCursor--;
                }
                if ($contextCursor < 0) {
                    return false;
                }
                $contextCursor--;
                continue;
            }
            $cursor = $doc->parent($cursor);
        }

        return true;
    }

    /** @param array{tag:?string,id:?string,classes:list<string>} $compound */
    private static function compoundMatchesBlock(BlockMarkup $doc, int $index, array $compound): bool
    {
        $facts = self::elementFacts($doc, $index);
        if ($compound['tag'] !== null && $compound['tag'] !== $facts['tag']) {
            return false;
        }
        if ($compound['id'] !== null && $compound['id'] !== $facts['id']) {
            return false;
        }
        foreach ($compound['classes'] as $class) {
            if (!in_array($class, $facts['classes'], true)) {
                return false;
            }
        }
        return true;
    }

    /** @return array{tag:?string,id:?string,classes:list<string>} */
    private static function elementFacts(BlockMarkup $doc, int $index): array
    {
        $attrs = $doc->attrs($index) ?? [];
        $tag = is_string($attrs['tagName'] ?? null) ? strtolower($attrs['tagName']) : null;
        if ($tag === null && $doc->name($index) === 'navigation') {
            $tag = 'nav';
        }
        $id = is_string($attrs['anchor'] ?? null) ? $attrs['anchor'] : null;
        $classes = self::elementClassTokens($doc, $index);

        if (preg_match('/<([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*>/s', $doc->ownHtml($index), $wrapper) === 1) {
            $tag = strtolower($wrapper[1]);
            $wrapperId = self::tagAttribute($wrapper[0], 'id');
            if ($wrapperId !== null && trim($wrapperId[0]) !== '') {
                $id = $wrapperId[0];
            }
        }

        return ['tag' => $tag, 'id' => $id, 'classes' => array_values(array_unique($classes))];
    }

    /**
     * Class tokens on a block's own element: its comment className plus the
     * class attribute of its first wrapper tag. Descendant markup is ignored.
     *
     * @return list<string>
     */
    private static function elementClassTokens(BlockMarkup $doc, int $index): array
    {
        $tokens = [];
        $className = ($doc->attrs($index) ?? [])['className'] ?? null;
        if (is_string($className)) {
            $tokens = preg_split('/[\x20\t\r\n\f]+/', trim($className), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (preg_match('/<[a-zA-Z][a-zA-Z0-9-]*\b[^>]*>/s', $doc->ownHtml($index), $tag) === 1) {
            $class = self::tagAttribute($tag[0], 'class');
            if ($class !== null && trim($class[0]) !== '') {
                $tokens = array_merge(
                    $tokens,
                    preg_split('/[\x20\t\r\n\f]+/', trim($class[0]), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                );
            }
        }
        return $tokens;
    }
}
