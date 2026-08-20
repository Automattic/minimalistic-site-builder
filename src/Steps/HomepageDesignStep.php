<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\ContinuationRecovery;
use Automattic\SiteBuild\DesignMarkupSanitizer;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\MalformedDesignException;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TruncatedGenerationException;

/**
 * Designs one complete homepage from the computed design direction. Single-shot
 * by default; the seeded tournament and the source-critique loop only run when a
 * caller raises design_candidates or critique_rounds. Candidate fan-out is the
 * only concurrent call; critique and whole-document recovery calls stay serial.
 */
final class HomepageDesignStep implements Step
{
    use LlmOptions;

    private const DEFAULT_CANDIDATES = 1;
    private const DEFAULT_CRITIQUE_ROUNDS = 0;
    private const VOID_ELEMENTS = [
        'area',
        'base',
        'br',
        'col',
        'embed',
        'hr',
        'img',
        'input',
        'link',
        'meta',
        'param',
        'source',
        'track',
        'wbr',
    ];
    private const RAW_TEXT_ELEMENTS = [
        'script',
        'style',
        'title',
        'textarea',
    ];
    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
    ) {}

    public function id(): string
    {
        return 'homepage-design';
    }

    public function label(): string
    {
        return 'Design homepage';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['meta.json', 'siteSpec.json', 'designDirection.json'],
            writes: ['design/*', 'warnings.json'],
            concurrent: true,
        );
    }

    public function run(Project $project): void
    {
        $meta = $project->readJson('meta.json');
        $brief = trim((string) ($meta['prompt'] ?? ''));
        if ($brief === '') {
            throw new \RuntimeException('meta.json has no "prompt"');
        }

        $siteSpec = $project->readText('siteSpec.json');
        $designDirection = $project->readText('designDirection.json');
        $candidateCount = self::intKnob(
            $meta,
            'design_candidates',
            self::DEFAULT_CANDIDATES,
            1,
        );
        $critiqueRounds = self::intKnob(
            $meta,
            'critique_rounds',
            self::DEFAULT_CRITIQUE_ROUNDS,
            0,
        );

        $warnings = [];
        try {
            $seeds = $this->candidateSeeds($brief, $siteSpec, $candidateCount, $warnings);
            $language = SiteSpecStep::languageOf($project);
            $requests = [];
            foreach ($seeds as $index => $seed) {
                $requests[$index] = $this->withOptions([
                    'prompt' => $this->renderer->render('homepage-design.md', [
                        'brief'            => $brief,
                        'site_spec'        => $siteSpec,
                        'design_direction' => $designDirection,
                        'language'         => $language,
                        'seed'             => $seed,
                    ]),
                ]);
            }

            $batch = $this->llm->completeBatch($requests);
            $candidates = [];
            foreach (array_keys($requests) as $index) {
                if (!array_key_exists($index, $batch->texts)) {
                    throw new \RuntimeException("homepage-design: missing candidate result {$index}");
                }
                $candidate = $batch->texts[$index];
                $candidatePath = 'design/candidate-' . ($index + 1) . '.html';
                if (!self::isClosedDocument($candidate)) {
                    $candidate = $this->recoverCandidate(
                        $candidate,
                        $index,
                        $candidatePath,
                        $warnings,
                    );
                }
                $candidate = DesignMarkupSanitizer::sanitize(
                    $candidate,
                    $candidatePath,
                    'tournament candidate ' . ($index + 1),
                    $warnings,
                );
                if (!self::isClosedDocument($candidate)) {
                    $candidate = self::closeDocumentDeterministically($candidate);
                    $candidate = DesignMarkupSanitizer::sanitize(
                        $candidate,
                        $candidatePath,
                        'deterministic candidate closure',
                        $warnings,
                    );
                    $warnings[] = "malformed_design: {$candidatePath} context tournament candidate "
                        . ($index + 1) . '; authored unclosed regeneration delivered DOM-closed '
                        . 'document; disposition repaired';
                }
                $candidates[$index] = $candidate;
                $project->writeText($candidatePath, $candidate);
                foreach ($batch->notesFor($index) as $note) {
                    $warnings[] = "candidate_generation: {$candidatePath} delivered with degraded "
                        . "generation: {$note}";
                }
            }

            // A single candidate has nothing to compare, so the judge call and its
            // verdict file are skipped rather than paid for.
            if (count($candidates) > 1) {
                $judge = $this->judge($candidates, $warnings);
                $project->writeJson('design/judge.json', $judge);
                $winner = $this->winnerIndex($judge, count($candidates), $warnings);
            } else {
                $winner = array_key_first($candidates);
            }
            $document = $candidates[$winner];

            for ($round = 1; $round <= $critiqueRounds; $round++) {
                $critique = $this->critique($document, $round, $warnings);
                $project->writeJson("design/critique-{$round}.json", $critique);

                $normalized = self::normalizeCritique($critique);
                if ($normalized === null) {
                    $warnings[] = "malformed_critique: design/critique-{$round}.json lacks a usable "
                        . 'pass verdict or revise notes; unchanged document delivered';
                    continue;
                }
                if ($normalized['verdict'] === 'pass') {
                    break;
                }

                $beforeRevision = $document;
                $patches = $this->replacementPatches(
                    $document,
                    $normalized['notes'],
                    $round,
                    $warnings,
                );
                $spliced = self::splicePatches($document, $normalized['notes'], $patches);
                if ($spliced['document'] !== null) {
                    $document = $spliced['document'];
                    continue;
                }

                $selector = $spliced['selector'] ?? '(unknown)';
                $warnings[] = "splice_failure: design/home.html landmark {$selector} could not be "
                    . 'replaced; one full-document revision attempted';
                try {
                    $document = $this->reviseWholeDocument(
                        $document,
                        $normalized['notes'],
                        "splice failure at {$selector}",
                        "homepage-design-full-revise-{$round}",
                        'design/home.html',
                        "critique round {$round} full-document fallback",
                        $warnings,
                    );
                } catch (TruncatedGenerationException $error) {
                    $document = $beforeRevision;
                    $warnings[] = 'truncated_generation: full-document splice fallback remained '
                        . 'unclosed after continuation cap; pre-revision bytes delivered';
                }
            }

            $issue = self::designIssue($document);
            if ($issue !== null) {
                $beforeRepair = $document;
                try {
                    $document = $this->reviseWholeDocument(
                        $document,
                        [[
                            'section'     => 'document',
                            'instruction' => "Repair {$issue} while preserving all usable content.",
                        ]],
                        $issue,
                        'homepage-design-repair',
                        'design/home.html',
                        'final malformed-design repair',
                        $warnings,
                    );
                } catch (TruncatedGenerationException $error) {
                    $document = $beforeRepair;
                }

                $repairedIssue = self::designIssue($document);
                if ($repairedIssue !== null) {
                    $project->writeText('design/home.html', $beforeRepair);
                    $warnings[] = "malformed_design: design/home.html {$issue}; one repair attempt "
                        . "still produced {$repairedIssue}; pre-repair bytes delivered";
                    throw new MalformedDesignException(
                        "homepage-design: {$repairedIssue} after one repair attempt"
                    );
                }

                $warnings[] = "malformed_design: design/home.html {$issue}; one full-document repair "
                    . 'delivered replacement markup';
            }

            $document = DesignMarkupSanitizer::sanitize(
                $document,
                'design/home.html',
                'final homepage delivery',
                $warnings,
            );
            $style = self::styleContents($document);
            if ($style === null) {
                throw new MalformedDesignException(
                    'homepage-design: validated document lost its style element'
                );
            }

            $project->writeText('design/home.html', $document);
            $project->writeText('design/site.css', $style);
        } finally {
            $project->addWarnings($this->id(), $warnings);
        }
    }

    /**
     * @param array<string,mixed> $meta
     */
    private static function intKnob(array $meta, string $key, int $default, int $minimum): int
    {
        $value = $meta[$key] ?? $default;
        if (!is_int($value) || $value < $minimum) {
            throw new \RuntimeException(
                "meta.json \"{$key}\" must be an integer greater than or equal to {$minimum}"
            );
        }
        return $value;
    }

    /**
     * @param list<string> $warnings
     * @return list<string>
     */
    private function candidateSeeds(
        string $brief,
        string $siteSpec,
        int $count,
        array &$warnings,
    ): array {
        $seeds = [];
        try {
            $prompt = $this->renderer->render('design-direction-seeds.md', [
                'user_prompt' => $brief,
                'site_spec'   => $siteSpec,
            ]);
            $payload = $this->llm->completeJson(
                $prompt,
                $this->withOptions(['log_label' => 'design-direction-seeds']),
            );
            foreach (is_array($payload['seeds'] ?? null) ? $payload['seeds'] : [] as $raw) {
                $seed = DesignDirectionStep::normalizeSeed($raw);
                if ($seed !== null && !in_array($seed, $seeds, true)) {
                    $seeds[] = $seed;
                }
            }
        } catch (\RuntimeException $error) {
            $warnings[] = 'seed_generation: design-direction seed request failed; distinct '
                . 'built-in candidate angles delivered';
        }

        if (count($seeds) < $count) {
            $warnings[] = 'seed_generation: fewer distinct design-direction seeds than '
                . "{$count} candidates; built-in candidate angles filled missing slots";
        }
        while (count($seeds) < $count) {
            $number = count($seeds) + 1;
            $seeds[] = "Fallback angle {$number} — invent a distinctive, topic-grounded "
                . "visual concept unlike every other candidate.";
        }

        return array_slice($seeds, 0, $count);
    }

    /**
     * @param list<string> $warnings
     */
    private function recoverCandidate(
        string $partial,
        int $index,
        string $path,
        array &$warnings,
    ): string {
        $candidateNumber = $index + 1;
        $prompt = "Tournament candidate {$candidateNumber} is not a closed HTML document.\n\n"
            . "<partial_document>\n{$partial}\n</partial_document>\n\n"
            . 'Regenerate it as one complete, self-contained HTML document. Preserve usable '
            . 'content and design intent. Return ONLY the full document from <!doctype html> '
            . 'through </html>. Do not use Markdown fences.';
        try {
            $recovered = ContinuationRecovery::completeToClose(
                $this->llm,
                $prompt,
                $this->withOptions([
                    'log_label' => "homepage-design-candidate-recovery-{$candidateNumber}",
                ]),
                static fn (string $html): bool => self::isClosedDocument($html),
            );
        } catch (TruncatedGenerationException $error) {
            $recovered = $error->getPartialText();
        }

        $warnings[] = "malformed_design: {$path} context tournament candidate {$candidateNumber}; "
            . 'authored unclosed document delivered regenerated document; disposition repaired';
        return DesignMarkupSanitizer::sanitize(
            $recovered,
            $path,
            "tournament candidate {$candidateNumber} regeneration",
            $warnings,
        );
    }

    /**
     * @param list<string> $candidates
     * @param list<string> $warnings
     * @return array<mixed>
     */
    private function judge(array $candidates, array &$warnings): array
    {
        $parts = [];
        foreach ($candidates as $index => $candidate) {
            $parts[] = "<candidate index=\"{$index}\">\n{$candidate}\n</candidate>";
        }
        $prompt = $this->renderer->render('design-judge.md', [
            'candidates' => implode("\n\n", $parts),
        ]);
        try {
            return $this->llm->completeJson(
                $prompt,
                $this->withOptions(['log_label' => 'homepage-design-judge']),
            );
        } catch (\RuntimeException $error) {
            $warnings[] = 'invalid_judge_verdict: design/judge.json remained invalid after JSON '
                . 'repair or unavailable at advisory boundary; candidate 0 delivered';
            return [];
        }
    }

    /**
     * @param array<mixed> $judge
     * @param list<string> $warnings
     */
    private function winnerIndex(array $judge, int $candidateCount, array &$warnings): int
    {
        $winner = $judge['winner'] ?? null;
        $why = $judge['why'] ?? null;
        $exactShape = count($judge) === 2
            && array_key_exists('winner', $judge)
            && array_key_exists('why', $judge);
        if (
            $exactShape
            && is_int($winner)
            && $winner >= 0
            && $winner < $candidateCount
            && is_string($why)
            && trim($why) !== ''
        ) {
            return $winner;
        }
        $authored = is_scalar($winner) || $winner === null
            ? var_export($winner, true)
            : get_debug_type($winner);
        $warnings[] = "invalid_judge_verdict: design/judge.json exact {winner:int, why:string} "
            . "contract failed for winner {$authored}; candidate 0 delivered";
        return 0;
    }

    /**
     * @param list<string> $warnings
     * @return array<mixed>
     */
    private function critique(string $document, int $round, array &$warnings): array
    {
        $prompt = $this->renderer->render('design-critique.md', [
            'document' => $document,
        ]);
        try {
            return $this->llm->completeJson(
                $prompt,
                $this->withOptions(['log_label' => "homepage-design-critique-{$round}"]),
            );
        } catch (\RuntimeException $error) {
            $warnings[] = "malformed_critique: design/critique-{$round}.json remained invalid "
                . 'or unavailable at advisory boundary; unchanged document delivered';
            return [];
        }
    }

    /**
     * @param array<mixed> $critique
     * @return array{verdict:'pass'|'revise',notes:list<array{section:string,instruction:string}>}|null
     */
    private static function normalizeCritique(array $critique): ?array
    {
        if (
            count($critique) !== 2
            || !array_key_exists('verdict', $critique)
            || !array_key_exists('notes', $critique)
            || !is_string($critique['verdict'])
            || !is_array($critique['notes'])
            || !array_is_list($critique['notes'])
        ) {
            return null;
        }

        $verdict = $critique['verdict'] ?? null;
        if ($verdict === 'pass' && $critique['notes'] === []) {
            return ['verdict' => 'pass', 'notes' => []];
        }
        if ($verdict !== 'revise' || $critique['notes'] === []) {
            return null;
        }

        $notes = [];
        foreach ($critique['notes'] as $raw) {
            if (
                !is_array($raw)
                || count($raw) !== 2
                || !array_key_exists('section', $raw)
                || !array_key_exists('instruction', $raw)
                || !is_string($raw['section'])
                || !is_string($raw['instruction'])
            ) {
                return null;
            }
            $section = trim($raw['section']);
            $instruction = trim($raw['instruction']);
            if ($section === '' || $instruction === '') {
                return null;
            }
            $notes[] = ['section' => $section, 'instruction' => $instruction];
        }
        return ['verdict' => 'revise', 'notes' => $notes];
    }

    /**
     * @param list<array{section:string,instruction:string}> $notes
     * @return array<string,string>
     */
    private function replacementPatches(
        string $document,
        array $notes,
        int $round,
        array &$warnings,
    ): array
    {
        $prompt = $this->renderer->render('design-revise.md', [
            'document' => $document,
            'notes'    => self::encodeJson($notes),
        ]);
        $response = $this->llm->complete(
            $prompt,
            $this->withOptions(['log_label' => "homepage-design-patch-{$round}"]),
        );
        $patches = self::parseReplacementPatches($response);
        foreach ($patches as $selector => $patch) {
            $patches[$selector] = DesignMarkupSanitizer::sanitize(
                $patch,
                'design/home.html',
                "critique round {$round} patch {$selector}",
                $warnings,
            );
        }
        return $patches;
    }

    /**
     * @param list<array{section:string,instruction:string}> $notes
     */
    private function reviseWholeDocument(
        string $document,
        array $notes,
        string $reason,
        string $logLabel,
        string $path,
        string $context,
        array &$warnings,
    ): string {
        $prompt = $this->renderer->render('design-revise.md', [
            'document' => $document,
            'notes'    => self::encodeJson($notes),
        ]);
        $prompt .= "\n\nFULL-DOCUMENT FALLBACK OVERRIDE\n"
            . "Patch splicing cannot safely resolve: {$reason}.\n"
            . "Return ONLY one complete revised HTML document, from <!doctype html> through "
            . '</html>. Do not use Markdown fences or section markers.';

        $revised = ContinuationRecovery::completeToClose(
            $this->llm,
            $prompt,
            $this->withOptions(['log_label' => $logLabel]),
            static fn (string $html): bool => self::isClosedDocument($html),
        );
        return DesignMarkupSanitizer::sanitize($revised, $path, $context, $warnings);
    }

    /**
     * @return array<string,string>
     */
    private static function parseReplacementPatches(string $response): array
    {
        $matched = preg_match_all(
            '/<!--\s*section:\s*(.*?)\s*-->\s*```(?:html)?\s*(.*?)\s*```/is',
            $response,
            $matches,
            PREG_SET_ORDER,
        );
        if (!is_int($matched) || $matched < 1) {
            return [];
        }

        $patches = [];
        foreach ($matches as $match) {
            $selector = trim($match[1]);
            $fragment = trim($match[2]);
            if (
                $selector === ''
                || $fragment === ''
                || array_key_exists($selector, $patches)
            ) {
                return [];
            }
            $patches[$selector] = $fragment;
        }
        return $patches;
    }

    /**
     * @param list<array{section:string,instruction:string}> $notes
     * @param array<string,string> $patches
     * @return array{document:?string,selector:?string}
     */
    private static function splicePatches(string $document, array $notes, array $patches): array
    {
        $spliced = $document;
        foreach ($notes as $note) {
            $selector = $note['section'];
            $replacement = $patches[$selector] ?? null;
            if ($replacement === null) {
                return ['document' => null, 'selector' => $selector];
            }

            $landmark = self::landmarkMatch($spliced, $selector);
            if (
                $landmark === null
                || !self::replacementMatchesLandmark(
                    $replacement,
                    $selector,
                    $landmark['target'],
                )
            ) {
                return ['document' => null, 'selector' => $selector];
            }
            [$start, $length] = $landmark['span'];
            $spliced = substr($spliced, 0, $start)
                . $replacement
                . substr($spliced, $start + $length);
        }
        return ['document' => $spliced, 'selector' => null];
    }

    /**
     * DOM chooses the semantic target; source scanning finds that target's
     * original byte span so untouched siblings never pass through serialization.
     *
     * @return array{span:array{int,int},target:\DOMElement}|null
     */
    private static function landmarkMatch(string $html, string $selector): ?array
    {
        $dom = self::loadDocument($html);
        if ($dom === null) {
            return null;
        }
        $target = self::selectLandmark($dom, $selector);
        if (!$target instanceof \DOMElement) {
            return null;
        }

        $ordinal = 0;
        foreach ($dom->getElementsByTagName($target->tagName) as $element) {
            if ($element->isSameNode($target)) {
                $span = self::rawElementSpan($html, strtolower($target->tagName), $ordinal);
                if ($span === null) {
                    return null;
                }
                $source = substr($html, $span[0], $span[1]);
                return self::replacementMatchesLandmark($source, $selector, $target)
                    ? ['span' => $span, 'target' => $target]
                    : null;
            }
            $ordinal++;
        }
        return null;
    }

    private static function replacementMatchesLandmark(
        string $replacement,
        string $selector,
        \DOMElement $target,
    ): bool {
        $root = self::fragmentRoot($replacement);
        if ($root === null) {
            return false;
        }
        if (strtolower($root->tagName) !== strtolower($target->tagName)) {
            return false;
        }
        if (
            $target->hasAttribute('id')
            && $root->getAttribute('id') !== $target->getAttribute('id')
        ) {
            return false;
        }

        if (preg_match('/^(?:([A-Za-z][A-Za-z0-9:-]*))?#([A-Za-z_][A-Za-z0-9_.:-]*)$/', $selector, $m)) {
            return (($m[1] ?? '') === '' || strtolower($root->tagName) === strtolower($m[1]))
                && $root->getAttribute('id') === $m[2];
        }
        if (preg_match('/^(?:([A-Za-z][A-Za-z0-9:-]*))?\.([A-Za-z_][A-Za-z0-9_-]*)$/', $selector, $m)) {
            return (($m[1] ?? '') === '' || strtolower($root->tagName) === strtolower($m[1]))
                && in_array($m[2], self::classNames($root), true);
        }
        if (preg_match(
            '/^(?:([A-Za-z][A-Za-z0-9:-]*))?\[([A-Za-z_:][A-Za-z0-9_.:-]*)=(["\'])(.*?)\3\]$/',
            $selector,
            $m,
        )) {
            return (($m[1] ?? '') === '' || strtolower($root->tagName) === strtolower($m[1]))
                && $root->hasAttribute($m[2])
                && $root->getAttribute($m[2]) === $m[4];
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9:-]*$/', $selector)) {
            return strtolower($root->tagName) === strtolower($selector);
        }

        if ($target->hasAttribute('id')) {
            return $root->getAttribute('id') === $target->getAttribute('id');
        }
        $targetClasses = self::classNames($target);
        if ($targetClasses !== []) {
            return array_diff($targetClasses, self::classNames($root)) === [];
        }

        $needle = self::normalizedText($selector);
        if ($needle === null) {
            return false;
        }
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            foreach ($root->getElementsByTagName($tag) as $heading) {
                if (self::normalizedText($heading->textContent) === $needle) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private static function classNames(\DOMElement $element): array
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        return array_values(array_filter($classes, static fn (string $class): bool => $class !== ''));
    }

    private static function selectLandmark(\DOMDocument $dom, string $selector): ?\DOMElement
    {
        $xpath = new \DOMXPath($dom);
        $query = null;

        if (preg_match('/^(?:([A-Za-z][A-Za-z0-9:-]*))?#([A-Za-z_][A-Za-z0-9_.:-]*)$/', $selector, $m)) {
            $tag = ($m[1] ?? '') !== '' ? strtolower($m[1]) : '*';
            $query = "//{$tag}[@id=" . self::xpathLiteral($m[2]) . ']';
        } elseif (preg_match('/^(?:([A-Za-z][A-Za-z0-9:-]*))?\.([A-Za-z_][A-Za-z0-9_-]*)$/', $selector, $m)) {
            $tag = ($m[1] ?? '') !== '' ? strtolower($m[1]) : '*';
            $class = self::xpathLiteral(' ' . $m[2] . ' ');
            $query = "//{$tag}[contains(concat(' ', normalize-space(@class), ' '), {$class})]";
        } elseif (preg_match(
            '/^(?:([A-Za-z][A-Za-z0-9:-]*))?\[([A-Za-z_:][A-Za-z0-9_.:-]*)=(["\'])(.*?)\3\]$/',
            $selector,
            $m,
        )) {
            $tag = ($m[1] ?? '') !== '' ? strtolower($m[1]) : '*';
            $query = "//{$tag}[@" . $m[2] . '=' . self::xpathLiteral($m[4]) . ']';
        } elseif (preg_match('/^[A-Za-z][A-Za-z0-9:-]*$/', $selector)) {
            $query = '//' . strtolower($selector);
        }

        if ($query !== null) {
            $matches = $xpath->query($query);
            return $matches instanceof \DOMNodeList && $matches->length === 1
                ? $matches->item(0)
                : null;
        }

        $needle = self::normalizedText($selector);
        if ($needle === null || $needle === '') {
            return null;
        }
        $matches = [];
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $heading) {
            if ($heading instanceof \DOMElement && self::normalizedText($heading->textContent) === $needle) {
                $matches[] = $heading;
            }
        }
        if (count($matches) !== 1) {
            return null;
        }

        $node = $matches[0];
        while ($node instanceof \DOMElement) {
            if (in_array(strtolower($node->tagName), ['section', 'header', 'footer', 'main', 'article'], true)) {
                return $node;
            }
            $node = $node->parentNode;
        }
        return $matches[0];
    }

    private static function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'{$value}'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }
        $parts = explode("'", $value);
        return 'concat(' . implode(', "\'", ', array_map(
            static fn (string $part): string => "'{$part}'",
            $parts,
        )) . ')';
    }

    private static function normalizedText(string $text): ?string
    {
        $normalized = preg_replace('/\s+/u', ' ', $text);
        return $normalized === null ? null : mb_strtolower(trim($normalized));
    }

    /**
     * @return array{int,int}|null
     */
    private static function rawElementSpan(string $html, string $targetTag, int $ordinal): ?array
    {
        $offset = 0;
        $seen = 0;
        $start = null;
        $depth = 0;

        while (($token = self::nextTag($html, $offset)) !== null) {
            $offset = $token['end'];
            $name = $token['name'];
            if (
                !$token['closing']
                && !$token['self_closing']
                && in_array($name, self::RAW_TEXT_ELEMENTS, true)
            ) {
                $close = self::rawTextCloseToken($html, $name, $offset);
                if ($close === null) {
                    return null;
                }
                if ($name === $targetTag) {
                    if ($seen === $ordinal) {
                        return [$token['start'], $close['end'] - $token['start']];
                    }
                    $seen++;
                }
                $offset = $close['end'];
                continue;
            }
            if ($name !== $targetTag) {
                continue;
            }
            if ($token['self_closing']) {
                if ($start === null) {
                    if ($seen === $ordinal) {
                        return [$token['start'], $token['end'] - $token['start']];
                    }
                    $seen++;
                }
                continue;
            }

            if (!$token['closing']) {
                if ($start === null) {
                    if ($seen === $ordinal) {
                        $start = $token['start'];
                        $depth = 1;
                    }
                    $seen++;
                } else {
                    $depth++;
                }
                continue;
            }
            if ($start !== null) {
                $depth--;
                if ($depth === 0) {
                    return [$start, $token['end'] - $start];
                }
            }
        }
        return null;
    }

    /**
     * @return array{start:int,end:int,name:string,closing:bool,self_closing:bool}|null
     */
    private static function nextTag(string $html, int $offset): ?array
    {
        while (($candidate = self::nextTagCandidate($html, $offset)) !== null) {
            $offset = $candidate['end'];
            if ($candidate['malformed']) {
                continue;
            }
            return [
                'start'        => $candidate['start'],
                'end'          => $candidate['end'],
                'name'         => $candidate['name'],
                'closing'      => $candidate['closing'],
                'self_closing' => $candidate['self_closing'],
            ];
        }
        return null;
    }

    /**
     * @return array{
     *   start:int,
     *   end:int,
     *   malformed:bool,
     *   name?:string,
     *   closing?:bool,
     *   self_closing?:bool
     * }|null
     */
    private static function nextTagCandidate(string $html, int $offset): ?array
    {
        $length = strlen($html);
        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                return null;
            }
            if (substr($html, $start, 4) === '<!--') {
                $offset = self::commentSpan($html, $start)['end'];
                continue;
            }

            if (substr($html, $start, 2) === '<!' || substr($html, $start, 2) === '<?') {
                $declarationEnd = self::ignoredPreHeadMarkupEnd($html, $start, $length);
                if ($declarationEnd !== null) {
                    $offset = $declarationEnd;
                    continue;
                }
                return [
                    'start'     => $start,
                    'end'       => $length,
                    'malformed' => true,
                ];
            }

            $cursor = $start + 1;
            while ($cursor < $length && str_contains(" \t\n\f\r", $html[$cursor])) {
                $cursor++;
            }
            $closing = ($html[$cursor] ?? '') === '/';
            if ($closing) {
                $cursor++;
                while ($cursor < $length && str_contains(" \t\n\f\r", $html[$cursor])) {
                    $cursor++;
                }
            }
            if (
                $cursor >= $length
                || preg_match('/^[A-Za-z]$/D', $html[$cursor]) !== 1
            ) {
                $offset = $start + 1;
                continue;
            }

            $nameStart = $cursor;
            while (
                $cursor < $length
                && !self::isTagNameDelimiter($html[$cursor])
            ) {
                $cursor++;
            }
            $rawName = substr($html, $nameStart, $cursor - $nameStart);
            $validName = preg_match('/^[A-Za-z][A-Za-z0-9:-]*$/D', $rawName) === 1;

            $quote = null;
            $end = $cursor;
            for (; $end < $length; $end++) {
                $char = $html[$end];
                if ($quote !== null) {
                    if ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }
                if ($char === '"' || $char === "'") {
                    $quote = $char;
                    continue;
                }
                if ($char === '>') {
                    break;
                }
            }
            if ($end >= $length) {
                return [
                    'start'     => $start,
                    'end'       => $length,
                    'malformed' => true,
                ];
            }

            if (!$validName) {
                return [
                    'start'     => $start,
                    'end'       => $end + 1,
                    'malformed' => true,
                ];
            }
            $raw = substr($html, $start, $end - $start + 1);
            $name = strtolower($rawName);
            return [
                'start'        => $start,
                'end'          => $end + 1,
                'malformed'    => false,
                'name'         => $name,
                'closing'      => $closing,
                'self_closing' => !in_array($name, self::RAW_TEXT_ELEMENTS, true)
                    && preg_match('/\/\s*>$/s', $raw) === 1,
            ];
        }
        return null;
    }

    private static function isTagNameDelimiter(string $char): bool
    {
        return $char === '/'
            || $char === '>'
            || str_contains(" \t\n\f\r", $char);
    }

    /**
     * @return array{start:int,end:int,name:string,closing:bool,self_closing:bool}|null
     */
    private static function rawTextCloseToken(
        string $html,
        string $name,
        int $offset,
    ): ?array {
        $needle = "</{$name}";
        while (($start = stripos($html, $needle, $offset)) !== false) {
            $afterName = $start + strlen($needle);
            $delimiter = $html[$afterName] ?? '';
            if (
                $delimiter === '>'
                || $delimiter === '/'
                || ($delimiter !== '' && str_contains(" \t\n\f\r", $delimiter))
            ) {
                $close = self::nextTag($html, $start);
                if (
                    $close !== null
                    && $close['closing']
                    && $close['name'] === $name
                ) {
                    return $close;
                }
            }
            $offset = $afterName;
        }
        return null;
    }

    /**
     * @return array{start:int,end:int,malformed:bool}
     */
    private static function commentSpan(string $html, int $start): array
    {
        $length = strlen($html);
        if (substr($html, $start, 5) === '<!-->') {
            return ['start' => $start, 'end' => $start + 5, 'malformed' => true];
        }
        if (substr($html, $start, 6) === '<!--->') {
            return ['start' => $start, 'end' => $start + 6, 'malformed' => true];
        }

        $standardEnd = strpos($html, '-->', $start + 4);
        $bangEnd = strpos($html, '--!>', $start + 4);
        if ($standardEnd === false && $bangEnd === false) {
            return ['start' => $start, 'end' => $length, 'malformed' => true];
        }
        if ($bangEnd !== false && ($standardEnd === false || $bangEnd < $standardEnd)) {
            return ['start' => $start, 'end' => $bangEnd + 4, 'malformed' => true];
        }
        return ['start' => $start, 'end' => $standardEnd + 3, 'malformed' => false];
    }

    private static function ignoredPreHeadMarkupEnd(
        string $html,
        int $start,
        int $end,
    ): ?int {
        if (substr($html, $start, 4) === '<!--') {
            return min(self::commentSpan($html, $start)['end'], $end);
        }
        $prefix = substr($html, $start, 2);
        if ($prefix !== '<!' && $prefix !== '<?') {
            return null;
        }

        for ($offset = $start + 2; $offset < $end; $offset++) {
            if ($html[$offset] === '>') {
                return $offset + 1;
            }
        }
        return null;
    }

    /**
     * @param array{start:int,end:int,name:string,closing:bool,self_closing:bool} $opening
     * @return array{start:int,end:int,name:string,closing:bool,self_closing:bool}|null
     */
    private static function matchingCloseToken(string $html, array $opening): ?array
    {
        $name = $opening['name'];
        if ($opening['self_closing'] || in_array($name, self::VOID_ELEMENTS, true)) {
            return null;
        }
        if (in_array($name, self::RAW_TEXT_ELEMENTS, true)) {
            return self::rawTextCloseToken($html, $name, $opening['end']);
        }
        $depth = 1;
        $offset = $opening['end'];
        while (($token = self::nextTag($html, $offset)) !== null) {
            $offset = $token['end'];
            if (
                !$token['closing']
                && !$token['self_closing']
                && in_array($token['name'], self::RAW_TEXT_ELEMENTS, true)
                && $token['name'] !== $name
            ) {
                $rawClose = self::matchingCloseToken($html, $token);
                if ($rawClose === null) {
                    return null;
                }
                $offset = $rawClose['end'];
                continue;
            }
            if ($token['name'] !== $name || $token['self_closing']) {
                continue;
            }
            $depth += $token['closing'] ? -1 : 1;
            if ($depth === 0) {
                return $token;
            }
        }
        return null;
    }

    private static function fragmentRoot(string $fragment): ?\DOMElement
    {
        if (!self::isRawBalancedSingleRoot($fragment)) {
            return null;
        }
        $dom = self::loadDocument(
            '<!doctype html><html><body><div id="homepage-patch-root">'
            . $fragment
            . '</div></body></html>'
        );
        if ($dom === null) {
            return null;
        }
        $root = (new \DOMXPath($dom))->query('//*[@id="homepage-patch-root"]')?->item(0);
        if (!$root instanceof \DOMElement) {
            return null;
        }

        $element = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                if ($element !== null) {
                    return null;
                }
                $element = $child;
            } elseif ($child instanceof \DOMText && trim($child->textContent) !== '') {
                return null;
            }
        }
        return $element;
    }

    private static function isRawBalancedSingleRoot(string $fragment): bool
    {
        if ($fragment === '') {
            return false;
        }

        $stack = [];
        $offset = 0;
        while (($token = self::nextTag($fragment, $offset)) !== null) {
            $offset = $token['end'];
            if ($stack === [] && $token['start'] !== 0) {
                return false;
            }

            $name = $token['name'];
            if ($token['closing']) {
                $last = array_key_last($stack);
                if ($last === null || $stack[$last] !== $name) {
                    return false;
                }
                array_pop($stack);
                if ($stack === []) {
                    return $token['end'] === strlen($fragment);
                }
                continue;
            }
            if ($token['self_closing'] || in_array($name, self::VOID_ELEMENTS, true)) {
                if ($stack === []) {
                    return $token['start'] === 0 && $token['end'] === strlen($fragment);
                }
                continue;
            }

            $stack[] = $name;
            if (in_array($name, self::RAW_TEXT_ELEMENTS, true)) {
                $close = self::matchingCloseToken($fragment, $token);
                if ($close === null) {
                    return false;
                }
                array_pop($stack);
                $offset = $close['end'];
                if ($stack === []) {
                    return $close['end'] === strlen($fragment);
                }
            }
        }
        return false;
    }

    private static function isClosedDocument(string $html): bool
    {
        if (preg_match('/<\/html\s*>\s*$/i', $html) !== 1) {
            return false;
        }
        $dom = self::loadDocument($html);
        return $dom !== null
            && $dom->documentElement instanceof \DOMElement
            && strtolower($dom->documentElement->tagName) === 'html'
            && $dom->getElementsByTagName('body')->length === 1;
    }

    private static function closeDocumentDeterministically(string $html): string
    {
        if (trim($html) !== '') {
            $dom = self::loadDocument($html);
            if ($dom !== null) {
                $closed = $dom->saveHTML();
                if (is_string($closed) && self::isClosedDocument($closed)) {
                    return $closed;
                }
            }
        }

        $escaped = htmlspecialchars(
            $html,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
        return "<!doctype html>\n<html><head><style></style></head><body>"
            . '<header></header><main><pre>'
            . $escaped
            . '</pre></main><footer></footer></body></html>';
    }

    private static function designIssue(string $html): ?string
    {
        if (trim($html) === '') {
            return 'is empty';
        }
        if (!self::isClosedDocument($html)) {
            return 'is not a closed HTML document';
        }
        if (self::styleContents($html) === null) {
            return 'has no <style> element';
        }

        $dom = self::loadDocument($html);
        if (
            $dom === null
            || $dom->getElementsByTagName('header')->length < 1
            || $dom->getElementsByTagName('footer')->length < 1
        ) {
            return 'has missing or malformed header/footer landmarks';
        }
        return null;
    }

    private static function styleContents(string $html): ?string
    {
        $styles = [];
        $offset = 0;
        while (($token = self::nextTag($html, $offset)) !== null) {
            $offset = $token['end'];
            if (
                $token['closing']
                || $token['self_closing']
                || !in_array($token['name'], self::RAW_TEXT_ELEMENTS, true)
            ) {
                continue;
            }

            $close = self::matchingCloseToken($html, $token);
            if ($close === null) {
                break;
            }
            if ($token['name'] === 'style') {
                $styles[] = substr(
                    $html,
                    $token['end'],
                    $close['start'] - $token['end'],
                );
            }
            $offset = $close['end'];
        }
        return $styles === [] ? null : implode('', $styles);
    }

    private static function loadDocument(string $html): ?\DOMDocument
    {
        // UTF-8 hint so libxml doesn't guess ISO-8859-1 and double-encode.
        return \Automattic\SiteBuild\Html::loadUtf8Html($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    }

    /**
     * @param array<mixed> $value
     */
    private static function encodeJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
