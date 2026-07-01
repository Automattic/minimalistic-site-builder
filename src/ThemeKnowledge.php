<?php
declare(strict_types=1);

/**
 * Read-only loader for the design-knowledge repo (the "themer" project).
 *
 * Two kinds of context flow from that repo into the section-building prompts:
 *   - playbook(): the distilled per-part/section conventions (insights/sections/<key>.md)
 *   - examples(): k real block-markup files from the corpus, chosen via
 *     insights/examples.json, injected verbatim as few-shot references.
 *
 * The builder must work unchanged when the knowledge repo is absent: if the
 * configured root is unset or missing, every method returns "" and the build
 * proceeds exactly as it would without insights. We never fail a build because
 * this optional context is missing.
 *
 * See insights/BUILDER-INTEGRATION.md in the themer repo for the full spec.
 */
final class ThemeKnowledge
{
    private bool $enabled;
    private string $insightsDir;

    /** Parsed examples.json, loaded lazily and cached. null = not yet loaded. */
    private ?array $examplesIndex = null;

    /**
     * @param ?string $corpusRoot   THEMER_ROOT — corpus root the example `file`
     *                              paths in examples.json are relative to. The
     *                              insights live at <root>/insights.
     * @param int     $defaultK     Examples per part (kept small — token budget).
     * @param int     $maxExampleBytes  Skip an example whose extracted markup is
     *                              larger than this, so one big hero cover can't
     *                              dominate the prompt. Try the next-ranked one.
     */
    public function __construct(
        private ?string $corpusRoot,
        private int $defaultK = 2,
        private int $maxExampleBytes = 8192,
    ) {
        $root = $corpusRoot !== null ? rtrim($corpusRoot, '/') : '';
        $this->corpusRoot = $root === '' ? null : $root;
        $this->enabled = $this->corpusRoot !== null && is_dir($this->corpusRoot);
        $this->insightsDir = ($this->corpusRoot ?? '') . '/insights';
    }

    /**
     * The distilled playbook for a part/section, ready to inject as guidance.
     *
     * @param string $key e.g. "header", "footer", "hero". Resolves to
     *                    insights/sections/<key>.md. Returns "" if absent.
     */
    public function playbook(string $key): string
    {
        if (!$this->enabled) {
            return '';
        }
        $file = $this->insightsDir . '/sections/' . $key . '.md';
        if (!is_file($file)) {
            return '';
        }
        return self::stripPlaybookFrontMatter((string) file_get_contents($file));
    }

    /**
     * Up to $k real example blocks for a part/section, formatted as labelled
     * few-shot references. Returns "" if the key is unknown or nothing loads.
     *
     * @param string $partOrType "header"/"footer" map to top-level arrays;
     *                           anything else maps to sections[<type>] (e.g. "hero").
     */
    public function examples(string $partOrType, ?int $k = null): string
    {
        if (!$this->enabled) {
            return '';
        }
        $k = $k ?? $this->defaultK;
        if ($k < 1) {
            return '';
        }

        $entries = $this->entriesFor($partOrType);
        if ($entries === []) {
            return '';
        }

        $blocks = [];
        foreach ($entries as $entry) {
            if (count($blocks) >= $k) {
                break;
            }
            $file = $entry['file'] ?? null;
            if (!is_string($file) || $file === '') {
                continue;
            }
            $path = $this->corpusRoot . '/' . ltrim($file, '/');
            if (!is_file($path)) {
                continue;
            }
            $markup = self::extractMarkup((string) file_get_contents($path), $path);
            // Skip empties, anything that still holds raw PHP, and oversized
            // examples — never emit broken/huge markup; just try the next one.
            if ($markup === '' || strlen($markup) > $this->maxExampleBytes) {
                continue;
            }
            $theme = (string) ($entry['theme'] ?? 'theme');
            $note = (string) ($entry['note'] ?? '');
            $label = $note !== '' ? "{$theme} ({$note})" : $theme;
            $blocks[] = "<!-- EXAMPLE: {$label} -->\n{$markup}\n<!-- /EXAMPLE -->";
        }

        if ($blocks === []) {
            return '';
        }

        $intro = "Here are real, hand-built examples of this part from high-quality themes. "
            . "Study their structure, token usage, and proportions — do NOT copy their copy/content; "
            . "build for THIS site.";

        return $intro . "\n\n" . implode("\n\n", $blocks);
    }

    /**
     * Resolve a part/section key to its ranked list of example entries.
     *
     * @return array<int,array<string,mixed>>
     */
    private function entriesFor(string $partOrType): array
    {
        $index = $this->index();
        $list = match ($partOrType) {
            'header' => $index['header'] ?? null,
            'footer' => $index['footer'] ?? null,
            default  => $index['sections'][$partOrType] ?? null,
        };
        return is_array($list) ? $list : [];
    }

    /** Load and cache examples.json (returns [] if missing/invalid). */
    private function index(): array
    {
        if ($this->examplesIndex !== null) {
            return $this->examplesIndex;
        }
        $file = $this->insightsDir . '/examples.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return $this->examplesIndex = is_array($data) ? $data : [];
    }

    /**
     * Extract pure block markup from a corpus file. Pure — unit-testable.
     *
     * - .html template parts are already pure markup; trimmed as-is.
     * - .php pattern files carry a PHP header comment (and sometimes inline PHP
     *   in the markup): drop every <?php … ?> segment, then take everything from
     *   the first "<!-- wp:". If any raw <?php survives (an unterminated tag),
     *   return "" so we never leak PHP into a prompt.
     *
     * Returns "" when no block markup can be recovered.
     */
    public static function extractMarkup(string $raw, string $path): string
    {
        $isPhp = str_ends_with(strtolower($path), '.php');
        if ($isPhp) {
            // Drop every complete PHP tag segment (the header comment plus any
            // inline tags). 's' so . spans newlines; non-greedy so each tag
            // matches up to its own terminator.
            $raw = (string) preg_replace('/<\?php.*?\?>/s', '', $raw);
        }

        $start = strpos($raw, '<!-- wp:');
        if ($start === false) {
            return '';
        }
        $markup = trim(substr($raw, $start));

        // Defensive: a stray, unterminated PHP open tag would survive the strip
        // above (it has no terminator to match). Refuse rather than emit raw PHP.
        if (str_contains($markup, '<?php')) {
            return '';
        }
        return $markup;
    }

    /**
     * Strip a playbook's leading "# … playbook" H1 and an immediately-following
     * "> provenance" blockquote line to save tokens. Pure — unit-testable.
     */
    public static function stripPlaybookFrontMatter(string $text): string
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $i = 0;
        $n = count($lines);
        // Skip leading blank lines.
        while ($i < $n && trim($lines[$i]) === '') {
            $i++;
        }
        // Drop a leading H1.
        if ($i < $n && str_starts_with(ltrim($lines[$i]), '# ')) {
            $i++;
            // Drop a single provenance blockquote that follows the H1.
            if ($i < $n && str_starts_with(ltrim($lines[$i]), '> ')) {
                $i++;
            }
        }
        return trim(implode("\n", array_slice($lines, $i)));
    }
}
