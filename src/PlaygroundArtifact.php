<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Builds a shareable WordPress Playground Blueprint bundle for one project.
 *
 * The outer ZIP is both the runnable Playground artifact and the debug archive:
 *   blueprint.json
 *   project.zip  (contains project/<slug>/..., the complete generated project)
 */
final class PlaygroundArtifact
{
    public const DEFAULT_ARTIFACT_BRANCH = 'playground-artifacts';

    public static function defaultAssetName(Project $project, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $project->slug() . '-playground-' . $now->format('Ymd\THis\Z') . '.zip';
    }

    public static function artifactUrl(string $repo, string $branch, string $assetName): string
    {
        return 'https://raw.githubusercontent.com/' . $repo
            . '/' . rawurlencode($branch)
            . '/' . rawurlencode($assetName);
    }

    public static function playgroundUrl(string $artifactUrl): string
    {
        return 'https://playground.wordpress.net/?blueprint-url=' . rawurlencode($artifactUrl);
    }

    /**
     * Decode an index.json payload, dropping malformed content and non-object
     * entries so a corrupted index degrades to "no entries" instead of failing.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function parseIndex(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, static fn ($item) => is_array($item)));
    }

    /**
     * Prepend an entry, replacing any previous entry for the same asset.
     *
     * @param array<int,array<string,mixed>> $index
     * @param array<string,mixed> $entry
     * @return array<int,array<string,mixed>>
     */
    public static function updateIndex(array $index, array $entry): array
    {
        $index = array_values(array_filter(
            $index,
            static fn (array $item) => ($item['asset'] ?? null) !== $entry['asset']
        ));
        array_unshift($index, $entry);
        return $index;
    }

    /** @param array<int,array<string,mixed>> $index */
    public static function renderArtifactReadme(array $index): string
    {
        $lines = [
            '# Playground artifacts',
            '',
            'Generated WordPress Playground bundles.',
            '',
            '| Project | Created | ZIP | Playground | Size |',
            '| --- | --- | --- | --- | --- |',
        ];

        if ($index === []) {
            $lines[] = '| _none_ |  |  |  |  |';
        }

        foreach ($index as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $project = self::markdownCell((string) ($entry['project'] ?? $entry['slug'] ?? ''));
            $created = self::markdownCell(self::formatCreatedAt((string) ($entry['created_at'] ?? '')));
            $asset = (string) ($entry['asset'] ?? '');
            $artifactUrl = (string) ($entry['artifact_url'] ?? '');
            $playgroundUrl = (string) ($entry['playground_url'] ?? '');
            $size = self::markdownCell(self::formatBytes((int) ($entry['size_bytes'] ?? 0)));

            $zipLink = $asset !== '' && $artifactUrl !== ''
                ? '[' . self::markdownLinkText($asset) . '](' . $artifactUrl . ')'
                : self::markdownCell($asset);
            $playgroundLink = $playgroundUrl !== '' ? '[Open](' . $playgroundUrl . ')' : '';

            $lines[] = "| {$project} | {$created} | {$zipLink} | {$playgroundLink} | {$size} |";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Blueprint step neutralizing outbound HTTP for the local Playground CLI.
     *
     * The CLI's wasm PHP cannot complete outbound requests, so a block whose
     * render fetches remote content — wp:embed → server-side oEmbed discovery —
     * blocks forever and pins its worker. This mu-plugin makes the local preview
     * fail fast instead. Published browser artifacts use Playground's networking
     * support and must not install this step.
     *
     * @return array<mixed>
     */
    public static function offlineGuardStep(): array
    {
        return [
            'step' => 'writeFile',
            'path' => '/wordpress/wp-content/mu-plugins/0-preview-offline.php',
            'data' => <<<'PHP'
                <?php
                /**
                 * The local Playground CLI cannot complete outbound requests.
                 * Resolve oEmbeds to a plain link and fail any other WordPress
                 * HTTP request fast so a render never pins a worker.
                 */
                add_filter( 'pre_oembed_result', function ( $result, $url ) {
                    return '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>';
                }, 10, 2 );
                add_filter( 'pre_http_request', function () {
                    return new WP_Error( 'http_request_failed', 'Outbound HTTP is disabled in the local Playground preview.' );
                } );
                PHP,
        ];
    }

    /** @return array<mixed> */
    public static function blueprint(Project $project): array
    {
        $options = self::siteOptions($project);

        $steps = [
            ['step' => 'setSiteOptions', 'options' => $options],
            [
                'step' => 'mkdir',
                'path' => '/wordpress/wp-content/builder-project-archive',
            ],
            [
                'step'          => 'unzip',
                'zipFile'       => [
                    'resource' => 'bundled',
                    'path'     => '/project.zip',
                ],
                'extractToPath' => '/wordpress/wp-content/builder-project-archive',
            ],
            [
                'step'     => 'mv',
                'fromPath' => '/wordpress/wp-content/builder-project-archive/project/' . $project->slug() . '/theme',
                'toPath'   => '/wordpress/wp-content/themes/' . $project->slug(),
            ],
            [
                'step'            => 'activateTheme',
                'themeFolderName' => $project->slug(),
            ],
        ];

        // The companion content plugin ships next to the theme and activates
        // AFTER it: the seeder resolves theme:./assets/ refs against the
        // ACTIVE stylesheet when it creates the pages.
        if ($project->exists('plugin/site-content.php')) {
            $pluginDir = $project->slug() . '-content';
            $steps[] = [
                'step'     => 'mv',
                'fromPath' => '/wordpress/wp-content/builder-project-archive/project/' . $project->slug() . '/plugin',
                'toPath'   => '/wordpress/wp-content/plugins/' . $pluginDir,
            ];
            $steps[] = [
                'step'       => 'activatePlugin',
                'pluginPath' => $pluginDir . '/site-content.php',
            ];
        }

        return [
            '$schema'     => 'https://playground.wordpress.net/blueprint-schema.json',
            'landingPage' => '/',
            'login'       => true,
            'steps'       => $steps,
        ];
    }

    /**
     * Build the ZIP and return its absolute path.
     *
     * @throws RuntimeException when the project is incomplete or packaging fails.
     */
    public static function build(Project $project, ?string $assetName = null, ?string $out = null): string
    {
        self::assertTool('zip');

        if (!is_file($project->themePath('style.css'))) {
            throw new \RuntimeException("No built theme at {$project->themePath()} (need style.css).");
        }

        $assetName ??= self::defaultAssetName($project);
        self::assertAssetName($assetName);

        $out ??= sys_get_temp_dir() . '/' . $assetName;
        $out = self::absolutePath($out);
        $outDir = dirname($out);
        if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            throw new \RuntimeException("Could not create output directory: {$outDir}");
        }

        $tmp = sys_get_temp_dir() . '/builder-playground-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $bundleDir = $tmp . '/bundle';
        $projectArchiveDir = $tmp . '/project-archive';

        try {
            self::mkdirp($bundleDir);
            self::mkdirp($projectArchiveDir . '/project');

            $blueprint = json_encode(self::blueprint($project), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($blueprint === false || file_put_contents($bundleDir . '/blueprint.json', $blueprint . "\n") === false) {
                throw new \RuntimeException('Could not write blueprint.json.');
            }

            // The full generated project is stored once, as a nested archive.
            // The Blueprint unzips it and moves project/<slug>/theme into
            // wp-content/themes/<slug>, avoiding a second copy of the theme.
            self::run('cp -a ' . escapeshellarg($project->path()) . ' ' . escapeshellarg($projectArchiveDir . '/project/'));
            self::run('zip -qr ' . escapeshellarg($bundleDir . '/project.zip') . ' .', $projectArchiveDir);

            @unlink($out);
            self::run('zip -qr ' . escapeshellarg($out) . ' .', $bundleDir);
        } finally {
            self::runAllowFailure('rm -rf ' . escapeshellarg($tmp));
        }

        if (!is_file($out)) {
            throw new \RuntimeException("Packaging finished without creating {$out}");
        }

        return $out;
    }

    /**
     * WP site identity for a Blueprint's setSiteOptions step: the header's
     * site-title/site-tagline blocks read these options, not the theme.
     * A malformed siteSpec.json falls back to the theme header/slug.
     *
     * @return array<string,string>
     */
    /**
     * The one rendered text for wp:site-tagline (WP's blogdescription): the
     * user's stated tagline, or nothing. The spec's `topic` is deliberately
     * NOT a fallback (BIGR-773): it is a factual description of the whole
     * site — the same semantic content every hero eyebrow/standfirst carries
     * — so rendering it in the header guarantees a near-duplicate small line
     * ~100px above the hero's. A blank tagline is strictly better; header
     * generation drops the block when this is empty.
     *
     * @param array<string,mixed> $spec
     */
    public static function blogDescription(array $spec): string
    {
        return trim((string) ($spec['tagline'] ?? ''));
    }

    public static function siteOptions(Project $project): array
    {
        $name = self::themeDisplayName($project);
        $blogname = $name !== '' ? $name : $project->slug();
        $blogdescription = '';

        if ($project->exists('siteSpec.json')) {
            try {
                $spec = $project->readJson('siteSpec.json');
            } catch (\RuntimeException) {
                $spec = [];
            }
            $blogname = (string) ($spec['name'] ?? $blogname);
            $blogdescription = self::blogDescription($spec);
        }

        return [
            'blogname'        => $blogname,
            'blogdescription' => $blogdescription,
            // Pretty permalinks so the seeded page tree's paths (/menu/,
            // /menu/breads/) resolve; WP rebuilds rewrite rules lazily and
            // the content plugin flushes them on activation.
            'permalink_structure' => '/%postname%/',
        ];
    }

    /** Theme display name from the style.css header, or '' if absent. */
    public static function themeDisplayName(Project $project): string
    {
        $style = $project->themePath('style.css');
        if (preg_match('/Theme Name:\s*(.+)/', (string) file_get_contents($style), $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private static function assertAssetName(string $assetName): void
    {
        // Allowlist, not basename(): the name lands in git sparse-checkout
        // patterns, git command lines, and markdown, so control characters and
        // leading '-' must be rejected too.
        if (
            !str_ends_with($assetName, '.zip')
            || str_starts_with($assetName, '-')
            || preg_match('/[^A-Za-z0-9._-]/', $assetName)
        ) {
            throw new \RuntimeException('--name must be a .zip filename using letters, numbers, dots, underscores, or hyphens.');
        }
    }

    private static function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return getcwd() . '/' . $path;
    }

    private static function mkdirp(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create directory: {$dir}");
        }
    }

    private static function assertTool(string $bin): void
    {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
        if ($path === '') {
            throw new \RuntimeException("{$bin} is required to build Playground artifacts.");
        }
    }

    private static function run(string $cmd, ?string $cwd = null): void
    {
        $out = [];
        $rc = 0;
        exec(self::commandForCwd($cmd, $cwd) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            throw new \RuntimeException("Command failed ({$rc}): {$cmd}\n" . implode("\n", $out));
        }
    }

    private static function runAllowFailure(string $cmd): void
    {
        exec($cmd . ' 2>/dev/null');
    }

    private static function commandForCwd(string $cmd, ?string $cwd): string
    {
        if ($cwd === null) {
            return $cmd;
        }
        return 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd;
    }

    private static function formatCreatedAt(string $createdAt): string
    {
        if ($createdAt === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($createdAt))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s \U\T\C');
        } catch (\Throwable) {
            return $createdAt;
        }
    }

    /** Human-readable size, or '' for zero/unknown so table cells stay blank. */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'GB') {
                return number_format($value, $value >= 10 ? 1 : 2) . " {$unit}";
            }
            $value /= 1024;
        }
        return $bytes . ' B';
    }

    private static function markdownCell(string $text): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $text);
    }

    private static function markdownLinkText(string $text): string
    {
        return str_replace(["\r", "\n", '[', ']'], [' ', ' ', '\\[', '\\]'], $text);
    }
}
