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
    // Simulate the unprivileged context (WP-CLI, Playground blueprint):
    // the kses post-content filter is active.
    $GLOBALS['wp_filters'] = ['content_save_pre' => ['wp_filter_post_kses' => 10]];
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
    function wp_update_post(array $post): int
    {
        $id = (int) ($post['ID'] ?? 0);
        if (isset($GLOBALS['wp_posts'][$id])) {
            $GLOBALS['wp_posts'][$id] = array_merge($GLOBALS['wp_posts'][$id], $post);
        }
        return $id;
    }
    function get_page_by_path(string $path)
    {
        foreach ($GLOBALS['wp_posts'] as $id => $post) {
            if (($post['post_name'] ?? '') === $path && ($post['post_type'] ?? '') === 'page') {
                return (object) ['ID' => $id, 'post_status' => (string) ($post['post_status'] ?? '')];
            }
        }
        return null;
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
    function has_filter(string $hook, $callback = false)
    {
        return $GLOBALS['wp_filters'][$hook][$callback] ?? false;
    }
    function remove_filter(string $hook, $callback, int $priority = 10): bool
    {
        $GLOBALS['wp_kses_calls'][] = "remove:{$hook}:{$callback}";
        unset($GLOBALS['wp_filters'][$hook][$callback]);
        return true;
    }
    function add_filter(string $hook, $callback, int $priority = 10): bool
    {
        $GLOBALS['wp_kses_calls'][] = "add:{$hook}:{$callback}";
        $GLOBALS['wp_filters'][$hook][$callback] = $priority;
        return true;
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
    // The menu page smuggles every script-capable vector a hand-edit (or a
    // build-check gap) could carry; seeding must strip them, not store them.
    $project->writeText('plugin/pages/menu.html', '<!-- wp:heading --><h2 onclick="alert(1)">Menu</h2><!-- /wp:heading -->' . "\n"
        . '<!-- wp:html --><script>alert(2)</script><!-- /wp:html -->' . "\n"
        . '<!-- wp:paragraph --><p><a href="javascript:alert(3)">Specials</a> and <a href="/breads/">breads</a>, come on in=side</p><!-- /wp:paragraph -->');
    $project->writeText('plugin/pages/breads.html', '<!-- wp:heading --><h2>Breads</h2><!-- /wp:heading -->');

    wp_stub_reset();
    // A fresh WordPress ships a published "Sample Page" — wp:page-list would
    // show it in the nav next to the seeded pages.
    $GLOBALS['wp_posts'][2] = [
        'post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'Sample Page', 'post_name' => 'sample-page',
    ];
    require_once $project->pluginPath('site-content.php');

    // ── Activation seeds every page in manifest order. ──
    builder_content_activate();

    $posts = $GLOBALS['wp_posts'];
    assert_eq(4, count($posts)); // sample page + 3 seeded
    // The stock sample page is unpublished (not deleted) so it leaves the nav.
    assert_eq('draft', $posts[2]['post_status']);
    $seeded = array_filter($posts, fn (array $p) => ($p['post_name'] ?? '') !== 'sample-page');
    $ids = array_keys($seeded);
    assert_eq(['home', 'menu', 'breads'], array_column($seeded, 'post_name'));
    assert_eq([0, 10, 20], array_column($seeded, 'menu_order'));

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

    // Script-capable markup was stripped before storage; content is intact.
    $menuContent = $byName['menu']['post_content'];
    assert_true(!str_contains($menuContent, '<script'), 'script element removed');
    assert_true(!str_contains($menuContent, 'onclick'), 'event handler removed');
    assert_true(!str_contains($menuContent, 'javascript:'), 'executable URL neutralized');
    assert_contains('come on in=side', $menuContent, 'prose is untouched');
    assert_contains('href="/breads/"', $menuContent, 'legitimate links survive');
    assert_contains('<!-- wp:html -->', $menuContent, 'block comments survive');
    assert_contains('>Menu</h2>', $menuContent, 'element content survives attribute stripping');

    // The seeded homepage became the front page; previous options snapshotted.
    assert_eq('page', get_option('show_on_front'));
    assert_eq($byName['home']['id'], get_option('page_on_front'));
    $state = get_option(BUILDER_CONTENT_STATE_OPTION);
    assert_eq('posts', $state['show_on_front']);
    assert_eq($ids, $state['page_ids']);

    // Only the post-content kses filter was suspended, and only around the
    // seeding — it is back in place afterwards.
    assert_eq(
        ['remove:content_save_pre:wp_filter_post_kses', 'add:content_save_pre:wp_filter_post_kses'],
        $GLOBALS['wp_kses_calls']
    );
    assert_eq(10, has_filter('content_save_pre', 'wp_filter_post_kses'));

    // ── A second activation is a no-op (no duplicate pages, no re-recording). ──
    builder_content_activate();
    assert_eq(4, count($GLOBALS['wp_posts']), 'no duplicates on re-activation');

    // ── Deactivation deletes exactly what was created and restores the rest. ──
    builder_content_deactivate();
    assert_eq([2], array_keys($GLOBALS['wp_posts']), 'only the sample page survives');
    assert_eq('publish', $GLOBALS['wp_posts'][2]['post_status'], 'sample page republished');
    assert_eq('posts', get_option('show_on_front'));
    assert_eq(0, get_option('page_on_front'));
    assert_eq(false, get_option(BUILDER_CONTENT_STATE_OPTION));

    // Deactivating again (state gone) is harmless.
    builder_content_deactivate();

    exec('rm -rf ' . escapeshellarg($tmp));
});

