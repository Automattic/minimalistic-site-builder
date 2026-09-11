<?php
declare(strict_types=1);

use Automattic\SiteBuild\ActionCapabilities;
use Automattic\SiteBuild\Steps\CtaBudgetStep;
use Automattic\SiteBuild\Steps\PagePlanStep;

function action_capability_pages(): array
{
    return [[
        'slug' => 'home', 'path' => '/', 'title' => 'Home', 'front' => true,
        'sections' => [
            ['slug' => 'hero', 'title' => 'Atlas Field', 'role' => 'hero', 'content_notes' => 'Start a free trial.',
                'primary_action' => ['label' => 'Start free trial', 'intent' => 'Create an account.', 'destination' => '#features']],
            ['slug' => 'features', 'title' => 'Features', 'role' => 'content', 'primary_action' => null],
            ['slug' => 'contact', 'title' => 'Contact', 'role' => 'closing', 'primary_action' => null],
        ],
    ]];
}

function capability_button(string $label, ?string $href): string
{
    return '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link"'
        . ($href === null ? '' : ' href="' . $href . '"') . '>' . $label . '</a></div><!-- /wp:button -->';
}

test('a local content link cannot promise a free trial', function () {
    $pages = action_capability_pages();
    $warnings = [];
    $out = PagePlanStep::reconcileActionCapabilities($pages, [], $warnings);
    assert_eq('Features', $out[0]['sections'][0]['primary_action']['label']);
    assert_eq('#features', $out[0]['sections'][0]['primary_action']['destination']);
    assert_contains('Omit the earlier transaction promise', $out[0]['sections'][0]['content_notes']);
    assert_eq($pages[0]['sections'][1], $out[0]['sections'][1]);
    assert_eq($pages[0]['sections'][2], $out[0]['sections'][2]);
    assert_contains('pages.json', $warnings[0]);
    assert_contains('Start free trial', $warnings[0]);
    assert_contains('Features', $warnings[0]);
    $nextWarnings = [];
    assert_eq($out, PagePlanStep::reconcileActionCapabilities($out, [], $nextWarnings));
    assert_eq([], $nextWarnings);
});

test('action repair removes only a dead catalogue button and corrects a trial label', function () {
    $sibling = '<!-- wp:paragraph --><p>The studio uses recycled glass.</p><!-- /wp:paragraph -->';
    $markup = $sibling . capability_button('Request catalogue', null) . $sibling
        . capability_button('Start free trial', '/#features') . $sibling;
    $context = ActionCapabilities::context([], action_capability_pages());
    $result = ActionCapabilities::repairMarkup($markup, $context, 'theme/parts/page-home--contact.html');
    assert_eq($sibling . $sibling . capability_button('Features', '/#features') . $sibling, $result['markup']);
    assert_eq(2, count($result['warnings']));
    assert_contains('delivered=removed', $result['warnings'][0]);
    assert_contains('blocks[', $result['warnings'][0]);
    assert_contains('Request catalogue', $result['warnings'][0]);
    assert_eq(['markup' => $result['markup'], 'warnings' => []], ActionCapabilities::repairMarkup($result['markup'], $context, 'theme/parts/page-home--contact.html'));
});

test('verified contact routes and real host forms retain transaction labels', function () {
    $context = ActionCapabilities::context(['email' => 'trade@example.com', 'trial_url' => 'https://app.example.com/trial'], action_capability_pages());
    assert_eq('Request catalogue', ActionCapabilities::label('Request catalogue', 'mailto:trade@example.com', $context));
    assert_eq('Start free trial', ActionCapabilities::label('Start free trial', 'https://app.example.com/trial', $context));
    $context['form_destinations']['/#contact'] = true;
    assert_eq('Request catalogue', ActionCapabilities::label('Request catalogue', '#contact', $context));
    assert_eq('See trial features', ActionCapabilities::label('See trial features', '#features', $context));
    assert_eq(null, ActionCapabilities::label('Reserve now', 'https://invented.example/book', $context));
    $context['primary_cta'] = 'Comenzar gratis';
    $context['cta_type'] = 'free trial signup';
    assert_eq('Features', ActionCapabilities::label('Comenzar gratis', '#features', $context));
});

test('the CTA step checks the header hero footer and closing section', function () {
    with_project('builder_action_capability_', function ($project) {
        $project->writeJson('siteSpec.json', ['primary_cta' => 'Start free trial', 'cta_type' => 'free trial signup']);
        $project->writeJson('meta.json', []);
        $project->writeJson('pages.json', ['pages' => action_capability_pages()]);
        foreach (['header', 'footer', 'page-home--hero', 'page-home--features'] as $part) {
            $project->writeText('theme/parts/' . $part . '.html', capability_button('Start free trial', '/#features'));
        }
        $project->writeText('theme/parts/page-home--contact.html', capability_button('Request catalogue', null));
        $step = new CtaBudgetStep();
        $step->run($project);
        foreach (['header', 'footer', 'page-home--hero', 'page-home--features'] as $part) {
            $markup = $project->readText('theme/parts/' . $part . '.html');
            assert_contains('Features</a>', $markup);
            assert_true(!str_contains($markup, 'Start free trial'));
        }
        assert_eq('', $project->readText('theme/parts/page-home--contact.html'));
        assert_eq(5, count($project->readJson('warnings.json')['cta-budget']));
        $before = $project->readText('theme/parts/header.html');
        $step->run($project);
        assert_eq($before, $project->readText('theme/parts/header.html'));
    });
});

test('a content action label has plain text within the primary-action limit', function () {
    $pages = action_capability_pages();
    $pages[0]['sections'][1]['title'] = '<span class="emph">' . str_repeat('Useful details ', 10) . '</span>';
    $context = ActionCapabilities::context([], $pages);
    $label = ActionCapabilities::label('Start free trial', '#features', $context);
    assert_true(is_string($label));
    assert_true(!str_contains($label, '<'));
    assert_true(mb_strlen($label) <= 80);
});
