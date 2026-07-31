<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\CurlFontFetcher;
use Automattic\SiteBuild\FontCatalog;
use Automattic\SiteBuild\FontFetcher;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
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
    /** Face formats the build will write; the vendored catalog is woff2 throughout. */
    private const FACE_EXTENSIONS = ['woff2', 'woff', 'ttf', 'otf'];

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
            reads: ['designDirection.json', 'theme/theme.json', 'theme/parts/*', 'theme/templates/*', 'plugin/pages/*'],
            writes: ['theme/theme.json', 'theme/assets/fonts/*', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $theme = $project->readJson('theme/theme.json');
        // The committed direction is a floor for bundling too: a variant the
        // design director selected ships as a face even when no final markup
        // uses it (BIGR-750). FontsPhpStep reports the disposition warnings.
        $directionWarnings = [];
        $requirements = FontsPhpStep::fontRequirements(
            $theme,
            FontsPhpStep::themeMarkup($project),
            DesignDirectionStep::dataFor($project),
            $directionWarnings,
        );
        if ($requirements === []) {
            echo "  no Google-hosted families; nothing to bundle\n";
            return;
        }

        $catalog = $this->catalog ?? FontCatalog::load();
        $fetcher = $this->fetcher ?? new CurlFontFetcher();
        $warnings = [];
        $bundled = 0;

        foreach (FontsPhpStep::googleFamiliesBySlug($theme) as $slug => $name) {
            $family = $catalog->resolve($name);
            if ($family === null) {
                $warnings[] = "font family '{$name}' is not in the Google Fonts catalog; "
                    . 'left on the fonts.php link path';
                continue;
            }

            $faces = $catalog->faces($family, $requirements[$name]['weights'], $requirements[$name]['italic']);
            // A resolvable family can still select nothing (e.g. no normal-style
            // face). Writing fontFace: [] would count it bundled while shipping
            // no fonts — degrade it like a catalog miss instead.
            if ($faces === []) {
                $warnings[] = "font family '{$name}' has no faces for the scanned use; "
                    . 'left on the fonts.php link path';
                continue;
            }

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
                $filename = self::faceFilename((string) $family['slug'], $face);
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
            $project->writeJson('theme/theme.json', $theme);
        }
        $project->addWarnings($this->id(), $warnings);
        echo "  bundle-fonts: {$bundled} family/families bundled"
            . ($warnings !== [] ? ', ' . count($warnings) . ' degraded to the link path (see warnings.json)' : '')
            . "\n";
    }

    /**
     * Name the asset after the resolved family rather than the theme preset that
     * uses it (`heading`, `body`, etc.), so the filename identifies its contents
     * and remains stable when a family moves between roles. Both the catalog slug
     * and the URL-derived extension still cross a filesystem boundary: slugify
     * the former and allowlist the latter. The catalog is woff2 throughout, so
     * the extension allowlist is a no-op on real data.
     *
     * @param array{fontWeight:string,fontStyle:string,src:string} $face
     */
    private static function faceFilename(string $familySlug, array $face): string
    {
        $extension = strtolower(pathinfo((string) parse_url($face['src'], PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($extension, self::FACE_EXTENSIONS, true)) {
            $extension = 'woff2';
        }
        return ProjectStore::slugify($familySlug) . '-' . $face['fontWeight']
            . ($face['fontStyle'] === 'italic' ? '-italic' : '')
            . '.' . $extension;
    }

    /**
     * @param array<mixed> $theme
     * @param array<int,array<string,mixed>> $fontFace
     * @return array<mixed>
     */
    private static function withFontFace(array $theme, string $slug, array $fontFace): array
    {
        $i = FontsPhpStep::familyIndexBySlug($theme, $slug);
        if ($i !== null) {
            $theme['settings']['typography']['fontFamilies'][$i]['fontFace'] = $fontFace;
        }
        return $theme;
    }
}
