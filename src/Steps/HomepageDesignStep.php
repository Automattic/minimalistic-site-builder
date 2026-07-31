<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\ContinuationRecovery;
use Automattic\SiteBuild\GeneratedJsonException;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\MalformedDesignException;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TruncatedGenerationException;

/**
 * Designs one complete homepage through a seeded tournament and bounded
 * source-critique loop. Candidate fan-out is the only concurrent call; all
 * critique and whole-document recovery calls stay serial on the shared client.
 */
final class HomepageDesignStep implements Step
{
    use LlmOptions;

    private const DEFAULT_CANDIDATES = 3;
    private const DEFAULT_CRITIQUE_ROUNDS = 2;

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
        $seeds = $this->candidateSeeds($brief, $siteSpec, $candidateCount, $warnings);
        $requests = [];
        foreach ($seeds as $index => $seed) {
            $requests[$index] = $this->withOptions([
                'prompt' => $this->renderer->render('homepage-design.md', [
                    'brief'            => $brief,
                    'site_spec'        => $siteSpec,
                    'design_direction' => $designDirection,
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
            $candidates[$index] = $candidate;
            $project->writeText('design/candidate-' . ($index + 1) . '.html', $candidate);
            foreach ($batch->notesFor($index) as $note) {
                $warnings[] = 'candidate_generation: design/candidate-' . ($index + 1)
                    . ".html delivered with degraded generation: {$note}";
            }
        }

        $judge = $this->judge($candidates, $warnings);
        $project->writeJson('design/judge.json', $judge);
        $winner = $this->winnerIndex($judge, count($candidates), $warnings);
        $document = $candidates[$winner];

        for ($round = 1; $round <= $critiqueRounds; $round++) {
            $critique = $this->critique($document, $round, $warnings);
            $project->writeJson("design/critique-{$round}.json", $critique);

            $normalized = self::normalizeCritique($critique);
            if ($normalized === null) {
                $warnings[] = "malformed_critique: design/critique-{$round}.json lacks a usable "
                    . 'pass verdict or revise notes; unchanged document delivered';
                break;
            }
            if ($normalized['verdict'] === 'pass') {
                break;
            }

            $beforeRevision = $document;
            $patches = $this->replacementPatches($document, $normalized['notes'], $round);
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
                );
            } catch (TruncatedGenerationException $error) {
                $document = $beforeRepair;
            }

            $repairedIssue = self::designIssue($document);
            if ($repairedIssue !== null) {
                $project->writeText('design/home.html', $beforeRepair);
                $warnings[] = "malformed_design: design/home.html {$issue}; one repair attempt "
                    . "still produced {$repairedIssue}; pre-repair bytes delivered";
                $project->addWarnings($this->id(), $warnings);
                throw new MalformedDesignException(
                    "homepage-design: {$repairedIssue} after one repair attempt"
                );
            }

            $warnings[] = "malformed_design: design/home.html {$issue}; one full-document repair "
                . 'delivered replacement markup';
        }

        $style = self::styleContents($document);
        if ($style === null) {
            throw new \LogicException('homepage-design: validated document lost its style element');
        }

        $project->writeText('design/home.html', $document);
        $project->writeText('design/site.css', $style);
        $project->addWarnings($this->id(), $warnings);
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
        } catch (\Throwable $error) {
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
        } catch (GeneratedJsonException $error) {
            $warnings[] = 'invalid_judge_verdict: design/judge.json remained invalid after JSON '
                . 'repair; candidate 0 delivered';
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
        if (is_int($winner) && $winner >= 0 && $winner < $candidateCount) {
            return $winner;
        }
        $authored = is_scalar($winner) || $winner === null
            ? var_export($winner, true)
            : get_debug_type($winner);
        $warnings[] = "invalid_judge_verdict: design/judge.json winner {$authored} is outside "
            . "candidate range 0.." . ($candidateCount - 1) . '; candidate 0 delivered';
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
        } catch (GeneratedJsonException $error) {
            $warnings[] = "malformed_critique: design/critique-{$round}.json remained invalid "
                . 'after JSON repair; unchanged document delivered';
            return [];
        }
    }

    /**
     * @param array<mixed> $critique
     * @return array{verdict:'pass'|'revise',notes:list<array{section:string,instruction:string}>}|null
     */
    private static function normalizeCritique(array $critique): ?array
    {
        $verdict = $critique['verdict'] ?? null;
        if ($verdict === 'pass') {
            return ['verdict' => 'pass', 'notes' => []];
        }
        if ($verdict !== 'revise' || !is_array($critique['notes'] ?? null)) {
            return null;
        }

        $notes = [];
        foreach ($critique['notes'] as $raw) {
            if (!is_array($raw)) {
                return null;
            }
            $section = trim((string) ($raw['section'] ?? ''));
            $instruction = trim((string) ($raw['instruction'] ?? ''));
            if ($section === '' || $instruction === '') {
                return null;
            }
            $notes[] = ['section' => $section, 'instruction' => $instruction];
        }
        if ($notes === []) {
            return null;
        }
        return ['verdict' => 'revise', 'notes' => $notes];
    }

    /**
     * @param list<array{section:string,instruction:string}> $notes
     * @return array<string,string>
     */
    private function replacementPatches(string $document, array $notes, int $round): array
    {
        $prompt = $this->renderer->render('design-revise.md', [
            'document' => $document,
            'notes'    => self::encodeJson($notes),
        ]);
        $response = $this->llm->complete(
            $prompt,
            $this->withOptions(['log_label' => "homepage-design-patch-{$round}"]),
        );
        return self::parseReplacementPatches($response);
    }

    /**
     * @param list<array{section:string,instruction:string}> $notes
     */
    private function reviseWholeDocument(
        string $document,
        array $notes,
        string $reason,
        string $logLabel,
    ): string {
        $prompt = $this->renderer->render('design-revise.md', [
            'document' => $document,
            'notes'    => self::encodeJson($notes),
        ]);
        $prompt .= "\n\nFULL-DOCUMENT FALLBACK OVERRIDE\n"
            . "Patch splicing cannot safely resolve: {$reason}.\n"
            . "Return ONLY one complete revised HTML document, from <!doctype html> through "
            . '</html>. Do not use Markdown fences or section markers.';

        return ContinuationRecovery::completeToClose(
            $this->llm,
            $prompt,
            $this->withOptions(['log_label' => $logLabel]),
            static fn (string $html): bool => self::isClosedDocument($html),
        );
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
                || !self::isSingleElementFragment($fragment)
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

            $span = self::landmarkSpan($spliced, $selector);
            if ($span === null) {
                return ['document' => null, 'selector' => $selector];
            }
            [$start, $length] = $span;
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
     * @return array{int,int}|null
     */
    private static function landmarkSpan(string $html, string $selector): ?array
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
                return self::rawElementSpan($html, strtolower($target->tagName), $ordinal);
            }
            $ordinal++;
        }
        return null;
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
        if ($needle === '') {
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

    private static function normalizedText(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
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
                && in_array($name, ['style', 'script'], true)
            ) {
                $rawClose = stripos($html, "</{$name}", $offset);
                if ($rawClose === false) {
                    return null;
                }
                $close = self::nextTag($html, $rawClose);
                if ($close === null || !$close['closing'] || $close['name'] !== $name) {
                    return null;
                }
                $offset = $close['end'];
                continue;
            }
            if ($name !== $targetTag || $token['self_closing']) {
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
        $length = strlen($html);
        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                return null;
            }
            if (substr($html, $start, 4) === '<!--') {
                $commentEnd = strpos($html, '-->', $start + 4);
                if ($commentEnd === false) {
                    return null;
                }
                $offset = $commentEnd + 3;
                continue;
            }

            $quote = null;
            $end = $start + 1;
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
                return null;
            }

            $raw = substr($html, $start, $end - $start + 1);
            if (!preg_match('/^<\s*(\/?)\s*([A-Za-z][A-Za-z0-9:-]*)\b/s', $raw, $match)) {
                $offset = $end + 1;
                continue;
            }
            return [
                'start'        => $start,
                'end'          => $end + 1,
                'name'         => strtolower($match[2]),
                'closing'      => $match[1] === '/',
                'self_closing' => preg_match('/\/\s*>$/s', $raw) === 1,
            ];
        }
        return null;
    }

    private static function isSingleElementFragment(string $fragment): bool
    {
        $dom = self::loadDocument(
            '<!doctype html><html><body><div id="homepage-patch-root">'
            . $fragment
            . '</div></body></html>'
        );
        if ($dom === null) {
            return false;
        }
        $root = (new \DOMXPath($dom))->query('//*[@id="homepage-patch-root"]')?->item(0);
        if (!$root instanceof \DOMElement) {
            return false;
        }

        $elements = 0;
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $elements++;
            } elseif ($child instanceof \DOMText && trim($child->textContent) !== '') {
                return false;
            }
        }
        return $elements === 1;
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
        if (preg_match('/<style\b[^>]*>(.*?)<\/style\s*>/is', $html, $match) !== 1) {
            return null;
        }
        return $match[1];
    }

    private static function loadDocument(string $html): ?\DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            $loaded = $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $loaded ? $dom : null;
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
