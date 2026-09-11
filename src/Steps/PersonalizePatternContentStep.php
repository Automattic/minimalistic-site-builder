<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\GeneratedJsonException;
use Automattic\SiteBuild\GeneratedJsonFallbackStep;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Patterns\ContentPersonalizer;
use Automattic\SiteBuild\Patterns\PatternInputs;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\StepDeclaration;

/** Batches supplied layouts, then retries only invalid slots once. */
final class PersonalizePatternContentStep implements GeneratedJsonFallbackStep
{
    public function __construct(private Llm $llm) {}
    public function id(): string { return 'personalize-pattern-content'; }
    public function label(): string { return 'Personalize approved content slots'; }
    public function declaration(): StepDeclaration
    {
        return new StepDeclaration($this->id(), $this->label(),
            ['meta.json', 'pattern-inputs.json', 'pattern-content.json'],
            ['pattern-values.json', 'logs/pattern-content-requests.json', 'warnings.json'], true);
    }

    public function requests(Project $project): array
    {
        $input = PatternInputs::read($project);
        $prepared = PatternInputs::retained($project, 'pattern-content.json', $input);
        $requests = [];
        foreach ($prepared['layouts'] as $id => $layout) {
            if ($layout['pending'] !== []) {
                $requests[$id] = ContentPersonalizer::request($layout['pending'], $input['facts']);
            }
        }
        return $requests;
    }

    public function run(Project $project): void
    {
        $requests = $this->requests($project);
        try {
            $results = $requests === [] ? [] : $this->llm->completeJsonBatch($requests);
        } catch (GeneratedJsonException $error) {
            $this->consumeGeneratedJsonFailure($project, $error->partialResults, $error->failures);
            return;
        }
        $this->consume($project, $results);
    }

    public function consumeGeneratedJsonFailure(Project $project, array $results, array $failures): void
    {
        $this->deliver($project, $results, $failures);
    }

    public function consume(Project $project, array $results): void
    {
        $this->deliver($project, $results, []);
    }

    private function deliver(Project $project, array $results, array $failures): void
    {
        $input = PatternInputs::read($project);
        $prepared = PatternInputs::retained($project, 'pattern-content.json', $input);
        $layouts = $prepared['layouts'];
        $requests = $this->requests($project);
        foreach (array_keys($results + $failures) as $key) {
            if (!isset($requests[$key])) {
                throw new \LogicException("Pattern batch returned unknown request key {$key}");
            }
        }
        $attempts = [['requests' => $requests, 'results' => $results, 'failures' => $failures]];
        $retry = [];
        foreach ($layouts as $id => &$layout) {
            $consumed = ContentPersonalizer::consume($layout['pending'], $results[$id] ?? []);
            $layout['values'] += $consumed['values'];
            $layout['pending'] = $consumed['pending'];
            $layout['warnings'] = array_merge($layout['warnings'], $consumed['warnings']);
            if ($layout['pending'] !== []) {
                $retry[$id] = ContentPersonalizer::request($layout['pending'], $input['facts']);
            }
        }
        unset($layout);
        if ($retry !== []) {
            $retryFailures = [];
            try {
                $retryResults = $this->llm->completeJsonBatch($retry);
            } catch (GeneratedJsonException $error) {
                $retryResults = $error->partialResults;
                $retryFailures = $error->failures;
            }
            foreach (array_keys($retryResults + $retryFailures) as $key) {
                if (!isset($retry[$key])) {
                    throw new \LogicException("Pattern retry returned unknown request key {$key}");
                }
            }
            $attempts[] = ['requests' => $retry, 'results' => $retryResults, 'failures' => $retryFailures];
            foreach ($retry as $id => $_request) {
                $consumed = ContentPersonalizer::consume($layouts[$id]['pending'], $retryResults[$id] ?? []);
                $layouts[$id]['values'] += $consumed['values'];
                $fallback = ContentPersonalizer::finish($consumed['pending']);
                $layouts[$id]['values'] += $fallback['values'];
                $layouts[$id]['warnings'] = array_merge($layouts[$id]['warnings'], $consumed['warnings'], $fallback['warnings']);
            }
        }
        $values = [];
        $warnings = [];
        foreach ($layouts as $id => $layout) {
            $values[$id] = $layout['values'];
            foreach ($layout['warnings'] as $warning) {
                $warnings[] = json_encode(['file' => 'pattern-output.json', 'layout' => $id] + $warning, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        $project->writeJsonAtomic('logs/pattern-content-requests.json', $attempts);
        $project->writeJsonAtomic('pattern-values.json', [
            'version' => PatternInputs::VERSION, 'input_hash' => PatternInputs::fingerprint($input), 'layouts' => $values,
        ]);
        $project->replaceWarnings($this->id(), $warnings);
    }
}
