<?php
declare(strict_types=1);

use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\DesignPreviewStep;
use Automattic\SiteBuild\Steps\InspirationStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * Coverage for the screenshots half of inspiration: slices recorded on a
 * reference are reloaded and attached to the design calls. The brief text
 * renders whether or not the images survive, so nothing else in the suite
 * notices when this path breaks.
 */

/**
 * @param array<string,list<string>> $slicesByRef reference url => slice basenames
 * @return array{0:Project,1:string}
 */
function images_fixture(array $slicesByRef, bool $writeFiles = true): array
{
    $tmp = sys_get_temp_dir() . '/builder_inspiration_images_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Hearth & Crumb', 'description' => 'Bread studio']);
    $project->writeJson('designDirection.json', [
        'direction' => ['title' => 'Flour Archive', 'description' => 'Warm editorial layouts.'],
    ]);

    $dir = $project->logPath('inspiration');
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $references = [];
    foreach ($slicesByRef as $url => $names) {
        foreach ($names as $name) {
            if ($writeFiles) {
                file_put_contents($dir . '/' . $name, 'PNG:' . $name);
            }
        }
        $references[] = [
            'url' => $url,
            'page_type' => 'other',
            'owner_type' => 'business',
            'style' => 'Bold and high-contrast',
            'typography' => 'Grotesque sans with oversized headings',
            'layout' => 'Centered hero over a feature grid',
            'mood' => ['bold', 'warm'],
            'colors' => [['hex' => '#ff90e8', 'name' => 'pink', 'role' => 'accent']],
            'sections' => [['category' => 'hero', 'description' => 'Full-bleed color field']],
            'screenshots' => $names,
        ];
    }
    $project->writeJson(InspirationStep::FILE, [
        'urls' => array_keys($slicesByRef),
        'references' => $references,
    ]);
    return [$project, $tmp];
}

test('imagesFor loads recorded slices as raw bytes ready for an images request', function () {
    [$project, $tmp] = images_fixture(['https://a.example' => ['reference-0-1.png', 'reference-0-2.png']]);
    try {
        $images = InspirationStep::imagesFor($project);
        assert_eq(2, count($images));
        assert_eq('PNG:reference-0-1.png', $images[0]['bytes']);
        assert_eq('image/png', $images[0]['mime']);
    } finally {
        remove_tree($tmp);
    }
});

test('imagesFor represents every reference before giving any a second slice', function () {
    [$project, $tmp] = images_fixture([
        'https://a.example' => ['a-1.png', 'a-2.png', 'a-3.png'],
        'https://b.example' => ['b-1.png', 'b-2.png'],
    ]);
    try {
        $order = array_map(
            static fn (array $image): string => substr($image['bytes'], 4),
            InspirationStep::imagesFor($project),
        );
        // Index-first: one reference contributing three slices must not push the
        // other reference's above-fold capture out of a truncated request.
        assert_eq(['a-1.png', 'b-1.png', 'a-2.png', 'b-2.png', 'a-3.png'], $order);
    } finally {
        remove_tree($tmp);
    }
});

test('imagesFor caps the request below the per-request image limit', function () {
    [$project, $tmp] = images_fixture([
        'https://a.example' => ['a-1.png', 'a-2.png', 'a-3.png', 'a-4.png'],
        'https://b.example' => ['b-1.png', 'b-2.png', 'b-3.png', 'b-4.png'],
    ]);
    try {
        $images = InspirationStep::imagesFor($project);
        assert_eq(6, count($images), 'eight recorded slices must be trimmed to the cap');
        // ImageInput rejects a request carrying more than eight images, so the
        // cap has to stay under it rather than relying on the caller.
        assert_true(count($images) <= 8);
    } finally {
        remove_tree($tmp);
    }
});

test('imagesFor returns nothing when a reference recorded no screenshots', function () {
    [$project, $tmp] = images_fixture(['https://a.example' => []]);
    try {
        assert_eq([], InspirationStep::imagesFor($project));
    } finally {
        remove_tree($tmp);
    }
});

test('imagesFor reports recorded screenshots whose files have gone missing', function () {
    [$project, $tmp] = images_fixture(
        ['https://a.example' => ['gone-1.png', 'gone-2.png']],
        writeFiles: false,
    );
    try {
        // The brief still renders, so a silent [] here would lose half the
        // feature with the build still looking correct.
        $sink = fopen('php://memory', 'w+');
        Narrator::setStream($sink);
        try {
            $images = InspirationStep::imagesFor($project);
            rewind($sink);
            $narrated = (string) stream_get_contents($sink);
        } finally {
            Narrator::reset();
            fclose($sink);
        }

        assert_eq([], $images);
        assert_contains('missing', $narrated);
        assert_contains('2 recorded screenshot', $narrated);
    } finally {
        remove_tree($tmp);
    }
});

test('imagesFor contains a host-supplied path traversal to the inspiration directory', function () {
    [$project, $tmp] = images_fixture(['https://a.example' => ['reference-0-1.png']]);
    try {
        // A host supplies references directly; a crafted name must not read
        // outside the project's own inspiration directory.
        $artifact = $project->readJson(InspirationStep::FILE);
        $artifact['references'][0]['screenshots'] = ['../../../../etc/passwd', 'reference-0-1.png'];
        $project->writeJson(InspirationStep::FILE, $artifact);

        $images = InspirationStep::imagesFor($project);
        assert_eq(1, count($images), 'only the in-directory slice resolves');
        assert_eq('PNG:reference-0-1.png', $images[0]['bytes']);
    } finally {
        remove_tree($tmp);
    }
});

test('design-preview attaches the reference screenshots to its generation call', function () {
    [$project, $tmp] = images_fixture(['https://a.example' => ['reference-0-1.png', 'reference-0-2.png']]);
    try {
        $llm = new FakeLlm();
        $llm->queueText(images_preview_document());
        quietly(static function () use ($project, $llm): void {
            (new DesignPreviewStep($llm, new PromptRenderer(Package::promptsDir())))->run($project);
        });

        $opts = $llm->calls[0]['opts'];
        assert_eq(2, count($opts['images'] ?? []), 'the design model must see the reference');
        assert_eq('PNG:reference-0-1.png', $opts['images'][0]['bytes']);
        assert_contains('Screenshots taken from those reference sites are attached', $llm->calls[0]['prompt']);
    } finally {
        remove_tree($tmp);
    }
});

test('design-preview sends no images and no screenshot guidance when there are none', function () {
    [$project, $tmp] = images_fixture(['https://a.example' => []]);
    try {
        $llm = new FakeLlm();
        $llm->queueText(images_preview_document());
        quietly(static function () use ($project, $llm): void {
            (new DesignPreviewStep($llm, new PromptRenderer(Package::promptsDir())))->run($project);
        });

        assert_eq(false, array_key_exists('images', $llm->calls[0]['opts']));
        assert_eq(
            false,
            str_contains($llm->calls[0]['prompt'], 'Screenshots taken from those reference sites are attached'),
            'guidance must not promise attachments that are not there',
        );
    } finally {
        remove_tree($tmp);
    }
});

test('disabled inspiration keeps screenshots out of the design calls', function () {
    [$project, $tmp] = images_fixture(['https://a.example' => ['reference-0-1.png']]);
    try {
        $llm = new FakeLlm();
        $llm->queueText(images_preview_document());
        quietly(static function () use ($project, $llm): void {
            (new DesignPreviewStep(
                $llm,
                new PromptRenderer(Package::promptsDir()),
                useInspiration: false,
            ))->run($project);
        });

        assert_eq(false, array_key_exists('images', $llm->calls[0]['opts']));
    } finally {
        remove_tree($tmp);
    }
});

test('design-direction attaches the reference screenshots to its generation call', function () {
    [$project, $tmp] = images_fixture(['https://a.example' => ['reference-0-1.png']]);
    try {
        $llm = new FakeLlm();
        $llm->queueJson(['direction' => [
            'title' => 'Ember Grid',
            'description' => 'Warm editorial layouts with documentary imagery.',
        ]]);
        quietly(static function () use ($project, $llm): void {
            try {
                (new DesignDirectionStep(
                    $llm,
                    new PromptRenderer(Package::promptsDir()),
                ))->run($project);
            } catch (\Throwable) {
                // The step does more than the one call this test observes; the
                // recorded opts are what matter.
            }
        });

        $withImages = array_values(array_filter(
            $llm->calls,
            static fn (array $call): bool => array_key_exists('images', $call['opts']),
        ));
        assert_true($withImages !== [], 'design-direction must send the reference screenshots');
        assert_eq('PNG:reference-0-1.png', $withImages[0]['opts']['images'][0]['bytes']);
    } finally {
        remove_tree($tmp);
    }
});

function images_preview_document(): string
{
    return '<!doctype html>'
        . '<html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>:root { --content-size: 800px; --wide-size: 1280px; }'
        . 'body { margin: 0; font-family: system-ui, sans-serif; }'
        . 'main { max-width: var(--wide-size); margin-inline: auto; }</style>'
        . '</head><body>'
        . '<header><nav aria-label="Primary"><a href="/menu">Menu</a></nav></header>'
        . '<main><section id="hero"><h1>DESIGN-PREVIEW</h1>'
        . '<img alt="AI_IMAGE: A baker sliding a sourdough loaf into a stone oven, viewed from counter height | homepage hero beside the primary headline | photorealistic | landscape">'
        . '</section></main>'
        . '</body></html>';
}
