<?php
declare(strict_types=1);

// Run this file with PHP. Save its HTML output outside the repository for browser tests.
require_once __DIR__ . '/../../src/bootstrap.php';

use Automattic\SiteBuild\Surface;

$surfaces = array_slice(Surface::ALL, 1);
?>
<!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Section texture test</title>
<style>
body { margin: 0; font: 18px/1.5 sans-serif; background: #dedede; }
section { box-sizing: border-box; min-height: 250px; padding: 32px; }
.light { background: #ffffff; color: #17191c; }
.dark { background: #17191c; color: #ffffff; }
.black { background: #000000; color: #ffffff; }
.mid { background: #606060; color: #ffffff; }
h2, p { margin: 0 0 16px; }
.sample-image { float: right; width: 180px; height: 120px; }
a { color: inherit; }
<?php foreach ($surfaces as $surface) {
    echo Surface::kitCss($surface, '#17191c', '#ffffff');
} ?>
</style>
<body>
<main class="wp-block-post-content">
<section id="plain-before" class="light"><h2>Plain section</h2><p>This section has no texture.</p></section>
<?php foreach ($surfaces as $surface): foreach (['light', 'dark', 'black', 'mid'] as $ground): ?>
<section id="<?= $surface ?>-<?= $ground ?>" class="surface--<?= $surface ?> <?= $ground ?>">
<img class="sample-image" alt="Opaque color test" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='180' height='120'%3E%3Cpath fill='%23708090' d='M0 0h180v120H0z'/%3E%3C/svg%3E">
<h2><?= ucfirst($surface) ?> on <?= $ground ?></h2>
<p>The texture stays below this text and the image.</p>
<a href="#plain-after">Go to the plain section</a>
</section>
<?php endforeach; endforeach; ?>
<section id="plain-after" class="dark"><h2>Plain section</h2><p>This section also has no texture.</p></section>
</main>
</body>
</html>
