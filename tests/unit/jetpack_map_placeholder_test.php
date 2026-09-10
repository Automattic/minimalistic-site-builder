<?php
declare(strict_types=1);

use Automattic\SiteBuild\MapPlaceholder;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\SectionsStep;
use Automattic\SiteBuild\ThemeValidator;
use Automattic\SiteBuild\Units\SectionUnit;

require_once __DIR__ . '/section_unit_test.php';
require_once __DIR__ . '/validator_test.php';

/** Render a section request with map placeholders on or off. */
function jp_map_request_text(bool $placeholders): string
{
    $unit = new SectionUnit(
        new Automattic\SiteBuild\Tests\FakeLlm(''),
        new PromptRenderer(repo_path('prompts')),
    );
    $input = section_unit_input();
    if ($placeholders) {
        $input['map_placeholders'] = true;
    }

    return section_unit_request_text($unit->request($input));
}

/** A page whose only content is one map placeholder paragraph. */
function jp_map_page(string $spec): string
{
    return '<!-- wp:paragraph {"className":"jetpack-map-placeholder"} -->'
        . '<p class="jetpack-map-placeholder">' . $spec . '</p>'
        . '<!-- /wp:paragraph -->';
}

/** Validate a project carrying one placeholder page, with the flag on or off. */
function jp_map_validate(string $spec, bool $placeholders): string
{
    [$project, $tmp] = validator_project();
    if ($placeholders) {
        $project->writeJson('meta.json', ['prompt' => 'x', 'map_placeholders' => true]);
    }
    $project->writeText('plugin/pages/visit.html', jp_map_page($spec));
    $joined = implode(' ', ThemeValidator::validate($project));
    exec('rm -rf ' . escapeshellarg($tmp));

    return $joined;
}

test('by default a section is told nothing about maps at all', function () {
    $prompt = jp_map_request_text(false);

    // Unlike forms there is no off-mode counterpart prompt: the system
    // preamble already rules that a brief asking for a map states a content
    // need, so silence here is the whole default.
    assert_true(!str_contains($prompt, 'JP_MAP'), 'no placeholder contract leaks into a default build');
    assert_true(!str_contains($prompt, 'jetpack-map-placeholder'), 'nor does the class');
});

test('--use-jetpack-maps swaps in the JP_MAP contract', function () {
    $prompt = jp_map_request_text(true);

    assert_contains('JP_MAP: address | marker-title | scope', $prompt, 'the spec format is stated');
    assert_contains('jetpack-map-placeholder', $prompt, 'the placeholder block carries a locatable class');
});

test('the two placeholder capabilities are independent', function () {
    // A host can own a form backend and not be able to geocode, which is
    // exactly the state this contract ships into. Neither flag may drag the
    // other's contract into the prompt.
    $unit = new SectionUnit(
        new Automattic\SiteBuild\Tests\FakeLlm(''),
        new PromptRenderer(repo_path('prompts')),
    );
    $formOnly = section_unit_request_text(
        $unit->request(section_unit_input() + ['form_placeholders' => true]),
    );

    assert_contains('JP_FORM', $formOnly, 'the form contract is in');
    assert_true(!str_contains($formOnly, 'JP_MAP'), 'the map contract is not');
    assert_true(!str_contains(jp_map_request_text(true), 'JP_FORM'), 'and the reverse holds');
});

/** The contract as one line, so an assertion pins a sentence and not its wrapping. */
function jp_map_contract(): string
{
    $contract = (string) file_get_contents(repo_path('prompts/jetpack-map.md'));

    return (string) preg_replace('/\s+/', ' ', $contract);
}

test('the map contract never asks the model for real map markup', function () {
    $contract = jp_map_contract();

    assert_contains('Never emit `<iframe>`', $contract, 'raw embeds stay forbidden');
    assert_contains('wp:jetpack/*', $contract, 'the model does not author host blocks either');
    assert_contains('Never invent an address', $contract, 'the invented-facts rule is restated here');
    assert_contains('more than one placeholder in a section', $contract, 'one map per section');
});

test('the map spec grammar is unambiguous enough to parse', function () {
    $contract = jp_map_contract();

    assert_contains('JP_MAP: address | marker-title | scope', $contract, 'the spec format is stated');
    // The host splits on this separator, so a value carrying one would make
    // the spec unparseable. The contract has to say so, for both free-text
    // values.
    assert_contains('An address may not contain `|`', $contract, 'the address separator is reserved');
    assert_contains('It may not contain `|`', $contract, 'the title separator is reserved too');
    foreach (array_keys(MapPlaceholder::SCOPES) as $scope) {
        assert_contains("`{$scope}`", $contract, "the {$scope} scope is documented");
    }
});

test('map instructions stay inside the cached build layer of the section prompt', function () {
    $section = (string) file_get_contents(repo_path('prompts/section.md'));

    $mapPos = strpos($section, '{{map_instructions}}');
    $pageLayer = strpos($section, '<!-- cache-layer:page -->');
    assert_true($mapPos !== false, 'the section prompt renders map instructions');
    assert_true(
        $pageLayer !== false && $mapPos < $pageLayer,
        'map instructions are static per build, so they belong in the cached prefix',
    );
});

test('the map capability travels from createProject to the sections step', function () {
    $tmp = sys_get_temp_dir() . '/builder_jpmap_' . uniqid();

    $off = make_test_builder(new Automattic\SiteBuild\Tests\FakeLlm(), $tmp)
        ->createProject('a test cafe', 'maps-off');
    assert_true(
        !array_key_exists('map_placeholders', $off->readJson('meta.json')),
        'the default build records no map capability at all',
    );
    assert_eq(false, SectionsStep::mapPlaceholders($off));

    // Forms on, maps off: the state this ships into, and the one a shared
    // flag would have got wrong.
    $formsOnly = make_test_builder(new Automattic\SiteBuild\Tests\FakeLlm(), $tmp)
        ->createProject('a test cafe', 'forms-only', formPlaceholders: true);
    assert_eq(true, SectionsStep::formPlaceholders($formsOnly));
    assert_eq(false, SectionsStep::mapPlaceholders($formsOnly));

    $on = make_test_builder(new Automattic\SiteBuild\Tests\FakeLlm(), $tmp)
        ->createProject('a test cafe', 'maps-on', mapPlaceholders: true);
    assert_eq(true, $on->readJson('meta.json')['map_placeholders']);
    assert_eq(true, SectionsStep::mapPlaceholders($on));
    assert_eq(false, SectionsStep::formPlaceholders($on));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('a well-formed map spec parses into what a host needs', function () {
    $found = MapPlaceholder::find(
        jp_map_page('JP_MAP: 14 Rue de Rivoli, 75004 Paris, France | Atelier Rivoli | street'),
    );
    assert_eq(1, count($found));

    $parsed = MapPlaceholder::parse($found[0]['spec']);
    assert_true(is_array($parsed));
    assert_eq('14 Rue de Rivoli, 75004 Paris, France', $parsed['address'], 'commas in an address survive');
    assert_eq('Atelier Rivoli', $parsed['title']);
    assert_eq('street', $parsed['scope']);
    assert_eq(MapPlaceholder::SCOPES['street'], $parsed['zoom'], 'the scope word carries the zoom');
});

test('every scope resolves to a zoom level, in the order the words imply', function () {
    $zooms = [];
    foreach (array_keys(MapPlaceholder::SCOPES) as $scope) {
        $parsed = MapPlaceholder::parse("JP_MAP: 1 Main St | Somewhere | {$scope}");
        assert_true(is_array($parsed), "{$scope} is a scope the parser accepts");
        $zooms[] = $parsed['zoom'];
    }
    $descending = $zooms;
    rsort($descending);
    assert_eq($descending, $zooms, 'closer scopes zoom in further');
});

test('a spec a host could not parse is caught instead of shipping as body text', function () {
    // Dashes where the contract says pipes: still a paragraph, so nothing else
    // in the pipeline would ever notice.
    $joined = jp_map_validate('JP_MAP: 1 Main St - Somewhere - street', true);
    assert_contains('unparseable map spec', $joined);
    assert_contains('pipe-separated parts', $joined);

    assert_contains('unknown scope', jp_map_validate('JP_MAP: 1 Main St | Somewhere | close', true));
    assert_contains('empty address', jp_map_validate('JP_MAP:  | Somewhere | street', true));
    assert_contains('empty marker title', jp_map_validate('JP_MAP: 1 Main St |  | street', true));
});

test('a clean map spec passes validation', function () {
    $joined = jp_map_validate('JP_MAP: 1 Main St, Springfield | The Shop | street', true);

    assert_true(!str_contains($joined, 'map spec'), "clean spec was flagged: {$joined}");
    assert_true(!str_contains($joined, 'map marker'), "clean spec was flagged: {$joined}");
});

test('a map marker in a build with no host to substitute it is a problem', function () {
    $joined = jp_map_validate('JP_MAP: 1 Main St | The Shop | street', false);

    assert_contains('no map host', $joined);
});

test('a map marker loose in the markup is caught, since the host only reads the block', function () {
    [$project, $tmp] = validator_project();
    $project->writeJson('meta.json', ['prompt' => 'x', 'map_placeholders' => true]);
    $project->writeText(
        'plugin/pages/visit.html',
        jp_map_page('JP_MAP: 1 Main St | The Shop | street')
        . '<!-- wp:paragraph --><p>JP_MAP: elsewhere</p><!-- /wp:paragraph -->',
    );
    $joined = implode(' ', ThemeValidator::validate($project));
    exec('rm -rf ' . escapeshellarg($tmp));

    assert_contains('outside a jetpack-map-placeholder block', $joined);
});

test('one contract never sees the other contract markers', function () {
    // Both counts are substring counts over the same page, so a page carrying
    // both placeholders must still report one of each rather than two of
    // either — otherwise the loose-marker arithmetic invents problems.
    $page = jp_map_page('JP_MAP: 1 Main St | The Shop | street')
        . '<!-- wp:paragraph {"className":"jetpack-form-placeholder"} -->'
        . '<p class="jetpack-form-placeholder">JP_FORM: contact | Email:email:required | Send</p>'
        . '<!-- /wp:paragraph -->';

    assert_eq(1, count(MapPlaceholder::find($page)));
    assert_eq(1, MapPlaceholder::markerCount($page));
    assert_eq(1, count(Automattic\SiteBuild\FormPlaceholder::find($page)));
    assert_eq(1, Automattic\SiteBuild\FormPlaceholder::markerCount($page));
});

test('stripping loose map markers keeps the placeholder and drops the rest', function () {
    $warnings = [];
    $files = [
        'parts/page-visit--find.html' => jp_map_page('JP_MAP: 1 Main St | The Shop | street')
            . '<!-- wp:paragraph --><p>JP_MAP</p><!-- /wp:paragraph -->',
    ];

    $out = SectionsStep::stripLooseMapMarkers($files, $warnings);
    $markup = $out['parts/page-visit--find.html'];

    assert_eq(1, MapPlaceholder::markerCount($markup), 'the bare marker paragraph is gone');
    assert_eq(1, count(MapPlaceholder::find($markup)), 'the real placeholder is untouched');
    assert_contains('JP_MAP marker(s) outside a jetpack-map-placeholder block', implode(' ', $warnings));
});

test('the map placeholder block survives the passes that rewrite generated markup', function () {
    // The whole design rests on this: the marker has to still be there when
    // the host looks for it. Pin the two passes that rewrite section markup
    // before delivery, and the re-serializer fix-blocks runs it through.
    $spec = 'JP_MAP: 14 Rue de Rivoli, 75004 Paris, France | Atelier Rivoli | street';
    $markup = '<!-- wp:paragraph {"className":"jetpack-map-placeholder"} -->' . "\n"
        . '<p class="jetpack-map-placeholder">' . $spec . '</p>' . "\n"
        . '<!-- /wp:paragraph -->';

    $sanitized = Automattic\SiteBuild\MarkupSanitizer::sanitize($markup);
    $fixed     = Automattic\SiteBuild\LayoutFixer::fix(
        $sanitized,
        Automattic\SiteBuild\LayoutFixer::ROLE_SECTION,
        860.0,
    )['markup'];

    assert_contains('jetpack-map-placeholder', $fixed, 'the class the host locates the block by');
    assert_contains($spec, $fixed, 'the spec text, pipes and all, reaches the host unaltered');

    $serialized = (new Automattic\SiteBuild\BlockSerializer\Serializer())->transform($fixed)->html;
    assert_eq(1, count(MapPlaceholder::find($serialized)), 'and the re-serializer keeps it findable');
});

test('a placeholder whose class lives only in the block comment still counts', function () {
    // The re-serializer copies `className` onto the <p>, but it runs after the
    // pass that deletes markers no placeholder claims. Reading only the <p>
    // would throw this block away one step before it was repaired — and a map
    // has no injected fallback to bring it back.
    $spec = 'JP_MAP: 1 Main St | The Shop | street';
    $markup = '<!-- wp:heading --><h2>Find us</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph {"className":"jetpack-map-placeholder"} -->'
        . '<p>' . $spec . '</p><!-- /wp:paragraph -->';

    $found = MapPlaceholder::find($markup);
    assert_eq(1, count($found), 'the class in the comment locates the block');
    assert_eq($spec, $found[0]['spec']);

    $warnings = [];
    $out = SectionsStep::stripLooseMapMarkers(['parts/x.html' => $markup], $warnings);
    assert_contains($spec, $out['parts/x.html'], 'and the strip leaves it alone');
    assert_eq([], $warnings, 'a claimed placeholder is not a loose marker');

    // What the re-serializer then makes of it is what the host reads.
    $serialized = (new Automattic\SiteBuild\BlockSerializer\Serializer())->transform($markup)->html;
    assert_contains('<p class="jetpack-map-placeholder">', $serialized);
    assert_eq(1, count(MapPlaceholder::find($serialized)));
});

test('a marker in a paragraph that claims no placeholder class is still loose', function () {
    // The widening above must not swallow the case it was never about: a bare
    // marker with no class anywhere is body copy the host never reads.
    $markup = '<!-- wp:heading --><h2>Find us</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>JP_MAP: 1 Main St | The Shop | street</p><!-- /wp:paragraph -->';

    assert_eq([], MapPlaceholder::find($markup), 'no class, no placeholder');

    $warnings = [];
    $out = SectionsStep::stripLooseMapMarkers(['parts/x.html' => $markup], $warnings);
    assert_eq(0, MapPlaceholder::markerCount($out['parts/x.html']), 'and the strip removes it');
    assert_contains('outside a jetpack-map-placeholder block', implode(' ', $warnings));
});
