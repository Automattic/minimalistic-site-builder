<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step 2 (LLM): produce the site spec from the user's creation prompt.
 *
 * Input:  meta.json (the user prompt, seeded by the runner)
 * Output: siteSpec.json — FACTUAL site information only (name, slug, title,
 *         type, topic, area, audience, a short visual vibe, required sections),
 *         plus any concrete facts the user stated. No design decisions
 *         (colors/typography/layout) live here — those are made later, inline,
 *         by the theme-json and landing-page steps.
 *
 * The spec is also the single source of truth for voice and identity: it
 * records the `language` all site copy must be written in, and one committed
 * identity (name / persona_name / email_domain) that masthead, hero, contact
 * and footer copy must agree on. Identity values the model invented (because
 * the user stated none) are listed under `invented`.
 *
 * The user prompt and this spec are the inputs the theme-json and landing-page
 * steps build the design from.
 */
final class SiteSpecStep implements Step
{
    use LlmOptions;

    /** Factual properties the spec must always carry. */
    private const REQUIRED = ['name', 'title', 'description', 'site_type', 'topic', 'area', 'audience', 'visual_vibe', 'persona_name'];

    /** Identity keys the model may invent (and must then flag in `invented`). */
    private const IDENTITY_KEYS = ['name', 'persona_name', 'email_domain'];

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
    ) {}

    public function id(): string
    {
        return 'site-spec';
    }

    public function label(): string
    {
        return 'Generate site spec';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['meta.json'],
            writes: ['siteSpec.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $meta = $project->readJson('meta.json');
        $prompt = (string) ($meta['prompt'] ?? '');
        if (trim($prompt) === '') {
            throw new \RuntimeException('meta.json has no "prompt"');
        }

        $rendered = $this->renderer->render('site-spec.md', ['user_prompt' => $prompt]);
        $spec = $this->llm->completeJson($rendered, $this->withOptions(['log_label' => $this->id()]));

        $spec = self::normalize($spec);
        $project->writeJson('siteSpec.json', $spec);
    }

    /**
     * The committed copy language from siteSpec.json, for wiring into the
     * {{language}} placeholder of downstream prompts. Falls back to a
     * descriptive phrase for specs that predate the language field, so the
     * rendered rule still reads as an instruction.
     */
    public static function languageOf(Project $project): string
    {
        $language = trim((string) ($project->readJson('siteSpec.json')['language'] ?? ''));
        return $language !== '' ? $language : 'the language the user prompt is written in';
    }

    /**
     * The user's explicit animation request, verbatim — '' when none was
     * stated (the default path). Non-empty is what lets CustomMotionStep run.
     */
    public static function animationRequestOf(Project $project): string
    {
        if (!$project->exists('siteSpec.json')) {
            return '';
        }
        return trim((string) ($project->readJson('siteSpec.json')['animation_request'] ?? ''));
    }

    /**
     * Require the fixed factual properties and normalize name/slug/sections.
     * Any extra factual keys the model returned pass through untouched — the
     * spec has no fixed/exhaustive schema beyond the required properties. No
     * design fields are filled in: design is decided later by the theme-json
     * and landing-page steps.
     *
     * @param array<mixed> $spec
     * @return array<mixed>
     */
    private static function normalize(array $spec): array
    {
        $name = trim((string) ($spec['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('site spec has no "name"');
        }

        $slug = ProjectStore::slugify((string) ($spec['slug'] ?? $name));
        $spec['name'] = $name;
        $spec['slug'] = $slug;

        // Title falls back to the name when the model omits it.
        if (trim((string) ($spec['title'] ?? '')) === '') {
            $spec['title'] = $name;
        }

        // Fill the remaining factual properties with a benign empty string so
        // downstream consumers can rely on the keys existing.
        foreach (self::REQUIRED as $key) {
            if (trim((string) ($spec[$key] ?? '')) === '') {
                $spec[$key] = '';
            }
        }

        // Sections must be a list so the section-plan step can build on it.
        if (!isset($spec['sections']) || !is_array($spec['sections'])) {
            $spec['sections'] = [];
        }

        // `invented` lists which identity values the model made up; keep only
        // the identity keys so downstream features can trust its contents.
        $invented = array_values(array_intersect(
            array_map('strval', is_array($spec['invented'] ?? null) ? $spec['invented'] : []),
            self::IDENTITY_KEYS,
        ));

        // Every piece of site copy is written in this language; a spec without
        // one would let each downstream prompt pick its own.
        $language = trim((string) ($spec['language'] ?? ''));
        if ($language === '') {
            throw new \RuntimeException('site spec has no "language"');
        }
        if (!self::plausibleLanguage($language)) {
            throw new \RuntimeException("site spec \"language\" is not a plausible language code or name: {$language}");
        }
        $spec['language'] = $language;

        // The user's explicit animation request, verbatim. Its presence is
        // what arms the optional custom-motion step; everything else in the
        // motion feature is preset-driven and must not be triggered here.
        $spec['animation_request'] = trim((string) ($spec['animation_request'] ?? ''));

        // Contact emails are minted from this domain; when the model returned
        // none (or something that is not a domain), derive it from the slug so
        // the identity stays coherent — and flag it as invented.
        $domain = strtolower(trim((string) ($spec['email_domain'] ?? '')));
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain)) {
            $domain = str_replace('-', '', $slug) . '.com';
            $invented[] = 'email_domain';
        }
        $spec['email_domain'] = $domain;
        $spec['invented'] = array_values(array_unique($invented));

        return $spec;
    }

    /** A BCP-47-ish code ("en", "es-AR", "pt_BR") or a plain language name ("Spanish"). */
    private static function plausibleLanguage(string $language): bool
    {
        return preg_match('/^[a-z]{2,3}([_-][a-z0-9]{2,8})*$/i', $language) === 1
            || preg_match("/^\p{L}[\p{L}' -]{1,39}$/u", $language) === 1;
    }
}
