<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Define the image kinds, image prompts, QA rules, and screen frame CSS. The frame has no window chrome. */
final class ImageKind
{
    public const ALL = ['photo', '3d-object', 'ui-mockup', 'line-illustration', 'abstract-gradient'];

    public const DEFAULT = 'photo';

    public const TILT_CLASS = 'screen-frame--tilt';

    /**
     * The one no-text rule for a product screen, shared by the first-pass
     * prompt and the QA correction so the two can never drift.
     *
     * It enumerates the elements that actually leaked on a generated page —
     * panel titles, table cells and summary figures — and it says what the
     * panel names in the authored subject are FOR. The subject has to name
     * them to describe the layout ("a load-curve chart panel", "a settlement
     * rate table"), and the model letters those very nouns onto the panels,
     * the same way a hex in the prompt gets painted as a label
     * (see screenTheme()).
     */
    public const SCREEN_NO_TEXT =
        'Every text run, panel title, label, heading, legend, axis value, summary figure and table cell is'
        . ' a plain rounded placeholder bar with no glyphs inside it, so the screen carries no readable'
        . ' letters, words or numerals anywhere. The panel names in this description identify the layout for'
        . ' you; they are never lettered onto the screen itself.';

    private const STYLE = [
        'photo'             => 'photorealistic',
        '3d-object'         => '3d-render',
        'ui-mockup'         => 'ui-screenshot',
        'line-illustration' => 'illustration',
        'abstract-gradient' => 'abstract',
    ];

    public static function explicit(mixed $raw): ?string
    {
        return BoundedChoice::explicit($raw, self::ALL);
    }

    public static function styleKeyword(string $kind): string
    {
        return self::STYLE[self::explicit($kind) ?? self::DEFAULT];
    }

    public static function meaning(string $kind): string
    {
        return match ($kind) {
            '3d-object'         => 'smooth matte clay-like 3D objects and simple geometric forms, rendered in soft studio light on plain seamless backdrops; no people, no scenes',
            'ui-mockup'         => 'edge-to-edge screenshots of a contemporary, design-led web application: layered panels, a large chart, tiles and lists as real interface components with text as soft placeholder bars, the screen content only with no window chrome, never a readable word',
            'line-illustration' => 'single-weight line illustrations with two or three flat colours and generous white space, one subject per image',
            'abstract-gradient' => 'soft abstract gradient fields with fine grain and slow colour drift; no objects, no scenes, no text',
            default             => 'photographs, one graded series',
        };
    }

    /**
     * @param string $screenTheme the ui-mockup interface theme sentence from
     *        screenTheme(); other kinds ignore it
     */
    public static function promptClause(?string $raw, bool $transparent = false, string $screenTheme = ''): string
    {
        $kind = self::explicit($raw) ?? self::DEFAULT;

        if ($kind === '3d-object' && $transparent) {
            return 'Imagery kind for all site imagery: one smooth matte clay-like 3D object floating in even,'
                . ' shadowless light, no ground plane, no contact shadow, no cast shadow, no reflection,'
                . ' no people and no environment.';
        }
        if ($transparent) {
            return '';
        }
        return match ($kind) {
            '3d-object'         => 'Imagery kind for all site imagery: smooth matte clay-like 3D objects and simple geometric'
                . ' forms in soft studio light on a plain seamless backdrop, no people and no environment.',

            'ui-mockup'         => 'Imagery kind for all site imagery: an edge-to-edge screenshot of a contemporary,'
                . ' design-led web application, the screen content only, filling the canvas to all four edges'
                . ' with no outer margin and no outer rounded corners. Compose it as a bento-style arrangement'
                . ' of layered panels and cards with soft elevation over a calm ground: one large chart with a'
                . ' smooth gradient fill, a row of summary tiles, a slim icon sidebar, toggle switches, an'
                . ' avatar stack of plain coloured discs, thin hairline dividers, generously rounded corners,'
                . ' ample breathing room and a restrained palette with one vivid accent. '
                . self::SCREEN_NO_TEXT
                . ' Pin-sharp, flat, straight-on and evenly lit'
                . ' at full resolution; no soft focus, no depth-of-field blur, no window frame, no title bar,'
                . ' no traffic-light dots, no browser tabs, no address bar, no bezel, no device, no drop shadow'
                . ' around the screen, no desk, no backdrop, no perspective, no reflections, no grain.'
                . ($screenTheme !== '' ? ' ' . $screenTheme : ''),
            'line-illustration' => 'Imagery kind for all site imagery: a single-weight line illustration with two or three flat'
                . ' colours, generous white space and one subject; no photographic texture, no text.',
            'abstract-gradient' => 'Imagery kind for all site imagery: a soft abstract gradient field with fine grain and'
                . ' slow colour drift, edge to edge; no objects, no scene, no text.',
            default             => '',
        };
    }

    /**
     * Describe the interface theme from the page palette so the screenshots
     * follow the page: a dark page receives a dark interface, a light page a
     * light one, and the site's accent names its hue. The sentence carries no
     * hex code because the image model paints a hex code as a label. An
     * unusable base returns ''.
     */
    public static function screenTheme(?string $baseHex, ?string $accentHex): string
    {
        $ground = GroundKey::classify(trim((string) $baseHex));
        if ($ground === null) {
            return '';
        }
        $hue = self::hueName(trim((string) $accentHex));
        $accentClause = $hue !== null ? ' The single accent colour is ' . $hue . '.' : '';
        return $ground === 'dark'
            ? 'The interface uses a dark theme: a near-black ground, panels one shade lighter than the'
                . ' ground, and light placeholder bars.' . $accentClause
            : 'The interface uses a light theme: an off-white ground, white panels, and dark grey'
                . ' placeholder bars.' . $accentClause;
    }

    /** Name the hue family of a hex colour in plain words, or null for an unusable value. */
    public static function hueName(string $hex): ?string
    {
        $rgb = ContrastMath::hexToRgb($hex);
        if ($rgb === null) {
            return null;
        }
        [$r, $g, $b] = array_map(static fn (int $c): float => $c / 255, $rgb);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;
        $lightness = ($max + $min) / 2;
        $saturation = $delta === 0.0 ? 0.0 : $delta / (1 - abs(2 * $lightness - 1));
        if ($saturation < 0.12 || $lightness < 0.06 || $lightness > 0.94) {
            return 'a neutral grey';
        }
        $h = match (true) {
            $max === $r => fmod(($g - $b) / $delta, 6),
            $max === $g => ($b - $r) / $delta + 2,
            default     => ($r - $g) / $delta + 4,
        } * 60;
        $h = $h < 0 ? $h + 360 : $h;
        return match (true) {
            $h < 15 || $h >= 345 => 'red',
            $h < 45  => 'orange',
            $h < 70  => 'amber',
            $h < 90  => 'lime',
            $h < 165 => 'green',
            $h < 195 => 'teal',
            $h < 215 => 'cyan',
            $h < 250 => 'blue',
            $h < 290 => 'violet',
            $h < 330 => 'magenta',
            default  => 'pink',
        };
    }

    public static function keepsSolidCutout(?string $raw): bool
    {
        return self::explicit($raw) === '3d-object';
    }

    public static function inspectsEveryImage(?string $raw): bool
    {
        return self::explicit($raw) === 'ui-mockup';
    }

    public static function keepsTilt(?string $raw): bool
    {
        return in_array(self::explicit($raw), ['ui-mockup', '3d-object'], true);
    }

    public static function qaUprightRule(?string $raw): string
    {
        $kind = self::explicit($raw);
        if ($kind === 'ui-mockup') {
            return ' This picture is a product-interface mockup, not a photograph: a gentle tilt, a perspective'
                . ' view or a floating angle is the requested framing. Answer false only for a picture that is'
                . ' upside down or rotated a full quarter turn.';
        }
        if ($kind === '3d-object') {
            return ' This picture is a rendered object, not a photograph: a tilt, a floating angle or a'
                . ' perspective view is the requested framing. Answer false only for a picture that is upside'
                . ' down or rotated a full quarter turn.';
        }
        return '';
    }

    /**
     * The correction for a text finding on a product screen.
     *
     * The generic photographic correction ("every surface is plain and
     * unmarked") tells an interface nothing: a screen is MADE of marked
     * panels, so the model reads the sentence as inapplicable and letters the
     * screen again. A generated page proved it — one regeneration left
     * "Load-curve chart", "Settlement rate" and "6.3K" on the panels.
     *
     * The cause is the authored subject itself. It has to name the panels to
     * describe the layout ("a load-curve chart panel", "a settlement rate
     * table"), and the model letters those very nouns onto them, the same way
     * a hex in the prompt gets painted as a label (see screenTheme()). So the
     * correction speaks the interface's own language and says what the panel
     * names are FOR.
     *
     * Returns '' for every other kind, which keeps the photographic wording.
     */
    /** Every kind takes one regeneration; a screen takes more for a text leak. */
    public const REGENERATION_BUDGET = 1;
    public const SCREEN_TEXT_BUDGET = 3;

    /**
     * How many regenerations a finding earns.
     *
     * A text leak on a product screen is the one defect a reworded prompt
     * measurably does not fix: an A/B over nine screens left the first-pass
     * leak rate flat at 6 of 9 clean under the old clause and the reworded one.
     * Every draw is independent and ImageQa reads each one, so extra draws are
     * the lever that moves the DELIVERED rate. At the measured per-draw pass
     * rate, three retries take an expected leak from roughly a third of screens
     * to a few per hundred, and it costs nothing on a screen that passes.
     *
     * The budget is scoped to that one pairing. Every other kind and every
     * other finding keeps the single retry, so no photographic subject starts
     * spending four image calls.
     *
     * @param list<string> $findings
     */
    public static function regenerationBudget(?string $raw, array $findings): int
    {
        if (self::explicit($raw) !== 'ui-mockup') {
            return self::REGENERATION_BUDGET;
        }
        foreach ($findings as $finding) {
            if (str_starts_with($finding, 'rendered text')) {
                return self::SCREEN_TEXT_BUDGET;
            }
        }
        return self::REGENERATION_BUDGET;
    }

    public static function screenTextCorrection(?string $raw): string
    {
        if (self::explicit($raw) !== 'ui-mockup') {
            return '';
        }
        return self::SCREEN_NO_TEXT;
    }

    public static function qaTextRule(?string $raw): string
    {
        if (self::explicit($raw) !== 'ui-mockup') {
            return '';
        }
        return ' This picture is a product-interface mockup: blurred placeholder bars, blocks and abstract'
            . ' chart shapes are NOT text. Answer true only for legible letters, words or numerals.';
    }

    private const PERSON_PATTERN = '/(?<![\p{L}-])(?:portrait|headshot|head-and-shoulders|person|people|woman|women|man|men|face|faces|founder|founders|team photo|avatar|smiling|customer|user|CEO|hands)(?![\p{L}-])/iu';

    public static function namesPerson(string $text): bool
    {
        if (preg_match('/\b(?:portrait|headshot|head-and-shoulders|team photo)\b/iu', $text) === 1) {
            return true;
        }
        if (preg_match('/\b(?:dashboard|interface|screenshot|board|app|screen|status pills|avatar stack)\b/iu', $text) === 1) {
            return false;
        }
        return preg_match(self::PERSON_PATTERN, $text) === 1;
    }

    /** Select the asset kind for the prompt and QA. */
    public static function effectiveKind(array $spec): string
    {
        $kind = self::explicit($spec['image_kind'] ?? null) ?? self::DEFAULT;
        if (($spec['role'] ?? '') === 'site-logo') {
            return 'photo';
        }
        if ($kind === 'ui-mockup' && self::namesPerson((string) ($spec['subject'] ?? '') . ' ' . (string) ($spec['pageContext'] ?? ''))) {
            return 'photo';
        }
        return $kind;
    }

    public static function skipsGrade(?string $raw): bool
    {
        return self::explicit($raw) === 'ui-mockup';
    }

    public static function portraitClause(): string
    {
        return 'Imagery kind for this picture: a photographic portrait of one real person, head and shoulders,'
            . ' natural light, a real room softly out of focus behind them; not an interface, not an icon,'
            . ' not a silhouette, not an abstract or geometric avatar, no text.';
    }

    /**
     * @param array<string,mixed> $spec
     */
    public static function isScreen(array $spec): bool
    {
        $filename = basename((string) ($spec['filename'] ?? ''));
        if ($filename === '' || str_ends_with(strtolower($filename), '.png')) {
            return false;
        }
        $text = (string) ($spec['subject'] ?? '') . ' ' . (string) ($spec['pageContext'] ?? '');
        return !self::namesPerson($text);
    }

    /**
     * @param list<array<string,mixed>> $specs
     * @return list<string>
     */
    public static function offKindFiles(array $specs): array
    {
        $files = [];
        foreach ($specs as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $filename = basename((string) ($spec['filename'] ?? ''));
            if ($filename !== '' && !str_ends_with(strtolower($filename), '.png') && !self::isScreen($spec)) {
                $files[] = $filename;
            }
        }
        return array_values(array_unique($files));
    }

    /**
     * @param list<string> $offKindFiles delivered filenames the frame must skip
     */
    public static function kitCss(?string $raw, array $offKindFiles = []): ?string
    {
        if (self::explicit($raw) !== 'ui-mockup') {
            return null;
        }
        $tilt = self::TILT_CLASS;
        $skip = '';
        foreach ($offKindFiles as $file) {
            $file = str_replace(['\\', '"'], ['\\\\', '\\"'], basename((string) $file));
            if ($file !== '') {
                $skip .= ':not(:has(> img[src$="/' . $file . '"]))';
            }
        }
        return <<<CSS
            :is(.wp-block-image, .card-media, .card-media-tall, .card-media-thumb, .feature-media, .hero-composition__media):not(.wp-block-cover *):not(.is-style-rounded):not([class*="avatar"]):not([class*="logo"]):has(> img:not([src$=".png"])){$skip} {
                position: relative;
                border-radius: var(--shape-radius-panel, 1rem);
                overflow: hidden;
                background: color-mix(in srgb, currentColor 6%, transparent);
                box-shadow:
                    0 1px 2px rgb(0 0 0 / 0.08),
                    0 2.5rem 5rem -2rem rgb(0 0 0 / 0.4);
            }
            :is(.wp-block-image, .card-media, .card-media-tall, .card-media-thumb, .feature-media, .hero-composition__media):not(.wp-block-cover *):not(.is-style-rounded):not([class*="avatar"]):not([class*="logo"]):has(> img:not([src$=".png"])){$skip}::after {
                content: "";
                position: absolute;
                inset: 0;
                border-radius: inherit;
                box-shadow:
                    inset 0 0 0 1px color-mix(in srgb, currentColor 14%, transparent),
                    inset 0 1px 0 rgb(255 255 255 / 0.35);
                pointer-events: none;
            }
            :is(.wp-block-image, .card-media, .card-media-tall, .card-media-thumb, .feature-media):not(.wp-block-cover *):not(.is-style-rounded):not([class*="avatar"]):not([class*="logo"]){$skip} > img:not([src$=".png"]) {
                display: block;
                width: 100%;
                height: auto;
                border-radius: 0;
            }

            :has(> .{$tilt}) {
                perspective: 1400px;
            }
            .{$tilt} {
                rotate: x 6deg;
                transform-origin: 50% 100%;
            }

            :is(:has(.{$tilt}), .{$tilt}) ~ * .{$tilt},
            :is(:has(.{$tilt}), .{$tilt}) ~ .{$tilt} {
                rotate: none;
            }
            @media (max-width: 781px) {
                .{$tilt} {
                    rotate: none;
                }
            }

            CSS;
    }
}
