<?php
declare(strict_types=1);

/**
 * Test doubles and fixture helpers shared by more than one test file. Loaded
 * from lib.php so any single unit test file can run on its own. Helpers used
 * by only one file stay in that file.
 */

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\ConcurrentStep;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\SiteBuilder;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Tests\FakeLlm;

/** A minimal ConcurrentStep that records what it consumes. */
final class RecordingConcurrentStep implements ConcurrentStep
{
    public array $consumed = [];

    /**
     * @param array<string,array<string,mixed>> $requests
     * @param list<string>                      $reads
     * @param list<string>                      $writes
     */
    public function __construct(
        private string $id,
        private array $requests,
        private array $reads = [],
        private array $writes = [],
    ) {}

    public function id(): string { return $this->id; }
    public function label(): string { return $this->id; }
    public function requests(Project $project): array { return $this->requests; }
    public function consume(Project $project, array $results): void { $this->consumed = $results; }
    public function run(Project $project): void {}
    public function declaration(): StepDeclaration
    {
        return new StepDeclaration($this->id, $this->id, $this->reads, $this->writes, false);
    }
}

function valid_theme_payload(): array
{
    return [
        'version' => 2, // should be forced to 3
        'settings' => [
            'color' => ['palette' => [
                ['slug' => 'base', 'color' => '#fff', 'name' => 'Base'],
                ['slug' => 'contrast', 'color' => '#111', 'name' => 'Contrast'],
                ['slug' => 'primary', 'color' => '#2f6b4f', 'name' => 'Primary'],
                ['slug' => 'secondary', 'color' => '#4f6f48', 'name' => 'Secondary'],
                ['slug' => 'accent', 'color' => '#8b5a2b', 'name' => 'Accent'],
                ['slug' => 'band', 'color' => '#E6E6E6', 'name' => 'Band'],
            ]],
            'typography' => ['fontFamilies' => [
                ['slug' => 'heading', 'fontFamily' => 'Fraunces, serif', 'name' => 'Heading'],
                ['slug' => 'body', 'fontFamily' => 'Source Sans 3, sans-serif', 'name' => 'Body'],
            ]],
        ],
    ];
}

/** @param array<string,string> $models */
function make_test_builder(FakeLlm $llm, string $outputRoot, ?BlockFixer $fixer = null, array $models = []): SiteBuilder
{
    $fixer ??= new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            return '[fix-templates] noop';
        }
    };

    return new SiteBuilder(
        llm: $llm,
        promptsDir: Package::promptsDir(),
        outputRoot: $outputRoot,
        blockFixer: $fixer,
        models: $models,
    );
}

/** @return array{0:Project,1:FakeLlm,2:string} */
function make_sitespec_fixture(bool $multiPage = false, ?array $pages = null, ?array $siteSpec = null): array
{
    $tmp = sys_get_temp_dir() . '/builder_sitespec_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $meta = ['prompt' => 'A cozy neighborhood bakery', 'multi_page' => $multiPage];
    if ($pages !== null) {
        $meta['pages'] = $pages;
    }
    if ($siteSpec !== null) {
        $meta['site_spec'] = $siteSpec;
    }
    $project->writeJson('meta.json', $meta);
    return [$project, new FakeLlm(), $tmp];
}

/** @param array<string,string> $files relative theme path => bytes */
function php_block_fixer_test_theme(array $files = []): string
{
    $root = sys_get_temp_dir() . '/php-block-fixer-' . bin2hex(random_bytes(8));
    $theme = $root . '/theme';
    if (!mkdir($theme, 0775, true) && !is_dir($theme)) {
        throw new RuntimeException('Could not create PHP block fixer test theme');
    }
    foreach ($files as $relative => $content) {
        $path = $theme . '/' . $relative;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Could not create PHP block fixer fixture directory');
        }
        if (file_put_contents($path, $content) !== strlen($content)) {
            throw new RuntimeException('Could not write PHP block fixer fixture');
        }
    }
    return $theme;
}
