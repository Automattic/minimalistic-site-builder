#!/usr/bin/env php
<?php
declare(strict_types=1);

use Automattic\SiteBuild\Pipeline;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ReplayLlm;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\StepComposition;

require_once dirname(__DIR__) . '/autoload.php';

$options = getopt('', ['input:', 'output:', 'responses:']);
$inputPath = isset($options['input']) ? (string) $options['input'] : '';
$outputPath = isset($options['output']) ? (string) $options['output'] : '';
$responsesPath = isset($options['responses']) ? (string) $options['responses'] : '';

if ($inputPath === '' || $outputPath === '' || $responsesPath === '') {
    Narrator::write("Usage: php bin/pattern-build.php --input=<pattern-inputs.json> --output=<project-dir> --responses=<responses.json>\n");
    exit(2);
}

try {
    $readJson = static function (string $path): array {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("Could not read {$path}");
        }
        $value = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new InvalidArgumentException("Expected a JSON object or array in {$path}");
        }
        return $value;
    };

    $input = $readJson($inputPath);
    $responses = $readJson($responsesPath);
    if (!array_is_list($responses)) {
        throw new InvalidArgumentException('Fixture responses must be a JSON list');
    }
    if (!is_dir($outputPath) && !mkdir($outputPath, 0775, true) && !is_dir($outputPath)) {
        throw new RuntimeException("Could not create {$outputPath}");
    }

    $project = new Project($outputPath);
    $project->writeJsonAtomic('meta.json', ['graph' => StepComposition::GRAPH_PATTERNS]);
    $project->writeJsonAtomic('pattern-inputs.json', $input);

    $inputRoot = dirname(realpath($inputPath) ?: $inputPath);
    foreach (($input['media'] ?? []) as $media) {
        $source = is_array($media) ? ($media['source'] ?? null) : null;
        if (!is_string($source) || $source === '') {
            continue;
        }
        $from = $inputRoot . '/' . $source;
        if (!is_file($from)) {
            throw new RuntimeException("Missing fixture media source: {$from}");
        }
        $target = $project->path($source);
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new RuntimeException("Could not create media directory for {$target}");
        }
        if (!copy($from, $target)) {
            throw new RuntimeException("Could not copy fixture media to {$target}");
        }
    }

    $llm = new ReplayLlm($responses);
    $composition = StepComposition::patterns($llm);
    $pipeline = new Pipeline($composition->steps(), $composition->seeds());
    $pipeline->runThrough($project, reporter: static function ($step, $elapsed): void {
        fwrite(STDOUT, $step->id() . ': ' . number_format($elapsed, 3) . "s\n");
    });
    if ($llm->remaining() !== 0) {
        throw new RuntimeException('Fixture response file contains unused responses');
    }
    fwrite(STDOUT, "Content bundle: {$outputPath}/content-bundle.json\n");
} catch (Throwable $error) {
    Narrator::write($error->getMessage() . "\n");
    exit(1);
}
