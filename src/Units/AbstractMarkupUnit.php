<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\PromptRenderer;

/** Shared dependency and single-call plumbing for markup units. */
abstract class AbstractMarkupUnit implements MarkupUnit
{
    private const OUTPUT_CONTRACT_TEMPLATE = 'block-markup-output-contract.md';
    private const SITE_CONTEXT_TEMPLATE = 'site-context.md';

    /**
     * Frozen cache-layer markers. Every markup prompt opens with the site
     * layer — byte-identical across header, footer, hero and section, so one
     * warm-up primes it for all of them — and each unit splits the rest into
     * whatever further layers it can reuse.
     */
    protected const SITE_LAYER_MARKER = '<!-- cache-layer:site -->';
    protected const UNIT_LAYER_MARKER = '<!-- cache-layer:unit -->';

    public function __construct(
        protected Llm $llm,
        protected PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
    ) {}

    /**
     * Execute one rendered request, forwarding all request metadata (including
     * cached_prefixes) to the single-completion path.
     */
    final public function generate(array $input): MarkupResult
    {
        $request = $this->request($input);
        $prompt = $request['prompt'];
        unset($request['prompt']);

        // Batch transports use the request key as their log label. Supply the
        // same label explicitly on this single-call path.
        $request['log_label'] = $this->key($input);

        return $this->finish($this->llm->complete($prompt, $request), $input);
    }

    /**
     * @param array<string,string> $vars
     * @return array{prompt:string,model?:string,temperature?:float}
     */
    final protected function renderedRequest(string $template, array $vars): array
    {
        // Keep the response contract in one shared fragment while each prompt
        // controls where it belongs (the section places it in its build layer).
        $vars['block_markup_output_contract'] = rtrim(
            $this->renderer->render(self::OUTPUT_CONTRACT_TEMPLATE, []),
            "\r\n",
        );
        $request = ['prompt' => $this->renderer->render($template, $vars)];
        if ($this->model !== null) {
            $request['model'] = $this->model;
        }
        if ($this->temperature !== null) {
            $request['temperature'] = $this->temperature;
        }
        return $request;
    }

    /** Require one string-valued input key, while allowing an empty string. */
    final protected function inputString(array $input, string $key): string
    {
        if (!array_key_exists($key, $input) || !is_string($input[$key])) {
            throw new \InvalidArgumentException("unit input '{$key}' must be a string");
        }
        return $input[$key];
    }

    /**
     * Accept JSON text from the CLI adapter or a decoded object from HTTP.
     *
     * Both shapes must render to the same bytes: the site layer is a shared
     * cache prefix, and a step reading siteSpec.json as text would otherwise
     * never match one that read it as an array. writeJson() encodes with these
     * exact flags and appends a newline, so trimming the terminator is all it
     * takes to make the two agree.
     */
    final protected function inputJson(array $input, string $key): string
    {
        if (!array_key_exists($key, $input)) {
            throw new \InvalidArgumentException("unit input '{$key}' must be JSON text or an array");
        }
        if (is_string($input[$key])) {
            return rtrim($input[$key], "\r\n");
        }
        if (!is_array($input[$key])) {
            throw new \InvalidArgumentException("unit input '{$key}' must be JSON text or an array");
        }
        $json = json_encode(
            $input[$key],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!is_string($json)) {
            throw new \InvalidArgumentException("unit input '{$key}' could not be encoded as JSON");
        }
        return $json;
    }

    /** Accept a decoded object or decode one JSON object from a portable input. */
    final protected function inputArrayOrJson(array $input, string $key): array
    {
        if (!array_key_exists($key, $input)) {
            throw new \InvalidArgumentException("unit input '{$key}' must be JSON text or an array");
        }
        if (is_array($input[$key])) {
            return $input[$key];
        }
        if (!is_string($input[$key])) {
            throw new \InvalidArgumentException("unit input '{$key}' must be JSON text or an array");
        }
        try {
            $decoded = json_decode($input[$key], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("unit input '{$key}' must contain valid JSON", 0, $e);
        }
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException("unit input '{$key}' must decode to an object");
        }
        return $decoded;
    }

    /**
     * The context shared by header, footer, hero, and section prompts.
     *
     * site_context is the rendered shared cache layer: the same site spec,
     * theme tokens and design direction those four prompts all open with, in
     * one arrangement so the bytes match and the layer is cacheable once.
     *
     * @return array{site_context:string,language:string,outline:string}
     */
    final protected function commonVars(array $input): array
    {
        return [
            'site_context' => rtrim($this->renderer->render(self::SITE_CONTEXT_TEMPLATE, [
                'site_spec'        => $this->inputJson($input, 'site_spec'),
                'theme_json'       => $this->inputJson($input, 'theme_json'),
                'design_direction' => $this->inputString($input, 'design_direction'),
            ]), "\r\n"),
            'language' => $this->inputString($input, 'language'),
            'outline'  => $this->inputString($input, 'outline'),
        ];
    }

    /**
     * Split a rendered prompt at its frozen cache-layer markers.
     *
     * Every layer but the last is a reusable prefix: newline-trimmed with
     * exactly "\n\n" appended, so adjacent Anthropic content blocks and an
     * OpenAI-compatible concatenation assemble to the same text. The final
     * layer is the varying prompt and is newline-trimmed only.
     *
     * Marker order and uniqueness are programming invariants of the template,
     * not generated content, so a violation throws.
     *
     * @param  list<string> $markers ordered, one per layer
     * @return list<string> one layer per marker, in the same order
     */
    final protected static function cacheLayers(string $rendered, array $markers): array
    {
        foreach ($markers as $marker) {
            if (substr_count($rendered, $marker) !== 1) {
                throw new \RuntimeException("markup prompt must contain exactly one {$marker} marker");
            }
        }

        $positions = array_map(
            static fn (string $marker): int => (int) strpos($rendered, $marker),
            $markers,
        );
        $sorted = $positions;
        sort($sorted);
        if ($positions !== $sorted) {
            throw new \RuntimeException('markup prompt cache layer markers are out of order');
        }

        $rest = $rendered;
        $layers = [];
        foreach ($markers as $index => $marker) {
            [$before, $rest] = explode($marker, $rest, 2);
            if ($index === 0) {
                if (trim($before, "\r\n") !== '') {
                    throw new \RuntimeException('markup prompt has content before its first cache layer');
                }
                continue;
            }
            // Remove only newlines belonging to the marker separators;
            // preserve every other byte, including indentation.
            $layers[] = rtrim(ltrim($before, "\r\n"), "\r\n") . "\n\n";
        }
        $layers[] = trim($rest, "\r\n");

        foreach ($layers as $layer) {
            if (trim($layer) === '') {
                throw new \RuntimeException('markup prompt cache layers must not be empty');
            }
        }
        return $layers;
    }

    /**
     * Render a chrome prompt (header, footer, hero) as two layers: the shared
     * site context every markup call reuses, and this unit's own brief.
     *
     * @param array<string,string> $vars
     * @return array{prompt:string,model?:string,temperature?:float,cached_prefixes:list<string>}
     */
    final protected function siteLayeredRequest(string $template, array $vars): array
    {
        $request = $this->renderedRequest($template, $vars);
        [$site, $unit] = self::cacheLayers(
            $request['prompt'],
            [self::SITE_LAYER_MARKER, self::UNIT_LAYER_MARKER],
        );
        $request['cached_prefixes'] = [$site];
        $request['prompt'] = $unit;
        return $request;
    }
}
