<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Deterministically give every transformed section one inline-axis owner.
 *
 * SectionRhythmStep owns the root group's block-axis spacing. This sibling
 * pass runs immediately afterwards and owns only the inline axis: every
 * section root becomes constrained, root-authored horizontal padding is
 * removed, and direct cover children or CSS-authored background bands opt into
 * full bleed. Covers also carry the constrained-layout class bridge their inner
 * container needs. Nested blocks stay outside this ownership boundary.
 *
 * Rewrites are transactional per page. A malformed generated section keeps
 * that page's pre-transformation bytes, records one durable warning, and does
 * not prevent healthy sibling pages from being normalized.
 */
final class SectionLayoutStep implements Step
{
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
            reads: ['pages.json', 'theme/parts/*', 'design/site.css'],
            writes: ['theme/parts/*', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $writes = [];
        $adjustments = 0;
        $warnings = [];
        $wideClasses = self::wideClassSet($project);
        $fullBleedClasses = self::fullBleedClassSet($project);

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
                        $wideClasses,
                        $fullBleedClasses,
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

        foreach ($writes as $path => $markup) {
            $project->writeText($path, $markup);
        }
        $project->addWarnings($this->id(), $warnings);

        Narrator::write("  section layout: {$adjustments} section adjustment(s)\n");
        if ($warnings !== []) {
            Narrator::write('  [section-layout] warning: ' . count($warnings)
                . " page degradation(s) recorded in warnings.json\n");
        }
    }

    /**
     * @param array<string,true> $wideClasses class token => true from design/site.css
     * @param array<string,true> $fullBleedClasses class token => true from design/site.css
     */
    private static function rewriteSection(
        string $markup,
        string $label,
        array $wideClasses = [],
        array $fullBleedClasses = [],
    ): string
    {
        $doc = self::validatedDocument($markup, $label);
        $root = (int) $doc->topLevel();
        $attrs = $doc->attrs($root) ?? [];

        $attrs['layout'] = ['type' => 'constrained'];
        self::constrainCommentClass($attrs, $label);
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

        // A constrained layout re-caps its children at contentSize unless the
        // measure-bearing block opts into wide. Align the OUTERMOST block whose
        // element carries a var(--wide-size) class (the root when it bears it,
        // else the inner wrapper that does). A direct cover already runs full,
        // so its explicit align is never downgraded.
        if ($wideClasses !== []) {
            $target = self::outermostWideBlock($doc, $wideClasses);
            if ($target !== null) {
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

    /** The wide-class lookup set from the design stylesheet, empty when absent. @return array<string,true> */
    private static function wideClassSet(Project $project): array
    {
        if (!$project->exists('design/site.css')) {
            return [];
        }
        $set = [];
        foreach (self::wideClassTokens($project->readText('design/site.css')) as $token) {
            $set[$token] = true;
        }
        return $set;
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

    /** Whether a rule body's max-width or width references var(--wide-size). */
    private static function measuresWideSize(string $body): bool
    {
        foreach (explode(';', $body) as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                continue;
            }
            $property = strtolower(trim(substr($declaration, 0, $colon)));
            if (($property === 'max-width' || $property === 'width')
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
     * The first block, in document (outermost-first) order, whose own element
     * carries a wide-class token.
     *
     * @param array<string,true> $wideClasses
     */
    private static function outermostWideBlock(BlockMarkup $doc, array $wideClasses): ?int
    {
        foreach ($doc->indices() as $index) {
            foreach (self::elementClassTokens($doc, $index) as $token) {
                if (isset($wideClasses[$token])) {
                    return $index;
                }
            }
        }
        return null;
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
