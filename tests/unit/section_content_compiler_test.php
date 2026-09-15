<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\CardStyle;
use Automattic\SiteBuild\ItemPattern;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\SectionComposition;
use Automattic\SiteBuild\SectionContent;
use Automattic\SiteBuild\SectionContentCompiler;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\CardStyleContract;
use Automattic\SiteBuild\Units\SectionUnit;

/** @return array<string,mixed> */
function content_unit_input(string $archetype, ?string $itemPattern, string $cardStyle, string $background, string $placement, bool $action = true): array
{
    return [
        'site_spec'        => '{"name":"Tbilisi Tavern","writing_direction":"ltr","pages":[{"title":"Home","slug":"home"},{"title":"Menu","slug":"menu"}]}',
        'language'         => 'en',
        'theme_json'       => '{"settings":{"color":{"palette":[{"slug":"base","color":"#2A0E16"},{"slug":"contrast","color":"#F2D4DD"},{"slug":"band","color":"#501B2A"}]}}}',
        'design_direction' => "# Test\n- **Canvas**: framed — a mat.\n- **Heading emphasis**: italic-word — wrap words.\n- **Image crop**: landscape — cards 3:2.\n- **Type treatment**: tight — sentence case.\n",
        'card_style'       => $cardStyle,
        'motion_profile'   => 'minimal',
        'outline'          => '1. Visit us (essentials)',
        'site_pages'       => '- "Home" — / (front page): the home page' . "\n" . '- "Menu" — /menu/: the menu',
        'page'             => ['slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true],
        'section'          => [
            'slug'             => 'essentials',
            'title'            => 'Visit us',
            'role'             => 'content',
            'type'             => 'essentials',
            'purpose'          => 'Practical information.',
            'content_notes'    => 'Three cards.',
            'layout_archetype' => $archetype,
            'background'       => $background,
            'vertical_density' => 'compact',
            'item_pattern'     => $itemPattern,
            'text_placement'   => $placement,
            'handoff'          => 'Above: base. Below: contrast.',
            'primary_action'   => $action ? ['label' => 'Reserve a table', 'intent' => 'book', 'destination' => '/menu/'] : null,
        ],
        'neighbors'        => 'Above: about. Below: cta.',
        'header_contract'  => '',
        'is_opening'       => false,
    ];
}

function content_warning_block(string $warning): string
{
    return preg_match("/block=(\"[^\"]*\"|'[^']*')/", $warning, $m) === 1 ? $m[1] : substr($warning, 0, 120);
}

/** A document that fills every field the archetype reads. */
function content_document(string $archetype, ?string $itemPattern): array
{
    [, $max] = SectionContent::itemCounts($archetype, $itemPattern);
    $keys = SectionContent::itemKeys($archetype, $itemPattern);
    $items = [];
    for ($i = 1; $i <= min($max, 5); $i++) {
        $item = [];
        foreach ($keys as $key) {
            $item[$key] = match ($key) {
                'heading'       => $archetype === 'stat-ledger' ? "{$i}20+" : "Item {$i} heading",
                'text'          => "Item {$i} text with <em>emphasis</em> & an ampersand.",
                'meta'          => $archetype === 'pricing-tiers' ? "\${$i}9 per month" : 'Identity · Web · 2025',
                'list'          => ['Feature one', 'Feature two', 'Feature three'],
                'link_label'    => "Item {$i} link",
                'link_href'     => '/menu/',
                'image_subject' => "A clay pot number {$i} on a stone table under a low ceiling.",
                'image_context' => 'card image in a row of equal cards',
                default         => '',
            };
        }
        $items[] = $item;
    }
    return [
        'heading'          => 'Visit us in the old town',
        'heading_emphasis' => 'old town',
        'lead'             => 'Everything you need to find the door.',
        'paragraphs'       => ['A cellar room off one of the carved-balcony lanes.', 'Guests without a booking are welcome.'],
        'items'            => $items,
        'image_subject'    => 'A stone-walled room with a long table, a window to the left and a beamed ceiling above.',
        'image_context'    => 'wide feature photograph beside the section copy',
        'image_style'      => 'photorealistic',
        'action_label'     => 'Reserve a table',
        'link_label'       => 'See the menu',
        'link_href'        => '/menu/',
    ];
}

test('compiled sections satisfy the catalog, item pattern, and card style contracts', function () {
    $failures = [];
    $compiled = 0;
    foreach (SectionComposition::ARCHETYPES as $archetype) {
        $meta = SectionComposition::metadata($archetype);
        $patterns = SectionContent::itemCounts($archetype, null)[1] === 0 ? [null] : [null, ...ItemPattern::ALL];
        foreach ($patterns as $itemPattern) {
            foreach (CardStyle::ALL as $cardStyle) {
                foreach ($meta['backgrounds'] as $background) {
                    foreach (['left-column', 'centered', 'asymmetric-thirds', 'split'] as $placement) {
                        $input = content_unit_input($archetype, $itemPattern, $cardStyle, $background, $placement);
                        $doc = content_document($archetype, $itemPattern);
                        $markup = SectionContentCompiler::compile($doc, $input);
                        $compiled++;
                        $part = "{$archetype}/{$itemPattern}/{$cardStyle}/{$background}/{$placement}";
                        $document = BlockMarkup::parse($markup);
                        if ($document->hasMalformedDelimiters() || $document->hasMismatchedDelimiters() || $document->unclosedIndices() !== []) {
                            $failures[] = "{$part}: malformed block document";
                            continue;
                        }
                        $key = "{$archetype}/{$itemPattern}/{$cardStyle}/{$background}";
                        foreach (SectionComposition::markupWarnings($markup, $archetype, $part, $itemPattern, false) as $warning) {
                            $failures[] = "{$key}: " . content_warning_block($warning);
                        }
                        if ($itemPattern !== null) {
                            foreach (ItemPattern::markupWarnings($markup, $itemPattern, $part) as $warning) {
                                $failures[] = "{$key}: " . content_warning_block($warning);
                            }
                        }
                        $contract = CardStyleContract::enforce($markup, $cardStyle, $part, themeJson: $input['theme_json']);
                        foreach ($contract['warnings'] as $warning) {
                            $failures[] = "{$key}: card " . content_warning_block($warning);
                        }
                        foreach ($contract['repairs'] as $repair) {
                            $failures[] = "{$key}: card repair " . json_encode($repair['code'] ?? $repair);
                        }
                    }
                }
            }
        }
    }
    assert_true($compiled > 500, 'the catalog product was compiled');
    assert_eq([], array_slice(array_values(array_unique($failures)), 0, 40), count($failures) . ' contract failures');
});

test('one schema serves every archetype so the batch shares one cache prefix', function () {
    $json = json_encode(SectionContent::schema(), JSON_THROW_ON_ERROR);
    assert_true(!str_contains($json, '"properties":[]'), 'no empty property list');
    assert_true(!str_contains($json, 'maxItems') && !str_contains($json, 'minItems'), 'no array bounds');
    foreach (SectionComposition::ARCHETYPES as $archetype) {
        foreach ([null, ...ItemPattern::ALL] as $itemPattern) {
            foreach (SectionContent::itemKeys($archetype, $itemPattern) as $key) {
                assert_true(in_array($key, SectionContent::ALL_ITEM_KEYS, true), "{$archetype} item key {$key} is in the schema");
            }
        }
    }
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')), contentMode: true);
    $a = $unit->request(content_unit_input('cta-panel', null, 'flush', 'base', 'centered'));
    $b = $unit->request(content_unit_input('equal-card-grid', 'rule-row', 'flush', 'base', 'centered'));
    assert_eq($a['json_schema'], $b['json_schema'], 'the schema is byte-identical across archetypes');
    assert_eq($a['cached_prefixes'], $b['cached_prefixes'], 'the cached layers are byte-identical across archetypes');
});

test('a closing panel without a planned action falls back to the spec email', function () {
    $input = content_unit_input('cta-panel', null, 'flush', 'base', 'centered', false);
    $doc = content_document('cta-panel', null);
    $doc['link_href'] = '';
    assert_true(!str_contains(SectionContentCompiler::compile($doc, $input), 'wp:button'), 'no email in the spec, no button');
    $input['site_spec'] = '{"name":"Tbilisi Tavern","writing_direction":"ltr","email":"table@tbilisi.example"}';
    assert_contains('<a href="mailto:table@tbilisi.example">Reserve a table</a>', SectionContentCompiler::compile($doc, $input));
});

test('compiled markup keeps the hooks later steps read', function () {
    $input = content_unit_input('equal-card-grid', 'card', 'flush', 'tinted', 'centered');
    $markup = SectionContentCompiler::compile(content_document('equal-card-grid', 'card'), $input);

    assert_contains('"anchor":"essentials"', $markup);
    assert_contains('section-composition--equal-card-grid item-pattern--card', $markup);
    assert_contains('"backgroundColor":"band"', $markup, 'tinted band');
    assert_contains('<span class="emph">old town</span>', $markup, 'heading emphasis wrapped');
    assert_contains('"className":"equal-cards"', $markup);
    assert_contains('card-style--flush card-flush', $markup);
    assert_contains('"className":"card-body"', $markup);
    assert_contains('AI_IMAGE: A clay pot number 1 on a stone table under a low ceiling. | card image in a row of equal cards | photorealistic | card-landscape', $markup);
    assert_contains('theme:./assets/essentials-a-clay-pot-number-1-on.jpg', $markup);
    assert_contains('<a href="/menu/">Reserve a table</a>', $markup, 'planned primary action');
    assert_contains('"className":"text-action cta-bottom"', $markup);
    assert_contains('with <em>emphasis</em> &amp; an ampersand.', $markup, 'inline markup kept, text escaped');
    assert_contains('"align":"left"', $markup, 'wrapping copy starts at the writing edge in a centered stack');
});

test('compiled markup drops unsafe links and scripts', function () {
    $input = content_unit_input('feature-row-hairlines', null, 'flush', 'base', 'left-column', false);
    $doc = content_document('feature-row-hairlines', null);
    $doc['paragraphs'] = ['Call <a href="https://evil.example/">us</a> or <a href="/menu/">see the menu</a> <script>x()</script>'];
    $doc['link_href'] = 'javascript:alert(1)';
    $doc['lead'] = 'amber glass<br/>380 × 150 mm';
    $markup = SectionContentCompiler::compile($doc, $input);

    assert_contains('amber glass<br>380 × 150 mm', $markup, 'line breaks survive as br');
    assert_true(!str_contains($markup, 'evil.example'), 'external link dropped');
    assert_contains('Call us or <a href="/menu/">see the menu</a>', $markup);
    assert_true(!str_contains($markup, '<script'), 'script escaped');
    assert_true(!str_contains($markup, 'javascript:'), 'unsafe text link dropped');
    assert_true(!str_contains($markup, 'wp:buttons'), 'no button without a planned action');
});

test('content mode requests a schema-bound document and compiles the reply', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')), contentMode: true);
    $input = content_unit_input('equal-card-grid', 'rule-row', 'borderless', 'tinted', 'centered');
    $request = $unit->request($input);

    assert_eq(3, count($request['cached_prefixes']), 'site, build, and page layers');
    assert_eq('section_content', $request['json_schema']['name']);
    assert_contains('CONTENT SHAPE', $request['prompt']);
    assert_contains('Items are ledger rows', $request['prompt']);
    assert_contains('"Reserve a table" -> /menu/', $request['prompt']);
    assert_true(!str_contains($request['cached_prefixes'][1], 'wp:group'), 'the build layer carries no markup rules');
    assert_true(strlen($request['cached_prefixes'][1]) < 9000, 'the build layer stays short');

    $result = $unit->finish(json_encode(content_document('equal-card-grid', 'rule-row')), $input);
    assert_contains('section-composition--equal-card-grid item-pattern--rule-row', $result->markup);
    assert_contains('Visit us in the', $result->markup);
    assert_eq([], array_filter($result->warnings, static fn (string $w): bool => !str_contains($w, 'two-tone')), 'no delivery warnings');
});

test('content mode rejects a reply that is not a document', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')), contentMode: true);
    $input = content_unit_input('equal-card-grid', null, 'flush', 'base', 'left-column');
    assert_throws(fn () => $unit->finish('<!-- wp:group --><div></div><!-- /wp:group -->', $input));
});

test('content mode is off unless the env asks for it', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    assert_eq(false, $unit->contentMode());
});
