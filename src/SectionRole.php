<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Structural roles shared by page planning and stateless section generation. */
final class SectionRole
{
    public const HERO = 'hero';
    public const CONTENT = 'content';
    public const CLOSING = 'closing';

    /** @var list<string> */
    public const ALL = [self::HERO, self::CONTENT, self::CLOSING];

    /** The deterministic role for one position in an ordered section list. */
    public static function forPosition(int $index, int $count): string
    {
        if ($count < 1 || $index < 0 || $index >= $count) {
            throw new \InvalidArgumentException('section position is outside the page plan');
        }
        return $index === 0
            ? self::HERO
            : ($index === $count - 1 ? self::CLOSING : self::CONTENT);
    }
}
