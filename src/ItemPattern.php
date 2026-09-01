<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Code-owned catalog for the site's repeated-item idiom.
 *
 * The design direction commits one value, PagePlanStep assigns it only to
 * list-like sections, and SectionUnit exposes exactly one matching recipe to
 * the section author. Delivery checks are advisory: generated markup that
 * misses the recipe remains usable and is shipped with an actionable warning.
 */
final class ItemPattern
{
    /**
     * A numbered/lettered index is deliberately absent: identifier columns
     * ("01", "02", …) are banned unless the site brief asks for them
     * (BIGR-949). Legacy 'index' commitments normalize to the default.
     *
     * @var list<string>
     */
    public const ALL = ['card', 'rule-row', 'spec-table', 'tag-cluster'];

    public const DEFAULT = 'card';
    public const MARKER_PREFIX = 'item-pattern--';
    public const ITEM_MARKER = 'item-pattern__item';

    /** @var array<string,string> */
    private const RECIPES = [
        'card'        => 'item-patterns/card.md',
        'rule-row'    => 'item-patterns/rule-row.md',
        'spec-table'  => 'item-patterns/spec-table.md',
        'tag-cluster' => 'item-patterns/tag-cluster.md',
    ];

    public static function isKnown(string $pattern): bool
    {
        return isset(self::RECIPES[$pattern]);
    }

    public static function assertKnown(string $pattern): void
    {
        if (!self::isKnown($pattern)) {
            throw new \InvalidArgumentException(
                "unknown item pattern '{$pattern}' (use one of: " . implode(', ', self::ALL) . ')'
            );
        }
    }

    public static function recipeTemplate(string $pattern): string
    {
        self::assertKnown($pattern);
        return self::RECIPES[$pattern];
    }

    public static function marker(string $pattern): string
    {
        self::assertKnown($pattern);
        return self::MARKER_PREFIX . $pattern;
    }

    /**
     * The direction boundary always returns one executable commitment.
     *
     * @param list<string> $warnings
     */
    public static function normalize(mixed $authored, array &$warnings = []): string
    {
        return BoundedChoice::normalize(
            $authored,
            self::ALL,
            self::DEFAULT,
            'item_pattern',
            $warnings,
            'unsupported generated repeated-item idiom replaced by default',
        );
    }

    public static function explicit(mixed $authored): ?string
    {
        return BoundedChoice::explicit($authored, self::ALL);
    }

    /**
     * Advisory verification for one assigned section. The root marker is
     * repaired by SectionUnit when that is semantics-safe; missing repeated
     * item hooks are reported without rewriting generated content.
     *
     * @return list<string>
     */
    public static function markupWarnings(string $markup, string $pattern, string $part): array
    {
        self::assertKnown($pattern);
        $document = BlockMarkup::parse($markup);
        $root = $document->topLevel();
        $rootClasses = $root === null ? [] : self::classTokens($document, $root);
        $warnings = [];

        $marker = self::marker($pattern);
        if (!in_array($marker, $rootClasses, true)) {
            $warnings[] = self::warning(
                $part,
                'item-pattern root marker',
                ['required_class' => $marker],
                ['root_classes' => $rootClasses],
                'safe parseable section was retained; restore the assigned item-pattern marker',
            );
        }

        $items = 0;
        foreach ($document->indices() as $index) {
            if (in_array(self::ITEM_MARKER, self::classTokens($document, $index), true)) {
                $items++;
            }
        }
        if ($items < 2) {
            $warnings[] = self::warning(
                $part,
                'repeated-item recipe',
                ['pattern' => $pattern, 'required_item_class' => self::ITEM_MARKER, 'minimum' => 2],
                ['marked_item_count' => $items],
                'safe parseable section was retained for later item-pattern repair; copy was not invented or removed',
            );
        }

        return $warnings;
    }

    /** @return list<string> */
    private static function classTokens(BlockMarkup $document, int $index): array
    {
        return preg_split(
            '/\s+/',
            trim((string) (($document->attrs($index) ?? [])['className'] ?? '')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
    }

    private static function warning(
        string $part,
        string $block,
        mixed $authored,
        mixed $delivered,
        string $disposition,
    ): string {
        return "file='theme/parts/{$part}.html'; block=" . self::describe($block)
            . '; authored=' . self::describe($authored)
            . '; delivered=' . self::describe($delivered)
            . '; disposition=' . $disposition;
    }

    private static function describe(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? get_debug_type($value) : $encoded;
    }
}
