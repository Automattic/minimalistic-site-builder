<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\CtaStyle;
use Automattic\SiteBuild\CtaStyleMarkup;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\FixBlocksStep;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\ThemeValidator;

test('CTA style maps every bounded commitment to a distinct executable construction', function () {
    assert_eq(['solid', 'outline', 'underline', 'ghost-arrow', 'block'], CtaStyle::ALL);
    assert_eq('solid', CtaStyle::DEFAULT);

    $solid = CtaStyle::themeStyle('solid');
    assert_eq('var:preset|color|accent', $solid['color']['background']);
    assert_true(!isset($solid['color']['text']), 'solid label remains contrast-fix-owned');

    $outline = CtaStyle::themeStyle('outline');
    assert_eq('transparent', $outline['color']['background']);
    assert_eq('inherit', $outline['color']['text']);
    assert_eq('2px', $outline['border']['width']);

    $underline = CtaStyle::themeStyle('underline');
    assert_eq('underline', $underline['typography']['textDecoration']);
    assert_contains('text-decoration-thickness', $underline['css']);
    assert_eq('0', $underline['border']['width']);
    assert_true(
        !isset($underline['border']['bottom']),
        'underline draws one line through text-decoration, never a second border rule',
    );

    $ghost = CtaStyle::themeStyle('ghost-arrow');
    assert_contains('content:"→"', $ghost['css']);

    $block = CtaStyle::themeStyle('block');
    assert_true(!str_contains($block['css'], 'width:100%'), 'block is a slab construction, not a width rule');
    assert_true(!str_contains($block['css'], 'display:block'), 'the slab keeps its intrinsic width');
    assert_true(!str_contains($block['css'], 'min-width'), 'the slab minimum lives on the wrapper, where a percentage resolves');
    assert_contains('.wp-block-buttons > .wp-block-button{min-width:min(12rem,100%);}', CtaStyle::BLOCK_WRAPPER_CSS);
    assert_contains('text-align:center', $block['css']);
    assert_eq('var:preset|color|contrast', $block['color']['background']);
    assert_contains('narrow container', CtaStyle::meaning('block'));

    assert_eq(null, CtaStyle::themeStyle('pill'));
    assert_eq('ghost-arrow', CtaStyle::explicit(' Ghost-Arrow '));
});

test('CTA theme repair owns construction while preserving typography and shape radius', function () {
    $authored = ['styles' => [
        'elements' => ['button' => [
            'color' => ['background' => 'red', 'text' => 'white', 'gradient' => 'bad'],
            'border' => ['radius' => '0.5rem', 'width' => '9px', 'color' => 'red'],
            'spacing' => ['padding' => ['top' => '9rem'], 'margin' => ['top' => '1rem']],
            'typography' => ['fontWeight' => '700', 'textTransform' => 'uppercase'],
            'css' => 'display:none',
            ':hover' => ['color' => ['background' => 'pink', 'text' => 'yellow']],
        ]],
        'blocks' => ['core/button' => [
            'typography' => ['fontFamily' => 'var:preset|font-family|body'],
            'color' => ['background' => 'purple'],
            'variations' => ['outline' => ['border' => ['width' => '7px']]],
        ]],
    ]];

    [$theme, $repairs] = ThemeJsonStep::repairCtaStyle($authored, 'ghost-arrow');
    $button = $theme['styles']['elements']['button'];
    assert_eq('transparent', $button['color']['background']);
    assert_eq('inherit', $button['color']['text']);
    assert_eq('0.5rem', $button['border']['radius']);
    assert_eq('0', $button['border']['width']);
    assert_eq(['top' => '1rem'], $button['spacing']['margin']);
    assert_eq('700', $button['typography']['fontWeight']);
    assert_eq('uppercase', $button['typography']['textTransform']);
    assert_contains('content:"→"', $button['css']);
    assert_eq(
        ['fontFamily' => 'var:preset|font-family|body'],
        $theme['styles']['blocks']['core/button']['typography'],
    );
    assert_true(!isset($theme['styles']['blocks']['core/button']['color']));
    assert_true(!isset($theme['styles']['blocks']['core/button']['variations']));
    assert_true(count($repairs) >= 8);

    [$fixed, $fixedRepairs] = ThemeJsonStep::repairCtaStyle($theme, 'ghost-arrow');
    assert_eq($theme, $fixed);
    assert_eq([], $fixedRepairs, 'the deterministic repair reaches a fixed point');

    [$shaped] = ThemeJsonStep::repairShapeWiring($theme, 'soft');
    [$afterShape, $afterShapeRepairs] = ThemeJsonStep::repairCtaStyle($shaped, 'ghost-arrow');
    assert_eq($shaped, $afterShape, 'later shape wiring may reorder border keys but does not drift CTA');
    assert_eq([], $afterShapeRepairs, 'JSON object key order is not reported as CTA drift');

    $rawSolid = ['styles' => ['elements' => ['button' => [
        'color' => ['text' => '#fefefe'],
    ]]]];
    [$solidTheme] = ThemeJsonStep::repairCtaStyle($rawSolid, 'solid');
    assert_eq(
        'var:preset|color|base',
        $solidTheme['styles']['elements']['button']['color']['text'],
        'solid rejects an arbitrary model label color before contrast repair',
    );
    $solidTheme['styles']['elements']['button']['color']['text'] = 'var:preset|color|contrast';
    [, $solidFixedRepairs] = ThemeJsonStep::repairCtaStyle($solidTheme, 'solid');
    assert_eq([], $solidFixedRepairs, 'a deterministic contrast/base repair is retained');
});

test('theme-json production write binds the committed CTA construction', function () {
    $tmp = sys_get_temp_dir() . '/builder_cta_theme_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A quiet editorial archive']);
    $project->writeJson('siteSpec.json', ['name' => 'Archive']);
    seed_test_design_direction($project, overrides: ['cta_style' => 'underline', 'shape' => 'soft']);

    $payload = valid_theme_payload();
    $payload['styles']['elements']['button'] = [
        'color' => ['background' => 'var:preset|color|accent', 'text' => 'var:preset|color|base'],
        'typography' => ['fontWeight' => '650'],
        'spacing' => ['padding' => ['top' => '2rem']],
    ];
    $llm = new FakeLlm();
    $llm->queueJson($payload);
    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $button = $project->readJson('theme/theme.json')['styles']['elements']['button'];
    assert_eq('transparent', $button['color']['background']);
    assert_eq('inherit', $button['color']['text']);
    assert_eq('underline', $button['typography']['textDecoration']);
    assert_eq('650', $button['typography']['fontWeight']);
    assert_eq('0.5rem', $button['border']['radius']);
    assert_eq('0', $button['border']['width']);
    assert_true(
        !isset($button['border']['bottom']),
        'a soft shape cannot curve a bottom border the underline CTA no longer draws',
    );
    assert_contains('committed underline CTA construction', $project->readText('logs/theme-json-direction-bind.txt'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('CTA markup removes local construction and block strips width at content width', function () {
    $markup = '<!-- wp:button {"backgroundColor":"accent","textColor":"base","width":50,'
        . '"className":"is-style-outline hover-lift","style":{"border":{"radius":"8px",'
        . '"width":"3px","color":"#f00"},"spacing":{"padding":{"top":"2rem"},'
        . '"margin":{"top":"1rem"}},"css":"display:none"}} -->'
        . '<div class="wp-block-button is-style-outline wp-block-button__width-50">'
        . '<a class="wp-block-button__link has-accent-background-color has-background '
        . 'has-base-color has-text-color wp-element-button">Go</a></div><!-- /wp:button -->';

    $ghost = CtaStyleMarkup::normalize($markup, 'ghost-arrow');
    $doc = BlockMarkup::parse($ghost['markup']);
    $attrs = $doc->attrs(0);
    assert_true(!isset($attrs['backgroundColor'], $attrs['textColor']));
    assert_eq('8px', $attrs['style']['border']['radius']);
    assert_true(!isset($attrs['style']['border']['width'], $attrs['style']['spacing']['padding']));
    assert_eq(['top' => '1rem'], $attrs['style']['spacing']['margin']);
    assert_eq('hover-lift', $attrs['className']);
    assert_true(!str_contains($ghost['markup'], 'is-style-outline'));
    assert_true(!str_contains($ghost['markup'], 'has-accent-background-color'));
    assert_true(count($ghost['changes']) >= 7);
    $fixed = CtaStyleMarkup::normalize($ghost['markup'], 'ghost-arrow');
    assert_eq($ghost['markup'], $fixed['markup']);
    assert_eq([], $fixed['changes']);

    // A bare button (no column or card ancestor) sits at content width, so
    // block strips the authored width like every other style does.
    $block = CtaStyleMarkup::normalize($markup, 'block');
    assert_true(!isset(BlockMarkup::parse($block['markup'])->attrs(0)['width']));
    assert_true(!str_contains($block['markup'], 'wp-block-button__width-50'));
    assert_true(!str_contains($block['markup'], 'wp-block-button__width-100'), 'block never injects a width of its own');
    assert_eq('hover-lift', BlockMarkup::parse($block['markup'])->attrs(0)['className']);
    $blockFixed = CtaStyleMarkup::normalize($block['markup'], 'block');
    assert_eq($block['markup'], $blockFixed['markup']);
    assert_eq([], $blockFixed['changes']);

    $unsupportedWidth = '<!-- wp:button {"width":33,"className":"wp-block-button__width-33 keep"} -->'
        . '<div class="wp-block-button wp-block-button__width-33">'
        . '<a class="wp-block-button__link wp-element-button">Odd</a></div><!-- /wp:button -->';
    $unsupportedBlock = CtaStyleMarkup::normalize($unsupportedWidth, 'block');
    assert_true(!str_contains($unsupportedBlock['markup'], '"width":'));
    assert_true(!str_contains($unsupportedBlock['markup'], 'wp-block-button__width-'));
    assert_eq('keep', BlockMarkup::parse($unsupportedBlock['markup'])->attrs(0)['className']);
    $unsupportedOutline = CtaStyleMarkup::normalize($unsupportedWidth, 'outline');
    assert_true(!str_contains($unsupportedOutline['markup'], '"width"'));
    assert_true(!str_contains($unsupportedOutline['markup'], 'wp-block-button__width-33'));
    assert_eq('keep', BlockMarkup::parse($unsupportedOutline['markup'])->attrs(0)['className']);
});

/** One button inside one column of a two-column row; $width is the column width attribute. */
function cta_test_column_button(string $width, string $rowAlign = '', string $buttons = '', string $button = '{"width":100}'): string
{
    $align = $rowAlign === '' ? '' : '"align":"' . $rowAlign . '",';
    $alignClass = $rowAlign === '' ? '' : ' align' . $rowAlign;
    $buttonsAttrs = $buttons === '' ? '' : ' ' . $buttons;
    return '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:columns {' . $align . '"style":{}} --><div class="wp-block-columns' . $alignClass . '">'
        . '<!-- wp:column {"width":"' . $width . '"} --><div class="wp-block-column" style="flex-basis:' . $width . '">'
        . '<!-- wp:buttons' . $buttonsAttrs . ' --><div class="wp-block-buttons">'
        . '<!-- wp:button ' . $button . ' --><div class="wp-block-button has-custom-width wp-block-button__width-100">'
        . '<a class="wp-block-button__link wp-element-button">Go</a></div><!-- /wp:button -->'
        . '</div><!-- /wp:buttons --></div><!-- /wp:column -->'
        . '<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Copy</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->';
}

test('block CTA keeps an authored full width only inside a narrow container', function () {
    // 30% of a content-width row: narrow, the authored width survives as the
    // canonical class and the has-custom-width residue goes.
    $narrow = CtaStyleMarkup::normalize(cta_test_column_button('30%'), 'block', 1040.0, 1760.0);
    $doc = BlockMarkup::parse($narrow['markup']);
    $button = null;
    foreach ($doc->indices() as $i) {
        if ($doc->name($i) === 'button') {
            $button = $i;
        }
    }
    assert_true($button !== null);
    assert_eq('wp-block-button__width-100', $doc->attrs($button)['className']);
    assert_true(!isset($doc->attrs($button)['width']), 'the width attribute never survives delivery');
    assert_contains('class="wp-block-button wp-block-button__width-100"', $narrow['markup']);
    assert_true(!str_contains($narrow['markup'], 'has-custom-width'));
    $kept = array_values(array_filter(
        $narrow['changes'],
        static fn (array $change): bool => $change['delivered'] === 'wp-block-button__width-100',
    ));
    assert_true($kept !== [], 'keeping the width is a recorded canonicalization');
    assert_contains('column share 30%', $kept[0]['disposition']);
    $narrowFixed = CtaStyleMarkup::normalize($narrow['markup'], 'block', 1040.0, 1760.0);
    assert_eq($narrow['markup'], $narrowFixed['markup']);
    assert_eq([], $narrowFixed['changes'], 'a kept full width reaches a fixed point');

    // 58% of a content-width row: wide, the width is removed and the row
    // names the share so the warning is actionable.
    $wide = CtaStyleMarkup::normalize(cta_test_column_button('58%'), 'block', 1040.0, 1760.0);
    assert_true(!str_contains($wide['markup'], 'wp-block-button__width-100'));
    assert_true(!str_contains($wide['markup'], 'has-custom-width'));
    assert_true(!str_contains($wide['markup'], '"width":100'));
    $removed = array_values(array_filter(
        $wide['changes'],
        static fn (array $change): bool => $change['property'] === 'width',
    ));
    assert_eq(1, count($removed));
    assert_eq(100, $removed[0]['authored']);
    assert_eq(null, $removed[0]['delivered']);
    assert_contains('removed full width outside a narrow container (column share 58%', $removed[0]['disposition']);
    $wideFixed = CtaStyleMarkup::normalize($wide['markup'], 'block', 1040.0, 1760.0);
    assert_eq($wide['markup'], $wideFixed['markup']);
    assert_eq([], $wideFixed['changes']);

    // The same 30% column in a wide row spans 30% * 1760 / 1040 = 51% of the
    // content width, so alignment moves the column across the boundary.
    $wideRow = CtaStyleMarkup::normalize(cta_test_column_button('30%', 'wide'), 'block', 1040.0, 1760.0);
    assert_true(!str_contains($wideRow['markup'], 'wp-block-button__width-100'));
    $wideRowRemoved = array_values(array_filter(
        $wideRow['changes'],
        static fn (array $change): bool => $change['property'] === 'width',
    ));
    assert_contains('column share 51%', $wideRowRemoved[0]['disposition']);

    // Without theme sizes the row alignment cannot be scaled: the share is
    // read against the row alone.
    $unsized = CtaStyleMarkup::normalize(cta_test_column_button('30%', 'wide'), 'block');
    assert_contains('wp-block-button__width-100', $unsized['markup']);

    // Exactly one third is still narrow.
    $third = CtaStyleMarkup::normalize(cta_test_column_button('33.33%'), 'block', 1040.0, 1760.0);
    assert_contains('wp-block-button__width-100', $third['markup']);

    // A wide column that authored no width stays untouched: block never
    // injects the class.
    $plain = CtaStyleMarkup::normalize(
        str_replace(
            ['<!-- wp:button {"width":100} -->', ' has-custom-width wp-block-button__width-100'],
            ['<!-- wp:button -->', ''],
            cta_test_column_button('58%'),
        ),
        'block',
        1040.0,
        1760.0,
    );
    assert_true(!str_contains($plain['markup'], 'wp-block-button__width-100'));
    assert_eq([], $plain['changes']);
    $plainNarrow = CtaStyleMarkup::normalize(
        str_replace(
            ['<!-- wp:button {"width":100} -->', ' has-custom-width wp-block-button__width-100'],
            ['<!-- wp:button -->', ''],
            cta_test_column_button('30%'),
        ),
        'block',
        1040.0,
        1760.0,
    );
    assert_true(!str_contains($plainNarrow['markup'], 'wp-block-button__width-100'));
    assert_eq([], $plainNarrow['changes']);
});

test('block CTA treats a card as narrow and relaxes a stretched container elsewhere', function () {
    $card = '<!-- wp:group {"className":"card-style--framed"} --><div class="wp-block-group card-style--framed">'
        . '<!-- wp:group {"className":"card-body"} --><div class="wp-block-group card-body">'
        . '<!-- wp:buttons {"className":"cta-bottom"} --><div class="wp-block-buttons cta-bottom">'
        . '<!-- wp:button {"className":"wp-block-button__width-100"} -->'
        . '<div class="wp-block-button wp-block-button__width-100">'
        . '<a class="wp-block-button__link wp-element-button">Card</a></div><!-- /wp:button -->'
        . '</div><!-- /wp:buttons --></div><!-- /wp:group --></div><!-- /wp:group -->';
    $inCard = CtaStyleMarkup::normalize($card, 'block', 1040.0, 1760.0);
    assert_eq($card, $inCard['markup'], 'a card is narrow at any column width');
    assert_eq([], $inCard['changes']);

    // Equal unsized columns split the row: three columns are narrow, two are not.
    $three = '<!-- wp:columns --><div class="wp-block-columns">'
        . '<!-- wp:column --><div class="wp-block-column">'
        . '<!-- wp:buttons --><div class="wp-block-buttons">'
        . '<!-- wp:button {"width":100} --><div class="wp-block-button wp-block-button__width-100">'
        . '<a class="wp-block-button__link wp-element-button">One</a></div><!-- /wp:button -->'
        . '</div><!-- /wp:buttons --></div><!-- /wp:column -->'
        . '<!-- wp:column --><div class="wp-block-column"></div><!-- /wp:column -->'
        . '<!-- wp:column --><div class="wp-block-column"></div><!-- /wp:column -->'
        . '</div><!-- /wp:columns -->';
    assert_contains('wp-block-button__width-100', CtaStyleMarkup::normalize($three, 'block', 1040.0, 1760.0)['markup']);
    $empty = '<!-- wp:column --><div class="wp-block-column"></div><!-- /wp:column -->';
    $two = str_replace($empty . $empty, $empty, $three);
    assert_true($two !== $three);
    assert_true(!str_contains(CtaStyleMarkup::normalize($two, 'block', 1040.0, 1760.0)['markup'], 'wp-block-button__width-100'));

    // The hero shape from the audited build: a vertical, stretched buttons
    // container at content width. Both the width and the stretch go.
    $stretched = '<!-- wp:cover {"url":"x.jpg"} --><div class="wp-block-cover">'
        . '<div class="wp-block-cover__inner-container">'
        . '<!-- wp:buttons {"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->'
        . '<div class="wp-block-buttons is-vertical is-content-justification-stretch">'
        . '<!-- wp:button {"width":100,"className":"has-custom-width wp-block-button__width-100"} -->'
        . '<div class="wp-block-button has-custom-width wp-block-button__width-100">'
        . '<a class="wp-block-button__link wp-element-button">View the archive</a></div><!-- /wp:button -->'
        . '</div><!-- /wp:buttons --></div></div><!-- /wp:cover -->';
    $relaxed = CtaStyleMarkup::normalize($stretched, 'block', 1040.0, 1760.0);
    assert_true(!str_contains($relaxed['markup'], 'wp-block-button__width-100'));
    assert_true(!str_contains($relaxed['markup'], 'has-custom-width'));
    assert_true(!str_contains($relaxed['markup'], 'stretch'));
    $doc = BlockMarkup::parse($relaxed['markup']);
    assert_eq(['type' => 'flex', 'orientation' => 'vertical'], $doc->attrs(1)['layout']);
    $container = array_values(array_filter(
        $relaxed['changes'],
        static fn (array $change): bool => $change['blockName'] === 'core/buttons',
    ));
    assert_eq(1, count($container));
    assert_eq('layout.justifyContent', $container[0]['property']);
    assert_eq('stretch', $container[0]['authored']);
    assert_contains('removed stretched container outside a narrow container', $container[0]['disposition']);
    $relaxedFixed = CtaStyleMarkup::normalize($relaxed['markup'], 'block', 1040.0, 1760.0);
    assert_eq($relaxed['markup'], $relaxedFixed['markup']);
    assert_eq([], $relaxedFixed['changes']);

    // A non-block style leaves the container alone: only the width goes.
    $outline = CtaStyleMarkup::normalize($stretched, 'outline', 1040.0, 1760.0);
    assert_contains('is-content-justification-stretch', $outline['markup']);
    assert_true(!str_contains($outline['markup'], 'wp-block-button__width-100'));
});

test('block CTA in a narrow vertical buttons container ships the wrapper width rule', function () {
    // Current WordPress sizes a width-classed button in a vertical wp:buttons
    // container from var(--wp--block-button--width); only the removed `width`
    // attribute emits that custom property, so without this rule the wrapper
    // collapses to content width there.
    $vertical = cta_test_column_button(
        '25%',
        '',
        '{"layout":{"type":"flex","orientation":"vertical","justifyContent":"center"}}',
    );
    $normalized = CtaStyleMarkup::normalize($vertical, 'block', 1040.0, 1760.0);
    $doc = BlockMarkup::parse($normalized['markup']);
    assert_eq('wp-block-button__width-100', $doc->attrs(4)['className']);
    assert_true(!isset($doc->attrs(4)['width']), 'the width attribute never survives block delivery');
    assert_contains('class="wp-block-button wp-block-button__width-100"', $normalized['markup']);
    assert_contains('"justifyContent":"center"', $normalized['markup'], 'a centered container is not a stretched one');

    // The theme repair ships the wrapper rules exactly once: the vertical
    // container rule and the mobile fill.
    [$theme, $repairs] = ThemeJsonStep::repairCtaStyle(['version' => 3], 'block');
    assert_eq(CtaStyle::BLOCK_WRAPPER_CSS, $theme['styles']['css']);
    assert_contains('.wp-block-buttons.is-vertical > .wp-block-button.wp-block-button__width-100{width:100%;}', $theme['styles']['css']);
    assert_contains('@media (max-width:781px)', $theme['styles']['css']);
    assert_contains('.wp-block-post-content .wp-block-button > .wp-block-button__link{width:100%;}', $theme['styles']['css']);
    assert_true($repairs !== [], 'the shipped rule is a recorded repair');
    [$fixed, $fixedRepairs] = ThemeJsonStep::repairCtaStyle($theme, 'block');
    assert_eq($theme, $fixed, 'the wrapper rule reaches a fixed point');
    assert_eq([], $fixedRepairs);

    // Authored root CSS survives with the rule appended after it.
    [$appended] = ThemeJsonStep::repairCtaStyle(
        ['version' => 3, 'styles' => ['css' => '.site-note{color:inherit;}']],
        'block',
    );
    assert_eq(
        ".site-note{color:inherit;}\n" . CtaStyle::BLOCK_WRAPPER_CSS,
        $appended['styles']['css'],
    );

    // A changed commitment removes the stale rule instead of shipping it.
    [$outline] = ThemeJsonStep::repairCtaStyle($appended, 'outline');
    assert_eq('.site-note{color:inherit;}', $outline['styles']['css']);
    [$outlineOnly] = ThemeJsonStep::repairCtaStyle($theme, 'outline');
    assert_true(!isset($outlineOnly['styles']['css']), 'an all-build-owned styles.css is removed with the rule');

    // Non-block styles never introduce the rule.
    [$solid] = ThemeJsonStep::repairCtaStyle(['version' => 3], 'solid');
    assert_true(!isset($solid['styles']['css']));
});

test('FixBlocks CTA normalization isolates failed files and keeps healthy siblings repaired', function () {
    with_temp_dir('cta-style-rollback-', function (string $tmp): void {
        $project = new Project($tmp);
        $project->writeJson('designDirection.json', ['cta_style' => 'outline']);
        $project->writeJson('theme/theme.json', ['version' => 3]);
        $healthy = '<!-- wp:button {"backgroundColor":"accent"} -->'
            . '<div class="wp-block-button"><a class="wp-block-button__link has-accent-background-color '
            . 'has-background wp-element-button">Healthy</a></div><!-- /wp:button -->';
        $failed = '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:button {"backgroundColor":"accent"} -->'
            . '<div class="wp-block-button"><a class="wp-block-button__link has-accent-background-color '
            . 'has-background wp-element-button">Failed</a></div><!-- /wp:button -->'
            . '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center" '
            . 'style="text-align:right">Conflict</h2><!-- /wp:heading -->'
            . '</div><!-- /wp:group -->';
        $project->writeText('theme/parts/a-healthy.html', $healthy);
        $project->writeText('theme/parts/b-failed.html', $failed);

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $deliveredHealthy = $project->readText('theme/parts/a-healthy.html');
        assert_true(!str_contains($deliveredHealthy, 'backgroundColor'));
        assert_true(!str_contains($deliveredHealthy, 'has-accent-background-color'));
        assert_contains('Healthy', $deliveredHealthy, 'healthy button content survives');
        assert_eq($failed, $project->readText('theme/parts/b-failed.html'), 'failed file restores entry bytes');

        $beforeWarnings = $project->readJson('warnings.json');
        $warnings = implode("\n", $beforeWarnings['fix-blocks'] ?? []);
        assert_contains(
            'parts/b-failed.html block 0/0 (core/button): CTA property backgroundColor; '
                . 'authored "accent"; delivered "accent" (pre-step value restored); '
                . 'disposition CTA normalization rolled back',
            $warnings,
        );
        assert_true(
            !str_contains($warnings, 'parts/a-healthy.html block 0 (core/button): CTA property'),
            'successful sibling repair does not enter warnings.json',
        );
        $log = $project->readText('logs/fix-blocks.log');
        assert_contains('[cta-style] 1 CTA construction normalization(s)', $log);
        assert_contains('parts/a-healthy.html block 0 (core/button): backgroundColor "accent" -> removed', $log);

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);
        assert_eq($deliveredHealthy, $project->readText('theme/parts/a-healthy.html'));
        assert_eq($beforeWarnings, $project->readJson('warnings.json'), 'fixed point adds no warning rows');
    });
});

test('block CTA width uses the frozen serializer canonical class and reaches a fixed point', function () {
    with_temp_dir('cta-style-width-fixed-point-', function (string $tmp): void {
        $project = new Project($tmp);
        $project->writeJson('designDirection.json', ['cta_style' => 'block']);
        $project->writeJson('theme/theme.json', [
            'version' => 3,
            'settings' => ['layout' => ['contentSize' => '1040px', 'wideSize' => '1760px']],
        ]);
        // A narrow column keeps the authored width; a bare part (content
        // width) loses it. Both must survive the frozen serializer unchanged.
        $project->writeText('theme/parts/cta.html', cta_test_column_button('25%'));
        $project->writeText(
            'theme/parts/wide.html',
            '<!-- wp:button {"width":100} --><div class="wp-block-button wp-block-button__width-100">'
                . '<a class="wp-block-button__link wp-element-button">Go</a></div><!-- /wp:button -->',
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $delivered = $project->readText('theme/parts/cta.html');
        $doc = BlockMarkup::parse($delivered);
        $attrs = $doc->attrs(4);
        assert_eq('button', $doc->name(4));
        assert_true(!isset($attrs['width']), 'the frozen serializer does not retain a width support attr');
        assert_eq('wp-block-button__width-100', $attrs['className']);
        assert_contains('wp-block-button__width-100', $delivered);
        $normalized = CtaStyleMarkup::normalize($delivered, 'block', 1040.0, 1760.0);
        assert_eq($delivered, $normalized['markup']);
        assert_eq([], $normalized['changes'], 'the token normalizer accepts serializer-canonical delivery');

        $wide = $project->readText('theme/parts/wide.html');
        assert_true(!str_contains($wide, 'wp-block-button__width-100'), 'a content-width button keeps intrinsic width');
        assert_true(!str_contains($wide, '"width"'));
        $log = $project->readText('logs/fix-blocks.log');
        assert_contains('removed full width outside a narrow container', $log);

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);
        assert_eq($delivered, $project->readText('theme/parts/cta.html'));
        assert_eq($wide, $project->readText('theme/parts/wide.html'));
    });
});

test('validate-theme warns on a delivered full-width block button in a wide container', function () {
    with_temp_dir('cta-style-validate-width-', function (string $tmp): void {
        $project = new Project($tmp);
        $project->writeJson('designDirection.json', ['cta_style' => 'block']);
        $project->writeJson('theme/theme.json', [
            'version' => 3,
            'settings' => ['layout' => ['contentSize' => '1040px', 'wideSize' => '1760px']],
        ]);
        $project->writeText('theme/parts/narrow.html', cta_test_column_button('25%'));
        $project->writeText('theme/parts/wide.html', cta_test_column_button('58%'));

        $warnings = array_values(array_filter(
            ThemeValidator::ctaWarnings($project),
            static fn (string $warning): bool => str_contains($warning, 'local override drift'),
        ));
        $wide = array_values(array_filter(
            $warnings,
            static fn (string $warning): bool => str_contains($warning, 'file=wide.html'),
        ));
        assert_true($wide !== [], 'the wide container is reported');
        assert_contains('property=width', implode("\n", $wide));
        assert_contains('authored=100', implode("\n", $wide));
        assert_contains(
            'disposition=removed full width outside a narrow container (column share 58% of the content width)',
            implode("\n", $wide),
        );
        $narrow = array_values(array_filter(
            $warnings,
            static fn (string $warning): bool => str_contains($warning, 'file=narrow.html')
                && str_contains($warning, 'removed full width'),
        ));
        assert_eq([], $narrow, 'a narrow container is allowed to fill');
    });
});

test('CTA prompt contract delegates construction and keeps radius separate', function () {
    $direction = (string) file_get_contents(repo_path('prompts/design-direction.md'));
    $theme = (string) file_get_contents(repo_path('prompts/theme-json.md'));
    $section = (string) file_get_contents(repo_path('prompts/section.md'));
    assert_contains('`cta_style`', $direction);
    assert_contains('`ghost-arrow`', $direction);
    assert_contains('the build owns button fill', strtolower($theme));
    assert_contains('CTA construction is global', $section);
    assert_contains('do not set `backgroundColor`', $section);

    // The one-third container rule is stated once in every markup prompt.
    foreach (['hero', 'section', 'footer', 'header'] as $prompt) {
        $text = (string) file_get_contents(repo_path('prompts/' . $prompt . '.md'));
        assert_contains('one third of the content width', $text, $prompt . '.md states the container rule');
    }
    assert_contains('minimal', $direction, 'block is steered away from restrained briefs');
});
