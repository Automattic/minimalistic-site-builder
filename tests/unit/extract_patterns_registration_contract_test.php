<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Steps\ExtractPatternsStep;
use Automattic\SiteBuild\Steps\ScaffoldPluginStep;

/** @param list<string> $warnings @return array<string,true> */
function extract_patterns_registered_plugin_blocks(
    ExtractPatternsStep $step,
    Project $project,
    array &$warnings = [],
): array {
    $method = new ReflectionMethod($step, 'registeredPluginBlocks');
    return $method->invokeArgs($step, [$project, &$warnings]);
}

function extract_patterns_registration_seed(Project $project): void
{
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'title' => 'Home',
        'path' => '/',
        'front' => true,
        'menu_order' => 0,
        'parent' => null,
        'sections' => [[
            'slug' => 'details',
            'type' => 'content',
            'role' => 'supporting',
        ]],
    ]]]);
    $project->writeJson('plugin/pages.json', ['pages' => [[
        'slug' => 'home',
        'title' => 'Home',
        'front' => true,
        'menu_order' => 0,
        'parent' => null,
    ]]]);
    $project->writeText(
        'plugin/pages/home.html',
        '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:blocks-engine/description-list --><dl></dl>'
            . '<!-- /wp:blocks-engine/description-list -->'
            . '</div><!-- /wp:group -->',
    );
    $project->writeText('theme/style.css', 'body{color:#fff}');
    $project->writeJson('theme/theme.json', ['version' => 3]);
}

test('registration parser consumes real ScaffoldPluginStep output', function (): void {
    with_project('builder_pattern_registration_', function (Project $project): void {
        (new ScaffoldPluginStep())->run($project);

        assert_eq([
            'blocks-engine/authored-input' => true,
            'blocks-engine/authored-select' => true,
            'blocks-engine/author-layout' => true,
            'blocks-engine/description-list' => true,
        ], extract_patterns_registered_plugin_blocks(new ExtractPatternsStep(), $project));
    });
});

test('one unreadable companion block.json skips that block and keeps the rest', function (): void {
    with_project('builder_pattern_registration_one_bad_', function (Project $project): void {
        $project->writeText(
            ScaffoldPluginStep::MAIN_FILE,
            "<?php\n\$blocks = array(\n"
            . "    'blocks-engine/description-list' => __DIR__ . '/blocks/description-list',\n"
            . "    'blocks-engine/authored-input' => __DIR__ . '/blocks/authored-input',\n"
            . ");\n",
        );
        $project->writeJson('plugin/blocks/description-list/block.json', [
            'name' => 'blocks-engine/description-list',
        ]);
        $project->writeText('plugin/blocks/authored-input/block.json', '{not json');

        $warnings = [];
        $registered = extract_patterns_registered_plugin_blocks(new ExtractPatternsStep(), $project, $warnings);

        assert_eq(['blocks-engine/description-list' => true], $registered);
        assert_contains('plugin/blocks/authored-input/block.json', implode("\n", $warnings));
        assert_true(!isset($registered['blocks-engine/authored-input']));
    });
});

test('non-empty unparseable companion registration warns and continues', function (): void {
    with_project('builder_pattern_registration_warning_', function (Project $project): void {
        extract_patterns_registration_seed($project);
        $project->writeText(ScaffoldPluginStep::MAIN_FILE, "<?php\n\$blocks = array('unparseable');\n");

        (new ExtractPatternsStep())->run($project);

        $warnings = $project->readJson('warnings.json')['extract-patterns'] ?? [];
        assert_true(in_array(
            'plugin/site-content.php: block path "registration array"; authored value "non-empty plugin file"; '
                . 'delivered value "no registered companion blocks"; disposition: continued with companion blocks '
                . 'treated as unregistered',
            $warnings,
            true,
        ));
    });
});
