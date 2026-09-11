<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\SectionImageContract;
use Automattic\SiteBuild\Units\SectionUnit;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;

function contract_image(string $slug): string
{
    return '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/' . $slug
        . '.jpg" alt="AI_IMAGE: ' . $slug . ' | card | photo | 1:1"/></figure><!-- /wp:image -->';
}

test('the section image contract removes only excess assets and retains cover text', function () {
    $copy = '<!-- wp:paragraph --><p>Keep every word.</p><!-- /wp:paragraph -->';
    $cover = '<!-- wp:cover {"url":"theme:./assets/extra.jpg"} --><div class="wp-block-cover">'
        . '<img src="theme:./assets/extra.jpg"/><div class="wp-block-cover__inner-container">'
        . $copy . '</div></div><!-- /wp:cover -->';
    $images = contract_image('one') . contract_image('two');
    $raw = $cover . $images . $copy;
    $repairs = $warnings = [];
    $out = SectionImageContract::enforce($raw, 'page-menu--mains', 2, $repairs, $warnings);
    assert_contains($images . $copy, $out);
    assert_eq(2, substr_count($out, $copy));
    assert_true(!str_contains($out, 'extra.jpg'));
    assert_contains("file='theme/parts/page-menu--mains.html'", implode("\n", $warnings));
    assert_contains('block=', implode("\n", $warnings));
    assert_contains('authored=', implode("\n", $warnings));
    assert_contains('delivered=removed', implode("\n", $warnings));
    $nextRepairs = $nextWarnings = [];
    assert_eq($out, SectionImageContract::enforce($out, 'page-menu--mains', 2, $nextRepairs, $nextWarnings));
    assert_eq([], $nextWarnings);
});

test('zero images removes media and preserves adjacent text', function () {
    $copy = '<!-- wp:paragraph --><p>Call the restaurant.</p><!-- /wp:paragraph -->';
    $repairs = $warnings = [];
    $out = SectionImageContract::enforce(contract_image('extra') . $copy, 'page-visit--contact', 0, $repairs, $warnings);
    assert_eq($copy, $out);
    assert_eq(1, count($warnings));
});

test('an unsafe excess image retains its complete boundary', function () {
    $raw = '<!-- wp:image --><figure><img src="theme:./assets/extra.jpg"/><p>Retain this raw content.</p>';
    $repairs = $warnings = [];
    assert_eq($raw, SectionImageContract::enforce($raw, 'page-visit--contact', 0, $repairs, $warnings));
    assert_contains('retained the unsafe media boundary', implode("\n", $warnings));
    assert_eq([], $repairs);
});

test('absent planned images warn without fabricated subjects', function () {
    $copy = '<!-- wp:paragraph --><p>Keep this text.</p><!-- /wp:paragraph -->';
    $repairs = $warnings = [];
    assert_eq($copy, SectionImageContract::enforce($copy, 'page-about--story', 2, $repairs, $warnings));
    assert_contains('delivered=0', implode("\n", $warnings));
    assert_contains('add the absent planned subjects', implode("\n", $warnings));
});

test('an image that owns a text block retains the text boundary', function () {
    $text = '<!-- wp:paragraph --><p>Retain this section text.</p><!-- /wp:paragraph -->';
    $raw = '<!-- wp:image --><figure><img src="theme:./assets/extra.jpg"/>' . $text . '</figure><!-- /wp:image -->';
    $repairs = $warnings = [];
    assert_eq($raw, SectionImageContract::enforce($raw, 'page-visit--contact', 0, $repairs, $warnings));
    assert_contains('retained the unsafe media boundary', implode("\n", $warnings));
});
