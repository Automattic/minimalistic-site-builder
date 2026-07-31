<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\InnerPagesDesignStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * @param list<array<string,mixed>> $pages
 * @return array{0:Project,1:FakeLlm,2:string}
 */
function inner_pages_fixture(array $pages, ?string $home = null): array
{
    $tmp = sys_get_temp_dir() . '/builder_inner_pages_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', [
        'name' => 'Northstar Studio',
        'language' => 'English',
        'pages' => $pages,
    ]);
    $project->writeText(
        'design/site.css',
        "/* EXACT-SITE-CSS-5F7D */\n:root{--ink:#18222d}.shared-shell{max-width:72rem}\n",
    );
    $project->writeText(
        'design/home.html',
        $home ?? '<!doctype html><html><head><style>.ignored{color:red}</style></head>'
            . '<body><header>Shared header</header>'
            . '<main id="home-main"><section><h1>EXACT-HOME-MAIN-9C2A</h1></section></main>'
            . '<footer>Shared footer</footer></body></html>',
    );
    return [$project, new FakeLlm(), $tmp];
}

function inner_pages_run(Project $project, FakeLlm $llm): InnerPagesDesignStep
{
    $step = new InnerPagesDesignStep($llm, new PromptRenderer(repo_path('prompts')));
    $step->run($project);
    return $step;
}

function inner_pages_cleanup(string $tmp): void
{
    exec('rm -rf ' . escapeshellarg($tmp));
}

/** @return array<string,mixed> */
function inner_page(string $slug, string $title, string $purpose, array $children = []): array
{
    $page = ['slug' => $slug, 'title' => $title, 'purpose' => $purpose];
    if ($children !== []) {
        $page['children'] = $children;
    }
    return $page;
}

test('inner-pages-design declares and batches every flattened non-home page against exact cache layers', function () {
    $pages = [
        inner_page('home', 'Home', 'Welcome visitors'),
        inner_page('about', 'About', 'Explain the studio', [
            inner_page('team', 'Team', 'Introduce the team'),
        ]),
        inner_page('contact', 'Contact', 'Give practical contact details'),
    ];
    [$project, $llm, $tmp] = inner_pages_fixture($pages);
    $css = $project->readText('design/site.css');
    $homeMain = '<main id="home-main"><section><h1>EXACT-HOME-MAIN-9C2A</h1></section></main>';
    $responses = [
        '<main id="about-main"><h1>About</h1></main>',
        '<main id="team-main"><h1>Team</h1></main>',
        '<main id="contact-main"><h1>Contact</h1></main>',
    ];
    foreach ($responses as $response) {
        $llm->queueText($response);
    }

    $step = inner_pages_run($project, $llm);

    $declaration = $step->declaration();
    assert_eq('inner-pages-design', $step->id());
    assert_eq(
        ['siteSpec.json', 'design/site.css', 'design/home.html'],
        $declaration->reads,
    );
    assert_eq(['design/*', 'warnings.json'], $declaration->writes);
    assert_true($declaration->concurrent);
    assert_eq(1, $llm->completeBatchCalls, 'one completeBatch call owns all page requests');
    assert_eq(3, count($llm->calls), 'one request per flattened non-home page');
    $sharedPrefixes = null;
    foreach ($llm->calls as $call) {
        $prefixes = $call['opts']['cached_prefixes'] ?? [];
        assert_eq(2, count($prefixes));
        $sharedPrefixes ??= $prefixes;
        assert_eq($sharedPrefixes, $prefixes, 'every page gets byte-identical cache layers');
        assert_contains($css, $prefixes[0]);
        assert_contains($homeMain, $prefixes[1]);
        assert_true(!str_contains($call['prompt'], $css), 'site.css bytes live only in cached prefix');
        assert_true(!str_contains($call['prompt'], $homeMain), 'home main bytes live only in cached prefix');
    }
    assert_contains('About', $llm->calls[0]['prompt']);
    assert_contains('Explain the studio', $llm->calls[0]['prompt']);
    assert_contains('Team', $llm->calls[1]['prompt']);
    assert_contains('Introduce the team', $llm->calls[1]['prompt']);
    assert_contains('Contact', $llm->calls[2]['prompt']);
    assert_contains('Give practical contact details', $llm->calls[2]['prompt']);
    assert_eq($responses[0], $project->readText('design/about.html'));
    assert_eq($responses[1], $project->readText('design/team.html'));
    assert_eq($responses[2], $project->readText('design/contact.html'));
    assert_true(!$project->exists('design/home.failed'));
    inner_pages_cleanup($tmp);
});

test('inner-pages-design with no non-home pages writes nothing and calls no LLM method', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'One-page site'),
    ]);
    $before = glob($project->path('design/*')) ?: [];
    sort($before);

    inner_pages_run($project, $llm);

    $after = glob($project->path('design/*')) ?: [];
    sort($after);
    assert_eq($before, $after);
    assert_eq(0, $llm->completeBatchCalls);
    assert_eq(0, $llm->completeCalls);
    assert_eq(0, $llm->completeJsonCalls);
    assert_eq(0, $llm->completeJsonBatchCalls);
    assert_true(!$project->exists('warnings.json'));
    inner_pages_cleanup($tmp);
});

test('inner-pages-design repairs one malformed page serially then marks only that page failed', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('valid', 'Valid', 'Must land'),
        inner_page('broken', 'Broken', 'Must isolate failure'),
    ]);
    $valid = '<main id="valid-main"><h1>Valid sibling lands</h1></main>';
    $llm->queueText($valid);
    $llm->queueText('<section>Broken authored fragment</section>');
    $llm->queueText('<div>Still no main after repair</div>');

    inner_pages_run($project, $llm);

    assert_eq(1, $llm->completeBatchCalls);
    assert_eq(1, $llm->completeCalls, 'malformed page receives exactly one serial repair');
    assert_eq($valid, $project->readText('design/valid.html'));
    assert_true(!$project->exists('design/valid.failed'));
    assert_true(!$project->exists('design/broken.html'));
    assert_true($project->exists('design/broken.failed'));
    assert_contains('Broken authored fragment', $llm->calls[2]['prompt']);

    $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
    assert_contains('design/broken.html', $warnings);
    assert_contains('authored', $warnings);
    assert_contains('delivered', $warnings);
    assert_contains('design/broken.failed', $warnings);
    assert_contains('disposition', $warnings);
    inner_pages_cleanup($tmp);
});

test('inner-pages-design preserves only optional page CSS before main', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('allowed-style', 'Allowed style', 'Needs one small override'),
        inner_page('body-style', 'Body style', 'Must not weaken sanitizer'),
    ]);
    $allowed = '<style data-page-css>.accent{color:#b20}</style>'
        . '<main id="allowed-main"><p class="accent">Allowed</p></main>';
    $llm->queueText($allowed);
    $llm->queueText(
        '<main id="body-main"><style data-page-css>.unsafe{position:fixed}</style>'
        . '<p id="body-style-survivor">Keep me</p></main>',
    );

    inner_pages_run($project, $llm);

    assert_eq($allowed, $project->readText('design/allowed-style.html'));
    $body = $project->readText('design/body-style.html');
    assert_true(!str_contains($body, '<style'), 'body-level style removed');
    assert_contains('<p id="body-style-survivor">Keep me</p>', $body);
    $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
    assert_contains('data-page-css', $warnings);
    assert_contains('delivered removed', $warnings);
    assert_contains('disposition removed', $warnings);
    inner_pages_cleanup($tmp);
});

test('inner-pages-design sends every hostile fragment through shared hardened sanitizer', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('abrupt-comment', 'Abrupt comment', 'Probe comment handling'),
        inner_page('attribute-separators', 'Attribute separators', 'Probe event handlers'),
        inner_page('invalid-url', 'Invalid URL', 'Probe URL decoding'),
        inner_page('overlap', 'Overlap', 'Probe overlapping removals'),
        inner_page('quoted-doctype', 'Quoted doctype', 'Probe declaration boundary'),
    ]);
    $llm->queueText(
        '<main id="comment-main"><p>Before</p><!--><script>COMMENT_PWNED()</script>'
        . '<p id="comment-survivor">After</p></main>',
    );
    $llm->queueText(
        '<main id="attributes-main"><a href="#safe" /onclick="ATTR_PWNED()">Safe link</a>'
        . '<p id="attribute-survivor">After</p></main>',
    );
    $llm->queueText(
        '<main id="url-main"><a href="javascript:URL_PWNED()' . "\xC3" . '">Bad URL</a>'
        . '<p id="url-survivor">After</p></main>',
    );
    $llm->queueText(
        '<main id="overlap-main"><svg><a=></svg><!--<script>OVERLAP_PWNED()</script>-->'
        . '<p id="overlap-survivor">After</p></main>',
    );
    $llm->queueText(
        '<main id="doctype-main"><!DOCTYPE html PUBLIC "><script>DOCTYPE_PWNED()</script>">'
        . '<p id="doctype-survivor">After</p></main>',
    );

    inner_pages_run($project, $llm);

    $comment = strtolower($project->readText('design/abrupt-comment.html'));
    assert_true(!str_contains($comment, '<script'));
    assert_contains('comment-survivor', $comment);

    $attributes = strtolower($project->readText('design/attribute-separators.html'));
    assert_true(!str_contains($attributes, 'onclick'));
    assert_contains('attribute-survivor', $attributes);

    $url = strtolower($project->readText('design/invalid-url.html'));
    assert_true(!str_contains($url, 'javascript:'));
    assert_contains('url-survivor', $url);

    $overlap = strtolower($project->readText('design/overlap.html'));
    assert_true(!str_contains($overlap, '<script'));
    assert_true(!str_contains($overlap, '<svg'));
    assert_contains('overlap-survivor', $overlap);

    $doctype = strtolower($project->readText('design/quoted-doctype.html'));
    assert_true(!str_contains($doctype, '<script'));
    assert_contains('doctype-survivor', $doctype);

    $source = (string) file_get_contents(repo_path('src/Steps/InnerPagesDesignStep.php'));
    assert_contains('DesignMarkupSanitizer::sanitize(', $source);
    assert_true(
        preg_match('/(?<!Design)MarkupSanitizer::sanitize\s*\(/', $source) !== 1,
        'legacy MarkupSanitizer path forbidden',
    );
    inner_pages_cleanup($tmp);
});

test('inner-page prompt freezes supported slice responsive and small-CSS contract', function () {
    $path = repo_path('prompts/inner-page-design.md');
    assert_true(is_file($path), 'inner-page-design.md must exist');
    $prompt = strtolower((string) file_get_contents($path));

    foreach ([
        '<main>',
        'data-page-css',
        'reuse existing classes',
        'last resort',
        'must be small',
        'headings',
        'paragraphs',
        'lists',
        'block quotes',
        'tables',
        'images',
        'buttons',
        'links',
        'no forms',
        'no svg',
        'no custom elements',
        'no javascript',
        'mobile-first',
        'min-width',
        'clamp()',
        'grid',
        'flex',
        'bounded content widths',
        'images that cannot overflow',
        'navigation',
        'long words',
        'multi-column',
        'focus states',
        'readable contrast',
        'reduced-motion',
    ] as $required) {
        assert_contains($required, $prompt);
    }
});
