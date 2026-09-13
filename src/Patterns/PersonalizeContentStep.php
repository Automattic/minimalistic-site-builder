<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step (LLM): write this site's words into the patterns already chosen.
 *
 * Extracted from Big Sky's `Replace_Content`, split into the three operations
 * VIPPROD-1180 asks for: collect a request from the layouts and the supplied
 * facts, make the model call, consume the answer back into those layouts. The
 * first and third are `ContentSlots`, which is pure and needs no model to test;
 * the deterministic half — contact details, hours, social links, conditional
 * blocks — is `ContentBindings` and runs before the model sees the page.
 *
 * What the host used to supply is now explicit: facts instead of an agent
 * context, the model as an argument, and slot ids derived from where a block
 * sits instead of `md5(uniqid(mt_rand()))`, so a fixture replay can line a
 * response up with the page it was generated for.
 *
 * Two of Big Sky's behaviours are kept deliberately, because the library's
 * generic JSON repair does not replace them: the response is validated against
 * what was asked for and only the items that failed are asked again, and a slot
 * that never came back keeps the pattern's own copy rather than emptying. The
 * second one is why every count here is reported — a page that kept every
 * placeholder and returned success is the failure this stage is most likely to
 * have.
 */
final class PersonalizeContentStep implements Step
{
    /**
     * How far over its limit a piece of copy may be before it is asked again.
     *
     * Big Sky's buffer, kept: models miscount words, and a two-word button that
     * comes back three words still fits, while the same button as a sentence
     * does not.
     */
    private const WORD_BUFFER = 2;

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
    ) {
    }

    public function id(): string
    {
        return 'personalize-content';
    }

    public function label(): string
    {
        return 'Write content into the chosen patterns';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [PatternArtifacts::NORMALIZED, PatternArtifacts::PLAN, PatternArtifacts::LAYOUTS],
            writes: [PatternArtifacts::PAGES],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $inputs = $project->readJson(PatternArtifacts::NORMALIZED);
        NormalizeInputsStep::assertVersion($inputs);

        $planned = self::bySlug($project->readJson(PatternArtifacts::PLAN)['pages'] ?? []);
        $layouts = $project->readJson(PatternArtifacts::LAYOUTS)['pages'] ?? [];

        if ($layouts === []) {
            throw new \RuntimeException('No layouts were composed, so there is no page to write content into.');
        }

        $warnings = [];
        $slotsFound = 0;

        foreach ($layouts as $layout) {
            $slug = (string) ($layout['slug'] ?? '');
            $page = $planned[$slug] ?? null;

            // The plan is where a page's title and order live; the layouts only
            // carry its sections. A slug in one and not the other means the two
            // disagree about what site this is, and guessing a title here would
            // publish a page named after a slug.
            if ($page === null) {
                throw new \RuntimeException(sprintf(
                    'The layouts contain a page "%s" the plan does not. The plan has: %s.',
                    $slug,
                    implode(', ', array_keys($planned)) ?: '(none)',
                ));
            }

            $written = $this->writePage($project, $slug, $page, $layout, $inputs);
            $slotsFound += $written['slots'];
            $warnings = array_merge($warnings, $written['warnings']);
        }

        // Every page written from patterns that hold no rewritable text at all.
        // The pages exist, the build reports success, and the site goes out
        // carrying the placeholder copy the patterns shipped with. Observed
        // against an inventory whose entries were empty markup: four pages,
        // no model call, no warning, 0.00s.
        if ($slotsFound === 0) {
            throw new \RuntimeException(
                'No page offered a single piece of text to write. The chosen patterns hold no '
                . 'heading, paragraph, button or list item, which usually means the inventory '
                . 'carries placeholder markup rather than the patterns the theme registers.'
            );
        }

        $project->replaceWarnings($this->id(), $warnings);
    }

    /**
     * One page: bindings, then the model, then the words written back in.
     *
     * @param array<string, mixed> $page
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $inputs
     * @return array{slots: int, warnings: list<string>}
     */
    private function writePage(
        Project $project,
        string $slug,
        array $page,
        array $layout,
        array $inputs,
    ): array {
        $markup = self::sectionsOf($layout);
        if (trim($markup) === '') {
            throw new \RuntimeException(sprintf('Page "%s" composed to no markup at all.', $slug));
        }

        $facts = is_array($inputs['facts'] ?? null) ? $inputs['facts'] : [];
        $bindings = ContentBindings::apply($markup, $facts);
        $declaredFeatures = is_array($facts['features'] ?? null) && $facts['features'] !== [];
        $slots = ContentSlots::in($bindings['markup'], $slug);
        $request = $slots->request();

        $asked = $request === []
            ? ['answers' => [], 'reasons' => []]
            : $this->ask($request, $slug, $page, $inputs);
        $outcome = $slots->fill($asked['answers']);

        $project->writeJson(rtrim(PatternArtifacts::PAGES, '*') . $slug . '.json', [
            'slug' => $slug,
            'title' => (string) ($page['title'] ?? ucfirst($slug)),
            'front' => (bool) ($page['front'] ?? false),
            'menu_order' => (int) ($page['menu_order'] ?? 0),
            'content' => $outcome['markup'],
        ]);

        return [
            'slots' => count($request),
            'warnings' => self::warnings($slug, $request, $bindings, $outcome, $asked['reasons'], $declaredFeatures),
        ];
    }

    /**
     * The copy for one page, asked for once and then asked again for whatever
     * came back wrong.
     *
     * @param list<array<string, mixed>> $request
     * @param array<string, mixed>       $page
     * @param array<string, mixed>       $inputs
     * @return array{answers: array<string, string>, reasons: array<string, string>}
     */
    private function ask(array $request, string $slug, array $page, array $inputs): array
    {
        $round = self::read($this->call($request, $slug, $page, $inputs), $request);
        $answers = $round['answers'];
        $reasons = $round['reasons'];
        $missing = self::unanswered($request, $answers);

        // Asking again for everything is a second full generation that may fail
        // the same way; asking for nothing leaves the page half written. Big
        // Sky's rule, kept: one more round, only for what failed, and only when
        // something did come back.
        if ($missing !== [] && count($missing) < count($request)) {
            $retry = self::read($this->call($missing, $slug, $page, $inputs), $missing);
            $answers += $retry['answers'];
            $reasons = array_merge($reasons, $retry['reasons']);
        }

        return [
            'answers' => $answers,
            'reasons' => array_diff_key($reasons, $answers),
        ];
    }

    /**
     * @param list<array<string, mixed>> $request
     * @param array<string, mixed>       $page
     * @param array<string, mixed>       $inputs
     * @return array<string, mixed>
     */
    private function call(array $request, string $slug, array $page, array $inputs): array
    {
        $prompt = $this->renderer->render('pattern-personalize-content.md', [
            'facts' => (string) json_encode($inputs['facts'] ?? [], JSON_PRETTY_PRINT),
            'slug' => $slug,
            'title' => (string) ($page['title'] ?? $slug),
            'purpose' => (string) ($page['description'] ?? 'Not stated.'),
            'locale' => (string) ($inputs['locale'] ?? 'en'),
            'slots' => (string) json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'notes' => trim((string) ($inputs['facts']['notes'] ?? '')) ?: 'None.',
        ]);

        return $this->llm->completeJson($prompt, array_filter([
            'model' => $this->model,
            'json_schema' => ['name' => 'page_content', 'schema' => self::schema()],
            'max_tokens' => 8000,
        ]));
    }

    /**
     * The answers that can actually be used, keyed by slot.
     *
     * Everything rejected here is asked again, so this is the whole definition
     * of a bad answer. Big Sky also rejected copy that did not match a
     * classname — a price that was not `$12`, a menu item that read like a
     * call to action. Those are gone: the dollar sign rejects every site that
     * does not price in dollars, and the call-to-action list is English words
     * on a library that has to write Spanish. What is left holds for any site
     * in any language.
     *
     * Each rejection carries why. Big Sky builds the same explanation and
     * throws it away; without it a slot that kept its placeholder because the
     * copy was three words too long reads exactly like one the model never
     * answered, and those want opposite fixes.
     *
     * @param array<string, mixed>       $response
     * @param list<array<string, mixed>> $request
     * @return array{answers: array<string, string>, reasons: array<string, string>}
     */
    private static function read(array $response, array $request): array
    {
        $limits = [];
        $blocks = [];
        foreach ($request as $slot) {
            $limits[(string) $slot['id']] = (int) $slot['max_words'];
            $blocks[(string) $slot['id']] = (string) $slot['block'];
        }

        $answers = [];
        $reasons = array_fill_keys(array_keys($limits), 'the model returned nothing for it');

        foreach ($response['content'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = (string) ($item['id'] ?? '');
            $text = trim((string) ($item['text'] ?? ''));

            if (!isset($limits[$id])) {
                continue;
            }

            if ($text === '') {
                $reasons[$id] = 'the model returned an empty string';
                continue;
            }

            $limit = $limits[$id];
            $words = ContentSlots::wordCount($text);
            if ($limit > 0 && $words > $limit + self::WORD_BUFFER) {
                $reasons[$id] = sprintf('%d words where the pattern has room for %d', $words, $limit);
                continue;
            }

            $answers[$id] = $blocks[$id] === 'core/heading' ? self::sentenceCase($text) : $text;
        }

        return ['answers' => $answers, 'reasons' => array_diff_key($reasons, $answers)];
    }

    /**
     * The slots still without usable copy, in the request's own shape so the
     * retry asks for them exactly as the first call did.
     *
     * @param list<array<string, mixed>> $request
     * @param array<string, string>      $answers
     * @return list<array<string, mixed>>
     */
    private static function unanswered(array $request, array $answers): array
    {
        return array_values(array_filter(
            $request,
            static fn (array $slot): bool => !isset($answers[(string) $slot['id']]),
        ));
    }

    /**
     * What a reader of the report needs to know went wrong, per page.
     *
     * Blocks removed because a fact is missing are not listed: a site with no
     * opening hours dropping its opening-hours block is the binding working.
     * What is listed is the case where that judgement could not be made —
     * conditional blocks removed by a site that declared no features at all,
     * which is what a host that forgot the field looks like from in here.
     *
     * @param list<array<string, mixed>>                                     $request
     * @param array{hidden: list<string>, deferred: list<string>}            $bindings
     * @param array{filled: int, kept: list<string>, flattened: list<string>} $outcome
     * @param array<string, string>                                           $reasons
     * @return list<string>
     */
    private static function warnings(
        string $slug,
        array $request,
        array $bindings,
        array $outcome,
        array $reasons,
        bool $declaredFeatures,
    ): array {
        $warnings = [];

        if ($request === []) {
            $warnings[] = sprintf('%s: no slot to write into, so the page keeps the patterns as they are', $slug);
        }

        if ($outcome['kept'] !== []) {
            $named = array_map(
                static fn (string $id): string => $id . ' (' . ($reasons[$id] ?? 'unknown') . ')',
                $outcome['kept'],
            );
            $warnings[] = sprintf(
                '%s: %d of %d slots kept the pattern\'s own copy: %s',
                $slug,
                count($outcome['kept']),
                count($request),
                implode(', ', $named),
            );
        }

        if ($outcome['flattened'] !== []) {
            $warnings[] = sprintf(
                '%s: %d slots held inline markup that the replacement dropped: %s',
                $slug,
                count($outcome['flattened']),
                implode(', ', $outcome['flattened']),
            );
        }

        if (!$declaredFeatures && $bindings['hidden'] !== []) {
            $warnings[] = sprintf(
                '%s: %d conditional blocks were removed because the request declared no features: %s',
                $slug,
                count($bindings['hidden']),
                implode(', ', $bindings['hidden']),
            );
        }

        foreach ($bindings['deferred'] as $block) {
            $warnings[] = sprintf('%s: %s is bound to media that resolve-media has not filled yet', $slug, $block);
        }

        return $warnings;
    }

    /**
     * Every section of a page, in order, as one document.
     *
     * The sections are concatenated before anything reads them so a slot id is
     * unique across the page and a group path spans it, which is what lets the
     * model write a heading in one section knowing what the next one says.
     *
     * @param array<string, mixed> $layout
     */
    private static function sectionsOf(array $layout): string
    {
        $sections = [];
        foreach ($layout['sections'] ?? [] as $section) {
            $content = trim((string) ($section['content'] ?? ''));
            if ($content !== '') {
                $sections[] = $content;
            }
        }

        return implode("\n\n", $sections);
    }

    /**
     * A capital where a heading starts.
     *
     * `mb_*` because `ucfirst` mangles "Únete". Left alone when the second
     * letter is already a capital, which is the shape of a name that owns its
     * own lowercase first letter — "iPhone repairs", "eBay listings".
     */
    private static function sentenceCase(string $text): string
    {
        $second = mb_substr($text, 1, 1);
        if ($second !== '' && mb_strtoupper($second) === $second && mb_strtolower($second) !== $second) {
            return $text;
        }

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @return array<string, array<string, mixed>>
     */
    private static function bySlug(array $pages): array
    {
        $bySlug = [];
        foreach ($pages as $page) {
            $slug = (string) ($page['slug'] ?? '');
            if ($slug !== '') {
                $bySlug[$slug] = $page;
            }
        }

        return $bySlug;
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['content'],
            'additionalProperties' => false,
            'properties' => [
                'content' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['id', 'text'],
                        'additionalProperties' => false,
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'text' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
