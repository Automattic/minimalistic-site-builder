<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\SectionReadabilityContract;

function readability_input(): array
{
    return ['theme_json' => ['settings' => ['typography' => ['fontSizes' => [
        ['slug' => 'body', 'size' => '1rem'], ['slug' => 'lead', 'size' => '1.5rem'],
    ]]]], 'section' => ['role' => 'content', 'title' => 'Our dishes']];
}

test('long paragraphs use body scale and retain all text and siblings', function () {
    $text = str_repeat('This paragraph describes has-lead-font-size as text. ', 5);
    $short = '<!-- wp:paragraph {"fontSize":"lead"} --><p class="has-lead-font-size">Short lead.</p><!-- /wp:paragraph -->';
    $long = '<!-- wp:paragraph {"fontSize":"lead"} --><p class="has-lead-font-size">' . $text . '</p><!-- /wp:paragraph -->';
    $repairs = $warnings = [];
    $out = SectionReadabilityContract::enforce($short . $long, readability_input(), 'page-menu--food', $repairs, $warnings);
    assert_contains($short, $out);
    assert_contains($text, $out);
    assert_contains('"fontSize":"body"', $out);
    assert_contains('has-body-font-size', $out);
    assert_contains('authored="lead"; delivered="body"', implode("\n", $warnings));
    $nextRepairs = $nextWarnings = [];
    assert_eq($out, SectionReadabilityContract::enforce($out, readability_input(), 'page-menu--food', $nextRepairs, $nextWarnings));
    assert_eq([], $nextWarnings);
});

test('a section with only item headings receives its planned h2', function () {
    $items = '<!-- wp:heading {"level":3} --><h3>Khinkali</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Six dumplings.</p><!-- /wp:paragraph -->';
    $raw = '<!-- wp:group --><div class="wp-block-group">' . $items . '</div><!-- /wp:group -->';
    $repairs = $warnings = [];
    $out = SectionReadabilityContract::enforce($raw, readability_input(), 'page-menu--food', $repairs, $warnings);
    assert_contains('<h2 class="wp-block-heading">Our dishes</h2>', $out);
    assert_contains($items, $out);
    assert_eq([], $warnings);
    assert_eq($out, SectionReadabilityContract::enforce($out, readability_input(), 'page-menu--food', $repairs, $warnings));
    $input = readability_input();
    $input['section']['role'] = 'hero';
    assert_eq($raw, SectionReadabilityContract::enforce($raw, $input, 'page-menu--hero', $repairs, $warnings));
});

test('the readability contract retains unsafe text boundaries', function () {
    $raw = '<!-- wp:paragraph {"fontSize":"lead"} --><p class="has-lead-font-size">' . str_repeat('Keep these words. ', 12);
    $repairs = $warnings = [];
    assert_eq($raw, SectionReadabilityContract::enforce($raw, readability_input(), 'page-menu--food', $repairs, $warnings));
});

test('malformed paragraph font sizes retain content with an actionable warning', function () {
    foreach ([['lead'], 42, true] as $size) {
        $raw = '<!-- wp:paragraph ' . json_encode(['fontSize' => $size]) . ' --><p>'
            . str_repeat('Keep these words. ', 10) . '</p><!-- /wp:paragraph -->';
        $repairs = $warnings = [];
        assert_eq($raw, SectionReadabilityContract::enforce($raw, readability_input(), 'page-menu--food', $repairs, $warnings));
        assert_contains('retained the malformed fontSize', implode("\n", $warnings));
    }
});

test('paragraph size repair changes only real class and font-size attributes', function () {
    foreach (['<p>', "<p class='has-lead-font-size'>", '<p title="has-lead-font-size" class="has-lead-font-size">',
        '<p data-class="meta" class="has-lead-font-size" style="font-size:36px;margin-top:var(--wp--preset--spacing--md)">'] as $tag) {
        $raw = '<!-- wp:paragraph {"fontSize":"lead"} -->' . $tag . str_repeat('Keep these words. ', 10) . '</p><!-- /wp:paragraph -->';
        $repairs = $warnings = [];
        $out = SectionReadabilityContract::enforce($raw, readability_input(), 'page-menu--food', $repairs, $warnings);
        $document = \Automattic\SiteBuild\BlockMarkup::parse($out);
        $class = \Automattic\SiteBuild\MarkupScan::tagAttribute(\Automattic\SiteBuild\MarkupScan::wrapperTag($document->ownHtml(0), 0), 'class');
        assert_contains('has-body-font-size', $class[0]);
        assert_true(!str_contains($out, 'font-size:36px'));
        if (str_contains($tag, 'title=')) {
            assert_contains('title="has-lead-font-size"', $out);
        }
        if (str_contains($tag, 'data-class=')) {
            assert_contains('data-class="meta"', $out);
            assert_contains('margin-top:var(--wp--preset--spacing--md)', $out);
        }
        assert_eq($out, SectionReadabilityContract::enforce($out, readability_input(), 'page-menu--food', $repairs, $warnings));
    }
});

test('section heading repair retains a quoted greater-than sign in the wrapper', function () {
    $tag = '<div data-note="1 > 0" class="wp-block-group">';
    $raw = '<!-- wp:group -->' . $tag . '<!-- wp:heading {"level":3} --><h3>Khinkali</h3><!-- /wp:heading --></div><!-- /wp:group -->';
    $repairs = $warnings = [];
    $out = SectionReadabilityContract::enforce($raw, readability_input(), 'page-menu--food', $repairs, $warnings);
    assert_contains($tag . '<!-- wp:heading -->', $out);
    assert_contains('<h2 class="wp-block-heading">Our dishes</h2>', $out);
});
