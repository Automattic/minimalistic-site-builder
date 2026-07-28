<?php
declare(strict_types=1);

use Automattic\SiteBuild\Package;

/**
 * Vendored consumers load only autoload.php (not bootstrap). SiteBuilder must
 * still assemble. Runs in a subprocess so this process's bootstrap can't mask
 * a missing-function fatal.
 */

test('SiteBuilder assembles the pipeline with only the package autoloader loaded', function () {
    $autoload = Package::root() . '/autoload.php';
    $code = 'require ' . var_export($autoload, true) . ';'
        . '$llm = new class implements Automattic\SiteBuild\Llm {'
        . '    public function complete(string $prompt, array $opts = []): string { return ""; }'
        . '    public function completeJson(string $prompt, array $opts = []): array { return []; }'
        . '    public function completeBatch(array $requests): Automattic\SiteBuild\TextBatchResult {'
        . '        return new Automattic\SiteBuild\TextBatchResult([]);'
        . '    }'
        . '    public function completeJsonBatch(array $requests): array { return []; }'
        . '};'
        . '$fixer = new class implements Automattic\SiteBuild\BlockFixer {'
        . '    public function fix(string $themeDir): string { return "noop"; }'
        . '};'
        . '$builder = new Automattic\SiteBuild\SiteBuilder('
        . '    llm: $llm,'
        . '    promptsDir: Automattic\SiteBuild\Package::promptsDir(),'
        . '    outputRoot: sys_get_temp_dir() . "/sb-standalone-" . getmypid(),'
        . '    blockFixer: $fixer,'
        . ');'
        . 'echo implode(",", $builder->pipeline()->stepIds());';

    $out = [];
    $rc = 1;
    exec('php -r ' . escapeshellarg($code) . ' 2>&1', $out, $rc);
    $joined = implode("\n", $out);

    assert_eq(0, $rc, "standalone consumer must not fatal; got:\n{$joined}");
    assert_true(str_contains($joined, 'site-spec'), 'pipeline assembled without bootstrap');
});
