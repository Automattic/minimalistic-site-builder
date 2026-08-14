<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\OpenAiCompatibleClient;
use Automattic\SiteBuild\Steps\InnerPagesDesignStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * @param list<array<string,mixed>> $pages
 * @return array{0:Project,1:FakeLlm,2:string}
 */
function inner_pages_fixture(array $pages, ?string $preview = null): array
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
        'design/preview.html',
        $preview ?? '<!doctype html><html><head><style>.ignored{color:red}</style></head>'
            . '<body><header>EXACT-FOLD-HEADER-9C2A</header>'
            . '<main><section id="hero"><h1>EXACT-FOLD-HERO-6D1F</h1></section></main>'
            . '</body></html>',
    );
    return [$project, new FakeLlm(), $tmp];
}

function inner_pages_home_body(string $marker = 'EXACT-HOME-BODY-84E3'): string
{
    return '<main><section id="story"><h2>' . $marker . '</h2></section></main>'
        . '<footer><p>EXACT-HOME-FOOTER-7A4C</p></footer>';
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

function inner_pages_has_live_script(string $html): bool
{
    $previous = libxml_use_internal_errors(true);
    try {
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML(
            '<!doctype html><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    return $loaded && $dom->getElementsByTagName('script')->length > 0;
}

test('inner-pages-design batches home body and every inner page against exact fold cache layers', function () {
    $pages = [
        inner_page('home', 'Home', 'Welcome visitors'),
        inner_page('about', 'About', 'Explain the studio', [
            inner_page('team', 'Team', 'Introduce the team'),
        ]),
        inner_page('contact', 'Contact', 'Give practical contact details'),
    ];
    [$project, $llm, $tmp] = inner_pages_fixture($pages);
    $css = $project->readText('design/site.css');
    $preview = $project->readText('design/preview.html');
    $homeBody = inner_pages_home_body();
    $responses = [
        '<main id="about-main"><h1>About</h1></main>',
        '<main id="team-main"><h1>Team</h1></main>',
        '<main id="contact-main"><h1>Contact</h1></main>',
    ];
    $llm->queueText($homeBody);
    foreach ($responses as $response) {
        $llm->queueText($response);
    }

    $step = inner_pages_run($project, $llm);

    $declaration = $step->declaration();
    assert_eq('inner-pages-design', $step->id());
    assert_eq(
        ['meta.json', 'siteSpec.json', 'designDirection.json', 'design/site.css', 'design/preview.html'],
        $declaration->reads,
    );
    assert_eq(['design/*', 'warnings.json'], $declaration->writes);
    assert_true($declaration->concurrent);
    assert_eq(1, $llm->completeBatchCalls, 'one completeBatch call owns all page requests');
    assert_eq(4, count($llm->calls), 'one home-body request plus one request per inner page');
    $sharedPrefixes = null;
    foreach ($llm->calls as $call) {
        $prefixes = $call['opts']['cached_prefixes'] ?? [];
        assert_eq(2, count($prefixes));
        $sharedPrefixes ??= $prefixes;
        assert_eq($sharedPrefixes, $prefixes, 'every page gets byte-identical cache layers');
        assert_contains($css, $prefixes[0]);
        assert_contains($preview, $prefixes[1]);
        assert_true(!str_contains($call['prompt'], $css), 'site.css bytes live only in cached prefix');
        assert_true(!str_contains($call['prompt'], $preview), 'fold bytes live only in cached prefix');
    }
    assert_contains('About', $llm->calls[1]['prompt']);
    assert_contains('Explain the studio', $llm->calls[1]['prompt']);
    assert_contains('Team', $llm->calls[2]['prompt']);
    assert_contains('Introduce the team', $llm->calls[2]['prompt']);
    assert_contains('Contact', $llm->calls[3]['prompt']);
    assert_contains('Give practical contact details', $llm->calls[3]['prompt']);
    assert_eq($homeBody, $project->readText('design/home-body.html'));
    assert_eq($responses[0], $project->readText('design/about.html'));
    assert_eq($responses[1], $project->readText('design/team.html'));
    assert_eq($responses[2], $project->readText('design/contact.html'));
    assert_true(!$project->exists('design/home-body.failed'));
    inner_pages_cleanup($tmp);
});

test('inner-pages-design with no inner pages still generates one home body in one batch', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'One-page site'),
    ]);
    $homeBody = inner_pages_home_body('SINGLE-PAGE-HOME-BODY');
    $llm->queueText($homeBody);

    inner_pages_run($project, $llm);

    assert_eq($homeBody, $project->readText('design/home-body.html'));
    assert_eq(1, $llm->completeBatchCalls);
    assert_eq(1, count($llm->calls));
    assert_eq(0, $llm->completeCalls);
    assert_eq(0, $llm->completeJsonCalls);
    assert_eq(0, $llm->completeJsonBatchCalls);
    assert_true(!$project->exists('warnings.json'));
    inner_pages_cleanup($tmp);
});

test('home-body accepts a nested attribution footer plus one page footer without repair', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'One-page site'),
    ]);
    $homeBody = '<main><section id="testimonials"><blockquote><p>Exceptional work.</p>'
        . '<footer class="quote-attribution">Casey Rivera</footer>'
        . '</blockquote></section></main>'
        . '<footer><p>Northstar Studio</p></footer>';
    $llm->queueText($homeBody);
    $llm->queueText('FOOTER-DEPTH-REPAIR-SENTINEL');

    try {
        inner_pages_run($project, $llm);

        assert_eq($homeBody, $project->readText('design/home-body.html'));
        assert_true(!$project->exists('design/home-body.failed'));
        assert_eq(0, $llm->completeCalls, 'valid nested footer must not consume serial repair completion');
        assert_eq('FOOTER-DEPTH-REPAIR-SENTINEL', $llm->complete('sentinel probe'));
    } finally {
        inner_pages_cleanup($tmp);
    }
});

test('home-body footer depth change keeps top-level footer and all-depth main guards', function () {
    $validate = new ReflectionMethod(InnerPagesDesignStep::class, 'isValidHomeBodyFragment');
    $validate->setAccessible(true);
    $invalid = [
        'two top-level footers' => '<main><p>Body</p></main><footer></footer><footer></footer>',
        'nested second main' => '<main><section><main><p>Nested main</p></main></section></main><footer></footer>',
        'nested footer only' => '<main><blockquote><footer class="quote-attribution">Citation</footer></blockquote></main>',
        'footer nested in page footer' => '<main><p>Body</p></main><footer><footer>x</footer></footer>',
        'footer nested in address' => '<main><address><footer>x</footer></address></main><footer>Page</footer>',
    ];

    foreach ($invalid as $case => $html) {
        assert_true(!$validate->invoke(null, $html), "{$case} must remain invalid");
    }
});

test('inner-pages-design repairs one malformed page serially then marks only that page failed', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('valid', 'Valid', 'Must land'),
        inner_page('broken', 'Broken', 'Must isolate failure'),
    ]);
    $valid = '<main id="valid-main"><h1>Valid sibling lands</h1></main>';
    $llm->queueText(inner_pages_home_body());
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
    assert_contains('Broken authored fragment', $llm->calls[3]['prompt']);

    $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
    assert_contains('design/broken.html', $warnings);
    assert_contains('authored', $warnings);
    assert_contains('delivered', $warnings);
    assert_contains('design/broken.failed', $warnings);
    assert_contains('disposition', $warnings);
    inner_pages_cleanup($tmp);
});

test('inner-pages-design rerun clears stale failed markers after primary and repaired writes', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('primary', 'Primary', 'Succeed directly on rerun'),
        inner_page('repaired', 'Repaired', 'Succeed after repair on rerun'),
    ]);

    $llm->queueText(inner_pages_home_body('FIRST-RUN-HOME-BODY'));
    $llm->queueText('<section>Primary first failure</section>');
    $llm->queueText('<section>Repaired first failure</section>');
    $llm->queueText('<div>Primary repair still invalid</div>');
    $llm->queueText('<div>Repaired repair still invalid</div>');
    inner_pages_run($project, $llm);
    assert_true($project->exists('design/primary.failed'));
    assert_true($project->exists('design/repaired.failed'));

    $primary = '<main id="primary-main"><h1>Primary now valid</h1></main>';
    $repaired = '<main id="repaired-main"><h1>Repair now valid</h1></main>';
    $llm->queueText(inner_pages_home_body('SECOND-RUN-HOME-BODY'));
    $llm->queueText($primary);
    $llm->queueText('<section>Needs one rerun repair</section>');
    $llm->queueText($repaired);
    inner_pages_run($project, $llm);

    assert_eq($primary, $project->readText('design/primary.html'));
    assert_eq($repaired, $project->readText('design/repaired.html'));
    assert_true(!$project->exists('design/primary.failed'), 'primary success clears stale marker');
    assert_true(!$project->exists('design/repaired.failed'), 'repair success clears stale marker');
    assert_eq(2, $llm->completeBatchCalls, 'one page batch per run');
    assert_eq(3, $llm->completeCalls, 'two first-run repairs plus one rerun repair');
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
    $llm->queueText(inner_pages_home_body());
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

test('inner-pages-design accepts realistic page CSS while retaining a 16 KiB ceiling', function () {
    $isValidFragment = new ReflectionMethod(InnerPagesDesignStep::class, 'isValidFragment');
    $isValidFragment->setAccessible(true);

    $realisticCss = str_repeat('.x{color:red}', 461);
    $runawayCss = str_repeat('.x{color:red}', 1261);
    assert_eq(5993, strlen($realisticCss));
    assert_true(strlen($runawayCss) > 16384);

    assert_true(!$isValidFragment->invoke(
        null,
        '<style data-page-css>' . $runawayCss . '</style><main><p>Runaway</p></main>',
    ), 'page CSS over 16384 bytes remains rejected');
    assert_true($isValidFragment->invoke(
        null,
        '<style data-page-css>' . $realisticCss . '</style><main><p>Realistic</p></main>',
    ), 'page CSS between 4096 and 16384 bytes is accepted');
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
    $llm->queueText(inner_pages_home_body());
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
    assert_true(!inner_pages_has_live_script($overlap));
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

test('page-generation prompts freeze fold-seeded inner and below-fold home contracts', function () {
    $path = repo_path('prompts/inner-page-design.md');
    assert_true(is_file($path), 'inner-page-design.md must exist');
    $prompt = strtolower((string) file_get_contents($path));

    foreach ([
        '<main>',
        'data-page-css',
        'prefer established site classes',
        'last resort',
        'minimal and well under 16 kb',
        'no preamble',
        'commentary',
        'explanation',
        'prose before or after',
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
        'design preview',
    ] as $required) {
        assert_contains($required, $prompt);
    }
    assert_true(!str_contains($prompt, '{{home_body}}'), 'inner prompt has no full-home cache placeholder');
    assert_true(!str_contains($prompt, 'homepage <main>'), 'inner prompt never names full-home main as seed');

    $homePath = repo_path('prompts/home-body-design.md');
    assert_true(is_file($homePath), 'home-body-design.md must exist');
    $homePrompt = strtolower((string) file_get_contents($homePath));
    foreach ([
        '<main>',
        '<footer>',
        'below the fold',
        'do not emit a <header>',
        'do not repeat the hero',
        'design preview',
        'prefer established site classes',
        'minimize new page-specific classes',
        'no preamble',
        'commentary',
        'explanation',
        'prose before or after',
        'no javascript',
    ] as $required) {
        assert_contains($required, $homePrompt);
    }

    $source = (string) file_get_contents(repo_path('src/Steps/InnerPagesDesignStep.php'));
    assert_true(!str_contains($source, 'homeReference('), 'full-home source helper is retired');
});

test('inner-pages-design deterministically suffixes reserved inner slugs without overwriting artifacts', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('landing', 'Landing', 'Front page'),
        inner_page('preview', 'Preview page', 'Reserved preview collision'),
        inner_page('home', 'Home page', 'Reserved home collision'),
        inner_page('site', 'Site page', 'Reserved site collision'),
    ]);
    $originalPreview = $project->readText('design/preview.html');
    $originalCss = $project->readText('design/site.css');
    $project->writeText('design/home.html', 'EXISTING-COMPOSED-HOME-MUST-SURVIVE');

    $llm->queueText(inner_pages_home_body());
    $llm->queueText('<main><p>RESERVED-PREVIEW-PAGE</p></main>');
    $llm->queueText('<main><p>RESERVED-HOME-PAGE</p></main>');
    $llm->queueText('<main><p>RESERVED-SITE-PAGE</p></main>');

    inner_pages_run($project, $llm);

    assert_eq($originalPreview, $project->readText('design/preview.html'));
    assert_eq($originalCss, $project->readText('design/site.css'));
    assert_eq('EXISTING-COMPOSED-HOME-MUST-SURVIVE', $project->readText('design/home.html'));
    assert_contains('RESERVED-PREVIEW-PAGE', $project->readText('design/preview-2.html'));
    assert_contains('RESERVED-HOME-PAGE', $project->readText('design/home-2.html'));
    assert_contains('RESERVED-SITE-PAGE', $project->readText('design/site-2.html'));
    assert_true(!$project->exists('design/site.html'));
    $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
    foreach (['preview', 'preview-2', 'home', 'home-2', 'site', 'site-2', 'disposition renamed'] as $needle) {
        assert_contains($needle, $warnings);
    }
    inner_pages_cleanup($tmp);
});

test('inner-pages-design balances safe-tag structural damage and document wrappers per page', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('repairable', 'Repairable', 'Repair crossed safe tags'),
        inner_page('wrapped-repair', 'Wrapped repair', 'Reject document wrapper'),
        inner_page('stray-closer', 'Stray closer', 'Reject stray closing tag'),
        inner_page('nested-document', 'Nested document', 'Reject nested document tags'),
        inner_page('sibling', 'Sibling', 'Land valid sibling'),
    ]);

    // One home-body response, then one batch response per inner page.
    $llm->queueText(inner_pages_home_body());
    $llm->queueText('<main><section><h1>A</h1></main>');
    $llm->queueText('<section>Missing main before wrapped repair</section>');
    $llm->queueText('<main><h1>Stray</h1></section></main>');
    $llm->queueText(
        '<main><!doctype html><head><title>Nested</title></head>'
        . '<body><p>Nested body</p></body></main>',
    );
    $sibling = '<main id="sibling-main"><h1>Valid sibling</h1></main>';
    $llm->queueText($sibling);

    // Only the no-main response needs semantic repair.
    $llm->queueText('<html><body><main><h1>Still wrapped</h1></main></body></html>');

    inner_pages_run($project, $llm);

    assert_eq(1, $llm->completeBatchCalls);
    assert_eq(1, $llm->completeCalls, 'only the no-main fragment gets one serial repair');
    assert_eq(
        '<main><section><h1>A</h1></section></main>',
        $project->readText('design/repairable.html'),
    );
    assert_eq(
        '<main><h1>Still wrapped</h1></main>',
        $project->readText('design/wrapped-repair.html'),
    );
    assert_eq('<main><h1>Stray</h1></main>', $project->readText('design/stray-closer.html'));
    assert_eq(
        '<main>Nested<p>Nested body</p></main>',
        $project->readText('design/nested-document.html'),
    );
    assert_eq($sibling, $project->readText('design/sibling.html'));
    foreach (['repairable', 'wrapped-repair', 'stray-closer', 'nested-document', 'sibling'] as $slug) {
        assert_true(!$project->exists("design/{$slug}.failed"));
    }
    inner_pages_cleanup($tmp);
});

test('inner-pages-design caches the whole fold despite fake main text in head style and comment', function () {
    $preview = '<!doctype html><html><head>'
        . '<style>.bait::before{content:"<main id=\'fake-style-main\'>FAKE STYLE</main>"}</style>'
        . '<!-- <main id="fake-comment-main">FAKE COMMENT</main> -->'
        . '</head><body><header>EXACT-FOLD-HEADER</header>'
        . '<main><section id="hero">EXACT-FOLD-HERO</section></main></body></html>';
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('about', 'About', 'Explain the studio'),
    ], $preview);
    $llm->queueText(inner_pages_home_body());
    $llm->queueText('<main><h1>About</h1></main>');

    inner_pages_run($project, $llm);

    $foldPrefix = $llm->calls[0]['opts']['cached_prefixes'][1] ?? '';
    assert_eq($preview . "\n\n", $foldPrefix);
    assert_contains('fake-style-main', $foldPrefix, 'fold seed is byte-faithful, not DOM-normalized');
    assert_contains('fake-comment-main', $foldPrefix, 'inert fold comment bytes remain in cache');
    assert_contains('EXACT-FOLD-HEADER', $foldPrefix);
    assert_contains('EXACT-FOLD-HERO', $foldPrefix);
    inner_pages_cleanup($tmp);
});

test('inner-pages-design cache separators assemble byte-identically across providers', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('about', 'About', 'Explain the studio'),
    ]);
    $llm->queueText(inner_pages_home_body());
    $llm->queueText('<main><h1>About</h1></main>');

    inner_pages_run($project, $llm);

    $call = $llm->calls[0];
    $prefixes = $call['opts']['cached_prefixes'] ?? [];
    assert_eq(2, count($prefixes));
    foreach ($prefixes as $prefix) {
        assert_true(str_ends_with($prefix, "\n\n"), 'cache layer ends with one blank-line separator');
        assert_true(!str_ends_with($prefix, "\n\n\n"), 'cache layer has no third trailing newline');
    }

    $request = ['prompt' => $call['prompt']] + $call['opts'];
    $anthropic = AnthropicClient::bodyFor($request, 'claude-sonnet-4-6', 16000);
    $anthropicContent = $anthropic['messages'][0]['content'];
    assert_true(is_array($anthropicContent));
    $anthropicUser = implode('', array_map(
        static fn (array $block): string => (string) ($block['text'] ?? ''),
        $anthropicContent,
    ));
    $openAi = OpenAiCompatibleClient::bodyFor(
        $request,
        'gpt-5.2',
        16000,
        'openai',
    );
    assert_eq($anthropicUser, $openAi['messages'][1]['content']);
    inner_pages_cleanup($tmp);
});
