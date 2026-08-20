<?php
declare(strict_types=1);

use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Units\SectionUnit;

require_once __DIR__ . '/section_unit_test.php';

/** Render a section request with form placeholders on or off. */
function jp_form_request_text(bool $placeholders): string
{
    $unit = new SectionUnit(
        new Automattic\SiteBuild\Tests\FakeLlm(''),
        new PromptRenderer(repo_path('prompts')),
    );
    $input = section_unit_input();
    if ($placeholders) {
        $input['form_placeholders'] = true;
    }

    return section_unit_request_text($unit->request($input));
}

test('by default a section is told to emit no form markup at all', function () {
    $prompt = jp_form_request_text(false);

    assert_contains('NO FORM MARKUP', $prompt, 'the default build has no form backend');
    assert_contains('mailto:', $prompt, 'the default build still offers a way to reach the site');
    assert_true(!str_contains($prompt, 'JP_FORM'), 'no placeholder contract leaks into a default build');
});

test('--use-jetpack-placeholders swaps in the JP_FORM contract', function () {
    $prompt = jp_form_request_text(true);

    assert_contains('JP_FORM: purpose | fields | submit-label', $prompt, 'the spec format is stated');
    assert_contains('jetpack-form-placeholder', $prompt, 'the placeholder block carries a locatable class');
    assert_true(!str_contains($prompt, 'NO FORM MARKUP'), 'the no-backend rule is replaced, not stacked');
});

test('the placeholder contract never asks the model for real form markup', function () {
    $contract = (string) file_get_contents(repo_path('prompts/jetpack-form.md'));

    assert_contains('Never emit `<form>`', $contract, 'raw form markup stays forbidden');
    assert_contains('wp:jetpack/*', $contract, 'the model does not author host blocks either');
    assert_contains('never emit more than one placeholder in a section', $contract, 'one form per section');
});

test('the form spec grammar is unambiguous enough to parse', function () {
    $contract = (string) file_get_contents(repo_path('prompts/jetpack-form.md'));

    // The host splits on these separators, so a label carrying one would make
    // the spec unparseable. The contract has to say so.
    assert_contains('A label may not contain `,` `:` or `|`', $contract, 'separators are reserved');
    assert_contains('`label:type` or `label:type:required`', $contract, 'the field form is stated');
    assert_contains('Party size:select(1, 2, 3, 4 or more):required', $contract, 'choices have a worked example');
    // Labels reach the visitor verbatim, so the examples have to model the
    // casing the rule asks for rather than contradict it.
    assert_contains('capitalised the way it will be READ on the page', $contract, 'label casing is stated');
    assert_true(
        !str_contains($contract, 'JP_FORM: contact | name:text'),
        'no example shows a lowercased label',
    );
});

test('both form modes stay inside the cached build layer of the section prompt', function () {
    $section = (string) file_get_contents(repo_path('prompts/section.md'));

    $formPos = strpos($section, '{{form_instructions}}');
    $pageLayer = strpos($section, '<!-- section-cache-layer:page -->');
    assert_true($formPos !== false, 'the section prompt renders form instructions');
    assert_true(
        $pageLayer !== false && $formPos < $pageLayer,
        'form instructions are static per build, so they belong in the cached prefix',
    );
});

test('the placeholder capability travels from createProject to the sections step', function () {
    $tmp = sys_get_temp_dir() . '/builder_jpform_' . uniqid();

    $off = make_test_builder(new Automattic\SiteBuild\Tests\FakeLlm(), $tmp)
        ->createProject('a test cafe', 'forms-off');
    assert_true(
        !array_key_exists('form_placeholders', $off->readJson('meta.json')),
        'the default build records no form capability at all',
    );
    assert_eq(false, Automattic\SiteBuild\Steps\SectionsStep::formPlaceholders($off));

    $on = make_test_builder(new Automattic\SiteBuild\Tests\FakeLlm(), $tmp)
        ->createProject('a test cafe', 'forms-on', formPlaceholders: true);
    assert_eq(true, $on->readJson('meta.json')['form_placeholders']);
    assert_eq(true, Automattic\SiteBuild\Steps\SectionsStep::formPlaceholders($on));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('a project that never went through createProject defaults to no placeholders', function () {
    $tmp = sys_get_temp_dir() . '/builder_jpform_' . uniqid();
    $project = make_test_builder(new Automattic\SiteBuild\Tests\FakeLlm(), $tmp)
        ->createProject('a test cafe', 'no-meta');
    unlink($project->path() . '/meta.json');

    assert_eq(false, Automattic\SiteBuild\Steps\SectionsStep::formPlaceholders($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the placeholder block survives the passes that rewrite generated markup', function () {
    // The whole design rests on this: the marker has to still be there when the
    // host looks for it. The section prompt warns that a deterministic pass
    // re-serializes every block from its comment JSON and drops classes the
    // attributes do not produce, so pin that `className` is one it does keep.
    $markup = '<!-- wp:paragraph {"className":"jetpack-form-placeholder"} -->' . "\n"
        . '<p class="jetpack-form-placeholder">'
        . 'JP_FORM: contact | name:text:required, email:email:required, message:textarea | Send'
        . '</p>' . "\n"
        . '<!-- /wp:paragraph -->';

    $sanitized = Automattic\SiteBuild\MarkupSanitizer::sanitize($markup);
    $fixed     = Automattic\SiteBuild\LayoutFixer::fix(
        $sanitized,
        Automattic\SiteBuild\LayoutFixer::ROLE_SECTION,
        860.0,
    )['markup'];

    assert_contains('jetpack-form-placeholder', $fixed, 'the class the host locates the block by');
    assert_contains(
        'JP_FORM: contact | name:text:required, email:email:required, message:textarea | Send',
        $fixed,
        'the spec text, pipes and all, reaches the host unaltered',
    );
});

/** A page whose only content is one form placeholder paragraph. */
function jp_form_page(string $spec): string
{
    return '<!-- wp:paragraph {"className":"jetpack-form-placeholder"} -->'
        . '<p class="jetpack-form-placeholder">' . $spec . '</p>'
        . '<!-- /wp:paragraph -->';
}

/** Validate a project carrying one placeholder page, with the flag on or off. */
function jp_form_validate(string $spec, bool $placeholders): string
{
    [$project, $tmp] = validator_project();
    if ($placeholders) {
        $project->writeJson('meta.json', ['prompt' => 'x', 'form_placeholders' => true]);
    }
    $project->writeText('plugin/pages/contact.html', jp_form_page($spec));
    $joined = implode(' ', Automattic\SiteBuild\ThemeValidator::validate($project));
    exec('rm -rf ' . escapeshellarg($tmp));

    return $joined;
}

test('a well-formed spec passes validation, options and all', function () {
    $joined = jp_form_validate(
        'JP_FORM: booking | name:text:required, party size:select(1, 2, 3 or more):required, notes:textarea | Book',
        true,
    );

    assert_true(!str_contains($joined, 'form spec'), "clean spec was flagged: {$joined}");
    assert_true(!str_contains($joined, 'form marker'), "clean spec was flagged: {$joined}");
});

test('a spec a host could not parse is caught instead of shipping as body text', function () {
    // Dashes where the contract says pipes: still a paragraph, so nothing else
    // in the pipeline would ever notice.
    $joined = jp_form_validate('JP_FORM: contact - name, email - Send', true);
    assert_contains('unparseable form spec', $joined);
    assert_contains('pipe-separated parts', $joined);

    assert_contains('unknown purpose', jp_form_validate('JP_FORM: signup | email:email | Go', true));
    assert_contains('unknown type', jp_form_validate('JP_FORM: contact | name:fullname | Go', true));
    assert_contains('no fields', jp_form_validate('JP_FORM: contact |  | Go', true));
    assert_contains(
        "expected 'required'",
        jp_form_validate('JP_FORM: contact | name:text:optional | Go', true),
    );
});

test('a marker in a build with no host to substitute it is a problem', function () {
    $joined = jp_form_validate('JP_FORM: contact | email:email:required | Send', false);

    assert_contains('no form host', $joined);
});

test('a marker loose in the markup is caught, since the host only reads the block', function () {
    [$project, $tmp] = validator_project();
    $project->writeJson('meta.json', ['prompt' => 'x', 'form_placeholders' => true]);
    $project->writeText(
        'plugin/pages/contact.html',
        jp_form_page('JP_FORM: contact | email:email:required | Send')
        . '<!-- wp:paragraph --><p>JP_FORM: rsvp | name:text | Yes</p><!-- /wp:paragraph -->',
    );
    $joined = implode(' ', Automattic\SiteBuild\ThemeValidator::validate($project));
    exec('rm -rf ' . escapeshellarg($tmp));

    assert_contains('outside a jetpack-form-placeholder block', $joined);
});
