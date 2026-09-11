<?php
declare(strict_types=1);

/** Offline proof; writes review artifacts outside the checkout by default. */
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/FakeLlm.php';
require_once __DIR__ . '/FakeImageClient.php';

use Automattic\SiteBuild\Pipeline;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\StepComposition;
use Automattic\SiteBuild\Tests\FakeImageClient;
use Automattic\SiteBuild\Tests\FakeLlm;

$root = $argv[1] ?? sys_get_temp_dir() . '/msb-pattern-proof-' . bin2hex(random_bytes(4));
if (!is_dir($root) && !mkdir($root, 0775, true)) {
    throw new RuntimeException('Could not create the proof output directory');
}
if (is_file($root . '/meta.json')) {
    $existing = json_decode(file_get_contents($root . '/meta.json'), true);
    if (($existing['graph'] ?? null) !== 'patterns') {
        throw new RuntimeException('Refusing to reuse an output directory not owned by the pattern proof');
    }
}
$project = new Project($root);
$input = json_decode(file_get_contents(__DIR__ . '/fixtures/patterns/input.json'), true, flags: JSON_THROW_ON_ERROR);
// Portable build-time references; a destination importer relocates these.
$input['facts']['hero_image'] = 'http://localhost/wp-content/uploads/pattern-proof/studio.png';
$input['facts']['contact_url'] = 'http://localhost/contact/';
$project->writeJsonAtomic('meta.json', ['graph' => 'patterns']);
$project->writeJsonAtomic('pattern-inputs.json', $input);
$images = new FakeImageClient();
$project->writeText('media/studio.png', $images->generate('Fixture studio', ['mime' => 'image/png']));
$llm = new FakeLlm();
foreach (json_decode(file_get_contents(__DIR__ . '/fixtures/patterns/responses.json'), true, flags: JSON_THROW_ON_ERROR) as $response) {
    $llm->queueJson($response);
}
$composition = StepComposition::patterns($llm);
$pipeline = new Pipeline($composition->steps(), $composition->seeds());
$pipeline->runThrough($project, reporter: static function ($step, $elapsed): void {
    echo $step->id() . ': ' . number_format($elapsed, 3) . "s\n";
});
$output = $project->readJson('pattern-output.json');
foreach ($output['layouts'] as $layout) {
    $project->writeText($layout['id'] . '.html', $layout['markup']);
}
echo "Fixture proof: {$root}\n";
