<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\GeneratedJsonException;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\WritingDirection;

/**
 * Step 2: produce the canonical site spec from either a host-supplied
 * `meta.json.site_spec` value or an LLM reading the user's creation prompt.
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
 * identity (name / persona_name) that masthead, hero, and footer copy must
 * agree on. Identity values the model invented (because the user stated none)
 * are listed under `invented`. Contact facts (email_domain, emails, phones,
 * street addresses, URLs) are never invented — they stay empty unless the
 * user stated them.
 *
 * The user prompt and this spec are the inputs the theme-json and landing-page
 * steps build the design from.
 *
 * A supplied spec replaces only candidate generation: it goes through the same
 * deterministic normalization, page-scope rules, and warning path as model
 * output. This keeps siteSpec.json as the declared step artifact and lets hosts
 * carry the input through the portable meta.json boundary without an LLM call.
 *
 * The page tree is normally the candidate spec's decision (scoped by the
 * multi_page flag), but a caller can fix it: meta.json `pages` (--pages on the
 * runners, or pre-seeded by a host that already names its pages) makes the
 * final spec reproduce exactly that tree and take only matching per-page
 * purposes from the candidate.
 */
final class SiteSpecStep implements Step
{
    use LlmOptions;

    /** Factual properties the spec must always carry. */
    private const REQUIRED = ['name', 'title', 'description', 'site_type', 'topic', 'area', 'audience', 'visual_vibe', 'persona_name'];

    /** Identity keys the model may invent (and must then flag in `invented`). */
    private const IDENTITY_KEYS = ['name', 'persona_name'];

    /** A user-stated contact domain; empty is the no-contact-domain state. */
    private const EMAIL_DOMAIN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/';

    /** Extra keys whose values are contact facts, not identity or copy. Matched
     * against a key normalized by keyNamesContact(), so it needs no case flag. */
    private const CONTACT_KEY = '/(?<![a-z])(?:e[-_]?mails?|phones?|telephones?|mobiles?|tels?|whats[-_]?app|fax(?:es)?|address(?:es)?|streets?|urls?|websites?|instagram|twitter|facebook|linkedin|social)(?![a-z])/';

    /** Internal artifact basenames that generated page slugs must not claim. */
    private const RESERVED_PAGE_SLUGS = ['preview'];

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
            writes: ['siteSpec.json', 'warnings.json'],
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
        // Validate an explicit caller value before spending the site-spec LLM
        // call. The generated spec never owns this field.
        $callerWritingDirection = array_key_exists('writing_direction', $meta)
            ? WritingDirection::validate($meta['writing_direction'])
            : null;

        // Inner pages are opt-in: runners record --multi-page, while the facade
        // resolves an omitted scope to true for a supplied spec. Without either,
        // normalize() enforces a landing page only.
        $multiPage = (bool) ($meta['multi_page'] ?? false);

        // A caller-fixed page list takes the page-tree decision away from the
        // candidate: the LLM prompt echoes it on the generated path, and
        // normalize() enforces it on both paths. Only honored on multi-page
        // builds — the flag owns WHETHER inner pages exist; the list says WHICH.
        $requested = $multiPage ? self::requestedPages($meta['pages'] ?? null) : [];

        $warnings = [];
        if (array_key_exists('site_spec', $meta)) {
            if (!is_array($meta['site_spec'])) {
                throw new \RuntimeException('meta.json "site_spec" must be a JSON object');
            }
            $spec = $meta['site_spec'];
            Narrator::write("  using host-supplied site spec (no site-spec LLM call)\n");
        } else {
            $rendered = $this->renderer->render('site-spec.md', [
                'user_prompt'     => $prompt,
                'page_tree_scope' => $requested !== [] ? self::REQUESTED_SCOPE
                    : ($multiPage ? self::MULTI_PAGE_SCOPE : self::SINGLE_PAGE_SCOPE),
                'page_tree_rule'  => $requested !== [] ? self::requestedRule($requested)
                    : ($multiPage ? self::MULTI_PAGE_RULE : self::SINGLE_PAGE_RULE),
            ]);
            try {
                $spec = $this->llm->completeJson($rendered, $this->withOptions(['log_label' => $this->id()]));
            } catch (GeneratedJsonException $e) {
                // Only terminal generated-content failures degrade. Transport,
                // sender-contract, and programming exceptions remain fatal.
                $spec = [];
                $warnings[] = 'siteSpec.json: generated JSON remained unusable after its repair attempt ('
                    . $e->getMessage() . '); deterministic prompt-derived site spec delivered';
            }
        }

        // Contact facts are grounded in what the USER wrote. refine-prompt runs
        // immediately before this step and replaces meta's `prompt` with its own
        // rewrite, so grounding against that would let a contact detail refine
        // invented vouch for itself. `original_prompt` is absent only when no
        // refinement happened, and `prompt` is then the raw input.
        $stated = $meta['original_prompt'] ?? null;
        $statedPrompt = is_string($stated) && trim($stated) !== '' ? $stated : $prompt;

        $spec = self::normalize(
            $spec,
            $multiPage,
            $requested,
            $warnings,
            self::nameFromPrompt($prompt),
            $statedPrompt,
            array_key_exists('site_spec', $meta),
        );
        $spec['writing_direction'] = $callerWritingDirection
            ?? WritingDirection::fromLanguage((string) ($spec['language'] ?? ''));
        if ($warnings !== []) {
            $project->addWarnings($this->id(), $warnings);
            Narrator::write('  [site-spec] warning: ' . count($warnings)
                . " spec field(s) repaired with deterministic fallbacks (recorded in warnings.json)\n");
        }
        $project->writeJson('siteSpec.json', $spec);
    }

    /**
     * A deterministic site name derived from the user prompt, for specs whose
     * model output carried none: the prompt's first few words, cleaned up.
     * Pure — unit-testable.
     */
    public static function nameFromPrompt(string $prompt): string
    {
        $words = preg_split('/\s+/', trim($prompt), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // Punctuation becomes a space (not deleted) so hyphenated words stay
        // separate; MB_CASE_TITLE capitalizes a leading multibyte letter that
        // byte-oriented ucwords() would leave lowercase.
        $name = trim((string) preg_replace(
            '/[^\p{L}\p{N}]+/u',
            ' ',
            implode(' ', array_slice($words, 0, 6)),
        ));
        $name = trim(mb_substr($name, 0, 48));
        return $name !== '' ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8') : 'New Site';
    }

    /**
     * The committed copy language from siteSpec.json, for wiring into the
     * {{language}} placeholder of downstream prompts. Falls back to a
     * descriptive phrase for specs that arrive without the field — a
     * host-supplied spec never carries one — so the rendered rule still reads
     * as an instruction.
     *
     * The fallback names the SITE SPEC, not the user prompt: the prompts that
     * author visitor-facing copy (section, hero, header, footer, and the
     * HTML-first page prompts) all carry {{site_spec}} but NONE of them carry
     * {{user_prompt}}, so a rule pointing at the prompt names a document the
     * model cannot see. Left unresolvable, the richest language signal in
     * context is the site's address, and a francophone address produced a
     * French About page on an otherwise English site (BIGR-849). The spec is
     * a faithful stand-in: its own title, description, and page purposes are
     * written in the user's language on both the generated and host paths.
     */
    public static function languageOf(Project $project): string
    {
        $language = trim((string) ($project->readJson('siteSpec.json')['language'] ?? ''));
        return $language !== ''
            ? $language
            : "the SITE SPEC's own language (never a language implied by the site's location or audience)";
    }

    /** The normalized logical writing direction persisted in siteSpec.json. */
    public static function writingDirectionOf(Project $project): string
    {
        $direction = strtolower(trim((string) (
            $project->readJson('siteSpec.json')['writing_direction'] ?? ''
        )));
        return in_array($direction, WritingDirection::VALUES, true) ? $direction : 'ltr';
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
     * Extra factual keys the user stated pass through — the spec has no
     * exhaustive schema beyond the required properties. On the generated
     * path, contact-shaped extras must also appear in the user prompt. No
     * design fields are filled in: design is decided later by the theme-json
     * and landing-page steps.
     *
     * @param array<mixed>                       $spec
     * @param array<int,array<string,mixed>>     $requested caller-fixed page
     *        list (already normalized by requestedPages); [] = model decides
     * @param list<string>                       $warnings appended to in place
     * @return array<mixed>
     */
    private static function normalize(
        array $spec,
        bool $multiPage,
        array $requested = [],
        array &$warnings = [],
        string $fallbackName = 'New Site',
        string $statedPrompt = '',
        bool $hostSupplied = false,
    ): array {
        $name = trim((string) ($spec['name'] ?? ''));
        if ($name === '') {
            $name = $fallbackName;
            $warnings[] = "site spec has no \"name\"; using \"{$fallbackName}\" derived from the prompt";
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
        $pageWarnings = [];
        $spec['pages'] = self::normalizePages($spec['pages'] ?? null, $spec, $multiPage, $pageWarnings);
        if ($requested !== []) {
            // A caller-fixed list is promised to survive unchanged — same
            // order, same slugs, same titles (REQUESTED_SCOPE above, and the
            // tests that pin it). A host that explicitly asks for a Cart page
            // keeps its name and its route; the cart CONTENTS still degrade
            // downstream, where StorefrontDegrade::markup strips the purchase
            // controls the catalog cannot honor.
            $spec['pages'] = self::forcePages($requested, $spec['pages']);
        } else {
            array_push($warnings, ...$pageWarnings);
        }

        // `invented` lists which identity values the model made up; keep only
        // the identity keys so downstream features can trust its contents.
        $rawInvented = array_map(
            'strval',
            is_array($spec['invented'] ?? null) ? $spec['invented'] : [],
        );
        $invented = array_values(array_intersect($rawInvented, self::IDENTITY_KEYS));

        // Every piece of site copy is written in this language. A missing or
        // implausible value degrades to '' — languageOf() then renders the
        // "follow the spec's own language" instruction downstream — with
        // a durable warning, instead of aborting the build over one field.
        $language = trim((string) ($spec['language'] ?? ''));
        if ($language === '') {
            $warnings[] = 'site spec has no "language"; downstream copy follows the spec\'s own language';
        } elseif (!self::plausibleLanguage($language)) {
            $warnings[] = "site spec \"language\" is not a plausible language code or name: {$language}; "
                . "dropped — downstream copy follows the spec's own language";
            $language = '';
        }
        $spec['language'] = $language;

        // The user's explicit animation request, verbatim. Its presence is
        // what arms the optional custom-motion step; everything else in the
        // motion feature is preset-driven and must not be triggered here.
        $spec['animation_request'] = trim((string) ($spec['animation_request'] ?? ''));

        // Whether the site's core offering IS visual imagery. Anything but an
        // explicit boolean true degrades to false: the field only ever narrows
        // the hero recipe pool, so absence must never change behavior.
        $spec['subject_is_visual_work'] = ($spec['subject_is_visual_work'] ?? null) === true;

        // Empty email_domain is the no-contact-domain state; do not fill it from the slug.
        $domain = strtolower(trim((string) ($spec['email_domain'] ?? '')));
        if ($domain !== '' && preg_match(self::EMAIL_DOMAIN, $domain) !== 1) {
            $warnings[] = "site spec \"email_domain\" is not a usable domain: {$domain}; "
                . 'dropped rather than inventing one';
            $domain = '';
        } elseif ($domain !== '' && !$hostSupplied && !self::promptStatesDomain($statedPrompt, $domain)) {
            $warnings[] = 'site spec "email_domain" was not stated in the prompt; dropped';
            $domain = '';
        } elseif ($domain !== '' && $hostSupplied && in_array('email_domain', $rawInvented, true)) {
            $warnings[] = 'site spec "email_domain" was invented rather than stated; dropped';
            $domain = '';
        }
        $spec['email_domain'] = $domain;
        $spec['invented'] = array_values(array_unique($invented));

        if (!$hostSupplied) {
            $spec = self::scrubUngroundedContact($spec, $statedPrompt, $warnings);
        }

        return $spec;
    }

    /**
     * Drop generated emails, phones, streets, and URLs that do not appear in
     * the user prompt. Host-supplied specs skip this: the host already stated
     * those facts by handing them over.
     *
     * @param array<mixed> $spec
     * @param list<string> $warnings
     * @return array<mixed>
     */
    private static function scrubUngroundedContact(array $spec, string $statedPrompt, array &$warnings): array
    {
        $reserved = [
            'name' => true,
            'slug' => true,
            'title' => true,
            'description' => true,
            'site_type' => true,
            'topic' => true,
            'area' => true,
            'audience' => true,
            'language' => true,
            'writing_direction' => true,
            'persona_name' => true,
            'email_domain' => true,
            'invented' => true,
            'visual_vibe' => true,
            'subject_is_visual_work' => true,
            'animation_request' => true,
            'sections' => true,
            'pages' => true,
        ];
        foreach ($spec as $key => $value) {
            if (isset($reserved[(string) $key])) {
                continue;
            }
            $cleaned = self::scrubContactNode($value, (string) $key, $statedPrompt, (string) $key, $warnings);
            if ($cleaned === null) {
                unset($spec[$key]);
            } else {
                $spec[$key] = $cleaned;
            }
        }
        return $spec;
    }

    /**
     * @param list<string> $warnings
     */
    private static function scrubContactNode(
        mixed $value,
        string $key,
        string $statedPrompt,
        string $path,
        array &$warnings,
    ): mixed {
        if (is_array($value)) {
            $wasList = array_is_list($value);
            $hadEntries = $value !== [];
            foreach ($value as $childKey => $child) {
                $childPath = is_int($childKey) ? $path . '[]' : $path . '.' . $childKey;
                // A list item inherits its parent's key: "address": ["24 Market
                // Street"] is as much a street as "address": "24 Market Street".
                $childName = is_int($childKey) ? $key : (string) $childKey;
                $cleaned = self::scrubContactNode($child, $childName, $statedPrompt, $childPath, $warnings);
                if ($cleaned === null) {
                    unset($value[$childKey]);
                } else {
                    $value[$childKey] = $cleaned;
                }
            }
            // Reindex: dropping a middle item would otherwise turn the list
            // into a JSON object keyed by the surviving offsets.
            if ($wasList) {
                $value = array_values($value);
            }
            // Only an array the scrub emptied is dropped; one that arrived
            // empty is not a contact fact and keeps its key.
            return $hadEntries && $value === [] ? null : $value;
        }
        if (!is_string($value)) {
            // A phone emitted as a JSON number is still a phone, but only its key
            // can say so — a bare number carries no contact shape of its own.
            if ((is_int($value) || is_float($value)) && self::keyNamesContact($key)
                && !self::promptContains($statedPrompt, (string) $value)
            ) {
                $warnings[] = "site spec \"{$path}\" was not stated in the prompt; dropped";
                return null;
            }
            return $value;
        }
        $text = trim($value);
        if ($text === '' || !self::isContactShaped($key, $text)) {
            return $value;
        }
        if (self::promptContains($statedPrompt, $text)) {
            return $value;
        }
        $warnings[] = "site spec \"{$path}\" was not stated in the prompt; dropped";
        return null;
    }

    private static function isContactShaped(string $key, string $value): bool
    {
        if (self::keyNamesContact($key)) {
            return true;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return true;
        }
        if (preg_match('#^https?://#i', $value) === 1) {
            return true;
        }
        if (str_starts_with(strtolower($value), 'mailto:')
            || str_starts_with(strtolower($value), 'tel:')
        ) {
            return true;
        }
        return preg_match('/^\+?[0-9][0-9(). -]{6,}$/', $value) === 1;
    }

    /**
     * Whether the key names a contact fact. Generated JSON spells one key three
     * ways — emailAddress, email_address, email — so the word boundaries the
     * pattern needs (to keep `tel` out of `hotel`) are cut at a camelCase hump
     * too, or every camelCase contact key reads as ordinary copy.
     */
    private static function keyNamesContact(string $key): bool
    {
        $words = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key));
        return preg_match(self::CONTACT_KEY, $words) === 1;
    }

    private static function promptStatesDomain(string $prompt, string $domain): bool
    {
        return self::promptContains($prompt, $domain)
            || self::promptContains($prompt, '@' . $domain);
    }

    private static function promptContains(string $prompt, string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '' || trim($prompt) === '') {
            return false;
        }
        return mb_stripos($prompt, $needle) !== false;
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
     * @param list<string> $warnings
     * @return array<int,array<string,mixed>>
     */
    public static function normalizePages($raw, array $spec, bool $multiPage = true, array &$warnings = []): array
    {
        $seen = self::initialPageSlugSet();
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
        [$pages, $cartWarnings] = \Automattic\SiteBuild\StorefrontDegrade::pages($pages);
        array_push($warnings, ...$cartWarnings);
        return $pages;
    }

    /** Seed reserved slugs into the shared recursive uniqueness state. */
    private static function initialPageSlugSet(): array
    {
        return array_fill_keys(self::RESERVED_PAGE_SLUGS, true);
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
        $seen = self::initialPageSlugSet();
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
