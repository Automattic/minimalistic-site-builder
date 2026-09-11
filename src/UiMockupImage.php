<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Draw bounded interface illustrations with placeholder bars and no text. */
final class UiMockupImage
{
    public static function layout(array $spec): ?string
    {
        if (ImageKind::effectiveKind($spec) !== 'ui-mockup' || ($spec['role'] ?? '') === 'site-logo') {
            return null;
        }
        $subject = (string) ($spec['subject'] ?? '');
        foreach ([
            'schedule' => '/\b(?:schedule|scheduling|week grid)\b/i',
            'roster' => '/\b(?:crew roster|member avatars|team roster)\b/i',
            'report' => '/\b(?:daily (?:site )?report|report form|materials count)\b/i',
            'summary' => '/\b(?:client update|project summary|timeline list)\b/i',
        ] as $layout => $pattern) {
            if (preg_match($pattern, $subject)) {
                return $layout;
            }
        }
        return null;
    }

    /** Return null when the local renderer cannot supply this image. */
    public static function render(array $spec, string $ratio, array $theme = []): ?string
    {
        if (!class_exists(\Imagick::class) || self::layout($spec) === null) {
            return null;
        }
        try {
            $image = new \Imagick();
            $image->setFormat('MSVG');
            $image->readImageBlob(self::svg($spec, $ratio, $theme));
            $image->setFormat('jpeg');
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(90);
            $bytes = $image->getImageBlob();
            $image->clear();
            return GeminiImage::mimeFromBytes($bytes) === 'image/jpeg' ? $bytes : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Build SVG from fixed shapes. Subject text never enters the SVG. */
    public static function svg(array $spec, string $ratio, array $theme = []): string
    {
        $layout = self::layout($spec);
        if ($layout === null) {
            throw new \InvalidArgumentException('The UI image has no local layout.');
        }
        $ratios = ['21:9' => 21 / 9, '16:9' => 16 / 9, '4:3' => 4 / 3, '3:2' => 1.5,
            '1:1' => 1, '3:4' => .75, '2:3' => 2 / 3, '9:16' => 9 / 16];
        $width = 1536;
        $height = (int) round($width / ($ratios[$ratio] ?? (16 / 9)));
        $colors = ['base' => '#f0f2f3', 'surface' => '#ffffff', 'contrast' => '#29343b', 'accent' => '#e9a344'];
        foreach ($theme['settings']['color']['palette'] ?? [] as $entry) {
            $slug = $entry['slug'] ?? '';
            if (isset($colors[$slug]) && preg_match('/^#[0-9a-f]{6}$/i', (string) ($entry['color'] ?? ''))) {
                $colors[$slug] = $entry['color'];
            }
        }
        if (preg_match('/\bdark theme\b/i', (string) ($spec['screen_theme'] ?? ''))) {
            $colors['base'] = '#151c25';
            $colors['surface'] = '#242e3a';
            $colors['contrast'] = '#dce3ec';
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height
            . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
        $rect = static function (float $x, float $y, float $w, float $h, string $color, float $opacity = 1, int $radius = 12) use (&$svg): void {
            $svg .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="%d" fill="%s" opacity="%.2f"/>',
                $x, $y, $w, $h, $radius, $color, $opacity);
        };
        $rect(0, 0, $width, $height, $colors['base'], 1, 0);
        $context = (string) ($spec['subject'] ?? '') . ' ' . (string) ($spec['pageContext'] ?? '');
        $left = 72.0;
        $right = $width - 72.0;
        if (preg_match('/left (?:third|half|side).{0,90}(?:empty|calm|negative|low-detail)/i', $context)) {
            $left = $width * .40;
        } elseif (preg_match('/right (?:third|half|side).{0,90}(?:empty|calm|negative|low-detail)/i', $context)) {
            $right = $width * .60;
        }
        $w = $right - $left;
        $top = 64.0;
        $bottom = $height - 64.0;
        $rect($left + 6, $top + 10, $w, $bottom - $top, $colors['contrast'], .08, 24);
        $rect($left, $top, $w, $bottom - $top, $colors['surface'], 1, 24);
        $rect($left + 26, $top + 26, $w * .22, 14, $colors['contrast'], .8, 7);
        $rect($right - 100, $top + 22, 72, 22, $colors['accent'], 1, 8);
        $x = $left + 26;
        $y = $top + 80;
        $contentWidth = $w - 52;
        $contentHeight = $bottom - $y - 26;
        $bar = static function (float $bx, float $by, float $bw, float $opacity = .18) use ($rect, $colors): void {
            $rect($bx, $by, $bw, 10, $colors['contrast'], $opacity, 5);
        };
        if ($layout === 'schedule') {
            $columnWidth = $contentWidth / 5;
            for ($column = 0; $column < 5; $column++) {
                $cx = $x + $column * $columnWidth;
                $bar($cx + 8, $y, $columnWidth * .5, .45);
                for ($row = 0; $row < 3; $row++) {
                    $cy = $y + 36 + $row * (($contentHeight - 36) / 3);
                    $ch = ($contentHeight - 36) / 3 - 12;
                    $rect($cx + 4, $cy, $columnWidth - 12, $ch, $colors['accent'], .12 + .05 * (($column + $row) % 3));
                    $rect($cx + 12, $cy + 14, 4, max(12, $ch - 28), $colors['accent'], .8, 2);
                    $bar($cx + 24, $cy + 18, max(16, $columnWidth - 52), .45);
                    $bar($cx + 24, $cy + 38, max(12, $columnWidth - 70));
                }
            }
        } elseif ($layout === 'roster') {
            $rect($x, $y, $contentWidth, 64, $colors['accent'], .12);
            $bar($x + 20, $y + 18, $contentWidth * .4, .55);
            $bar($x + 20, $y + 38, $contentWidth * .26);
            for ($row = 0; $row < 5; $row++) {
                $cy = $y + 92 + $row * max(40, ($contentHeight - 110) / 5);
                $svg .= sprintf('<circle cx="%.1f" cy="%.1f" r="17" fill="%s" opacity=".4"/>', $x + 20, $cy, $colors['accent']);
                $bar($x + 54, $cy - 9, $contentWidth * (.36 + ($row % 2) * .12), .55);
                $bar($x + 54, $cy + 9, $contentWidth * .24);
                $rect($right - 116, $cy - 10, 52, 20, $colors['accent'], .18, 10);
            }
        } else {
            $rect($x, $y, $contentWidth, 12, $colors['contrast'], .1, 6);
            $rect($x, $y, $contentWidth * .68, 12, $colors['accent'], 1, 6);
            $tileY = $y + 38;
            $tileHeight = $contentHeight * .34;
            for ($column = 0; $column < 2; $column++) {
                $tx = $x + $column * ($contentWidth / 2 + 6);
                $tw = $contentWidth / 2 - 12;
                $rect($tx, $tileY, $tw, $tileHeight, $colors['contrast'], .07);
                $rect($tx + 16, $tileY + $tileHeight * .4, $tw * .4, $tileHeight * .4, $colors['accent'], .2, 3);
                $rect($tx + $tw * .52, $tileY + $tileHeight * .2, $tw * .35, $tileHeight * .6, $colors['contrast'], .12, 3);
            }
            for ($row = 0; $row < 4; $row++) {
                $cy = $tileY + $tileHeight + 32 + $row * (($contentHeight - $tileHeight - 90) / 4);
                $rect($x, $cy, 8, 8, $colors['accent'], $row === 0 ? 1 : .25, 4);
                $bar($x + 24, $cy, $contentWidth * (.48 - ($row % 2) * .08), .5);
                $bar($right - 116, $cy, 60, .25);
            }
        }
        return $svg . '</svg>';
    }
}
