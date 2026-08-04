<?php
declare(strict_types=1);

use Automattic\SiteBuild\Html;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\SpliceHomeDesignStep;

function splice_home_preview(): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<style>:root{--ink:#231f20}</style></head><body class="fold-shell">'
        . '<header class="site-header"><nav>FOLD-HEADER-38A1</nav></header>'
        . '<main><section id="hero" class="hero"><h1>FOLD-HERO-97C4</h1></section></main>'
        . '</body></html>';
}

function splice_home_body(): string
{
    return '<main><section id="story"><h2>HOME-BODY-STORY-5D2E</h2></section>'
        . '<section id="visit"><p>HOME-BODY-VISIT-6F70</p></section></main>'
        . '<footer class="site-footer"><p>HOME-BODY-FOOTER-2B19</p></footer>';
}

/** @return array{0:Project,1:string} */
function splice_home_fixture(?string $preview = null, ?string $body = null): array
{
    $tmp = sys_get_temp_dir() . '/builder_splice_home_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    if ($preview !== null) {
        $project->writeText('design/preview.html', $preview);
    }
    if ($body !== null) {
        $project->writeText('design/home-body.html', $body);
    }
    return [$project, $tmp];
}

function splice_home_run(Project $project): SpliceHomeDesignStep
{
    $step = new SpliceHomeDesignStep();
    $step->run($project);
    return $step;
}

test('splice-home-design declares exact inputs and deterministic home output', function () {
    $outputs = [];
    for ($run = 0; $run < 2; $run++) {
        [$project, $tmp] = splice_home_fixture(splice_home_preview(), splice_home_body());
        $step = splice_home_run($project);

        assert_eq('splice-home-design', $step->id());
        assert_eq(
            ['design/preview.html', 'design/home-body.html'],
            $step->declaration()->reads,
        );
        assert_eq(['design/home.html', 'warnings.json'], $step->declaration()->writes);
        assert_eq(false, $step->declaration()->concurrent);
        assert_true($project->exists('design/home.html'));
        $outputs[] = $project->readText('design/home.html');

        $dom = Html::loadUtf8Html($outputs[$run], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        assert_true($dom instanceof DOMDocument, 'composed homepage parses');
        $xpath = new DOMXPath($dom);
        assert_eq(1, $xpath->query('//header')->length, 'fold contributes exactly one header');
        assert_eq(1, $xpath->query('//footer')->length, 'home body contributes exactly one footer');
        assert_eq(1, $xpath->query('/html/body/main')->length, 'fold and body share one main');
        assert_eq(1, $xpath->query('/html/body/main/section[@id="hero"]')->length);
        assert_eq(1, $xpath->query('/html/body/main/section[@id="story"]')->length);
        assert_eq(1, $xpath->query('/html/body/main/section[@id="visit"]')->length);
        assert_contains('<header class="site-header"><nav>FOLD-HEADER-38A1</nav></header>', $outputs[$run]);
        assert_contains('<section id="hero" class="hero"><h1>FOLD-HERO-97C4</h1></section>', $outputs[$run]);
        assert_contains('<footer class="site-footer"><p>HOME-BODY-FOOTER-2B19</p></footer>', $outputs[$run]);
        assert_true(!$project->exists('warnings.json'), 'valid splice needs no warning');
        exec('rm -rf ' . escapeshellarg($tmp));
    }

    assert_eq($outputs[0], $outputs[1], 'fixed fold and body compose byte-identically');
});

test('splice-home-design degrades every generated-content boundary and never drops home', function () {
    $cases = [
        'missing header' => [
            str_replace(
                '<header class="site-header"><nav>FOLD-HEADER-38A1</nav></header>',
                '',
                splice_home_preview(),
            ),
            splice_home_body(),
            null,
            'HOME-BODY-STORY-5D2E',
        ],
        'missing hero' => [
            str_replace(
                '<section id="hero" class="hero"><h1>FOLD-HERO-97C4</h1></section>',
                '<section id="intro">NO-HERO</section>',
                splice_home_preview(),
            ),
            splice_home_body(),
            null,
            'HOME-BODY-STORY-5D2E',
        ],
        'empty home body' => [splice_home_preview(), '', null, 'FOLD-HERO-97C4'],
        'failed home body' => [splice_home_preview(), null, 'failed', 'FOLD-HERO-97C4'],
        'malformed fold' => ['<html><body><header>MALFORMED-FOLD', splice_home_body(), null, 'HOME-BODY-STORY-5D2E'],
    ];

    foreach ($cases as $name => [$preview, $body, $failed, $survivor]) {
        [$project, $tmp] = splice_home_fixture($preview, $body);
        if ($failed !== null) {
            $project->writeText('design/home-body.failed', "HOME-BODY-FAILED\n");
        }

        $caught = null;
        try {
            splice_home_run($project);
        } catch (Throwable $error) {
            $caught = $error;
        }

        assert_eq(null, $caught, "{$name} never throws");
        assert_true($project->exists('design/home.html'), "{$name} still writes front page");
        $home = $project->readText('design/home.html');
        assert_true(trim($home) !== '', "{$name} writes non-empty front page");
        assert_contains($survivor, $home, "{$name} keeps safest usable unit");
        $warnings = implode("\n", $project->readJson('warnings.json')['splice-home-design'] ?? []);
        foreach (['design/home.html', 'authored_value', 'delivered_value', 'disposition'] as $needle) {
            assert_contains($needle, $warnings, "{$name} warning carries {$needle}");
        }
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
