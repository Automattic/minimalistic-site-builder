<?php
declare(strict_types=1);

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\HeroComposition;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\HeroUnit;
use Automattic\SiteBuild\Units\SectionUnit;

/** A delivery contract for one blueprint, header archetype, and writing direction. */
function hero_content_contract(array $blueprint, string $headerArchetype, ?array $action, string $direction): array
{
    $projection = HeroComposition::planProjection($blueprint);
    $pages = [[
        'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
        'sections' => [[
            'slug' => 'hero', 'title' => 'Hero',
            'layout_archetype' => $projection['layout_archetype'],
            'background' => $projection['default_background'],
            'primary_action' => $action,
        ]],
    ]];
    return AboveFoldContract::resolve(
        $pages,
        $blueprint,
        'full-bleed',
        ['base' => '#FFFFFF', 'contrast' => '#111111'],
        ['stable_id' => 'unit-contract', 'writing_direction' => $direction, 'page_count' => 1],
        ['archetype' => 'minimal-columns', 'surface' => 'base'],
        $headerArchetype,
    );
}

/** @return array<string,mixed> */
function hero_content_input(array $blueprint, array $contract, ?array $action, string $direction): array
{
    $projection = HeroComposition::planProjection($blueprint);
    return [
        'site_spec'        => '{"name":"Tbilisi Tavern","writing_direction":"' . $direction . '"}',
        'language'         => 'en',
        'theme_json'       => '{"settings":{"color":{"palette":[{"slug":"base","color":"#FFFFFF"},{"slug":"contrast","color":"#111111"}]}}}',
        'design_direction' => "# Test\n- **Canvas**: full-bleed — edge to edge.\n- **Heading emphasis**: italic-word — wrap words.\n",
        'outline'          => '1. Hero (hero)',
        'site_pages'       => '- "Home" — / (front page): the home page',
        'page'             => ['slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true],
        'section'          => [
            'slug' => 'hero', 'role' => 'hero', 'title' => 'Hero', 'type' => 'hero',
            'purpose' => 'Open the site.', 'content_notes' => 'Bold welcome.',
            'layout_archetype' => $projection['layout_archetype'],
            'background' => $projection['default_background'],
            'vertical_density' => 'standard', 'handoff' => 'Below: about.',
            'primary_action' => $action,
        ],
        'neighbors'           => 'Below: about.',
        'hero_blueprint'      => $blueprint,
        'above_fold_contract' => $contract,
    ];
}

function hero_content_document(): array
{
    return [
        'heading' => 'Clay pots, walnut sauce and long Old Town suppers',
        'heading_emphasis' => 'Old Town',
        'lead' => 'Georgian and Caucasus cooking served the traditional way, at shared tables.',
        'paragraphs' => [], 'items' => [],
        'image_subject' => 'A long wooden table under a vaulted stone ceiling, clay pots and bread down its length, a window to the left.',
        'image_context' => 'full-frame photographic backdrop, the centre kept calm',
        'image_style' => 'photorealistic',
        'action_label' => 'Reserve a table', 'link_label' => '', 'link_href' => '',
    ];
}

test('compiled heroes satisfy the recipe checks across the blueprint product', function () {
    $unit = new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')), contentMode: true);
    $failures = [];
    $compiled = 0;
    foreach (HeroComposition::RECIPES as $recipe) {
        $meta = HeroComposition::metadata($recipe);
        $headers = in_array('overlay', $meta['header_modes'], true) ? ['standard-row', 'minimal-overlay'] : ['standard-row'];
        foreach ($headers as $header) {
            foreach (['center', 'center-start', 'center-end'] as $anchor) {
                foreach ($meta['media_aspects'] as $aspect) {
                    foreach ($meta['media_weights'] as $weight) {
                        foreach (['ltr', 'rtl'] as $direction) {
                            foreach ([null, ['label' => 'Reserve a table', 'intent' => 'book', 'destination' => '#reservations']] as $action) {
                                $blueprint = HeroBlueprint::defaultFor($recipe);
                                $blueprint['text_anchor'] = $anchor;
                                $blueprint['media_aspect'] = $aspect;
                                $blueprint['media_weight'] = $weight;
                                $repairs = [];
                                $warnings = [];
                                $blueprint = HeroBlueprint::normalize($blueprint, $recipe, $repairs, $warnings);
                                $contract = hero_content_contract($blueprint, $header, $action, $direction);
                                $input = hero_content_input($blueprint, $contract, $action, $direction);
                                $key = "{$recipe}/{$header}/{$anchor}/{$aspect}/{$weight}/{$direction}/" . ($action ? 'action' : 'none');
                                try {
                                    $result = $unit->finish(json_encode(hero_content_document()), $input);
                                } catch (Throwable $e) {
                                    $failures[] = "{$key}: " . get_class($e) . ' ' . substr($e->getMessage(), 0, 160);
                                    continue;
                                }
                                $compiled++;
                                $document = BlockMarkup::parse($result->markup);
                                if ($document->hasMalformedDelimiters() || $document->hasMismatchedDelimiters() || $document->unclosedIndices() !== []) {
                                    $failures[] = "{$key}: malformed block document";
                                }
                                foreach ($result->warnings as $warning) {
                                    $failures[] = "{$key}: " . (preg_match("/block=(\"[^\"]*\"|'[^']*')/", $warning, $m) === 1 ? $m[1] : substr($warning, 0, 120));
                                }
                                foreach ($result->repairs as $repair) {
                                    $failures[] = "{$key}: repair " . json_encode($repair['code'] ?? $repair);
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    assert_true($compiled > 60, 'the blueprint product was compiled');
    assert_eq([], array_slice(array_values(array_unique($failures)), 0, 30), count($failures) . ' failures');
});

test('the hero content request shares the sections cache layers and schema', function () {
    $blueprint = HeroBlueprint::defaultFor('cinematic-safe-zone');
    $action = ['label' => 'Reserve a table', 'intent' => 'book', 'destination' => '#reservations'];
    $contract = hero_content_contract($blueprint, 'standard-row', $action, 'ltr');
    $hero = (new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')), contentMode: true))
        ->request(hero_content_input($blueprint, $contract, $action, 'ltr'));
    $sectionInput = hero_content_input($blueprint, $contract, $action, 'ltr');
    $sectionInput['section'] = [
        'slug' => 'about', 'role' => 'content', 'title' => 'About', 'layout_archetype' => 'feature-row-hairlines',
        'background' => 'base', 'vertical_density' => 'standard', 'item_pattern' => null,
        'text_placement' => 'centered', 'handoff' => 'x', 'primary_action' => null,
    ];
    $sectionInput['header_contract'] = '';
    $sectionInput['card_style'] = 'flush';
    $section = (new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')), contentMode: true))
        ->request($sectionInput);

    assert_eq($section['cached_prefixes'], $hero['cached_prefixes'], 'the hero shares the front page sections cache layers');
    assert_eq($section['json_schema'], $hero['json_schema'], 'the hero shares the schema');
    assert_contains('Front-page hero recipe: cinematic-safe-zone', $hero['prompt']);
    assert_contains('"Reserve a table" -> #reservations', $hero['prompt']);
});

test('a compiled cover hero carries the recipe markers, the copy region, and the planned action', function () {
    $blueprint = HeroBlueprint::defaultFor('layered-poster');
    $action = ['label' => 'Reserve a table', 'intent' => 'book', 'destination' => '#reservations'];
    $contract = hero_content_contract($blueprint, 'standard-row', $action, 'ltr');
    $result = (new HeroUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')), contentMode: true))
        ->finish(json_encode(hero_content_document()), hero_content_input($blueprint, $contract, $action, 'ltr'));

    assert_contains('hero-composition--layered-poster hero-mobile--flatten-layers', $result->markup);
    assert_contains('"className":"hero-composition__media"', $result->markup);
    assert_contains('"className":"hero-composition__copy"', $result->markup);
    assert_contains('"contentPosition":"center left"', $result->markup, 'center-start resolves to the left in ltr');
    assert_contains('<h1', $result->markup);
    assert_contains('<span class="emph">Old Town</span>', $result->markup);
    assert_contains('<a href="#reservations">Reserve a table</a>', $result->markup);
    assert_contains('| photorealistic | landscape"', $result->markup, 'the cover image takes the blueprint aspect');
    assert_eq([], $result->warnings);
});
