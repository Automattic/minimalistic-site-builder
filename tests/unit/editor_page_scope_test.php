<?php
declare(strict_types=1);

use Automattic\SiteBuild\PageScope;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\FinalizeThemeStep;

function editor_scope_style(): string
{
    return ':where(.blocks-engine-page-home) .work-grid{display:grid}'
        . ':where(.blocks-engine-page-work) .work-grid{gap:2rem}'
        . ':where(.blocks-engine-page-about, .blocks-engine-page-home, .blocks-engine-page-work) h1{font:revert}'
        . '@media (min-width:700px){:where(.blocks-engine-page-home) .work-grid{grid-template-columns:1fr 1fr}}'
        . ':where(.blocks-engine-page-work) .x, :where(.blocks-engine-page-home) .y{color:red}';
}

test('editorCss keeps one page\'s rules and rewrites them onto the editor canvas', function () {
    $home = PageScope::editorCss(editor_scope_style(), 'home');
    assert_contains(':where(.' . PageScope::EDITOR_WRAPPER_CLASS . ') .work-grid{display:grid}', $home);
    assert_contains('grid-template-columns:1fr 1fr', $home);
    assert_contains('@media (min-width:700px)', $home);
    assert_contains(':where(.' . PageScope::EDITOR_WRAPPER_CLASS . ') h1{font:revert}', $home);
    assert_contains(':where(.' . PageScope::EDITOR_WRAPPER_CLASS . ') .y{color:red}', $home);
    assert_true(!str_contains($home, 'blocks-engine-page-work'), 'sibling page class must not appear');
    assert_true(!str_contains($home, 'blocks-engine-page-about'), 'sibling page class must not appear');
    assert_true(!str_contains($home, 'blocks-engine-page-home'), 'front-end scope is rewritten away');
    assert_true(!str_contains($home, '.x{color:red}'), 'sibling-only selector branch is dropped');
});

test('editorCss for a page with no matching rules is empty and does not throw', function () {
    assert_eq('', PageScope::editorCss(editor_scope_style(), 'contact'));
    assert_eq('', PageScope::editorCss('', 'home'));
});

test('finalize-theme registers the editor filter and emits per-page editor CSS', function () {
    $tmp = sys_get_temp_dir() . '/builder_editor_scope_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText(
        'theme/style.css',
        "/*\nTheme Name: Demo\n*/\n" . editor_scope_style(),
    );
    finalize_static_header($project);

    try {
        quietly(fn () => (new FinalizeThemeStep())->run($project));

        $php = $project->readText('theme/functions.php');
        assert_contains("add_filter('block_editor_settings_all'", $php);
        assert_contains("get_theme_file_path('editor/'", $php);
        assert_contains("get_post_field('post_name'", $php);
        $out = [];
        $rc = 0;
        exec('php -l ' . escapeshellarg($project->themePath('functions.php')) . ' 2>&1', $out, $rc);
        assert_eq(0, $rc, implode("\n", $out));

        $home = $project->readText('theme/editor/home.css');
        assert_contains('.work-grid', $home);
        assert_contains(PageScope::EDITOR_WRAPPER_CLASS, $home);
        assert_true(!str_contains($home, 'blocks-engine-page-work'));

        $work = $project->readText('theme/editor/work.css');
        assert_contains('.work-grid', $work);
        assert_true(!str_contains($work, 'display:grid}'), 'home-only work-grid declaration stays off the work editor sheet');
        assert_true(!str_contains($work, 'blocks-engine-page-home'));

        assert_true(!$project->exists('theme/editor/contact.css'), 'a page with no scoped CSS emits no extra sheet');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
