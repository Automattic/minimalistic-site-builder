<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageCaptions;
use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Steps\FixBlocksStep;

/**
 * The pipeline fixed point (BIGR-867). The block fixer re-serializes after
 * this pass, and core/image round-trips its caption between the <figcaption>
 * element and the `caption` attribute. Removing only one channel lets the
 * fixer put the caption straight back — the defect BIGR-861 shipped twice.
 * This asserts the caption is gone AFTER the fixer has run, not merely after
 * the pass.
 */

function ic_fixture_project(string $tmp, string $rel, string $markup): Project
{
    $project = new Project($tmp);
    $project->writeJson('designDirection.json', ['shape' => 'soft']);
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeText('theme/' . $rel, $markup);
    return $project;
}

test('a caption removed before the block fixer does not come back after it', function () {
    with_temp_dir('image-captions-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/hero.html',
            '<!-- wp:image {"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large">'
            . '<img src="/x.jpg" alt="A yard"/>'
            . '<figcaption class="wp-element-caption">Brunswick, 2 days.</figcaption>'
            . '</figure><!-- /wp:image -->'
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $delivered = $project->readText('theme/parts/hero.html');
        assert_true(!str_contains($delivered, '<figcaption'), 'no figcaption survives the fixer');
        assert_true(!str_contains($delivered, 'Brunswick'), 'no caption text survives the fixer');
        assert_contains('<img', $delivered, 'the image itself survives');
    });
});

test('a gallery caption survives the whole step', function () {
    with_temp_dir('image-captions-gallery-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/work.html',
            '<!-- wp:gallery {"columns":2} --><figure class="wp-block-gallery">'
            . '<!-- wp:image {"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large">'
            . '<img src="/y.jpg" alt="Plate"/>'
            . '<figcaption class="wp-element-caption">Plate 4, 1972.</figcaption>'
            . '</figure><!-- /wp:image -->'
            . '</figure><!-- /wp:gallery -->'
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $delivered = $project->readText('theme/parts/work.html');
        assert_contains('Plate 4, 1972.', $delivered, 'gallery caption survives');
    });
});

test('a removed caption is recorded in warnings.json', function () {
    with_temp_dir('image-captions-warn-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/hero.html',
            '<!-- wp:image {"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large">'
            . '<img src="/x.jpg" alt="A yard"/>'
            . '<figcaption class="wp-element-caption">Footscray, 5 days.</figcaption>'
            . '</figure><!-- /wp:image -->'
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $warnings = $project->readText('warnings.json');
        assert_contains('Footscray, 5 days.', $warnings, 'the removed text is durable, not log-only');
        assert_contains('parts/hero.html', $warnings, 'the row names the file');
    });
});

test('source-backed image attributes do not detach removal evidence after serialization', function () {
    with_temp_dir('image-captions-sourced-attrs-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/hero.html',
            '<!-- wp:image {"id":123,"url":"/x.jpg","alt":"A yard","sizeSlug":"large",'
            . '"caption":"Sourced caption."} -->'
            . '<figure class="wp-block-image size-large"><img src="/x.jpg" alt="A yard"/>'
            . '<figcaption class="wp-element-caption">Sourced caption.</figcaption></figure>'
            . '<!-- /wp:image -->'
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $delivered = $project->readText('theme/parts/hero.html');
        assert_contains('src="/x.jpg"', $delivered, 'the sourced image URL survives');
        assert_contains('alt="A yard"', $delivered, 'the sourced alt text survives');
        assert_contains('wp-image-123', $delivered, 'the renderer-derived id class is represented');
        assert_true(!str_contains($delivered, 'Sourced caption.'), 'the caption is removed');
        $warnings = array_values(array_filter(
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            static fn (string $warning): bool => str_contains($warning, 'Sourced caption.'),
        ));
        assert_eq(1, count($warnings), 'removal evidence survives sourced-attribute normalization');
        assert_contains('delivered removed', $warnings[0]);
    });
});

test('shape normalization does not detach caption removal evidence', function () {
    with_temp_dir('image-captions-shape-identity-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/hero.html',
            '<!-- wp:image {"url":"/shape.jpg","alt":"Shape","sizeSlug":"large",'
            . '"style":{"border":{"radius":"12px"}},"caption":"Shape caption."} -->'
            . '<figure class="wp-block-image size-large has-custom-border">'
            . '<img src="/shape.jpg" alt="Shape" style="border-radius:12px"/>'
            . '<figcaption class="wp-element-caption">Shape caption.</figcaption></figure>'
            . '<!-- /wp:image -->'
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $delivered = $project->readText('theme/parts/hero.html');
        assert_true(!str_contains($delivered, 'Shape caption.'), 'the caption is removed');
        $warnings = array_values(array_filter(
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            static fn (string $warning): bool => str_contains($warning, 'Shape caption.'),
        ));
        assert_eq(1, count($warnings), 'shape changes do not detach authored evidence');
        assert_contains('delivered removed', $warnings[0]);
    });
});

test('an image inside caption markup does not change the parent image identity', function () {
    with_temp_dir('image-captions-inline-image-', function (string $tmp): void {
        $caption = 'Caption <img src="/badge.png" alt="Badge"/> text.';
        $project = ic_fixture_project($tmp, 'parts/hero.html',
            '<!-- wp:image {"url":"/main.jpg","alt":"Main","sizeSlug":"large",'
            . '"caption":"Caption <img src=\"/badge.png\" alt=\"Badge\"/> text."} -->'
            . '<figure class="wp-block-image size-large"><img src="/main.jpg" alt="Main"/>'
            . '<figcaption class="wp-element-caption">' . $caption . '</figcaption></figure>'
            . '<!-- /wp:image -->'
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $delivered = $project->readText('theme/parts/hero.html');
        assert_contains('/main.jpg', $delivered, 'the primary image survives');
        assert_true(!str_contains($delivered, '/badge.png'), 'the caption image is removed with its caption');
        $warnings = array_values(array_filter(
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            static fn (string $warning): bool => str_contains($warning, 'Caption'),
        ));
        assert_eq(1, count($warnings), 'caption descendants do not detach removal evidence');
        assert_contains('delivered removed', $warnings[0]);
    });
});

test('a caption exposed by repair of an unclosed image is removed before delivery', function () {
    with_temp_dir('image-captions-repaired-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/hero.html',
            '<!-- wp:image {"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large">'
            . '<img src="/x.jpg" alt="A yard"/>'
            . '<figcaption class="wp-element-caption">Caption after truncation.</figcaption>'
            . '</figure>'
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $delivered = $project->readText('theme/parts/hero.html');
        assert_contains('<!-- /wp:image -->', $delivered, 'the fixer completed the image block');
        assert_true(!str_contains($delivered, '<figcaption'), 'the repaired image has no caption element');
        assert_true(!str_contains($delivered, 'Caption after truncation.'), 'caption text is not delivered');
        assert_contains('<img', $delivered, 'the repaired image survives');

        $warnings = $project->readText('warnings.json');
        assert_contains('parts/hero.html', $warnings, 'the warning identifies the repaired file');
        assert_contains('Caption after truncation.', $warnings, 'the warning retains the authored value');

        $again = ImageCaptions::stripOutsideGalleries($delivered);
        assert_eq($delivered, $again['markup'], 'the delivered image is already a caption fixed point');
        assert_eq([], $again['warnings'], 'the fixed point has no residual caption warning');
    });
});

test('a caption exposed after an invalid nested child is removed before delivery', function () {
    with_temp_dir('image-captions-nested-child-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/hero.html',
            '<!-- wp:image {"sizeSlug":"large","caption":"Attribute before repair."} -->'
            . '<figure class="wp-block-image size-large"><img src="/x.jpg" alt="A yard"/>'
            . '<figcaption class="wp-element-caption">Shared value.</figcaption>'
            . '<!-- wp:paragraph --><p>Nested copy.</p><!-- /wp:paragraph -->'
            . '<figcaption class="wp-element-caption">Shared value.</figcaption>'
            . '</figure><!-- /wp:image -->'
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $delivered = $project->readText('theme/parts/hero.html');
        assert_true(!str_contains($delivered, '<figcaption'), 'caption exposed by repair is removed');
        assert_true(!str_contains($delivered, 'Shared value.'), 'exposed caption text is not delivered');
        assert_contains('<img', $delivered, 'the image survives the nested-child repair');

        $warnings = array_values(array_filter(
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            static fn (string $warning): bool => str_contains($warning, 'parts/hero.html: wp:image[1]'),
        ));
        assert_eq(1, count($warnings), 'both passes produce one warning row for the image');
        assert_contains('Attribute before repair.', $warnings[0], 'the row retains the comment value');
        assert_contains('Shared value.', $warnings[0], 'the row retains the shared element value');
        assert_eq(1, substr_count($warnings[0], 'Shared value.'), 'overlap is deduplicated as raw evidence');
    });
});

test('a caption in a pre-existing page is removed before delivery', function () {
    with_temp_dir('image-captions-page-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'pages/landing.html',
            '<!-- wp:image {"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large">'
            . '<img src="/landing.jpg" alt="Landing"/>'
            . '<figcaption class="wp-element-caption">Landing-page caption.</figcaption>'
            . '</figure><!-- /wp:image -->'
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $delivered = $project->readText('theme/pages/landing.html');
        assert_true(!str_contains($delivered, '<figcaption'), 'page caption element is removed');
        assert_true(!str_contains($delivered, 'Landing-page caption.'), 'page caption text is not delivered');
        assert_contains('<img', $delivered, 'the page image survives');

        $warnings = $project->readText('warnings.json');
        assert_contains('pages/landing.html', $warnings, 'the warning identifies the page file');
        assert_contains('Landing-page caption.', $warnings, 'the warning retains the authored value');
    });
});

test('a malformed caption attribute cannot reach the block renderer', function () {
    with_temp_dir('image-captions-malformed-attr-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/hero.html',
            '<!-- wp:image {"sizeSlug":"large","caption":["injected"]} -->'
            . '<figure class="wp-block-image size-large">'
            . '<img src="/x.jpg" alt="A yard"/>'
            . '</figure>'
        );

        $phpWarnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$phpWarnings): bool {
            $phpWarnings[] = "{$severity}: {$message}";
            return true;
        });
        try {
            (new FixBlocksStep(new PhpBlockFixer()))->run($project);
        } finally {
            restore_error_handler();
        }

        assert_eq([], $phpWarnings, 'the renderer never casts the malformed caption value');
        $delivered = $project->readText('theme/parts/hero.html');
        assert_contains('<!-- /wp:image -->', $delivered, 'the unsafe image was repaired');
        assert_true(!str_contains($delivered, 'caption'), 'the malformed caption attr is not delivered');
        assert_true(!str_contains($delivered, '>Array<'), 'the renderer did not synthesize visible Array text');
        assert_contains('<img', $delivered, 'the image survives');

        $warnings = $project->readText('warnings.json');
        assert_contains('parts/hero.html', $warnings, 'the warning identifies the file');
        assert_contains('[\\"injected\\"]', $warnings, 'warnings.json retains the authored array');
    });
});

test('a malformed gallery caption attribute cannot reach the block renderer', function () {
    with_temp_dir('image-captions-malformed-gallery-attr-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/gallery.html',
            '<!-- wp:gallery --><figure class="wp-block-gallery">'
            . '<!-- wp:image {"url":"/x.jpg","alt":"A yard","sizeSlug":"large",'
            . '"style":{"border":{"radius":"12px"}},"caption":["injected"]} -->'
            . '<figure class="wp-block-image size-large has-custom-border">'
            . '<img src="/x.jpg" alt="A yard" style="border-radius:12px"/></figure>'
            . '</figure>'
        );

        $phpWarnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$phpWarnings): bool {
            $phpWarnings[] = "{$severity}: {$message}";
            return true;
        });
        try {
            (new FixBlocksStep(new PhpBlockFixer()))->run($project);
        } finally {
            restore_error_handler();
        }

        assert_eq([], $phpWarnings, 'the gallery renderer never casts the malformed caption value');
        $delivered = $project->readText('theme/parts/gallery.html');
        assert_true(!str_contains($delivered, 'caption'), 'the malformed gallery caption attr is not delivered');
        assert_true(!str_contains($delivered, '>Array<'), 'the renderer did not synthesize visible Array text');
        assert_contains('<img', $delivered, 'the gallery image survives');

        $warnings = $project->readJson('warnings.json')['fix-blocks'] ?? [];
        assert_eq(1, count($warnings), 'one actionable warning records the malformed value');
        assert_contains('["injected"]', $warnings[0], 'the warning retains the authored array');
    });
});

test('a mismatched gallery cannot make the renderer expose a malformed caption value', function () {
    with_temp_dir('image-captions-mismatched-gallery-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/gallery.html',
            '<!-- wp:gallery --><figure class="wp-block-gallery">'
            . '<!-- wp:image {"url":"/x.jpg","alt":"A yard","sizeSlug":"large",'
            . '"style":{"border":{"radius":"12px"}},"caption":["injected"]} -->'
            . '<figure class="wp-block-image size-large has-custom-border">'
            . '<img src="/x.jpg" alt="A yard" style="border-radius:12px"/></figure>'
            . '<!-- /wp:image -->'
            . '</figure><!-- /wp:gallery -->'
            . '<!-- /wp:group -->'
        );

        $phpWarnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$phpWarnings): bool {
            $phpWarnings[] = "{$severity}: {$message}";
            return true;
        });
        try {
            (new FixBlocksStep(new PhpBlockFixer()))->run($project);
        } finally {
            restore_error_handler();
        }

        assert_eq([], $phpWarnings, 'the renderer does not cast the malformed caption value');
        $delivered = $project->readText('theme/parts/gallery.html');
        assert_true(!str_contains($delivered, '>Array<'), 'no visible Array caption is delivered');
        assert_contains('<img', $delivered, 'the gallery image survives');

        $warnings = array_values(array_filter(
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            static fn (string $warning): bool => str_contains($warning, 'parts/gallery.html: wp:image[1]'),
        ));
        assert_eq(1, count($warnings), 'one actionable warning records the malformed value');
        assert_contains('["injected"]', $warnings[0], 'the warning retains the authored array');
        assert_contains('delivered removed', $warnings[0], 'the warning describes the delivered value');
        assert_contains('disposition:', $warnings[0], 'the warning records the repair disposition');
    });
});

test('a synthesized image keeps malformed-caption removal evidence', function () {
    with_temp_dir('image-captions-synthesized-img-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/image.html',
            '<!-- wp:image {"id":123,"sizeSlug":"large","caption":["injected"]} -->'
            . '<figure class="wp-block-image size-large"></figure><!-- /wp:image -->'
            . '<!-- /wp:group -->'
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $delivered = $project->readText('theme/parts/image.html');
        assert_contains('<img', $delivered, 'the fixer synthesizes the image element');
        assert_contains('wp-image-123', $delivered, 'the synthesized image carries the derived class');
        assert_true(!str_contains($delivered, 'caption'), 'the malformed caption is not delivered');
        $warnings = array_values(array_filter(
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            static fn (string $warning): bool => str_contains($warning, '["injected"]'),
        ));
        assert_eq(1, count($warnings), 'synthesized markup does not detach authored evidence');
        assert_contains('delivered removed', $warnings[0]);
    });
});

test('an unresolved malformed caption in mismatched markup is warned as unchanged', function () {
    with_temp_dir('image-captions-deferred-gallery-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/gallery.html',
            '<!-- wp:gallery --><figure class="wp-block-gallery">'
            . '<!-- wp:image {"sizeSlug":"large","caption":["injected"]} -->'
            . '<figure class="wp-block-image size-large"><img src="/x.jpg" alt="A yard"/></figure>'
            . '<!-- /wp:image -->'
            . '</figure><!-- /wp:gallery -->'
            . '<!-- /wp:group -->'
        );
        $fixer = new class implements BlockFixer {
            public function fix(string $themeDir): string
            {
                return '0 fixed';
            }
        };

        (new FixBlocksStep($fixer))->run($project);

        $delivered = $project->readText('theme/parts/gallery.html');
        assert_contains('"caption":["injected"]', $delivered, 'the no-op fixer leaves authored bytes intact');
        $warnings = array_values(array_filter(
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            static fn (string $warning): bool => str_contains($warning, 'parts/gallery.html: wp:image[1]'),
        ));
        assert_eq(1, count($warnings), 'one actionable warning records the residual defect');
        assert_contains('["injected"]', $warnings[0], 'the warning retains the authored array');
        assert_contains('delivered unchanged', $warnings[0], 'the warning tells the truth about delivery');
        assert_contains('disposition:', $warnings[0], 'the warning records why repair was deferred');
    });
});

test('a shifted image ordinal does not invent a caption removal warning', function () {
    with_temp_dir('image-captions-shifted-ordinal-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/gallery.html',
            '<!-- wp:gallery --><figure class="wp-block-gallery">'
            . '<!-- wp:image {"sizeSlug":"large","caption":["injected"]} -->'
            . '<figure class="wp-block-image size-large"><img src="/x.jpg" alt="A yard"/></figure>'
            . '<!-- /wp:image -->'
            . '</figure><!-- /wp:gallery -->'
            . '<!-- /wp:group -->'
        );
        $fixer = new class implements BlockFixer {
            public function fix(string $themeDir): string
            {
                $path = $themeDir . '/parts/gallery.html';
                $inserted = '<!-- wp:image {"sizeSlug":"large"} -->'
                    . '<figure class="wp-block-image size-large">'
                    . '<img src="/new.jpg" alt="A new image"/></figure>'
                    . '<!-- /wp:image -->';
                $markup = (string) file_get_contents($path);
                file_put_contents($path, $inserted . $markup);
                return '1 image inserted';
            }
        };

        (new FixBlocksStep($fixer))->run($project);

        $delivered = $project->readText('theme/parts/gallery.html');
        assert_contains('"caption":["injected"]', $delivered, 'the malformed value remains delivered');
        $warnings = array_values(array_filter(
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            static fn (string $warning): bool => str_contains($warning, 'authored value ["injected"]'),
        ));
        assert_eq(1, count($warnings), 'the carried value produces one warning after the ordinal shift');
        assert_contains('wp:image[2]', $warnings[0], 'the warning points to the delivered image ordinal');
        assert_contains('delivered unchanged', $warnings[0], 'the carried value is not falsely reported removed');
        assert_true(
            !str_contains($warnings[0], 'wp:image[1]'),
            'the pre-fixer ordinal does not leak into delivered evidence',
        );
    });
});

test('duplicate malformed values do not assign a deleted image to its surviving sibling', function () {
    with_temp_dir('image-captions-duplicate-value-', function (string $tmp): void {
        $first = '<!-- wp:image {"sizeSlug":"large","caption":["same"]} -->'
            . '<figure class="wp-block-image size-large"><img src="/first.jpg" alt="First"/></figure>'
            . '<!-- /wp:image -->';
        $second = '<!-- wp:image {"sizeSlug":"large","caption":["same"]} -->'
            . '<figure class="wp-block-image size-large"><img src="/second.jpg" alt="Second"/></figure>'
            . '<!-- /wp:image -->';
        $project = ic_fixture_project(
            $tmp,
            'parts/gallery.html',
            $first . $second . '<!-- /wp:group -->',
        );
        $fixer = new class($first) implements BlockFixer {
            public function __construct(private string $removedImage) {}

            public function fix(string $themeDir): string
            {
                $path = $themeDir . '/parts/gallery.html';
                $markup = (string) file_get_contents($path);
                file_put_contents($path, str_replace($this->removedImage, '', $markup));
                return '1 image removed';
            }
        };

        (new FixBlocksStep($fixer))->run($project);

        $delivered = $project->readText('theme/parts/gallery.html');
        assert_true(!str_contains($delivered, '/first.jpg'), 'the first image was removed by the fixer');
        assert_contains('/second.jpg', $delivered, 'the second image survives at the first ordinal');
        $warnings = array_values(array_filter(
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            static fn (string $warning): bool => str_contains($warning, 'authored value ["same"]'),
        ));
        assert_eq(1, count($warnings), 'only the surviving malformed value is reported');
        assert_contains('wp:image[1]', $warnings[0], 'the warning points to the delivered sibling');
        assert_contains('delivered unchanged', $warnings[0], 'the surviving sibling remains unresolved');
        assert_true(
            !str_contains($warnings[0], 'delivered removed'),
            'the deleted image does not create a false caption-removal row',
        );
    });
});

test('exact image clones with different malformed values fail closed after deletion', function () {
    with_temp_dir('image-captions-clone-values-', function (string $tmp): void {
        $first = '<!-- wp:image {"sizeSlug":"large","caption":["first"]} -->'
            . '<figure class="wp-block-image size-large"><img src="/same.jpg" alt="Same"/></figure>'
            . '<!-- /wp:image -->';
        $second = '<!-- wp:image {"sizeSlug":"large","caption":["second"]} -->'
            . '<figure class="wp-block-image size-large"><img src="/same.jpg" alt="Same"/></figure>'
            . '<!-- /wp:image -->';
        $project = ic_fixture_project(
            $tmp,
            'parts/gallery.html',
            $first . $second . '<!-- /wp:group -->',
        );
        $fixer = new class($first) implements BlockFixer {
            public function __construct(private string $removedImage) {}

            public function fix(string $themeDir): string
            {
                $path = $themeDir . '/parts/gallery.html';
                $markup = (string) file_get_contents($path);
                file_put_contents($path, str_replace($this->removedImage, '', $markup));
                return '1 exact clone removed';
            }
        };

        (new FixBlocksStep($fixer))->run($project);

        $warnings = array_values(array_filter(
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            static fn (string $warning): bool => str_contains($warning, 'authored value ["'),
        ));
        assert_eq(1, count($warnings), 'ambiguous deleted-clone evidence is not assigned to the survivor');
        assert_contains('wp:image[1]', $warnings[0], 'the explicit warning uses the final survivor path');
        assert_contains('["second"]', $warnings[0], 'the surviving malformed value is retained');
        assert_contains('delivered unchanged', $warnings[0], 'the survivor is truthfully reported');
        assert_true(!str_contains($warnings[0], '["first"]'), 'the deleted clone is not misreported');
    });
});

test('one malformed clone retains removal evidence beside an uncaptioned clone', function () {
    with_temp_dir('image-captions-malformed-clone-subset-', function (string $tmp): void {
        $captioned = '<!-- wp:image {"sizeSlug":"large","caption":["first"]} -->'
            . '<figure class="wp-block-image size-large"><img src="/same.jpg" alt="Same"/></figure>'
            . '<!-- /wp:image -->';
        $plain = '<!-- wp:image {"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large"><img src="/same.jpg" alt="Same"/></figure>'
            . '<!-- /wp:image -->';
        $project = ic_fixture_project(
            $tmp,
            'parts/images.html',
            $captioned . $plain . '<!-- /wp:group -->',
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $warnings = array_values(array_filter(
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            static fn (string $warning): bool => str_contains($warning, '["first"]'),
        ));
        assert_eq(1, count($warnings), 'the full clone inventory preserves subset evidence');
        assert_contains('wp:image[1]', $warnings[0], 'the malformed first occurrence is identified');
        assert_contains('delivered removed', $warnings[0]);
    });
});

test('a newly inserted captioned image does not absorb an earlier removal warning', function () {
    with_temp_dir('image-captions-inserted-caption-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/images.html',
            '<!-- wp:image {"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large"><img src="/original.jpg" alt="Original"/>'
            . '<figcaption class="wp-element-caption">Original caption.</figcaption></figure>'
            . '<!-- /wp:image -->'
        );
        $fixer = new class implements BlockFixer {
            public function fix(string $themeDir): string
            {
                $path = $themeDir . '/parts/images.html';
                $inserted = '<!-- wp:image {"sizeSlug":"large"} -->'
                    . '<figure class="wp-block-image size-large"><img src="/inserted.jpg" alt="Inserted"/>'
                    . '<figcaption class="wp-element-caption">Inserted caption.</figcaption></figure>'
                    . '<!-- /wp:image -->';
                $markup = (string) file_get_contents($path);
                file_put_contents($path, $inserted . $markup);
                return '1 captioned image inserted';
            }
        };

        (new FixBlocksStep($fixer))->run($project);

        $delivered = $project->readText('theme/parts/images.html');
        assert_true(!str_contains($delivered, '<figcaption'), 'both captions are removed');
        $warnings = array_values(array_filter(
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            static fn (string $warning): bool => str_contains($warning, 'caption.'),
        ));
        assert_eq(2, count($warnings), 'each final image receives its own removal evidence');
        assert_contains('wp:image[1]', $warnings[1], 'the inserted caption uses its final first ordinal');
        assert_contains('Inserted caption.', $warnings[1], 'the inserted value stays with the inserted image');
        assert_contains('wp:image[2]', $warnings[0], 'the original caption rebases to its final second ordinal');
        assert_contains('Original caption.', $warnings[0], 'the original value stays with the original image');
        assert_true(
            !str_contains($warnings[0], 'Inserted caption.')
                && !str_contains($warnings[1], 'Original caption.'),
            'cross-pass evidence is not merged by ordinal',
        );
    });
});

test('equal-count image clones retain one removal warning per occurrence', function () {
    with_temp_dir('image-captions-equal-clones-', function (string $tmp): void {
        $image = static fn (string $caption): string =>
            '<!-- wp:image {"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large"><img src="/same.jpg" alt="Same"/>'
            . '<figcaption class="wp-element-caption">' . $caption . '</figcaption></figure>'
            . '<!-- /wp:image -->';
        $project = ic_fixture_project(
            $tmp,
            'parts/images.html',
            $image('First clone caption.') . $image('Second clone caption.'),
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $warnings = array_values(array_filter(
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            static fn (string $warning): bool => str_contains($warning, 'clone caption.'),
        ));
        assert_eq(2, count($warnings), 'each preserved clone keeps its removal evidence');
        assert_contains('wp:image[1]', $warnings[0]);
        assert_contains('First clone caption.', $warnings[0]);
        assert_contains('wp:image[2]', $warnings[1]);
        assert_contains('Second clone caption.', $warnings[1]);
    });
});

test('one captioned clone retains removal evidence beside an uncaptioned clone', function () {
    with_temp_dir('image-captions-clone-subset-', function (string $tmp): void {
        $captioned = '<!-- wp:image {"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large"><img src="/same.jpg" alt="Same"/>'
            . '<figcaption class="wp-element-caption">Only first caption.</figcaption></figure>'
            . '<!-- /wp:image -->';
        $plain = '<!-- wp:image {"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large"><img src="/same.jpg" alt="Same"/></figure>'
            . '<!-- /wp:image -->';
        $project = ic_fixture_project($tmp, 'parts/images.html', $captioned . $plain);

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $warnings = array_values(array_filter(
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            static fn (string $warning): bool => str_contains($warning, 'Only first caption.'),
        ));
        assert_eq(1, count($warnings), 'the full clone inventory preserves the sole removal');
        assert_contains('wp:image[1]', $warnings[0], 'the first occurrence retains its evidence');
        assert_contains('delivered removed', $warnings[0]);
    });
});

test('cross-pass long-value collisions remain distinct in one warning row', function () {
    with_temp_dir('image-captions-long-cross-pass-', function (string $tmp): void {
        $prefix = str_repeat('Same prefix ', 20);
        $initial = $prefix . 'initial';
        $exposed = $prefix . 'exposed';
        $attrs = json_encode(['sizeSlug' => 'large', 'caption' => $initial], JSON_THROW_ON_ERROR);
        $project = ic_fixture_project($tmp, 'parts/hero.html',
            "<!-- wp:image {$attrs} -->"
            . '<figure class="wp-block-image size-large"><img src="/x.jpg" alt="A yard"/>'
            . '<!-- wp:paragraph --><p>Nested copy.</p><!-- /wp:paragraph -->'
            . '<figcaption class="wp-element-caption">' . $exposed . '</figcaption>'
            . '</figure><!-- /wp:image -->'
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $warnings = array_values(array_filter(
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            static fn (string $warning): bool => str_contains($warning, 'parts/hero.html: wp:image[1]'),
        ));
        assert_eq(1, count($warnings), 'both passes produce one warning row');
        assert_eq(2, substr_count($warnings[0], 'fingerprint:'), 'both colliding raw values remain represented');
    });
});
