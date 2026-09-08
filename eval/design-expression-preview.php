<?php
declare(strict_types=1);

/**
 * No-network capability proof, NOT model/style-quality acceptance.
 * php eval/design-expression-preview.php /tmp/<fresh-output-directory>
 * Open deco.html, organic.html and minimal.html at desktop/mobile sizes.
 * Real Gutenberg serialization + PageStylesStep deliver the fixture CSS.
 * Keep resulting HTML/screenshots outside the worktree.
 */
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/tests/FakeLlm.php';

use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Tests\FakeLlm;

$output = $argv[1] ?? sys_get_temp_dir() . '/design-expression-' . uniqid();
if (file_exists($output)) {
    throw new RuntimeException('Choose a fresh output directory; existing evidence is never overwritten.');
}
$cases = [
    'deco' => [
        'title' => 'Geometry & ceremony', 'base' => '#102E29', 'ink' => '#FFF5D6', 'paint' => '#D5AA59',
        'signature' => 'Double gold design-frame borders; a radial geometric fan in design-motif above the invitation.',
        'css' => '.design-frame {border: 6px double var(--wp--preset--color--primary)}'
            . '.design-motif::before {height: 8rem; background: repeating-conic-gradient(from -90deg at 50% 100%, var(--wp--preset--color--primary) 0deg 5deg, transparent 5deg 15deg); clip-path: polygon(0 100%, 0 65%, 15% 65%, 15% 35%, 30% 35%, 30% 0, 70% 0, 70% 35%, 85% 35%, 85% 65%, 100% 65%, 100% 100%)}',
        'type' => 'Georgia, serif',
    ],
    'organic' => [
        'title' => 'Softness & growth', 'base' => '#F0F0DC', 'ink' => '#244231', 'paint' => '#697D3E',
        'signature' => 'An asymmetric curved design-frame; soft radial lobes in design-motif below the introduction.',
        'css' => '.design-frame {border: 2px solid var(--wp--preset--color--primary); border-radius: 8% 3% 12% 4%}'
            . '.design-motif::after {height: 8rem; width: 80%; border-radius: 65% 30% 60% 35%; background: radial-gradient(ellipse at 20% 60%, var(--wp--preset--color--primary) 0% 25%, transparent 26%), radial-gradient(ellipse at 70% 60%, var(--wp--preset--color--primary) 0% 30%, transparent 31%)}',
        'type' => 'Verdana, sans-serif',
    ],
    'minimal' => [
        'title' => 'Type & space', 'base' => '#FAFAFA', 'ink' => '#202020', 'paint' => '#202020',
        'signature' => 'Unadorned typography and whitespace; no decorative hooks.', 'css' => '',
        'type' => 'Arial, sans-serif',
    ],
];
foreach ($cases as $slug => $case) {
    $project = (new ProjectStore($output . '/projects'))->create($slug);
    $project->writeText('theme/style.css', '/* Theme Name: Expression fixture */');
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('designDirection.json', ['style_signature' => $case['signature'], 'style_hooks' => $case['css'] === '' ? [] : ['design-frame', 'design-motif']]);
    $classes = $case['css'] === '' ? '' : 'design-frame design-motif';
    $attrs = json_encode(['className' => $classes, 'layout' => ['type' => 'constrained']]);
    $markup = '<!-- wp:group ' . $attrs . ' --><div class="' . $classes . '">'
        . '<!-- wp:heading {"level":1} --><h1>' . $case['title'] . '</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>One expressive channel, different visual languages. The decoration occupies its own space; this copy remains readable and this link remains usable.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p><a href="#details">Explore the details</a></p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $project->writeText('theme/parts/fixture.html', $markup);
    $report = (new PhpBlockFixer())->fix($project->path('theme'));
    if (str_contains($report, 'failed')) {
        throw new RuntimeException($report);
    }
    $llm = new FakeLlm();
    if ($case['css'] !== '') {
        $llm->queueText($case['css']);
    }
    (new PageStylesStep($llm, new PromptRenderer(dirname(__DIR__) . '/prompts')))->run($project);
    $css = $project->readText('theme/style.css');
    $markup = $project->readText('theme/parts/fixture.html');
    $html = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Expression fixture: ' . $slug . '</title><style>'
        . ':root {--wp--preset--color--primary:' . $case['paint'] . ';font-size:16px}'
        . '*{box-sizing:border-box}body{margin:0;background:' . $case['base'] . ';color:' . $case['ink'] . ';font-family:' . $case['type'] . ';line-height:1.6}'
        . 'main{max-width:800px;margin:4rem auto;padding:1rem}.wp-block-group{padding:clamp(1rem,5vw,3rem)}h1{font-size:clamp(2rem,5vw,4rem);line-height:1.1;margin:0 0 1rem}p{max-width:52ch}a{color:inherit;text-underline-offset:.25em}#details{margin-top:4rem}'
        . $css . '</style><main>' . $markup . '<section id="details"><h2>Content after decoration</h2><p>Still in normal reading order. No images, external fonts, model calls or scripts are required by the generated decoration.</p></section></main>'
        . '<script>addEventListener("load",()=>{const g=document.querySelector(".wp-block-group");const a=document.querySelector("a");const r=a.getBoundingClientRect();document.documentElement.dataset.proof=JSON.stringify({viewport:innerWidth,overflow:document.documentElement.scrollWidth>innerWidth,linkHit:document.elementFromPoint(r.x+Math.min(10,r.width/2),r.y+r.height/2)===a,before:getComputedStyle(g,"::before").backgroundImage,after:getComputedStyle(g,"::after").backgroundImage});});</script></html>';
    if (file_put_contents($output . '/' . $slug . '.html', $html) === false) {
        throw new RuntimeException('Could not write fixture HTML.');
    }
    echo $output . '/' . $slug . ".html\n";
}
