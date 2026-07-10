<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\ApplyIdentityStep;
use Automattic\SiteBuild\Steps\ScaffoldPluginStep;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;

/**
 * Unit tests for the content-seeder plugin: the deterministic scaffold, the
 * identity fill, and — via a tiny in-process WordPress stub — the actual
 * activation/deactivation behavior of the generated plugin code.
 */

// ── Minimal WordPress stubs so the plugin file can be included and driven. ──
// State lives in $GLOBALS so tests can inspect and reset it.

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}

function wp_stub_reset(): void
{
    $GLOBALS['wp_options'] = ['show_on_front' => 'posts', 'page_on_front' => 0];
    $GLOBALS['wp_posts'] = [];
    $GLOBALS['wp_next_id'] = 100;
    $GLOBALS['wp_kses_calls'] = [];
}

if (!function_exists('get_option')) {
    function get_option(string $key, $default = false)
    {
        return $GLOBALS['wp_options'][$key] ?? $default;
    }
    function update_option(string $key, $value): bool
    {
        $GLOBALS['wp_options'][$key] = $value;
        return true;
    }
    function delete_option(string $key): bool
    {
        unset($GLOBALS['wp_options'][$key]);
        return true;
    }
    function wp_insert_post(array $post, bool $wp_error = false): int
    {
        $id = $GLOBALS['wp_next_id']++;
        $GLOBALS['wp_posts'][$id] = $post;
        return $id;
    }
    function wp_delete_post(int $id, bool $force = false): bool
    {
        unset($GLOBALS['wp_posts'][$id]);
        return true;
    }
    function is_wp_error($thing): bool
    {
        return false;
    }
    function get_stylesheet_directory_uri(): string
    {
        return 'https://example.test/wp-content/themes/demo';
    }
    function trailingslashit(string $s): string
    {
        return rtrim($s, '/') . '/';
    }
    function kses_remove_filters(): void
    {
        $GLOBALS['wp_kses_calls'][] = 'remove';
    }
    function kses_init_filters(): void
    {
        $GLOBALS['wp_kses_calls'][] = 'init';
    }
    function flush_rewrite_rules(): void
    {
    }
    function register_activation_hook(string $file, callable $cb): void
    {
    }
    function register_deactivation_hook(string $file, callable $cb): void
    {
    }
}

/** Scaffold + identity-fill a project and return it (plugin ready to include). */
function scaffold_plugin_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_plug_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', [
        'name' => 'Hearth & Crumb', 'slug' => 'hearth-crumb',
        'description' => 'Artisan bread.', 'language' => 'en',
    ]);
    (new ScaffoldThemeStep())->run($project);
    (new ScaffoldPluginStep())->run($project);
    (new ApplyIdentityStep())->run($project);
    return [$project, $tmp];
}

test('scaffold-plugin writes the static seeder with identity placeholders', function () {
    $tmp = sys_get_temp_dir() . '/builder_plugscaf_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    (new ScaffoldPluginStep())->run($project);

    $php = $project->readText(ScaffoldPluginStep::MAIN_FILE);
    assert_contains('Plugin Name: {{THEME_NAME}} Content', $php);
    assert_contains('Text Domain: {{THEME_SLUG}}-content', $php);
    assert_contains('register_activation_hook', $php);
    assert_contains('register_deactivation_hook', $php);
    assert_contains("if (!defined('ABSPATH'))", $php);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('apply-identity fills the plugin header and the code lints', function () {
    [$project, $tmp] = scaffold_plugin_fixture();

    $php = $project->readText(ScaffoldPluginStep::MAIN_FILE);
    assert_contains('Plugin Name: Hearth & Crumb Content', $php);
    assert_contains('Text Domain: hearth-crumb-content', $php);
    assert_true(!str_contains($php, '{{'), 'no unfilled placeholders');

    exec(PHP_BINARY . ' -l ' . escapeshellarg($project->pluginPath('site-content.php')) . ' 2>&1', $out, $rc);
    assert_eq(0, $rc, 'php -l: ' . implode("\n", $out));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the seeder plugin creates, fronts, and removes the site pages', function () {
    [$project, $tmp] = scaffold_plugin_fixture();

    // Hand-written content bundle (assemble-pages writes these in a real build).
    $project->writeJson('plugin/pages.json', ['pages' => [
        ['slug' => 'home', 'title' => 'Home', 'front' => true, 'menu_order' => 0, 'parent' => null],
        ['slug' => 'menu', 'title' => 'Menu', 'front' => false, 'menu_order' => 10, 'parent' => null],
        ['slug' => 'breads', 'title' => 'Breads', 'front' => false, 'menu_order' => 20, 'parent' => 'menu'],
    ]]);
    $project->writeText('plugin/pages/home.html', '<!-- wp:heading --><h2>Welcome</h2><!-- /wp:heading -->' . "\n"
        . '<img src="theme:./assets/hero.jpg" alt="AI_IMAGE: a bakery | hero | photo | landscape">');
    $project->writeText('plugin/pages/menu.html', '<!-- wp:heading --><h2>Menu</h2><!-- /wp:heading -->');
    $project->writeText('plugin/pages/breads.html', '<!-- wp:heading --><h2>Breads</h2><!-- /wp:heading -->');

    wp_stub_reset();
    require_once $project->pluginPath('site-content.php');

    // ── Activation seeds every page in manifest order. ──
    builder_content_activate();

    $posts = $GLOBALS['wp_posts'];
    assert_eq(3, count($posts));
    $ids = array_keys($posts);
    assert_eq(['home', 'menu', 'breads'], array_column($posts, 'post_name'));
    assert_eq([0, 10, 20], array_column($posts, 'menu_order'));

    // The child page hangs off its parent's freshly created id.
    $byName = [];
    foreach ($posts as $id => $post) {
        $byName[$post['post_name']] = ['id' => $id] + $post;
    }
    assert_eq(0, $byName['menu']['post_parent']);
    assert_eq($byName['menu']['id'], $byName['breads']['post_parent']);

    // Asset refs resolved against the active theme at seed time.
    assert_contains('https://example.test/wp-content/themes/demo/assets/hero.jpg', $byName['home']['post_content']);
    assert_true(!str_contains($byName['home']['post_content'], 'theme:./assets/'), 'no theme: refs left');

    // The seeded homepage became the front page; previous options snapshotted.
    assert_eq('page', get_option('show_on_front'));
    assert_eq($byName['home']['id'], get_option('page_on_front'));
    $state = get_option(BUILDER_CONTENT_STATE_OPTION);
    assert_eq('posts', $state['show_on_front']);
    assert_eq($ids, $state['page_ids']);

    // kses was bypassed only around the seeding.
    assert_eq(['remove', 'init'], $GLOBALS['wp_kses_calls']);

    // ── A second activation is a no-op (no duplicate pages). ──
    builder_content_activate();
    assert_eq(3, count($GLOBALS['wp_posts']), 'no duplicates on re-activation');

    // ── Deactivation deletes exactly what was created and restores options. ──
    builder_content_deactivate();
    assert_eq([], $GLOBALS['wp_posts']);
    assert_eq('posts', get_option('show_on_front'));
    assert_eq(0, get_option('page_on_front'));
    assert_eq(false, get_option(BUILDER_CONTENT_STATE_OPTION));

    // Deactivating again (state gone) is harmless.
    builder_content_deactivate();

    exec('rm -rf ' . escapeshellarg($tmp));
});
