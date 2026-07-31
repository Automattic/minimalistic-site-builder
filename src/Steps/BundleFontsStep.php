<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\CurlFontFetcher;
use Automattic\SiteBuild\FontCatalog;
use Automattic\SiteBuild\FontFetcher;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step: bundle the theme's Google families as theme assets declared in
 * theme.json, so shipped sites serve their own fonts instead of sending every
 * visitor's browser to fonts.googleapis.com.
 *
 * The scan from FontsPhpStep is the variant selector: only the weights and
 * italics the build actually uses are downloaded (via the vendored catalog,
 * whose every src is fonts.gstatic.com), written to theme/assets/fonts/, and
 * declared as fontFace entries with file: sources — the Font Library shape, so
 * the fonts work in the editor and users can manage them there.
 *
 * Degradation, per family: not in the catalog, or any face fails to download
 * (all-or-nothing per family — a partial family renders inconsistently), the
 * family is left without fontFace and recorded in warnings.json. FontsPhpStep
 * runs after this step and hotlinks exactly the families that remain
 * unbundled, so the build never ships less than today; a total network outage
 * reproduces today's behavior byte-for-byte.
 */
final class BundleFontsStep implements Step
{
    public function __construct(
        private ?FontFetcher $fetcher = null,
        private ?FontCatalog $catalog = null,
    ) {
    }

    public function id(): string
    {
        return 'bundle-fonts';
    }

    public function label(): string
    {
        return 'Bundle Google Fonts as theme assets';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['theme/theme.json', 'theme/parts/*', 'theme/templates/*'],
            writes: ['theme/theme.json', 'theme/assets/fonts/*', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $theme = $project->readJson('theme/theme.json');
        $requirements = FontsPhpStep::fontRequirements($theme, FontsPhpStep::themeMarkup($project));
        if ($requirements === []) {
            echo "  no Google-hosted families; nothing to bundle\n";
            return;
        }

        $catalog = $this->catalog ?? FontCatalog::load();
        $fetcher = $this->fetcher ?? new CurlFontFetcher();
        $warnings = [];
        $bundled = 0;

        foreach (FontsPhpStep::googleFamiliesBySlug($theme) as $slug => $name) {
            if (!isset($requirements[$name])) {
                continue;
            }
            $family = $catalog->resolve($name);
            if ($family === null) {
                $warnings[] = "font family '{$name}' is not in the Google Fonts catalog; "
                    . 'left on the fonts.php link path';
                continue;
            }

            $faces = $catalog->faces($family, $requirements[$name]['weights'], $requirements[$name]['italic']);

            // Download every face before writing any, so a mid-family failure
            // cannot leave a half-bundled family behind.
            $downloads = [];
            try {
                foreach ($faces as $face) {
                    $downloads[] = [$face, $fetcher->fetch($face['src'])];
                }
            } catch (\Throwable $e) {
                $warnings[] = "font family '{$name}' could not be bundled ({$e->getMessage()}); "
                    . 'left on the fonts.php link path';
                continue;
            }

            $fontFace = [];
            foreach ($downloads as [$face, $bytes]) {
                $filename = self::faceFilename($slug, $face);
                $project->writeText('theme/assets/fonts/' . $filename, $bytes);
                $fontFace[] = [
                    'fontFamily' => $family['name'],
                    'fontStyle'  => $face['fontStyle'],
                    'fontWeight' => $face['fontWeight'],
                    'src'        => ['file:./assets/fonts/' . $filename],
                ];
            }
            $theme = self::withFontFace($theme, $slug, $fontFace);
            ++$bundled;
        }

        if ($bundled > 0) {
            $project->writeJsonAtomic('theme/theme.json', $theme);
        }
        $project->addWarnings($this->id(), $warnings);
        echo "  bundle-fonts: {$bundled} family/families bundled"
            . ($warnings !== [] ? ', ' . count($warnings) . ' degraded to the link path (see warnings.json)' : '')
            . "\n";
    }

    /** @param array{fontWeight:string,fontStyle:string,src:string} $face */
    private static function faceFilename(string $slug, array $face): string
    {
        $extension = strtolower(pathinfo((string) parse_url($face['src'], PHP_URL_PATH), PATHINFO_EXTENSION));
        return $slug . '-' . $face['fontWeight']
            . ($face['fontStyle'] === 'italic' ? '-italic' : '')
            . '.' . ($extension !== '' ? $extension : 'woff2');
    }

    /**
     * @param array<mixed> $theme
     * @param array<int,array<string,mixed>> $fontFace
     * @return array<mixed>
     */
    private static function withFontFace(array $theme, string $slug, array $fontFace): array
    {
        foreach ($theme['settings']['typography']['fontFamilies'] ?? [] as $i => $family) {
            if (is_array($family) && (string) ($family['slug'] ?? '') === $slug) {
                $theme['settings']['typography']['fontFamilies'][$i]['fontFace'] = $fontFace;
                break;
            }
        }
        return $theme;
    }
}
