<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContactFacts;
use Automattic\SiteBuild\GroundedContactMarkup;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\SectionUnit;

/** @return array<string,mixed> */
function section_unit_input(): array
{
    return [
        'site_spec'        => '{"name":"UNIT-SPEC-SENTINEL"}',
        'language'         => 'unit-language-sentinel',
        'theme_json'       => '{"unit-theme-sentinel":true}',
        'design_direction' => 'UNIT-DIRECTION-SENTINEL',
        'card_style'       => 'flush',
        'outline'          => '1. UNIT-OUTLINE-SENTINEL (hero)',
        'site_pages'       => '- "Home" — / (front page): UNIT-PAGES-SENTINEL',
        'page'             => [
            'slug'  => 'unit-page',
            'title' => 'UNIT-PAGE-TITLE-SENTINEL',
            'path'  => '/unit-page/',
        ],
        'section'          => [
            'slug'             => 'unit-section',
            'title'            => 'UNIT-TITLE-SENTINEL',
            'role'             => 'hero',
            'type'             => 'hero',
            'purpose'          => 'UNIT-PURPOSE-SENTINEL',
            'content_notes'    => 'UNIT-NOTES-SENTINEL',
            'layout_archetype' => 'full-bleed-cover',
            'background'       => 'image',
            'vertical_density' => 'standard',
            'handoff'          => 'UNIT-HANDOFF-SENTINEL',

        ],
        'neighbors' => 'UNIT-NEIGHBORS-SENTINEL',
        'header_contract' => 'UNIT-HEADER-CONTRACT-SENTINEL',
    ];
}

/** Reconstruct the logical prompt text from a layered LLM request. */
function section_unit_request_text(array $request): string
{
    return implode('', $request['cached_prefixes'] ?? []) . $request['prompt'];
}

test('section prompt keeps dash-free headings semantically lossless', function () {
    $prompt = (string) file_get_contents(repo_path('prompts/section.md'));

    assert_contains('no em or en dashes', $prompt, 'all section heading levels reject dash-joined labels');
    assert_contains('preserve both endpoints', $prompt, 'semantic ranges cannot lose a bound');
    assert_contains('From 2004 to 2024', $prompt, 'ranges have a dash-free heading form');
    assert_contains('move the intact range into supporting copy', $prompt, 'ranges may move without losing meaning');
});

test('section prompt keeps unbreakable contact tokens out of display type', function () {
    $prompt = (string) file_get_contents(repo_path('prompts/section.md'));

    assert_contains('email addresses, long URLs', $prompt, 'the affected token shapes are named');
    assert_contains('never take a display or heading scale', $prompt, 'large-type prohibition covers paragraphs and headings');
    assert_contains('`lead`-at-most mailto link or a button labeled with words', $prompt, 'safe contact treatments remain available');
    assert_true(!str_contains($prompt, 'inquiries@alcortaph'), 'malformed cohort identity copy is excluded');
});

test('centered-stack prompts keep wrapping copy aligned to the writing-direction start', function () {
    $section = (string) file_get_contents(repo_path('prompts/section.md'));
    $composition = (string) file_get_contents(repo_path('prompts/section-composition.md'));
    $pagePlan = (string) file_get_contents(repo_path('prompts/page-plan.md'));

    assert_contains('center display type only', $section, 'short display lines may remain centered');
    assert_contains('writing direction\'s start edge', $section, 'reading copy follows language direction');
    assert_contains('`"align":"left"` for LTR', $section, 'LTR Gutenberg mapping is explicit');
    assert_contains('`"align":"right"` for RTL', $section, 'RTL Gutenberg mapping is explicit');
    foreach ([$composition, $pagePlan] as $centeredStackContract) {
        assert_contains('start-aligned', $centeredStackContract);
        assert_contains('left for LTR', $centeredStackContract);
        assert_contains('right for RTL', $centeredStackContract);
    }
});

test('section prompt balances media rows without padding or detaching copy', function () {
    $prompt = (string) file_get_contents(repo_path('prompts/section.md'));

    assert_contains('at the desktop side-by-side state', $prompt, 'the blank-quadrant rule targets the affected layout');
    assert_contains('NEVER add, repeat, or invent copy', $prompt, 'content cannot be padded to satisfy geometry');
    assert_contains('smallest composition-only change', $prompt, 'repairs stay in the layout domain');
    assert_contains('directly UNDER its own media', $prompt, 'stacking retains ownership');
    assert_contains('preserving its media association and reading order', $prompt, 'moves remain semantics-safe');
    assert_contains('Stacked mobile columns need no artificial height matching', $prompt, 'mobile stacking is exempt');
    assert_true(!str_contains($prompt, 'fold the lines into a neighboring column'), 'ambiguous reassignment is absent');
});

test('SectionUnit generates normalized markup from self-contained input', function () {
    $llm = new FakeLlm();
    $llm->queueText(
        "```html\n"
        . '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset--spacing--xl"}}}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->'
        . "\n```"
    );
    $unit = new SectionUnit(
        $llm,
        new PromptRenderer(repo_path('prompts')),
        'unit-model',
        0.42,
    );

    $result = $unit->generate(section_unit_input());
    $markup = $result->markup;

    assert_eq(1, $llm->completeCalls, 'one direct unit execution uses one single completion');
    assert_eq(0, $llm->completeBatchCalls, 'the single-unit wrapper does not create a batch');
    assert_eq('unit-model', $llm->calls[0]['opts']['model'] ?? null);
    assert_eq(0.42, $llm->calls[0]['opts']['temperature'] ?? null);
    assert_eq('page-unit-page--unit-section', $llm->calls[0]['opts']['log_label'] ?? null);

    $sent = $llm->calls[0]['opts'] + ['prompt' => $llm->calls[0]['prompt']];
    assert_eq(3, count($sent['cached_prefixes'] ?? []), 'direct execution forwards every cache layer');
    $prompt = section_unit_request_text($sent);
    assert_contains('Role:     hero', $prompt);
    assert_contains('ASSIGNED CARD STYLE (authoritative machine contract): flush', $prompt);
    foreach ([
        'UNIT-SPEC-SENTINEL',
        'unit-language-sentinel',
        'unit-theme-sentinel',
        'UNIT-DIRECTION-SENTINEL',
        'UNIT-OUTLINE-SENTINEL',
        'UNIT-TITLE-SENTINEL',
        'UNIT-PURPOSE-SENTINEL',
        'UNIT-NOTES-SENTINEL',
        'UNIT-HANDOFF-SENTINEL',
        'UNIT-NEIGHBORS-SENTINEL',
        'UNIT-PAGES-SENTINEL',
        'UNIT-PAGE-TITLE-SENTINEL',
        'AI_IMAGE:',
    ] as $sentinel) {
        assert_contains($sentinel, $prompt);
    }

    assert_true(!str_contains($markup, '```'), 'code fence removed');
    assert_contains('var:preset|spacing|xl', $markup, 'preset reference normalized');
    assert_eq([], $result->warnings, 'semantics-preserving normalization is not a durable warning');
    assert_eq(
        ['response-fence-removed', 'preset-reference-canonicalized'],
        array_column($result->repairs, 'code')
    );
    assert_eq($result->toArray(), json_decode((string) json_encode($result), true));
});

test('SectionUnit removes the smallest blocks carrying invented contact facts', function () {
    $input = section_unit_input();
    $input['site_spec'] = [
        'name' => 'Grounded Bakery',
        'email' => 'hello@grounded.example',
        'phone' => '+1 207 555 0100',
        'website' => 'https://grounded.example/contact',
        'location' => ['line1' => '42 Grounded Road'],
    ];
    $safe = '<!-- wp:paragraph --><p>Fresh bread daily.</p><!-- /wp:paragraph -->';
    $grounded = '<!-- wp:paragraph --><p><a href="mailto:hello@grounded.example">Email us</a> '
        . 'or call +1 (207) 555-0100 at 42 Grounded Road. '
        . '<a href="https://grounded.example/contact">Directions</a></p><!-- /wp:paragraph -->';
    $invented = '<!-- wp:paragraph --><p><a href="mailto:fake@example.com">fake@example.com</a> '
        . 'at 24 Market Street.</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($safe . $grounded . $invented, $input);

    assert_contains($safe, $result->markup, 'unrelated sibling stays byte-for-byte intact');
    assert_contains($grounded, $result->markup, 'canonical phone formatting and exact email stay grounded');
    assert_true(!str_contains($result->markup, 'fake@example.com'));
    assert_true(!str_contains($result->markup, '24 Market Street'));
    assert_eq(1, count($result->warnings), 'one contaminated paragraph is one isolated removal');
    assert_contains("file=\"theme/parts/page-unit-page--unit-section.html\"", $result->warnings[0]);
    assert_contains('block="wp:paragraph[2]"', $result->warnings[0]);
    assert_contains('delivered=removed', $result->warnings[0]);

    $fixedPoint = $unit->finish($result->markup, $input);
    assert_eq($result->markup, $fixedPoint->markup);
    assert_eq([], $fixedPoint->warnings, 'contact removal reaches a fixed point');
});

test('SectionUnit distinguishes block IDs from phones and catches tel-only destinations', function () {
    $input = section_unit_input();
    $image = '<!-- wp:image {"id":1234567890} -->'
        . '<figure class="wp-block-image"><img src="photo.jpg" alt="Bread"/></figure><!-- /wp:image -->';
    $button = '<!-- wp:buttons --><div class="wp-block-buttons">'
        . '<!-- wp:button {"url":"tel:2075550199"} -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" '
        . 'href="tel:2075550199">Call</a></div><!-- /wp:button -->'
        . '</div><!-- /wp:buttons -->';
    $visiblePhone = '<!-- wp:paragraph --><p>Call 2075550199 ext 42</p><!-- /wp:paragraph -->';
    $protocolRelative = '<!-- wp:paragraph --><p><a href="//invented.example/contact">Visit</a></p>'
        . '<!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($image . $button . $protocolRelative . $visiblePhone, $input);

    assert_contains($image, $result->markup, 'a numeric media ID is not a phone number');
    assert_true(!str_contains($result->markup, 'tel:2075550199'), 'a destination-only invented phone is removed');
    assert_true(!str_contains($result->markup, '2075550199 ext 42'), 'a cue exposes a separator-free visible phone');
    assert_true(!str_contains($result->markup, 'invented.example'), 'protocol-relative URLs are still external URLs');
    assert_eq(3, count($result->warnings));
    assert_contains(
        'block="wp:buttons[0]"',
        implode("\n", $result->warnings),
        'the newly empty buttons wrapper is removed too',
    );
});

test('SectionUnit preserves dates and obvious dotted file or version tokens', function () {
    $safe = '<!-- wp:paragraph --><p>Open 2026-08-24. Download portrait.heic and menu.docx. '
        . 'Release v1.alpha. Published, January 2026. Order 1234567890.</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($safe, section_unit_input());

    assert_eq($safe, $result->markup, 'non-contact copy stays byte-for-byte intact');
    assert_eq([], $result->warnings);
});

test('SectionUnit preserves retina asset paths that contain an at sign', function () {
    $safe = '<!-- wp:image {"src":"/assets/logo@2x.png"} -->'
        . '<figure class="wp-block-image"><img src="/assets/logo@2x.png" alt="Logo"></figure><!-- /wp:image -->'
        . '<!-- wp:image {"src":"/assets/photo@2x.webp"} -->'
        . '<figure class="wp-block-image"><img src="/assets/photo@2x.webp" alt="Photo"></figure><!-- /wp:image -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($safe, section_unit_input());

    assert_eq($safe, $result->markup);
    assert_eq([], $result->warnings);
});

test('SectionUnit still rejects valid email shapes with file-like top-level domains', function () {
    $visibleZip = '<!-- wp:paragraph --><p>fake@example.zip</p><!-- /wp:paragraph -->';
    $visibleMov = '<!-- wp:paragraph --><p>fake@example.mov</p><!-- /wp:paragraph -->';
    $mailtoZip = '<!-- wp:button {"url":"mailto:fake@example.zip"} -->'
        . '<div class="wp-block-button"><a href="mailto:fake@example.zip">Email</a></div><!-- /wp:button -->';
    $mailtoMov = '<!-- wp:button {"url":"mailto:fake@example.mov"} -->'
        . '<div class="wp-block-button"><a href="mailto:fake@example.mov">Email</a></div><!-- /wp:button -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($visibleZip . $visibleMov . $mailtoZip . $mailtoMov, section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(4, count($result->warnings));
});

test('SectionUnit grounds mailto recipients before query parameters', function () {
    $invented = '<!-- wp:button {"url":"mailto:fake@example.com?subject=Hello"} -->'
        . '<div class="wp-block-button"><a href="mailto:fake@example.com?subject=Hello">Email</a></div>'
        . '<!-- /wp:button -->';
    $invalid = '<!-- wp:button {"url":"mailto:not-an-address?subject=Hello"} -->'
        . '<div class="wp-block-button"><a href="mailto:not-an-address?subject=Hello">Email</a></div>'
        . '<!-- /wp:button -->';
    $inventedCc = '<!-- wp:button {"url":"mailto:hello@grounded.example?cc=fake%40example.com"} -->'
        . '<div class="wp-block-button"><a href="mailto:hello@grounded.example?cc=fake%40example.com">Email</a></div>'
        . '<!-- /wp:button -->';
    $grounded = '<!-- wp:button {"url":"mailto:hello@grounded.example?subject=Hello"} -->'
        . '<div class="wp-block-button"><a href="mailto:hello@grounded.example?subject=Hello">Email</a></div>'
        . '<!-- /wp:button -->';
    $input = section_unit_input();
    $input['site_spec'] = ['email' => 'hello@grounded.example'];
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($invented . $invalid . $inventedCc . $grounded, $input);

    assert_eq($grounded, $result->markup, 'a query cannot hide an invented recipient');
    assert_eq(3, count($result->warnings));
    $warnings = implode("\n", $result->warnings);
    assert_contains('fake@example.com', $warnings);
    assert_contains('not-an-address', $warnings);
});

test('SectionUnit grounds every communication URI recipient and parameter', function () {
    $fax = '<!-- wp:button {"url":"fax:+12075550199"} -->'
        . '<div class="wp-block-button"><a href="fax:+12075550199">Fax</a></div><!-- /wp:button -->';
    $mms = '<!-- wp:button {"url":"mms:+12075550199"} -->'
        . '<div class="wp-block-button"><a href="mms:+12075550199">Message</a></div><!-- /wp:button -->';
    $body = '<!-- wp:button {"url":"sms:+12075550100?body=fake%40example.com"} -->'
        . '<div class="wp-block-button"><a href="sms:+12075550100?body=fake%40example.com">Text</a></div>'
        . '<!-- /wp:button -->';
    $input = section_unit_input();
    $input['site_spec'] = ['phone' => '+12075550100'];
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($fax . $mms . $body, $input);

    assert_eq('', $result->markup);
    assert_eq(3, count($result->warnings));
    $warnings = implode("\n", $result->warnings);
    assert_contains('+12075550199', $warnings);
    assert_contains('fake@example.com', $warnings);
});

test('SectionUnit grounds contact facts inside every URI scheme', function () {
    $parts = [
        '<!-- wp:paragraph --><p><a href="xmpp:fake@example.com">Chat</a></p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><a href="webcal:https://invented.example/event">Calendar</a></p>'
            . '<!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><a href="irc://invented.example/channel">IRC</a></p><!-- /wp:paragraph -->',
    ];
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(implode('', $parts), section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(3, count($result->warnings));
    $warnings = implode("\n", $result->warnings);
    assert_contains('fake@example.com', $warnings);
    assert_contains('invented.example', $warnings);
});

test('SectionUnit grounds phone semantics in opaque communication URIs', function () {
    $parts = [
        '<!-- wp:paragraph --><p><a href="skype:2075550199?call">Call</a></p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><a href="skype:?call&amp;number=2075550199">Call</a></p>'
            . '<!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><a href="signal:?phone=2075550199">Signal</a></p><!-- /wp:paragraph -->',
    ];
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(implode('', $parts), section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(3, count($result->warnings));
    assert_contains('2075550199', implode("\n", $result->warnings));
});

test('SectionUnit compares non-phone communication recipients as exact URI destinations', function () {
    $markup = '<!-- wp:paragraph --><p><a href="skype:echo123?call">Skype</a></p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $invented = $unit->finish($markup, section_unit_input());
    assert_eq('', $invented->markup);
    assert_eq(1, count($invented->warnings));

    $input = section_unit_input();
    $input['site_spec'] = ['skype' => 'skype:echo123?call'];
    $grounded = $unit->finish($markup, $input);
    assert_eq($markup, $grounded->markup);
    assert_eq([], $grounded->warnings);
});

test('SectionUnit preserves numbered prose that is not a street address', function () {
    $safe = '<!-- wp:paragraph --><p>3 Simple Steps</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>10 years of trusted service</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>24 Hours on the Road</p><!-- /wp:paragraph -->'
        . '<!-- wp:heading --><h2>5 Ways to Drive Growth</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Tour de France 2026</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>ISBN 9781234567890</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Order 123-456-7890</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Version 192.168.10.20</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Resolution 1920/1080/2024</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Version:207.555.0199</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>SSN:123-45-6789</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Order:207-555-0199</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Resolution:1920/1080/2024</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"value":"1234567890"} --><p>Inventory reference</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($safe, section_unit_input());

    assert_eq($safe, $result->markup, 'ordinary numbered copy stays byte-for-byte intact');
    assert_eq([], $result->warnings);
});

test('SectionUnit catches sentence-final contact and contact-language locations', function () {
    $email = '<!-- wp:paragraph --><p>Email fake@example.com.</p><!-- /wp:paragraph -->';
    $location = '<!-- wp:paragraph --><p>Visit us in Boston.</p><!-- /wp:paragraph -->';
    $safe = '<!-- wp:paragraph --><p>Fresh bread daily.</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($safe . $email . $location, section_unit_input());

    assert_eq($safe, $result->markup, 'safe sibling stays byte-for-byte intact');
    assert_eq(2, count($result->warnings));
    assert_contains('fake@example.com', implode("\n", $result->warnings));
    assert_contains('Boston', implode("\n", $result->warnings));
});

test('SectionUnit catches common visible phone and address forms without touching block IDs', function () {
    $parts = [
        '<!-- wp:paragraph --><p>2075550199</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Hotline: 2075550199</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Reservations phone — ٢٠٧٥٥٥٠١٩٩</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Call +1&nbsp;207&nbsp;555&nbsp;0199</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>+1 207 555 0199</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>PO Box 123, Boston, MA 02108</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>221B Baker Street</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>1 Infinite Loop</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>123 Road to Nowhere</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Via Roma 10</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>10 Rue de Rivoli</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Unter den Linden 77</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Call +1‑207‑555‑0199</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Contact: 2075550199</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Call us at 2075550188</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Tel: 2075550177</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Call us on 2075550166</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Call +49 30/123456</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Dial 2075550155</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Ring us on 2075550144</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Reservations: 2075550133</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>For appointments: 2075550122</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Phone enquiries: 2075550111</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Phone enquiries, 2075550100</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Phone enquiries only: 2075550198</p><!-- /wp:paragraph -->',
    ];
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(implode('', $parts), section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(25, count($result->warnings));
});

test('SectionUnit bounds a grounded location before ordinary sentence copy', function () {
    $input = section_unit_input();
    $input['site_spec'] = ['location' => [
        'city' => 'Boston',
        'line1' => '123 Road to Nowhere',
        'venues' => ['Center for Contemporary Art', 'Museum with No Frontiers'],
    ]];
    $markup = '<!-- wp:paragraph --><p>Visit us in Boston for fresh pastries daily.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit us in Boston for Mother\'s Day events.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit us in Boston for brunch in May.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit us at 123 Road to Nowhere.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit us at Center for Contemporary Art.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit us at Museum with No Frontiers.</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, $input);

    assert_eq($markup, $result->markup);
    assert_eq([], $result->warnings);
});

test('SectionUnit does not let grounded location prose shelter a second location claim', function () {
    $input = section_unit_input();
    $input['site_spec'] = ['location' => ['city' => 'Boston']];
    $markup = '<!-- wp:paragraph --><p>Visit us in Boston with another office in Cambridge.</p>'
        . '<!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit us in Boston with offices in Cambridge.</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, $input);

    assert_eq('', $result->markup);
    assert_eq(2, count($result->warnings));
    assert_contains('Cambridge', implode("\n", $result->warnings));
});

test('SectionUnit catches office and branch location claims', function () {
    $markup = '<!-- wp:paragraph --><p>Our office is in Cambridge.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Our branches are in Somerville.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Headquarters: Portland.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit our Cambridge office.</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(4, count($result->warnings));
    assert_contains('Cambridge', implode("\n", $result->warnings));
    assert_contains('Somerville', implode("\n", $result->warnings));
    assert_contains('Portland', implode("\n", $result->warnings));
});

test('SectionUnit treats explicit address labels as semantic in any case or script', function () {
    $markup = '<!-- wp:paragraph --><p>Address: cambridge.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Address: 東京都中央区築地5-2-1.</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(2, count($result->warnings));
    assert_contains('cambridge', implode("\n", $result->warnings));
    assert_contains('東京都中央区築地5-2-1', implode("\n", $result->warnings));
});

test('SectionUnit preserves non-location prose about business locations', function () {
    $safe = '<!-- wp:paragraph --><p>Our shop is family-owned.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Our studio is independent.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Our facility is accessible.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Our branches are growing.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Our store is online only.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Our office is closed today.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Office: Closed today.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit our Online Store.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit our Gift Shop.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit our New Online Store.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Office: Holiday Hours.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit our Brand New Online Store.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit our Pop-Up Gift Shop.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Office: Staff Meeting.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Office: Team Lunch.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Office: Private Event.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Office: Maintenance.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Office: Summer Break.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit our Award Winning Online Store.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Office: Renovations.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Office: Staff Retreat.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Office: Team Offsite.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Office: Public Holiday.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Office: Appointment Required.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit our Family Owned Online Store.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit our Newly Renovated Gift Shop.</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($safe, section_unit_input());

    assert_eq($safe, $result->markup);
    assert_eq([], $result->warnings);
});

test('contact grounding reports an empty result when only structural wrappers survive', function () {
    $markup = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>fake@example.com</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, section_unit_input());

    assert_eq('', $result->markup, 'an empty group is not material delivery');
    assert_eq(1, count($result->warnings));
});

test('contact grounding prunes only newly empty structural ancestors', function () {
    $badColumn = '<!-- wp:column --><div class="wp-block-column">'
        . '<!-- wp:paragraph --><p>fake@example.com</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:column -->';
    $safeColumn = '<!-- wp:column --><div class="wp-block-column">'
        . '<!-- wp:paragraph --><p>Safe sibling.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:column -->';
    $columnsOpen = '<!-- wp:columns --><div class="wp-block-columns">';
    $columnsClose = '</div><!-- /wp:columns -->';
    $emptyBand = '<!-- wp:group {"style":{"dimensions":{"minHeight":"400px"}}} -->'
        . '<div class="wp-block-group" style="min-height:400px">'
        . '<!-- wp:paragraph --><p>another@example.com</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $safe = '<!-- wp:paragraph --><p>Independent safe bytes.</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($columnsOpen . $badColumn . $safeColumn . $columnsClose . $emptyBand . $safe, section_unit_input());

    assert_eq($columnsOpen . $safeColumn . $columnsClose . $safe, $result->markup);
    assert_eq(2, count($result->warnings));
});

test('contact grounding removes contact-only navigation and social shells', function () {
    $social = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:social-links --><ul class="wp-block-social-links">'
        . '<!-- wp:social-link {"url":"https://invented.example","service":"wordpress"} /-->'
        . '</ul><!-- /wp:social-links -->'
        . '</div><!-- /wp:group -->';
    $navigation = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:navigation --><nav class="wp-block-navigation">'
        . '<!-- wp:navigation-link {"label":"Contact","url":"https://invented.example"} /-->'
        . '</nav><!-- /wp:navigation -->'
        . '</div><!-- /wp:group -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    foreach ([$social, $navigation] as $markup) {
        $result = $unit->finish($markup, section_unit_input());
        assert_eq('', $result->markup, 'a contact-only structural shell triggers the part fallback');
        assert_eq(1, count($result->warnings));
    }
});

test('contact grounding removes a list emptied by contact removal', function () {
    $markup = '<!-- wp:list --><ul class="wp-block-list">'
        . '<!-- wp:list-item --><li>fake@example.com</li><!-- /wp:list-item -->'
        . '</ul><!-- /wp:list -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $first = $unit->finish($markup, section_unit_input());
    assert_eq('', $first->markup);
    assert_eq(1, count($first->warnings));
});

test('contact grounding removes quote shells emptied by contact removal', function () {
    $quote = '<!-- wp:quote --><blockquote class="wp-block-quote">'
        . '<!-- wp:paragraph --><p>fake@example.com</p><!-- /wp:paragraph -->'
        . '</blockquote><!-- /wp:quote -->';
    $pullquote = '<!-- wp:pullquote --><figure class="wp-block-pullquote"><blockquote>'
        . '<!-- wp:paragraph --><p>another@example.com</p><!-- /wp:paragraph -->'
        . '</blockquote></figure><!-- /wp:pullquote -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    foreach ([$quote, $pullquote] as $markup) {
        $result = $unit->finish($markup, section_unit_input());
        assert_eq('', $result->markup);
        assert_eq(1, count($result->warnings));
    }
});

test('SectionUnit keeps grounded locations containing abbreviations', function () {
    $input = section_unit_input();
    $input['site_spec'] = ['location' => [
        'city' => 'St. Louis',
        'district' => 'Washington, D.C.',
        'line1' => '123 Main St.',
        'mailing' => 'P.O. Box 123',
    ]];
    $markup = '<!-- wp:paragraph --><p>Visit us in St. Louis.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Our address is 123 Main St.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit us in Washington, D.C.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Our address is P.O. Box 123.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Address: 123 Main St. Open daily.</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, $input);

    assert_eq($markup, $result->markup);
    assert_eq([], $result->warnings);
});

test('SectionUnit compares comma continuations after abbreviations as part of the location', function () {
    $suite = '<!-- wp:paragraph --><p>Address: 123 Main St., Suite 999.</p><!-- /wp:paragraph -->';
    $cities = '<!-- wp:paragraph --><p>Visit us in Washington, D.C., Cambridge.</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $baseAddress = section_unit_input();
    $baseAddress['site_spec'] = ['address' => '123 Main St.'];
    assert_eq('', $unit->finish($suite, $baseAddress)->markup, 'a base street cannot shelter an invented suite');

    $fullAddress = section_unit_input();
    $fullAddress['site_spec'] = ['address' => '123 Main St., Suite 999'];
    assert_eq($suite, $unit->finish($suite, $fullAddress)->markup, 'the complete stated suite stays grounded');

    $baseCity = section_unit_input();
    $baseCity['site_spec'] = ['location' => 'Washington, D.C.'];
    assert_eq('', $unit->finish($cities, $baseCity)->markup, 'a base district cannot shelter another city');

    $fullCity = section_unit_input();
    $fullCity['site_spec'] = ['location' => 'Washington, D.C., Cambridge'];
    assert_eq($cities, $unit->finish($cities, $fullCity)->markup, 'the complete stated location stays grounded');
});

test('SectionUnit separates grounded locations from ordinary prose continuations', function () {
    $input = section_unit_input();
    $input['site_spec'] = ['location' => ['Boston', 'Washington, D.C.'], 'address' => '123 Main St.'];
    $markup = '<!-- wp:paragraph --><p>Visit us in Boston with free parking.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit us in Boston and enjoy fresh pastries.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit us in Boston, open daily.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit us in Boston during the summer.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit us in Boston, serving pastries daily.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit us in Washington, D.C., where we bake daily.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Address: 123 Main St., open daily.</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, $input);

    assert_eq($markup, $result->markup);
    assert_eq([], $result->warnings);

    $invented = '<!-- wp:paragraph --><p>Visit us in Boston and Cambridge.</p><!-- /wp:paragraph -->';
    $blocked = $unit->finish($invented, $input);
    assert_eq('', $blocked->markup, 'a second proper place does not inherit the first location authorization');
    assert_eq(1, count($blocked->warnings));
});

test('SectionUnit distinguishes uncased locations from ordinary named continuations', function () {
    $input = section_unit_input();
    $input['site_spec'] = ['location' => 'Boston'];
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $parts = [
        '<!-- wp:paragraph --><p>Visit us in Boston and 東京.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Visit us in Boston and 上海.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and القاهرة.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston with กรุงเทพ.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston for دبي.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and أبو ظبي.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and buenos aires.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Visit us in Boston with Google Pay available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Visit us in Boston with Free Parking.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Visit us in Boston during Holiday Hours.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and مرحباً بكم.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston with يقدم المعجنات الطازجة.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston, serving 朝食 daily.</p><!-- /wp:paragraph -->',
    ];

    $result = $unit->finish(implode('', $parts), $input);

    assert_true(!str_contains($result->markup, '東京'));
    assert_true(!str_contains($result->markup, '上海'));
    assert_true(!str_contains($result->markup, 'القاهرة'));
    assert_true(!str_contains($result->markup, 'กรุงเทพ'));
    assert_true(!str_contains($result->markup, 'دبي'));
    assert_true(!str_contains($result->markup, 'أبو ظبي'));
    assert_true(!str_contains($result->markup, 'buenos aires'));
    assert_contains('Google Pay available', $result->markup);
    assert_contains('Free Parking', $result->markup);
    assert_contains('Holiday Hours', $result->markup);
    assert_contains('مرحباً بكم', $result->markup);
    assert_contains('يقدم المعجنات الطازجة', $result->markup);
    assert_contains('朝食 daily', $result->markup);
    assert_eq(7, count($result->warnings));
});

test('SectionUnit normalizes Unicode digits for tel grounding', function () {
    $button = '<!-- wp:button {"url":"tel:+١٢٠٧٥٥٥٠١٩٩"} -->'
        . '<div class="wp-block-button"><a href="tel:+١٢٠٧٥٥٥٠١٩٩">Call</a></div><!-- /wp:button -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $ungrounded = $unit->finish($button, section_unit_input());
    assert_eq('', $ungrounded->markup, 'an invented Unicode-digit telephone destination is removed');
    assert_eq(1, count($ungrounded->warnings));

    $input = section_unit_input();
    $input['site_spec'] = ['phone' => '+١٢٠٧٥٥٥٠١٩٩'];
    $grounded = $unit->finish($button, $input);
    assert_eq($button, $grounded->markup, 'the exact spec phone authorizes presentation-equivalent digits');
    assert_eq([], $grounded->warnings);
});

test('SectionUnit treats browser-collapsed ASCII whitespace as phone separators', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $parts = [
        '<!-- wp:paragraph --><p>Call 207&Tab;555&Tab;0199</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Call 207&#9;555&#x09;0199</p><!-- /wp:paragraph -->',
        "<!-- wp:paragraph --><p>Call 207\n555\n0199</p><!-- /wp:paragraph -->",
    ];

    $result = $unit->finish(implode('', $parts), section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(3, count($result->warnings));
});

test('SectionUnit keeps phone extensions separate from the main dial target', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $button = '<!-- wp:button {"url":"tel:+1207555019;ext=9"} -->'
        . '<div class="wp-block-button"><a href="tel:+1207555019;ext=9">Call</a></div><!-- /wp:button -->';
    $input = section_unit_input();
    $input['site_spec'] = ['phone' => '+12075550199'];

    $mismatch = $unit->finish($button, $input);
    assert_eq('', $mismatch->markup, 'extension digits cannot complete a shorter main number');
    assert_eq(1, count($mismatch->warnings));

    $unknownParameter = str_replace('+1207555019;ext=9', '+12075550199;phone-context=work', $button);
    $unknown = $unit->finish($unknownParameter, $input);
    assert_eq('', $unknown->markup, 'unknown telephone parameters are never treated as the stated phone');
    assert_eq(1, count($unknown->warnings));

    $input['site_spec'] = ['phone' => '+1 207 555 0199 ext 42'];
    $withExtension = str_replace('+1207555019;ext=9', '+12075550199;ext=42', $button);
    $grounded = $unit->finish($withExtension, $input);
    assert_eq($withExtension, $grounded->markup, 'reviewed extension spellings share one exact identity');
    assert_eq([], $grounded->warnings);
});

test('SectionUnit does not treat an arbitrary numeric spec field as a phone', function () {
    $input = section_unit_input();
    $input['site_spec'] = ['order_id' => '207-555-0199'];
    $button = '<!-- wp:button {"url":"tel:2075550199"} -->'
        . '<div class="wp-block-button"><a href="tel:2075550199">Call</a></div><!-- /wp:button -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($button, $input);

    assert_eq('', $result->markup);
    assert_eq(1, count($result->warnings));
});

test('SectionUnit catches browser-normalized backslash URLs and accessible contact copy', function () {
    $input = section_unit_input();
    $input['site_spec'] = ['name' => 'example.com', 'email_domain' => 'example.com'];
    $backslashUrl = 'https:\\\\example.com\\evil';
    $link = '<!-- wp:paragraph --><p><a href="' . $backslashUrl . '">Visit</a></p><!-- /wp:paragraph -->';
    $tripleSlashUrl = '///example.com/evil';
    $tripleSlashLink = '<!-- wp:paragraph --><p><a href="' . $tripleSlashUrl . '">Visit</a></p>'
        . '<!-- /wp:paragraph -->';
    $slashlessUrl = 'https:example.com/evil';
    $slashlessLink = '<!-- wp:paragraph --><p><a href="' . $slashlessUrl . '">Visit</a></p>'
        . '<!-- /wp:paragraph -->';
    $image = '<!-- wp:image {"alt":"Call +1 207 555 0199 at 24 Market Street"} -->'
        . '<figure class="wp-block-image"><img src="photo.jpg" '
        . 'alt="Call +1 207 555 0199 at 24 Market Street"/></figure><!-- /wp:image -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($link . $tripleSlashLink . $slashlessLink . $image, $input);

    assert_eq('', $result->markup);
    assert_eq(4, count($result->warnings));
    $warnings = implode("\n", $result->warnings);
    assert_contains('example.com', $warnings);
    assert_contains('evil', $warnings);
    assert_contains($tripleSlashUrl, $warnings);
    assert_contains($slashlessUrl, $warnings);
    assert_contains('+1 207 555 0199', $warnings);
    assert_contains('24 Market Street', $warnings);
});

test('SectionUnit follows browser normalization for obfuscated contact destinations', function () {
    $parts = [
        '<!-- wp:paragraph --><p><a href="h&#x09;ttps://invented.example/contact">Visit</a></p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><a href="mailto&#58;fake@example.com">Email</a></p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><a href="tel&#58;2075550199">Call</a></p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><a href="sms:2075550199">Text</a></p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><a href="https&#58&#47&#47invented.example/contact">Visit</a></p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>fake&#64example&#46com</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Call &#50&#48&#55&#53&#53&#53&#48&#49&#57&#57</p><!-- /wp:paragraph -->',
    ];
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(implode('', $parts), section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(7, count($result->warnings));
});

test('SectionUnit follows complete browser numeric-reference decoding', function () {
    $encodedPhone = '<!-- wp:paragraph --><p>Call '
        . '&#1633&#1634&#1635&#1636&#1637&#1638&#1639&#1640&#1641&#1632</p><!-- /wp:paragraph -->';
    $outOfRange = '<!-- wp:paragraph --><p>Call &#491234567890</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $invented = $unit->finish($encodedPhone, section_unit_input());
    assert_eq('', $invented->markup, 'browser-rendered Unicode digits expose the invented phone');
    assert_eq(1, count($invented->warnings));

    $safe = $unit->finish($outOfRange, section_unit_input());
    assert_eq($outOfRange, $safe->markup, 'an out-of-range reference is replacement text, not an ASCII phone');
    assert_eq([], $safe->warnings);
});

test('SectionUnit decodes visible HTML entities exactly once', function () {
    $markup = '<!-- wp:paragraph --><p>Write fake&amp;#64;example&amp;#46;com in the documentation.</p>'
        . '<!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Document Call &amp;#49;&amp;#50;&amp;#51;&amp;#52;&amp;#53;'
        . '&amp;#54;&amp;#55;&amp;#56;&amp;#57;&amp;#48; syntax.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Escape https&amp;#58;&amp;#47;&amp;#47;example.com in source.</p>'
        . '<!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, section_unit_input());

    assert_eq($markup, $result->markup);
    assert_eq([], $result->warnings);

    $numericAmpersands = '<!-- wp:paragraph --><p>Write fake&#38;#64;example&#38;#46;com for documentation.</p>'
        . '<!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Write fake&#38;commat;example.com for documentation.</p>'
        . '<!-- /wp:paragraph -->';
    $numericResult = $unit->finish($numericAmpersands, section_unit_input());
    assert_eq($numericAmpersands, $numericResult->markup);
    assert_eq([], $numericResult->warnings);
});

test('SectionUnit recognizes localized contact labels', function () {
    $location = '<!-- wp:paragraph --><p>所在地: 東京都中央区築地5-2-1.</p><!-- /wp:paragraph -->';
    $phone = '<!-- wp:paragraph --><p>Teléfono: 2075550199.</p><!-- /wp:paragraph -->';
    $compoundPhone = '<!-- wp:paragraph --><p>電話番号：2075550199.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Telefonnummer: 0301234567.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Telefone para contato: 2112345678.</p><!-- /wp:paragraph -->';
    $compoundLocation = '<!-- wp:paragraph --><p>会社所在地：東京都中央区築地5-2-1.</p><!-- /wp:paragraph -->';
    $morePhones = '<!-- wp:paragraph --><p>Telefon-Nr.: 0301234567.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Telefone de contato: 2112345678.</p><!-- /wp:paragraph -->';
    $moreLocations = '<!-- wp:paragraph --><p>Dirección de la oficina: Barcelona.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Adresse des Büros: Berlin.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>支店所在地：東京.</p><!-- /wp:paragraph -->';
    $standalonePhones = '<!-- wp:paragraph --><p>☎ 2075550199</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>📞 2075550199</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>WhatsApp de ventas: 2112345678.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Número de contacto: 2112345678.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Telefonische Auskunft: 0301234567.</p><!-- /wp:paragraph -->';
    $standaloneAddresses = '<!-- wp:paragraph --><p>東京都中央区築地5-2-1</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Hauptstraße 12</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Adresse du bureau: Paris.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Endereço do escritório: Lisboa.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>会社の所在地：東京.</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(
        $location . $phone . $compoundPhone . $compoundLocation . $morePhones . $moreLocations
            . $standalonePhones . $standaloneAddresses,
        section_unit_input(),
    );

    assert_eq('', $result->markup);
    assert_eq(21, count($result->warnings));
});

test('SectionUnit treats an explicit website cue as a domain despite a file-like TLD', function () {
    $website = '<!-- wp:paragraph --><p>Our website is support.zip.</p><!-- /wp:paragraph -->';
    $numeric = '<!-- wp:paragraph --><p>Domain: 123.com.</p><!-- /wp:paragraph -->';
    $imperative = '<!-- wp:paragraph --><p>Go to support.zip for help.</p><!-- /wp:paragraph -->';
    $longCue = '<!-- wp:paragraph --><p>Website for all customer questions and technical support: support.zip.</p>'
        . '<!-- /wp:paragraph -->';
    $browser = '<!-- wp:paragraph --><p>Open support.zip in your browser.</p><!-- /wp:paragraph -->';
    $visit = '<!-- wp:paragraph --><p>Visit support.zip for help.</p><!-- /wp:paragraph -->';
    $veryLongCue = '<!-- wp:paragraph --><p>Website for product questions, customer support, reservations, returns, '
        . 'warranty claims, accessibility help, and account recovery: support.zip.</p><!-- /wp:paragraph -->';
    $click = '<!-- wp:paragraph --><p>Click support.zip to open the website.</p><!-- /wp:paragraph -->';
    $openWebsite = '<!-- wp:paragraph --><p>Open support.zip as a website.</p><!-- /wp:paragraph -->';
    $abbreviation = '<!-- wp:paragraph --><p>Our website is, e.g. support.zip for help.</p><!-- /wp:paragraph -->';
    $markup = $website . $numeric . $imperative . $longCue . $browser . $visit . $veryLongCue
        . $click . $openWebsite . $abbreviation;
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(10, count($result->warnings));
    assert_contains('support.zip', implode("\n", $result->warnings));
    assert_contains('123.com', implode("\n", $result->warnings));

    $input = section_unit_input();
    $input['site_spec'] = ['website' => ['support.zip', '123.com']];
    $grounded = $unit->finish($markup, $input);
    assert_eq($markup, $grounded->markup);
    assert_eq([], $grounded->warnings);
});

test('SectionUnit preserves file-like domains explicitly described as files', function () {
    $markup = '<!-- wp:paragraph --><p>Our website offers downloads, including the file support.zip.</p>'
        . '<!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Browse to Downloads and choose the file support.zip.</p>'
        . '<!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit our website to download support.zip.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Click to download support.zip.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Our website offers support.zip for download.</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, section_unit_input());

    assert_eq($markup, $result->markup);
    assert_eq([], $result->warnings);
    assert_true(!ContactFacts::sourceStatesDomain(
        'Our website offers downloads, including the file support.zip.',
        'support.zip',
    ));
    assert_true(!ContactFacts::sourceStatesDomain(
        'Visit our website to download support.zip.',
        'support.zip',
    ));
});

test('SectionUnit scans real HTML destination and CSS attributes only', function () {
    $srcset = '<!-- wp:image --><figure class="wp-block-image"><img src="/safe.jpg" '
        . 'srcset="https://invented.example/a.jpg 2x"></figure><!-- /wp:image -->';
    $style = '<!-- wp:group --><div class="wp-block-group" '
        . 'style="background-image:url(https://invented.example/a.jpg)">Safe</div><!-- /wp:group -->';
    $inert = '<!-- wp:group --><div class="wp-block-group" '
        . 'data-example="href=\'https://invented.example/path\' aria-label=\'fake@example.com\'">Safe</div>'
        . '<!-- /wp:group -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($srcset . $style . $inert, section_unit_input());

    assert_eq($inert, $result->markup);
    assert_eq(2, count($result->warnings));
});

test('SectionUnit scans tag-specific request sinks and decoded CSS URLs', function () {
    $active = [
        '<!-- wp:html --><svg><rect filter="url(https://invented.example/filter.svg#x)"/></svg>'
            . '<!-- /wp:html -->',
        '<!-- wp:html --><svg><rect fill="url(https://invented.example/fill.svg#x)"/></svg>'
            . '<!-- /wp:html -->',
        '<!-- wp:html --><svg><rect clip-path="url(https://invented.example/clip.svg#x)"/></svg>'
            . '<!-- /wp:html -->',
        '<!-- wp:group --><div class="wp-block-group" '
            . 'style="background-image:url(https\\3a \\2f \\2f invented.example/a.jpg)">Safe</div>'
            . '<!-- /wp:group -->',
        '<!-- wp:html --><div style="background:url(https://invented.example/a\\20 b.png)">Safe</div>'
            . '<!-- /wp:html -->',
        '<!-- wp:html --><math href="https://invented.example/root"><mi>x</mi></math><!-- /wp:html -->',
        '<!-- wp:html --><math><mrow href="https://invented.example/row"><mi>x</mi></mrow></math>'
            . '<!-- /wp:html -->',
        '<!-- wp:html --><svg><defs><linearGradient id="g" '
            . 'href="https://invented.example/x.svg#g"/></defs></svg><!-- /wp:html -->',
        '<!-- wp:html --><svg><defs><radialGradient id="g" '
            . 'xlink:href="https://invented.example/x.svg#g"/></defs></svg><!-- /wp:html -->',
        '<!-- wp:html --><a href="&#1;https://invented.example/path">Visit</a><!-- /wp:html -->',
        '<!-- wp:html --><div style="background:url(\\1 https://invented.example/a.png)">Safe</div>'
            . '<!-- /wp:html -->',
        "<!-- wp:html --><div style=\"content:&quot;broken\n;"
            . 'background:url(/*x*/https://invented.example/a.png)">Safe</div><!-- /wp:html -->',
        '<!-- wp:html --><math><mtable><mlabeledtr href="https://invented.example/labeled">'
            . '<mtd><mtext>Label</mtext></mtd></mlabeledtr></mtable></math><!-- /wp:html -->',
        '<!-- wp:html --><math><mstack href="https://invented.example/stack"><mn>1</mn></mstack></math>'
            . '<!-- /wp:html -->',
        '<!-- wp:html --><math><msgroup href="https://invented.example/group"><msrow><mn>1</mn></msrow>'
            . '</msgroup></math><!-- /wp:html -->',
        '<!-- wp:html --><math><msrow href="https://invented.example/row"><mn>1</mn></msrow></math>'
            . '<!-- /wp:html -->',
        '<!-- wp:html --><math><maligngroup href="https://invented.example/align"/></math><!-- /wp:html -->',
        '<!-- wp:html --><math><mlongdiv href="https://invented.example/div"><mn>1</mn></mlongdiv></math>'
            . '<!-- /wp:html -->',
        '<!-- wp:html --><input type=" hidden " value="Call +1 207 555 0199"><!-- /wp:html -->',
        '<!-- wp:html --><button type="bogus" formaction="https://invented.example/post">Go</button>'
            . '<!-- /wp:html -->',
    ];
    $inert = '<!-- wp:html --><div data="https://invented.example/data" '
        . 'cite="https://invented.example/cite" poster="https://invented.example/poster" '
        . 'style="--label:\'https://invented.example/custom\';content:\'https://invented.example/text\'">Safe</div>'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><a href="&nbsp;https://invented.example/path">Keep NBSP</a><!-- /wp:html -->'
        . '<!-- wp:html --><div style=\'background:url("/*note*/https://not-remote.example/a.png")\'>'
        . 'Keep CSS string</div><!-- /wp:html -->'
        . '<!-- wp:html --><input type="text" src="https://invented.example/x.png" value="Keep src">'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><input type="text" formaction="https://invented.example/post" '
        . 'value="Keep action"><!-- /wp:html -->'
        . '<!-- wp:html --><button type="button" formaction="https://invented.example/post">Keep button</button>'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><input type=" image " src="https://invented.example/x.png" value="Keep spaced">'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><a ping="&nbsp;https://invented.example/ping" href="/safe">Keep ping</a>'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><div style="background:url(&nbsp;https://invented.example/a.png)">Keep CSS NBSP</div>'
        . '<!-- /wp:html -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(implode('', $active) . $inert, section_unit_input());

    assert_eq($inert, $result->markup, 'inert attributes on the wrong element do not make a request');
    assert_eq(20, count($result->warnings));
});

test('SectionUnit follows browser parsing for CSS resources and URL-list attributes', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $imageSet = '<!-- wp:html --><div style="background-image:image-set(&quot;data:image/png;base64,AAAA&quot; 1x, '
        . '&quot;https://invented.example/a.png&quot; 2x)">Safe</div><!-- /wp:html -->';
    $attribution = '<!-- wp:html --><a href="/safe" '
        . 'attributionsrc="https://invented.example/register">Safe</a><!-- /wp:html -->'
        . '<!-- wp:html --><img src="/safe.png" '
        . 'attributionsrc="https://invented.example/register"><!-- /wp:html -->';
    $blocked = $unit->finish($imageSet . $attribution, section_unit_input());
    assert_eq('', $blocked->markup);
    assert_eq(3, count($blocked->warnings));

    $tabSrcsetInput = section_unit_input();
    $tabSrcsetInput['site_spec'] = ['website' => 'https://invented.example/a2x'];
    $tabSrcset = "<!-- wp:html --><img src=\"/safe.png\" srcset=\"https://invented.example/a\t2x\">"
        . '<!-- /wp:html -->';
    assert_eq('', $unit->finish($tabSrcset, $tabSrcsetInput)->markup);

    $commaSrcsetInput = section_unit_input();
    $commaSrcsetInput['site_spec'] = ['website' => 'https://invented.example/a'];
    $commaSrcset = '<!-- wp:html --><img src="/safe.png" '
        . 'srcset="https://invented.example/a,b 2x"><!-- /wp:html -->';
    assert_eq('', $unit->finish($commaSrcset, $commaSrcsetInput)->markup);

    $pingInput = section_unit_input();
    $pingInput['site_spec'] = [
        'website' => 'https://allowed.example/onehttps://invented.example/two',
    ];
    $ping = "<!-- wp:html --><a href=\"/safe\" ping=\"https://allowed.example/one\t"
        . 'https://invented.example/two">Safe</a><!-- /wp:html -->';
    assert_eq('', $unit->finish($ping, $pingInput)->markup);

    $invalid = '<!-- wp:html --><div style="background:url (https://invented.example/a.png)">Safe</div>'
        . '<!-- /wp:html -->'
        . "<!-- wp:html --><div style=\"background:url('https://invented.example/a\n.png')\">Safe</div>"
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><img src="/safe.png" '
        . 'srcset="https://invented.example/a.png 1x 2x"><!-- /wp:html -->';
    $invalidResult = $unit->finish($invalid, section_unit_input());
    assert_eq($invalid, $invalidResult->markup);
    assert_eq([], $invalidResult->warnings);

    $unicodeInput = section_unit_input();
    $unicodeInput['site_spec'] = ['website' => 'https://例子.测试/a.png'];
    $unicodeCss = '<!-- wp:html --><div style="background:url(https://\\4F8B\\5B50.\\6D4B\\8BD5/a.png)">'
        . 'Safe</div><!-- /wp:html -->';
    assert_eq($unicodeCss, $unit->finish($unicodeCss, $unicodeInput)->markup);
});

test('SectionUnit reads RCDATA as visible text but ignores inert template and hidden accessibility copy', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $textarea = '<!-- wp:html --><textarea><a href="https://invented.example/path">Visit</a></textarea>'
        . '<!-- /wp:html -->';
    $blocked = $unit->finish($textarea, section_unit_input());
    assert_eq('', $blocked->markup);
    assert_eq(1, count($blocked->warnings));

    $safeBefore = '<!-- wp:html --><template>fake@example.com'
        . '<a href="https://invented.example/path">Inert</a></template><p>Safe</p><!-- /wp:html -->'
        . '<!-- wp:html --><span aria-hidden="true" aria-label="fake@example.com">Safe visible text</span>'
        . '<!-- /wp:html -->';
    $hidden = '<!-- wp:html --><span hidden>fake@example.com</span><p>Safe hidden sibling</p><!-- /wp:html -->';
    $safeAfter = '<!-- wp:html --><div aria-hidden="true"><span aria-label="fake@example.com">Safe</span></div>'
        . '<p>Safe aria sibling</p><!-- /wp:html -->'
        . '<!-- wp:html --><xmp>fake&#64;example.com</xmp><p>Safe raw text</p><!-- /wp:html -->';
    $safe = $unit->finish($safeBefore . $hidden . $safeAfter, section_unit_input());
    assert_eq($safeBefore . $safeAfter, $safe->markup);
    assert_eq(1, count($safe->warnings));
});

test('SectionUnit requires exact schemeless URL suffixes instead of inheriting a grounded host', function () {
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $input = section_unit_input();
    $input['site_spec'] = ['domain' => 'example.com'];
    $invented = '<!-- wp:paragraph --><p>Visit example.com/invented-offer</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Visit example.com?offer=invented</p><!-- /wp:paragraph -->';
    $blocked = $unit->finish($invented, $input);
    assert_eq('', $blocked->markup);
    assert_eq(2, count($blocked->warnings));

    $input['site_spec'] = [
        'first' => 'example.com/invented-offer',
        'second' => 'example.com?offer=invented',
    ];
    $grounded = $unit->finish($invented, $input);
    assert_eq($invented, $grounded->markup);
    assert_eq([], $grounded->warnings);
});

test('SectionUnit recognizes common number-first and number-last international addresses', function () {
    $parts = [
        '<!-- wp:paragraph --><p>Calle de Alcalá, 48</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Piazza del Colosseo, 1</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>48, Calle de Alcalá</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>1-2-3 Shibuya, Tokyo</p><!-- /wp:paragraph -->',
    ];
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(implode('', $parts), section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(4, count($result->warnings));

    $safe = '<!-- wp:paragraph --><p>2026-08-24 Product Launch, New York</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>1-2-3 Simple Steps, Start Today</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>2024-01-15 Winter Sale, Shop Online</p><!-- /wp:paragraph -->';
    $safeResult = $unit->finish($safe, section_unit_input());
    assert_eq($safe, $safeResult->markup);
    assert_eq([], $safeResult->warnings);
});

test('SectionUnit rejects long location continuations while preserving narrative continuations', function () {
    $input = section_unit_input();
    $input['site_spec'] = ['location' => 'Boston'];
    $invented = [
        '<!-- wp:paragraph --><p>Location: Boston and San Pedro de Atacama.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and Washington District of Columbia.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and مدينة نيويورك في أمريكا.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston, serving Cambridge customers daily.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and daily service across Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free delivery throughout Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston, serving cambridge customers daily.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free delivery around Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free delivery near cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free delivery beyond Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free delivery within Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free delivery toward Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free service for Cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free delivery beside Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free delivery through Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free delivery into Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free delivery via Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free delivery through cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free delivery through كامبريدج.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free delivery for every customer and every family '
            . 'in our network through Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free service through Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free service into Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and Cambridge parking available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and Central Cambridge parking available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and Cambridge public parking available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and Cambridge pickup available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and Cambridge delivery available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and parking available in cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free service to cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and service for cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and Cambridge service available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and Cambridge consultations available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and Cambridge repairs available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free delivery for cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and consultations in cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free service via Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free service covers Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free customer support serving Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and Cambridge training available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free service reaches Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and Cambridge classes available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and Cambridge events available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free training via Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free service serves Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and service coverage includes Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free technical help in cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and Cambridge workshops available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and Cambridge seminars available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free workshops via Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free service supports Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free support assists Cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free service benefits Cambridge families.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free service welcomes Cambridge customers.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free support advises Cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free support advised Cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support assisted Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helped cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helped the Cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support welcomes our Cambridge customers.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support connects Cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support empowers Cambridge businesses.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support can guide cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support can mentor Cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support may coach Cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support must coach Cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support should mentor Cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support can train Cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support should teach Cambridge customers.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support can educate Cambridge residents.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support will instruct Cambridge customers.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support should counsel Cambridge families.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helps residents of Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support can serve cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helps residents outside cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and parking within walking distance of Cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston, featuring San Pedro de Atacama tours daily.</p><!-- /wp:paragraph -->',
    ];
    $safe = [
        '<!-- wp:paragraph --><p>Location: Boston, offering fine dining.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston, serving local families.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston, featuring live music.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston, where creativity thrives.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free parking within walking distance.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free service for corporate clients.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and hospitality for business visitors.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and growth through referrals.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and support via telephone.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free support via Zoom.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and service into the evening.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Visit us in Boston and say hello.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Visit us in Boston and bring a friend.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and valet parking available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and street parking available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and visitor parking available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and garage parking available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and bicycle parking available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and paid parking available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and overnight parking available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and staff parking available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Visit us in Boston and enjoy the view.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and free support for WordPress users.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and premium customer service available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and technical support available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and full-service support available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and 24/7 support available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and dedicated customer support available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and virtual appointments available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and same-day appointments available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and same day appointments available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and walk-in appointments available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and support via Slack.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and consultations via Zoom.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and appointments in person.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and enterprise customer support available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and support via Discord.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and appointments via Google Meet.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and support at no cost.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and service covers enterprise clients.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and evening appointments available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and support via Webex.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and appointments via FaceTime.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and service covers nonprofit clients.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and customer support serving WordPress users.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helps growing families.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helps young families.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helps global customers.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helps solve technical problems.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helps creative teams.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support assists with setup.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helps troubleshoot issues.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helps ensure uptime.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helps account recovery.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helps remote teams.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support is available daily.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support hours vary.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support was helpful.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support services for growing businesses.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support remains available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support resources are available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support exists online.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support team works remotely.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support can answer questions.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support can be remote.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support may work well.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support could cost less.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support options include email and chat.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support team answers questions.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helps answer questions.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helps protect your account.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support includes live chat.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support includes phone and email.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our services teach useful skills.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support helps product teams.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support includes Microsoft Teams.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support improves accessibility.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and weekend appointments available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and flexible appointments available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and emergency repairs available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and evening repairs available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and urgent repairs available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and warranty repairs available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and expert consultations available.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and complimentary parking available.</p><!-- /wp:paragraph -->',
    ];
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(implode('', $invented) . implode('', $safe), $input);

    assert_eq(implode('', $safe), $result->markup);
    assert_eq(76, count($result->warnings));
});

test('SectionUnit applies foreign destination semantics only inside SVG and MathML namespaces', function () {
    $markup = '<!-- wp:html --><mrow href="https://invented.example/path">Safe</mrow><!-- /wp:html -->'
        . '<!-- wp:html --><rect fill="url(https://invented.example/a.svg)">Safe</rect><!-- /wp:html -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, section_unit_input());

    assert_eq($markup, $result->markup);
    assert_eq([], $result->warnings);

    $active = '<!-- wp:html --><svg><foreignObject><form action="https://invented.example/post">'
        . '<button>Go</button></form></foreignObject></svg><!-- /wp:html -->'
        . '<!-- wp:html --><math><mtext><input type="image" '
        . 'src="https://invented.example/a.png"></mtext></math><!-- /wp:html -->'
        . '<!-- wp:html --><svg><text><![CDATA[fake@example.com]]></text></svg><!-- /wp:html -->'
        . '<!-- wp:html --><svg><template><image href="https://invented.example/pixel.png">'
        . '</image></template></svg><!-- /wp:html -->'
        . '<!-- wp:html --><div><template shadowrootmode="open"><a '
        . 'href="https://invented.example/">Visit</a></template></div><!-- /wp:html -->';
    $blocked = $unit->finish($active, section_unit_input());
    assert_eq('', $blocked->markup);
    assert_eq(5, count($blocked->warnings));
});

test('SectionUnit follows browser hidden RCDATA shadow and accessible-name semantics', function () {
    $hiddenVoid = '<!-- wp:html --><input hidden value="fake@example.com"><p>Safe sibling</p>'
        . '<!-- /wp:html -->';
    $invalidShadow = '<!-- wp:html --><ul><template shadowrootmode="open">fake@example.com</template></ul>'
        . '<p>Safe shadow host</p><!-- /wp:html -->';
    $inert = '<!-- wp:html --><div inert aria-label="fake@example.com">Safe inert text</div>'
        . '<!-- /wp:html -->';
    $dangerous = [
        '<!-- wp:html --><span hidden style="display:block">fake@example.com</span><!-- /wp:html -->',
        '<!-- wp:html --><span hidden style="display:block!important;display:none">fake@example.com</span>'
            . '<!-- /wp:html -->',
        '<!-- wp:html --><textarea><span hidden>fake@example.com</span></textarea><!-- /wp:html -->',
        '<!-- wp:html --><button aria-labelledby="contact-label">Contact</button>'
            . '<span id="contact-label" hidden>fake@example.com</span><!-- /wp:html -->',
        '<!-- wp:html --><div><template shadowrootmode="open">fake@example.com</template></div>'
            . '<!-- /wp:html -->',
    ];
    $orphanDatalist = '<!-- wp:html --><datalist><option value="fake@example.com"></datalist>'
        . '<!-- /wp:html -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(
        $hiddenVoid . $invalidShadow . $inert . $orphanDatalist . implode('', $dangerous),
        section_unit_input(),
    );

    assert_eq($invalidShadow . $inert . $orphanDatalist, $result->markup);
    assert_eq(6, count($result->warnings));
});

test('plaintext keeps entity syntax literal', function () {
    assert_eq(
        'fake&#64;example.com',
        \Automattic\SiteBuild\PlainText::fromMarkup('<plaintext>fake&#64;example.com'),
    );
});

test('HTML self-closing syntax does not invalidate a declarative shadow host', function () {
    $markup = '<!-- wp:html --><div/><template shadowrootmode="open">fake@example.com</template>'
        . '<!-- /wp:html -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(1, count($result->warnings));
});

test('SectionUnit tracks foreign namespace transitions and truncated CDATA like a browser', function () {
    $parts = [
        '<!-- wp:html --><svg><font color="red"><form action="https://invented.example/post">'
            . 'Go</form></font></svg><!-- /wp:html -->',
        '<!-- wp:html --><math><annotation-xml><svg><foreignObject><form '
            . 'action="https://invented.example/post">Go</form></foreignObject></svg>'
            . '</annotation-xml></math><!-- /wp:html -->',
        '<!-- wp:html --><svg><foreignObject><p>Safe</p></foreignObject>'
            . '<filter href="https://invented.example/filter"></filter></svg><!-- /wp:html -->',
        '<!-- wp:html --><svg><![CDATA[fake@example.com<!-- /wp:html -->',
    ];
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(implode('', $parts), section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(4, count($result->warnings));
});

test('SectionUnit scans SVG data images and active SVG presentation URL sinks', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><text>fake@example.com</text></svg>';
    $data = 'data:image/svg+xml;base64,' . base64_encode($svg);
    $parts = [
        '<!-- wp:html --><img src="/safe.png" srcset="' . $data . ' 2x"><!-- /wp:html -->',
        '<!-- wp:html --><div style="background-image:url(' . $data . ')">Safe</div><!-- /wp:html -->',
        '<!-- wp:html --><svg><text><tspan fill="url(https://invented.example/paint.svg#x)">x</tspan>'
            . '</text></svg><!-- /wp:html -->',
        '<!-- wp:html --><svg><foreignObject filter="url(https://invented.example/filter.svg#x)">'
            . '<p>Safe</p></foreignObject></svg><!-- /wp:html -->',
        '<!-- wp:html --><svg><switch filter="url(https://invented.example/switch.svg#x)">'
            . '<rect width="20" height="20"></rect></switch></svg><!-- /wp:html -->',
        '<!-- wp:html --><svg><defs fill="url(https://invented.example/defs.svg#x)">'
            . '<rect id="x" width="20" height="20"></rect></defs><use href="#x"></use></svg><!-- /wp:html -->',
    ];
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(implode('', $parts), section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(6, count($result->warnings));
});

test('contact grounding scans XHTML native values rendered inside data SVG', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="80"><foreignObject '
        . 'width="300" height="80"><div xmlns="http://www.w3.org/1999/xhtml">'
        . '<input value="foreign-object@example.com"/></div></foreignObject></svg>';
    $markup = '<!-- wp:html --><img src="data:image/svg+xml;base64,' . base64_encode($svg) . '">'
        . '<!-- /wp:html -->';
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(1, count($warnings));
    assert_contains('foreign-object@example.com', $warnings[0]);
});

test('contact grounding ignores foreign input semantics and rasterized SVG ARIA', function () {
    $foreign = '<!-- wp:html --><svg><input value="foreign@example.com"></input></svg><p>Safe</p>'
        . '<!-- /wp:html -->';
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
        . 'xmlns="http://www.w3.org/1999/xhtml" aria-label="internal@example.com">Safe</div>'
        . '</foreignObject></svg>';
    $rasterized = '<!-- wp:html --><img alt="Safe" src="data:image/svg+xml;base64,'
        . base64_encode($svg) . '"><!-- /wp:html -->';
    $warnings = [];

    assert_eq($foreign . $rasterized, GroundedContactMarkup::scrub(
        $foreign . $rasterized,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq([], $warnings);
});

test('SectionUnit decodes percent-encoded base64 SVG image data like a browser', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><text>fake@example.com</text></svg>';
    $encoded = str_replace(['+', '/', '='], ['%2B', '%2F', '%3D'], base64_encode($svg));
    $data = 'data:image/svg+xml;base64,' . $encoded;
    $markup = '<!-- wp:html --><img src="/safe.png" srcset="' . $data . ' 2x"><!-- /wp:html -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(1, count($result->warnings));
});

test('contact grounding inspects browser-decoded XML and CSS inside SVG data images', function () {
    $utf16 = "\xFE\xFF" . mb_convert_encoding(
        '<svg xmlns="http://www.w3.org/2000/svg"><text>fake@example.com</text></svg>',
        'UTF-16BE',
        'UTF-8',
    );
    $entity = '<!DOCTYPE svg [<!ENTITY e "fake@example.com">]>'
        . '<svg xmlns="http://www.w3.org/2000/svg"><text>&e;</text></svg>';
    $styled = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject>'
        . '<div xmlns="http://www.w3.org/1999/xhtml" class="x"></div>'
        . '<style xmlns="http://www.w3.org/1999/xhtml">.x::before{content:"fake@example.com"}</style>'
        . '</foreignObject></svg>';
    $parts = [
        'data:image/svg+xml;charset=utf-16;base64,' . base64_encode($utf16),
        'data:image/svg+xml;base64,' . base64_encode($entity),
        'data:image/svg+xml;base64,' . base64_encode($styled),
        'data:image/svg+xml ;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg"><text>fake@example.com</text></svg>',
        ),
    ];
    foreach ($parts as $data) {
        $emails = array_values(array_filter(
            GroundedContactMarkup::svgDataCandidates($data),
            static fn (array $candidate): bool => $candidate['type'] === 'email',
        ));
        assert_eq('fake@example.com', $emails[0]['canonical'] ?? null);
    }
});

test('contact grounding inspects active SVG stylesheet instructions and ignores inert style types', function () {
    $instruction = '<?xml-stylesheet type="text/css" '
        . 'href="data:text/css,.x%3A%3Abefore%7Bcontent%3A%22fake%40example.com%22%7D"?>'
        . '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject>'
        . '<div xmlns="http://www.w3.org/1999/xhtml" class="x"></div>'
        . '</foreignObject></svg>';
    $inert = '<svg xmlns="http://www.w3.org/2000/svg"><style type="text/plain">'
        . '.x::before{content:"fake@example.com"}</style><rect width="20" height="20" fill="red"/>'
        . '</svg>';

    $activeCandidates = GroundedContactMarkup::svgDataCandidates(
        'data:image/svg+xml;base64,' . base64_encode($instruction),
    );
    $inertCandidates = GroundedContactMarkup::svgDataCandidates(
        'data:image/svg+xml;base64,' . base64_encode($inert),
    );

    assert_true(in_array('fake@example.com', array_column($activeCandidates, 'canonical'), true));
    assert_true(!in_array('fake@example.com', array_column($inertCandidates, 'canonical'), true));
});

test('contact grounding follows browser CSS encoding and stylesheet import behavior in SVG data', function () {
    $css = '.x::before{content:"fake@example.com"}';
    $utf16 = "\xFF\xFE" . mb_convert_encoding($css, 'UTF-16LE', 'UTF-8');
    $hrefs = [
        'data:text/css,' . rawurlencode($css),
        'data:text/css;charset=utf-16;base64,' . base64_encode($utf16),
        'data:text/css,' . rawurlencode('@import "data:text/css,' . rawurlencode($css) . '";'),
    ];
    foreach ($hrefs as $index => $href) {
        $type = $index === 0 ? '' : ' type="text/css"';
        $svg = '<?xml-stylesheet' . $type . ' href="' . $href . '"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject>'
            . '<div xmlns="http://www.w3.org/1999/xhtml" class="x"></div>'
            . '</foreignObject></svg>';
        $candidates = GroundedContactMarkup::svgDataCandidates(
            'data:image/svg+xml;base64,' . base64_encode($svg),
        );
        assert_true($candidates !== [], 'active stylesheet form is never silently uninspected');
    }
});

test('contact grounding ignores inactive SVG stylesheet media and alternates', function () {
    $css = rawurlencode('.x::before{content:"fake@example.com"}');
    $payloads = [
        '<?xml-stylesheet type="text/css" media="not all" href="data:text/css,' . $css . '"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject></svg>',
        '<?xml-stylesheet type="text/css" alternate="yes" title="alt" href="data:text/css,' . $css . '"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject></svg>',
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject>'
            . '<style media="not all">.x::before{content:"fake@example.com"}</style></svg>',
        '<?xml-stylesheet type="text/css" media="not&#9;all" href="data:text/css,' . $css . '"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject></svg>',
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject>'
            . '<style media="not  all">.x::before{content:"fake@example.com"}</style></svg>',
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject>'
            . '<style media="not all, not all">.x::before{content:"fake@example.com"}</style></svg>',
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject>'
            . '<style media="not all,">.x::before{content:"fake@example.com"}</style></svg>',
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject>'
            . '<style media="not all and (min-width:0px)">.x::before{content:"fake@example.com"}</style></svg>',
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject>'
            . '<style media=",">.x::before{content:"fake@example.com"}</style></svg>',
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject>'
            . '<style media=",,">.x::before{content:"fake@example.com"}</style></svg>',
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject>'
            . '<style media="all\2c not all">.x::before{content:"fake@example.com"}</style></svg>',
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject>'
            . '<style media="not/**/all">.x::before{content:"fake@example.com"}</style></svg>',
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject>'
            . '<style media="n\\6ft all">.x::before{content:"fake@example.com"}</style></svg>',
    ];
    foreach ($payloads as $svg) {
        $candidates = GroundedContactMarkup::svgDataCandidates(
            'data:image/svg+xml;base64,' . base64_encode($svg),
        );
        assert_true(!in_array('fake@example.com', array_column($candidates, 'canonical'), true));
    }
});

test('contact grounding keeps escaped commas inside active SVG media identifiers', function () {
    $css = rawurlencode('.x::before{content:"fake@example.com"}');
    $payloads = [
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject>'
            . '<style media="not all\2c">.x::before{content:"fake@example.com"}</style></svg>',
        '<?xml-stylesheet type="text/css" media="not all\2c" href="data:text/css,' . $css . '"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject></svg>',
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject>'
            . '<style media="not all\20">.x::before{content:"fake@example.com"}</style></svg>',
        '<?xml-stylesheet type="text/css" media="not all\9" href="data:text/css,' . $css . '"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div '
            . 'xmlns="http://www.w3.org/1999/xhtml" class="x"/></foreignObject></svg>',
    ];
    foreach ($payloads as $svg) {
        $candidates = GroundedContactMarkup::svgDataCandidates(
            'data:image/svg+xml;base64,' . base64_encode($svg),
        );
        assert_true(in_array('fake@example.com', array_column($candidates, 'canonical'), true));
    }
});

test('contact grounding fails closed without the required DOM extension', function () {
    $html = '<button aria-labelledby="x">Contact</button>'
        . '<span id="x" hidden aria-label="fake@example.com"></span>';
    $method = new ReflectionMethod(GroundedContactMarkup::class, 'referencedAccessibleTextCandidates');
    $candidates = $method->invoke(null, $html, false);

    assert_true($candidates !== []);
    assert_contains('DOM unavailable', $candidates[0]['authored']);

    $descendant = '<button aria-labelledby="x">Contact</button>'
        . '<span id="x" hidden title="Safe"><img alt="fake@example.com"></span>';
    $descendantCandidates = $method->invoke(null, $descendant, false);
    assert_true($descendantCandidates !== []);

    $safe = '<button aria-labelledby="x">Contact</button><span id="x" hidden>Safe label</span>';
    assert_true($method->invoke(null, $safe, false) !== []);

    $titleOnly = '<button aria-labelledby="x">Contact</button>'
        . '<span id="x" hidden title="fake@example.com"></span>';
    assert_true($method->invoke(null, $titleOnly, false) !== []);
    $ignoredTitle = '<button aria-labelledby="x">Contact</button>'
        . '<span id="x" hidden title="fake@example.com">Safe label</span>';
    assert_true($method->invoke(null, $ignoredTitle, false) !== []);
});

test('contact grounding follows first ARIA attributes and presentational image names', function () {
    $duplicate = '<button aria-labelledby="safe" aria-labelledby="evil">Contact</button>'
        . '<span id="safe" hidden>Safe</span><span id="evil" hidden aria-label="fake@example.com"></span>'
        ;
    $method = new ReflectionMethod(GroundedContactMarkup::class, 'referencedAccessibleTextCandidates');
    assert_eq([], $method->invoke(null, $duplicate, true));

    $presentation = '<!-- wp:html --><button aria-labelledby="x">Contact</button>'
        . '<span id="x" hidden><img role="presentation" alt="fake@example.com"></span><!-- /wp:html -->';
    $warnings = [];

    $delivered = GroundedContactMarkup::scrub(
        $presentation,
        [],
        'theme/parts/test.html',
        $warnings,
    );

    assert_eq('', $delivered);
    assert_eq(1, count($warnings));
});

test('contact grounding resolves referenced presentational image names across blocks', function () {
    $sameBlock = '<!-- wp:html --><button aria-labelledby="pic">Open</button>'
        . '<img id="pic" src="/safe.gif" role="presentation" alt="same@example.com"><!-- /wp:html -->';
    $button = '<!-- wp:html --><button aria-labelledby="cross-pic">Open</button><!-- /wp:html -->';
    $image = '<!-- wp:html --><img id="cross-pic" src="/safe.gif" role="presentation" '
        . 'alt="cross@example.com"><!-- /wp:html -->';
    $warnings = [];

    $delivered = GroundedContactMarkup::scrub(
        $sameBlock . $button . $image,
        [],
        'theme/parts/test.html',
        $warnings,
    );

    assert_eq($button, $delivered, 'rendered image fallback is removed before contextual references recompute');
    assert_eq(2, count($warnings));
    assert_contains('same@example.com', implode("\n", $warnings));
    assert_contains('cross@example.com', implode("\n", $warnings));
});

test('contact grounding composes multi-ID accessible names before extraction', function () {
    $phone = '<!-- wp:html --><button aria-labelledby="phone-a phone-b phone-c">Call</button>'
        . '<span id="phone-a">207</span><span id="phone-b">555</span><span id="phone-c">0199</span>'
        . '<!-- /wp:html -->';
    $address = '<!-- wp:html --><button aria-describedby="address-a address-b">Visit</button>'
        . '<span id="address-a">123</span><span id="address-b">Main Street</span><!-- /wp:html -->';
    $self = '<!-- wp:html --><button id="self-a" aria-labelledby="self-a self-b">123</button>'
        . '<span id="self-b" hidden aria-label="Main Street"></span><!-- /wp:html -->';
    $unicode = '<!-- wp:html --><button aria-labelledby="unicode-a&#160;unicode-b&#8239;unicode-c">Open</button>'
        . '<span id="unicode-a">207</span><span id="unicode-b">555</span><span id="unicode-c">0199</span>'
        . '<!-- /wp:html -->';
    $cycle = '<!-- wp:html --><button aria-labelledby="cycle-x cycle-z">Open</button>'
        . '<span id="cycle-x" aria-labelledby="cycle-y">123</span>'
        . '<span id="cycle-y" aria-labelledby="cycle-x"></span>'
        . '<span id="cycle-z">Main Street</span><!-- /wp:html -->';
    $nonemptyCycle = '<!-- wp:html --><button aria-labelledby="nonempty-x nonempty-z">Open</button>'
        . '<span id="nonempty-x" aria-labelledby="nonempty-y">123</span>'
        . '<span id="nonempty-y" aria-labelledby="nonempty-x">irrelevant</span>'
        . '<span id="nonempty-z">Main Street</span><!-- /wp:html -->';
    $mixedSelfCycle = '<!-- wp:html --><button id="mixed-a" aria-labelledby="mixed-a mixed-b mixed-c">'
        . '123</button><span id="mixed-b" aria-labelledby="mixed-a"></span>'
        . '<span id="mixed-c" aria-label="Main Street"></span><!-- /wp:html -->';
    $mixedCycleContent = '<!-- wp:html --><button id="content-a" '
        . 'aria-labelledby="content-a content-b content-c">Safe</button>'
        . '<span id="content-b" aria-labelledby="content-a">123</span>'
        . '<span id="content-c" aria-label="Main Street"></span><!-- /wp:html -->';
    $nameAndDescription = '<!-- wp:html --><button aria-labelledby="paired-name" '
        . 'aria-describedby="paired-description">Visit</button><span id="paired-name">123</span>'
        . '<span id="paired-description">Main Street</span><!-- /wp:html -->';
    $titleDescription = '<!-- wp:html --><button title="Main Street">123</button><!-- /wp:html -->';
    $emptyDescription = '<!-- wp:html --><button aria-describedby="empty-description" '
        . 'aria-description="description@example.com">Safe</button>'
        . '<span id="empty-description"></span><!-- /wp:html -->';
    $overriddenAriaLabel = '<!-- wp:html --><button aria-labelledby="safe-name" '
        . 'aria-label="overridden@example.com">Open</button><span id="safe-name">Safe</span><!-- /wp:html -->';
    $overriddenAlt = '<!-- wp:html --><img src="/safe.gif" aria-labelledby="safe-image-name" '
        . 'alt="overridden-alt@example.com"><span id="safe-image-name">Safe</span><!-- /wp:html -->';
    $templateDescription = '<!-- wp:html --><button aria-describedby="empty-template" '
        . 'aria-description="template-description@example.com">Safe</button>'
        . '<template id="empty-template"></template><!-- /wp:html -->';
    $styleDescription = '<!-- wp:html --><button aria-describedby="style-description">Safe</button>'
        . '<style id="style-description">.z{color:red}/* style-description@example.com */</style>'
        . '<!-- /wp:html -->';
    $nestedReferencedName = '<!-- wp:html --><button aria-labelledby="nested-x nested-y nested-z">Call</button>'
        . '<span id="nested-x" aria-labelledby="nested-q">207</span>'
        . '<span id="nested-q">irrelevant</span><span id="nested-y">555</span>'
        . '<span id="nested-z">0199</span><!-- /wp:html -->';
    $nestedReferencedDescription = '<!-- wp:html --><button '
        . 'aria-describedby="nested-description-x nested-description-y">Visit</button>'
        . '<span id="nested-description-x" aria-labelledby="nested-description-q">123</span>'
        . '<span id="nested-description-q">irrelevant</span>'
        . '<span id="nested-description-y">Main Street</span><!-- /wp:html -->';
    $warnings = [];

    $delivered = GroundedContactMarkup::scrub(
        $phone . $address . $self . $unicode . $cycle . $nonemptyCycle . $mixedSelfCycle . $mixedCycleContent
            . $nameAndDescription . $titleDescription . $emptyDescription . $overriddenAriaLabel
            . $overriddenAlt . $templateDescription . $styleDescription . $nestedReferencedName
            . $nestedReferencedDescription,
        [],
        'theme/parts/test.html',
        $warnings,
    );

    assert_eq(
        $unicode . $emptyDescription . $overriddenAriaLabel . $templateDescription . $styleDescription,
        $delivered,
    );
    assert_eq(12, count($warnings));
    assert_contains('207 555 0199', implode("\n", $warnings));
    assert_contains('123 Main Street', implode("\n", $warnings));
});

test('contact grounding scans rendered native values despite safe ARIA naming', function () {
    $markup = '<!-- wp:html --><input value="value@example.com" aria-label="Safe"><!-- /wp:html -->'
        . '<!-- wp:html --><input placeholder="placeholder@example.com" aria-label="Safe"><!-- /wp:html -->'
        . '<!-- wp:html --><img src="/missing.png" aria-label="Safe" alt="alt@example.com"><!-- /wp:html -->'
        . '<!-- wp:html --><button aria-describedby="safe-description" '
        . 'title="title@example.com">Safe</button><span id="safe-description">Safe description</span>'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><button aria-hidden="true" title="hidden-title@example.com">Safe</button>'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><input aria-hidden="true" value="hidden-value@example.com"><!-- /wp:html -->'
        . '<!-- wp:html --><input aria-hidden="true" placeholder="hidden-placeholder@example.com">'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><img aria-hidden="true" src="/missing.png" alt="hidden-alt@example.com">'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><div inert><input value="inert-value@example.com"></div><!-- /wp:html -->'
        . '<!-- wp:html --><img src="/missing.png" role="presentation" '
        . 'alt="presentation-alt@example.com"><!-- /wp:html -->';
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(10, count($warnings));
});

test('contact grounding preserves numeric controls and tag-ignored native attributes', function () {
    $safe = '<!-- wp:html --><label>Order ID <input type="number" value="1234567890"></label>'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><input type="button" value="Safe" placeholder="ignored@example.com">'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><input type="date" value="invalid@example.com"><!-- /wp:html -->';
    $unsafe = '<!-- wp:html --><label>Phone <input type="number" value="2125550199"></label>'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><input type="number" placeholder="number-placeholder@example.com">'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><input type="date" placeholder="date-placeholder@example.com">'
        . '<!-- /wp:html -->';
    $warnings = [];

    assert_eq($safe, GroundedContactMarkup::scrub(
        $safe . $unsafe,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(3, count($warnings));
});

test('contact grounding evaluates rendered form values with their browser-computed names', function () {
    $unsafe = '<!-- wp:html --><label>207 555<input value="0199"></label><!-- /wp:html -->'
        . '<!-- wp:html --><label>1 Infinite <input value="Loop"></label><!-- /wp:html -->'
        . '<!-- wp:html --><input aria-label="207 555" value="0199"><!-- /wp:html -->'
        . '<!-- wp:html --><label for="phone">207 555</label><input id="phone" value="0199">'
        . '<!-- /wp:html -->';
    $safe = '<!-- wp:html --><label for="order">Order ID</label>'
        . '<input id="order" type="number" value="1234567890"><!-- /wp:html -->'
        . '<!-- wp:html --><span id="order-name">Order ID</span>'
        . '<input type="number" aria-labelledby="order-name" value="1234567890"><!-- /wp:html -->';
    $warnings = [];

    assert_eq($safe, GroundedContactMarkup::scrub(
        $unsafe . $safe,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(4, count($warnings));
});

test('contact grounding resolves labels globally and gives duplicate IDs first-match browser semantics', function () {
    $safeLabel = '<!-- wp:html --><label for="order">Order ID</label><!-- /wp:html -->';
    $safeInput = '<!-- wp:html --><input id="order" type="number" value="1234567890">'
        . '<!-- /wp:html -->';
    $duplicate = '<!-- wp:html --><label for="x">Order ID</label>'
        . '<input id="x" type="number" value="1234567890">'
        . '<input id="x" type="number" value="2125550199"><!-- /wp:html -->';
    $warnings = [];

    assert_eq($safeLabel . $safeInput, GroundedContactMarkup::scrub(
        $safeLabel . $safeInput . $duplicate,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(1, count($warnings));
    assert_contains('2125550199', $warnings[0]);
});

test('contact grounding composes every browser-exposed textbox and select value', function () {
    $parts = [
        '<!-- wp:html --><label for="textarea-phone">207 555</label><!-- /wp:html -->'
            . '<!-- wp:html --><textarea id="textarea-phone">0199</textarea><!-- /wp:html -->',
        '<!-- wp:html --><label for="select-phone">207 555</label><!-- /wp:html -->'
            . '<!-- wp:html --><select id="select-phone"><option selected>0199</option></select>'
            . '<!-- /wp:html -->',
        '<!-- wp:html --><div contenteditable role="textbox" aria-label="207 555">0199</div>'
            . '<!-- /wp:html -->',
        '<!-- wp:html --><label for="missing-phone">207 555</label>'
            . '<input id="missing-phone" aria-labelledby="missing" value="0199"><!-- /wp:html -->',
        '<!-- wp:html --><span id="empty-name"></span><label for="empty-phone">207 555</label>'
            . '<input id="empty-phone" aria-labelledby="empty-name" value="0199"><!-- /wp:html -->',
        '<!-- wp:html --><label hidden for="hidden-label-phone">Order ID</label>'
            . '<input id="hidden-label-phone" type="number" value="2125550199"><!-- /wp:html -->',
    ];
    $warnings = [];
    $survivingLabels = '<!-- wp:html --><label for="textarea-phone">207 555</label><!-- /wp:html -->'
        . '<!-- wp:html --><label for="select-phone">207 555</label><!-- /wp:html -->';

    assert_eq($survivingLabels, GroundedContactMarkup::scrub(
        implode('', $parts),
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(6, count($warnings));
});

test('contact grounding activates bare and inherited contenteditable textboxes in isolation', function () {
    $parts = [
        '<!-- wp:html --><div contenteditable role="textbox" aria-label="207 555">0199</div>'
            . '<!-- /wp:html -->',
        '<!-- wp:html --><div contenteditable><div role="textbox" aria-label="207 555">0199</div>'
            . '</div><!-- /wp:html -->',
    ];
    foreach ($parts as $markup) {
        $warnings = [];
        assert_eq('', GroundedContactMarkup::scrub(
            $markup,
            [],
            'theme/parts/test.html',
            $warnings,
        ));
        assert_eq(1, count($warnings));
    }
});

test('contact grounding composes roleless editable values and visible control variants', function () {
    $parts = [
        '<!-- wp:html --><div contenteditable aria-label="207 555">0199</div><!-- /wp:html -->',
        '<!-- wp:html --><label for="range-phone">Phone 207 555</label>'
            . '<input id="range-phone" type="range" min="0" max="9999" value="1999"><!-- /wp:html -->',
        '<!-- wp:html --><label for="multi-phone">207 555</label><select id="multi-phone" multiple>'
            . '<option>0199</option></select><!-- /wp:html -->',
        '<!-- wp:html --><label for="image-phone">207 555</label>'
            . '<input id="image-phone" type="image" src="missing.gif" alt="0199"><!-- /wp:html -->',
        '<!-- wp:html --><label for="default-range-phone">Phone 207 555</label>'
            . '<input id="default-range-phone" type="range" min="0" max="9999" value="garbage">'
            . '<!-- /wp:html -->',
        '<!-- wp:html --><label for="missing-range-phone">Phone 207 555</label>'
            . '<input id="missing-range-phone" type="range" min="0" max="9999"><!-- /wp:html -->',
        '<!-- wp:html --><label for="button-element-phone">207 555</label>'
            . '<button id="button-element-phone">0199</button><!-- /wp:html -->',
        '<!-- wp:html --><div contenteditable><div aria-label="207 555">0199</div></div><!-- /wp:html -->',
    ];
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub(
        implode('', $parts),
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(8, count($warnings));
});

test('contact grounding excludes hidden descendants from native label text', function () {
    $parts = [
        '<label for="hidden-child"><span hidden>Order ID</span></label>',
        '<label for="display-child"><span style="display:none">Order ID</span></label>',
        '<label for="visibility-child"><span style="visibility:hidden">Order ID</span></label>',
        '<label for="aria-child"><span aria-hidden="true">Order ID</span></label>',
    ];
    $markup = '';
    foreach ($parts as $offset => $label) {
        $id = ['hidden-child', 'display-child', 'visibility-child', 'aria-child'][$offset];
        $markup .= '<!-- wp:html -->' . $label . '<input id="' . $id
            . '" type="number" value="2125550199"><!-- /wp:html -->';
    }
    $markup .= '<!-- wp:html --><style>.gone{display:none}</style>'
        . '<label class="gone" for="class-hidden-child">Order ID</label>'
        . '<input id="class-hidden-child" type="number" value="2125550199"><!-- /wp:html -->';
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(5, count($warnings));
});

test('contact grounding applies selector cascade and ignores inactive styles', function () {
    $unsafe = [
        '<style>label.gone{display:none}</style><label class="gone" for="compound">Order ID</label>'
            . '<input id="compound" type="number" value="2125550199">',
        '<style>.wrap label[data-kind="order"]{visibility:hidden}</style><div class="wrap">'
            . '<label data-kind="order" for="descendant">Order ID</label></div>'
            . '<input id="descendant" type="number" value="2125550199">',
    ];
    $safe = [
        '<style>.gone{display:none}.gone{display:block}</style>'
            . '<label class="gone" for="override">Order ID</label>'
            . '<input id="override" type="number" value="2125550199">',
        '<style media="not all">.gone{display:none}</style>'
            . '<label class="gone" for="inactive">Order ID</label>'
            . '<input id="inactive" type="number" value="2125550199">',
        '<template><style>.gone{display:none}</style></template>'
            . '<label class="gone" for="template-style">Order ID</label>'
            . '<input id="template-style" type="number" value="2125550199">',
    ];
    $markup = '';
    foreach ([...$unsafe, ...$safe] as $part) {
        $markup .= '<!-- wp:html -->' . $part . '<!-- /wp:html -->';
    }
    $expected = '';
    foreach ($safe as $part) {
        $expected .= '<!-- wp:html -->' . $part . '<!-- /wp:html -->';
    }
    $warnings = [];

    assert_eq($expected, GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(2, count($warnings));
});

test('contact grounding resolves functional selectors and indirect hiding values', function () {
    $parts = [
        '<style>:is(.gone){display:none}</style><label class="gone" for="is-hidden">Order ID</label>'
            . '<input id="is-hidden" type="number" value="2125550199">',
        '<style>:where(.gone){display:none}</style><label class="gone" for="where-hidden">Order ID</label>'
            . '<input id="where-hidden" type="number" value="2125550199">',
        '<style>.gone{--hide:none;display:var(--hide)}</style>'
            . '<label class="gone" for="custom-hidden">Order ID</label>'
            . '<input id="custom-hidden" type="number" value="2125550199">',
        '<style>.gone{display:var(--missing,none)}</style>'
            . '<label class="gone" for="fallback-hidden">Order ID</label>'
            . '<input id="fallback-hidden" type="number" value="2125550199">',
        '<style>:root{--root-hide:none}.gone{display:var(--root-hide)}</style>'
            . '<label class="gone" for="root-hidden">Order ID</label>'
            . '<input id="root-hidden" type="number" value="2125550199">',
    ];
    $markup = '';
    foreach ($parts as $part) {
        $markup .= '<!-- wp:html -->' . $part . '<!-- /wp:html -->';
    }
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(5, count($warnings));
});

test('contact grounding resolves structural and flagged label selectors', function () {
    $selectors = [
        'label.gone:first-child',
        'label.gone:nth-child(1)',
        'label:has(+ input)',
        'label[class=GONE i]',
    ];
    $markup = '';
    foreach ($selectors as $index => $selector) {
        $markup .= '<!-- wp:html --><style>' . $selector . '{display:none}</style><div>'
            . '<label class="gone" for="structural-' . $index . '">Order ID</label>'
            . '<input id="structural-' . $index . '" type="number" value="2125550199"></div>'
            . '<!-- /wp:html -->';
    }
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub($markup, [], 'theme/parts/test.html', $warnings));
    assert_eq(4, count($warnings));
});

test('contact grounding excludes hidden descendants from rendered control values', function () {
    $parts = [
        '<button><span hidden>Order ID</span>2125550199</button>',
        '<output><span style="display:none">Order ID</span>2125550199</output>',
        '<div contenteditable><span style="visibility:hidden">Order ID</span>2125550199</div>',
    ];
    $markup = '';
    foreach ($parts as $part) {
        $markup .= '<!-- wp:html -->' . $part . '<!-- /wp:html -->';
    }
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(3, count($warnings));
});

test('contact grounding follows range step base when min is absent', function () {
    $markup = '<!-- wp:html --><label for="range-step-base">207 555</label>'
        . '<input id="range-step-base" type="range" max="9999" value="4999" step="10000">'
        . '<!-- /wp:html -->';
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(1, count($warnings));
});

test('contact grounding treats inherited editable descendants as one static value', function () {
    $markup = '<!-- wp:html --><div contenteditable>Order ID <span>2125550199</span></div>'
        . '<!-- /wp:html -->';
    $warnings = [];

    assert_eq($markup, GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq([], $warnings);
});

test('contact grounding excludes hidden select choices from the published value', function () {
    $parts = [
        '<select multiple><option hidden>Order ID</option><option>2125550199</option></select>',
        '<select multiple><optgroup hidden label="Order ID"><option>Order ID</option></optgroup>'
            . '<option>2125550199</option></select>',
    ];
    $markup = '';
    foreach ($parts as $part) {
        $markup .= '<!-- wp:html -->' . $part . '<!-- /wp:html -->';
    }
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(2, count($warnings));
});

test('contact grounding scans datalist values only when a live input publishes them', function () {
    $safe = '<!-- wp:html --><template><datalist id="template-list">'
        . '<option value="fake@example.com"></datalist></template><p>Safe</p><!-- /wp:html -->'
        . '<!-- wp:html --><datalist id="orphan-list"><option value="fake@example.com"></datalist>'
        . '<p>Safe orphan</p><!-- /wp:html -->'
        . '<!-- wp:html --><input type="hidden" list="hidden-list"><datalist id="hidden-list">'
        . '<option value="fake@example.com"></datalist><!-- /wp:html -->'
        . '<!-- wp:html --><input hidden list="hidden-attribute-list">'
        . '<datalist id="hidden-attribute-list"><option value="fake@example.com"></datalist><!-- /wp:html -->'
        . '<!-- wp:html --><input style="display:none" list="hidden-style-list">'
        . '<datalist id="hidden-style-list"><option value="fake@example.com"></datalist><!-- /wp:html -->';
    $unsafe = '<!-- wp:html --><input list="live-list"><datalist id="live-list">'
        . '<option value="fake@example.com"></datalist><!-- /wp:html -->';
    $warnings = [];

    assert_eq($safe, GroundedContactMarkup::scrub(
        $safe . $unsafe,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(1, count($warnings));
});

test('contact grounding ignores disabled and unsupported datalist carriers', function () {
    $markup = '';
    foreach (['<input disabled>', '<input type="checkbox">', '<input type="radio">', '<input type="file">', '<input type="button">'] as $index => $input) {
        $id = 'inactive-list-' . $index;
        $markup .= '<!-- wp:html -->' . substr($input, 0, -1) . ' list="' . $id . '">'
            . '<datalist id="' . $id . '"><option value="fake@example.com"></datalist>'
            . '<!-- /wp:html -->';
    }
    $warnings = [];

    assert_eq($markup, GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq([], $warnings);
});

test('contact grounding ignores invalid typed and readonly datalist suggestions', function () {
    $carriers = [
        '<input type="range">',
        '<input type="number">',
        '<input type="date">',
        '<input type="color">',
        '<input type="text" readonly>',
    ];
    $markup = '';
    foreach ($carriers as $index => $input) {
        $id = 'typed-list-' . $index;
        $markup .= '<!-- wp:html -->' . substr($input, 0, -1) . ' list="' . $id . '">'
            . '<datalist id="' . $id . '"><option value="fake@example.com"></datalist>'
            . '<!-- /wp:html -->';
    }
    $warnings = [];

    assert_eq($markup, GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq([], $warnings);
});

test('contact grounding ignores invalid temporal datalist suggestions', function () {
    $markup = '';
    foreach (['time', 'month', 'week', 'datetime-local'] as $index => $type) {
        $id = 'temporal-list-' . $index;
        $markup .= '<!-- wp:html --><input type="' . $type . '" list="' . $id . '">'
            . '<datalist id="' . $id . '"><option value="fake@example.com"></datalist>'
            . '<!-- /wp:html -->';
    }
    $warnings = [];

    assert_eq($markup, GroundedContactMarkup::scrub($markup, [], 'theme/parts/test.html', $warnings));
    assert_eq([], $warnings);
});

test('contact grounding preserves generated numeric IDs named by their element', function () {
    $markup = '<!-- wp:html --><style>.ticket::before{content:"123456"}</style>'
        . '<span class="ticket">Ticket</span><!-- /wp:html -->';
    $warnings = [];

    assert_eq($markup, GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq([], $warnings);
});

test('SectionUnit distinguishes lowercase service locations from ordinary objects', function () {
    $input = section_unit_input();
    $input['site_spec'] = ['location' => 'Boston'];
    $unsafe = [
        '<!-- wp:paragraph --><p>Location: Boston and support can reach central cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and support serves cambridge.</p><!-- /wp:paragraph -->',
    ];
    $safe = [
        '<!-- wp:paragraph --><p>Location: Boston and support can improve accessibility.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and support helps engineering teams.</p><!-- /wp:paragraph -->',
    ];
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(implode('', [...$unsafe, ...$safe]), $input);

    assert_eq(implode('', $safe), $result->markup);
    assert_eq(2, count($result->warnings));
});

test('SectionUnit handles lowercase metro locations and technical audiences', function () {
    $input = section_unit_input();
    $input['site_spec'] = ['location' => 'Boston'];
    $unsafe = [
        '<!-- wp:paragraph --><p>Location: Boston and our support serves metro cambridge.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Location: Boston and our support reaches metropolitan cambridge.</p><!-- /wp:paragraph -->',
    ];
    $safe = '<!-- wp:paragraph --><p>Location: Boston and our support helps technical teams.</p>'
        . '<!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(implode('', $unsafe) . $safe, $input);

    assert_eq($safe, $result->markup);
    assert_eq(2, count($result->warnings));
});

test('SectionUnit separates lowercase location prepositions from ordinary support prose', function () {
    $input = section_unit_input();
    $input['site_spec'] = ['location' => 'Boston'];
    $unsafe = [
        'support is available outside cambridge',
        'support is available inside cambridge',
        'support is available from cambridge',
        'support extends outside cambridge',
    ];
    $safe = [
        'our services support accessibility',
        'support covers costs',
        'support extends beyond business hours',
        'support offers near instant replies',
    ];
    $markup = '';
    foreach ([...$unsafe, ...$safe] as $copy) {
        $markup .= '<!-- wp:paragraph --><p>Location: Boston and ' . $copy . '.</p><!-- /wp:paragraph -->';
    }
    $expected = '';
    foreach ($safe as $copy) {
        $expected .= '<!-- wp:paragraph --><p>Location: Boston and ' . $copy . '.</p><!-- /wp:paragraph -->';
    }
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $result = $unit->finish($markup, $input);

    assert_eq($expected, $result->markup);
    assert_eq(4, count($result->warnings));
});

test('contact grounding follows browser text input value sanitization', function () {
    $parts = [
        ['<input type="email" value="fake&#10;@photo.png">', []],
        ['<input type="text" value="fake&#13;@archive.zip">', []],
        ['<input type="email" value="fake&#10;@example.com">', ['domain' => 'example.com']],
    ];
    foreach ($parts as [$input, $siteSpec]) {
        $markup = '<!-- wp:html -->' . $input . '<!-- /wp:html -->';
        $warnings = [];
        assert_eq('', GroundedContactMarkup::scrub(
            $markup,
            $siteSpec,
            'theme/parts/test.html',
            $warnings,
        ));
        assert_eq(1, count($warnings));
    }
});

test('contact grounding composes self-referential ARIA names with native fallbacks', function () {
    $aria = '<!-- wp:html --><span id="aria-y">555</span><input id="aria-x" aria-label="207" '
        . 'aria-labelledby="aria-x aria-y" value="0199"><!-- /wp:html -->';
    $native = '<!-- wp:html --><label for="native-x">207</label><span id="native-y">555</span>'
        . '<input id="native-x" aria-labelledby="native-x native-y" value="0199"><!-- /wp:html -->';
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub(
        $aria . $native,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(2, count($warnings));
});

test('contact grounding uses browser accessible label text and visibility', function () {
    $unsafe = '<!-- wp:html --><label><img alt="207 555" src="valid.gif"><input value="0199"></label>'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><label><span aria-label="207 555"></span><input value="0199"></label>'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><label style="display:none" for="display-phone">Order ID</label>'
        . '<input id="display-phone" type="number" value="2125550199"><!-- /wp:html -->'
        . '<!-- wp:html --><label style="visibility:hidden" for="visibility-phone">Order ID</label>'
        . '<input id="visibility-phone" type="number" value="2125550199"><!-- /wp:html -->';
    $safe = '<!-- wp:html --><label inert for="inert-order">Order ID</label>'
        . '<input id="inert-order" type="number" value="1234567890"><!-- /wp:html -->';
    $warnings = [];

    assert_eq($safe, GroundedContactMarkup::scrub(
        $unsafe . $safe,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(4, count($warnings));
});

test('contact grounding keeps conditional visibility states independent', function () {
    $markup = '<!-- wp:html --><style>@media(max-width:1px){.gone{display:none}}</style>'
        . '<label class="gone" for="phone">207 555</label>'
        . '<input id="phone" value="0199"><!-- /wp:html -->';
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(1, count($warnings));
});

test('contact grounding evaluates compatible visibility conditions and stylesheet media', function () {
    $conditional = '<!-- wp:html --><style>.gone{display:var(--a,none)}'
        . '@media(min-width:500px){.gone{--a:var(--b)}}'
        . '@media(max-width:700px){.gone{--b:block}}'
        . '@media(min-width:800px){.gone{--b:none}}</style>'
        . '<label class="gone" for="phone">207 555</label>'
        . '<input id="phone" value="0199"><!-- /wp:html -->';
    $styleMedia = '<!-- wp:html --><style media="(max-width:1px)">.gone{display:none}</style>'
        . '<label class="gone" for="media-phone">207 555</label>'
        . '<input id="media-phone" value="0199"><!-- /wp:html -->';
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub(
        $conditional . $styleMedia,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(2, count($warnings));
});

test('contact grounding respects all layers and cyclic custom-property fallback', function () {
    $safe = [
        '<style>.gone{display:none;all:initial}</style>',
        '<style>.gone{display:none;all:in\69tial}</style>',
        '<style>.gone{display:block}@layer low{.gone{display:none}}</style>',
        '<style>.gone{--a:var(--b,none);--b:var(--a);display:var(--a,block)}</style>',
        '<style>.gone{display:env(safe-area-inset-top,none)}</style>',
        '<style>.gone{display:env(safe-area-max-inset-top,none)}</style>',
        '<style>.gone{display:env(preferred-text-scale,none)}</style>',
    ];
    foreach ($safe as $style) {
        $markup = '<!-- wp:html -->' . $style
            . '<label class="gone" for="order">Order ID</label>'
            . '<input id="order" type="number" value="2125550199"><!-- /wp:html -->';
        $warnings = [];
        assert_eq($markup, GroundedContactMarkup::scrub(
            $markup,
            [],
            'theme/parts/test.html',
            $warnings,
        ), $style);
        assert_eq([], $warnings, $style);
    }

    foreach ([
        ['<number>', '1'],
        ['<length>', 'calc(1px + 1px)'],
    ] as [$type, $value]) {
        $markup = '<!-- wp:html --><style>.gone{display:attr(data-show type('
            . $type . '),none)}</style><label class="gone" data-show="' . $value
            . '" for="order">Order ID</label><input id="order" type="number"'
            . ' value="2125550199"><!-- /wp:html -->';
        $warnings = [];
        assert_eq($markup, GroundedContactMarkup::scrub(
            $markup,
            [],
            'theme/parts/test.html',
            $warnings,
        ), $type);
        assert_eq([], $warnings, $type);
    }
});

test('contact grounding resolves computed visibility sources and condition overflow', function () {
    $unsafe = [
        '<style>.gone{display:env(msb-missing,none)}</style>',
        '<style>.gone{display:env(titlebar-area-width,none)}</style>',
        '<style>.gone{display:env(viewport-segment-width,none)}</style>',
        '<style>.gone{display:env(SAFE-AREA-INSET-TOP,none)}</style>',
        '<style>.gone{display:env(safe-area-inset-top 0,none)}</style>',
        '<style>.gone{display:attr(data-app type(<custom-ident>),none)}</style>',
        '<style>@property --show{syntax:"<custom-ident>";inherits:false;initial-value:none}'
            . '.p{--show:block}.gone{display:var(--show)}</style>',
        '<style>.gone{display:n\6f ne}</style>',
        '<style>.p{visibility:hidden}.gone{visibility:inherit}</style>',
        '<style>.p{visibility:hidden}.gone{all:unset}</style>',
        '<style>@layer low,high;@layer low{.gone{display:none}}'
            . '@layer high{.gone{display:revert-layer}}</style>',
        '<style>@media(min-width:1px){@property --show{syntax:"<custom-ident>";inherits:false;'
            . 'initial-value:none}}.p{--show:block}.gone{display:var(--show)}</style>',
        '<style>@property --show{syntax:"<custom-ident>";inherits:false;initial-value:none}'
            . '.p{--show:block}.gone{--show:revert;display:var(--show)}</style>',
        '<style>.p{visibility:hidden}.gone{visibility:revert}.p input{visibility:visible}</style>',
        '<style>@property --show{syntax:"<custom-ident>+";inherits:false;initial-value:none}'
            . '.gone{--show:1px;display:var(--show)}</style>',
    ];
    foreach ($unsafe as $index => $style) {
        $attribute = $index === 5 ? ' data-app="1px"' : '';
        $markup = '<!-- wp:html -->' . $style . '<div class="p">'
            . '<label class="gone"' . $attribute . ' for="order">Order ID</label>'
            . '<input id="order" type="number" value="2125550199"></div><!-- /wp:html -->';
        $warnings = [];
        assert_eq('', GroundedContactMarkup::scrub(
            $markup,
            [],
            'theme/parts/test.html',
            $warnings,
        ), $style);
        assert_eq(1, count($warnings), $style);
    }

    $overflowCss = '.gone{display:none}@media(min-width:500px){.gone{display:block}}';
    for ($width = 800; $width <= 815; $width++) {
        $overflowCss .= '@media(min-width:' . $width . 'px){.gone{display:none}}';
    }
    $overflow = '<!-- wp:html --><style>' . $overflowCss . '</style>'
        . '<label class="gone" for="phone">207 555</label>'
        . '<input id="phone" value="0199"><!-- /wp:html -->';
    $warnings = [];
    assert_eq('', GroundedContactMarkup::scrub(
        $overflow,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(1, count($warnings));

    $conditionalRegistration = '<!-- wp:html --><style>'
        . '@media(max-width:1px){@property --show{syntax:"<custom-ident>";inherits:false;'
        . 'initial-value:block}}.p{--show:none}.gone{display:var(--show)}</style>'
        . '<div class="p"><label class="gone" for="order">Order ID</label>'
        . '<input id="order" type="number" value="2125550199"></div><!-- /wp:html -->';
    $warnings = [];
    assert_eq('', GroundedContactMarkup::scrub(
        $conditionalRegistration,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(1, count($warnings));

    $universalWithoutInitial = '<!-- wp:html --><style>'
        . '@property --show{syntax:"*";inherits:false}.p{--show:none}'
        . '.gone{display:var(--show,block)}</style>'
        . '<div class="p"><label class="gone" for="order">Order ID</label>'
        . '<input id="order" type="number" value="2125550199"></div><!-- /wp:html -->';
    $warnings = [];
    assert_eq($universalWithoutInitial, GroundedContactMarkup::scrub(
        $universalWithoutInitial,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq([], $warnings);

    $supportsOverflowCss = '.gone{display:none}'
        . '@supports(display:inline){.gone{display:block}}';
    foreach (['block', 'flex', 'grid', 'flow-root', 'inline-block', 'inline-flex',
        'inline-grid', 'list-item', 'table', 'table-cell', 'table-row', 'contents'] as $display
    ) {
        $supportsOverflowCss .= '@supports not (display:' . $display . '){.gone{display:none}}';
    }
    $supportsOverflow = '<!-- wp:html --><style>' . $supportsOverflowCss . '</style>'
        . '<label class="gone" for="phone">207 555</label>'
        . '<input id="phone" value="0199"><!-- /wp:html -->';
    $warnings = [];
    assert_eq('', GroundedContactMarkup::scrub(
        $supportsOverflow,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(1, count($warnings));
});

test('contact grounding does not invent impossible nested-condition states', function () {
    $markup = '<!-- wp:html --><style>.gone{display:block}'
        . '@media(min-width:500px){@media(max-width:700px){.gone{display:none}}'
        . '.gone{display:block}}</style>'
        . '<label class="gone" for="order">Order ID</label>'
        . '<input id="order" type="number" value="2125550199"><!-- /wp:html -->';
    $warnings = [];

    assert_eq($markup, GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq([], $warnings);
});

test('contact grounding composes button and output values with their labels', function () {
    $markup = '<!-- wp:html --><label for="button-phone">207 555</label>'
        . '<input id="button-phone" type="button" value="0199"><!-- /wp:html -->'
        . '<!-- wp:html --><label for="output-phone">207 555</label>'
        . '<output id="output-phone">0199</output><!-- /wp:html -->';
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(2, count($warnings));
});

test('contact grounding ignores submission and masked values that browsers do not publish', function () {
    $markup = '<!-- wp:html --><select><option value="fake@example.com">Safe choice</option></select>'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><input type="password" value="fake@example.com"><!-- /wp:html -->';
    $warnings = [];

    assert_eq($markup, GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq([], $warnings);
});

test('contact grounding follows browser number-value sanitization', function () {
    $markup = '<!-- wp:html --><label>Phone <input type="number" value="٢١٢٥٥٥٠١٩٩"></label>'
        . '<!-- /wp:html -->'
        . '<!-- wp:html --><label>Phone <input type="number" value="２１２５５５０１９９"></label>'
        . '<!-- /wp:html -->';
    $warnings = [];

    assert_eq($markup, GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq([], $warnings);
});

test('contact grounding composes XHTML form values rendered by data SVGs', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject>'
        . '<div xmlns="http://www.w3.org/1999/xhtml"><label for="x">207 555</label>'
        . '<input id="x" value="0199" /></div></foreignObject></svg>';
    $markup = '<!-- wp:html --><img alt="Safe" src="data:image/svg+xml;base64,'
        . base64_encode($svg) . '"><!-- /wp:html -->';
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(1, count($warnings));
    assert_contains('207 555 0199', $warnings[0]);
});

test('contact grounding applies outer SVG styles to foreignObject form labels', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.gone{display:none}</style><foreignObject>'
        . '<div xmlns="http://www.w3.org/1999/xhtml"><label class="gone" for="o">Order ID</label>'
        . '<input id="o" type="number" value="2125550199" /></div></foreignObject></svg>';
    $markup = '<!-- wp:html --><img alt="Safe" src="data:image/svg+xml;base64,'
        . base64_encode($svg) . '"><!-- /wp:html -->';
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub($markup, [], 'theme/parts/test.html', $warnings));
    assert_eq(1, count($warnings));

    $conditionalSvg = '<svg xmlns="http://www.w3.org/2000/svg">'
        . '<style media="(max-width:1px)">.gone{display:none}</style><foreignObject>'
        . '<div xmlns="http://www.w3.org/1999/xhtml"><label class="gone" for="p">207 555</label>'
        . '<input id="p" value="0199" /></div></foreignObject></svg>';
    $conditionalMarkup = '<!-- wp:html --><img alt="Safe" src="data:image/svg+xml;base64,'
        . base64_encode($conditionalSvg) . '"><!-- /wp:html -->';
    $warnings = [];
    assert_eq('', GroundedContactMarkup::scrub(
        $conditionalMarkup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(1, count($warnings));
});

test('contact grounding applies visual-only form semantics inside rasterized data SVGs', function () {
    $payloads = [
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject>'
            . '<div xmlns="http://www.w3.org/1999/xhtml"><input aria-label="207 555" value="0199" /></div>'
            . '</foreignObject></svg>',
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject>'
            . '<div xmlns="http://www.w3.org/1999/xhtml"><template shadowrootmode="open">'
            . '<label>207 555<input value="0199" /></label></template><span>Safe</span></div>'
            . '</foreignObject></svg>',
    ];
    foreach ($payloads as $svg) {
        $markup = '<!-- wp:html --><img alt="Safe" src="data:image/svg+xml;base64,'
            . base64_encode($svg) . '"><!-- /wp:html -->';
        $warnings = [];
        assert_eq($markup, GroundedContactMarkup::scrub(
            $markup,
            [],
            'theme/parts/test.html',
            $warnings,
        ));
        assert_eq([], $warnings);
    }
});

test('contact grounding rejects CSS-generated phone fragments inside rasterized data SVGs', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject>'
        . '<div xmlns="http://www.w3.org/1999/xhtml"><style>'
        . '.x::before{content:"20755"}.a::before{content:"alpha"}.b::before{content:"beta"}'
        . '.c::before{content:"gamma"}.x::after{content:"50199"}'
        . 'header::before{content:"20755"}footer::before{content:"50199"}</style>'
        . '<label class="x"><input value="0199" /></label></div></foreignObject></svg>';
    $markup = '<!-- wp:html --><img alt="Safe" src="data:image/svg+xml;base64,'
        . base64_encode($svg) . '"><!-- /wp:html -->';
    $warnings = [];

    assert_eq('', GroundedContactMarkup::scrub(
        $markup,
        [],
        'theme/parts/test.html',
        $warnings,
    ));
    assert_eq(1, count($warnings));
});

test('contact grounding applies ARIA role tokens and presentation conflicts like a browser', function () {
    $unsafe = '<!-- wp:html --><img src="/safe.gif" role="presentation" tabindex="0" '
        . 'alt="fake@example.com"><!-- /wp:html -->'
        . '<!-- wp:html --><input type="image" src="/safe.gif" role="presentation" '
        . 'alt="fake2@example.com"><!-- /wp:html -->'
        . '<!-- wp:html --><img src="/safe.gif" role="presentation" tabindex=" 0 " '
        . 'alt="tab@example.com"><!-- /wp:html -->'
        . '<!-- wp:html --><img src="/safe.gif" role="image presentation" '
        . 'alt="image@example.com"><!-- /wp:html -->'
        . '<!-- wp:html --><img src="/safe.gif" role="time presentation" '
        . 'alt="time@example.com"><!-- /wp:html -->'
        . '<!-- wp:html --><img src="/safe.gif" role="comment presentation" '
        . 'alt="comment@example.com"><!-- /wp:html -->'
        . '<!-- wp:html --><img src="/safe.gif" role="suggestion presentation" '
        . 'alt="suggestion@example.com"><!-- /wp:html -->'
        . '<!-- wp:html --><img src="/safe.gif" role="presentation" aria-braillelabel="Safe" '
        . 'alt="braille@example.com"><!-- /wp:html -->'
        . '<!-- wp:html --><img src="/safe.gif" role="presentation" tabindex="0foo" '
        . 'alt="prefix@example.com"><!-- /wp:html -->'
        . '<!-- wp:html --><img src="/safe.gif" role="presentation" tabindex="0.0" '
        . 'alt="decimal@example.com"><!-- /wp:html -->';
    $safe = '<!-- wp:html --><img src="/safe.gif" role="presentation img" '
        . 'alt="fake3@example.com"><!-- /wp:html -->'
        . '<!-- wp:html --><img src="/safe.gif" role="presentation" '
        . 'tabindex="999999999999999999999" alt="overflow@example.com"><!-- /wp:html -->';
    $warnings = [];

    $delivered = GroundedContactMarkup::scrub(
        $unsafe . $safe,
        [],
        'theme/parts/test.html',
        $warnings,
    );

    assert_eq('', $delivered);
    assert_eq(12, count($warnings));

    $missingRole = '<!-- wp:html --><img src="/safe.gif" role="graphics-document presentation" '
        . 'alt="graphic@example.com"><!-- /wp:html -->';
    $invalidConflicts = '<!-- wp:html --><img src="/safe.gif" role="presentation" tabindex="nope" '
        . 'alt="safe@example.com"><!-- /wp:html -->'
        . '<!-- wp:html --><img src="/safe.gif" role="presentation" aria-checked="false" '
        . 'alt="safe2@example.com"><!-- /wp:html -->';
    $warnings = [];
    assert_eq(
        '',
        GroundedContactMarkup::scrub(
            $missingRole . $invalidConflicts,
            [],
            'theme/parts/test.html',
            $warnings,
        ),
    );
    assert_eq(3, count($warnings));
});

test('contact grounding ignores inert area alt but scans an active image map', function () {
    $inert = '<!-- wp:html --><p>Keep this sibling</p><area href="#safe" role="presentation" '
        . 'alt="area@example.com"><!-- /wp:html -->';
    $carrier = '<!-- wp:html --><img src="/safe.gif" usemap="#links" alt="Safe"><!-- /wp:html -->';
    $active = '<!-- wp:html --><map name="links"><area href="#safe" '
        . 'alt="active@example.com"></map><!-- /wp:html -->';
    $idCarrier = '<!-- wp:html --><img src="/safe.gif" usemap="#id-links" alt="Safe">'
        . '<map id="id-links"><area href="#safe" alt="id@example.com"></map><!-- /wp:html -->';
    $firstMapWins = '<!-- wp:html --><img src="/safe.gif" usemap="#duplicate" alt="Safe">'
        . '<map name="duplicate"><area href="#safe" alt="Safe"></map>'
        . '<map name="duplicate"><area href="#safe" alt="duplicate@example.com"></map><!-- /wp:html -->';
    $warnings = [];

    $delivered = GroundedContactMarkup::scrub(
        $inert . $carrier . $active . $idCarrier . $firstMapWins,
        [],
        'theme/parts/test.html',
        $warnings,
    );

    assert_eq($inert . $carrier . $firstMapWins, $delivered);
    assert_eq(2, count($warnings));
    assert_contains('active@example.com', implode("\n", $warnings));
    assert_contains('id@example.com', implode("\n", $warnings));
});

test('contact grounding resolves image maps in document and tree-scope order', function () {
    $carrier = '<!-- wp:html --><img src="/safe.gif" usemap="#ordered" alt="Safe"><!-- /wp:html -->';
    $inertDuplicate = '<!-- wp:html --><template><map name="ordered"><area href="#safe" '
        . 'alt="Safe"></map></template><!-- /wp:html -->';
    $active = '<!-- wp:html --><map name="ordered"><area href="#safe" '
        . 'alt="ordered@example.com"></map><!-- /wp:html -->';
    $whitespace = '<!-- wp:html --><img src="/safe.gif" usemap=" #spaced" alt="Safe">'
        . '<img src="/safe.gif" usemap="#spaced " alt="Safe">'
        . '<map name="spaced"><area href="#safe" alt="spaced@example.com"></map><!-- /wp:html -->';
    $outerCarrier = '<!-- wp:html --><img src="/safe.gif" usemap="#shadowed" alt="Safe">'
        . '<div><template shadowrootmode="open"><map name="shadowed"><area href="#safe" '
        . 'alt="shadowed@example.com"></map></template></div><!-- /wp:html -->';
    $shadowActive = '<!-- wp:html --><div><template shadowrootmode="open">'
        . '<img src="/safe.gif" usemap="#inner" alt="Safe"><map name="inner"><area href="#safe" '
        . 'alt="inner@example.com"></map></template></div><!-- /wp:html -->';
    $inertMap = '<!-- wp:html --><p>Keep inert sibling</p><img src="/safe.gif" usemap="#inert-links" '
        . 'alt="Safe"><div inert><map name="inert-links"><area href="#safe" '
        . 'alt="inert@example.com"></map></div><!-- /wp:html -->';
    $ariaHiddenMap = '<!-- wp:html --><img src="/safe.gif" usemap="#aria-hidden-links" alt="Safe">'
        . '<div aria-hidden="true"><map name="aria-hidden-links"><area href="#safe" '
        . 'alt="aria-hidden-map@example.com"></map></div><!-- /wp:html -->';
    $inertFirstWins = '<!-- wp:html --><img src="/safe.gif" usemap="#claimed" alt="Safe">'
        . '<div inert><map name="claimed"><area href="#safe" alt="Safe"></map></div>'
        . '<map name="claimed"><area href="#safe" alt="unused@example.com"></map><!-- /wp:html -->';
    $warnings = [];

    $delivered = GroundedContactMarkup::scrub(
        $carrier . $inertDuplicate . $active . $whitespace . $outerCarrier . $shadowActive . $inertMap
            . $ariaHiddenMap . $inertFirstWins,
        [],
        'theme/parts/test.html',
        $warnings,
    );

    assert_eq(
        $carrier . $inertDuplicate . $whitespace . $outerCarrier . $inertMap . $inertFirstWins,
        $delivered,
    );
    assert_eq(3, count($warnings));
    assert_contains('ordered@example.com', implode("\n", $warnings));
    assert_contains('inner@example.com', implode("\n", $warnings));
    assert_contains('aria-hidden-map@example.com', implode("\n", $warnings));

    $orphan = '<map name="early"><area href="#safe" alt="orphan@example.com"></map>';
    $late = '<!-- wp:html --><img src="/safe.gif" usemap="#early" alt="Safe">'
        . '<map name="early"><area href="#safe" alt="Safe"></map><!-- /wp:html -->';
    $orphanWarnings = [];
    assert_eq('', GroundedContactMarkup::scrub(
        $orphan . $late,
        [],
        'theme/parts/test.html',
        $orphanWarnings,
    ));
    assert_eq(1, count($orphanWarnings));
    assert_contains('orphan@example.com', $orphanWarnings[0]);
});

test('contact grounding scans area names only through an active image map', function () {
    $standalone = '<!-- wp:html --><p>Keep standalone</p><area href="#safe" '
        . 'aria-label="standalone@example.com"><!-- /wp:html -->';
    $unreferenced = '<!-- wp:html --><p>Keep unreferenced</p><map name="unused"><area href="#safe" '
        . 'aria-label="unused@example.com"></map><!-- /wp:html -->';
    $active = '<!-- wp:html --><img src="/safe.gif" usemap="#named" alt="Safe">'
        . '<map name="named"><area href="#safe" aria-label="active-area@example.com"></map><!-- /wp:html -->';
    $emptyAriaLabel = '<!-- wp:html --><img src="/safe.gif" usemap="#empty-aria" alt="Safe">'
        . '<map name="empty-aria"><area href="#safe" aria-label="" '
        . 'alt="empty-label@example.com"></map><!-- /wp:html -->';
    $emptyLabelledBy = '<!-- wp:html --><img src="/safe.gif" usemap="#empty-ref" alt="Safe">'
        . '<map name="empty-ref"><area href="#safe" aria-labelledby="empty-target" '
        . 'aria-label="fallback@example.com"></map><span id="empty-target"></span><!-- /wp:html -->';
    $titleDescription = '<!-- wp:html --><img src="/safe.gif" usemap="#title-description" alt="Safe">'
        . '<map name="title-description"><area href="#safe" alt="Safe" '
        . 'title="area-title@example.com"></map><!-- /wp:html -->';
    $warnings = [];

    $delivered = GroundedContactMarkup::scrub(
        $standalone . $unreferenced . $active . $emptyAriaLabel . $emptyLabelledBy . $titleDescription,
        [],
        'theme/parts/test.html',
        $warnings,
    );

    assert_eq($standalone . $unreferenced, $delivered);
    assert_eq(4, count($warnings));
    assert_contains('active-area@example.com', $warnings[0]);
    assert_contains('empty-label@example.com', implode("\n", $warnings));
    assert_contains('fallback@example.com', implode("\n", $warnings));
    assert_contains('area-title@example.com', implode("\n", $warnings));
});

test('contact grounding recomputes map exposure after intrinsic block removal', function () {
    $carrier = '<!-- wp:html --><img src="/safe.gif" usemap="#stale" '
        . 'alt="one@example.com"><!-- /wp:html -->';
    $map = '<!-- wp:html --><p>Keep map sibling</p><map name="stale"><area href="#safe" '
        . 'alt="two@example.com"></map><!-- /wp:html -->';
    $warnings = [];

    $delivered = GroundedContactMarkup::scrub(
        $carrier . $map,
        [],
        'theme/parts/test.html',
        $warnings,
    );

    assert_eq($map, $delivered);
    assert_eq(1, count($warnings));
    assert_contains('one@example.com', $warnings[0]);
});

test('SectionUnit grounds inline CSS text and unresolved resource indirection', function () {
    $markup = '<!-- wp:html --><ul style="list-style-type:&quot;fake@example.com&quot;">'
        . '<li>Safe</li></ul><!-- /wp:html -->'
        . '<!-- wp:html --><div style="--u:&quot;https://invented.example/p.png&quot;;'
        . 'background-image:image-set(var(--u) 1x)">Safe</div><!-- /wp:html -->'
        . '<!-- wp:html --><svg style="--u:&quot;https://invented.example/f.svg#x&quot;">'
        . '<switch filter="var(--u)"><rect width="20" height="20"></rect></switch></svg><!-- /wp:html -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(3, count($result->warnings));
});

test('SectionUnit keeps inert MathML svg and invalid shadow modes byte-identical', function () {
    $markup = '<!-- wp:html --><math><mrow><svg><image fill="url(https://invented.example/paint.svg#x)">'
        . '</image></svg></mrow></math><p>Keep math</p><!-- /wp:html -->'
        . '<!-- wp:html --><div><template shadowrootmode=" open ">fake@example.com</template></div>'
        . '<p>Keep template</p><!-- /wp:html -->'
        . '<!-- wp:html --><div><template shadowrootmode="open"><span>Safe</span></template>'
        . '<template shadowrootmode="open"><span>fake@example.com</span></template></div><!-- /wp:html -->'
        . '<!-- wp:html --><math><annotation-xml encoding=" text/html "><form '
        . 'action="https://invented.example/post"><button>Safe</button></form>'
        . '</annotation-xml></math><!-- /wp:html -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, section_unit_input());

    assert_eq($markup, $result->markup);
    assert_eq([], $result->warnings);
});

test('contact grounding preserves a data SVG with a nonterminal base64 parameter', function () {
    $markup = '<!-- wp:html --><img src="data:image/svg+xml;base64;foo,'
        . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><text>fake@example.com</text></svg>')
        . '"><p>Keep invalid data</p><!-- /wp:html -->';
    $warnings = [];

    $delivered = GroundedContactMarkup::scrub($markup, [], 'theme/parts/test.html', $warnings);

    assert_eq($markup, $delivered);
    assert_eq([], $warnings);
});

test('contact grounding ignores invalid and nonpainted SVG data text', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="20" height="20" fill="red">'
        . '</rect>%s</svg>';
    $verticalTab = base64_encode(sprintf($svg, '<text>fake@example.com</text>'));
    $verticalTab = substr($verticalTab, 0, 4) . "\x0B" . substr($verticalTab, 4);
    $payloads = [
        $verticalTab,
        base64_encode(sprintf($svg, '<text>fake&#64example.com</text>')),
        base64_encode(sprintf($svg, '<text>fake&nbsp;@example.com</text>')),
        base64_encode(sprintf($svg, '<title>fake@example.com</title>')),
        base64_encode(sprintf($svg, '<desc>fake@example.com</desc>')),
        base64_encode(sprintf($svg, '<metadata>fake@example.com</metadata>')),
        base64_encode(sprintf(
            '<!DOCTYPE svg [<!ENTITY e "SYSTEM READY">]>' . $svg,
            '<text>&e;</text>',
        )),
    ];
    foreach ($payloads as $payload) {
        $markup = '<!-- wp:html --><img alt="Safe icon" src="data:image/svg+xml;base64,'
            . $payload . '"><!-- /wp:html -->';
        $warnings = [];
        assert_eq(
            $markup,
            GroundedContactMarkup::scrub($markup, [], 'theme/parts/test.html', $warnings),
        );
        assert_eq([], $warnings);
    }

    $fragment = '<!-- wp:html --><img alt="Safe icon" src="data:image/svg+xml;base64,'
        . base64_encode(sprintf($svg, '')) . '#fake@example.com"><!-- /wp:html -->';
    $warnings = [];
    assert_eq($fragment, GroundedContactMarkup::scrub($fragment, [], 'theme/parts/test.html', $warnings));
    assert_eq([], $warnings);
});

test('contact grounding never resolves external SVG entities across encodings', function () {
    with_temp_dir('builder_svg_external_entity_', function (string $dir): void {
        $external = $dir . '/contact.txt';
        file_put_contents($external, 'fake@example.com');
        $xml = '<!DOCTYPE svg [<!ENTITY e SYSTEM "file://' . $external . '">]>'
            . '<svg xmlns="http://www.w3.org/2000/svg"><text>&e;</text></svg>';
        $payloads = [
            $xml,
            "\xFE\xFF" . mb_convert_encoding($xml, 'UTF-16BE', 'UTF-8'),
            "\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'),
            mb_convert_encoding($xml, 'UTF-16BE', 'UTF-8'),
            mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'),
            "\x00\x00\xFE\xFF" . mb_convert_encoding($xml, 'UTF-32BE', 'UTF-8'),
            "\xFF\xFE\x00\x00" . mb_convert_encoding($xml, 'UTF-32LE', 'UTF-8'),
        ];
        foreach ($payloads as $payload) {
            $candidates = GroundedContactMarkup::svgDataCandidates(
                'data:image/svg+xml;base64,' . base64_encode($payload),
            );
            assert_true(
                !in_array('fake@example.com', array_column($candidates, 'canonical'), true),
                'the parser never reads an external entity into candidate text',
            );
            assert_contains('external-entity', (string) ($candidates[0]['authored'] ?? ''));
        }
    });
});

test('SectionUnit catches domains beside unrelated image prose and default-ignorable contact separators', function () {
    $parts = [
        '<!-- wp:paragraph --><p>Website: invented.example; browse our image gallery.</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>Visit invented.example for details about our image gallery.</p>'
            . '<!-- /wp:paragraph -->',
        "<!-- wp:paragraph --><p>Call +1\u{200B}212\u{200B}555\u{200B}0199</p><!-- /wp:paragraph -->",
        "<!-- wp:paragraph --><p>Call +1\u{2060}212\u{2060}555\u{2060}0199</p><!-- /wp:paragraph -->",
        "<!-- wp:paragraph --><p>Call +1\u{E007F}212\u{E007F}555\u{E007F}0199</p><!-- /wp:paragraph -->",
        "<!-- wp:paragraph --><p>Call +1\u{1BCA0}212\u{1BCA0}555\u{1BCA0}0199</p><!-- /wp:paragraph -->",
        "<!-- wp:paragraph --><p>Call +1\u{1D173}212\u{1D173}555\u{1D173}0199</p><!-- /wp:paragraph -->",
        "<!-- wp:paragraph --><p>Call +1\u{FFF0}212\u{FFF0}555\u{FFF0}0199</p><!-- /wp:paragraph -->",
        "<!-- wp:paragraph --><p>Call +1\u{E0000}212\u{E0000}555\u{E0000}0199</p><!-- /wp:paragraph -->",
        "<!-- wp:paragraph --><p>Call +1\u{E0002}212\u{E0002}555\u{E0002}0199</p><!-- /wp:paragraph -->",
        "<!-- wp:paragraph --><p>Call +1\u{E0080}212\u{E0080}555\u{E0080}0199</p><!-- /wp:paragraph -->",
        "<!-- wp:paragraph --><p>Call +1\u{E01F0}212\u{E01F0}555\u{E01F0}0199</p><!-- /wp:paragraph -->",
        "<!-- wp:paragraph --><p>Visit 1\u{200B} Infinite Loop</p><!-- /wp:paragraph -->",
        "<!-- wp:paragraph --><p>Visit Via Roma\u{200B} 10</p><!-- /wp:paragraph -->",
    ];
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(implode('', $parts), section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(14, count($result->warnings));
});

test('SectionUnit compares email URL and opaque URI punctuation exactly', function () {
    $input = section_unit_input();
    $input['site_spec'] = [
        'email' => 'first-last@example.com',
        'website' => 'https://trusted.example/trusted-path?id=abc',
        'chat' => 'skype:first-last?call',
    ];
    $nonBreakingHyphen = "\u{2011}";
    $parts = [
        '<!-- wp:paragraph --><p><a href="mailto:first' . $nonBreakingHyphen
            . 'last@example.com">Email</a></p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><a href="https://trusted.example/trusted' . $nonBreakingHyphen
            . 'path?id=abc">Visit</a></p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><a href="skype:first' . $nonBreakingHyphen
            . 'last?call">Chat</a></p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><a href="https://trusted.example/trusted-path?id=abc!">Visit</a></p>'
            . '<!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><a href="https://trusted.example/trusted-path?id=abc)">Visit</a></p>'
            . '<!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><a href="https://trusted.example/trusted-path?id=abc]">Visit</a></p>'
            . '<!-- /wp:paragraph -->',
    ];
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(implode('', $parts), $input);

    assert_eq('', $result->markup);
    assert_eq(6, count($result->warnings));
});

test('SectionUnit does not fold endpoint digits across numeral systems', function () {
    $input = section_unit_input();
    $input['site_spec'] = [
        'email' => 'user123@example.com',
        'website' => 'https://example.com/order/123',
        'chat' => 'skype:user123',
    ];
    $parts = [
        '<!-- wp:paragraph --><p><a href="mailto:user١٢٣@example.com">Email</a></p>'
            . '<!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><a href="https://example.com/order/١٢٣">Visit</a></p>'
            . '<!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><a href="skype:user١٢٣">Chat</a></p><!-- /wp:paragraph -->',
    ];
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(implode('', $parts), $input);

    assert_eq('', $result->markup);
    assert_eq(3, count($result->warnings));
});

test('SectionUnit grounds SIP phone identities and removes invented ones', function () {
    $sip = '<!-- wp:paragraph --><p><a href="sip:+12075550199">Call</a></p><!-- /wp:paragraph -->';
    $sips = '<!-- wp:paragraph --><p><a href="sips:+12075550199">Call</a></p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($sip . $sips, section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(2, count($result->warnings));
});

test('SectionUnit preserves directly stated endpoint punctuation and rejects URL suffixes', function () {
    $input = section_unit_input();
    $input['site_spec'] = [
        'website' => 'https://trusted.example/path?id=abc!',
        'chat' => 'skype:trusted!',
        'sip' => 'sip:+12075550199!',
    ];
    $grounded = '<!-- wp:paragraph --><p><a href="https://trusted.example/path?id=abc!">Visit</a></p>'
        . '<!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p><a href="skype:trusted!">Chat</a></p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p><a href="sip:+12075550199!">Call</a></p><!-- /wp:paragraph -->';
    $suffix = '<!-- wp:paragraph --><p><a href="https://trusted.example/path?id=abc! extra">Visit</a></p>'
        . '<!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p><a href="https://trusted.example/path?id=abc">Visit</a></p>'
        . '<!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p><a href="skype:trusted">Chat</a></p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p><a href="sip:+12075550199">Call</a></p><!-- /wp:paragraph -->';
    $srcset = '<!-- wp:image --><figure class="wp-block-image"><img src="/safe.jpg" '
        . 'srcset="https://trusted.example/path?id=abc! 2x"></figure><!-- /wp:image -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($grounded . $suffix . $srcset, $input);

    assert_eq($grounded . $srcset, $result->markup);
    assert_eq(4, count($result->warnings));
});

test('SectionUnit does not derive an email identity from malformed structured spec punctuation', function () {
    $input = section_unit_input();
    $input['site_spec'] = ['email' => 'user@example.com!'];
    $markup = '<!-- wp:paragraph --><p><a href="mailto:user@example.com">Email</a></p>'
        . '<!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, $input);

    assert_eq('', $result->markup);
    assert_eq(1, count($result->warnings));
});

test('SectionUnit scrubs contact facts from rendered form and search attributes', function () {
    $searchPhone = '<!-- wp:search {"label":"Search","showLabel":false,'
        . '"placeholder":"Call +1 207 555 0199","buttonText":"Search"} /-->';
    $searchAddress = '<!-- wp:search {"label":"Search","showLabel":false,'
        . '"placeholder":"24 Market Street","buttonText":"Search"} /-->';
    $numericSearch = '<!-- wp:search {"label":"Search","showLabel":false,'
        . '"placeholder":12075550199,"buttonText":"Search"} /-->';
    $rawInput = '<!-- wp:html --><input placeholder="Call +1 207 555 0188" value="">'
        . '<!-- /wp:html -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($searchPhone . $searchAddress . $numericSearch . $rawInput, section_unit_input());

    assert_eq('', $result->markup);
    assert_eq(4, count($result->warnings));
    $warnings = implode("\n", $result->warnings);
    assert_contains('+1 207 555 0199', $warnings);
    assert_contains('24 Market Street', $warnings);
    assert_contains('12075550199', $warnings);
    assert_contains('+1 207 555 0188', $warnings);
});

test('SectionUnit scans only tag-valid accessible attributes and first duplicate attributes', function () {
    $ariaDescription = '<!-- wp:html --><button aria-description="Email fake@example.com">Contact</button>'
        . '<!-- /wp:html -->';
    $ariaValue = '<!-- wp:html --><input type="range" aria-valuetext="Call +1 212 555 0199">'
        . '<!-- /wp:html -->';
    $ariaPlaceholder = '<!-- wp:html --><div aria-placeholder="Email another@example.com">Contact</div>'
        . '<!-- /wp:html -->';
    $optionLabel = '<!-- wp:html --><select><option label="Email option@example.com">Safe</option></select>'
        . '<!-- /wp:html -->';
    $optgroupLabel = '<!-- wp:html --><select><optgroup label="Call +1 207 555 0188"><option>Safe</option>'
        . '</optgroup></select><!-- /wp:html -->';
    $trackLabel = '<!-- wp:html --><video><track src="/safe.vtt" label="Email track@example.com"></video>'
        . '<!-- /wp:html -->';
    $inertAlt = '<!-- wp:html --><div alt="fake@example.com">Keep alt</div><!-- /wp:html -->';
    $hiddenValue = '<!-- wp:html --><input type="hidden" value="fake@example.com"><p>Keep hidden</p>'
        . '<!-- /wp:html -->';
    $globallyHidden = '<!-- wp:html --><input hidden value="fake@example.com"><p>Keep hidden attr</p>'
        . '<!-- /wp:html -->';
    $checkboxValue = '<!-- wp:html --><input type="checkbox" value="fake@example.com"><p>Keep checkbox</p>'
        . '<!-- /wp:html -->';
    $duplicateHref = '<!-- wp:html --><a href="/safe" href="https://invented.example">Keep link</a>'
        . '<!-- /wp:html -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(
        $ariaDescription . $ariaValue . $ariaPlaceholder . $optionLabel . $optgroupLabel . $trackLabel
            . $inertAlt . $hiddenValue
            . $globallyHidden . $checkboxValue . $duplicateHref,
        section_unit_input(),
    );

    assert_eq(
        $inertAlt . $hiddenValue . $checkboxValue . $duplicateHref,
        $result->markup,
    );
    assert_eq(7, count($result->warnings));
});

test('SectionUnit scans decoded block destination attributes', function () {
    $escapedTelephone = '<!-- wp:navigation-link {"label":"Call",'
        . '"url":"\\u0074\\u0065\\u006c\\u003a1234567890"} /-->';
    $escapedUrl = '<!-- wp:navigation-link {"label":"Visit",'
        . '"url":"\\u0068\\u0074\\u0074\\u0070\\u0073\\u003a\\u002f\\u002f192.0.2.1"} /-->';
    $escapedFeed = '<!-- wp:rss {"feedURL":"\\u0068\\u0074\\u0074\\u0070\\u0073\\u003a'
        . '\\u002f\\u002f192.0.2.1"} /-->';
    $escapedMedia = '<!-- wp:media-text {"mediaUrl":"\\u0068\\u0074\\u0074\\u0070\\u0073'
        . '\\u003a\\u002f\\u002f192.0.2.1"} /-->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish(
        $escapedTelephone . $escapedUrl . $escapedFeed . $escapedMedia,
        section_unit_input(),
    );

    assert_eq('', $result->markup);
    assert_eq(4, count($result->warnings));
    $warnings = implode("\n", $result->warnings);
    assert_contains('1234567890', $warnings);
    assert_contains('192.0.2.1', $warnings);
});

test('SectionUnit ignores inert nested metadata destinations', function () {
    $safe = '<!-- wp:paragraph {"metadata":{"url":"https://schema.org/Thing"}} -->'
        . '<p>Fresh bread.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"metadata":{"url":"\\u0068\\u0074\\u0074\\u0070\\u0073'
        . '\\u003a\\u002f\\u002fschema.org\\u002fThing"}} -->'
        . '<p>Fresh pastries.</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($safe, section_unit_input());

    assert_eq($safe, $result->markup);
    assert_eq([], $result->warnings);
});

test('SectionUnit permits a stated domain-shaped identity as visible copy only', function () {
    $input = section_unit_input();
    $input['site_spec'] = ['name' => 'Pets.com'];
    $name = '<!-- wp:paragraph --><p>Pets.com</p><!-- /wp:paragraph -->';
    $url = '<!-- wp:paragraph --><p><a href="https://pets.com">Visit</a></p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($name . $url, $input);

    assert_eq($name, $result->markup, 'the identity domain does not authorize a URL destination');
    assert_eq(1, count($result->warnings));
});

test('contact grounding fails closed on unisolatable non-block residue', function () {
    $warnings = [];
    $markup = 'orphan fake@example.com';

    $delivered = GroundedContactMarkup::scrub($markup, [], 'theme/parts/orphan.html', $warnings);

    assert_eq('', $delivered);
    assert_eq(1, count($warnings));
    assert_contains('outside a complete block', $warnings[0]);
    assert_contains('fake@example.com', $warnings[0]);
});

test('SectionUnit keeps a tel-only destination backed by a bare spec phone', function () {
    $input = section_unit_input();
    $input['site_spec'] = ['phone' => '2075550199'];
    $button = '<!-- wp:button {"url":"tel:2075550199"} -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" '
        . 'href="tel:2075550199">Call</a></div><!-- /wp:button -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($button, $input);

    assert_eq($button, $result->markup);
    assert_eq([], $result->warnings);
});

test('SectionUnit carries nested phone semantics to compact visible copy', function () {
    $input = section_unit_input();
    $input['site_spec'] = ['phone' => ['primary' => 12075550199]];
    $markup = '<!-- wp:paragraph --><p>12075550199</p><!-- /wp:paragraph -->';
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($markup, $input);

    assert_eq($markup, $result->markup);
    assert_eq([], $result->warnings);
});

test('SectionUnit deterministically normalizes list-thumb delivery', function () {
    $llm = new FakeLlm();
    $llm->queueText(
        '<!-- wp:columns {"className":"list-thumb-flush"} -->'
        . '<div class="wp-block-columns list-thumb-flush">'
        . '<!-- wp:column {"width":"18%"} --><div class="wp-block-column" style="flex-basis:18%">'
        . '<!-- wp:image {"className":"card-media-thumb"} -->'
        . '<figure class="wp-block-image card-media-thumb"><img src="thumb.jpg" alt=""/></figure>'
        . '<!-- /wp:image --></div><!-- /wp:column -->'
        . '<!-- wp:column {"width":"82%"} --><div class="wp-block-column" style="flex-basis:82%">'
        . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Item</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>One line.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:column -->'
        . '</div><!-- /wp:columns -->',
    );
    $unit = new SectionUnit($llm, new PromptRenderer(repo_path('prompts')));

    $result = $unit->generate(section_unit_input());

    assert_contains('"isStackedOnMobile":false', $result->markup);
    assert_contains('is-not-stacked-on-mobile', $result->markup);
    assert_contains('"blockGap":"var:preset|spacing|xs"', $result->markup);
    assert_true(in_array('list-thumb-row-normalized', array_column($result->repairs, 'code'), true));
    assert_eq([], $result->warnings);
});

test('SectionUnit rejects a response without block markup', function () {
    $llm = new FakeLlm();
    $llm->queueText('plain text');
    $unit = new SectionUnit($llm, new PromptRenderer(repo_path('prompts')));

    assert_throws(fn () => $unit->generate(section_unit_input()));
});

test('SectionUnit request preparation does not call the LLM', function () {
    $llm = new FakeLlm();
    $unit = new SectionUnit($llm, new PromptRenderer(repo_path('prompts')));
    $input = section_unit_input();
    $input['site_spec'] = ['name' => 'DECODED-SPEC-SENTINEL'];
    $input['theme_json'] = ['decoded-theme-sentinel' => true];

    $request = $unit->request($input);

    $prompt = section_unit_request_text($request);
    assert_contains('UNIT-TITLE-SENTINEL', $prompt);
    assert_contains('DECODED-SPEC-SENTINEL', $prompt);
    assert_contains('decoded-theme-sentinel', $prompt);
    assert_eq(0, $llm->completeCalls);
    assert_eq(0, $llm->completeBatchCalls);
});

test('SectionUnit teaches the attribute-light markup contract without sacrificing authored design data', function () {
    $request = (new SectionUnit(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->request(section_unit_input());
    $prompt = section_unit_request_text($request);

    foreach ([
        'ATTRIBUTE-LIGHT BLOCK SAVE MARKUP',
        'Omit serializer-generated wrapper classes and inline styles',
        'copy exactly those custom class tokens onto the block wrapper',
        'Preserve RichText inline markup and attributes exactly',
        'Keep source-critical HTML',
        'AI_IMAGE',
    ] as $required) {
        assert_contains($required, $prompt);
    }

    foreach ([
        '<h2 class="wp-block-heading has-heading-font-family has-primary-color has-text-color">',
        '<figure class="wp-block-image size-large"><img',
        '<div class="wp-block-cover alignfull" style="min-height:80vh">',
    ] as $redundantExample) {
        assert_true(
            !str_contains($prompt, $redundantExample),
            "section prompt must not teach redundant save markup: {$redundantExample}",
        );
    }
});

test('SectionUnit documents the nested flush-card body contract', function () {
    $request = (new SectionUnit(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->request(section_unit_input());
    $prompt = section_unit_request_text($request);

    assert_contains(
        'ONE inner `wp:group` with `"className":"card-body"`',
        $prompt,
        'flush cards give their padded text group the stable flex-body hook',
    );
    assert_contains(
        '`"className":"card-body overlap-up"`',
        $prompt,
        'overlap cards retain both the flex-body and overlap hooks',
    );
    assert_contains(
        'a nested `cta-bottom` can align with sibling cards',
        $prompt,
        'the placement requirement explains why the body hook is structural',
    );
    assert_contains(
        'put ALL of that content in ONE such wrapper and give it `"className":"card-body"` regardless of treatment',
        $prompt,
        'every optional nested card text wrapper receives the shared structural hook',
    );
    assert_contains(
        'REQUIRED for `flush` and `overlap`; it is OPTIONAL for `framed` and `borderless`',
        $prompt,
        'framed and borderless cards may stay flat but cannot create an unhooked nested body',
    );
    foreach (['flush', 'framed', 'overlap', 'borderless'] as $treatment) {
        assert_contains(
            '`card-style--' . $treatment . '`',
            $prompt,
            "the {$treatment} construction has one universal treatment marker",
        );
    }
    assert_contains(
        '`"className":"card-style--overlap card-flush"`',
        $prompt,
        'overlap cards carry the universal marker and flush behavior hook together',
    );
    assert_contains(
        'ONE uniform literal pixel value for all four padding sides',
        $prompt,
        'framed geometry remains deterministic enough for the delivery contract to verify',
    );
});

test('SectionUnit documents the complete list-thumb delivery contract', function () {
    $request = (new SectionUnit(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->request(section_unit_input());
    $prompt = section_unit_request_text($request);

    assert_contains(
        'One `wp:columns` per row with `"isStackedOnMobile":false`',
        $prompt,
        'the dense two-column row is kept horizontal at Core\'s mobile breakpoint',
    );
    assert_contains(
        '`isStackedOnMobile:false` is MANDATORY for BOTH flush and framed rows',
        $prompt,
    );
    assert_contains(
        '`"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}`',
        $prompt,
        'the text column owns a tight intra-row rhythm',
    );
    assert_contains('`"className":"list-thumb-flush"`', $prompt);
});

test('SectionUnit gives standalone requests the authoritative machine card style', function () {
    $input = section_unit_input();
    $input['design_direction'] = 'Direction prose with no card-treatment commitment.';
    $input['card_style'] = 'framed';

    $request = (new SectionUnit(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->request($input);
    $prompt = section_unit_request_text($request);

    assert_contains('ASSIGNED CARD STYLE (authoritative machine contract): framed', $prompt);
    assert_contains(
        'overrides absent or conflicting prose in the DESIGN DIRECTION',
        $prompt,
    );
    assert_true(
        !str_contains($prompt, 'when the direction carries no card-treatment line, default to `flush`'),
        'standalone non-flush input is not contradicted by a prose-derived default',
    );
    assert_contains(
        'ASSIGNED CARD STYLE (authoritative machine contract): framed',
        $request['cached_prefixes'][1] ?? '',
        'the site-wide machine assignment remains in the stable build cache layer',
    );
});

test('SectionUnit keeps front-page hero topology out of the general prompt', function () {
    $input = section_unit_input();
    $input['outline'] = '1. UNIT-OUTLINE-SENTINEL (content)';
    $input['section']['role'] = 'content';
    $input['section']['type'] = 'feature';
    $input['hero_blueprint'] = [
        'recipe' => 'typographic-poster',
        'media_mode' => 'SECTION-HERO-BLUEPRINT-LEAK-SENTINEL',
    ];
    $input['above_fold_contract'] = 'SECTION-ABOVE-FOLD-LEAK-SENTINEL';

    $request = (new SectionUnit(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->request($input);
    $prompt = section_unit_request_text($request);

    foreach ([
        'SECTION-HERO-BLUEPRINT-LEAK-SENTINEL',
        'SECTION-ABOVE-FOLD-LEAK-SENTINEL',
        'ASSIGNED HERO COMPOSITION',
        'NORMALIZED HERO BLUEPRINT',
        'hero-composition--',
        'Hero notes',
        'hero-entrance',
    ] as $heroOnly) {
        assert_true(!str_contains($prompt, $heroOnly), "general section prompt excludes {$heroOnly}");
    }
    assert_contains('UNIT-HEADER-CONTRACT-SENTINEL', $prompt, 'recipe-free opening relation remains supported');
});

test('SectionUnit layered request loses only cache marker separators', function () {
    $input = section_unit_input();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $request = (new SectionUnit(new FakeLlm(), $renderer))->request($input);

    $composition = $renderer->render('section-composition.md', [
        'layout_archetype' => $input['section']['layout_archetype'],
        'background'       => $input['section']['background'],
        'vertical_density' => $input['section']['vertical_density'],
        'handoff'          => $input['section']['handoff'],
        'neighbors'        => $input['neighbors'],
    ]);
    $rendered = $renderer->render('section.md', [
        'site_context'      => rtrim($renderer->render('site-context.md', [
            'site_spec'        => $input['site_spec'],
            'theme_json'       => $input['theme_json'],
            'design_direction' => $input['design_direction'],
        ]), "\r\n"),
        'language'          => $input['language'],
        'card_style'        => $input['card_style'],
        'outline'           => $input['outline'],
        'site_pages'        => $input['site_pages'],
        'page_title'        => $input['page']['title'],
        'page_path'         => $input['page']['path'],
        'section_title'     => $input['section']['title'],
        'section_slug'      => $input['section']['slug'],
        'section_role'      => $input['section']['role'],
        'section_type'      => $input['section']['type'],
        'section_purpose'   => $input['section']['purpose'],
        'content_notes'     => $input['section']['content_notes'],
        'composition'       => $composition,
        'header_contract'   => $input['header_contract'],
        'image_instructions' => $renderer->render('image-generation.md', []),
        'form_instructions'  => $renderer->render('no-forms.md', []),
        'block_markup_output_contract' => rtrim(
            $renderer->render('block-markup-output-contract.md', []),
            "\r\n",
        ),
    ]);
    $withoutMarkers = rtrim(ltrim(str_replace([
        "<!-- cache-layer:site -->\n",
        "<!-- cache-layer:build -->\n",
        "<!-- cache-layer:page -->\n",
        "<!-- cache-layer:brief -->\n",
    ], '', $rendered), "\r\n"), "\r\n");

    assert_eq($withoutMarkers, section_unit_request_text($request));
});

test('SectionUnit rejects malformed nested HTTP input before calling the LLM', function () {
    $llm = new FakeLlm();
    $unit = new SectionUnit($llm, new PromptRenderer(repo_path('prompts')));
    $input = section_unit_input();
    $input['section']['title'] = ['not', 'text'];

    assert_throws(fn () => $unit->generate($input));
    assert_eq(0, $llm->completeCalls);
});

test('SectionUnit requires an explicit section role before calling the LLM', function () {
    $llm = new FakeLlm();
    $unit = new SectionUnit($llm, new PromptRenderer(repo_path('prompts')));
    $input = section_unit_input();
    unset($input['section']['role']);

    $error = null;
    try {
        $unit->generate($input);
    } catch (InvalidArgumentException $e) {
        $error = $e;
    }

    assert_true($error instanceof InvalidArgumentException);
    assert_contains("unit input 'section.role' is required", $error->getMessage());
    assert_eq(0, $llm->completeCalls);
});

test('SectionUnit rejects malformed or unsupported section roles before calling the LLM', function () {
    foreach ([
        'non-string'  => [['hero'], "unit input 'section.role' must be a string"],
        'unsupported' => ['featured', "unit input 'section.role' must be one of: hero, content, closing"],
    ] as $case => [$role, $message]) {
        $llm = new FakeLlm();
        $unit = new SectionUnit($llm, new PromptRenderer(repo_path('prompts')));
        $input = section_unit_input();
        $input['section']['role'] = $role;

        $error = null;
        try {
            $unit->generate($input);
        } catch (InvalidArgumentException $e) {
            $error = $e;
        }

        assert_true($error instanceof InvalidArgumentException, "{$case} role should be rejected");
        assert_contains($message, $error->getMessage());
        assert_eq(0, $llm->completeCalls, "{$case} role should fail before calling the LLM");
    }
});
