<?php
declare(strict_types=1);

use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Units\LegacyAttributes;

/**
 * Canonical-vs-lean equivalence matrix: for every block type the section
 * prompt allows, attribute-light markup (bare wrappers, comment JSON only)
 * must serialize to the same bytes as verbose markup carrying the generated
 * classes — with nothing dropped, nothing preserved-as-failed, and a stable
 * fixed point. This is the machine check behind the lean output contract.
 */

/** @return array{0:string,1:string} fixed [verbose, lean] for one pair */
function cle_fix_pair(string $verbose, string $lean, string $name): array
{
    $tmp = sys_get_temp_dir() . '/builder_equiv_' . preg_replace('/[^a-z-]/', '', $name) . '_' . uniqid();
    $theme = $tmp . '/theme';
    mkdir($theme . '/parts', 0777, true);
    file_put_contents($theme . '/parts/verbose.html', $verbose);
    file_put_contents($theme . '/parts/lean.html', $lean);
    try {
        $report = (new PhpBlockFixer())->fix($theme);
        assert_true(!str_contains($report, 'FAILED'), "{$name}: neither variant fails");
        assert_true(!str_contains($report, 'REPAIR preserved'), "{$name}: neither variant needs preservation");
        assert_contains('0 style/class value(s) dropped', $report, "{$name}: nothing dropped");

        $fixedVerbose = (string) file_get_contents($theme . '/parts/verbose.html');
        $fixedLean = (string) file_get_contents($theme . '/parts/lean.html');

        // Fixed point: a second full run must change nothing.
        $second = (new PhpBlockFixer())->fix($theme);
        assert_contains('0/2 file(s) re-serialized', $second, "{$name}: the serialized output is a fixed point");

        return [$fixedVerbose, $fixedLean];
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

test('every allowed block type serializes lean and verbose variants to identical bytes', function () {
    $img = '<img src="theme:./assets/photo.jpg" alt="AI_IMAGE: Studio at dusk | card | photorealistic | landscape"/>';

    // [comment-JSON document pairs] — verbose carries the generated classes
    // the lean contract omits; both share identical comment JSON.
    $cases = [
        'group-heading-paragraph' => [
            "<!-- wp:group {\"className\":\"story\",\"backgroundColor\":\"contrast\",\"layout\":{\"type\":\"constrained\"}} -->\n"
            . "<div class=\"wp-block-group story has-contrast-background-color has-background\">\n"
            . "<!-- wp:heading {\"level\":2,\"textColor\":\"accent\",\"fontSize\":\"large\"} -->\n"
            . "<h2 class=\"wp-block-heading has-accent-color has-text-color has-large-font-size\">Practice</h2>\n"
            . "<!-- /wp:heading -->\n"
            . "<!-- wp:paragraph {\"className\":\"lede\"} -->\n"
            . "<p class=\"lede\">See <a href=\"/work/\">work</a>.</p>\n"
            . "<!-- /wp:paragraph -->\n"
            . "</div>\n"
            . '<!-- /wp:group -->',
            "<!-- wp:group {\"className\":\"story\",\"backgroundColor\":\"contrast\",\"layout\":{\"type\":\"constrained\"}} -->\n"
            . "<div class=\"story\">\n"
            . "<!-- wp:heading {\"level\":2,\"textColor\":\"accent\",\"fontSize\":\"large\"} -->\n"
            . "<h2>Practice</h2>\n"
            . "<!-- /wp:heading -->\n"
            . "<!-- wp:paragraph {\"className\":\"lede\"} -->\n"
            . "<p class=\"lede\">See <a href=\"/work/\">work</a>.</p>\n"
            . "<!-- /wp:paragraph -->\n"
            . "</div>\n"
            . '<!-- /wp:group -->',
        ],
        'columns-column' => [
            "<!-- wp:columns -->\n"
            . "<div class=\"wp-block-columns\">\n"
            . "<!-- wp:column -->\n<div class=\"wp-block-column\">\n"
            . "<!-- wp:paragraph -->\n<p>Left</p>\n<!-- /wp:paragraph -->\n</div>\n<!-- /wp:column -->\n"
            . "<!-- wp:column -->\n<div class=\"wp-block-column\">\n"
            . "<!-- wp:paragraph -->\n<p>Right</p>\n<!-- /wp:paragraph -->\n</div>\n<!-- /wp:column -->\n"
            . "</div>\n<!-- /wp:columns -->",
            "<!-- wp:columns -->\n"
            . "<div>\n"
            . "<!-- wp:column -->\n<div>\n"
            . "<!-- wp:paragraph -->\n<p>Left</p>\n<!-- /wp:paragraph -->\n</div>\n<!-- /wp:column -->\n"
            . "<!-- wp:column -->\n<div>\n"
            . "<!-- wp:paragraph -->\n<p>Right</p>\n<!-- /wp:paragraph -->\n</div>\n<!-- /wp:column -->\n"
            . "</div>\n<!-- /wp:columns -->",
        ],
        'buttons-button' => [
            "<!-- wp:buttons {\"layout\":{\"type\":\"flex\",\"justifyContent\":\"center\"}} -->\n"
            . "<div class=\"wp-block-buttons\">\n"
            . "<!-- wp:button {\"backgroundColor\":\"accent\",\"textColor\":\"base\"} -->\n"
            . "<div class=\"wp-block-button\"><a class=\"wp-block-button__link has-base-color has-accent-background-color has-text-color has-background wp-element-button\" href=\"/contact/\">Talk</a></div>\n"
            . "<!-- /wp:button -->\n"
            . "</div>\n<!-- /wp:buttons -->",
            "<!-- wp:buttons {\"layout\":{\"type\":\"flex\",\"justifyContent\":\"center\"}} -->\n"
            . "<div>\n"
            . "<!-- wp:button {\"backgroundColor\":\"accent\",\"textColor\":\"base\"} -->\n"
            . "<div><a href=\"/contact/\">Talk</a></div>\n"
            . "<!-- /wp:button -->\n"
            . "</div>\n<!-- /wp:buttons -->",
        ],
        'image' => [
            "<!-- wp:image {\"sizeSlug\":\"large\",\"className\":\"card-media\"} -->\n"
            . "<figure class=\"wp-block-image size-large card-media\">{$img}</figure>\n"
            . '<!-- /wp:image -->',
            "<!-- wp:image {\"sizeSlug\":\"large\",\"className\":\"card-media\"} -->\n"
            . "<figure class=\"card-media\">{$img}</figure>\n"
            . '<!-- /wp:image -->',
        ],
        'cover' => [
            "<!-- wp:cover {\"url\":\"theme:./assets/hero.jpg\",\"dimRatio\":50,\"minHeight\":80,\"minHeightUnit\":\"vh\"} -->\n"
            . "<div class=\"wp-block-cover\">"
            . "<img class=\"wp-block-cover__image-background\" alt=\"AI_IMAGE: Dawn ridge | hero | photorealistic | landscape\" src=\"theme:./assets/hero.jpg\" data-object-fit=\"cover\"/>"
            . "<span aria-hidden=\"true\" class=\"wp-block-cover__background has-background-dim\"></span>"
            . "<div class=\"wp-block-cover__inner-container\">\n"
            . "<!-- wp:heading {\"level\":1,\"textColor\":\"base\"} -->\n<h1 class=\"wp-block-heading has-base-color has-text-color\">High Country</h1>\n<!-- /wp:heading -->\n"
            . "</div></div>\n<!-- /wp:cover -->",
            "<!-- wp:cover {\"url\":\"theme:./assets/hero.jpg\",\"dimRatio\":50,\"minHeight\":80,\"minHeightUnit\":\"vh\"} -->\n"
            . "<div>"
            . "<img alt=\"AI_IMAGE: Dawn ridge | hero | photorealistic | landscape\" src=\"theme:./assets/hero.jpg\"/>"
            . "<div>\n"
            . "<!-- wp:heading {\"level\":1,\"textColor\":\"base\"} -->\n<h1>High Country</h1>\n<!-- /wp:heading -->\n"
            . "</div></div>\n<!-- /wp:cover -->",
        ],
        'gallery-nested-images' => [
            "<!-- wp:gallery {\"columns\":2,\"linkTo\":\"none\"} -->\n"
            . "<figure class=\"wp-block-gallery has-nested-images columns-2 is-cropped\">\n"
            . "<!-- wp:image {\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\">{$img}</figure>\n<!-- /wp:image -->\n"
            . "<!-- wp:image {\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\">{$img}</figure>\n<!-- /wp:image -->\n"
            . "</figure>\n<!-- /wp:gallery -->",
            "<!-- wp:gallery {\"columns\":2,\"linkTo\":\"none\"} -->\n"
            . "<figure>\n"
            . "<!-- wp:image {\"sizeSlug\":\"large\"} -->\n<figure>{$img}</figure>\n<!-- /wp:image -->\n"
            . "<!-- wp:image {\"sizeSlug\":\"large\"} -->\n<figure>{$img}</figure>\n<!-- /wp:image -->\n"
            . "</figure>\n<!-- /wp:gallery -->",
        ],
        'media-text' => [
            "<!-- wp:media-text {\"mediaType\":\"image\"} -->\n"
            . "<div class=\"wp-block-media-text is-stacked-on-mobile\">"
            . "<figure class=\"wp-block-media-text__media\">{$img}</figure>"
            . "<div class=\"wp-block-media-text__content\">\n"
            . "<!-- wp:paragraph -->\n<p>Beside the image.</p>\n<!-- /wp:paragraph -->\n"
            . "</div></div>\n<!-- /wp:media-text -->",
            "<!-- wp:media-text {\"mediaType\":\"image\"} -->\n"
            . "<div>"
            . "<figure>{$img}</figure>"
            . "<div>\n"
            . "<!-- wp:paragraph -->\n<p>Beside the image.</p>\n<!-- /wp:paragraph -->\n"
            . "</div></div>\n<!-- /wp:media-text -->",
        ],
        'quote' => [
            "<!-- wp:quote -->\n"
            . "<blockquote class=\"wp-block-quote\">\n"
            . "<!-- wp:paragraph -->\n<p>Make it simple.</p>\n<!-- /wp:paragraph -->\n"
            . "<cite>A. Designer</cite></blockquote>\n<!-- /wp:quote -->",
            "<!-- wp:quote -->\n"
            . "<blockquote>\n"
            . "<!-- wp:paragraph -->\n<p>Make it simple.</p>\n<!-- /wp:paragraph -->\n"
            . "<cite>A. Designer</cite></blockquote>\n<!-- /wp:quote -->",
        ],
        'pullquote' => [
            "<!-- wp:pullquote -->\n"
            . "<figure class=\"wp-block-pullquote\"><blockquote><p>Less, better.</p><cite>D. Rams</cite></blockquote></figure>\n"
            . '<!-- /wp:pullquote -->',
            "<!-- wp:pullquote -->\n"
            . "<figure><blockquote><p>Less, better.</p><cite>D. Rams</cite></blockquote></figure>\n"
            . '<!-- /wp:pullquote -->',
        ],
        'list-items' => [
            "<!-- wp:list -->\n"
            . "<ul class=\"wp-block-list\">\n"
            . "<!-- wp:list-item -->\n<li>One</li>\n<!-- /wp:list-item -->\n"
            . "<!-- wp:list-item -->\n<li>Two</li>\n<!-- /wp:list-item -->\n"
            . "</ul>\n<!-- /wp:list -->",
            "<!-- wp:list -->\n"
            . "<ul>\n"
            . "<!-- wp:list-item -->\n<li>One</li>\n<!-- /wp:list-item -->\n"
            . "<!-- wp:list-item -->\n<li>Two</li>\n<!-- /wp:list-item -->\n"
            . "</ul>\n<!-- /wp:list -->",
        ],
        'separator' => [
            "<!-- wp:separator -->\n"
            . "<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n"
            . '<!-- /wp:separator -->',
            "<!-- wp:separator -->\n<hr/>\n<!-- /wp:separator -->",
        ],
        'spacer' => [
            "<!-- wp:spacer {\"height\":\"40px\"} -->\n"
            . "<div style=\"height:40px\" aria-hidden=\"true\" class=\"wp-block-spacer\"></div>\n"
            . '<!-- /wp:spacer -->',
            "<!-- wp:spacer {\"height\":\"40px\"} -->\n<div style=\"height:40px\"></div>\n<!-- /wp:spacer -->",
        ],
    ];

    foreach ($cases as $name => [$verbose, $lean]) {
        [$fixedVerbose, $fixedLean] = cle_fix_pair($verbose, $lean, $name);
        assert_eq($fixedVerbose, $fixedLean, "{$name}: lean and verbose converge byte-for-byte");
    }
});

test('legacy generated attributes converge with current-schema markup after intake conversion', function () {
    // The model may still emit the legacy shape; the intake conversion plus
    // the serializer must land on the same bytes as current-schema input.
    $legacy = '<!-- wp:heading {"level":2,"textAlign":"center","textColor":"contrast"} -->'
        . "\n<h2>Centered</h2>\n<!-- /wp:heading -->";
    $current = '<!-- wp:heading {"level":2,"style":{"typography":{"textAlign":"center"}},"textColor":"contrast"} -->'
        . "\n<h2>Centered</h2>\n<!-- /wp:heading -->";

    $converted = LegacyAttributes::normalize($legacy);
    assert_eq(1, count($converted['conversions']));

    [$fixedCurrent, $fixedConverted] = cle_fix_pair($current, $converted['markup'], 'legacy-textalign');
    assert_eq($fixedCurrent, $fixedConverted, 'the converted legacy shape serializes identically to current schema');
    // The pinned save derives the alignment class from the style attribute,
    // so the centering intent survives with no authored class at all.
    assert_contains('has-text-align-center', $fixedConverted, 'the centering intent survives without any saved class');
});

test('an unsupported sibling leaves every other block fully serialized', function () {
    // Matias's failure case: one unsupported block must not leave the whole
    // section classless — siblings keep their generated classes while the
    // smallest failing unit is preserved with one report row.
    $tmp = sys_get_temp_dir() . '/builder_equiv_mixed_' . uniqid();
    $theme = $tmp . '/theme';
    mkdir($theme . '/parts', 0777, true);
    $section = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n"
        . "<div>\n"
        . "<!-- wp:heading {\"level\":2,\"textColor\":\"accent\"} -->\n<h2>Kept</h2>\n<!-- /wp:heading -->\n"
        . "<!-- wp:query --><div class=\"wp-block-query\"></div><!-- /wp:query -->\n"
        . "<!-- wp:paragraph -->\n<p>Also kept.</p>\n<!-- /wp:paragraph -->\n"
        . "</div>\n<!-- /wp:group -->";
    file_put_contents($theme . '/parts/section.html', $section);

    try {
        $report = (new PhpBlockFixer())->fix($theme);
        $fixed = (string) file_get_contents($theme . '/parts/section.html');

        assert_true(!str_contains($report, 'FAILED'), 'the file is not failed wholesale');
        assert_eq(1, substr_count($report, 'REPAIR preserved'), 'exactly one preserved row: the smallest failing unit');
        assert_contains('preserved core/query', $report);
        assert_contains('class="wp-block-group"', $fixed, 'the parent group keeps its generated class');
        assert_contains('class="wp-block-heading has-accent-color has-text-color"', $fixed, 'the heading sibling keeps its classes');
        assert_contains('<div class="wp-block-query"></div>', $fixed, 'the unsupported block keeps its authored bytes');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

/**
 * Strip the class tokens the serializer regenerates from comment JSON, which
 * is what the ATTRIBUTE-LIGHT BLOCK SAVE MARKUP contract tells the model to
 * omit. Custom and source-critical tokens (gallery item/caption hooks, author
 * classes) are not serializer-derived, so they are kept — exactly what the
 * contract requires. Inline styles are left alone here; the hand-written
 * matrix above is what covers omitting those.
 */
function cle_leanify(string $html): string
{
    return preg_replace_callback(
        '/\sclass="([^"]*)"/',
        static function (array $match): string {
            $kept = array_values(array_filter(
                preg_split('/\s+/', trim($match[1])) ?: [],
                static fn (string $token): bool => $token !== ''
                    && !str_starts_with($token, 'wp-block-')
                    && !str_starts_with($token, 'has-')
                    && !str_starts_with($token, 'align')
                    && !str_starts_with($token, 'is-')
                    && !str_starts_with($token, 'size-'),
            ));
            return $kept === [] ? '' : ' class="' . implode(' ', $kept) . '"';
        },
        $html
    ) ?? $html;
}

/**
 * Oracle cases whose lean variant does NOT reach the runtime-certified output,
 * with the reason. Listed cases are asserted to STILL diverge, so a fix cannot
 * land without removing its entry and the list cannot quietly rot into a claim
 * of coverage it no longer has.
 */
const CLE_KNOWN_LEAN_DIVERGENCES = [
    'generated-demo-layout-and-paragraph-signatures' =>
        'paragraph roots carrying an authored inline style not derivable from comment JSON',
    'paragraph-conflicting-text-align' =>
        'reviewed degradation policy: the authored conflict is delivered verbatim by design',
    'paragraph-inline-color-carryover' =>
        'omitting the preset font-size class routes the block to a deprecation candidate that does '
        . 'not carry authored bytes, so it ships preserved-verbatim without its generated classes',
    'paragraph-opacity-reviewed-drop' =>
        'reviewed opacity-removal policy, which Gutenberg reserialization does not implement',
    'site-tagline-legacy-text-align' =>
        'intake legacy conversion folds textAlign into an existing style.typography, where the '
        . 'certified runtime migration drops it',
    'tbilisi25-footer-fixed-point' =>
        'observed real-build fixed point whose lean variant re-routes at least one block',
    'tbilisi60-traditional-offerings-fixed-point' =>
        'documented cosmetic attribute-order divergence held outside the generator',
];

test('a lean variant of every oracle case reaches the runtime-certified output', function () {
    // The oracle certifies the PHP fixer against the real Gutenberg runtime
    // using CANONICAL inputs. This walks the same corpus from the other end:
    // feed the attribute-light variant through the real pipeline (intake
    // legacy conversion, then the fixer) and require the runtime-certified
    // bytes back. Together they close the chain the review asked for — lean
    // equals canonical, canonical equals the runtime, therefore lean equals
    // the runtime — over one shared corpus instead of two unrelated ones.
    $root = repo_path('tests/fixtures/block-fixer/cases');
    $cases = array_values(array_filter(
        scandir($root) ?: [],
        static fn (string $entry): bool => $entry[0] !== '.' && is_dir($root . '/' . $entry),
    ));
    assert_true(count($cases) > 0, 'the oracle corpus is readable');

    $matched = 0;
    $diverged = [];
    foreach ($cases as $case) {
        foreach (glob("{$root}/{$case}/input/parts/*.html") ?: [] as $input) {
            $name = basename($input);
            $expectedPath = "{$root}/{$case}/expected/parts/{$name}";
            if (!file_exists($expectedPath)) {
                continue;
            }
            $lean = cle_leanify((string) file_get_contents($input));
            $converted = LegacyAttributes::normalize($lean)['markup'];

            $tmp = sys_get_temp_dir() . '/builder_oracle_lean_' . uniqid();
            mkdir($tmp . '/theme/parts', 0777, true);
            file_put_contents($tmp . '/theme/parts/' . $name, $converted);
            try {
                (new PhpBlockFixer())->fix($tmp . '/theme');
                $got = (string) file_get_contents($tmp . '/theme/parts/' . $name);
            } finally {
                exec('rm -rf ' . escapeshellarg($tmp));
            }

            if (trim($got) === trim((string) file_get_contents($expectedPath))) {
                $matched++;
            } else {
                $diverged[$case] = true;
            }
        }
    }

    // Every divergence is accounted for, and every accounted-for divergence
    // still diverges. Neither list can drift without failing here.
    foreach (array_keys($diverged) as $case) {
        assert_true(
            isset(CLE_KNOWN_LEAN_DIVERGENCES[$case]),
            "{$case}: lean variant diverges from the runtime-certified output with no recorded reason",
        );
    }
    foreach (CLE_KNOWN_LEAN_DIVERGENCES as $case => $why) {
        assert_true(
            in_array($case, $cases, true),
            "{$case}: recorded as a lean divergence but no longer in the oracle corpus",
        );
        assert_true(
            isset($diverged[$case]),
            "{$case}: recorded as a lean divergence ({$why}) but now converges — remove the entry",
        );
    }
    assert_eq(51, $matched, 'lean variants reaching the runtime-certified output');
});
