<?php
declare(strict_types=1);

test('extractBlocks parses names and balanced nested JSON attrs', function () {
    $markup = '<!-- wp:group {"align":"full","style":{"spacing":{"blockGap":"2rem"}}} -->'
        . '<div class="wp-block-group"><!-- wp:site-title /--></div>'
        . '<!-- /wp:group -->';
    $blocks = DesignValidator::extractBlocks($markup);
    // Opener + self-closing site-title.
    assert_eq('group', $blocks[0]['name']);
    assert_eq('full', $blocks[0]['attrs']['align']);
    assert_eq('2rem', $blocks[0]['attrs']['style']['spacing']['blockGap']);
    assert_eq('site-title', $blocks[1]['name']);
});

test('V2 flags a background without a text color', function () {
    $project = make_markup_project([
        'theme/parts/section-x.html' =>
            '<!-- wp:group {"align":"wide","backgroundColor":"primary"} --><div></div><!-- /wp:group -->',
    ]);
    $rules = rule_set(DesignValidator::validate($project));
    assert_true(in_array('V2-paired-color', $rules, true), 'expected V2 paired-color');
});

test('V2 passes when background and text are paired', function () {
    $project = make_markup_project([
        'theme/parts/section-x.html' =>
            '<!-- wp:group {"align":"wide","backgroundColor":"primary","textColor":"base"} --><div></div><!-- /wp:group -->',
    ]);
    assert_eq([], DesignValidator::validate($project));
});

test('V3 flags a grid/flex container without blockGap', function () {
    $project = make_markup_project([
        'theme/parts/section-x.html' =>
            '<!-- wp:group {"align":"full","layout":{"type":"flex"}} --><div></div><!-- /wp:group -->',
    ]);
    $rules = rule_set(DesignValidator::validate($project));
    assert_true(in_array('V3-grid-flex-gap', $rules, true), 'expected V3 gap finding');
});

test('V4 flags a hardcoded hex color in block attributes', function () {
    $project = make_markup_project([
        'theme/parts/section-x.html' =>
            '<!-- wp:group {"align":"full","style":{"color":{"background":"#ff0000","text":"#ffffff"}}} --><div></div><!-- /wp:group -->',
    ]);
    $rules = rule_set(DesignValidator::validate($project));
    assert_true(in_array('V4-token-discipline', $rules, true), 'expected V4 hardcode finding');
});

test('V5 flags a section whose outer block has no align', function () {
    $project = make_markup_project([
        'theme/parts/section-x.html' =>
            '<!-- wp:group {"backgroundColor":"primary","textColor":"base"} --><div></div><!-- /wp:group -->',
    ]);
    $rules = rule_set(DesignValidator::validate($project));
    assert_true(in_array('V5-alignment', $rules, true), 'expected V5 alignment finding');
});

test('a clean section passes V2–V5', function () {
    $project = make_markup_project([
        'theme/parts/section-hero.html' =>
            '<!-- wp:group {"align":"full","backgroundColor":"primary","textColor":"base"} -->'
            . '<div class="wp-block-group"><!-- wp:heading {"textColor":"base"} --><h2>Hi</h2><!-- /wp:heading --></div>'
            . '<!-- /wp:group -->',
    ]);
    assert_eq([], DesignValidator::validate($project));
});

/** Build a throwaway Project containing the given relative-path => markup files. */
function make_markup_project(array $files): Project
{
    $tmp = sys_get_temp_dir() . '/builder_dv_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    foreach ($files as $rel => $markup) {
        $project->writeText($rel, $markup);
    }
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($project->root)));
    return $project;
}

/** @param array<int,array{rule:string,file:string,detail:string}> $findings */
function rule_set(array $findings): array
{
    return array_values(array_unique(array_column($findings, 'rule')));
}
