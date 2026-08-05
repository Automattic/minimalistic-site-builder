<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Steps\FixBlocksStep;

test('real block serialization delivers authoritative image and button corner wiring', function () {
    with_temp_dir('shape-integration-', function (string $tmp): void {
        $project = new Project($tmp);
        $project->writeJson('designDirection.json', ['shape' => 'soft']);
        $project->writeJson('theme/theme.json', ['version' => 3]);
        $project->writeText(
            'theme/parts/shape-probe.html',
            '<!-- wp:image {"url":"/contained.jpg","alt":"Contained","caption":"",'
                . '"className":"card-media is-style-rounded keep-image",'
                . '"style":{"border":{"radius":"24px","width":"1px","style":"solid"},'
                . '"shadow":"0 1px 2px #000"}} -->'
                . '<figure class="wp-block-image card-media is-style-rounded keep-image has-custom-border">'
                . '<img src="/contained.jpg" alt="Contained" '
                . 'style="border-style:solid;border-width:1px;border-radius:24px;box-shadow:0 1px 2px #000"/>'
                . '</figure><!-- /wp:image -->'
                . '<!-- wp:image {"url":"/full.jpg","alt":"Full","caption":"","align":"full",'
                . '"className":"is-style-circle-mask keep-full",'
                . '"style":{"border":{"radius":"12px","width":"2px"}}} -->'
                . '<figure class="wp-block-image alignfull is-style-circle-mask keep-full has-custom-border">'
                . '<img src="/full.jpg" alt="Full" style="border-width:2px;border-radius:12px"/>'
                . '</figure><!-- /wp:image -->'
                . '<!-- wp:button {"url":"#go","text":"Go","className":"cta is-style-squared keep-button",'
                . '"style":{"border":{"radius":0,"width":"2px","style":"solid"},'
                . '"typography":{"fontWeight":"700"}}} -->'
                . '<div class="wp-block-button cta is-style-squared keep-button"><a '
                . 'class="wp-block-button__link no-border-radius wp-element-button" href="#go" '
                . 'style="border-style:solid;border-width:2px;font-weight:700">Go</a></div>'
                . '<!-- /wp:button -->',
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $delivered = $project->readText('theme/parts/shape-probe.html');
        $doc = BlockMarkup::parse($delivered);
        $indices = $doc->indices();
        $contained = $doc->attrs($indices[0]);
        $full = $doc->attrs($indices[1]);
        $button = $doc->attrs($indices[2]);

        assert_true(!array_key_exists('radius', $contained['style']['border']));
        assert_eq('1px', $contained['style']['border']['width']);
        assert_eq('solid', $contained['style']['border']['style']);
        assert_eq('0 1px 2px #000', $contained['style']['shadow']);
        assert_true(!str_contains($doc->innerHtml($indices[0]), 'border-radius'));
        assert_contains('border-width:1px', $doc->innerHtml($indices[0]));
        assert_contains('box-shadow:0 1px 2px #000', $doc->innerHtml($indices[0]));

        assert_eq('0', $full['style']['border']['radius']);
        assert_eq('2px', $full['style']['border']['width']);
        assert_contains('border-radius:0', $doc->innerHtml($indices[1]));

        assert_true(!array_key_exists('radius', $button['style']['border']));
        assert_eq('2px', $button['style']['border']['width']);
        assert_eq('700', $button['style']['typography']['fontWeight']);
        assert_true(!str_contains($doc->innerHtml($indices[2]), 'border-radius'));
        assert_contains('font-weight:700', $doc->innerHtml($indices[2]));

        foreach (['is-style-rounded', 'is-style-circle-mask', 'is-style-squared', 'no-border-radius'] as $token) {
            assert_true(!str_contains($delivered, $token), "{$token} cannot defeat the theme wiring");
        }
        foreach (['card-media', 'keep-image', 'keep-full', 'cta', 'keep-button'] as $token) {
            assert_contains($token, $delivered, "unrelated class {$token} survives");
        }

        assert_true(!$project->exists('warnings.json'), 'fully repaired corner overrides stay out of the repair queue');
        $report = $project->readText('logs/fix-blocks.log');
        assert_contains('parts/shape-probe.html block 0 (core/image)', $report);
        assert_contains('parts/shape-probe.html block 1 (core/image)', $report);
        assert_contains('parts/shape-probe.html block 2 (core/button)', $report);
        assert_contains('style.border.radius "24px" -> removed', $report);
        assert_contains('style.border.radius "12px" -> "0"', $report);
        assert_contains('style.border.radius 0 -> removed', $report);
        assert_contains('removed shape variation', $report);

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);
        assert_eq($delivered, $project->readText('theme/parts/shape-probe.html'));
        assert_true(!$project->exists('warnings.json'), 'the real serialized fixed point adds no warning evidence');
    });
});

test('real block serialization removes carried custom CSS and parent button element overrides', function () {
    with_temp_dir('shape-carried-integration-', function (string $tmp): void {
        $project = new Project($tmp);
        $project->writeJson('designDirection.json', ['shape' => 'round']);
        $project->writeJson('theme/theme.json', ['version' => 3]);
        $project->writeText(
            'theme/parts/shape-carried.html',
            '<!-- wp:image {"url":"/photo.jpg","alt":"Photo","caption":"",'
                . '"style":{"css":"\u0026 img { border-radius:50%!important; filter:none; } '
                . '\u0026 figcaption { border-radius:6px; }"}} -->'
                . '<figure class="wp-block-image"><img src="/photo.jpg" alt="Photo"/></figure>'
                . '<!-- /wp:image -->'
                . '<!-- wp:buttons {"style":{"elements":{"button":{'
                . '"border":{"radius":"2px","width":"1px"},'
                . '":hover":{"border":{"radius":"4px","color":"#123456"}}}}}} -->'
                . '<div class="wp-block-buttons"><!-- wp:button {"url":"#go","text":"Go"} -->'
                . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" '
                . 'href="#go">Go</a></div><!-- /wp:button --></div><!-- /wp:buttons -->',
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $delivered = $project->readText('theme/parts/shape-carried.html');
        $doc = BlockMarkup::parse($delivered);
        $indices = $doc->indices();
        $imageCss = $doc->attrs($indices[0])['style']['css'];
        $buttons = $doc->attrs($indices[1])['style']['elements']['button'];

        assert_true(!str_contains($imageCss, 'border-radius:50%'));
        assert_contains('filter:none', $imageCss);
        assert_contains('figcaption { border-radius:6px', $imageCss, 'caption geometry survives');
        assert_true(!isset($buttons['border']['radius']));
        assert_eq('1px', $buttons['border']['width']);
        assert_true(!isset($buttons[':hover']['border']['radius']));
        assert_eq('#123456', $buttons[':hover']['border']['color']);
        assert_true(!$project->exists('warnings.json'));
        $log = $project->readText('logs/fix-blocks.log');
        assert_contains('style.css declaration', $log);
        assert_contains('style.elements.button.border.radius', $log);
        assert_contains('style.elements.button.:hover.border.radius', $log);

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);
        assert_eq($delivered, $project->readText('theme/parts/shape-carried.html'));
    });
});
