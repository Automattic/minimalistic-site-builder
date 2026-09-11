<?php
declare(strict_types=1);

use Automattic\SiteBuild\ActionCapabilities;
use Automattic\SiteBuild\Steps\CtaBudgetStep;

function enquiry_context(array $spec = []): array
{
    return ActionCapabilities::context($spec, [
        ['slug' => 'home', 'path' => '/', 'title' => 'Home'],
        ['slug' => 'contact', 'path' => '/contact/', 'title' => 'Contact'],
        ['slug' => 'visit', 'path' => '/visit/', 'title' => 'Visit'],
    ]);
}

function enquiry_button(string $label, string $href): string
{
    return '<!-- wp:button --><div class="wp-block-button"><a href="' . $href . '">' . $label . '</a></div><!-- /wp:button -->';
}

test('enquiry labels need a contact channel and retain valid content labels', function () {
    $context = enquiry_context();
    foreach (['Enquire about a table', 'Inquire about a table', 'Call us', 'Write to us', 'Contact us'] as $label) {
        assert_eq('Contact', ActionCapabilities::label($label, '/contact/', $context));
    }
    foreach (['Contact', 'Visit', 'Read about reservations', 'Call of the mountains'] as $label) {
        assert_eq($label, ActionCapabilities::label($label, '/contact/', $context));
    }
    $context = enquiry_context(['email' => 'hello@example.com']);
    assert_eq('Enquire about a table', ActionCapabilities::label('Enquire about a table', 'mailto:hello@example.com', $context));
    $context['form_destinations']['/contact/'] = true;
    assert_eq('Enquire about a table', ActionCapabilities::label('Enquire about a table', '/contact/', $context));
});

test('unsupported contact instructions remove only their sentence and retain siblings', function () {
    $fact = '<!-- wp:paragraph --><p>The tavern is in Tbilisi Old Town.</p><!-- /wp:paragraph -->';
    $request = '<!-- wp:paragraph --><p>Write to us to hold a table, ask about a dish or a large gathering, or tell us how the evening went, and someone from the tavern will answer you.</p><!-- /wp:paragraph -->';
    $mixed = '<!-- wp:paragraph {"fontSize":"body"} --><p class="has-body-font-size">Call or write to us for a reservation. The tavern is in Tbilisi Old Town.</p><!-- /wp:paragraph -->';
    $raw = $fact . $request . $mixed . $fact;
    $result = ActionCapabilities::repairContactCopy($raw, enquiry_context(), 'theme/parts/contact.html', '/contact/');
    $kept = str_replace('Call or write to us for a reservation. ', '', $mixed);
    assert_eq($fact . $kept . $fact, $result['markup']);
    assert_eq(2, count($result['warnings']));
    foreach ($result['warnings'] as $warning) {
        assert_contains('file=theme/parts/contact.html; block=blocks[', $warning);
        assert_contains('authored=', $warning);
        assert_contains('delivered=', $warning);
        assert_contains('disposition=', $warning);
    }
    assert_eq(['markup' => $result['markup'], 'warnings' => []], ActionCapabilities::repairContactCopy($result['markup'], enquiry_context(), 'theme/parts/contact.html', '/contact/'));
    $two = str_replace('Call or write to us for a reservation.', 'Call us for a reservation. Write to us with questions.', $mixed);
    $result = ActionCapabilities::repairContactCopy($two, enquiry_context(), 'contact.html', '/contact/');
    assert_eq($kept, $result['markup']);
    assert_eq(['markup' => $kept, 'warnings' => []], ActionCapabilities::repairContactCopy($kept, enquiry_context(), 'contact.html', '/contact/'));
});

test('a reservation heading uses the title of its one actual destination', function () {
    $heading = '<!-- wp:heading {"level":2} --><h2>Make a <span class="emph">reservation</span></h2><!-- /wp:heading -->';
    $fact = '<!-- wp:paragraph --><p>The bread comes hot from the tone oven.</p><!-- /wp:paragraph -->';
    $button = enquiry_button('Plan your visit', '/visit/');
    $raw = '<!-- wp:group --><div>' . $heading . $fact . $button . '</div><!-- /wp:group -->';
    $result = ActionCapabilities::repairContactCopy($raw, enquiry_context(), 'theme/parts/contact.html', '/contact/');
    assert_eq(str_replace('Make a <span class="emph">reservation</span>', 'Visit', $raw), $result['markup']);
    assert_contains($fact . $button, $result['markup']);
    assert_eq(1, count($result['warnings']));
    assert_eq(['markup' => $result['markup'], 'warnings' => []], ActionCapabilities::repairContactCopy($result['markup'], enquiry_context(), 'theme/parts/contact.html', '/contact/'));
});

test('contact copy retains verified channels and uncertain text boundaries', function () {
    $raw = '<!-- wp:paragraph --><p>Call us for a table.</p><!-- /wp:paragraph -->';
    foreach ([enquiry_context(['phone' => '+995123456789']), enquiry_context(['email' => 'hello@example.com'])] as $context) {
        assert_eq(['markup' => $raw, 'warnings' => []], ActionCapabilities::repairContactCopy($raw, $context, 'contact.html', '/contact/'));
    }
    $context = enquiry_context();
    $context['form_destinations']['/contact/'] = true;
    assert_eq(['markup' => $raw, 'warnings' => []], ActionCapabilities::repairContactCopy($raw, $context, 'contact.html', '/contact/'));
    $complex = '<!-- wp:paragraph --><p>Call us for a table. <strong>The tavern is in Old Town.</strong></p><!-- /wp:paragraph -->';
    $result = ActionCapabilities::repairContactCopy($complex, enquiry_context(), 'contact.html', '/contact/');
    assert_eq($complex, $result['markup']);
    assert_eq(1, count($result['warnings']));
    $heading = '<!-- wp:group --><div><!-- wp:heading --><h2>Make a reservation</h2><!-- /wp:heading -->'
        . enquiry_button('Visit', '/visit/') . enquiry_button('External', 'https://unknown.example/') . '</div><!-- /wp:group -->';
    assert_eq($heading, ActionCapabilities::repairContactCopy($heading, enquiry_context(), 'contact.html', '/contact/')['markup']);
});

test('the CTA step corrects enquiry buttons and unsupported prose before page assembly', function () {
    with_project('enquiry_step_', function ($project) {
        $project->writeJson('meta.json', []);
        $project->writeJson('siteSpec.json', []);
        $project->writeJson('pages.json', ['pages' => [
            ['slug' => 'home', 'path' => '/', 'title' => 'Home', 'front' => true, 'sections' => [['slug' => 'hero', 'role' => 'hero']]],
            ['slug' => 'contact', 'path' => '/contact/', 'title' => 'Contact', 'sections' => [['slug' => 'hero', 'role' => 'hero']]],
        ]]);
        $project->writeText('theme/parts/page-home--hero.html', enquiry_button('Enquire about a table', '/contact/'));
        $project->writeText('theme/parts/page-contact--hero.html', '<!-- wp:heading {"level":1} --><h1>Contact</h1><!-- /wp:heading -->'
            . '<!-- wp:paragraph --><p>Write to us to hold a table.</p><!-- /wp:paragraph -->');
        (new CtaBudgetStep())->run($project);
        assert_contains('>Contact</a>', $project->readText('theme/parts/page-home--hero.html'));
        assert_true(!str_contains($project->readText('theme/parts/page-contact--hero.html'), 'Write to us'));
        assert_contains('corrected contact copy without a verified channel', implode("\n", $project->readJson('warnings.json')['cta-budget']));
    });
});
