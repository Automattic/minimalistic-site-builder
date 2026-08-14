<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\Html;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepComposition;
use Automattic\SiteBuild\Steps\DesignPreviewStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/** @return array{0:Project,1:FakeLlm,2:string} */
function design_preview_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_design_preview_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', [
        'prompt' => 'A neighborhood bakery with seasonal bread and classes',
    ]);
    $project->writeJson('siteSpec.json', [
        'name' => 'Hearth & Crumb',
        'description' => 'Neighborhood bread and pastry studio',
    ]);
    $project->writeJson('designDirection.json', [
        'direction' => [
            'title' => 'Flour Archive',
            'description' => 'Warm editorial layouts with documentary bakery imagery.',
        ],
    ]);
    return [$project, new FakeLlm(), $tmp];
}

function design_preview_document(string $marker = 'DESIGN-PREVIEW'): string
{
    return '<!doctype html>'
        . '<html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>:root { --content-size: 800px; --wide-size: 1280px; }'
        . 'body { margin: 0; font-family: system-ui, sans-serif; }'
        . 'main { max-width: var(--wide-size); margin-inline: auto; }</style>'
        . '</head><body>'
        . '<header><nav aria-label="Primary"><a href="/menu">Menu</a></nav></header>'
        . '<main><section id="hero"><h1>' . $marker . '</h1>'
        . '<img alt="AI_IMAGE: A baker sliding a sourdough loaf into a stone oven, viewed from counter height | homepage hero beside the primary headline | photorealistic | landscape">'
        . '</section></main>'
        . '</body></html>';
}

function design_preview_css(): string
{
    return ':root { --content-size: 800px; --wide-size: 1280px; }'
        . 'body { margin: 0; font-family: system-ui, sans-serif; }'
        . 'main { max-width: var(--wide-size); margin-inline: auto; }';
}

function design_preview_run(
    Project $project,
    FakeLlm $llm,
    ?string $model = null,
    ?float $temperature = null,
): DesignPreviewStep {
    $step = new DesignPreviewStep(
        $llm,
        new PromptRenderer(repo_path('prompts')),
        $model,
        $temperature,
    );
    $step->run($project);
    return $step;
}

function design_preview_cleanup(string $tmp): void
{
    exec('rm -rf ' . escapeshellarg($tmp));
}

function design_preview_warnings(Project $project): string
{
    if (!$project->exists('warnings.json')) {
        return '';
    }
    return implode(' ', $project->readJson('warnings.json')['design-preview'] ?? []);
}

function design_preview_assert_shape(string $html): void
{
    $dom = Html::loadUtf8Html($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    assert_true($dom instanceof DOMDocument, 'preview parses as one HTML document');
    $xpath = new DOMXPath($dom);

    $body = $xpath->query('/html/body')->item(0);
    assert_true($body instanceof DOMElement, 'preview has one body');
    $bodyElements = [];
    foreach ($body->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $bodyElements[] = strtolower($child->tagName);
        } elseif ($child instanceof DOMText) {
            assert_eq('', trim($child->textContent), 'body has no direct visitor text');
        }
    }
    assert_eq(['header', 'main'], $bodyElements, 'body is header followed by first-fold main only');

    assert_eq(1, $xpath->query('//header')->length, 'one total header');
    assert_eq(1, $xpath->query('//nav')->length, 'one total nav');
    assert_eq(1, $xpath->query('/html/body/header')->length, 'one header');
    assert_eq(1, $xpath->query('/html/body/header//nav')->length, 'one header nav');
    assert_eq(1, $xpath->query('/html/body/main')->length, 'one main');
    $main = $xpath->query('/html/body/main')->item(0);
    assert_true($main instanceof DOMElement, 'preview has one main element');
    foreach ($main->childNodes as $child) {
        if ($child instanceof DOMText) {
            assert_eq('', trim($child->textContent), 'main has no direct visitor text outside hero');
        }
    }
    assert_eq(1, $xpath->query('/html/body/main/*')->length, 'main contains only hero');
    assert_eq(1, $xpath->query('/html/body/main/section[@id="hero"]')->length, 'hero landmark');
    assert_eq(1, $xpath->query('//section')->length, 'no content sections below hero');
    assert_eq(0, $xpath->query('//footer')->length, 'no footer');

    $images = $xpath->query('//img');
    assert_eq(1, $images->length, 'one total image');
    $image = $images->item(0);
    assert_true($image instanceof DOMElement, 'hero image exists');
    assert_eq(
        1,
        preg_match(
            '/^AI_IMAGE: [^|]+ \| [^|]+ \| (?:photorealistic|digital-art|illustration|minimalist|flat-design|3d-render|abstract|watercolor) \| (?:square|landscape|portrait)$/D',
            $image->getAttribute('alt'),
        ),
        'image alt uses exact four-field AI_IMAGE convention',
    );
    assert_eq(1, $xpath->query('/html/body/main/section[@id="hero"]//img')->length, 'image belongs to hero');

    $styles = $xpath->query('/html/head/style');
    assert_eq(1, $styles->length, 'one inline head style');
    assert_eq(1, $xpath->query('//style')->length, 'no style outside head');
    $css = (string) $styles->item(0)?->textContent;
    assert_eq(1, preg_match('/--content-size\s*:\s*800px\s*;/', $css), 'content width frozen');
    assert_eq(1, preg_match('/--wide-size\s*:\s*1280px\s*;/', $css), 'wide width frozen');
    assert_true(!str_contains($css, '/*') && !str_contains($css, '*/'), 'no CSS comments');
    assert_true(stripos($css, '@import') === false, 'no CSS import');
    assert_true(stripos($css, '@font-face') === false, 'no external font face');
    assert_true(stripos($css, 'url(') === false, 'no CSS dependency URL');

    assert_eq(0, $xpath->query('//script|//link|//iframe')->length, 'no scripts or external dependency elements');
    assert_eq(0, $xpath->query('//comment()')->length, 'no HTML comments');
    foreach ($xpath->query('//*[@*]') as $element) {
        assert_true($element instanceof DOMElement);
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->name);
            $value = trim($attribute->value);
            assert_true(!str_starts_with($name, 'on'), "no JavaScript attribute {$name}");
            assert_true(!str_starts_with(strtolower($value), 'javascript:'), 'no javascript URL');
            assert_true(
                preg_match('~^(?:https?:)?//~i', $value) !== 1,
                "no external dependency URL {$value}",
            );
        }
    }
}

test('design-preview freezes its public id and declaration', function () {
    $llm = new FakeLlm();
    $step = new DesignPreviewStep($llm, new PromptRenderer(repo_path('prompts')));
    $declaration = $step->declaration();

    assert_eq('design-preview', $step->id());
    assert_eq(['design/preview.html', 'design/site.css', 'warnings.json'], $declaration->writes);
    assert_eq(false, $declaration->concurrent);
});

test('design-preview makes one configured text call and writes exact preview and style bytes', function () {
    [$project, $llm, $tmp] = design_preview_fixture();
    $preview = design_preview_document();
    $llm->queueText($preview);

    design_preview_run($project, $llm, 'preview-model', 0.37);

    assert_eq(1, $llm->completeCalls, 'one direct text call');
    assert_eq(0, $llm->completeBatchCalls, 'no text batch');
    assert_eq(0, $llm->completeJsonCalls, 'no JSON call');
    assert_eq(0, $llm->completeJsonBatchCalls, 'no JSON batch');
    assert_eq(1, count($llm->calls), 'one total LLM call');
    assert_eq('preview-model', $llm->calls[0]['opts']['model'] ?? null);
    assert_eq(0.37, $llm->calls[0]['opts']['temperature'] ?? null);
    assert_contains('A neighborhood bakery with seasonal bread and classes', $llm->calls[0]['prompt']);
    assert_contains('Hearth & Crumb', $llm->calls[0]['prompt']);
    assert_contains('Flour Archive', $llm->calls[0]['prompt']);
    assert_eq($preview, $project->readText('design/preview.html'));
    assert_eq(design_preview_css(), $project->readText('design/site.css'));

    $designFiles = array_map('basename', glob($project->path('design/*')) ?: []);
    sort($designFiles);
    assert_eq(['preview.html', 'site.css'], $designFiles, 'only fold HTML and byte-faithful CSS are written');
    design_preview_cleanup($tmp);

    [$wiredProject, $wiredLlm, $wiredTmp] = design_preview_fixture();
    $wiredLlm->queueText(design_preview_document('WIRED-DESIGN-PREVIEW'));
    $blockFixer = new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            return '[fix-templates] noop';
        }
    };
    $composition = StepComposition::default(
        llm: $wiredLlm,
        renderer: new PromptRenderer(Package::promptsDir()),
        models: [
            'design-preview' => 'wired-preview-model',
            'homepage-design' => 'wired-homepage-model',
        ],
        temperatures: [
            'design-preview' => 0.23,
            'homepage-design' => 0.91,
        ],
        blockFixer: $blockFixer,
    );
    $wiredPreview = null;
    foreach ($composition->steps() as $candidate) {
        if ($candidate->id() === 'design-preview') {
            $wiredPreview = $candidate;
            break;
        }
    }
    assert_true($wiredPreview instanceof Step, 'composition wires design-preview');
    $wiredPreview->run($wiredProject);
    assert_eq(1, count($wiredLlm->calls));
    assert_eq('wired-preview-model', $wiredLlm->calls[0]['opts']['model'] ?? null);
    assert_eq(0.23, $wiredLlm->calls[0]['opts']['temperature'] ?? null);
    assert_true(
        ($wiredLlm->calls[0]['opts']['model'] ?? null) !== 'wired-homepage-model',
        'preview does not inherit homepage model',
    );
    assert_true(
        ($wiredLlm->calls[0]['opts']['temperature'] ?? null) !== 0.91,
        'preview does not inherit homepage temperature',
    );
    assert_eq(design_preview_css(), $wiredProject->readText('design/site.css'));
    design_preview_cleanup($wiredTmp);
});

test('G4 design-preview renders a labelled SITE PAGES list and shared-header link contract', function () {
    [$project, $llm, $tmp] = design_preview_fixture();
    $project->writeJson('siteSpec.json', [
        'name' => 'Hearth & Crumb',
        'description' => 'Neighborhood bread and pastry studio',
        'pages' => [
            [
                'slug' => 'home',
                'title' => 'Home',
                'path' => '/',
                'front' => true,
                'purpose' => 'Welcome visitors',
            ],
            [
                'slug' => 'classes',
                'title' => 'Classes',
                'path' => '/classes/',
                'front' => false,
                'purpose' => 'Explain bread classes',
            ],
        ],
    ]);
    $llm->queueText(design_preview_document());

    design_preview_run($project, $llm);

    $prompt = $llm->calls[0]['prompt'];
    assert_contains('SITE PAGES (the whole site', $prompt);
    assert_contains('- "Home" — / (front page): Welcome visitors', $prompt);
    assert_contains('- "Classes" — /classes/: Explain bread classes', $prompt);
    assert_contains('page links use the SITE PAGES paths verbatim', $prompt);
    assert_contains('href="/#anchor"', $prompt);
    assert_contains('NEVER a bare `href="#anchor"`', $prompt);
    assert_contains('No `href="#"` placeholders', $prompt);
    design_preview_cleanup($tmp);
});

test('design-preview freezes the first-fold shape and prompt contract', function () {
    [$project, $llm, $tmp] = design_preview_fixture();
    $llm->queueText(design_preview_document());
    design_preview_run($project, $llm);

    design_preview_assert_shape($project->readText('design/preview.html'));

    $prompt = (string) file_get_contents(repo_path('prompts/design-preview.md'));
    foreach ([
        '{{brief}}',
        '{{site_spec}}',
        '{{design_direction}}',
        '<header>',
        '<nav>',
        '<section id="hero">',
        'exactly one',
        'AI_IMAGE: subject | page-context | style | aspect-ratio',
        '--content-size: 800px',
        '--wide-size: 1280px',
        'Do not emit a <footer>',
        'No JavaScript',
        'No HTML comments',
        'No CSS comments',
        'Do not load external',
    ] as $required) {
        assert_contains($required, $prompt);
    }
    design_preview_cleanup($tmp);

    $validRepair = design_preview_document('SAFE-REPAIR');
    $defects = [
        'live script' => str_replace('</header>', '</header><script>globalThis.previewAttack=true</script>', $validRepair),
        'event handler' => str_replace('<header>', '<header onclick="globalThis.previewAttack=true">', $validRepair),
        'javascript URL' => str_replace('href="/menu"', 'href="javascript:alert(1)"', $validRepair),
        'HTML comment' => str_replace('<main>', '<!-- preview comment --><main>', $validRepair),
        'CSS comment' => str_replace(':root {', '/* preview comment */:root {', $validRepair),
        'remote stylesheet link' => str_replace(
            '<style>',
            '<link rel="stylesheet" href="https://cdn.example.test/preview.css"><style>',
            $validRepair,
        ),
        'remote CSS import' => str_replace(
            '<style>',
            '<style>@import url("https://cdn.example.test/preview.css");',
            $validRepair,
        ),
        'remote CSS URL' => str_replace(
            '</style>',
            '.hero{background-image:url("https://cdn.example.test/hero.jpg")}</style>',
            $validRepair,
        ),
        'remote font' => str_replace(
            '</style>',
            '@font-face{font-family:Remote;src:url("https://cdn.example.test/font.woff2")}</style>',
            $validRepair,
        ),
        'extra header' => str_replace('<main>', '<header><nav></nav></header><main>', $validRepair),
        'extra nav' => str_replace('</nav>', '</nav><nav aria-label="Secondary"></nav>', $validRepair),
        'footer' => str_replace('</body>', '<footer>Not allowed</footer></body>', $validRepair),
        'content section' => str_replace(
            '</section></main>',
            '</section><section id="features">Not allowed</section></main>',
            $validRepair,
        ),
        'extra image' => str_replace(
            '</section>',
            '<img alt="AI_IMAGE: A second loaf | extra hero image | photorealistic | landscape"></section>',
            $validRepair,
        ),
        'missing image' => (string) preg_replace('/<img\b[^>]*>/', '', $validRepair, 1),
        'bad AI image alt' => (string) preg_replace(
            '/alt="AI_IMAGE:[^"]+"/',
            'alt="hero image"',
            $validRepair,
            1,
        ),
        'wrong widths' => str_replace(
            ['--content-size: 800px', '--wide-size: 1280px'],
            ['--content-size: 640px', '--wide-size: 960px'],
            $validRepair,
        ),
    ];
    foreach ($defects as $name => $defective) {
        assert_true($defective !== $validRepair, "{$name} fixture carries its defect");
        [$caseProject, $caseLlm, $caseTmp] = design_preview_fixture();
        $caseLlm->queueText($defective);
        $caseLlm->queueText($validRepair);

        design_preview_run($caseProject, $caseLlm);

        assert_true($caseLlm->completeCalls >= 1, "{$name} generated once");
        assert_true($caseLlm->completeCalls <= 2, "{$name} uses at most one repair");
        assert_eq(0, $caseLlm->completeBatchCalls, "{$name} stays on direct text calls");
        design_preview_assert_shape($caseProject->readText('design/preview.html'));
        design_preview_cleanup($caseTmp);
    }
});

test('design-preview repairs one malformed response once and warns', function () {
    [$project, $llm, $tmp] = design_preview_fixture();
    $malformed = '<!doctype html><html><body><main>MALFORMED-INITIAL</main></body></html>';
    $replacement = design_preview_document('REPAIRED-PREVIEW');
    $llm->queueText($malformed);
    $llm->queueText($replacement);

    design_preview_run($project, $llm);

    assert_eq(2, $llm->completeCalls, 'generation plus one repair');
    assert_eq(0, $llm->completeBatchCalls);
    assert_eq(2, count($llm->calls), 'one repair attempt only');
    assert_eq($replacement, $project->readText('design/preview.html'));
    assert_eq(design_preview_css(), $project->readText('design/site.css'));
    design_preview_assert_shape($project->readText('design/preview.html'));
    $warnings = design_preview_warnings($project);
    assert_contains('malformed_design', $warnings);
    assert_contains('file design/preview.html', $warnings);
    assert_contains('block_path document', $warnings);
    assert_contains('authored_value', $warnings);
    assert_contains('delivered_value', $warnings);
    assert_contains('disposition repaired', $warnings);
    design_preview_cleanup($tmp);
});

test('design-preview degrades two malformed responses to one deterministic safe scaffold', function () {
    $outputs = [];
    $styles = [];
    for ($run = 0; $run < 2; $run++) {
        [$project, $llm, $tmp] = design_preview_fixture();
        $llm->queueText('MALFORMED-INITIAL');
        $llm->queueText('MALFORMED-REPAIR');

        $caught = null;
        try {
            design_preview_run($project, $llm);
        } catch (Throwable $error) {
            $caught = $error;
        }

        assert_eq(null, $caught, 'generated-content failure never throws');
        assert_eq(2, $llm->completeCalls, 'generation plus one repair');
        assert_eq(0, $llm->completeBatchCalls);
        assert_eq(2, count($llm->calls), 'repair capped at one attempt');
        assert_true($project->exists('design/preview.html'), 'safe scaffold written');
        assert_true($project->exists('design/site.css'), 'safe scaffold CSS written');
        $outputs[] = $project->readText('design/preview.html');
        $styles[] = $project->readText('design/site.css');
        design_preview_assert_shape($outputs[$run]);
        $dom = Html::loadUtf8Html($outputs[$run], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        assert_true($dom instanceof DOMDocument);
        $style = (new DOMXPath($dom))->query('/html/head/style')->item(0);
        assert_true($style instanceof DOMElement);
        assert_eq($style->textContent, $styles[$run], 'degraded CSS equals delivered head style bytes');

        $warnings = design_preview_warnings($project);
        assert_contains('file design/preview.html', $warnings);
        assert_contains('block_path document', $warnings);
        assert_contains('authored_value', $warnings);
        assert_contains('MALFORMED-INITIAL', $warnings);
        assert_contains('delivered_value', $warnings);
        assert_contains('safe scaffold', $warnings);
        assert_contains('disposition degraded', $warnings);
        design_preview_cleanup($tmp);
    }

    assert_eq($outputs[0], $outputs[1], 'safe scaffold is deterministic');
    assert_eq($styles[0], $styles[1], 'safe scaffold CSS is deterministic');
});
