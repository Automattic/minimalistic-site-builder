<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\TextBatchResult;

/**
 * A model that answers from a recording, so a fixture run makes no network
 * call and lands the same site every time.
 *
 * The recording is the shape Site Foundry writes and `--record` produces:
 *
 *   { "version": 2,
 *     "slots": { "<slot-id>": "text", ... },
 *     "page_titles": { "<slug>": "title", ... },
 *     "plan": { "pages": [...] } }
 *
 * A page's answer is built from the slot ids its prompt names: each one found
 * in `slots` is answered, each one missing is left out, which the build
 * treats exactly as a model that skipped it. `plan` answers the planning
 * call, and `page_titles` renames the pages it names in that plan. A site of
 * supplied pages never plans, so a recording of only `slots` replays it.
 *
 * Raw-text calls are refused, so a recording cannot silently stand in for a
 * graph it does not cover.
 */
final class ReplayLlm implements Llm
{
    public const VERSION = 2;

    /** @param array<string, mixed> $recording */
    public function __construct(private array $recording)
    {
        if (($recording['version'] ?? null) !== self::VERSION || !is_array($recording['slots'] ?? null)) {
            throw new \InvalidArgumentException(
                'A replay recording is { "version": 2, "slots": { "<slot-id>": "text" }, "page_titles"?: {...}, "plan"?: {...} }'
            );
        }
    }

    /**
     * Which request a prompt is: the schema name and, for page content, the
     * slot ids the prompt asks for.
     *
     * @return array{string, list<string>}
     */
    public static function keyFor(string $prompt, array $opts): array
    {
        $name = (string) ($opts['json_schema']['name'] ?? 'unnamed');
        $ids = [];
        if ($name === 'page_content' && preg_match('~<slots>(.*?)</slots>~s', $prompt, $block)) {
            preg_match_all('/"id":\s*"([^"]+)"/', $block[1], $found);
            $ids = $found[1];
        }

        return [$name, $ids];
    }

    public function complete(string $prompt, array $opts = []): string
    {
        throw new \LogicException('ReplayLlm has no raw-text fixture response');
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        [$name, $ids] = self::keyFor($prompt, $opts);

        if ($name === 'page_content') {
            $content = [];
            foreach ($ids as $id) {
                $text = $this->recording['slots'][$id] ?? null;
                if (is_scalar($text)) {
                    $content[] = ['id' => $id, 'text' => (string) $text];
                }
            }

            return ['content' => $content];
        }

        if ($name === 'site_plan') {
            $plan = $this->recording['plan'] ?? null;
            if (!is_array($plan)) {
                throw new \RuntimeException(
                    'The recording has no plan, and a page with an intent needs one. Record one with --record, or supply every page.'
                );
            }

            return self::retitled($plan, (array) ($this->recording['page_titles'] ?? []));
        }

        throw new \RuntimeException(sprintf('The recording cannot answer a "%s" request.', $name));
    }

    public function completeJsonBatch(array $requests): array
    {
        $results = [];
        foreach ($requests as $key => $request) {
            $results[$key] = $this->completeJson((string) ($request['prompt'] ?? ''), $request);
        }

        return $results;
    }

    public function completeBatch(array $requests): TextBatchResult
    {
        throw new \LogicException('ReplayLlm has no raw-text fixture responses');
    }

    /**
     * @param array<string, mixed>  $plan
     * @param array<string, string> $titles
     * @return array<string, mixed>
     */
    private static function retitled(array $plan, array $titles): array
    {
        foreach ($plan['pages'] ?? [] as $i => $page) {
            $slug = (string) ($page['slug'] ?? '');
            if (is_array($page) && isset($titles[$slug]) && is_string($titles[$slug])) {
                $plan['pages'][$i]['title'] = $titles[$slug];
            }
        }

        return $plan;
    }
}
