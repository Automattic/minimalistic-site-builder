<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\CardStyle;
use Automattic\SiteBuild\ListThumbTreatment;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\SectionUnit;

test('thumbnail treatment follows the card assignment in the prompt and delivered markup', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $sibling = '<!-- wp:paragraph --><p>Keep this introduction.</p><!-- /wp:paragraph -->';
    $row = list_thumb_test_row(['className' => 'list-thumb-flush retained-row']);
    $raw = '<!-- wp:group {"className":"keep-root list-thumb-treatment--obsolete"} -->'
        . '<div class="wp-block-group keep-root list-thumb-treatment--obsolete">'
        . $sibling . $row . '</div><!-- /wp:group -->';
    foreach (CardStyle::ALL as $style) {
        $input = section_unit_input();
        $input['section']['layout_archetype'] = 'list-with-thumbnails';
        $input['card_style'] = $style;
        $prompt = section_unit_request_text($unit->request($input));
        assert_contains('Thumbnail treatment: ' . $style, $prompt);
        assert_contains(ListThumbTreatment::marker($style), $prompt);
        foreach (array_diff(CardStyle::ALL, [$style]) as $other) {
            assert_true(!str_contains($prompt, ListThumbTreatment::marker($other)));
        }
        $result = $unit->finish($raw, $input);
        assert_eq([], $result->warnings, $style);
        $document = BlockMarkup::parse($result->markup);
        $root = $document->topLevel();
        assert_contains(ListThumbTreatment::marker($style), $document->attrs($root)['className']);
        assert_contains(ListThumbTreatment::marker($style), $document->ownHtml($root));
        assert_contains('keep-root', $document->attrs($root)['className']);
        assert_contains($sibling, $result->markup);
        assert_contains('retained-row', $result->markup);
        assert_true(!str_contains($result->markup, 'list-thumb-treatment--obsolete'));
        $again = $unit->finish($result->markup, $input);
        assert_eq($result->markup, $again->markup, $style);
        assert_eq([], $again->repairs, $style);
        assert_eq([], $again->warnings, $style);
    }
});

test('thumbnail treatment leaves other compositions without a thumbnail marker', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $input = section_unit_input();
    $input['section']['layout_archetype'] = 'equal-card-grid';
    $prompt = section_unit_request_text($unit->request($input));
    assert_true(!str_contains($prompt, 'Thumbnail treatment:'));
    $raw = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>Keep this content.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $result = $unit->finish($raw, $input);
    assert_true(!str_contains($result->markup, ListThumbTreatment::MARKER_PREFIX));
});

test('the theme scaffold includes the thumbnail treatment rules', function () {
    with_project('thumbnail_treatment_css_', function ($project) {
        (new \Automattic\SiteBuild\Steps\ScaffoldThemeStep())->run($project);
        $css = $project->readText('theme/style.css');
        assert_contains(ListThumbTreatment::css(), $css);
    });
});
