<?php

/**
 * Run inside Site Foundry's wp-env CLI container:
 * wp eval-file /var/www/html/wp-content/msb-pattern-tests/integration/site-foundry-pattern-proof.php \
 *   /var/www/html/wp-content/msb-pattern-proof
 */

use Automattic\Site_Foundry\Provision\Blueprint_Interpreter;
use Automattic\Site_Foundry\Provision\Plan_Builder;
use Automattic\Site_Foundry\Provision\Plan_Preflight;

$bundleDir = $args[0] ?? '';
if (!is_dir($bundleDir)) {
    throw new RuntimeException('Proof bundle directory is missing');
}
$bundle = json_decode(file_get_contents($bundleDir . '/content-bundle.json'), true, flags: JSON_THROW_ON_ERROR);
if (($bundle['kind'] ?? null) !== 'wordpress-content' || ($bundle['version'] ?? null) !== 1) {
    throw new RuntimeException('Unsupported MSB content bundle');
}
$content = [];
foreach ($bundle['media'] as $media) {
    $content[] = ['type' => 'posts', 'source' => [
        'post_title' => $media['title'], 'post_name' => $media['slug'],
        'post_status' => 'inherit', 'post_type' => 'attachment',
        'post_mime_type' => $media['mime_type'], 'guid' => $media['url'],
        'meta_input' => ['_wp_attached_file' => $media['upload_path']],
    ]];
}
foreach ($bundle['pages'] as $page) {
    $content[] = ['type' => 'posts', 'source' => [
        'post_title' => $page['title'], 'post_name' => $page['slug'],
        'post_content' => $page['content'], 'post_type' => 'page', 'post_status' => 'publish',
    ]];
}
foreach ($bundle['shared_parts'] as $part) {
    $content[] = ['type' => 'posts', 'source' => [
        'post_title' => $part['title'], 'post_name' => $part['slug'],
        'post_content' => $part['content'], 'post_type' => 'wp_template_part', 'post_status' => 'publish',
        'tax_input' => ['wp_theme' => [$bundle['requirements']['theme']], 'wp_template_part_area' => [$part['area']]],
    ]];
}
$files = [];
foreach ($bundle['media'] as $media) {
    $files['/wordpress/wp-content/uploads/' . $media['upload_path']] = './' . $media['source'];
}
$blueprint = [
    '$schema' => 'https://playground.wordpress.net/blueprint-schema.json', 'version' => 2,
    'activeTheme' => ['source' => $bundle['requirements']['theme'], 'targetDirectoryName' => $bundle['requirements']['theme']],
    'siteOptions' => ['blogname' => $bundle['site']['title'], 'show_on_front' => 'page'],
    'content' => $content,
    'additionalStepsAfterExecution' => [['step' => 'writeFiles', 'files' => $files]],
];
$normalized = Blueprint_Interpreter::normalize($blueprint);
if ($normalized['error'] !== null) {
    throw new RuntimeException($normalized['error']);
}
$plan = Plan_Builder::build($normalized['blueprint']);
$preflight = Plan_Preflight::scan($plan, $bundleDir);
$preferences = [];
foreach ($preflight as $item) {
    $preferences[$item['source']] = ['action' => $item['default_action']];
}
$network = get_network();
$slug = 'pattern-proof-' . gmdate('YmdHis');
if (is_subdomain_install()) {
    $domain = $slug . '.' . preg_replace('/:\d+$/', '', $network->domain);
    $path = '/';
} else {
    $domain = $network->domain;
    $path = trailingslashit($network->path . $slug);
}
$blogId = wpmu_create_blog($domain, $path, 'Pattern Proof', 1, [], (int) $network->id);
if (is_wp_error($blogId)) {
    throw new RuntimeException($blogId->get_error_message());
}
$result = Blueprint_Interpreter::apply((int) $blogId, $plan, $bundleDir, $preferences);
if (!$result['ok']) {
    throw new RuntimeException(implode("\n", $result['errors']));
}

$expectedPage = null;
$expectedPart = null;
foreach ($blueprint['content'] as $item) {
    $post = $item['source'] ?? [];
    if (($post['post_type'] ?? '') === 'page') {
        $expectedPage = $post;
    } elseif (($post['post_type'] ?? '') === 'wp_template_part') {
        $expectedPart = $post;
    }
}
switch_to_blog((int) $blogId);
try {
    if (get_stylesheet() !== $bundle['requirements']['theme']) {
        throw new RuntimeException('Existing network theme was not activated');
    }
    $page = get_page_by_path($expectedPage['post_name'], OBJECT, 'page');
    if (!$page instanceof WP_Post || (int) get_option('page_on_front') !== $page->ID) {
        throw new RuntimeException('Imported home page was not selected as the front page');
    }
    if (count(parse_blocks($page->post_content)) !== 1) {
        throw new RuntimeException('Imported page did not retain its nested root block');
    }
    foreach (['approved-hero', 'padding-top:48px', 'Approved <strong>protected</strong> wording.', 'Make room for good work'] as $needle) {
        if (!str_contains($page->post_content, $needle)) {
            throw new RuntimeException("Imported page lost approved markup: {$needle}");
        }
    }
    if (str_contains($page->post_content, 'http://localhost/')) {
        throw new RuntimeException('Destination URL fixup left portable localhost URLs behind');
    }
    $part = get_page_by_path($expectedPart['post_name'], OBJECT, 'wp_template_part');
    if (!$part instanceof WP_Post || !str_contains($part->post_content, 'Harbor Studio')) {
        throw new RuntimeException('Shared footer was not imported with its binding');
    }
    $attachment = get_page_by_path('studio', OBJECT, 'attachment');
    if (!$attachment instanceof WP_Post) {
        throw new RuntimeException('Image attachment was not imported');
    }
    $relative = (string) get_post_meta($attachment->ID, '_wp_attached_file', true);
    $uploads = wp_upload_dir();
    $written = trailingslashit($uploads['basedir']) . $relative;
    if (!is_file($written) || file_get_contents($written) !== file_get_contents($bundleDir . '/media/studio.png')) {
        throw new RuntimeException('Imported image bytes do not match the generated fixture');
    }
    $siteUrl = get_site_url((int) $blogId);
} finally {
    restore_current_blog();
}

echo wp_json_encode([
    'blog_id' => (int) $blogId,
    'url' => $siteUrl,
    'normalization_version' => $normalized['version'],
    'plan_steps' => count($plan),
    'preflight' => $preflight,
    'apply' => $result,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
