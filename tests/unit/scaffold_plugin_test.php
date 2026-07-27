<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\MarkupSanitizer;
use Automattic\SiteBuild\Steps\ApplyIdentityStep;
use Automattic\SiteBuild\Steps\ScaffoldPluginStep;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;

require_once __DIR__ . '/../wp-html-api.php';

/**
 * Assert that an HTML string carries nothing a browser would execute.
 *
 * Uses the HTML API as the oracle rather than a regex: `<script` and
 * `href="javascript:..."` both appear harmlessly as prose in this corpus, and
 * a string match calls those a leak.
 */
function assert_inert(string $html, string $context): void
{
    $processor = WP_HTML_Processor::create_fragment($html);
    assert_true($processor !== null, "{$context}: output is parseable");
    if ($processor === null) {
        return;
    }
    $urls = array_merge(wp_kses_uri_attributes(), ['xlink:href']);
    while ($processor->next_tag()) {
        if ($processor->get_tag() === 'SCRIPT') {
            assert_eq('text/plain', $processor->get_attribute('type'), "{$context}: script is inert");
            assert_eq('', $processor->get_modifiable_text(), "{$context}: script body is empty");
        }
        foreach ((array) $processor->get_attribute_names_with_prefix('on') as $name) {
            assert_true(false, "{$context}: event handler {$name} survived");
        }
        foreach ($urls as $name) {
            $value = $processor->get_attribute($name);
            assert_true(
                !is_string($value) || !preg_match('/\\A\\s*(?:javascript|vbscript|data)\\s*:/i', $value),
                "{$context}: executable URL survived in {$name}",
            );
        }
    }
}

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
    $GLOBALS['wp_attachments'] = [];
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
    function wp_upload_bits(string $name, $deprecated, string $bits): array
    {
        $dir = sys_get_temp_dir() . '/wp-stub-uploads';
        @mkdir($dir);
        $file = $dir . '/' . $name;
        file_put_contents($file, $bits);
        return ['file' => $file, 'url' => 'http://example.test/wp-content/uploads/2026/07/' . $name, 'error' => false];
    }
    function wp_check_filetype(string $file): array
    {
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        return ['ext' => $ext, 'type' => $ext === 'png' ? 'image/png' : 'image/jpeg'];
    }
    function wp_insert_attachment(array $args, string $file): int
    {
        $id = $GLOBALS['wp_next_id']++;
        $GLOBALS['wp_attachments'][$id] = $args + ['file' => $file];
        return $id;
    }
    function wp_generate_attachment_metadata(int $id, string $file): array
    {
        return ['file' => basename($file)];
    }
    function wp_update_attachment_metadata(int $id, array $meta): bool
    {
        if (isset($GLOBALS['wp_attachments'][$id])) {
            $GLOBALS['wp_attachments'][$id]['meta'] = $meta;
        }
        return true;
    }
    function wp_delete_attachment(int $id, bool $force = false): bool
    {
        unset($GLOBALS['wp_attachments'][$id]);
        return true;
    }
}

/** The generated content plugin's function name for a slug (namespaced per site). */
function content_fn(string $slug, string $suffix): string
{
    return \Automattic\SiteBuild\Steps\ApplyIdentityStep::identifierPrefix($slug) . '_content_' . $suffix;
}

test('identifierPrefix always yields a valid PHP identifier', function () {
    assert_eq('hearth_crumb', ApplyIdentityStep::identifierPrefix('hearth-crumb'));
    // A slug may start with a digit, which no PHP function/constant name may.
    assert_eq('builder_24_hour_diner', ApplyIdentityStep::identifierPrefix('24-hour-diner'));
});

/** Scaffold + identity-fill a project and return it (plugin ready to include). */
function scaffold_plugin_fixture(string $slug = 'hearth-crumb'): array
{
    $tmp = sys_get_temp_dir() . '/builder_plug_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', [
        'name' => 'Hearth & Crumb', 'slug' => $slug,
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
    // Path-traversal guard on images.json filenames (basename + charset + realpath).
    assert_contains('basename($filename)', $php);
    assert_contains('realpath($path)', $php);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('content plugin skips path-shaped image filenames from images.json', function () {
    $slug = 'skips-images';
    [$project, $tmp] = scaffold_plugin_fixture($slug);

    $project->writeJson('plugin/pages.json', ['pages' => [
        ['slug' => 'home', 'title' => 'Home', 'front' => true, 'menu_order' => 0, 'parent' => null],
    ]]);
    $project->writeText('plugin/pages/home.html', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->');
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => '../site-content.php', 'title' => 'traversal'],
        ['filename' => 'not/a/basename.jpg', 'title' => 'slash'],
        ['filename' => '..%2fpasswd.jpg', 'title' => 'encoded'],
        'not-an-array',
        ['filename' => 'ok.jpg', 'title' => 'missing file'],
    ]]);

    wp_stub_reset();
    require_once $project->pluginPath('site-content.php');
    (content_fn($slug, 'activate'))();

    assert_eq(0, count($GLOBALS['wp_attachments']), 'path-shaped or invalid filenames never imported');

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

test('two generated sites define disjoint symbols and can coexist on one host', function () {
    // The real failure: two generated content plugins on one WordPress host
    // both defined builder_content_activate() → fatal redeclare, and the
    // second site's pages were seeded from the first site's directory.
    $a = scaffold_plugin_fixture('roastery-niebla');
    $b = scaffold_plugin_fixture('pottery-barro');

    require_once $a[0]->pluginPath('site-content.php');
    require_once $b[0]->pluginPath('site-content.php'); // would fatal on a shared name

    assert_true(function_exists(content_fn('roastery-niebla', 'activate')), 'site A seeder defined');
    assert_true(function_exists(content_fn('pottery-barro', 'activate')), 'site B seeder defined under a different name');
    assert_true(
        content_fn('roastery-niebla', 'activate') !== content_fn('pottery-barro', 'activate'),
        'the two sites name their seeders differently'
    );

    exec('rm -rf ' . escapeshellarg($a[1]));
    exec('rm -rf ' . escapeshellarg($b[1]));
});

test('generated seeder neutralizes the same threats as the intake sanitizer', function () {
    if (!load_wp_html_api()) {
        skip_test('no WordPress copy found for the HTML API; set SITEBUILD_WP_PATH');
    }
    $slug = 'sanitizer-parity';
    [$project, $tmp] = scaffold_plugin_fixture($slug);
    require_once $project->pluginPath('site-content.php');
    $sanitize = content_fn($slug, 'sanitize');

    $corpus = [
        '<img src=x onerror="E()">',
        '<svg/onload=E()>',
        '<svg id="x"/onload=\'E()\'>',
        '<div class="x"onclick=E()>',
        '<div id=a onload="x"class=y>t</div>',
        '<div id=a onload="x"onclick="y"class=z>t</div>',
        '<svg =" /onload=E()>',
        '<a href=javascript:alert(1)>x</a>',
        '<a/href=javascript:alert(1)>x</a>',
        '<a href="java&#x73;cript:alert(1)">x</a>',
        '<a href=jav&#97;script:alert(1)>x</a>',
        '<a href=javascript&colon;alert(1)>x</a>',
        "<a href=\"java\tscript:alert(1)\">x</a>",
        '<a href="java&#9;script:alert(1)">x</a>',
        '<img src=data:text/html,x>',
        '<form action=vbscript:x></form>',
        '<svg><a xlink:href=data:text/html,x>x</a></svg>',
        '<a =" /href=javascript:E()>x</a>',
        '<img src=x/onerror=not-an-attr>',
        '<a href="https://example.com">safe</a>',
        '<a href="&amp;#106;avascript:x">one decode only</a>',
        '<!-- href="javascript:x" --> prose href="javascript:x"',
        '<script><!--<script></script><!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph --></script><p>After</p>',
        // Tokenizer boundaries. Each of these is inert in a browser only if
        // both copies agree on where the tag, the bogus comment, the CDATA,
        // and the raw-text body start and end.
        '<p>a < b <script>E()</script></p>',
        '<p>x < y <base href="https://evil.example/">z</p>',
        '<div><![CDATA[><script>E()</script>]]></div>',
        '<div><![CDATA[x> <img src=x onerror=E()></div>',
        '<div><![CDATA[ never closed <img src=x onerror=E()>',
        '<div><! " ><img src=x onerror=E()> " ></div>',
        '<div><!bogus <img src=x onerror=E()>',
        '<svg><title><img src=x onerror=E()></title></svg>',
        '<svg><style><img src=x onerror=E()></style></svg>',
        '<svg><foreignObject><iframe srcdoc="&lt;script&gt;E()&lt;/script&gt;"></iframe></foreignObject></svg>',
        '<svg/><title>a <b> b</title><img src=x onerror=E()>',
        '<svg><![CDATA[<img src=x onerror=E()>]]></svg>',
        '<math><mtext><img src=x onerror=E()></mtext></math>',
        '<title>a <b> b</title><img src=x onerror=E()>',
        '</ b ><script>E()</script>',
        '<p>a</ b <script>E()</script></p>',
        '<div><svg><script>E()</script></svg></div>',
        '<xmp><img src=x onerror=E()></xmp>',
        // A comment ends at -->, at --!>, and immediately for <!--> / <!--->.
        '<div><!--><script>E()</script></div>',
        '<div><!---><script>E()</script></div>',
        '<div><!----><script>E()</script></div>',
        '<div><!-- c --!><script>E()</script></div>',
        '<div><!-- c --><script>E()</script></div>',
        '<div><!-- never closed <script>E()</script>',
        '<p>a<!-->b</p><a href=javascript:E()>x</a>',
        '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph --><!--><img src=x onerror=E()>',
        // Foreign-content breakout, and raw-text bodies vs tag removal.
        '<div><svg><p><![CDATA[x><img src=q onerror=E()>]]></svg></div>',
        '<div><svg><![CDATA[x><img src=q onerror=E()>]]></svg></div>',
        '<div><svg><![CDATA[x> <img src=q onerror=E()></div>',
        '<div><svg><div><![CDATA[x><base href="//evil/">]]></svg></div>',
        '<svg><img src=x onerror=E()><title><b>t</b></title></svg>',
        '<div><style>a{c:"<!--"}</style><base href="//evil/"></div>',
        '<div><title><!--</title><embed src=x></div>',
        '<div><textarea><!--</textarea><base href="//evil/"></div>',
        '<style>a{c:"<base>"}</style>',
    ];
    foreach ($corpus as $html) {
        // The two no longer agree byte-for-byte and are not meant to: the
        // build has no WordPress and scans the markup itself, while the seeder
        // drives WP_HTML_Processor. What has to hold on both is that nothing
        // executable survives, so that is what is asserted.
        foreach ([
            'intake' => MarkupSanitizer::sanitize($html),
            'seeder' => $sanitize($html),
        ] as $which => $out) {
            assert_inert($out, "{$which}: {$html}");
        }
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the seeder plugin creates, fronts, and removes the site pages', function () {
    if (!load_wp_html_api()) {
        skip_test('no WordPress copy found for the HTML API; set SITEBUILD_WP_PATH');
    }
    $slug = 'seeder-pages';
    [$project, $tmp] = scaffold_plugin_fixture($slug);

    // Hand-written content bundle (assemble-pages writes these in a real build).
    $project->writeJson('plugin/pages.json', ['pages' => [
        ['slug' => 'home', 'title' => 'Home', 'front' => true, 'menu_order' => 0, 'parent' => null],
        ['slug' => 'menu', 'title' => 'Menu', 'front' => false, 'menu_order' => 10, 'parent' => null],
        ['slug' => 'breads', 'title' => 'Breads', 'front' => false, 'menu_order' => 20, 'parent' => 'menu'],
    ]]);
    $project->writeText('plugin/pages/home.html', '<!-- wp:heading --><h2>Welcome</h2><!-- /wp:heading -->' . "\n"
        . '<!-- wp:image {"sizeSlug":"full"} --><figure class="wp-block-image size-full">'
        . '<img src="theme:./assets/hero.jpg" alt="AI_IMAGE: a bakery | hero | photo | landscape"/></figure><!-- /wp:image -->' . "\n"
        . '<!-- wp:cover {"url":"theme:./assets/hero.jpg"} -->'
        . '<div class="wp-block-cover" style="background-image:url(theme:./assets/hero.jpg)"></div><!-- /wp:cover -->' . "\n"
        . '<img src="theme:./assets/never-generated.jpg" alt="AI_IMAGE: skipped | x | photo | landscape">');
    // The menu page smuggles every script-capable vector a hand-edit (or a
    // build-check gap) could carry; seeding must strip them, not store them.
    $project->writeText('plugin/pages/menu.html', '<!-- wp:heading --><h2 onclick="alert(1)">Menu</h2><!-- /wp:heading -->' . "\n"
        . '<!-- wp:html --><script>alert(2)</script><!-- /wp:html -->' . "\n"
        . '<!-- wp:paragraph --><p><a href="javascript:alert(3)">Specials</a> and <a href="/breads/">breads</a>, come on in=side</p><!-- /wp:paragraph -->'
        . "\n<object><object>inner</object>nested_object_body()</object>"
        . "\n<script data-x=\"</script>\">quoted_script_body()</script>"
        . "\n<noscript>noscript_body()</noscript>"
        . "\n<img src=x\" onerror=malformed_handler()>"
        . "\n<script data-x=foo\"><span x=\">malformed_attribute_body()</script>"
        . "\n<script>unterminated_script_body()");
    $project->writeText('plugin/pages/breads.html', '<!-- wp:heading --><h2>Breads</h2><!-- /wp:heading -->');
    // The content-image bundle: hero.jpg shipped; never-generated.jpg listed
    // but absent (a build without --with-images), so it must fall back to the
    // theme's assets URL.
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => 'hero.jpg', 'title' => 'A bakery at dawn'],
        ['filename' => 'never-generated.jpg', 'title' => 'Skipped'],
    ]]);
    $project->writeText('plugin/images/hero.jpg', 'JPEGDATA');

    wp_stub_reset();
    // A fresh WordPress ships a published "Sample Page" — wp:page-list would
    // show it in the nav next to the seeded pages.
    $GLOBALS['wp_posts'][2] = [
        'post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'Sample Page', 'post_name' => 'sample-page',
    ];
    require_once $project->pluginPath('site-content.php');

    // ── Activation seeds every page in manifest order. ──
    (content_fn($slug, 'activate'))();

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

    // ── Bundled content images imported into the media library. ──
    assert_eq(1, count($GLOBALS['wp_attachments']), 'one bundled image imported');
    $attId = array_keys($GLOBALS['wp_attachments'])[0];
    $att = $GLOBALS['wp_attachments'][$attId];
    assert_eq('A bakery at dawn', $att['post_title']);
    assert_eq('inherit', $att['post_status']);
    assert_eq(['file' => 'hero.jpg'], $att['meta'], 'attachment metadata generated');

    $home = $byName['home']['post_content'];
    // The wp:image block carries the attachment id (unknown until import) and
    // the paired wp-image class, and loads from the media library.
    assert_contains('"id":' . $attId, $home);
    assert_contains('wp-image-' . $attId, $home);
    assert_contains('http://example.test/wp-content/uploads/2026/07/hero.jpg', $home);
    // The cover's url attr and inline background got the plain URL swap.
    assert_true(!str_contains($home, 'theme:./assets/hero.jpg'), 'no placeholder left for the imported image');
    assert_contains('background-image:url(http://example.test/wp-content/uploads/2026/07/hero.jpg)', $home);
    // An image the build never generated falls back to the theme's assets.
    assert_contains('https://example.test/wp-content/themes/demo/assets/never-generated.jpg', $home);

    // Script-capable markup was stripped before storage; content is intact.
    $menuContent = $byName['menu']['post_content'];
    // Neutralized in place, not deleted: deleting a tag joins its
    // neighbours, and that seam can spell a tag the browser never parsed.
    assert_inert($menuContent, 'seeded menu page');
    assert_true(!str_contains($menuContent, 'alert(2)'), 'script body removed');
    assert_true(!str_contains($menuContent, 'onclick'), 'event handler removed');
    assert_true(!str_contains($menuContent, 'javascript:'), 'executable URL neutralized');
    assert_true(!str_contains($menuContent, 'unterminated_script_body'), 'unclosed script body removed');
    assert_true(!str_contains($menuContent, 'nested_object_body'), 'nested object body removed');
    assert_true(!str_contains($menuContent, 'quoted_script_body'), 'a quoted fake closer cannot expose a script body');
    assert_true(!str_contains($menuContent, 'noscript_body'), 'noscript fallback body removed');
    assert_true(!str_contains($menuContent, 'malformed_handler'), 'malformed unquoted attribute cannot hide an event handler');
    assert_true(!str_contains($menuContent, 'malformed_attribute_body'), 'malformed attribute quote state cannot expose a script body');
    assert_contains('come on in=side', $menuContent, 'prose is untouched');
    assert_contains('href="/breads/"', $menuContent, 'legitimate links survive');
    assert_contains('<!-- wp:html -->', $menuContent, 'block comments survive');
    assert_contains('>Menu</h2>', $menuContent, 'element content survives attribute stripping');

    // The seeded homepage became the front page; previous options snapshotted.
    assert_eq('page', get_option('show_on_front'));
    assert_eq($byName['home']['id'], get_option('page_on_front'));
    $state = get_option(\Automattic\SiteBuild\Steps\ApplyIdentityStep::identifierPrefix($slug) . '_content_state');
    assert_eq('posts', $state['show_on_front']);
    assert_eq($ids, $state['page_ids']);
    assert_eq([$attId], $state['attachment_ids'], 'imported attachments recorded');

    // Only the post-content kses filter was suspended, and only around the
    // seeding — it is back in place afterwards.
    assert_eq(
        ['remove:content_save_pre:wp_filter_post_kses', 'add:content_save_pre:wp_filter_post_kses'],
        $GLOBALS['wp_kses_calls']
    );
    assert_eq(10, has_filter('content_save_pre', 'wp_filter_post_kses'));

    // ── A second activation is a no-op (no duplicate pages or attachments). ──
    (content_fn($slug, 'activate'))();
    assert_eq(4, count($GLOBALS['wp_posts']), 'no duplicates on re-activation');
    assert_eq(1, count($GLOBALS['wp_attachments']), 'no duplicate imports on re-activation');

    // ── Deactivation deletes exactly what was created and restores the rest. ──
    (content_fn($slug, 'deactivate'))();
    assert_eq([2], array_keys($GLOBALS['wp_posts']), 'only the sample page survives');
    assert_eq('publish', $GLOBALS['wp_posts'][2]['post_status'], 'sample page republished');
    assert_eq([], $GLOBALS['wp_attachments'], 'imported media removed');
    assert_eq('posts', get_option('show_on_front'));
    assert_eq(0, get_option('page_on_front'));
    assert_eq(false, get_option(\Automattic\SiteBuild\Steps\ApplyIdentityStep::identifierPrefix($slug) . '_content_state'));

    // Deactivating again (state gone) is harmless.
    (content_fn($slug, 'deactivate'))();

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the seeder reports when it degrades or refuses to store markup', function () {
    if (!load_wp_html_api()) {
        skip_test('no WordPress copy found for the HTML API; set SITEBUILD_WP_PATH');
    }
    $slug = 'seeder-logging';
    [$project, $tmp] = scaffold_plugin_fixture($slug);
    require_once $project->pluginPath('site-content.php');
    $sanitize = content_fn($slug, 'sanitize');

    $log = $tmp . '/php-error.log';
    $previous = ini_get('error_log');
    ini_set('error_log', $log);
    $read = static function () use ($log): string {
        return is_file($log) ? (string) file_get_contents($log) : '';
    };

    // A clean page says nothing: the log is for problems only.
    $sanitize('<!-- wp:paragraph --><p>Fine.</p><!-- /wp:paragraph -->', "page 'clean'");
    assert_eq('', $read(), 'a clean page logs nothing');

    // Which pathological input trips which internal limit is a WordPress
    // implementation detail — <plaintext> is unsupported, and the tree
    // processor's bookmark ceiling moved between 6.9 and 7.0. So assert the
    // contract rather than the trigger: nothing executable is ever stored,
    // refusing to store says so, and every note names the page.
    $pathological = [
        '<plaintext><img src=x onerror="E()">',
        str_repeat('<div>', 200) . '<img src=x onerror="E()">',
        str_repeat('<a', 12),
        '<svg><title><img src=x onerror="E()"></title></svg>',
    ];
    foreach ($pathological as $i => $html) {
        file_put_contents($log, '');
        $out = $sanitize($html, "page 'p{$i}'");

        assert_true(!str_contains($out, 'onerror='), "case {$i}: no handler stored");
        if ($out === '') {
            assert_contains(
                'stored empty rather than unreviewed markup',
                $read(),
                "case {$i}: refusing to store is reported",
            );
        }
        if ($read() !== '') {
            assert_contains("page 'p{$i}'", $read(), "case {$i}: the note names the page");
        }
    }

    ini_set('error_log', (string) $previous);
    exec('rm -rf ' . escapeshellarg($tmp));
});
