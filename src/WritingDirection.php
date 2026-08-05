<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Pure normalization for the site's logical writing direction.
 *
 * Direction is caller-owned when explicitly supplied. Otherwise it is
 * derived from a deliberately small reviewed language mapping; unknown and
 * missing languages are LTR. The model never gets to create a competing
 * direction value.
 */
final class WritingDirection
{
    public const VALUES = ['ltr', 'rtl'];

    /** @var list<string> BCP-47 primary language subtags written RTL by default. */
    private const RTL_LANGUAGE_CODES = [
        'ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'iw', 'nqo', 'ps', 'sd', 'syr', 'ug', 'ur', 'yi',
    ];

    /** @var list<string> Plain language names accepted by SiteSpecStep. */
    private const RTL_LANGUAGE_NAMES = [
        'arabic', 'aramaic', 'central kurdish', 'divehi', 'farsi', 'hebrew', 'kurdish sorani',
        'nko', "n'ko", 'pashto', 'persian', 'sindhi', 'sorani', 'syriac', 'uighur', 'urdu',
        'uyghur', 'yiddish',
    ];

    /** Validate an explicit caller value and return its canonical spelling. */
    public static function validate(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('writing_direction must be "ltr" or "rtl"');
        }
        $direction = strtolower(trim($value));
        if (!in_array($direction, self::VALUES, true)) {
            throw new \InvalidArgumentException(
                'writing_direction must be "ltr" or "rtl"; got ' . self::describe($value)
            );
        }
        return $direction;
    }

    /**
     * Resolve the source-of-truth direction from meta + normalized language.
     * Presence of the meta key is authoritative and therefore validated
     * strictly; an invalid caller value is a preflight/configuration error.
     *
     * @param array<mixed> $meta
     */
    public static function resolve(array $meta, string $language): string
    {
        return array_key_exists('writing_direction', $meta)
            ? self::validate($meta['writing_direction'])
            : self::fromLanguage($language);
    }

    /** Derive a direction from a normalized code/name, defaulting to LTR. */
    public static function fromLanguage(string $language): string
    {
        $normalized = strtolower(trim(str_replace('_', '-', $language)));
        if ($normalized === '') {
            return 'ltr';
        }

        $primary = explode('-', $normalized, 2)[0];
        if (in_array($primary, self::RTL_LANGUAGE_CODES, true)) {
            return 'rtl';
        }

        // A few languages can be written in more than one script. Only an
        // explicit Arabic-script subtag makes these RTL; the unscripted code
        // stays LTR instead of guessing from regional prose.
        if (preg_match('/^(?:az|ku|pa)-arab(?:-|$)/', $normalized) === 1) {
            return 'rtl';
        }

        return in_array($normalized, self::RTL_LANGUAGE_NAMES, true) ? 'rtl' : 'ltr';
    }

    private static function describe(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? get_debug_type($value) : $encoded;
    }
}
