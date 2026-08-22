<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Serializer;
use Automattic\SiteBuild\LayoutFixer;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Steps\FixBlocksStep;
use Automattic\SiteBuild\Units\GeneratedMarkup;

/** @return array{markup:string,repairs:list<array<string,mixed>>,warnings:list<string>} */
function gms_strip(string $markup, string $part = 'page-home--hero'): array
{
    $repairs = [];
    $warnings = [];
    return [
        'markup' => GeneratedMarkup::stripTextBlockShadow($markup, $part, $repairs, $warnings),
        'repairs' => $repairs,
        'warnings' => $warnings,
    ];
}

test('text shadow stripping deep-merges duplicate style keys in either order and survives downstream passes', function () {
    $styleOrders = [
        'shadow-first' => '"style":{"shadow":"var:preset|shadow|misregister","border":{}},'
            . '"style":{"typography":{"letterSpacing":"0.03em"}}',
        'shadow-last' => '"style":{"typography":{"letterSpacing":"0.03em"}},'
            . '"style":{"shadow":"var:preset|shadow|misregister","border":{}}',
    ];

    foreach ($styleOrders as $label => $styles) {
        $markup = '<!-- wp:heading {"level":2,' . $styles . '} -->'
            . '<h2 class="wp-block-heading" style="letter-spacing:0.03em;box-shadow:var(--wp--preset--shadow--misregister)">'
            . 'Shadow-free heading</h2><!-- /wp:heading -->';

        $result = gms_strip($markup, "{$label}--hero");
        $out = $result['markup'];

        assert_eq(1, substr_count($out, '"style":'), "{$label}: duplicate style keys were canonicalized");
        assert_contains('"typography":{"letterSpacing":"0.03em"}', $out, "{$label}: typography survived");
        assert_contains('"border":{}', $out, "{$label}: an empty JSON object retained object shape");
        assert_contains('letter-spacing:0.03em', $out, "{$label}: unrelated inline styling survived");
        assert_true(!str_contains($out, 'box-shadow'), "{$label}: visible inline shadow was removed immediately");
        assert_true(!str_contains($out, '"shadow"'), "{$label}: comment shadow was removed");
        assert_eq(
            ['duplicate-block-attribute-keys-merged', 'text-block-shadow-stripped'],
            array_column($result['repairs'], 'code'),
        );
        assert_eq(['style'], $result['repairs'][0]['paths']);
        assert_eq('wp:heading[0]', $result['repairs'][0]['block']);
        assert_eq(2, count($result['warnings']), "{$label}: attribute and inline removals are both durable");
        foreach ([
            "file='theme/parts/{$label}--hero.html'",
            "block='wp:heading[0]'",
            'delivered=removed',
            'disposition=',
        ] as $context) {
            assert_contains($context, implode("\n", $result['warnings']), "{$label}: actionable warning context");
        }
        assert_contains('authored style.shadow="var:preset|shadow|misregister"', $result['warnings'][0]);
        assert_contains(
            'authored saved HTML style.box-shadow="var(--wp--preset--shadow--misregister)"',
            $result['warnings'][1],
        );

        // Regression for the original bypass: LayoutFixer used to merge the
        // duplicates after intake and restore the shadow that last-key-wins
        // decoding had missed. Neither it nor serialization may do so now.
        $layout = LayoutFixer::fix($out, LayoutFixer::ROLE_SECTION, 860.0)['markup'];
        $serialized = (new Serializer())->transform($layout)->html;
        assert_true(!str_contains($layout, 'shadow'), "{$label}: LayoutFixer did not resurrect shadow state");
        assert_true(!str_contains($serialized, 'shadow'), "{$label}: serialization stayed shadow-free");
        assert_contains('letter-spacing:0.03em', $serialized, "{$label}: serialization retained typography");

        $again = gms_strip($out, "{$label}--hero");
        assert_eq($out, $again['markup'], "{$label}: fixed point");
        assert_eq([], $again['repairs']);
        assert_eq([], $again['warnings']);
    }
});

test('inline-only shadows are removed from the complete copy domain including explicit core names', function () {
    $cases = [
        'list-item' => ['li', 'list item'],
        'verse' => ['pre', 'verse'],
        'site-title' => ['p', 'site title'],
        'site-tagline' => ['p', 'site tagline'],
        'post-title' => ['h2', 'post title'],
        'core/paragraph' => ['p', 'explicit core paragraph'],
    ];

    foreach ($cases as $name => [$tag, $label]) {
        $markup = '<!-- wp:' . $name . ' -->'
            . '<' . $tag . ' style="color:green;box-shadow:' . $label . '-box;text-shadow:' . $label . '-text">'
            . $label . '</' . $tag . '><!-- /wp:' . $name . ' -->';
        $result = gms_strip($markup, 'inline-only');

        assert_true(!str_contains($result['markup'], 'box-shadow'), "{$name}: inline-only box shadow removed");
        assert_true(!str_contains($result['markup'], 'text-shadow'), "{$name}: inline-only text shadow removed");
        assert_contains('color:green', $result['markup'], "{$name}: non-shadow inline style retained");
        assert_eq(2, count($result['warnings']), "{$name}: every inline declaration was warned");
        assert_contains("block='wp:{$name}[0]'", implode("\n", $result['warnings']));
        assert_contains('authored saved HTML style.box-shadow=', $result['warnings'][0]);
        assert_contains('authored saved HTML style.text-shadow=', $result['warnings'][1]);
        assert_eq(['text-block-shadow-stripped'], array_column($result['repairs'], 'code'));
    }
});

test('clearly inert shadow declarations remain byte-identical and produce no warning noise', function () {
    $markup = '<!-- wp:paragraph {"style":{"shadow":" none ","typography":{"textShadow":"INITIAL"}}} -->'
        . '<p style="color:green;box-shadow:none !important;text-shadow:/* reset */ unset">Quiet copy</p>'
        . '<!-- /wp:paragraph -->';

    $result = gms_strip($markup, 'inert-shadow');

    assert_eq($markup, $result['markup']);
    assert_eq([], $result['repairs']);
    assert_eq([], $result['warnings']);
});

test('text shadow stripping covers every generated copy block and both executable style paths', function () {
    $markup = '<!-- wp:heading {"style":{"shadow":"heading-box"}} -->'
        . '<h2 style="color:red;box-shadow:heading-box">Heading</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph {"style":{"typography":{"fontWeight":"700","textShadow":"paragraph-text"}}} -->'
        . '<p style="font-weight:700;text-shadow:paragraph-text">Paragraph</p><!-- /wp:paragraph -->'
        . '<!-- wp:quote {"style":{"shadow":"quote-box","typography":{"textShadow":"quote-text"}}} -->'
        . '<blockquote class="wp-block-quote" style="box-shadow:quote-box;text-shadow:quote-text">'
        . '<p>Quote</p></blockquote><!-- /wp:quote -->'
        . '<!-- wp:pullquote {"value":"Pull quote","style":{"shadow":"pullquote-box"}} -->'
        . '<figure class="wp-block-pullquote" style="box-shadow:pullquote-box"><blockquote><p>Pull quote</p></blockquote></figure>'
        . '<!-- /wp:pullquote -->'
        . '<!-- wp:list {"style":{"typography":{"textShadow":"list-text"}}} -->'
        . '<ul style="text-shadow:list-text"><li>List item</li></ul><!-- /wp:list -->'
        . '<!-- wp:image {"url":"/photo.jpg","style":{"shadow":"media-box","typography":{"textShadow":"media-text"}}} -->'
        . '<figure class="wp-block-image" style="box-shadow:media-box;text-shadow:media-text">'
        . '<img src="/photo.jpg" alt=""/></figure><!-- /wp:image -->';

    $result = gms_strip($markup, 'page-home--section-2');
    $out = $result['markup'];

    foreach (['heading-box', 'paragraph-text', 'quote-box', 'quote-text', 'pullquote-box', 'list-text'] as $removed) {
        assert_true(!str_contains($out, $removed), "copy shadow {$removed} was removed from comment and HTML");
    }
    assert_contains('"fontWeight":"700"', $out, 'a typography sibling survived textShadow removal');
    assert_contains('font-weight:700', $out, 'an inline sibling survived text-shadow removal');
    assert_contains('color:red', $out, 'a box-shadow sibling survived inline filtering');
    assert_contains('"shadow":"media-box"', $out, 'media box-shadow remained available');
    assert_contains('"textShadow":"media-text"', $out, 'media textShadow remained outside the copy rule');
    assert_contains('box-shadow:media-box;text-shadow:media-text', $out, 'media inline shadows remained');

    // Six comment declarations and their six inline mirrors were removed.
    assert_eq(12, count($result['warnings']));
    $warnings = implode("\n", $result['warnings']);
    foreach (['heading[0]', 'paragraph[1]', 'quote[2]', 'pullquote[3]', 'list[4]'] as $path) {
        assert_contains("block='wp:{$path}'", $warnings);
    }
    foreach (['style.shadow', 'style.typography.textShadow', 'saved HTML style.box-shadow', 'saved HTML style.text-shadow'] as $path) {
        assert_contains("authored {$path}=", $warnings);
    }
});

test('normalize routes shadow removals to actionable warnings and synchronizes malformed inline style atomically', function () {
    $markup = '<!-- wp:paragraph {"style":{"shadow":"var:preset|shadow|offset"}} -->'
        . '<p style="color:blue;box-shadow:var(--wp--preset--shadow--offset);transform:translateX(calc(1px)">'
        . 'Usable copy</p><!-- /wp:paragraph -->';
    $warnings = [];
    $repairs = [];

    $out = GeneratedMarkup::normalize($markup, 'page-home--section-3', $warnings, $repairs);

    assert_contains('Usable copy', $out);
    assert_true(!str_contains($out, '"shadow"'), 'the comment mutation completed');
    assert_true(!str_contains($out, 'style='), 'the inseparable malformed inline shadow attribute was removed too');
    assert_eq(2, count($warnings), 'the comment declaration and malformed inline attribute are each actionable');
    $joined = implode("\n", $warnings);
    foreach ([
        "file='theme/parts/page-home--section-3.html'",
        "block='wp:paragraph[0]'",
        'authored style.shadow="var:preset|shadow|offset"',
        'authored saved HTML style="color:blue;box-shadow:var(--wp--preset--shadow--offset);transform:translateX(calc(1px)"',
        'delivered=removed',
        'disposition=',
    ] as $context) {
        assert_contains($context, $joined);
    }
    assert_eq(['text-block-shadow-stripped'], array_column($repairs, 'code'));
});

test('a block-local shadow isolation failure retains that block bytes and does not stop a healthy sibling', function () {
    $broken = '<!-- wp:heading {"style":{"shadow":"broken-box"}} -->'
        . 'unwrapped box-shadow:broken-box'
        . '<!-- /wp:heading -->';
    $healthy = '<!-- wp:paragraph {"style":{"shadow":"healthy-box"}} -->'
        . '<p style="box-shadow:healthy-box">Healthy sibling</p>'
        . '<!-- /wp:paragraph -->';

    $result = gms_strip($broken . $healthy, 'isolated-failure');

    assert_contains($broken, $result['markup'], 'the failed unit retained its exact pre-transformation bytes');
    assert_contains('Healthy sibling', $result['markup']);
    assert_true(!str_contains($result['markup'], '"shadow":"healthy-box"'), 'the sibling comment was repaired');
    assert_true(!str_contains($result['markup'], 'style="box-shadow:healthy-box"'), 'the sibling HTML was repaired');
    assert_eq(3, count($result['warnings']));
    assert_contains("block='wp:heading[0]'", $result['warnings'][0]);
    assert_contains('delivered="original block bytes"', $result['warnings'][0]);
    assert_contains('without partial edits', $result['warnings'][0]);
    assert_contains("block='wp:paragraph[1]'", implode("\n", array_slice($result['warnings'], 1)));
});

test('a later fix-blocks file rollback cannot resurrect an inline text-block shadow', function () {
    $raw = '<!-- wp:group {"align":"full"} -->'
        . '<div class="wp-block-group alignfull">'
        . '<!-- wp:paragraph {"style":{"shadow":"var:preset|shadow|misregister"}} -->'
        . '<p style="color:green;box-shadow:var(--wp--preset--shadow--misregister)">Retained copy</p>'
        . '<!-- /wp:paragraph -->'
        // A root text-alignment conflict still rolls back the WHOLE file (an
        // unsupported block is now isolated to itself), so it is what forces
        // the file-level rollback this test is about.
        . '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center" '
        . 'style="text-align:right">Conflicting sibling</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    $warnings = [];
    $repairs = [];
    $normalized = GeneratedMarkup::normalize($raw, 'rollback-proof', $warnings, $repairs);

    assert_true(!str_contains($normalized, 'shadow'), 'intake synchronized comment and inline shadow removal');
    assert_contains('color:green', $normalized, 'unrelated inline styling survived intake');
    assert_contains('Retained copy', $normalized);

    with_project('builder_shadow_rollback_', function ($project) use ($normalized): void {
        $project->writeText('theme/parts/rollback-proof.html', $normalized);
        quietly(fn () => (new FixBlocksStep(new PhpBlockFixer()))->run($project));

        assert_eq(
            $normalized,
            $project->readText('theme/parts/rollback-proof.html'),
            'the conflicting sibling restored exactly the already-safe step-entry bytes',
        );
        $fixWarnings = implode("\n", $project->readJson('warnings.json')['fix-blocks'] ?? []);
        assert_contains('parts/rollback-proof.html', $fixWarnings);
        assert_contains('has-text-align-center', $fixWarnings);
        assert_contains('pre-step markup delivered byte-for-byte', $fixWarnings);
    });
});
