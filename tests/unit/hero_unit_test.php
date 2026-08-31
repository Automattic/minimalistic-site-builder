<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\HeroComposition;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\GeneratedMarkup;
use Automattic\SiteBuild\Units\HeroUnit;
use Automattic\SiteBuild\Units\SectionUnit;

/**
 * @param array{label:string,intent:string,destination:string}|null $action
 * @return array<string,mixed>
 */
function hero_unit_contract_input(
    string $recipe = 'foreground-split',
    ?array $action = [
        'label' => 'Explore the work',
        'intent' => 'Help visitors reach the current work',
        'destination' => '/work/',
    ],
): array {
    $blueprint = HeroBlueprint::defaultFor($recipe);
    $projection = HeroComposition::planProjection($blueprint);
    $heroSection = [
        'slug' => 'hero',
        'title' => 'HERO-SECTION-TITLE-SENTINEL',
        'role' => 'hero',
        'type' => 'hero',
        'purpose' => 'HERO-PURPOSE-SENTINEL',
        'content_notes' => 'HERO-NOTES-SENTINEL',
        'layout_archetype' => $projection['layout_archetype'],
        'background' => $projection['default_background'],
        'vertical_density' => 'standard',
        'handoff' => 'HERO-HANDOFF-SENTINEL',
        'primary_action' => $action,
    ];
    $pages = [[
        'slug' => 'home', 'title' => 'HERO-PAGE-TITLE-SENTINEL', 'path' => '/', 'front' => true,
        'sections' => [$heroSection, [
            'slug' => 'work-preview', 'layout_archetype' => 'offset-grid',
            'background' => 'base', 'primary_action' => null,
        ]],
    ], [
        'slug' => 'work', 'title' => 'Work', 'path' => '/work/', 'front' => false,
        'sections' => [[
            'slug' => 'work-opening', 'layout_archetype' => 'centered-stack',
            'background' => 'base', 'primary_action' => null,
        ]],
    ]];
    $contract = AboveFoldContract::resolve(
        $pages,
        $blueprint,
        'full-bleed',
        ['base' => '#FFFFFF', 'contrast' => '#111111'],
        ['stable_id' => 'hero-unit', 'writing_direction' => 'ltr', 'page_count' => 2],
        ['archetype' => 'minimal-columns', 'surface' => 'base'],
        'standard-row',
    );

    return [
        'site_spec' => ['name' => 'HERO-SPEC-SENTINEL'],
        'language' => 'hero-language-sentinel',
        'theme_json' => ['version' => 3, 'hero-theme-sentinel' => true],
        'design_direction' => 'HERO-DIRECTION-SENTINEL',
        'outline' => "1. HERO-OUTLINE-SENTINEL (hero) [#hero]\n2. Work (content) [#work]",
        'site_pages' => '- "Home" — / (front page): HERO-PAGES-SENTINEL',
        'page' => [
            'slug' => 'home',
            'title' => 'HERO-PAGE-TITLE-SENTINEL',
            'path' => '/',
            'front' => true,
        ],
        'section' => $heroSection,
        'neighbors' => 'HERO-NEIGHBORS-SENTINEL',
        'hero_blueprint' => $blueprint,
        'above_fold_contract' => $contract,
        // The general-section field is deliberately present to prove the
        // dedicated prompt does not consume that competing contract.
        'header_contract' => 'GENERAL-SECTION-HEADER-CONTRACT-LEAK-SENTINEL',
    ];
}

function hero_unit_root(string $body): string
{
    return '<!-- wp:group {"anchor":"hero","className":"keep hero-composition--foreground-split hero-mobile--stack-copy-first","layout":{"type":"constrained"}} -->'
        . '<div id="hero" class="wp-block-group keep hero-composition--foreground-split hero-mobile--stack-copy-first">'
        . '<!-- wp:group {"className":"hero-composition__copy"} --><div class="wp-block-group hero-composition__copy">'
        . $body . '</div><!-- /wp:group -->'
        . '<!-- wp:group {"className":"hero-composition__media"} --><div class="wp-block-group hero-composition__media">'
        // The image request lives in alt, as prompts/image-generation.md
        // specifies, and its aspect matches the blueprint default. The old
        // fixture carried the request in src with an empty alt, which the
        // aspect check reads as a missing request (BIGR-912 made that check
        // reach the contained-split recipe, which it never covered before).
        . '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/subject.jpg"'
        . ' alt="AI_IMAGE: editorial subject | home hero | natural light | landscape" /></figure><!-- /wp:image -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
}

function assert_hero_unit_root_marker(string $markup, string $marker): void
{
    $document = BlockMarkup::parse($markup);
    $root = $document->topLevel();
    assert_true(is_int($root), 'hero markup has a root block');
    $attrs = $document->attrs($root);
    assert_true(is_array($attrs));
    $tokens = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: [];
    assert_eq(1, count(array_filter($tokens, static fn (string $token): bool => $token === $marker)));
    assert_eq(1, substr_count($document->ownHtml($root), $marker), 'saved root HTML carries the marker once');
}

test('HeroUnit exposes one isolated assigned recipe behind the shared site layer', function () {
    $renderer = new PromptRenderer(repo_path('prompts'));
    $markers = [
        'cinematic-safe-zone' => 'landscape cover stage',
        'foreground-split' => 'deliberately unequal copy and foreground-media regions',
        'layered-poster' => 'cover image beneath controlled block-built type',
    ];

    assert_eq(HeroComposition::RECIPES, array_keys($markers));
    foreach ($markers as $recipe => $marker) {
        $request = (new HeroUnit(new FakeLlm(), $renderer))->request(
            hero_unit_contract_input($recipe)
        );
        $prompt = markup_request_text($request);
        $blueprint = HeroBlueprint::defaultFor($recipe);

        assert_eq(1, count($request['cached_prefixes']), "{$recipe} carries only the shared site layer");
        assert_true(
            !str_contains($request['prompt'], 'SITE SPEC (JSON):'),
            "{$recipe} keeps the reusable site context out of its varying brief",
        );
        assert_contains("ASSIGNED HERO COMPOSITION for this build: **{$recipe}**", $prompt);
        assert_contains('hero-composition--' . $recipe, $prompt);
        assert_contains('hero-mobile--' . $blueprint['mobile_transformation'], $prompt);
        assert_contains('hero-composition__copy', $prompt);
        assert_contains('hero-composition__media', $prompt);
        assert_contains($marker, $prompt);
        foreach ($markers as $otherRecipe => $otherMarker) {
            if ($otherRecipe !== $recipe) {
                assert_true(
                    !str_contains($prompt, $otherMarker),
                    "{$otherRecipe} fragment is absent when {$recipe} is assigned",
                );
            }
        }
        assert_eq(
            HeroComposition::usesGeneratedImages($blueprint),
            str_contains($prompt, 'AI_IMAGE: subject | page-context | style | aspect-ratio'),
            "{$recipe} gates image-generation instructions from its delivered media mode",
        );
        assert_true(
            !str_contains($prompt, 'GENERAL-SECTION-HEADER-CONTRACT-LEAK-SENTINEL'),
            'the hero consumes only the canonical above-fold contract',
        );
    }
});

test('HeroUnit prompt bounds the standfirst without absorbing overflow', function () {
    $prompt = (new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts'))))
        ->request(hero_unit_contract_input());
    $prompt = markup_request_text($prompt);

    assert_contains('one sentence, about two set lines', $prompt, 'semantic length guidance stays primary');
    assert_contains('no more than roughly 180 characters', $prompt, 'the character guidance is an upper bound');
    assert_contains('Do not fold overflow into the standfirst', $prompt, 'section copy cannot fill the sole support slot');
    assert_true(!str_contains($prompt, 'fold it into the one standfirst'), 'the contradictory overflow option is absent');
});

test('HeroUnit prompt assigns the display token to every hero H1 recipe', function () {
    $renderer = new PromptRenderer(repo_path('prompts'));

    foreach (HeroComposition::RECIPES as $recipe) {
        $prompt = (new HeroUnit(new FakeLlm(), $renderer))
            ->request(hero_unit_contract_input($recipe));
        $prompt = markup_request_text($prompt);

        assert_contains('hero H1 always authors `"fontSize":"display"`', $prompt, "{$recipe} receives the masthead token contract");
        assert_contains('one level-1 `wp:heading` with `"fontSize":"display"`', $prompt, "{$recipe} receives the exact H1 shape");
        assert_true(
            !str_contains($prompt, 'never the `display` preset'),
            "{$recipe} does not contradict the display-token contract",
        );
        assert_true(
            !str_contains($prompt, 'step down to the largest heading preset'),
            "{$recipe} cannot author the cohort's section-title fallback",
        );
    }
});

test('HeroUnit prompt rejects generic headline registers without inventing differentiation', function () {
    $prompt = (new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts'))))
        ->request(hero_unit_contract_input());
    $prompt = markup_request_text($prompt);

    assert_contains('Never open with "Welcome to" (in any language)', $prompt);
    assert_contains('never let the headline be a bare category label', $prompt);
    assert_contains('grounded in the supplied SITE SPEC and section brief', $prompt);
    assert_contains('Specificity is not permission to invent a differentiator', $prompt);
    assert_contains('history, provenance, awards, superlatives', $prompt);
});

test('HeroUnit keeps portable identity and exact action facts in self-contained input', function () {
    $input = hero_unit_contract_input();
    $unit = new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $request = $unit->request($input);
    $prompt = markup_request_text($request);

    assert_eq('page-home--hero', $unit->key($input));
    assert_eq(
        $unit->key($input),
        (new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts'))))->key($input),
        'hero and ordinary section units share one part identity implementation',
    );
    foreach ([
        'HERO-SPEC-SENTINEL',
        'hero-language-sentinel',
        'hero-theme-sentinel',
        'HERO-DIRECTION-SENTINEL',
        'HERO-OUTLINE-SENTINEL',
        'HERO-PAGES-SENTINEL',
        'HERO-PAGE-TITLE-SENTINEL',
        'HERO-SECTION-TITLE-SENTINEL',
        'HERO-PURPOSE-SENTINEL',
        'HERO-NOTES-SENTINEL',
        'HERO-NEIGHBORS-SENTINEL',
    ] as $sentinel) {
        assert_contains($sentinel, $prompt);
    }
    assert_eq(1, substr_count($prompt, 'Explore the work'), 'authoritative action label appears once');
    assert_eq(1, substr_count($prompt, '/work/'), 'authoritative action destination appears once');
    assert_eq(1, substr_count($prompt, 'Help visitors reach the current work'), 'planning intent appears only inside the contract');

    $jsonInput = $input;
    $jsonInput['hero_blueprint'] = (string) json_encode(
        $input['hero_blueprint'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
    $jsonPrompt = $unit->request($jsonInput)['prompt'];
    assert_contains($jsonInput['hero_blueprint'], $jsonPrompt, 'portable JSON text is passed through intact');
    assert_eq(1, substr_count($jsonPrompt, 'Explore the work'));
    assert_eq(1, substr_count($jsonPrompt, '/work/'));
});

test('HeroUnit rejects non-front or incompatible blueprint input before the LLM', function () {
    foreach (['not-front', 'bad-mobile'] as $case) {
        $llm = new FakeLlm();
        $unit = new HeroUnit($llm, new PromptRenderer(repo_path('prompts')));
        $input = hero_unit_contract_input();
        if ($case === 'not-front') {
            $input['page']['front'] = false;
        } else {
            $input['hero_blueprint']['mobile_transformation'] = 'flatten-layers';
        }

        assert_throws(fn () => $unit->generate($input));
        assert_eq(0, $llm->completeCalls, "{$case} fails before model execution");
    }
});

test('HeroUnit rejects partial blueprints and contradictory portable contract projections before the LLM', function () {
    $cases = [
        'partial-blueprint-array' => static function (array $input): array {
            unset($input['hero_blueprint']['headline_line_target']);
            return $input;
        },
        'partial-blueprint-json' => static function (array $input): array {
            $input['hero_blueprint'] = '{"version":1,"recipe":"foreground-split","mobile_transformation":"stack-copy-first"}';
            return $input;
        },
        'viewport-drift' => static function (array $input): array {
            $input['above_fold_contract']['viewport']['headline_register'] = 'restrained';
            return $input;
        },
        'region-drift' => static function (array $input): array {
            $input['above_fold_contract']['regions']['text_safe'] = ['logical' => 'start', 'physical' => 'left'];
            return $input;
        },
        'action-treatment-drift' => static function (array $input): array {
            $input['above_fold_contract']['primary_action']['treatment'] = 'quiet';
            return $input;
        },
        'plan-projection-drift' => static function (array $input): array {
            $input['section']['layout_archetype'] = 'centered-stack';
            return $input;
        },
    ];

    foreach ($cases as $case => $mutate) {
        $llm = new FakeLlm();
        $unit = new HeroUnit($llm, new PromptRenderer(repo_path('prompts')));
        assert_throws(fn () => $unit->generate($mutate(hero_unit_contract_input())), $case);
        assert_eq(0, $llm->completeCalls, "{$case} fails during portable preflight");
    }
});

test('HeroUnit generate returns a JSON-serializable repairs and warnings envelope', function () {
    $llm = new FakeLlm();
    $llm->queueText(hero_unit_root(
        '<!-- wp:heading {"level":1} --><h1>Generated hero headline</h1><!-- /wp:heading -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons">'
        . '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/work/">Explore the work</a></div><!-- /wp:button -->'
        . '</div><!-- /wp:buttons -->'
    ));
    $unit = new HeroUnit($llm, new PromptRenderer(repo_path('prompts')));

    $result = $unit->generate(hero_unit_contract_input());

    assert_eq('page-home--hero', $llm->calls[0]['opts']['log_label'] ?? null);
    assert_eq(
        1,
        count($llm->calls[0]['opts']['cached_prefixes'] ?? []),
        'the shared site layer reaches the transport on the single-call path too',
    );
    assert_eq([], $result->warnings);
    assert_eq([], $result->repairs);
    assert_eq($result->toArray(), json_decode((string) json_encode($result), true));
});

test('HeroUnit warns for removed eyebrow and separator content while preserving headline-first copy', function () {
    $eyebrow = '<!-- wp:paragraph {"fontSize":"caption"} -->'
        . '<p class="has-caption-font-size">Tbilisi Old Town</p><!-- /wp:paragraph -->';
    $support = '<!-- wp:paragraph --><p>The exact support paragraph survives.</p><!-- /wp:paragraph -->';
    $separator = '<!-- wp:separator {"className":"is-style-wide"} -->'
        . '<hr class="wp-block-separator is-style-wide"/><!-- /wp:separator -->';
    $eyebrowShell = '<!-- wp:group {"backgroundColor":"accent"} -->'
        . '<div class="wp-block-group has-accent-background-color has-background">'
        . $eyebrow . $separator . '</div><!-- /wp:group -->';
    $headline = '<!-- wp:heading {"level":1} -->'
        . '<h1 class="wp-block-heading">Headline opens the hero</h1><!-- /wp:heading -->';
    $raw = hero_unit_root($eyebrowShell . $support . $headline);
    $unit = new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $first = $unit->finish($raw, hero_unit_contract_input('foreground-split', null));

    assert_true(!str_contains($first->markup, 'Tbilisi Old Town'));
    assert_true(!str_contains($first->markup, 'wp:separator'));
    assert_true(!str_contains($first->markup, 'has-accent-background-color'), 'the emptied shell is removed');
    assert_contains($headline . $support, $first->markup, 'the sole support block moves intact behind the H1');
    assert_eq(['hero-support-moved-after-headline'], array_column($first->repairs, 'code'));
    assert_eq(2, count($first->warnings));
    $warnings = implode("\n", $first->warnings);
    foreach ([
        "file='theme/parts/page-home--hero.html'",
        'Tbilisi Old Town',
        'is-style-wide',
        'delivered=removed',
        'disposition=',
    ] as $context) {
        assert_contains($context, $warnings);
    }

    $second = $unit->finish($first->markup, hero_unit_contract_input('foreground-split', null));
    assert_eq($first->markup, $second->markup);
    assert_eq([], $second->repairs);
    assert_eq([], $second->warnings);
});

test('HeroUnit normalizes recipe and mobile root markers while preserving unrelated classes', function () {
    $unit = new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $input = hero_unit_contract_input('foreground-split', null);
    $raw = '<!-- wp:group {"className":"keep hero-composition--old hero-mobile--old hero-mobile--stale","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group keep hero-composition--old hero-mobile--old">'
        . '<!-- wp:group {"className":"hero-composition__copy"} --><div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1>Hero</h1><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->'
        . '<!-- wp:group {"className":"hero-composition__media"} --><div class="wp-block-group hero-composition__media">'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/subject.jpg" alt="AI_IMAGE: subject | home | editorial | landscape" /></figure><!-- /wp:image -->'
        . '</div><!-- /wp:group -->'
        . '</div>'
        . '<!-- /wp:group -->';

    $first = $unit->finish($raw, $input);

    assert_hero_unit_root_marker($first->markup, 'hero-composition--foreground-split');
    assert_hero_unit_root_marker($first->markup, 'hero-mobile--stack-copy-first');
    assert_true(!str_contains($first->markup, 'hero-composition--old'));
    assert_true(!str_contains($first->markup, 'hero-mobile--old'));
    assert_true(!str_contains($first->markup, 'hero-mobile--stale'));
    assert_eq(2, substr_count($first->markup, 'keep'), 'unrelated comment and saved-HTML classes survive');
    assert_eq(['root-marker-normalized', 'root-marker-normalized'], array_column($first->repairs, 'code'));
    assert_eq([], $first->warnings);

    $second = $unit->finish($first->markup, $input);
    assert_eq($first->markup, $second->markup);
    assert_eq([], $second->repairs, 'both marker families reach a clean fixed point');
    assert_eq([], $second->warnings);
});

test('HeroUnit executes a tinted hero assignment with the committed band surface', function () {
    $unit = new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = hero_unit_root(
        '<!-- wp:heading {"level":1} --><h1>Portrait hero</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Copy survives on its planned band.</p><!-- /wp:paragraph -->',
    );
    // foreground-split defaults to the base surface, so the tinted band this
    // test is about comes from the section assignment, not the recipe.
    $input = hero_unit_contract_input('foreground-split', null);
    $input['section']['background'] = 'tinted';

    $result = $unit->finish($raw, $input);

    assert_contains('"backgroundColor":"band"', $result->markup);
    assert_contains('has-band-background-color', $result->markup);
    assert_contains('Copy survives on its planned band.', $result->markup);
    assert_true(in_array('tinted-band-surface-enforced', array_column($result->repairs, 'code'), true));
    assert_eq([], $result->warnings);
});

test('HeroUnit wraps complete roots without changing their bytes and reaches a fixed point', function () {
    $unit = new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $input = hero_unit_contract_input('foreground-split', null);
    $raw = '<!-- wp:group {"className":"hero-composition__copy"} --><div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1>Hero survives.</h1><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->'
        . '<!-- wp:group {"className":"hero-composition__media"} --><div class="wp-block-group hero-composition__media">'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/subject.jpg" alt="AI_IMAGE: One exhibit subject | hero media column | photorealistic | landscape" /></figure><!-- /wp:image -->'
        . '</div><!-- /wp:group -->'
        . '<!-- wp:paragraph --><p>Support survives.</p><!-- /wp:paragraph -->';

    $first = $unit->finish($raw, $input);

    assert_contains($raw, $first->markup, 'the complete generated roots survive byte-for-byte inside the envelope');
    assert_hero_unit_root_marker($first->markup, 'hero-composition--foreground-split');
    assert_hero_unit_root_marker($first->markup, 'hero-mobile--stack-copy-first');
    assert_contains('"layout":{"type":"constrained"}', $first->markup);
    assert_eq(['root-group-wrapped', 'root-marker-normalized', 'root-marker-normalized', 'root-layout-constrained'], array_column($first->repairs, 'code'));
    assert_eq([], $first->warnings);

    $second = $unit->finish($first->markup, $input);
    assert_eq($first->markup, $second->markup);
    assert_eq([], $second->repairs);
    assert_eq([], $second->warnings);
});

test('HeroUnit preserves safe recipe-internal defects and warns with repair-ready context', function () {
    $unit = new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $input = hero_unit_contract_input('foreground-split', null);
    $raw = '<!-- wp:group {"anchor":"hero","className":"hero-composition--foreground-split hero-mobile--stack-copy-first","layout":{"type":"constrained"}} -->'
        . '<div id="hero" class="wp-block-group hero-composition--foreground-split hero-mobile--stack-copy-first">'
        . '<!-- wp:paragraph --><p>Safe authored copy and siblings survive.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';

    $first = $unit->finish($raw, $input);

    assert_eq($raw, $first->markup, 'safe parseable bytes are retained when topology cannot be repaired semantically');
    assert_eq([], $first->repairs);
    assert_eq(3, count($first->warnings));
    foreach ($first->warnings as $warning) {
        foreach (["file='theme/parts/page-home--hero.html'", 'block=', 'authored=', 'delivered=', 'disposition='] as $context) {
            assert_contains($context, $warning);
        }
    }

    $second = $unit->finish($first->markup, $input);
    assert_eq($first->markup, $second->markup);
    assert_eq($first->warnings, $second->warnings, 'the residual warning boundary is stable without mutating safe content');
});

test('HeroUnit restores an identifiable paraphrased primary-action label exactly', function () {
    $unit = new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = hero_unit_root(
        '<!-- wp:heading {"level":1} --><h1>Generated hero headline</h1><!-- /wp:heading -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons">'
        . '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/work/">See our projects</a></div><!-- /wp:button -->'
        . '</div><!-- /wp:buttons -->'
    );

    $first = $unit->finish($raw, hero_unit_contract_input());

    assert_contains('>Explore the work</a>', $first->markup);
    assert_true(!str_contains($first->markup, 'See our projects'));
    assert_eq(['primary-action-label-restored'], array_column($first->repairs, 'code'));
    assert_eq([], $first->warnings, 'a semantics-safe exact-label repair is not durable loss');

    $second = $unit->finish($first->markup, hero_unit_contract_input());
    assert_eq($first->markup, $second->markup);
    assert_eq([], $second->repairs);
    assert_eq([], $second->warnings);
});

test('HeroUnit enforces the uniform copy-and-action budget without disturbing the cleaned hero', function () {
    $unit = new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $headline = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">One clear promise</h1><!-- /wp:heading -->';
    $standfirst = '<!-- wp:paragraph --><p>The one supporting thought stays.</p><!-- /wp:paragraph -->';
    $overflow = '<!-- wp:paragraph --><p>This generated overflow line goes.</p><!-- /wp:paragraph -->';
    $secondary = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Contact us</a></div><!-- /wp:button -->';
    $primary = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/work/">Explore the work</a></div><!-- /wp:button -->';
    $raw = hero_unit_root(
        $headline . $standfirst . $overflow
        . '<!-- wp:buttons --><div class="wp-block-buttons">'
        . $secondary . $primary
        . '</div><!-- /wp:buttons -->',
    );

    $first = $unit->finish($raw, hero_unit_contract_input());

    foreach ([$headline, $standfirst, $primary] as $survivor) {
        assert_contains($survivor, $first->markup);
    }
    assert_true(!str_contains($first->markup, $overflow));
    assert_true(!str_contains($first->markup, $secondary));
    assert_eq(2, count($first->warnings));
    $joined = implode("\n", $first->warnings);
    assert_contains('This generated overflow line goes.', $joined);
    assert_contains('Contact us', $joined);
    assert_contains('delivered=removed', $joined);

    $second = $unit->finish($first->markup, hero_unit_contract_input());
    assert_eq($first->markup, $second->markup);
    assert_eq([], $second->repairs);
    assert_eq([], $second->warnings);
});

test('HeroUnit removes only an identifiable wrong-destination action and warns actionably', function () {
    $unit = new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $sibling = '<!-- wp:paragraph --><p>Sibling content stays byte-for-byte.</p><!-- /wp:paragraph -->';
    $headline = '<!-- wp:heading {"level":1} --><h1>Generated hero headline</h1><!-- /wp:heading -->';
    $button = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/invented/">Explore the work</a></div><!-- /wp:button -->';
    $raw = hero_unit_root($headline . $sibling . '<!-- wp:buttons --><div class="wp-block-buttons">' . $button . '</div><!-- /wp:buttons -->');

    $result = $unit->finish($raw, hero_unit_contract_input());

    assert_contains($sibling, $result->markup);
    assert_true(!str_contains($result->markup, $button), 'only the identified harmful button block is excised');
    assert_true(!str_contains($result->markup, 'wp:buttons'), 'the now-empty action row is excised too');
    assert_eq([], $result->repairs);
    assert_eq(2, count($result->warnings));
    $warning = $result->warnings[0];
    foreach ([
        "file='parts/page-home--hero.html'",
        "block='wp:button[1]/a'",
        'Explore the work',
        '/invented/',
        'delivered=removed',
        'disposition=',
    ] as $context) {
        assert_contains($context, $warning);
    }
    assert_contains("block='wp:buttons[1]'", $result->warnings[1]);
    assert_contains('delivered=removed', $result->warnings[1]);

    $second = $unit->finish($result->markup, hero_unit_contract_input());
    assert_eq($result->markup, $second->markup);
    assert_eq(1, count($second->warnings), 'the undelivered planned action remains actionable at the parent boundary');
});

test('HeroUnit removes unplanned button blocks when the authoritative action is null', function () {
    $unit = new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $sibling = '<!-- wp:paragraph --><p>Authorized sibling copy survives byte-for-byte.</p><!-- /wp:paragraph -->';
    $headline = '<!-- wp:heading {"level":1} --><h1>Generated hero headline</h1><!-- /wp:heading -->';
    $button = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/work/">Invented CTA</a></div><!-- /wp:button -->';
    $raw = hero_unit_root($headline . $sibling . '<!-- wp:buttons --><div class="wp-block-buttons">' . $button . '</div><!-- /wp:buttons -->');

    $first = $unit->finish($raw, hero_unit_contract_input('foreground-split', null));

    assert_contains($sibling, $first->markup);
    assert_true(!str_contains($first->markup, $button));
    assert_true(!str_contains($first->markup, 'wp:buttons'));
    assert_eq([], $first->repairs);
    assert_eq(2, count($first->warnings));
    foreach ([
        "file='parts/page-home--hero.html'",
        "block='wp:button[1]'",
        'Invented CTA',
        'authored=',
        'delivered=removed',
        'disposition=',
    ] as $context) {
        assert_contains($context, $first->warnings[0]);
    }
    assert_contains("block='wp:buttons[1]'", $first->warnings[1]);

    $second = $unit->finish($first->markup, hero_unit_contract_input('foreground-split', null));
    assert_eq($first->markup, $second->markup);
    assert_eq([], $second->warnings, 'the isolated removal reaches a fixed point');
});

test('primary-action reconciliation does not guess when delivery is omitted or ambiguous', function () {
    $action = hero_unit_contract_input()['section']['primary_action'];
    $markup = hero_unit_root('<!-- wp:paragraph --><p>No primary control.</p><!-- /wp:paragraph -->');

    $result = GeneratedMarkup::reconcilePrimaryAction($markup, $action, 'page-home--hero');

    assert_eq($markup, $result['markup']);
    assert_eq(false, $result['delivered']);
    assert_eq([], $result['repairs']);
    assert_eq(1, count($result['warnings']));
    assert_contains('delivered=removed', $result['warnings'][0]);
    assert_contains('parent must null the delivered plan/contract action', $result['warnings'][0]);

    $unrelated = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Contact us</a></div><!-- /wp:button -->';
    $ambiguous = hero_unit_root(
        '<!-- wp:buttons --><div class="wp-block-buttons">'
        . '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/work/">See projects</a></div><!-- /wp:button -->'
        . '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/invented/">Explore the work</a></div><!-- /wp:button -->'
        . $unrelated
        . '</div><!-- /wp:buttons -->'
    );

    $ambiguousResult = GeneratedMarkup::reconcilePrimaryAction(
        $ambiguous,
        $action,
        'page-home--hero',
    );

    assert_eq(false, $ambiguousResult['delivered']);
    assert_true(!str_contains($ambiguousResult['markup'], 'See projects'));
    assert_true(!str_contains($ambiguousResult['markup'], 'Explore the work'));
    assert_contains($unrelated, $ambiguousResult['markup'], 'an unrelated secondary control remains byte-for-byte');
    assert_contains('wp:button[1]/a', $ambiguousResult['warnings'][0]);
    assert_contains('wp:button[2]/a', $ambiguousResult['warnings'][0]);
    assert_true(!str_contains($ambiguousResult['warnings'][0], 'wp:button[3]/a'));
});

test('dead-destination cleanup removes only the authoritative action block', function () {
    $action = hero_unit_contract_input()['section']['primary_action'];
    $sibling = '<!-- wp:paragraph --><p>Sibling copy stays byte-for-byte.</p><!-- /wp:paragraph -->';
    $secondary = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Contact us</a></div><!-- /wp:button -->';
    $primary = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/work/">Explore the work</a></div><!-- /wp:button -->';
    $markup = hero_unit_root(
        $sibling . '<!-- wp:buttons --><div class="wp-block-buttons">'
        . $primary . $secondary . '</div><!-- /wp:buttons -->',
    );

    $result = GeneratedMarkup::withoutPrimaryAction($markup, $action, 'page-home--hero');

    assert_true(!str_contains($result['markup'], $primary));
    assert_contains($sibling, $result['markup']);
    assert_contains($secondary, $result['markup']);
    assert_eq([], $result['repairs']);
    assert_eq(false, $result['delivered']);
    assert_eq(1, count($result['warnings']));
    foreach ([
        "file='parts/page-home--hero.html'",
        "block='wp:button[1]/a'",
        'authored=',
        'delivered=removed',
        'disposition=',
    ] as $context) {
        assert_contains($context, $result['warnings'][0]);
    }

    $again = GeneratedMarkup::withoutPrimaryAction($result['markup'], $action, 'page-home--hero');
    assert_eq($result['markup'], $again['markup']);
    assert_eq([], $again['warnings'], 'the isolated removal reaches a fixed point');
});

test('one exact primary action survives a secondary button sharing its destination', function () {
    $action = hero_unit_contract_input()['section']['primary_action'];
    $primary = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/work/">Explore the work</a></div><!-- /wp:button -->';
    $secondary = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/work/">View all</a></div><!-- /wp:button -->';
    $markup = hero_unit_root(
        '<!-- wp:buttons --><div class="wp-block-buttons">'
        . $primary . $secondary . '</div><!-- /wp:buttons -->',
    );

    $result = GeneratedMarkup::reconcilePrimaryAction($markup, $action, 'page-home--hero');

    assert_eq($markup, $result['markup']);
    assert_eq(true, $result['delivered']);
    assert_eq([], $result['repairs']);
    assert_eq([], $result['warnings']);
});

test('one exact primary action survives while a same-label invented destination is isolated', function () {
    $action = hero_unit_contract_input()['section']['primary_action'];
    $primary = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/work/">Explore the work</a></div><!-- /wp:button -->';
    $conflict = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/invented/">Explore the work</a></div><!-- /wp:button -->';
    $secondary = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Contact us</a></div><!-- /wp:button -->';
    $markup = hero_unit_root(
        '<!-- wp:buttons --><div class="wp-block-buttons">'
        . $primary . $conflict . $secondary . '</div><!-- /wp:buttons -->',
    );

    $result = GeneratedMarkup::reconcilePrimaryAction($markup, $action, 'page-home--hero');

    assert_contains($primary, $result['markup']);
    assert_true(!str_contains($result['markup'], $conflict));
    assert_contains($secondary, $result['markup']);
    assert_eq(true, $result['delivered']);
    assert_eq([], $result['repairs']);
    assert_eq(1, count($result['warnings']));
    foreach ([
        "file='parts/page-home--hero.html'",
        "block='wp:button[2]/a'",
        '/invented/',
        'delivered=removed',
        'authoritative action remained delivered',
    ] as $context) {
        assert_contains($context, $result['warnings'][0]);
    }

    $again = GeneratedMarkup::reconcilePrimaryAction($result['markup'], $action, 'page-home--hero');
    assert_eq($result['markup'], $again['markup']);
    assert_eq(true, $again['delivered']);
    assert_eq([], $again['warnings']);
});

test('primary-action presence uses the same wp:button boundary as reconciliation', function () {
    $action = hero_unit_contract_input()['section']['primary_action'];
    $paragraphLink = '<!-- wp:paragraph --><p><a href="/work/">Explore the work</a></p><!-- /wp:paragraph -->';
    assert_true(!GeneratedMarkup::containsPrimaryAction($paragraphLink, $action));

    $button = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/work/">Explore the work</a></div><!-- /wp:button -->';
    assert_true(GeneratedMarkup::containsPrimaryAction($button, $action));
});
