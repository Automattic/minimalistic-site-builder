<?php
declare(strict_types=1);

use Automattic\SiteBuild\SectionComposition;
use Automattic\SiteBuild\Units\GeneratedMarkup;

function no_image_band(string $archetype, string $inner): string
{
    return '<!-- wp:group {"className":"section-composition--' . $archetype . '","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group section-composition--' . $archetype . '">'
        . '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Services</h2><!-- /wp:heading -->'
        . $inner
        . '</div><!-- /wp:group -->';
}

function no_image_picture(string $name, string $extra = ''): string
{
    return '<!-- wp:image {"className":"card-media"' . $extra . '} --><figure class="wp-block-image card-media">'
        . '<img src="theme:./assets/' . $name . '.jpg" alt="AI_IMAGE: a clay sphere | card | 3d-object | square"/></figure><!-- /wp:image -->';
}

test('a zero-media archetype drops its authored image blocks at the block boundary and keeps the copy (frm PR-3u)', function () {
    $markup = no_image_band(
        'statement-lines',
        '<!-- wp:group {"className":"item-pattern__item"} --><div class="wp-block-group item-pattern__item">'
        . no_image_picture('service-research-sphere')
        . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">User research</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Interviews and audits.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . no_image_picture('service-systems-cubes')
        . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Design systems</h3><!-- /wp:heading -->'
    );
    $repairs = [];
    $warnings = [];
    $out = GeneratedMarkup::stripMediaOffNoImageArchetype($markup, 'page-home--services', 'statement-lines', $repairs, $warnings);
    assert_true(!str_contains($out, '<img'), 'no picture survives');
    assert_true(!str_contains($out, 'wp:image'), 'no image block survives');
    assert_contains('User research', $out);
    assert_contains('Design systems', $out);
    assert_contains('Interviews and audits.', $out);
    assert_contains('section-composition--statement-lines', $out);
    assert_eq(1, count($repairs));
    assert_eq('no-image-archetype-media-removed', $repairs[0]['code']);
    assert_eq('2 authored image(s) on statement-lines', $repairs[0]['authored']);
    assert_eq('removed', $repairs[0]['delivered']);
    assert_eq(2, count($warnings));
    assert_contains('the statement-lines archetype plans no media', $warnings[0]);
    assert_contains('service-research-sphere', $warnings[0]);
    $joined = implode("\n", SectionComposition::markupWarnings($out, 'statement-lines', 'page-home--services'));
    assert_true(!str_contains($joined, 'archetype media count'), 'the media count check is satisfied');
});

test('every zero-media archetype strips, a media archetype and an unassigned section keep their pictures (frm PR-3u)', function () {
    foreach (SectionComposition::ARCHETYPES as $archetype) {
        $markup = no_image_band($archetype, no_image_picture('object'));
        $repairs = [];
        $warnings = [];
        $out = GeneratedMarkup::stripMediaOffNoImageArchetype($markup, 'page-home--x', $archetype, $repairs, $warnings);
        $zero = (int) SectionComposition::metadata($archetype)['max_images'] === 0;
        assert_eq(!$zero, str_contains($out, '<img'), $archetype);
        assert_eq($zero ? 1 : 0, count($repairs), $archetype);
    }
    foreach (['pricing-tiers', 'stat-ledger', 'feature-row-hairlines', 'statement-lines', 'logo-strip'] as $archetype) {
        assert_eq(0, (int) SectionComposition::metadata($archetype)['max_images'], $archetype . ' plans no media');
    }
    $markup = no_image_band('statement-lines', no_image_picture('object'));
    $repairs = [];
    $warnings = [];
    assert_eq($markup, GeneratedMarkup::stripMediaOffNoImageArchetype($markup, 'page-home--x', null, $repairs, $warnings), 'no assignment, no repair');
    assert_eq([], $repairs);
    assert_eq([], $warnings);
});

test('a gallery on a zero-media archetype goes as one block (frm PR-3u)', function () {
    $gallery = '<!-- wp:gallery {"columns":2} --><figure class="wp-block-gallery has-nested-images columns-2">'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/a.jpg" alt="AI_IMAGE: a | b | photo | square"/></figure><!-- /wp:image -->'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/b.jpg" alt="AI_IMAGE: c | d | photo | square"/></figure><!-- /wp:image -->'
        . '</figure><!-- /wp:gallery -->';
    $repairs = [];
    $warnings = [];
    $out = GeneratedMarkup::stripMediaOffNoImageArchetype(no_image_band('logo-strip', $gallery), 'page-home--partners', 'logo-strip', $repairs, $warnings);
    assert_true(!str_contains($out, 'wp:gallery'));
    assert_true(!str_contains($out, '<img'));
    assert_contains('Services', $out);
    assert_eq(1, count($warnings), 'the outermost span is one removal');
});
