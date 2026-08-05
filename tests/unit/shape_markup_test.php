<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\BlockSerializer\FileReport;
use Automattic\SiteBuild\BlockSerializer\FixerReport;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ReportingBlockFixer;
use Automattic\SiteBuild\ShapeMarkup;
use Automattic\SiteBuild\Steps\FixBlocksStep;

test('FixBlocksStep declares the shape input and every durable output it mutates', function () {
    $declaration = (new FixBlocksStep(new PhpBlockFixer()))->declaration();
    assert_true(in_array('designDirection.json', $declaration->reads, true));
    assert_true(in_array('theme/parts/*', $declaration->writes, true));
    assert_true(in_array('warnings.json', $declaration->writes, true));
});

test('shape markup is an exact no-op without an explicit valid commitment', function () {
    $markup = '<!-- wp:image {"style":{"border":{"radius":"999px"}}} -->'
        . '<figure class="wp-block-image is-style-rounded"><img src="photo.jpg" alt=""/></figure>'
        . '<!-- /wp:image -->';

    foreach ([null, '', 'wavy'] as $shape) {
        $result = ShapeMarkup::normalize($markup, $shape);
        assert_eq($markup, $result['markup']);
        assert_eq([], $result['changes']);
    }
});

test('shape markup removes contained image and button overrides at the smallest block unit', function () {
    $before = '<!-- wp:paragraph --><p>Before unchanged</p><!-- /wp:paragraph -->';
    $after = '<!-- wp:paragraph --><p>After unchanged</p><!-- /wp:paragraph -->';
    $markup = $before
        . '<!-- wp:image {"className":"card-media is-style-rounded keep-image",'
        . '"style":{"border":{"radius":"999px","width":"1px"},"shadow":"0 1px #000"}} -->'
        . '<figure class="wp-block-image card-media keep-image"><img src="one.jpg" alt="One"/></figure>'
        . '<!-- /wp:image -->'
        . '<!-- wp:image {"className":"secondary-image"} -->'
        . '<figure class="wp-block-image secondary-image is-style-circle-mask"><img src="two.jpg" alt="Two"/></figure>'
        . '<!-- /wp:image -->'
        . '<!-- wp:button {"url":"#go","text":"Go","className":"cta is-style-squared keep-button",'
        . '"style":{"border":{"radius":"2px","width":"2px"},"typography":{"fontWeight":"700"}}} -->'
        . '<div class="wp-block-button cta is-style-squared keep-button"><a '
        . 'class="wp-block-button__link no-border-radius wp-element-button" href="#go" '
        . 'style="border-width:2px;border-radius:2px;font-weight:700">Go</a></div>'
        . '<!-- /wp:button -->'
        . $after;

    $result = ShapeMarkup::normalize($markup, 'soft');
    $fixed = $result['markup'];
    $doc = BlockMarkup::parse($fixed);
    $indices = $doc->indices();

    assert_true(str_starts_with($fixed, $before), 'the preceding sibling is byte-for-byte intact');
    assert_true(str_ends_with($fixed, $after), 'the following sibling is byte-for-byte intact');
    assert_true(!str_contains($fixed, 'is-style-rounded'), 'attr-only image variant removed');
    assert_true(!str_contains($fixed, 'is-style-circle-mask'), 'HTML-only image variant removed');
    assert_true(!str_contains($fixed, 'is-style-squared'), 'button variant removed from attrs and HTML');
    assert_true(!str_contains($fixed, 'no-border-radius'), 'HTML-only button variant removed');
    assert_contains('card-media keep-image', $fixed, 'unrelated image class tokens survive');
    assert_contains('cta keep-button', $fixed, 'unrelated button class tokens survive');

    $imageAttrs = $doc->attrs($indices[1]);
    assert_true(!array_key_exists('radius', $imageAttrs['style']['border']));
    assert_eq('1px', $imageAttrs['style']['border']['width'], 'border sibling survives');
    assert_eq('0 1px #000', $imageAttrs['style']['shadow'], 'style sibling survives');
    $buttonAttrs = $doc->attrs($indices[3]);
    assert_true(!array_key_exists('radius', $buttonAttrs['style']['border']));
    assert_eq('2px', $buttonAttrs['style']['border']['width']);
    assert_eq('700', $buttonAttrs['style']['typography']['fontWeight']);
    assert_eq(6, count($result['changes']), 'each authored radius or class override is reported once');

    $again = ShapeMarkup::normalize($fixed, 'soft');
    assert_eq($fixed, $again['markup'], 'normalization reaches a byte-for-byte fixed point');
    assert_eq([], $again['changes'], 'the fixed point emits no repeat warning evidence');
});

test('shape markup removes carried target CSS without touching caption or generic card geometry', function () {
    $markup = '<!-- wp:image {"style":{"css":"\u0026 img { border-radius: 50% !important; filter:none; } '
        . '\u0026 figcaption { border-radius:6px; padding:2px; }"}} -->'
        . '<figure class="wp-block-image"><img src="photo.jpg" alt=""/><figcaption>Caption</figcaption></figure>'
        . '<!-- /wp:image -->'
        . '<!-- wp:group {"style":{"border":{"radius":"12px"},'
        . '"css":"\u0026 { border-radius:12px; padding:1rem; }"}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->';

    $result = ShapeMarkup::normalize($markup, 'round');
    $doc = BlockMarkup::parse($result['markup']);
    $indices = $doc->indices();
    $imageCss = $doc->attrs($indices[0])['style']['css'];
    $groupStyle = $doc->attrs($indices[1])['style'];

    assert_true(!str_contains($imageCss, 'border-radius: 50%'), 'owned image selector is repaired');
    assert_contains('filter:none', $imageCss, 'image CSS sibling survives');
    assert_contains('figcaption { border-radius:6px', $imageCss, 'caption geometry is outside the commitment');
    assert_eq('12px', $groupStyle['border']['radius'], 'generic group radius survives');
    assert_contains('border-radius:12px', $groupStyle['css'], 'generic group custom CSS survives');
    assert_eq(1, count($result['changes']));
    assert_contains('style.css declaration', $result['changes'][0]['property']);
    assert_contains('border-radius: 50% !important', $result['changes'][0]['authored']);

    $again = ShapeMarkup::normalize($result['markup'], 'round');
    assert_eq($result['markup'], $again['markup']);
    assert_eq([], $again['changes']);
});

test('shape markup repairs mixed external declarations and balanced implicit-root selectors', function () {
    $css = 'border-radius:1rem; '
        . '--payload:{ border-radius:88px; }; '
        . '&:is(:hover,:focus) { border-radius:2rem; color:inherit; } '
        . '&:not(:has(.excluded)) { all:initial; filter:none; } '
        . '& figcaption { border-radius:3rem; padding:2px; }';
    $attrs = json_encode(['style' => ['css' => $css]], JSON_THROW_ON_ERROR);
    $markup = '<!-- wp:image ' . $attrs . ' -->'
        . '<figure class="wp-block-image"><img src="photo.jpg" alt=""/>'
        . '<figcaption>Caption</figcaption></figure><!-- /wp:image -->';

    $result = ShapeMarkup::normalize($markup, 'round');
    $delivered = BlockMarkup::parse($result['markup'])->attrs(0)['style']['css'];

    assert_true(!str_contains($delivered, 'border-radius:1rem'), 'direct implicit-root declaration is removed');
    assert_true(!str_contains($delivered, 'border-radius:2rem'), 'balanced :is root declaration is removed');
    assert_true(!str_contains($delivered, 'all:initial'), 'balanced :not/:has root reset is removed');
    assert_contains('--payload:{ border-radius:88px; }', $delivered, 'custom-property block stays opaque');
    assert_contains('color:inherit', $delivered);
    assert_contains('filter:none', $delivered);
    assert_contains('& figcaption { border-radius:3rem', $delivered, 'descendant caption geometry survives');
    assert_eq(3, count($result['changes']));

    $again = ShapeMarkup::normalize($result['markup'], 'round');
    assert_eq($result['markup'], $again['markup']);
    assert_eq([], $again['changes']);
});

test('shape markup leaves unrelated empty-object and empty-list attributes intact', function () {
    $markup = '<!-- wp:image {"metadata":{},"allowedBlocks":[],"style":{"border":{"radius":"9px"}}} -->'
        . '<figure class="wp-block-image"><img src="photo.jpg" alt=""/></figure><!-- /wp:image -->';

    $result = ShapeMarkup::normalize($markup, 'soft');

    assert_contains('"metadata":{}', $result['markup']);
    assert_contains('"allowedBlocks":[]', $result['markup']);
    assert_true(!str_contains($result['markup'], '"radius":"9px"'));
});

test('shape markup isolates malformed owned CSS and marks the wider loss durable', function () {
    $markup = '<!-- wp:image {"style":{"css":"\u0026 img { color:red; border-radius:99px"}} -->'
        . '<figure class="wp-block-image"><img src="photo.jpg" alt=""/></figure><!-- /wp:image -->';

    $result = ShapeMarkup::normalize($markup, 'soft');
    $attrs = BlockMarkup::parse($result['markup'])->attrs(0);
    assert_true(!isset($attrs['style']['css']));
    assert_eq(1, count($result['changes']));
    assert_eq(true, $result['changes'][0]['warning'] ?? false);
    assert_contains('structurally malformed custom CSS', $result['changes'][0]['disposition']);
    assert_contains('border-radius:99px', $result['changes'][0]['authored']);

    $fixer = new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            return '[fix-templates] malformed owned CSS isolated';
        }
    };
    with_temp_dir('shape-unsafe-css-', function (string $tmp) use ($fixer, $markup): void {
        $project = new Project($tmp);
        $project->writeJson('designDirection.json', ['shape' => 'soft']);
        $project->writeText('theme/parts/section.html', $markup);

        (new FixBlocksStep($fixer))->run($project);

        $warnings = implode(' ', $project->readJson('warnings.json')['fix-blocks'] ?? []);
        assert_contains('parts/section.html block 0 (core/image)', $warnings);
        assert_contains('corner property style.css', $warnings);
        assert_contains('authored', $warnings);
        assert_contains('delivered removed', $warnings);
        assert_contains('structurally malformed custom CSS', $warnings);
    });
});

test('shape markup removes carried descendant-button radii from parent blocks and states', function () {
    $markup = '<!-- wp:buttons {"style":{"elements":{"button":{'
        . '"border":{"radius":"1px","width":"2px"},'
        . '":hover":{"border":{"radius":"3px","color":"#123"}},'
        . '"css":"\u0026 { all:initial!important; color:inherit; }"},'
        . '"caption":{"border":{"radius":"7px"}}}}} -->'
        . '<div class="wp-block-buttons"><!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Go</a></div>'
        . '<!-- /wp:button --></div><!-- /wp:buttons -->';

    $result = ShapeMarkup::normalize($markup, 'soft');
    $doc = BlockMarkup::parse($result['markup']);
    $style = $doc->attrs($doc->indices()[0])['style'];
    $button = $style['elements']['button'];

    assert_true(!isset($button['border']['radius']));
    assert_eq('2px', $button['border']['width']);
    assert_true(!isset($button[':hover']['border']['radius']));
    assert_eq('#123', $button[':hover']['border']['color']);
    assert_true(!str_contains($button['css'], 'all:initial'));
    assert_contains('color:inherit', $button['css']);
    assert_eq('7px', $style['elements']['caption']['border']['radius'], 'unrelated element survives');
    assert_eq(3, count($result['changes']));

    $again = ShapeMarkup::normalize($result['markup'], 'soft');
    assert_eq($result['markup'], $again['markup']);
    assert_eq([], $again['changes']);
});

test('shape markup makes alignfull images square while contained images inherit the theme', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:image {"style":{"border":{"radius":"12px","color":"#123"}}} -->'
        . '<figure class="wp-block-image"><img src="contained.jpg" alt=""/></figure><!-- /wp:image -->'
        . '<!-- wp:image {"align":"full","style":{"border":{"radius":0,"width":"3px"}}} -->'
        . '<figure class="wp-block-image alignfull"><img src="full.jpg" alt=""/></figure><!-- /wp:image -->'
        . '<!-- wp:image --><figure class="wp-block-image alignfull"><img src="html-full.jpg" alt=""/></figure>'
        . '<!-- /wp:image -->'
        . '</div><!-- /wp:group -->';

    $result = ShapeMarkup::normalize($markup, 'round');
    $doc = BlockMarkup::parse($result['markup']);
    $indices = $doc->indices();
    $contained = $doc->attrs($indices[1]);
    $full = $doc->attrs($indices[2]);
    $htmlFull = $doc->attrs($indices[3]);

    assert_true(!array_key_exists('radius', $contained['style']['border']));
    assert_eq('#123', $contained['style']['border']['color']);
    assert_eq('0', $full['style']['border']['radius'], 'numeric zero is canonicalized to authoritative CSS zero');
    assert_eq('3px', $full['style']['border']['width']);
    assert_eq('0', $htmlFull['style']['border']['radius'], 'HTML-only alignfull is protected too');
    assert_eq(3, count($result['changes']));
    assert_true(!array_key_exists('warning', $result['changes'][2]), 'successful repair is report-only');
});

test('shape markup reports equivalent committed radii without durable warning noise', function () {
    $cases = [
        ['soft', 'image', '.500rem'],
        ['soft', 'button', '0.5REM'],
        ['round', 'image', '1.250rem'],
        ['round', 'button', '9999.0px'],
        ['sharp', 'image', '0px'],
        ['sharp', 'button', 0],
    ];
    foreach ($cases as [$shape, $block, $radius]) {
        $encoded = json_encode($radius);
        $tag = $block === 'image' ? 'figure' : 'div';
        $markup = '<!-- wp:' . $block . ' {"style":{"border":{"radius":' . $encoded . '}}} -->'
            . '<' . $tag . ' class="wp-block-' . $block . '"></' . $tag . '>'
            . '<!-- /wp:' . $block . ' -->';
        $result = ShapeMarkup::normalize($markup, $shape);

        assert_eq(1, count($result['changes']), "{$shape} {$block} still records its canonicalization");
        assert_true(!array_key_exists('warning', $result['changes'][0]));
        assert_true(!str_contains($result['markup'], '"radius"'));
    }

    $fullSharp = '<!-- wp:image {"align":"full"} -->'
        . '<figure class="wp-block-image alignfull"><img src="full.jpg" alt=""/></figure><!-- /wp:image -->';
    $result = ShapeMarkup::normalize($fullSharp, 'sharp');
    assert_eq($fullSharp, $result['markup'], 'sharp already makes an unstyled full-width image square globally');
    assert_eq([], $result['changes'], 'no redundant inline zero is added');
});

test('shape markup replaces only the smallest malformed full-width radius container', function () {
    $nullStyle = '<!-- wp:image {"align":"full","style":null,"className":"keep"} -->'
        . '<figure class="wp-block-image alignfull keep"><img src="one.jpg" alt=""/></figure><!-- /wp:image -->';
    $result = ShapeMarkup::normalize($nullStyle, 'soft');
    $attrs = BlockMarkup::parse($result['markup'])->attrs(0);
    assert_eq(['border' => ['radius' => '0']], $attrs['style']);
    assert_eq('keep', $attrs['className']);
    assert_eq('style', $result['changes'][0]['property']);
    assert_eq(null, $result['changes'][0]['authored']);
    assert_true(!array_key_exists('warning', $result['changes'][0]));

    $scalarBorder = '<!-- wp:image {"align":"full","style":{"border":"broken",'
        . '"spacing":{"margin":{"top":"1rem"}}},"className":"keep"} -->'
        . '<figure class="wp-block-image alignfull keep"><img src="two.jpg" alt=""/></figure><!-- /wp:image -->';
    $result = ShapeMarkup::normalize($scalarBorder, 'round');
    $attrs = BlockMarkup::parse($result['markup'])->attrs(0);
    assert_eq(['radius' => '0'], $attrs['style']['border']);
    assert_eq('1rem', $attrs['style']['spacing']['margin']['top'], 'style siblings survive border replacement');
    assert_eq('style.border', $result['changes'][0]['property']);
    assert_eq('broken', $result['changes'][0]['authored']);
    assert_true(!array_key_exists('warning', $result['changes'][0]));

    $again = ShapeMarkup::normalize($result['markup'], 'round');
    assert_eq($result['markup'], $again['markup']);
    assert_eq([], $again['changes']);

    $listStyle = '<!-- wp:image {"align":"full","style":["lost"],"className":"keep"} -->'
        . '<figure class="wp-block-image alignfull keep"><img src="three.jpg" alt=""/></figure><!-- /wp:image -->';
    $result = ShapeMarkup::normalize($listStyle, 'sharp');
    $attrs = BlockMarkup::parse($result['markup'])->attrs(0);
    assert_eq(['border' => ['radius' => '0']], $attrs['style']);
    assert_eq(['lost'], $result['changes'][0]['authored']);
    assert_true(!array_key_exists('warning', $result['changes'][0]), 'resolved sharp repair is report-only');
});

test('sharp keeps harmless square button variants but still removes a local radius', function () {
    $markup = '<!-- wp:button {"className":"is-style-squared keep",'
        . '"style":{"border":{"radius":"8px"}}} -->'
        . '<div class="wp-block-button is-style-squared keep"><a '
        . 'class="wp-block-button__link no-border-radius wp-element-button">Go</a></div>'
        . '<!-- /wp:button -->';

    $result = ShapeMarkup::normalize($markup, 'sharp');
    assert_contains('is-style-squared', $result['markup']);
    assert_contains('no-border-radius', $result['markup']);
    assert_true(!str_contains($result['markup'], '"radius"'));
    assert_eq(1, count($result['changes']), 'only the conflicting local radius is removed');
});

test('rounded commitments remove an HTML-only square variant from button elements too', function () {
    $markup = '<!-- wp:button {"tagName":"button","text":"Go"} -->'
        . '<div class="wp-block-button keep-wrapper"><button type="button" '
        . 'class="wp-block-button__link no-border-radius wp-element-button keep-link">Go</button></div>'
        . '<!-- /wp:button -->';

    $result = ShapeMarkup::normalize($markup, 'round');

    assert_true(!str_contains($result['markup'], 'no-border-radius'));
    assert_contains('wp-block-button__link wp-element-button keep-link', $result['markup']);
    assert_contains('keep-wrapper', $result['markup']);
    assert_eq(1, count($result['changes']));
    assert_eq('saved HTML class token', $result['changes'][0]['property']);
});

test('shape markup leaves unsafe target nodes untouched for structural recovery', function () {
    $unclosed = '<!-- wp:image {"style":{"border":{"radius":"24px"}},'
        . '"className":"is-style-rounded"} -->'
        . '<figure class="wp-block-image is-style-rounded"><img src="photo.jpg" alt=""/></figure>';
    $result = ShapeMarkup::normalize($unclosed, 'soft');
    assert_eq($unclosed, $result['markup']);
    assert_eq([], $result['changes']);

    $malformed = '<!-- wp:image {"style":{"border":{"radius":"24px"}} -->'
        . '<figure class="wp-block-image is-style-rounded"><img src="photo.jpg" alt=""/></figure>'
        . '<!-- /wp:image -->';
    $result = ShapeMarkup::normalize($malformed, 'soft');
    assert_eq($malformed, $result['markup']);
    assert_eq([], $result['changes']);
});

test('shape markup touches only selector-effective image variation classes', function () {
    $markup = '<!-- wp:image -->'
        . '<figure class="wp-block-image keep-root">'
        . '<a class="linked-image is-style-rounded keep-link" href="#photo"><img src="photo.jpg" alt=""/></a>'
        . '<figcaption class="alignfull is-style-rounded is-style-circle-mask keep-caption">Caption</figcaption>'
        . '</figure><!-- /wp:image -->';

    $result = ShapeMarkup::normalize($markup, 'soft');

    assert_contains('class="linked-image keep-link"', $result['markup'], 'rounded link ancestor no longer overrides radius');
    assert_contains(
        'class="alignfull is-style-rounded is-style-circle-mask keep-caption"',
        $result['markup'],
        'caption tokens are unrelated content and survive byte-for-byte',
    );
    assert_true(!str_contains($result['markup'], '"radius"'), 'caption alignfull cannot reclassify the image');
    assert_eq(1, count($result['changes']));
    assert_eq('saved HTML class token', $result['changes'][0]['property']);
});

test('shape attribute rewrites preserve inert sourced objects without emitting malformed lists', function () {
    $markup = '<!-- wp:image {"className":"is-style-rounded","style":{"border":{},"typography":{}},'
        . '"metadata":{}} -->'
        . '<figure class="wp-block-image is-style-rounded"><img src="photo.jpg" alt=""/></figure>'
        . '<!-- /wp:image -->';

    $result = ShapeMarkup::normalize($markup, 'soft');
    $fixed = $result['markup'];

    assert_contains('"style":{"border":{},"typography":{}}', $fixed);
    assert_contains('"metadata":{}', $fixed);
    assert_true(!str_contains($fixed, '[]'), 'empty JSON objects never become list-shaped attributes');
    assert_true(!str_contains($fixed, 'is-style-rounded'));
    $again = ShapeMarkup::normalize($fixed, 'soft');
    assert_eq($fixed, $again['markup']);
    assert_eq([], $again['changes']);
});

test('shape markup removes malformed contained containers and preserves valid siblings', function () {
    $markup = '<!-- wp:image {"className":"is-style-rounded keep-image","style":null} -->'
        . '<figure class="wp-block-image is-style-rounded keep-image"><img src="photo.jpg" alt=""/></figure>'
        . '<!-- /wp:image -->'
        . '<!-- wp:button {"className":"is-style-squared keep-button",'
        . '"style":{"border":null,"typography":{"fontWeight":"700"}}} -->'
        . '<div class="wp-block-button is-style-squared keep-button"><a '
        . 'class="wp-block-button__link wp-element-button">Go</a></div><!-- /wp:button -->';

    $result = ShapeMarkup::normalize($markup, 'soft');
    $doc = BlockMarkup::parse($result['markup']);
    $indices = $doc->indices();
    $image = $doc->attrs($indices[0]);
    $button = $doc->attrs($indices[1]);

    assert_true(!array_key_exists('style', $image), 'malformed image style is the isolated removal unit');
    assert_true(!array_key_exists('border', $button['style']), 'malformed button border alone is removed');
    assert_eq('700', $button['style']['typography']['fontWeight'], 'valid style sibling survives');
    assert_true(!str_contains($result['markup'], 'is-style-rounded'));
    assert_true(!str_contains($result['markup'], 'is-style-squared'));
    assert_contains('keep-image', $result['markup']);
    assert_contains('keep-button', $result['markup']);
    assert_eq(4, count($result['changes']));
});

test('FixBlocksStep leaves legacy no-shape markup to the fixer without shape warnings', function () {
    $fixer = new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            return '[fix-templates] legacy no-op';
        }
    };
    with_temp_dir('shape-legacy-', function (string $tmp) use ($fixer): void {
        $project = new Project($tmp);
        $original = '<!-- wp:image {"style":{"border":{"radius":"999px"}}} -->'
            . '<figure class="wp-block-image"><img src="photo.jpg" alt=""/></figure><!-- /wp:image -->';
        $project->writeText('theme/parts/section.html', $original);

        (new FixBlocksStep($fixer))->run($project);

        assert_eq($original, $project->readText('theme/parts/section.html'));
        assert_true(!$project->exists('warnings.json'), 'a pre-field direction produces no warning');
    });
});

test('FixBlocksStep logs equivalent-radius canonicalization without a durable warning', function () {
    $fixer = new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            return '[fix-templates] equivalent-radius no-op';
        }
    };
    with_temp_dir('shape-equivalent-', function (string $tmp) use ($fixer): void {
        $project = new Project($tmp);
        $project->writeJson('designDirection.json', ['shape' => 'soft']);
        $project->writeText(
            'theme/parts/section.html',
            '<!-- wp:image {"style":{"border":{"radius":".500rem","width":"1px"}}} -->'
                . '<figure class="wp-block-image"><img src="photo.jpg" alt=""/></figure><!-- /wp:image -->',
        );

        (new FixBlocksStep($fixer))->run($project);

        assert_true(!$project->exists('warnings.json'), 'equivalent visitor output is not actionable');
        $log = $project->readText('logs/fix-blocks.log');
        assert_contains('[shape] 1 corner-language normalization(s)', $log);
        assert_contains('style.border.radius ".500rem" -> removed', $log);
    });
});

test('FixBlocksStep reports malformed full-width state replacement without durable warning noise', function () {
    $fixer = new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            return '[fix-templates] malformed-style repaired';
        }
    };
    with_temp_dir('shape-malformed-warning-', function (string $tmp) use ($fixer): void {
        $project = new Project($tmp);
        $project->writeJson('designDirection.json', ['shape' => 'round']);
        $project->writeText(
            'theme/parts/section.html',
            '<!-- wp:image {"align":"full","style":null,"className":"keep"} -->'
                . '<figure class="wp-block-image alignfull keep"><img src="photo.jpg" alt=""/></figure>'
                . '<!-- /wp:image -->',
        );

        (new FixBlocksStep($fixer))->run($project);

        assert_true(!$project->exists('warnings.json'), 'fully repaired malformed state leaves no repair queue row');
        $log = $project->readText('logs/fix-blocks.log');
        assert_contains('parts/section.html block 0 (core/image)', $log);
        assert_contains('style null -> {"border":{"radius":"0"}}', $log);
        assert_contains('replaced malformed style container', $log);
    });
});

test('FixBlocksStep repairs malformed contained shape state before the real serializer', function () {
    with_temp_dir('shape-contained-malformed-', function (string $tmp): void {
        $project = new Project($tmp);
        $project->writeJson('designDirection.json', ['shape' => 'soft']);
        $project->writeJson('theme/theme.json', ['version' => 3]);
        $project->writeText(
            'theme/parts/section.html',
            '<!-- wp:image {"url":"/photo.jpg","alt":"Photo","caption":"",'
                . '"className":"is-style-rounded keep-image","style":null} -->'
                . '<figure class="wp-block-image is-style-rounded keep-image"><img src="/photo.jpg" alt="Photo"/>'
                . '</figure><!-- /wp:image -->'
                . '<!-- wp:button {"url":"#go","text":"Go","className":"is-style-squared keep-button",'
                . '"style":{"border":null,"typography":{"fontWeight":"700"}}} -->'
                . '<div class="wp-block-button is-style-squared keep-button"><a '
                . 'class="wp-block-button__link wp-element-button" href="#go" style="font-weight:700">Go</a></div>'
                . '<!-- /wp:button -->',
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $fixed = $project->readText('theme/parts/section.html');
        assert_contains('Photo', $fixed);
        assert_contains('>Go</a>', $fixed);
        assert_contains('keep-image', $fixed);
        assert_contains('keep-button', $fixed);
        assert_true(!str_contains($fixed, 'is-style-rounded'));
        assert_true(!str_contains($fixed, 'is-style-squared'));
        assert_true(!$project->exists('warnings.json'), 'complete repair avoids file rollback and warning');
        $log = $project->readText('logs/fix-blocks.log');
        assert_contains('style null -> removed', $log);
        assert_contains('style.border null -> removed', $log);
        assert_true(!str_contains($log, 'left parts/section.html unmodified'));
    });
});

test('FixBlocksStep reruns shape normalization after structural repair exposes a block', function () {
    $fixer = new class implements BlockFixer {
        public int $calls = 0;

        public function fix(string $themeDir): string
        {
            $this->calls++;
            $path = $themeDir . '/parts/section.html';
            if ($this->calls === 1) {
                file_put_contents(
                    $path,
                    '<!-- wp:image {"className":"is-style-rounded keep",'
                        . '"style":{"border":{"radius":"24px","width":"1px"}}} -->'
                        . '<figure class="wp-block-image is-style-rounded keep"><img src="photo.jpg" alt="" '
                        . 'style="border-width:1px;border-radius:24px"/></figure><!-- /wp:image -->',
                );
                return '[fix-templates] first pass balanced the image';
            }
            $markup = (string) file_get_contents($path);
            assert_true(!str_contains($markup, '"radius"'), 'post-repair pass removes the exposed radius');
            assert_true(!str_contains($markup, 'is-style-rounded'), 'post-repair pass removes the exposed class');
            file_put_contents(
                $path,
                '<!-- wp:image {"className":"keep","style":{"border":{"width":"1px"}}} -->'
                    . '<figure class="wp-block-image keep has-custom-border"><img src="photo.jpg" alt="" '
                    . 'style="border-width:1px"/></figure><!-- /wp:image -->',
            );
            return '[fix-templates] second pass serialized normalized image';
        }
    };

    with_temp_dir('shape-post-repair-', function (string $tmp) use ($fixer): void {
        $project = new Project($tmp);
        $project->writeJson('designDirection.json', ['shape' => 'soft']);
        $project->writeText(
            'theme/parts/section.html',
            '<!-- wp:image {"style":{"border":{"radius":"24px"}}} --><figure>',
        );

        (new FixBlocksStep($fixer))->run($project);

        assert_eq(2, $fixer->calls, 'shape work exposed by structural repair triggers the follow-up fixer');
        $fixed = $project->readText('theme/parts/section.html');
        assert_true(!str_contains($fixed, 'radius'));
        assert_contains('border-width:1px', $fixed, 'unrelated border styling survives');
        assert_true(!$project->exists('warnings.json'), 'resolved exposed overrides stay out of the repair queue');
        $log = $project->readText('logs/fix-blocks.log');
        assert_contains('parts/section.html block 0 (core/image)', $log);
        assert_contains('style.border.radius "24px" -> removed', $log);
        assert_contains('className token "is-style-rounded" -> removed', $log);
    });
});

test('FixBlocksStep filters rolled-back shape changes while healthy siblings continue', function () {
    with_temp_dir('shape-rollback-', function (string $tmp): void {
        $project = new Project($tmp);
        $project->writeJson('designDirection.json', ['shape' => 'sharp']);
        $project->writeJson('theme/theme.json', ['version' => 3]);
        $healthy = '<!-- wp:image {"style":{"border":{"radius":"8px","width":"1px"}}} -->'
            . '<figure class="wp-block-image has-custom-border"><img src="healthy.jpg" alt="" '
            . 'style="border-width:1px;border-radius:8px"/></figure><!-- /wp:image -->';
        $failed = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
            . '<!-- wp:image {"style":{"border":{"radius":"20px"}}} -->'
            . '<figure class="wp-block-image"><img src="failed.jpg" alt="" style="border-radius:20px"/></figure>'
            . '<!-- /wp:image -->'
            . '<!-- wp:query --><div class="wp-block-query"></div><!-- /wp:query -->'
            . '</div><!-- /wp:group -->';
        $project->writeText('theme/parts/a-healthy.html', $healthy);
        $project->writeText('theme/parts/b-failed.html', $failed);

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        assert_eq($failed, $project->readText('theme/parts/b-failed.html'), 'failed file restores entry bytes');
        assert_true(
            !str_contains($project->readText('theme/parts/a-healthy.html'), 'border-radius:8px'),
            'healthy sibling keeps its shape normalization',
        );
        $warnings = $project->readJson('warnings.json')['fix-blocks'] ?? [];
        $joinedWarnings = implode("\n", $warnings);
        assert_contains(
            'parts/b-failed.html block 0/0 (core/image): corner property style.border.radius; '
                . 'authored "20px"; delivered "20px" (pre-step value restored); '
                . 'disposition shape normalization rolled back',
            $joinedWarnings,
            'the rolled-back corner conflict remains actionable on its own',
        );
        assert_true(
            !str_contains($joinedWarnings, 'parts/a-healthy.html block 0 (core/image): corner property'),
            'successfully repaired siblings do not enter warnings.json',
        );
        assert_contains(
            'left parts/b-failed.html unmodified',
            $joinedWarnings,
            'the actual rollback remains actionable',
        );
        $log = $project->readText('logs/fix-blocks.log');
        assert_contains('[shape] 1 corner-language normalization(s)', $log);
        assert_contains('parts/a-healthy.html block 0 (core/image): style.border.radius "8px" -> removed', $log);
        assert_true(
            !str_contains($log, 'parts/b-failed.html block 0/0 (core/image): style.border.radius'),
            'rolled-back shape work is filtered from the delivered repair report',
        );
        assert_contains(
            'parts/b-failed.html block 0/0 (core/image): corner property style.border.radius',
            $log,
            'the rollback evidence is retained in the warning section of the step log',
        );

        $delivered = $project->readText('theme/parts/a-healthy.html');
        $beforeWarnings = $project->readJson('warnings.json');
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);
        assert_eq($delivered, $project->readText('theme/parts/a-healthy.html'));
        assert_eq($beforeWarnings, $project->readJson('warnings.json'), 'fixed point adds no warning rows');
    });
});

test('FixBlocksStep does not report an intermediate shape value after follow-up rollback', function () {
    $fixer = new class implements ReportingBlockFixer {
        public int $calls = 0;

        public function fix(string $themeDir): string
        {
            throw new LogicException('typed contract expected');
        }

        public function fixReport(string $themeDir): FixerReport
        {
            $this->calls++;
            $path = $themeDir . '/parts/section.html';
            if ($this->calls === 1) {
                file_put_contents(
                    $path,
                    '<!-- wp:image {"style":{"border":{"radius":"42px"}}} -->'
                        . '<figure class="wp-block-image"><img src="exposed.jpg" alt="" '
                        . 'style="border-radius:42px"/></figure><!-- /wp:image -->',
                );
                return new FixerReport([new FileReport('parts/section.html', 'fixed')]);
            }

            return new FixerReport([new FileReport(
                'parts/section.html',
                'failed',
                error: 'follow-up serializer rejected the exposed block',
            )]);
        }
    };

    with_temp_dir('shape-follow-up-rollback-', function (string $tmp) use ($fixer): void {
        $project = new Project($tmp);
        $project->writeJson('designDirection.json', ['shape' => 'soft']);
        $original = '<!-- wp:group --><div class="wp-block-group">unfinished original';
        $project->writeText('theme/parts/section.html', $original);

        (new FixBlocksStep($fixer))->run($project);

        assert_eq(2, $fixer->calls, 'the exposed radius triggers a follow-up serializer pass');
        assert_eq($original, $project->readText('theme/parts/section.html'), 'entry bytes are restored');
        $warnings = implode("\n", $project->readJson('warnings.json')['fix-blocks'] ?? []);
        assert_contains('left parts/section.html unmodified', $warnings);
        assert_true(
            !str_contains($warnings, 'corner property'),
            'an intermediate-only shape conflict is not described as delivered',
        );
        assert_true(!str_contains($warnings, '42px'), 'the restored entry bytes never carried this value');

        $log = $project->readText('logs/fix-blocks.log');
        assert_true(!str_contains($log, 'corner property'), 'the step log uses the same entry-byte evidence');
        assert_true(!str_contains($log, '42px'), 'intermediate shape state is not reported as delivered');
    });
});

test('FixBlocksStep keeps an already-normalized shape fixed point warning-free', function () {
    $fixer = new class implements ReportingBlockFixer {
        public function fix(string $themeDir): string
        {
            throw new LogicException('typed contract expected');
        }

        public function fixReport(string $themeDir): FixerReport
        {
            return new FixerReport([new FileReport('parts/section.html', 'ok')]);
        }
    };

    with_temp_dir('shape-clean-fixed-point-', function (string $tmp) use ($fixer): void {
        $project = new Project($tmp);
        $project->writeJson('designDirection.json', ['shape' => 'round']);
        $normalized = '<!-- wp:image {"align":"full","style":{"border":{"radius":"0"}}} -->'
            . '<figure class="wp-block-image alignfull"><img src="full.jpg" alt="" style="border-radius:0"/>'
            . '</figure><!-- /wp:image -->'
            . '<!-- wp:button {"url":"#go","text":"Go"} --><div class="wp-block-button"><a '
            . 'class="wp-block-button__link wp-element-button" href="#go">Go</a></div><!-- /wp:button -->';
        $project->writeText('theme/parts/section.html', $normalized);

        (new FixBlocksStep($fixer))->run($project);
        (new FixBlocksStep($fixer))->run($project);

        assert_eq($normalized, $project->readText('theme/parts/section.html'));
        assert_true(!$project->exists('warnings.json'));
    });
});

test('shape kit css wires rounded contained cover and media-text surfaces and exempts alignfull', function () {
    foreach ([null, '', 'wavy', 'sharp'] as $shape) {
        assert_true(ShapeMarkup::kitCss($shape) === null, var_export($shape, true));
    }

    $soft = ShapeMarkup::kitCss('soft');
    assert_contains('.wp-block-media-text:not(.alignfull) .wp-block-media-text__media,', $soft);
    assert_contains('.wp-block-media-text:not(.alignfull) .wp-block-media-text__media img,', $soft);
    assert_contains('.wp-block-media-text:not(.alignfull) .wp-block-media-text__media video', $soft);
    assert_contains('.wp-block-cover:not(.alignfull)', $soft);
    assert_contains('border-radius: 0.5rem', $soft);
    assert_contains('overflow: hidden', $soft);
    assert_true(!str_contains($soft, '9999px'), 'media surfaces never take the pill radius');

    $round = ShapeMarkup::kitCss(' ROUND ');
    assert_contains('border-radius: 1.25rem', $round);
    assert_true(!str_contains($round, '0.5rem'));
});

test('shape markup strips authored cover and media-text radii for every alignment', function () {
    $markup = '<!-- wp:cover {"url":"a.jpg","align":"full","dimRatio":30,"style":{"border":{"radius":"12px"}}} -->'
        . '<div class="wp-block-cover alignfull"><img class="wp-block-cover__image-background" src="a.jpg" alt=""/>'
        . '<div class="wp-block-cover__inner-container"></div></div>'
        . '<!-- /wp:cover -->'
        . '<!-- wp:cover {"url":"b.jpg","style":{"border":{"radius":"0.5rem"}}} -->'
        . '<div class="wp-block-cover"><img class="wp-block-cover__image-background" src="b.jpg" alt=""/>'
        . '<div class="wp-block-cover__inner-container"></div></div>'
        . '<!-- /wp:cover -->'
        . '<!-- wp:media-text {"align":"wide","mediaType":"image","style":{"border":{"radius":"1rem","width":"1px"}}} -->'
        . '<div class="wp-block-media-text alignwide"><figure class="wp-block-media-text__media">'
        . '<img src="c.jpg" alt=""/></figure><div class="wp-block-media-text__content"></div></div>'
        . '<!-- /wp:media-text -->';

    $result = ShapeMarkup::normalize($markup, 'soft');
    $doc = BlockMarkup::parse($result['markup']);
    $radii = [];
    foreach ($doc->indices() as $i) {
        $attrs = $doc->attrs($i) ?? [];
        $radii[] = $attrs['style']['border']['radius'] ?? null;
    }
    assert_eq([null, null, null], $radii, 'no cover/media-text keeps a local radius');

    // Unrelated border siblings survive the smallest-unit removal.
    $mediaText = $doc->attrs($doc->indices()[2]) ?? [];
    assert_eq('1px', $mediaText['style']['border']['width'] ?? null);

    $dispositions = array_column($result['changes'], 'disposition');
    assert_eq(3, count($dispositions));
    foreach ($dispositions as $disposition) {
        assert_contains('authoritative theme radius', $disposition);
    }
    // The contained cover's 0.5rem matches the committed soft radius exactly.
    assert_contains('removed redundant local radius', implode(' ', $dispositions));

    // Fixed point: a second pass reports nothing.
    $second = ShapeMarkup::normalize($result['markup'], 'soft');
    assert_eq($result['markup'], $second['markup']);
    assert_eq([], $second['changes']);
});

test('shape markup polices media-text per-block css that rounds the owned media surface', function () {
    $markup = '<!-- wp:media-text {"align":"wide","mediaType":"image","style":{"css":'
        . '"& .wp-block-media-text__media img { border-radius: 24px; } '
        . '& .wp-block-media-text__content { padding-top: 4px; }"}} -->'
        . '<div class="wp-block-media-text alignwide"><figure class="wp-block-media-text__media">'
        . '<img src="c.jpg" alt=""/></figure><div class="wp-block-media-text__content"></div></div>'
        . '<!-- /wp:media-text -->';

    $result = ShapeMarkup::normalize($markup, 'round');
    $doc = BlockMarkup::parse($result['markup']);
    $css = ($doc->attrs($doc->indices()[0]) ?? [])['style']['css'] ?? '';
    assert_true(!str_contains($css, 'border-radius'), 'the owned corner declaration is removed');
    assert_contains('padding-top: 4px', $css);

    $changes = $result['changes'];
    assert_eq(1, count($changes));
    assert_contains('media-text corner language', $changes[0]['disposition']);
});
