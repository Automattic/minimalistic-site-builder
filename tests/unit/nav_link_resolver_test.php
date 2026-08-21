<?php
declare(strict_types=1);

use Automattic\SiteBuild\DeterministicNavLinkResolver;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Steps\ResolveNavLinksStep;

/** @return list<array{label:string,path:string,anchors:list<string>}> */
function nav_link_pages(): array
{
    return [
        ['label' => 'Home', 'path' => '/', 'anchors' => ['hero', 'features']],
        ['label' => 'About & Services', 'path' => '/about-services/', 'anchors' => ['team']],
        ['label' => 'Contact', 'path' => '/contact/', 'anchors' => ['form']],
    ];
}

test('nav link resolver maps the azure garden navigation labels to every site page', function () {
    $resolver = new DeterministicNavLinkResolver();
    $pages = [
        ['label' => 'Home', 'path' => '/', 'anchors' => ['hero']],
        ['label' => 'Coaching Philosophy', 'path' => '/coaching-philosophy/', 'anchors' => []],
        ['label' => 'Services', 'path' => '/services/', 'anchors' => []],
        ['label' => 'Results & Testimonials', 'path' => '/results-testimonials/', 'anchors' => []],
        ['label' => 'Contact', 'path' => '/contact/', 'anchors' => []],
    ];
    $input = '<nav>'
        . '<a href="#hero">Home</a>'
        . '<a href="#hero">Philosophy</a>'
        . '<a href="#hero">Services</a>'
        . '<a href="#hero">Results</a>'
        . '<a href="#hero">Contact</a>'
        . '</nav>';

    assert_eq(5, substr_count($input, 'href="#hero"'));

    $result = $resolver->resolve($input, $pages, 'theme/parts/header.html', null);

    assert_eq(
        '<nav>'
            . '<a href="/">Home</a>'
            . '<a href="/coaching-philosophy/">Philosophy</a>'
            . '<a href="/services/">Services</a>'
            . '<a href="/results-testimonials/">Results</a>'
            . '<a href="/contact/">Contact</a>'
            . '</nav>',
        $result['markup'],
    );
    assert_eq(5, count($result['repairs']));
    assert_eq([], $result['warnings']);
});

test('nav link resolver roots an unmatched brand sharing the front page placeholder', function () {
    $resolver = new DeterministicNavLinkResolver();
    $pages = [
        ['label' => 'Home', 'path' => '/', 'anchors' => ['hero']],
        ['label' => 'Coaching Philosophy', 'path' => '/coaching-philosophy/', 'anchors' => []],
    ];
    $input = '<nav>'
        . '<a href="#hero"><strong>Acme Coaching</strong></a>'
        . '<a href="#hero">Home</a>'
        . '<a href="#hero">Philosophy</a>'
        . '</nav>';

    $result = $resolver->resolve($input, $pages, 'theme/parts/header.html', null);

    assert_eq(
        '<nav>'
            . '<a href="/"><strong>Acme Coaching</strong></a>'
            . '<a href="/">Home</a>'
            . '<a href="/coaching-philosophy/">Philosophy</a>'
            . '</nav>',
        $result['markup'],
    );
    assert_eq(3, count($result['repairs']));
    assert_eq([], $result['warnings']);

    $pageOwned = $resolver->resolve($input, $pages, 'design/home.html', '/');
    assert_eq($input, $pageOwned['markup']);
    assert_eq([], $pageOwned['repairs']);
    assert_eq([], $pageOwned['warnings']);
});

test('nav link resolver leaves an ambiguous partial label untouched and names every candidate', function () {
    $resolver = new DeterministicNavLinkResolver();
    $pages = [
        ['label' => 'Home', 'path' => '/', 'anchors' => ['hero']],
        ['label' => 'Coaching Philosophy', 'path' => '/coaching-philosophy/', 'anchors' => []],
        ['label' => 'Philosophy Workshops', 'path' => '/philosophy-workshops/', 'anchors' => []],
    ];
    $input = '<nav><a href="#hero">Philosophy</a></nav>';

    $result = $resolver->resolve($input, $pages, 'theme/parts/header.html', null);

    assert_eq($input, $result['markup']);
    assert_eq([], $result['repairs']);
    assert_eq(1, count($result['warnings']));
    assert_eq('#hero', $result['warnings'][0]['authored']);
    assert_eq('#hero', $result['warnings'][0]['delivered']);
    assert_contains('Coaching Philosophy', $result['warnings'][0]['disposition']);
    assert_contains('/coaching-philosophy/', $result['warnings'][0]['disposition']);
    assert_contains('Philosophy Workshops', $result['warnings'][0]['disposition']);
    assert_contains('/philosophy-workshops/', $result['warnings'][0]['disposition']);
});

test('nav link resolver matches a navigation label against a page slug', function () {
    $resolver = new DeterministicNavLinkResolver();
    $pages = [
        ['label' => 'Home', 'path' => '/', 'anchors' => ['hero']],
        ['label' => 'How We Help', 'path' => '/executive-coaching/', 'anchors' => []],
    ];

    $result = $resolver->resolve(
        '<nav><a href="#hero">Coaching</a></nav>',
        $pages,
        'theme/parts/header.html',
        null,
    );

    assert_eq('<nav><a href="/executive-coaching/">Coaching</a></nav>', $result['markup']);
    assert_eq([], $result['warnings']);
});

test('nav link resolver gives slashless and trailing-slash page paths the same valid verdict', function () {
    $resolver = new DeterministicNavLinkResolver();
    $pages = [
        ['label' => 'Home', 'path' => '/', 'anchors' => ['hero']],
        ['label' => 'Services', 'path' => '/services/', 'anchors' => []],
    ];
    $slashless = '<nav><a href="/services">Services</a></nav>';
    $trailing = '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Services","url":"/services/"} /-->'
        . '<!-- /wp:navigation -->';

    $slashlessResult = $resolver->resolve(
        $slashless,
        $pages,
        'theme/parts/footer.html',
        null,
    );
    $trailingResult = $resolver->resolve(
        $trailing,
        $pages,
        'theme/parts/header.html',
        null,
    );

    assert_eq($slashless, $slashlessResult['markup']);
    assert_eq($trailing, $trailingResult['markup']);
    assert_eq([], $slashlessResult['warnings']);
    assert_eq([], $trailingResult['warnings']);
});

test('nav link resolver still unwraps a genuinely unresolvable internal destination', function () {
    $resolver = new DeterministicNavLinkResolver();
    $input = '<nav><span>before</span><a href="#missing"><em>Elsewhere</em></a><b>after</b></nav>';

    $result = $resolver->resolve($input, nav_link_pages(), 'theme/parts/footer.html', null);

    assert_eq(
        '<nav><span>before</span><em>Elsewhere</em><b>after</b></nav>',
        $result['markup'],
    );
    assert_eq([], $result['repairs']);
    assert_eq(1, count($result['warnings']));
    assert_eq('#missing', $result['warnings'][0]['authored']);
    assert_eq('removed', $result['warnings'][0]['delivered']);
    assert_contains('unresolvable', $result['warnings'][0]['disposition']);
});

test('nav link resolver rewrites a header label match to the site page path', function () {
    $resolver = new DeterministicNavLinkResolver();
    $input = '<header><nav aria-label="Primary"><a class="brand" href="#hero"><span>About &amp; Services</span></a></nav></header>';

    $result = $resolver->resolve($input, nav_link_pages(), 'theme/parts/header.html', null);

    assert_eq(
        '<header><nav aria-label="Primary"><a class="brand" href="/about-services/"><span>About &amp; Services</span></a></nav></header>',
        $result['markup'],
    );
    assert_eq('/about-services/', $result['repairs'][0]['delivered']);
    assert_eq([], $result['warnings']);
});

test('nav link resolver preserves page-owned anchors and roots shared and cross-page deep links', function () {
    $resolver = new DeterministicNavLinkResolver();
    $pageMarkup = '<main><nav><a href="#team">Our people</a></nav></main>';
    $sharedMarkup = '<header><nav><a href="#hero">Explore</a></nav></header>';
    $crossPageMarkup = '<main><nav><a href="#team">Our people</a></nav></main>';

    $samePage = $resolver->resolve(
        $pageMarkup,
        nav_link_pages(),
        'design/about-services.html',
        '/about-services/',
    );
    $shared = $resolver->resolve(
        $sharedMarkup,
        nav_link_pages(),
        'theme/parts/header.html',
        null,
    );
    $crossPage = $resolver->resolve(
        $crossPageMarkup,
        nav_link_pages(),
        'design/contact.html',
        '/contact/',
    );

    assert_eq($pageMarkup, $samePage['markup']);
    assert_eq([], $samePage['repairs']);
    assert_contains('href="/#hero"', $shared['markup']);
    assert_contains('href="/about-services/#team"', $crossPage['markup']);
});

test('nav link resolver preserves a genuine page-owned bare anchor despite a page-label match', function () {
    $resolver = new DeterministicNavLinkResolver();
    $input = '<main><nav><a href="#team">About &amp; Services</a></nav></main>';

    $result = $resolver->resolve(
        $input,
        nav_link_pages(),
        'design/about-services.html',
        '/about-services/',
    );

    assert_eq($input, $result['markup']);
    assert_eq([], $result['repairs']);
    assert_eq([], $result['warnings']);
});

test('nav link resolver warns with full context and unwraps only the unresolved link', function () {
    $resolver = new DeterministicNavLinkResolver();
    $child = '<span class="label">Mystery&nbsp;<em>place</em></span>';
    $input = '<header data-before="same"><nav><b>before</b><a class="dead" href="#missing">'
        . $child . '</a><i>after</i></nav><p>tail</p></header>';

    $result = $resolver->resolve($input, nav_link_pages(), 'theme/parts/header.html', null);

    assert_eq(
        '<header data-before="same"><nav><b>before</b>' . $child
            . '<i>after</i></nav><p>tail</p></header>',
        $result['markup'],
    );
    assert_eq([], $result['repairs']);
    assert_eq(1, count($result['warnings']));
    assert_eq([
        'file' => 'theme/parts/header.html',
        'block' => 'nav[1]/a[1]',
        'authored' => '#missing',
        'delivered' => 'removed',
        'disposition' => 'unwrapped unresolvable internal navigation link; preserved child bytes',
    ], $result['warnings'][0]);
});

test('nav link resolver is idempotent and preserves external correct and non-nav links', function () {
    $resolver = new DeterministicNavLinkResolver();
    $input = '<a href="#missing">outside nav</a><nav>'
        . '<a href="https://example.com/x#y">External</a>'
        . '<a href="/contact/">Contact us</a>'
        . '<a href="#form">Write us</a>'
        . '</nav>';

    $once = $resolver->resolve($input, nav_link_pages(), 'theme/parts/header.html', null);
    $twice = $resolver->resolve($once['markup'], nav_link_pages(), 'theme/parts/header.html', null);

    assert_eq(
        '<a href="#missing">outside nav</a><nav>'
            . '<a href="https://example.com/x#y">External</a>'
            . '<a href="/contact/">Contact us</a>'
            . '<a href="/contact/#form">Write us</a>'
            . '</nav>',
        $once['markup'],
    );
    assert_eq($once['markup'], $twice['markup']);
    assert_eq([], $twice['repairs']);
    assert_eq([], $twice['warnings']);
});

test('nav link resolver drops an invalid fragment from a page-label match', function () {
    $resolver = new DeterministicNavLinkResolver();
    $input = '<nav><a data-note="href=&quot;kept&quot;" href="#hero">Contact</a></nav>';

    $result = $resolver->resolve($input, nav_link_pages(), 'theme/parts/header.html', null);

    assert_eq(
        '<nav><a data-note="href=&quot;kept&quot;" href="/contact/">Contact</a></nav>',
        $result['markup'],
    );
});

test('nav link resolver unwraps a navigation anchor with no href', function () {
    $resolver = new DeterministicNavLinkResolver();
    $input = '<nav><a class="dead"><strong>Unknown</strong></a><span>sibling</span></nav>';

    $result = $resolver->resolve($input, nav_link_pages(), 'theme/parts/header.html', null);

    assert_eq('<nav><strong>Unknown</strong><span>sibling</span></nav>', $result['markup']);
    assert_eq('missing', $result['warnings'][0]['authored']);
    assert_eq('removed', $result['warnings'][0]['delivered']);
});

test('nav link resolver rewrites compiler navigation blocks and isolates an unresolved sibling', function () {
    $resolver = new DeterministicNavLinkResolver();
    $input = '<!-- wp:navigation {"overlayMenu":"never"} -->' . "\n"
        . '<!-- wp:navigation-link {"label":"\u003cspan\u003eAbout & Services\u003c/span\u003e","url":"#hero","kind":"custom"} /-->' . "\n"
        . '<!-- wp:navigation-link {"label":"External","url":"https://example.com/x#y","kind":"custom"} /-->' . "\n"
        . '<!-- wp:navigation-link {"label":"Contact","url":"/contact/","kind":"custom"} /-->' . "\n"
        . '<!-- wp:navigation-link {"label":"\\u003cspan\\u003eMystery\\u003c/span\\u003e","url":"#missing","kind":"custom"} /-->' . "\n"
        . '<!-- /wp:navigation -->';

    $once = $resolver->resolve($input, nav_link_pages(), 'theme/parts/header.html', null);
    $twice = $resolver->resolve($once['markup'], nav_link_pages(), 'theme/parts/header.html', null);

    assert_eq(
        '<!-- wp:navigation {"overlayMenu":"never"} -->' . "\n"
            . '<!-- wp:navigation-link {"label":"\u003cspan\u003eAbout & Services\u003c/span\u003e","url":"/about-services/","kind":"custom"} /-->' . "\n"
            . '<!-- wp:navigation-link {"label":"External","url":"https://example.com/x#y","kind":"custom"} /-->' . "\n"
            . '<!-- wp:navigation-link {"label":"Contact","url":"/contact/","kind":"custom"} /-->' . "\n"
            . '<span>Mystery</span>' . "\n"
            . '<!-- /wp:navigation -->',
        $once['markup'],
    );
    assert_eq('#hero', $once['repairs'][0]['authored']);
    assert_eq('/about-services/', $once['repairs'][0]['delivered']);
    assert_eq('navigation[1]/navigation-link[4]', $once['warnings'][0]['block']);
    assert_eq('#missing', $once['warnings'][0]['authored']);
    assert_eq('removed', $once['warnings'][0]['delivered']);
    assert_eq($once['markup'], $twice['markup']);
    assert_eq([], $twice['repairs']);
    assert_eq([], $twice['warnings']);
});

test('resolve-nav-links step persists repairs and actionable isolated-loss warnings', function () {
    with_project('resolve_nav_links_', function (Project $project): void {
        $project->writeJson('pages.json', ['pages' => [
            [
                'slug' => 'home',
                'title' => 'Home',
                'path' => '/',
                'sections' => [['slug' => 'hero']],
            ],
            [
                'slug' => 'about',
                'title' => 'About',
                'path' => '/about/',
                'sections' => [['slug' => 'team']],
            ],
        ]]);
        $home = '<section id="hero"><nav><a href="#hero">Home</a></nav></section>';
        $project->writeText('theme/parts/page-home--hero.html', $home);
        $project->writeText('theme/parts/page-about--team.html', '<section id="team"><h1>Team</h1></section>');
        $project->writeText(
            'theme/parts/header.html',
            '<header><nav><a href="#hero">Explore</a><a href="#hero">About</a>'
                . '<b>before</b><a class="dead" href="#missing"><span>Mystery</span></a>'
                . '<i>after</i></nav></header>',
        );
        $footer = '<footer><nav><a href="/about/">About</a></nav></footer>';
        $project->writeText('theme/parts/footer.html', $footer);

        (new ResolveNavLinksStep())->run($project);

        assert_eq(
            '<header><nav><a href="/#hero">Explore</a><a href="/about/">About</a>'
                . '<b>before</b><span>Mystery</span><i>after</i></nav></header>',
            $project->readText('theme/parts/header.html'),
        );
        assert_eq($home, $project->readText('theme/parts/page-home--hero.html'));
        assert_eq($footer, $project->readText('theme/parts/footer.html'));

        $warnings = $project->readJson('warnings.json')['resolve-nav-links'] ?? [];
        assert_eq(1, count($warnings));
        foreach (['file ', 'block_path ', 'authored_value ', 'delivered_value ', 'disposition '] as $field) {
            assert_contains($field, $warnings[0]);
        }
        assert_contains('theme/parts/header.html', $warnings[0]);
        assert_contains('#missing', $warnings[0]);
        assert_contains('removed', $warnings[0]);

        $report = $project->readJson('reports/nav-links.json');
        assert_eq(2, count($report['repairs']));
        assert_eq(1, count($report['warnings']));
    });
});

test('resolve-nav-links step replaces stale warnings from an earlier resumed run', function () {
    with_project('resolve_nav_stale_warnings_', function (Project $project): void {
        $project->writeJson('pages.json', ['pages' => [[
            'slug' => 'home',
            'title' => 'Home',
            'path' => '/',
            'sections' => [['slug' => 'hero']],
        ]]]);
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<section id="hero"><h1>Home</h1></section>',
        );
        $project->writeText(
            'theme/parts/header.html',
            '<header><nav><a href="/">Home</a></nav></header>',
        );
        $project->writeJson('warnings.json', [
            'resolve-nav-links' => ['stale removed link warning'],
            'other-step' => ['current unrelated warning'],
        ]);

        (new ResolveNavLinksStep())->run($project);

        assert_eq(
            ['other-step' => ['current unrelated warning']],
            $project->readJson('warnings.json'),
        );
        assert_eq([], $project->readJson('reports/nav-links.json')['warnings']);
    });
});

test('resolve-nav-links step discovers ids only on real opening tags', function () {
    with_project('resolve_nav_real_anchors_', function (Project $project): void {
        $project->writeJson('pages.json', ['pages' => [[
            'slug' => 'home',
            'title' => 'Home',
            'path' => '/',
            'sections' => [['slug' => 'content']],
        ]]]);
        $project->writeText(
            'theme/parts/page-home--content.html',
            '<!-- id="comment-only" -->'
                . '<p data-note=\'id="nested-only"\'>id="text-only"</p>'
                . '<section id="real-anchor">Content</section>',
        );
        $project->writeText(
            'theme/parts/header.html',
            '<header><nav>'
                . '<a href="#comment-only">Comment</a>'
                . '<a href="#nested-only">Nested</a>'
                . '<a href="#text-only">Text</a>'
                . '<a href="#real-anchor">Real</a>'
                . '</nav></header>',
        );

        (new ResolveNavLinksStep())->run($project);

        assert_eq(
            '<header><nav>CommentNestedText<a href="/#real-anchor">Real</a></nav></header>',
            $project->readText('theme/parts/header.html'),
        );
        $warnings = $project->readJson('reports/nav-links.json')['warnings'];
        assert_eq(3, count($warnings));
        assert_eq(['#comment-only', '#nested-only', '#text-only'], array_column($warnings, 'authored'));
    });
});

test('nav link resolver context-encodes rewritten literal href values', function () {
    $resolver = new DeterministicNavLinkResolver();
    $pages = nav_link_pages();
    $pages[] = ['label' => 'Quoted', 'path' => '/say-"hello"/', 'anchors' => []];
    $input = '<nav><a href="#">Quoted</a></nav>';

    $once = $resolver->resolve($input, $pages, 'theme/parts/header.html', null);
    $twice = $resolver->resolve($once['markup'], $pages, 'theme/parts/header.html', null);

    assert_eq('<nav><a href="/say-&quot;hello&quot;/">Quoted</a></nav>', $once['markup']);
    assert_eq('/say-"hello"/', $once['repairs'][0]['delivered']);
    assert_eq($once['markup'], $twice['markup']);
    assert_eq([], $twice['repairs']);
});

test('nav link resolver never materializes executable decoded block-label markup', function () {
    $resolver = new DeterministicNavLinkResolver();
    $input = '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"\\u003cspan\\u003eBefore\\u003c/span\\u003e'
        . '\\u003cscript\\u003ealert(1)\\u003c/script\\u003eAfter","url":"#missing"} /-->'
        . '<!-- wp:navigation-link {"label":"Contact","url":"/contact/"} /-->'
        . '<!-- /wp:navigation -->';

    $result = $resolver->resolve($input, nav_link_pages(), 'theme/parts/header.html', null);

    assert_eq(
        '<!-- wp:navigation --><span>Before</span>After'
            . '<!-- wp:navigation-link {"label":"Contact","url":"/contact/"} /-->'
            . '<!-- /wp:navigation -->',
        $result['markup'],
    );
    assert_true(!str_contains($result['markup'], '<script'));
    assert_true(!str_contains($result['markup'], 'alert(1)'));
});

test('nav link resolver preserves current page bare anchor when another page repeats the id', function () {
    $resolver = new DeterministicNavLinkResolver();
    $pages = nav_link_pages();
    $pages[] = ['label' => 'Other', 'path' => '/other/', 'anchors' => ['team']];
    $input = '<main><nav><a href="#team">About &amp; Services</a></nav></main>';

    $result = $resolver->resolve(
        $input,
        $pages,
        'design/about-services.html',
        '/about-services/',
    );

    assert_eq($input, $result['markup']);
    assert_eq([], $result['repairs']);
    assert_eq([], $result['warnings']);
});

test('nav link resolver adds missing literal href only for a page-label match', function () {
    $resolver = new DeterministicNavLinkResolver();
    $input = '<nav><a class="known">Contact</a><i>same</i>'
        . '<a class="unknown"><span>Mystery</span></a></nav>';

    $result = $resolver->resolve($input, nav_link_pages(), 'theme/parts/header.html', null);

    assert_eq(
        '<nav><a class="known" href="/contact/">Contact</a><i>same</i>'
            . '<span>Mystery</span></nav>',
        $result['markup'],
    );
    assert_eq('missing', $result['repairs'][0]['authored']);
    assert_eq('/contact/', $result['repairs'][0]['delivered']);
    assert_eq('missing', $result['warnings'][0]['authored']);
    assert_eq('removed', $result['warnings'][0]['delivered']);
});

test('nav link resolver adds missing block URL only for a page-label match', function () {
    $resolver = new DeterministicNavLinkResolver();
    $input = '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Contact","kind":"custom"} /-->'
        . '<b>same</b>'
        . '<!-- wp:navigation-link {"label":"Mystery","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';

    $result = $resolver->resolve($input, nav_link_pages(), 'theme/parts/header.html', null);

    assert_eq(
        '<!-- wp:navigation -->'
            . '<!-- wp:navigation-link {"label":"Contact","kind":"custom","url":"/contact/"} /-->'
            . '<b>same</b>Mystery<!-- /wp:navigation -->',
        $result['markup'],
    );
    assert_eq('missing', $result['repairs'][0]['authored']);
    assert_eq('/contact/', $result['repairs'][0]['delivered']);
    assert_eq('missing', $result['warnings'][0]['authored']);
    assert_eq('removed', $result['warnings'][0]['delivered']);
});

test('nav link resolver quotes a rewritten unquoted href before decoded whitespace can inject an attribute', function () {
    $resolver = new DeterministicNavLinkResolver();
    $input = '<nav><a href=#x&#32;onmouseover=alert(1)>Contact</a></nav>';

    $result = $resolver->resolve($input, nav_link_pages(), 'theme/parts/header.html', null);

    assert_eq('<nav><a href="/contact/">Contact</a></nav>', $result['markup']);
    assert_true(!str_contains($result['markup'], 'onmouseover'));
    assert_eq('/contact/', $result['repairs'][0]['delivered']);
});

/**
 * sunny-ember's header CTA is `<a class="nav-cta" href="#contact">Book a session</a>`
 * inside the menu list. Its label matches no page, and the `contact` anchor
 * appears on more than one page, so the anchor-owner fallback finds two owners
 * and gives up — which DELETED the item. As a raw anchor it merely stayed
 * unresolved and still rendered; as a navigation-link it disappeared entirely,
 * because core/navigation renders inner blocks and drops stray text.
 *
 * The site has a real `contact` page. A fragment that names a page slug is a
 * destination, so it resolves there instead of being dropped.
 */
test('nav link resolver resolves an ambiguous fragment that names a page slug', function () {
    $resolver = new DeterministicNavLinkResolver();
    // `contact` is an anchor on two pages AND the slug of a third page.
    $pages = [
        ['label' => 'Home', 'path' => '/', 'anchors' => ['hero', 'contact']],
        ['label' => 'Testimonials', 'path' => '/testimonials/', 'anchors' => ['contact']],
        ['label' => 'Contact', 'path' => '/contact/', 'anchors' => ['form']],
    ];
    $input = '<!-- wp:navigation {"overlayMenu":"never"} -->' . "\n"
        . '<!-- wp:navigation-link {"label":"Book a session","url":"#contact","kind":"custom"} /-->' . "\n"
        . '<!-- /wp:navigation -->';

    $result = $resolver->resolve($input, $pages, 'theme/parts/header.html', null);

    assert_contains(
        '"url":"/contact/"',
        $result['markup'],
        'the CTA resolves to the page the fragment names',
    );
    assert_contains(
        'Book a session',
        $result['markup'],
        'the CTA survives as a navigation-link rather than being unwrapped away',
    );
    assert_eq([], $result['warnings'], 'a resolvable destination records no loss');
});
