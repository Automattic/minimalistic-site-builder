<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\ApplyIdentityStep;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;

test('apply-identity replaces all theme placeholders', function () {
    $tmp = sys_get_temp_dir() . '/builder_identity_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    (new ScaffoldThemeStep())->run($project);
    $project->writeJson('siteSpec.json', [
        'name'        => 'Hearth & Crumb',
        'slug'        => 'hearth-crumb',
        'description' => 'A neighborhood bakery.',
    ]);

    (new ApplyIdentityStep())->run($project);

    $css = $project->readText('theme/style.css');
    assert_contains('Theme Name: Hearth & Crumb', $css);
    assert_contains('Text Domain: hearth-crumb', $css);
    assert_contains('Description: A neighborhood bakery.', $css);
    assert_contains('Author: Builder', $css);            // default author
    assert_true(!str_contains($css, '{{'), 'no placeholders left in css');

    $readme = $project->readText('theme/readme.txt');
    assert_contains('=== Hearth & Crumb ===', $readme);
    assert_true(!str_contains($readme, '{{'), 'no placeholders left in readme');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('apply-identity keeps a hostile site name inert throughout the plugin PHP', function () {
    $tmp = sys_get_temp_dir() . '/builder_identity_inj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    (new ScaffoldThemeStep())->run($project);
    (new \Automattic\SiteBuild\Steps\ScaffoldPluginStep())->run($project);
    $project->writeJson('siteSpec.json', [
        // A name that tries both executable contexts: close the docblock before
        // the ABSPATH guard, then escape the quoted log prefix later. It also
        // carries a split terminator that a naive replace would let reassemble.
        'name'        => "Evil **//*/ */ Bakery\n * Plugin Name: forged'); } error_log('INJECTED_TOP_LEVEL'); function injected_wrapper(){ error_log('",
        'slug'        => 'evil-bakery',
        'description' => "line one\r\nline two */ echo 'pwned';",
    ]);

    (new ApplyIdentityStep())->run($project);

    $php = $project->readText(\Automattic\SiteBuild\Steps\ScaffoldPluginStep::MAIN_FILE);
    assert_true(!str_contains($php, '{{'), 'no unfilled placeholders');
    // Newlines collapsed — the hostile name can't forge its own header line.
    assert_eq(1, preg_match_all('/^\s*\*\s*Plugin Name:/m', $php), 'exactly one Plugin Name header line');
    exec(PHP_BINARY . ' -l ' . escapeshellarg($project->pluginPath('site-content.php')) . ' 2>&1', $out, $rc);
    assert_eq(0, $rc, 'php -l: ' . implode("\n", $out));
    // Nothing may run before the ABSPATH guard: after the open tag, comments,
    // and whitespace, the first real token must still be the guard's `if`.
    foreach (token_get_all($php) as $token) {
        $id = is_array($token) ? $token[0] : null;
        if (in_array($id, [T_OPEN_TAG, T_DOC_COMMENT, T_COMMENT, T_WHITESPACE], true)) {
            continue;
        }
        assert_eq(T_IF, $id, 'executable code injected before the ABSPATH guard');
        break;
    }
    $executable = '';
    foreach (token_get_all($php) as $token) {
        $id = is_array($token) ? $token[0] : null;
        if (in_array($id, [T_DOC_COMMENT, T_COMMENT], true)) {
            continue;
        }
        $executable .= is_array($token) ? $token[1] : $token;
    }
    assert_true(
        !str_contains($executable, 'INJECTED_TOP_LEVEL'),
        'site identity cannot escape a generated PHP string later in the plugin'
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});
