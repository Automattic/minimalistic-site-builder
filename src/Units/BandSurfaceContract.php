<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

/**
 * Resolve the semantic `tinted` section assignment to the committed `band`
 * palette role. A generated styling defect never costs the section: failure
 * restores its normalized pre-transformation bytes and emits an actionable
 * warning for the later repair pass.
 */
final class BandSurfaceContract
{
    public static function enforce(string $markup, string $background, string $part): MarkupResult
    {
        if ($background !== 'tinted') {
            return new MarkupResult($markup);
        }

        $before = $markup;
        $notes = [];
        try {
            $markup = GeneratedMarkup::withRootBackgroundColor($markup, 'band', $notes, $part);
        } catch (\Throwable $error) {
            return new MarkupResult($before, [], [
                "file='generated section {$part}'; block='root'; authored=tinted; "
                    . 'delivered=pre-transformation markup; disposition=band surface could not be '
                    . 'enforced without risking section content (' . $error->getMessage() . ')',
            ]);
        }

        $repairs = [];
        if ($markup !== $before) {
            $repairs[] = self::repair('tinted-band-surface-enforced', $part);
        }
        return new MarkupResult($markup, $repairs, $notes);
    }

    /** @return array<string,string> */
    private static function repair(string $code, string $part): array
    {
        return ['code' => $code, 'part' => $part, 'disposition' => 'repaired'];
    }
}
