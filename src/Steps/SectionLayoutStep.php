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
 * removed, and direct cover children opt into full bleed. Nested blocks stay
 * outside this ownership boundary.
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
            reads: ['pages.json', 'theme/parts/*'],
            writes: ['theme/parts/*', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $writes = [];
        $adjustments = 0;
        $warnings = [];

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

    private static function rewriteSection(string $markup, string $label): string
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
            $doc->setAttrs($covers[0], $coverAttrs);
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
}
