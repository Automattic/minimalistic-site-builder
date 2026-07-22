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
 *
 * The page tree is normally the model's decision (scoped by the multi_page
 * flag), but a caller can fix it: meta.json `pages` (--pages on the runners,
 * or pre-seeded by a host whose site spec already names its pages) makes the
 * prompt echo that exact list — the model contributes only per-page purposes —
 * and normalize() enforces it. Only the ABSENCE of the list lets the model
 * invent pages.
 */
final class SiteSpecStep implements Step
{
    use LlmOptions;

    /** Factual properties the spec must always carry. */
    private const REQUIRED = ['name', 'title', 'description', 'site_type', 'topic', 'area', 'audience', 'visual_vibe', 'persona_name'];

    /** Identity keys the model may invent (and must then flag in `invented`). */
    private const IDENTITY_KEYS = ['name', 'persona_name', 'email_domain'];

    /** {{page_tree_scope}} / {{page_tree_rule}} when inner pages are enabled (--multi-page). */
    private const MULTI_PAGE_SCOPE = '1-6 top-level pages; nest "children" ONLY where the site genuinely'
        . ' needs a second level (max depth 2). A one-page site (e.g. a simple landing page) is just the'
        . ' homepage entry.';
    private const MULTI_PAGE_RULE = '**Pages are factual scope decisions** — what the site covers, not how'
        . ' it looks. Ground the tree in `site_type` / `area` and what the prompt actually calls for: a'
        . ' restaurant wants something like home / menu / about / visit; a portfolio wants home / work /'
        . ' about / contact; a simple landing-page request wants ONLY the homepage. Each page\'s `purpose`'
        . ' states what content lives there so no two pages overlap. `sections` stays the homepage\'s'
        . ' section hint list.';

    /** The default: the build produces ONLY the landing page. */
    private const SINGLE_PAGE_SCOPE = 'exactly ONE entry — the homepage. This build is a one-page site:'
        . ' no inner pages, no "children".';
    private const SINGLE_PAGE_RULE = '**This is a one-page site** — `pages` holds exactly the homepage'
        . ' entry, and everything the site covers lives there. Fold what would otherwise be separate pages'
        . ' (about, menu, contact, …) into the homepage\'s `sections` list instead.';

    /** {{page_tree_scope}} when the caller fixed the page list (--pages / meta `pages`). */
    private const REQUESTED_SCOPE = 'EXACTLY the pages listed in the page-tree rule below — same order,'
        . ' same slugs, same titles, same nesting. Do not add, drop, merge, or rename pages.';

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

        // Inner pages are opt-in: the runner records the --multi-page flag in
        // meta.json; without it the spec plans (and normalize() enforces) a
        // landing page only.
        $multiPage = (bool) ($meta['multi_page'] ?? false);

        // A caller-fixed page list takes the page-tree decision away from the
        // model: the prompt asks it to echo the list and write purposes, and
        // normalize() enforces it. Only honored on multi-page builds — the
        // flag owns WHETHER inner pages exist; the list only says WHICH.
        $requested = $multiPage ? self::requestedPages($meta['pages'] ?? null) : [];

        $rendered = $this->renderer->render('site-spec.md', [
            'user_prompt'     => $prompt,
            'page_tree_scope' => $requested !== [] ? self::REQUESTED_SCOPE
                : ($multiPage ? self::MULTI_PAGE_SCOPE : self::SINGLE_PAGE_SCOPE),
            'page_tree_rule'  => $requested !== [] ? self::requestedRule($requested)
                : ($multiPage ? self::MULTI_PAGE_RULE : self::SINGLE_PAGE_RULE),
        ]);
        $spec = $this->llm->completeJson($rendered, $this->withOptions(['log_label' => $this->id()]));

        $spec = self::normalize($spec, $multiPage, $requested);
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
     * @param array<mixed>                       $spec
     * @param array<int,array<string,mixed>>     $requested caller-fixed page
     *        list (already normalized by requestedPages); [] = model decides
     * @return array<mixed>
     */
    private static function normalize(array $spec, bool $multiPage, array $requested = []): array
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

        // Sections must be a list so the page-plan step can build on it.
        if (!isset($spec['sections']) || !is_array($spec['sections'])) {
            $spec['sections'] = [];
        }

        // The page tree drives multi-page generation; every downstream step
        // may rely on at least a homepage entry existing. A caller-fixed list
        // wins over whatever tree the model returned — the model's tree can
        // contribute only per-page purposes.
        $spec['pages'] = self::normalizePages($spec['pages'] ?? null, $spec, $multiPage);
        if ($requested !== []) {
            $spec['pages'] = self::forcePages($requested, $spec['pages']);
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

    /**
     * Normalize the page tree: slugified, tree-wide unique slugs, title
     * fallback from the slug, children always a list. Junk entries are
     * dropped; a missing/empty tree degrades to a single homepage so a
     * one-page build is the floor, never a failure. The FIRST page is the
     * homepage — position is the contract, no flag is stored here.
     * When $multiPage is false the tree is CUT DOWN to the homepage — the
     * prompt already asks for one page, but the model doesn't always comply,
     * and the flag (not the model) owns that decision.
     * Pure — unit-testable.
     *
     * @param mixed        $raw
     * @param array<mixed> $spec
     * @return array<int,array<string,mixed>>
     */
    public static function normalizePages($raw, array $spec, bool $multiPage = true): array
    {
        $seen = [];
        $pages = is_array($raw) ? self::normalizePageList($raw, $seen) : [];
        if (!$multiPage && $pages !== []) {
            $pages = [array_merge($pages[0], ['children' => []])];
        }
        if ($pages === []) {
            return [[
                'title'    => 'Home',
                'slug'     => 'home',
                'purpose'  => trim((string) ($spec['description'] ?? '')),
                'children' => [],
            ]];
        }
        return $pages;
    }

    /**
     * One level of the page tree; $seen carries slug uniqueness across the
     * WHOLE tree (slugs become file names, permalinks, and manifest keys).
     *
     * @param array<mixed>       $raw
     * @param array<string,true> $seen
     * @return array<int,array<string,mixed>>
     */
    private static function normalizePageList(array $raw, array &$seen): array
    {
        $out = [];
        foreach ($raw as $i => $page) {
            if (!is_array($page)) {
                continue;
            }
            $title = trim((string) ($page['title'] ?? ''));
            $slug = ProjectStore::slugify((string) ($page['slug'] ?? ($title !== '' ? $title : 'page-' . $i)));
            $base = $slug;
            $n = 2;
            while (isset($seen[$slug])) {
                $slug = "{$base}-{$n}";
                $n++;
            }
            $seen[$slug] = true;

            $out[] = [
                'title'    => $title !== '' ? $title : ucwords(str_replace('-', ' ', $slug)),
                'slug'     => $slug,
                'purpose'  => trim((string) ($page['purpose'] ?? '')),
                'children' => is_array($page['children'] ?? null)
                    ? self::normalizePageList($page['children'], $seen)
                    : [],
            ];
        }
        return $out;
    }

    /**
     * The caller-fixed page list from meta.json `pages` (--pages on the
     * runners, or pre-seeded by a host whose site spec already names its
     * pages). Entries are title strings ("About") or page maps
     * ({title, slug, purpose, children}); junk is dropped. Normalized to the
     * same shape as the spec tree — first entry is the homepage. [] means the
     * caller fixed nothing and the model invents the tree. Pure — unit-testable.
     *
     * @param mixed $raw
     * @return array<int,array<string,mixed>>
     */
    public static function requestedPages($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $entries = [];
        foreach ($raw as $page) {
            if (is_string($page)) {
                if (trim($page) !== '') {
                    $entries[] = ['title' => trim($page)];
                }
            } elseif (is_array($page)) {
                $entries[] = $page;
            }
        }
        $seen = [];
        return self::normalizePageList($entries, $seen);
    }

    /**
     * Enforce the caller-fixed page list: the final tree IS $requested — pages
     * the model added are dropped, pages it lost come back, renames don't
     * stick. The model's only contribution is a `purpose` for each page the
     * caller left blank (matched by slug anywhere in its tree); purposes the
     * caller stated win. Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $requested  normalized (requestedPages)
     * @param array<int,array<string,mixed>> $modelPages normalized (normalizePages)
     * @return array<int,array<string,mixed>>
     */
    public static function forcePages(array $requested, array $modelPages): array
    {
        $purposes = [];
        self::collectPurposes($modelPages, $purposes);
        return self::graftPurposes($requested, $purposes);
    }

    /**
     * slug => purpose over a whole normalized tree (first occurrence wins).
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,string>           $purposes
     */
    private static function collectPurposes(array $pages, array &$purposes): void
    {
        foreach ($pages as $page) {
            $purpose = (string) ($page['purpose'] ?? '');
            if ($purpose !== '' && !isset($purposes[$page['slug']])) {
                $purposes[$page['slug']] = $purpose;
            }
            self::collectPurposes($page['children'] ?? [], $purposes);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,string>           $purposes
     * @return array<int,array<string,mixed>>
     */
    private static function graftPurposes(array $pages, array $purposes): array
    {
        return array_map(static fn (array $page): array => [
            'title'    => $page['title'],
            'slug'     => $page['slug'],
            'purpose'  => $page['purpose'] !== '' ? $page['purpose'] : ($purposes[$page['slug']] ?? ''),
            'children' => self::graftPurposes($page['children'], $purposes),
        ], $pages);
    }

    /**
     * {{page_tree_rule}} when the caller fixed the page list: the tree is not
     * the model's decision — it echoes the given pages and contributes only
     * each page's `purpose` (forcePages() enforces the list regardless).
     *
     * @param array<int,array<string,mixed>> $requested
     */
    private static function requestedRule(array $requested): string
    {
        return "**The page list is already decided** — the user supplied it, so reproduce it exactly:\n\n"
            . implode("\n", self::requestedTreeLines($requested))
            . "\n\nOutput `pages` as exactly this tree. Where no purpose is given above, write the"
            . ' `purpose` yourself — 1 sentence, grounded in the prompt, saying what content lives on'
            . ' that page so no two pages overlap. Given purposes are kept verbatim. `sections` stays'
            . " the homepage's section hint list.";
    }

    /**
     * One markdown bullet per requested page, indented by depth, carrying the
     * caller's purpose when stated.
     *
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,string>
     */
    private static function requestedTreeLines(array $pages, int $depth = 0): array
    {
        $lines = [];
        foreach ($pages as $i => $page) {
            $line = str_repeat('  ', $depth) . "- \"{$page['title']}\" (slug: {$page['slug']})";
            if ($depth === 0 && $i === 0) {
                $line .= ' — the homepage';
            }
            if ($page['purpose'] !== '') {
                $line .= " — purpose: {$page['purpose']}";
            }
            $lines[] = $line;
            foreach (self::requestedTreeLines($page['children'], $depth + 1) as $child) {
                $lines[] = $child;
            }
        }
        return $lines;
    }

    /** A BCP-47-ish code ("en", "es-AR", "pt_BR") or a plain language name ("Spanish"). */
    private static function plausibleLanguage(string $language): bool
    {
        return preg_match('/^[a-z]{2,3}([_-][a-z0-9]{2,8})*$/i', $language) === 1
            || preg_match("/^\p{L}[\p{L}' -]{1,39}$/u", $language) === 1;
    }
}
