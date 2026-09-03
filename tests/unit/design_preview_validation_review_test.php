<?php
declare(strict_types=1);

test('design-preview sanitizes a removable script without an LLM repair', function () {
    [$project, $llm, $tmp] = design_preview_fixture();
    $authored = str_replace(
        '</header>',
        '</header><script>globalThis.previewAttack = true;</script>',
        design_preview_document('AUTHORED-SCRIPT-MARKER'),
    );
    $llm->queueText($authored);

    design_preview_run($project, $llm);

    $delivered = $project->readText('design/preview.html');
    $warnings = design_preview_warnings($project);
    $completeCalls = $llm->completeCalls;
    $allCalls = count($llm->calls);
    design_preview_cleanup($tmp);

    assert_eq(1, $completeCalls, 'removable script uses generation call only');
    assert_eq(1, $allCalls, 'removable script does not trigger repair');
    assert_contains('AUTHORED-SCRIPT-MARKER', $delivered, 'sanitizer preserves authored content');
    assert_true(!str_contains(strtolower($delivered), '<script'), 'script is removed');
    assert_contains('file design/preview.html', $warnings, 'warning identifies delivered file');
    assert_contains('block_path document', $warnings, 'warning identifies document boundary');
    assert_contains('authored_value', $warnings, 'warning records authored value');
    assert_contains('delivered_value removed', $warnings, 'warning records removed delivery');
    assert_contains('disposition removed', $warnings, 'unsafe script removal is recorded');
    assert_true(!str_contains($warnings, 'disposition degraded'), 'safe scaffold is not used');
    design_preview_assert_shape($delivered);
});

$designPreviewEscapedCssAttacks = [
    'escaped url function' => [
        'css' => '.hero { background-image: u\\72l("https://cdn.example.test/hero.jpg"); }',
        'needle' => 'u\\72l(',
    ],
    'escaped import keyword' => [
        'css' => '@\\69mport "https://cdn.example.test/preview.css";',
        'needle' => '@\\69mport',
    ],
    'escaped font-face keyword' => [
        'css' => '@font\\2d face { font-family: Remote; src: local("Remote"); }',
        'needle' => '@font\\2d face',
    ],
];

foreach ($designPreviewEscapedCssAttacks as $name => $attack) {
    test("design-preview rejects {$name} and accepts one safe repair", function () use ($name, $attack) {
        [$project, $llm, $tmp] = design_preview_fixture();
        $authored = str_replace(
            '</style>',
            $attack['css'] . '</style>',
            design_preview_document('AUTHORED-ESCAPED-CSS'),
        );
        $safeRepair = design_preview_document('SAFE-ESCAPED-CSS-REPAIR');
        assert_contains($attack['needle'], $authored, "{$name} fixture carries escaped syntax");
        $llm->queueText($authored);
        $llm->queueText($safeRepair);

        design_preview_run($project, $llm);

        $delivered = $project->readText('design/preview.html');
        $warnings = design_preview_warnings($project);
        $completeCalls = $llm->completeCalls;
        $allCalls = count($llm->calls);
        design_preview_cleanup($tmp);

        assert_eq(2, $completeCalls, "{$name} triggers exactly one repair call");
        assert_eq(2, $allCalls, "{$name} has generation plus repair only");
        assert_eq($safeRepair, $delivered, "{$name} cannot ship as final output");
        assert_true(!str_contains($delivered, $attack['needle']), "{$name} is absent from delivery");
        assert_contains('SAFE-ESCAPED-CSS-REPAIR', $delivered, "{$name} delivers safe repair");
        assert_contains('disposition repaired', $warnings, "{$name} repair is recorded");
        design_preview_assert_shape($delivered);
    });
}

function design_preview_review_inject_css(string $html, string $css): string
{
    return str_replace('</style>', $css . '</style>', $html);
}

function design_preview_review_add_img_attribute(string $html, string $attribute): string
{
    return str_replace('<img ', '<img ' . $attribute . ' ', $html);
}

function design_preview_review_assert_no_dependency_markup(string $html): void
{
    $dom = Automattic\SiteBuild\Html::loadUtf8Html(
        $html,
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
    );
    assert_true($dom instanceof DOMDocument, 'delivered preview parses');
    $xpath = new DOMXPath($dom);
    assert_eq(
        0,
        $xpath->query('//img[@src or @srcset or @sizes]')->length,
        'delivered image omits src, srcset, and sizes',
    );
    assert_eq(0, $xpath->query('//picture|//source')->length, 'no picture or source dependency constructs');
    assert_eq(0, $xpath->query('//*[@style]')->length, 'all CSS stays in head style element');
    assert_eq(0, $xpath->query('//*[@background]')->length, 'no legacy background fetch attributes');
    assert_eq(
        0,
        $xpath->query('//*[@popover or @popovertarget or @popovertargetaction or @command or @commandfor]')->length,
        'no declarative behavior attributes',
    );
    assert_eq(
        0,
        $xpath->query('//*[@contenteditable or @draggable or @autofocus]')->length,
        'no editing, dragging, or autofocus behavior attributes',
    );
}

$designPreviewDependencyDefects = [
    'inline style attribute' => static fn (string $html): string => str_replace(
        '<h1>',
        '<h1 style="color: #251d16">',
        $html,
    ),
    'inline style attribute with remote URL' => static fn (string $html): string => str_replace(
        '<h1>',
        '<h1 style="background-image: url(https://cdn.example.test/hero.jpg)">',
        $html,
    ),
    'head CSS image-set remote string' => static fn (string $html): string => design_preview_review_inject_css(
        $html,
        '.hero { background-image: image-set("https://cdn.example.test/hero.jpg" 1x); }',
    ),
    'head CSS image-set relative string' => static fn (string $html): string => design_preview_review_inject_css(
        $html,
        '.hero { background-image: image-set("hero.jpg" 1x); }',
    ),
    'head CSS image-set data string' => static fn (string $html): string => design_preview_review_inject_css(
        $html,
        '.hero { background-image: image-set("data:image/png;base64,AAAA" 1x); }',
    ),
    'head CSS webkit image-set' => static fn (string $html): string => design_preview_review_inject_css(
        $html,
        '.hero { background-image: -webkit-image-set("hero.jpg" 1x); }',
    ),
    'head CSS image function' => static fn (string $html): string => design_preview_review_inject_css(
        $html,
        '.hero { background-image: image("https://cdn.example.test/hero.jpg"); }',
    ),
    'head CSS cross-fade function' => static fn (string $html): string => design_preview_review_inject_css(
        $html,
        '.hero { background-image: cross-fade(linear-gradient(red, blue), linear-gradient(black, white), 50%); }',
    ),
    'head CSS src function' => static fn (string $html): string => design_preview_review_inject_css(
        $html,
        '.hero { background-image: src("https://cdn.example.test/hero.jpg"); }',
    ),
    'head CSS element function' => static fn (string $html): string => design_preview_review_inject_css(
        $html,
        '.hero { background-image: element(#hero-art); }',
    ),
    'head CSS paint function' => static fn (string $html): string => design_preview_review_inject_css(
        $html,
        '.hero { background-image: paint(hero-background); }',
    ),
    'image empty src' => static fn (string $html): string => design_preview_review_add_img_attribute(
        $html,
        'src=""',
    ),
    'image relative src' => static fn (string $html): string => design_preview_review_add_img_attribute(
        $html,
        'src="hero.jpg"',
    ),
    'image data src' => static fn (string $html): string => design_preview_review_add_img_attribute(
        $html,
        'src="data:image/png;base64,AAAA"',
    ),
    'image theme src' => static fn (string $html): string => design_preview_review_add_img_attribute(
        $html,
        'src="theme:./assets/hero.jpg"',
    ),
    'image https colon without slashes src' => static fn (string $html): string => design_preview_review_add_img_attribute(
        $html,
        'src="https:cdn.example.test/hero.jpg"',
    ),
    'image backslash scheme src' => static fn (string $html): string => design_preview_review_add_img_attribute(
        $html,
        'src="https:\\cdn.example.test\\hero.jpg"',
    ),
    'image srcset' => static fn (string $html): string => design_preview_review_add_img_attribute(
        $html,
        'srcset="/hero-1.jpg 1x, /hero-2.jpg 2x"',
    ),
    'image sizes' => static fn (string $html): string => design_preview_review_add_img_attribute(
        $html,
        'sizes="100vw"',
    ),
    'picture wrapper' => static function (string $html): string {
        $html = str_replace('<img ', '<picture><img ', $html);
        return str_replace('</section>', '</picture></section>', $html);
    },
    'source element' => static fn (string $html): string => str_replace(
        '<img ',
        '<source srcset="/hero.jpg 1x"><img ',
        $html,
    ),
    'body relative background attribute' => static fn (string $html): string => str_replace(
        '<body>',
        '<body background="hero.jpg">',
        $html,
    ),
    'table relative background attribute' => static fn (string $html): string => str_replace(
        '</section>',
        '<table background="hero.jpg"><tbody><tr><td>Preview</td></tr></tbody></table></section>',
        $html,
    ),
    'table cell relative background attribute' => static fn (string $html): string => str_replace(
        '</section>',
        '<table><tbody><tr><td background="hero.jpg">Preview</td></tr></tbody></table></section>',
        $html,
    ),
    'popover target behavior' => static fn (string $html): string => str_replace(
        '</section>',
        '<button popovertarget="preview-popover">Open</button>'
            . '<div id="preview-popover" popover>Details</div></section>',
        $html,
    ),
    'command target behavior' => static fn (string $html): string => str_replace(
        '</section>',
        '<button commandfor="preview-popover" command="show-popover">Open</button>'
            . '<div id="preview-popover" popover>Details</div></section>',
        $html,
    ),
    'contenteditable behavior' => static fn (string $html): string => str_replace(
        '</section>',
        '<p contenteditable="true">Editable preview copy</p></section>',
        $html,
    ),
    'draggable behavior' => static fn (string $html): string => str_replace(
        '</section>',
        '<p draggable="true">Draggable preview copy</p></section>',
        $html,
    ),
    'autofocus behavior' => static fn (string $html): string => str_replace(
        '</section>',
        '<button autofocus>Focused action</button></section>',
        $html,
    ),
];

$designPreviewDeterministicDependencyRemovals = [
    'image data src' => true,
    'image theme src' => true,
    // The design engine drops an inline style that loads a resource at the
    // write, so no repair call is spent on it (BIGR-970).
    'inline style attribute with remote URL' => true,
    // A media source that names another host, in any spelling the browser
    // resolves, is removed at the write (BIGR-975).
    'image https colon without slashes src' => true,
    'image backslash scheme src' => true,
];

foreach ($designPreviewDependencyDefects as $name => $mutate) {
    test(
        "design-preview resolves {$name} before delivery",
        function () use ($name, $mutate, $designPreviewDeterministicDependencyRemovals) {
            [$project, $llm, $tmp] = design_preview_fixture();
            $base = design_preview_document('AUTHORED-DEPENDENCY-DEFECT');
            $authored = $mutate($base);
            $safeRepair = design_preview_document('SAFE-DEPENDENCY-REPAIR');
            assert_true($authored !== $base, "{$name} fixture carries defect");
            $llm->queueText($authored);
            $llm->queueText($safeRepair);

            design_preview_run($project, $llm);

            $delivered = $project->readText('design/preview.html');
            $warnings = design_preview_warnings($project);
            $completeCalls = $llm->completeCalls;
            $allCalls = count($llm->calls);
            design_preview_cleanup($tmp);

            $deterministicRemoval = isset($designPreviewDeterministicDependencyRemovals[$name]);
            $expectedCalls = $deterministicRemoval ? 1 : 2;
            assert_eq($expectedCalls, $completeCalls, "{$name} uses bounded LLM calls");
            assert_eq($expectedCalls, $allCalls, "{$name} records bounded LLM calls");
            if ($deterministicRemoval) {
                assert_contains(
                    'AUTHORED-DEPENDENCY-DEFECT',
                    $delivered,
                    "{$name} preserves authored content around removed source",
                );
                assert_contains('disposition removed', $warnings, "{$name} removal is recorded");
            } else {
                assert_eq($safeRepair, $delivered, "{$name} delivers exact safe repair");
                assert_contains('disposition repaired', $warnings, "{$name} repair is recorded");
            }
            design_preview_review_assert_no_dependency_markup($delivered);
            design_preview_assert_shape($delivered);
        },
    );
}

test('design-preview accepts inert URL text and a linear gradient in one call', function () {
    [$project, $llm, $tmp] = design_preview_fixture();
    $authored = design_preview_review_inject_css(
        design_preview_document('SAFE-INERT-CSS'),
        '.hero::before { content: "https://example.test"; '
            . 'background: linear-gradient(135deg, #fff8ea, #e08a3c); }',
    );
    $llm->queueText($authored);

    design_preview_run($project, $llm);

    $delivered = $project->readText('design/preview.html');
    $warnings = design_preview_warnings($project);
    $completeCalls = $llm->completeCalls;
    $allCalls = count($llm->calls);
    design_preview_cleanup($tmp);

    assert_eq(1, $completeCalls, 'inert CSS uses generation call only');
    assert_eq(1, $allCalls, 'inert CSS does not trigger repair');
    assert_eq($authored, $delivered, 'inert CSS ships byte-identically');
    assert_eq('', $warnings, 'inert CSS needs no warning');
    design_preview_review_assert_no_dependency_markup($delivered);
    design_preview_assert_shape($delivered);
});

$designPreviewHarmlessCssControls = [
    'content string containing url token' => '.hero::before { content: "url("; color: #251d16; }',
    'content string containing image-set token' => '.hero::before { content: "image-set("; color: #251d16; }',
    'selector string containing paint token' => '[data-copy="paint("] { color: #251d16; }',
    'content string containing comment token' => '.hero::before { content: "/* copy */"; color: #251d16; }',
    'content string containing import token' => '.hero::before { content: "@import"; color: #251d16; }',
    'content string containing font-face token' => '.hero::before { content: "@font-face"; color: #251d16; }',
];

foreach ($designPreviewHarmlessCssControls as $name => $css) {
    test("design-preview accepts {$name} in one call", function () use ($name, $css) {
        [$project, $llm, $tmp] = design_preview_fixture();
        $authored = design_preview_review_inject_css(
            design_preview_document('SAFE-INERT-CSS-TOKEN'),
            $css,
        );
        $llm->queueText($authored);

        design_preview_run($project, $llm);

        $delivered = $project->readText('design/preview.html');
        $warnings = design_preview_warnings($project);
        $completeCalls = $llm->completeCalls;
        $allCalls = count($llm->calls);
        design_preview_cleanup($tmp);

        assert_eq(1, $completeCalls, "{$name} uses generation call only");
        assert_eq(1, $allCalls, "{$name} does not trigger repair");
        assert_eq($authored, $delivered, "{$name} ships byte-identically");
        assert_eq('', $warnings, "{$name} needs no warning");
        design_preview_review_assert_no_dependency_markup($delivered);
    });
}

$designPreviewWidthBypasses = [
    'required width text hidden in inert content' => static function (string $html): string {
        $html = str_replace(
            ['--content-size: 800px;', '--wide-size: 1280px;'],
            ['--content-size: 10px;', '--wide-size: 20px;'],
            $html,
        );
        return design_preview_review_inject_css(
            $html,
            '.hero::before { content: "--content-size: 800px; --wide-size: 1280px;"; }',
        );
    },
    'later root declaration overrides required widths' => static fn (string $html): string => design_preview_review_inject_css(
        $html,
        ':root { --content-size: 10px; --wide-size: 20px; }',
    ),
];

foreach ($designPreviewWidthBypasses as $name => $mutate) {
    test("design-preview repairs {$name}", function () use ($name, $mutate) {
        [$project, $llm, $tmp] = design_preview_fixture();
        $base = design_preview_document('AUTHORED-WIDTH-BYPASS');
        $authored = $mutate($base);
        $safeRepair = design_preview_document('SAFE-WIDTH-REPAIR');
        assert_true($authored !== $base, "{$name} fixture carries bypass");
        $llm->queueText($authored);
        $llm->queueText($safeRepair);

        design_preview_run($project, $llm);

        $delivered = $project->readText('design/preview.html');
        $warnings = design_preview_warnings($project);
        $completeCalls = $llm->completeCalls;
        $allCalls = count($llm->calls);
        design_preview_cleanup($tmp);

        assert_eq(2, $completeCalls, "{$name} triggers exactly one repair call");
        assert_eq(2, $allCalls, "{$name} has generation plus repair only");
        assert_eq($safeRepair, $delivered, "{$name} delivers exact safe repair");
        assert_contains('disposition repaired', $warnings, "{$name} repair is recorded");
        design_preview_review_assert_no_dependency_markup($delivered);
        design_preview_assert_shape($delivered);
    });
}

test('design-preview records a long multibyte malformed initial response after valid repair', function () {
    [$project, $llm, $tmp] = design_preview_fixture();
    $malformed = str_repeat("😀", 100);
    $safeRepair = design_preview_document('SAFE-MULTIBYTE-REPAIR');
    $llm->queueText($malformed);
    $llm->queueText($safeRepair);

    $caught = null;
    try {
        design_preview_run($project, $llm);
    } catch (Throwable $error) {
        $caught = $error;
    }

    $previewExists = $project->exists('design/preview.html');
    $delivered = $previewExists ? $project->readText('design/preview.html') : '';
    $warningsExist = $project->exists('warnings.json');
    $warningData = null;
    $warningReadError = null;
    if ($warningsExist) {
        try {
            $warningData = $project->readJson('warnings.json');
        } catch (Throwable $error) {
            $warningReadError = $error;
        }
    }
    design_preview_cleanup($tmp);

    assert_true(
        $caught === null,
        'long multibyte repaired warning does not throw'
            . ($caught === null ? '' : ': ' . $caught::class . ': ' . $caught->getMessage()),
    );
    assert_eq(2, $llm->completeCalls, 'multibyte malformed response gets one repair call');
    assert_eq(2, count($llm->calls), 'multibyte repaired path caps calls at two');
    assert_true($previewExists, 'repaired preview artifact exists');
    assert_eq($safeRepair, $delivered, 'valid repair is delivered exactly');
    design_preview_assert_shape($delivered);
    assert_true($warningsExist, 'warnings.json exists after repaired multibyte defect');
    assert_true(
        $warningReadError === null,
        'warnings.json remains readable JSON after repaired multibyte defect'
            . ($warningReadError === null ? '' : ': ' . $warningReadError->getMessage()),
    );
    assert_true(is_array($warningData), 'warnings.json decodes to a map');
    $warnings = $warningData['design-preview'] ?? null;
    assert_true(is_array($warnings) && array_is_list($warnings), 'design-preview warnings are a list');
    $warningText = implode(' ', $warnings);
    assert_contains('file design/preview.html', $warningText);
    assert_contains('block_path document', $warningText);
    assert_contains('authored_value', $warningText);
    assert_contains('delivered_value', $warningText);
    assert_contains('disposition repaired', $warningText);
});

test('design-preview records a long multibyte malformed response before safe scaffold degradation', function () {
    [$project, $llm, $tmp] = design_preview_fixture();
    $malformed = str_repeat("😀", 100);
    $llm->queueText($malformed);
    $llm->queueText('MALFORMED-MULTIBYTE-REPAIR');

    $caught = null;
    try {
        design_preview_run($project, $llm);
    } catch (Throwable $error) {
        $caught = $error;
    }

    $previewExists = $project->exists('design/preview.html');
    $delivered = $previewExists ? $project->readText('design/preview.html') : '';
    $warningsExist = $project->exists('warnings.json');
    $warningData = null;
    $warningReadError = null;
    if ($warningsExist) {
        try {
            $warningData = $project->readJson('warnings.json');
        } catch (Throwable $error) {
            $warningReadError = $error;
        }
    }
    design_preview_cleanup($tmp);

    assert_true(
        $caught === null,
        'long multibyte degraded warning does not throw'
            . ($caught === null ? '' : ': ' . $caught::class . ': ' . $caught->getMessage()),
    );
    assert_eq(2, $llm->completeCalls, 'multibyte malformed response gets one failed repair call');
    assert_eq(2, count($llm->calls), 'multibyte degraded path caps calls at two');
    assert_true($previewExists, 'safe scaffold artifact exists');
    design_preview_assert_shape($delivered);
    assert_true($warningsExist, 'warnings.json exists after degraded multibyte defect');
    assert_true(
        $warningReadError === null,
        'warnings.json remains readable JSON after degraded multibyte defect'
            . ($warningReadError === null ? '' : ': ' . $warningReadError->getMessage()),
    );
    assert_true(is_array($warningData), 'warnings.json decodes to a map');
    $warnings = $warningData['design-preview'] ?? null;
    assert_true(is_array($warnings) && array_is_list($warnings), 'design-preview warnings are a list');
    $warningText = implode(' ', $warnings);
    assert_contains('file design/preview.html', $warningText);
    assert_contains('block_path document', $warningText);
    assert_contains('authored_value', $warningText);
    assert_contains('delivered_value', $warningText);
    assert_contains('safe scaffold', $warningText);
    assert_contains('disposition degraded', $warningText);
});
