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

test('a section over its media budget keeps the first pictures in document order and drops the rest (frm PR-3y)', function () {
    $band = static fn (string $archetype, string $inner): string => no_image_band($archetype, $inner);
    $three = $band('centered-stack', no_image_picture('screen-one') . '<!-- wp:paragraph --><p>Copy.</p><!-- /wp:paragraph -->' . no_image_picture('screen-two') . no_image_picture('screen-three'));
    $repairs = [];
    $warnings = [];
    $out = GeneratedMarkup::stripMediaOverBudget($three, 'page-home--dashboard', 'centered-stack', $repairs, $warnings);
    assert_eq(1, preg_match_all('~<img\\b~', $out), 'a centered stack has one supporting image');
    assert_contains('screen-one', $out);
    assert_true(!str_contains($out, 'screen-two'));
    assert_true(!str_contains($out, 'screen-three'), 'the third picture, last in document order, goes');
    assert_contains('Copy.', $out);
    assert_eq(1, count($repairs));
    assert_eq('media-over-budget-removed', $repairs[0]['code']);
    assert_eq('3 authored image(s) on centered-stack (budget 1)', $repairs[0]['authored']);
    assert_eq('1 kept', $repairs[0]['delivered']);
    assert_eq(2, count($warnings));
    $warningText = implode("\n", $warnings);
    assert_contains('budgets 1 picture(s) and this section authored 3', $warningText);
    assert_contains('screen-two', $warningText);
    assert_contains('screen-three', $warningText);
    $againRepairs = [];
    $againWarnings = [];
    assert_eq($out, GeneratedMarkup::stripMediaOverBudget($out, 'page-home--dashboard', 'centered-stack', $againRepairs, $againWarnings));
    assert_eq([], $againRepairs);
    assert_eq([], $againWarnings);
    $joined = implode("\n", SectionComposition::markupWarnings($out, 'centered-stack', 'page-home--dashboard'));
    assert_true(!str_contains($joined, 'archetype media count'), 'the media count check is satisfied');

    $two = $band('cta-panel', no_image_picture('a') . no_image_picture('b'));
    $repairs = [];
    $warnings = [];
    $one = GeneratedMarkup::stripMediaOverBudget($two, 'page-home--cta', 'cta-panel', $repairs, $warnings);
    assert_eq(1, preg_match_all('~<img\\b~', $one));
    assert_contains('assets/a.jpg', $one);
    $within = $band('centered-stack', no_image_picture('a'));
    $repairs = [];
    $warnings = [];
    assert_eq($within, GeneratedMarkup::stripMediaOverBudget($within, 'page-home--x', 'centered-stack', $repairs, $warnings));
    assert_eq([], $repairs);
    assert_eq($within, GeneratedMarkup::stripMediaOverBudget($within, 'page-home--x', null, $repairs, $warnings));
    $zero = $band('statement-lines', no_image_picture('a'));
    assert_eq($zero, GeneratedMarkup::stripMediaOverBudget($zero, 'page-home--x', 'statement-lines', $repairs, $warnings), 'a zero budget belongs to the other repair');
    assert_eq([], $repairs);
});

test('media removal preserves a block with other content and removes only the independent image', function () {
    $preserved = '<!-- wp:image --><figure class="wp-block-image"><img src="keep.jpg" alt=""/>'
        . '<!-- wp:paragraph --><p>Keep this content.</p><!-- /wp:paragraph --></figure><!-- /wp:image -->';
    $markup = no_image_band('statement-lines', $preserved . no_image_picture('remove'));
    $repairs = [];
    $warnings = [];
    $out = GeneratedMarkup::stripMediaOffNoImageArchetype($markup, 'page-home--values', 'statement-lines', $repairs, $warnings);
    assert_contains($preserved, $out);
    assert_true(!str_contains($out, 'assets/remove.jpg'));
    $report = implode("\n", $warnings);
    assert_contains('keep.jpg', $report);
    assert_contains('remove.jpg', $report);
    assert_contains('retained byte-for-byte', $report);
    $againRepairs = [];
    $againWarnings = [];
    assert_eq($out, GeneratedMarkup::stripMediaOffNoImageArchetype($out, 'page-home--values', 'statement-lines', $againRepairs, $againWarnings));
});
