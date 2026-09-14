<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\CtaStyle;
use Automattic\SiteBuild\CtaStyleMarkup;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\ThemeValidator;
use Automattic\SiteBuild\Steps\CoverContrastStep;

require_once __DIR__ . '/cover_contrast_test.php';

function transparent_cover_cta_markup(): string
{
    return '<!-- wp:cover {"url":"theme:./assets/hero.png","dimRatio":50,"overlayColor":"base"} -->'
        . '<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-base-background-color has-background-dim"></span>'
        . '<img class="wp-block-cover__image-background" alt="" src="theme:./assets/hero.png" data-object-fit="cover"/>'
        . '<div class="wp-block-cover__inner-container">'
        . '<!-- wp:paragraph {"textColor":"contrast"} --><p class="has-contrast-color has-text-color">Readable copy</p><!-- /wp:paragraph -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons">'
        . '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/menu/">Explore the menu</a></div><!-- /wp:button -->'
        . '</div><!-- /wp:buttons -->'
        . '</div></div><!-- /wp:cover -->';
}

test('cover contrast repairs transparent CTA ink and reaches a fixed point', function () {
    if (!extension_loaded('imagick')) {
        skip_test('Imagick is required for the cover image check');
    }
    foreach (['underline', 'ghost-arrow'] as $style) {
        [$project, $tmp] = cover_step_project(transparent_cover_cta_markup(), 'white');
        try {
            $theme = $project->readJson('theme/theme.json');
            $theme['styles']['elements']['button'] = CtaStyle::themeStyle($style);
            $project->writeJson('theme/theme.json', $theme);
            $project->writeJson('designDirection.json', ['cta_style' => $style]);
            $step = new CoverContrastStep(new PhpBlockFixer());
            quietly(fn () => $step->run($project));
            $first = $project->readText('theme/templates/front-page.html');
            $doc = BlockMarkup::parse($first);
            $buttons = array_values(array_filter($doc->indices(), fn (int $i): bool => $doc->name($i) === 'button'));
            assert_eq(1, count($buttons));
            $attrs = $doc->attrs($buttons[0]);
            assert_eq('contrast', $attrs['textColor'] ?? null);
            assert_contains('cover-contrast-transparent-button', $attrs['className'] ?? '');
            assert_contains('has-contrast-color', $doc->innerHtml($buttons[0]));
            assert_contains('href="/menu/"', $doc->innerHtml($buttons[0]));
            assert_true(!isset($attrs['backgroundColor']));
            assert_eq($theme, $project->readJson('theme/theme.json'));
            assert_eq([], CtaStyleMarkup::normalize($first, $style)['changes']);
            assert_eq([], ThemeValidator::ctaWarnings($project));
            (new PhpBlockFixer())->fix($project->themePath());
            quietly(fn () => $step->run($project));
            assert_eq($first, $project->readText('theme/templates/front-page.html'));
        } finally {
            remove_tree($tmp);
        }
    }
});

test('cover contrast preserves filled CTA construction and HTML-first CSS ownership', function () {
    if (!extension_loaded('imagick')) {
        skip_test('Imagick is required for the cover image check');
    }
    foreach ([['solid', false], ['block', false], ['outline', false], ['underline', true]] as [$style, $htmlFirst]) {
        $markup = transparent_cover_cta_markup();
        [$project, $tmp] = cover_step_project($markup, 'white');
        try {
            $theme = $project->readJson('theme/theme.json');
            $theme['styles']['elements']['button'] = CtaStyle::themeStyle($style);
            $project->writeJson('theme/theme.json', $theme);
            quietly(fn () => (new CoverContrastStep(new PhpBlockFixer(), htmlFirst: $htmlFirst))->run($project));
            assert_eq($markup, $project->readText('theme/templates/front-page.html'));
            assert_eq($theme, $project->readJson('theme/theme.json'));
        } finally {
            remove_tree($tmp);
        }
    }
});

test('the cover CTA marker does not exempt unrelated local color overrides', function () {
    $button = '<!-- wp:button {"textColor":"contrast","className":"cover-contrast-transparent-button"} -->'
        . '<div class="wp-block-button cover-contrast-transparent-button"><a class="wp-block-button__link has-contrast-color has-text-color wp-element-button" href="/menu/">Menu</a></div><!-- /wp:button -->';
    foreach ([
        [$button, 'underline'],
        ['<!-- wp:cover --><div class="wp-block-cover">' . str_replace('"textColor":"contrast"', '"textColor":"primary"', $button) . '</div><!-- /wp:cover -->', 'underline'],
        ['<!-- wp:cover --><div class="wp-block-cover">' . $button . '</div><!-- /wp:cover -->', 'solid'],
    ] as [$markup, $style]) {
        $result = CtaStyleMarkup::normalize($markup, $style);
        assert_true(array_filter($result['changes'], fn ($change): bool => $change['property'] === 'textColor') !== []);
        assert_true(!str_contains($result['markup'], CtaStyleMarkup::COVER_CONTRAST_CLASS));
    }
});
