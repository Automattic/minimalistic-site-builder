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
    assert_contains('width:100%', $block['css']);
    assert_eq('var:preset|color|contrast', $block['color']['background']);

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

test('CTA markup removes local construction and block style enforces full width', function () {
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

    $block = CtaStyleMarkup::normalize($markup, 'block');
    assert_true(!isset(BlockMarkup::parse($block['markup'])->attrs(0)['width']));
    assert_contains('wp-block-button__width-100', BlockMarkup::parse($block['markup'])->attrs(0)['className']);
    assert_true(!str_contains($block['markup'], 'wp-block-button__width-50'));
    assert_contains('wp-block-button__width-100', $block['markup']);

    $nonBlock = CtaStyleMarkup::normalize($block['markup'], 'outline');
    assert_true(!isset(BlockMarkup::parse($nonBlock['markup'])->attrs(0)['width']));
    assert_true(!str_contains($nonBlock['markup'], 'wp-block-button__width-100'));
    $nonBlockFixed = CtaStyleMarkup::normalize($nonBlock['markup'], 'outline');
    assert_eq($nonBlock['markup'], $nonBlockFixed['markup']);
    assert_eq([], $nonBlockFixed['changes']);

    $unsupportedWidth = '<!-- wp:button {"width":33,"className":"wp-block-button__width-33 keep"} -->'
        . '<div class="wp-block-button wp-block-button__width-33">'
        . '<a class="wp-block-button__link wp-element-button">Odd</a></div><!-- /wp:button -->';
    $unsupportedBlock = CtaStyleMarkup::normalize($unsupportedWidth, 'block');
    assert_true(!str_contains($unsupportedBlock['markup'], '"width":'));
    assert_contains('wp-block-button__width-100', $unsupportedBlock['markup']);
    assert_true(!str_contains($unsupportedBlock['markup'], 'wp-block-button__width-33'));
    assert_eq(
        'keep wp-block-button__width-100',
        BlockMarkup::parse($unsupportedBlock['markup'])->attrs(0)['className'],
    );
    $unsupportedOutline = CtaStyleMarkup::normalize($unsupportedWidth, 'outline');
    assert_true(!str_contains($unsupportedOutline['markup'], '"width"'));
    assert_true(!str_contains($unsupportedOutline['markup'], 'wp-block-button__width-33'));
    assert_eq('keep', BlockMarkup::parse($unsupportedOutline['markup'])->attrs(0)['className']);

    $misplacedWidth = '<!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link '
        . 'wp-block-button__width-100 wp-element-button">Misplaced</a></div><!-- /wp:button -->';
    $repositioned = CtaStyleMarkup::normalize($misplacedWidth, 'block');
    assert_eq(
        1,
        substr_count(BlockMarkup::parse($repositioned['markup'])->ownHtml(0), 'wp-block-button__width-100'),
    );
    assert_contains('class="wp-block-button wp-block-button__width-100"', $repositioned['markup']);
    assert_contains('class="wp-block-button__link wp-element-button"', $repositioned['markup']);
});

test('block CTA in a vertical buttons container ships the wrapper width rule', function () {
    // Current WordPress sizes a width-classed button in a vertical wp:buttons
    // container from var(--wp--block-button--width); only the removed `width`
    // attribute emits that custom property, so without this rule the wrapper
    // collapses to content width there.
    $vertical = '<!-- wp:buttons {"layout":{"type":"flex","orientation":"vertical",'
        . '"justifyContent":"center"}} -->'
        . '<div class="wp-block-buttons">'
        . '<!-- wp:button {"width":100} -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Reserve</a></div>'
        . '<!-- /wp:button --></div><!-- /wp:buttons -->';
    $normalized = CtaStyleMarkup::normalize($vertical, 'block');
    $doc = BlockMarkup::parse($normalized['markup']);
    assert_eq('wp-block-button__width-100', $doc->attrs(1)['className']);
    assert_true(!isset($doc->attrs(1)['width']), 'the width attribute never survives block delivery');
    assert_contains('class="wp-block-button wp-block-button__width-100"', $normalized['markup']);

    // The theme repair ships the vertical-container wrapper rule exactly once.
    [$theme, $repairs] = ThemeJsonStep::repairCtaStyle(['version' => 3], 'block');
    assert_eq(CtaStyle::BLOCK_VERTICAL_WRAPPER_CSS, $theme['styles']['css']);
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
        ".site-note{color:inherit;}\n" . CtaStyle::BLOCK_VERTICAL_WRAPPER_CSS,
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
        $project->writeJson('theme/theme.json', ['version' => 3]);
        $project->writeText(
            'theme/parts/cta.html',
            '<!-- wp:button {"width":100} --><div class="wp-block-button wp-block-button__width-100">'
                . '<a class="wp-block-button__link wp-element-button">Go</a></div><!-- /wp:button -->',
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $delivered = $project->readText('theme/parts/cta.html');
        $attrs = BlockMarkup::parse($delivered)->attrs(0);
        assert_true(!isset($attrs['width']), 'the frozen serializer does not retain a width support attr');
        assert_eq('wp-block-button__width-100', $attrs['className']);
        assert_contains('wp-block-button__width-100', $delivered);
        $normalized = CtaStyleMarkup::normalize($delivered, 'block');
        assert_eq($delivered, $normalized['markup']);
        assert_eq([], $normalized['changes'], 'the token normalizer accepts serializer-canonical delivery');

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);
        assert_eq($delivered, $project->readText('theme/parts/cta.html'));
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
});
