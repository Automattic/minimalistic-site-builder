<?php
declare(strict_types=1);

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

    public static function defaultAssetName(Project $project, ?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
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

    /** @return array<mixed> */
    public static function blueprint(Project $project): array
    {
        $options = self::siteOptions($project);

        return [
            '$schema'     => 'https://playground.wordpress.net/blueprint-schema.json',
            'landingPage' => '/',
            'login'       => true,
            'steps'       => [
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
            ],
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
            throw new RuntimeException("No built theme at {$project->themePath()} (need style.css).");
        }

        $assetName ??= self::defaultAssetName($project);
        self::assertAssetName($assetName);

        $out ??= sys_get_temp_dir() . '/' . $assetName;
        $out = self::absolutePath($out);
        $outDir = dirname($out);
        if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            throw new RuntimeException("Could not create output directory: {$outDir}");
        }

        $tmp = sys_get_temp_dir() . '/builder-playground-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $bundleDir = $tmp . '/bundle';
        $projectArchiveDir = $tmp . '/project-archive';

        try {
            self::mkdirp($bundleDir);
            self::mkdirp($projectArchiveDir . '/project');

            $blueprint = json_encode(self::blueprint($project), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($blueprint === false || file_put_contents($bundleDir . '/blueprint.json', $blueprint . "\n") === false) {
                throw new RuntimeException('Could not write blueprint.json.');
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
            throw new RuntimeException("Packaging finished without creating {$out}");
        }

        return $out;
    }

    /** @return array<string,string> */
    private static function siteOptions(Project $project): array
    {
        $name = self::themeDisplayName($project);
        $blogname = $name !== '' ? $name : $project->slug();
        $blogdescription = '';

        if ($project->exists('siteSpec.json')) {
            $spec = $project->readJson('siteSpec.json');
            $blogname = (string) ($spec['name'] ?? $blogname);
            $blogdescription = (string) ($spec['tagline'] ?? $spec['topic'] ?? '');
        }

        return [
            'blogname'        => $blogname,
            'blogdescription' => $blogdescription,
        ];
    }

    private static function themeDisplayName(Project $project): string
    {
        $style = $project->themePath('style.css');
        if (preg_match('/Theme Name:\s*(.+)/', (string) file_get_contents($style), $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private static function assertAssetName(string $assetName): void
    {
        if ($assetName === '' || basename($assetName) !== $assetName || !str_ends_with($assetName, '.zip')) {
            throw new RuntimeException('--name must be a ZIP filename, not a path.');
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
            throw new RuntimeException("Could not create directory: {$dir}");
        }
    }

    private static function assertTool(string $bin): void
    {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
        if ($path === '') {
            throw new RuntimeException("{$bin} is required to build Playground artifacts.");
        }
    }

    private static function run(string $cmd, ?string $cwd = null): void
    {
        $out = [];
        $rc = 0;
        exec(self::commandForCwd($cmd, $cwd) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            throw new RuntimeException("Command failed ({$rc}): {$cmd}\n" . implode("\n", $out));
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
            return (new DateTimeImmutable($createdAt))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s \U\T\C');
        } catch (Throwable) {
            return $createdAt;
        }
    }

    private static function formatBytes(int $bytes): string
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
