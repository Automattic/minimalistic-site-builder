<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\PromptRenderer;

/** Shared dependency and single-call plumbing for markup units. */
abstract class AbstractMarkupUnit implements MarkupUnit
{
    private const OUTPUT_CONTRACT_TEMPLATE = 'block-markup-output-contract.md';

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
    final public function generate(array $input): string
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

    /** Accept JSON text from the CLI adapter or a decoded object from HTTP. */
    final protected function inputJson(array $input, string $key): string
    {
        if (!array_key_exists($key, $input)) {
            throw new \InvalidArgumentException("unit input '{$key}' must be JSON text or an array");
        }
        if (is_string($input[$key])) {
            return $input[$key];
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

    /**
     * The context shared by header, footer, and section prompts.
     *
     * @return array{site_spec:string,language:string,theme_json:string,design_direction:string,outline:string}
     */
    final protected function commonVars(array $input): array
    {
        return [
            'site_spec'        => $this->inputJson($input, 'site_spec'),
            'language'         => $this->inputString($input, 'language'),
            'theme_json'       => $this->inputJson($input, 'theme_json'),
            'design_direction' => $this->inputString($input, 'design_direction'),
            'outline'          => $this->inputString($input, 'outline'),
        ];
    }
}
