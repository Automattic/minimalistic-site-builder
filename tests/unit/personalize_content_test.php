<?php
declare(strict_types=1);

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Patterns\NormalizeInputsStep;
use Automattic\SiteBuild\Patterns\PatternArtifacts;
use Automattic\SiteBuild\Patterns\PersonalizeContentStep;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\TextBatchResult;

/**
 * An Llm that answers each call from a queue and records every prompt, so a
 * test can assert both on what the model was shown and on how many times it
 * was asked.
 *
 * @param list<array<string, mixed>> $replies
 * @param list<string>               $prompts
 */
function personalize_llm(array $replies, ?array &$prompts = null): Llm
{
    $prompts = [];

    return new class($replies, $prompts) implements Llm {
        /** @param list<array<string, mixed>> $replies */
        public function __construct(private array $replies, private ?array &$prompts) {}
        public function complete(string $prompt, array $opts = []): string { return ''; }
        public function completeJson(string $prompt, array $opts = []): array
        {
            $this->prompts[] = $prompt;
            return array_shift($this->replies) ?? ['content' => []];
        }
        public function completeJsonBatch(array $requests): array { return []; }
        public function completeBatch(array $requests): TextBatchResult { throw new LogicException('unused'); }
    };
}

function personalize_section(string $className = ''): string
{
    $attrs = $className === '' ? '' : ' {"className":"' . $className . '"}';
    $class = $className === '' ? '' : ' ' . $className;

    return '<!-- wp:heading -->
<h2 class="wp-block-heading">Featured speakers</h2>
<!-- /wp:heading -->

<!-- wp:paragraph' . $attrs . ' -->
<p class="' . trim($class) . '">Sixty talks across five tracks.</p>
<!-- /wp:paragraph -->';
}

/**
 * @param array<string, mixed> $facts
 * @param list<array<string, mixed>> $pages
 */
function personalize_project(string $section = '', array $facts = [], array $pages = []): Project
{
    $dir = sys_get_temp_dir() . '/personalize-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o777, true);

    $pages = $pages ?: [[
        'slug' => 'speakers',
        'title' => 'Speakers',
        'front' => true,
        'menu_order' => 0,
        'description' => 'Feature the people on stage.',
    ]];

    $project = new Project($dir, basename($dir));
    $project->writeJson(PatternArtifacts::NORMALIZED, [
        'version' => NormalizeInputsStep::INPUTS_VERSION,
        'locale' => 'en',
        'facts' => $facts ?: ['intent' => 'Run a conference.'],
        'inventory' => [],
    ]);
    $project->writeJson(PatternArtifacts::PLAN, ['pages' => $pages]);
    $project->writeJson(PatternArtifacts::LAYOUTS, ['pages' => [[
        'slug' => (string) $pages[0]['slug'],
        'sections' => [['pattern' => 'theme/speakers', 'content' => $section ?: personalize_section()]],
    ]]]);

    return $project;
}

function personalize_step(Llm $llm): PersonalizeContentStep
{
    return new PersonalizeContentStep($llm, new PromptRenderer(Package::promptsDir()));
}

/** @param array<string, string> $textById */
function personalize_reply(array $textById): array
{
    $content = [];
    foreach ($textById as $id => $text) {
        $content[] = ['id' => $id, 'text' => $text];
    }

    return ['content' => $content];
}

function personalize_page(Project $project, string $slug = 'speakers'): array
{
    return $project->readJson(rtrim(PatternArtifacts::PAGES, '*') . $slug . '.json');
}

test('a page is written with the plan metadata and the copy asked for', function () {
    $project = personalize_project();
    $prompts = null;

    personalize_step(personalize_llm([personalize_reply([
        'speakers-1' => 'Who is speaking',
        'speakers-2' => 'Sixty sessions over three days in Berlin.',
    ])], $prompts))->run($project);

    $page = personalize_page($project);
    assert_eq('Speakers', $page['title']);
    assert_eq(true, $page['front']);
    assert_contains('<h2 class="wp-block-heading">Who is speaking</h2>', $page['content']);
    assert_contains('Sixty sessions over three days in Berlin.', $page['content']);
    assert_eq(1, count($prompts));
});

/**
 * The prompt carries the page's purpose from the plan, so the copy on a page
 * is written for what that page is for and not for the site in general.
 */
test('the model is shown what this page is for', function () {
    $project = personalize_project();
    $prompts = null;

    personalize_step(personalize_llm([personalize_reply(['speakers-1' => 'Who is speaking'])], $prompts))
        ->run($project);

    assert_contains('Feature the people on stage.', (string) $prompts[0]);
});

/**
 * Big Sky retries the items that came back missing or wrong, and the library's
 * generic JSON repair does not replace that: a reply that parsed cleanly and
 * broke the layout is not a JSON problem.
 */
test('copy that will not fit is asked for again, and only that copy', function () {
    $project = personalize_project();
    $prompts = null;

    personalize_step(personalize_llm([
        personalize_reply([
            'speakers-1' => 'A heading far longer than the two words this slot was drawn to hold',
            'speakers-2' => 'Sixty sessions over three days.',
        ]),
        personalize_reply(['speakers-1' => 'Who is speaking']),
    ], $prompts))->run($project);

    assert_eq(2, count($prompts));
    assert_contains('speakers-1', (string) $prompts[1]);
    assert_eq(false, str_contains((string) $prompts[1], 'speakers-2'), 'the retry asks only for what failed');
    assert_contains('Who is speaking', personalize_page($project)['content']);
});

/**
 * A second full generation may fail the same way for the same reason. Big Sky
 * only retries when part of the answer arrived, and so does this.
 */
test('an answer that failed entirely is not asked for a second time', function () {
    $project = personalize_project();
    $prompts = null;

    personalize_step(personalize_llm([['content' => []]], $prompts))->run($project);

    assert_eq(1, count($prompts));
    assert_contains('Featured speakers', personalize_page($project)['content']);
});

/**
 * Continuing with the pattern's own copy is the right call — an empty heading
 * is worse than a placeholder one. Reporting it is what stops a page of
 * placeholders from looking like a page that was written.
 */
test('slots that kept the pattern copy are reported, not swallowed', function () {
    $project = personalize_project();

    personalize_step(personalize_llm([personalize_reply(['speakers-1' => 'Who is speaking'])]))->run($project);

    $warnings = (string) json_encode($project->readJson('warnings.json'));
    assert_contains('kept the pattern', $warnings);
    assert_contains('speakers-2', $warnings);
    assert_contains('returned nothing for it', $warnings);
});

/**
 * A slot whose copy would not fit and one the model never mentioned both end
 * up keeping the placeholder, and they want opposite fixes: one is a prompt
 * that needs tightening, the other a pattern with no room in it.
 */
test('a slot that kept its copy says which way the answer failed', function () {
    $project = personalize_project();

    personalize_step(personalize_llm([
        personalize_reply([
            'speakers-1' => 'A heading far longer than the two words this slot was drawn to hold',
            'speakers-2' => 'Sixty sessions over three days.',
        ]),
        personalize_reply(['speakers-1' => 'Still far too long to fit inside this heading slot']),
    ]))->run($project);

    $warnings = (string) json_encode($project->readJson('warnings.json'));
    assert_contains('speakers-1', $warnings);
    assert_contains('room for 2', $warnings);
});

test('an id the model invented is ignored', function () {
    $project = personalize_project();

    personalize_step(personalize_llm([personalize_reply([
        'speakers-1' => 'Who is speaking',
        'speakers-99' => 'Copy for a slot that does not exist',
    ])]))->run($project);

    assert_eq(false, str_contains(personalize_page($project)['content'], 'does not exist'));
});

/**
 * A bound block is the site's own phone number, not the model's idea of one.
 * The number still reaches the prompt as site information — that is what makes
 * the rest of the copy fit the site — but the block holding it is not a slot,
 * so the model is never in a position to write over it.
 */
test('a block a binding owns is never offered to the model', function () {
    $project = personalize_project(personalize_section('ai-bind-phone'), [
        'intent' => 'Run a conference.',
        'phone' => '+34 600 111 222',
    ]);
    $prompts = null;

    personalize_step(personalize_llm([personalize_reply(['speakers-1' => 'Who is speaking'])], $prompts))
        ->run($project);

    assert_contains('Featured speakers', (string) $prompts[0], 'the heading is offered as a slot');
    assert_eq(
        false,
        str_contains((string) $prompts[0], 'Sixty talks across five tracks.'),
        'the bound block is not offered as a slot',
    );
    assert_contains('+34 600 111 222', personalize_page($project)['content']);
});

test('a heading that came back lowercase is capitalised', function () {
    $project = personalize_project();

    personalize_step(personalize_llm([personalize_reply(['speakers-1' => 'who is speaking'])]))->run($project);

    assert_contains('>Who is speaking<', personalize_page($project)['content']);
});

/**
 * A name owns its own lowercase first letter, and capitalising it is a typo
 * on the published page.
 */
test('a heading starting with a lowercase name is left as it came', function () {
    $project = personalize_project();

    personalize_step(personalize_llm([personalize_reply(['speakers-1' => 'iPhone repairs'])]))->run($project);

    assert_contains('>iPhone repairs<', personalize_page($project)['content']);
});

/**
 * The plan is where a page's title and order live. Guessing a title from the
 * slug publishes a page named after a URL.
 */
test('a composed page the plan does not know about stops the build', function () {
    $project = personalize_project();
    $project->writeJson(PatternArtifacts::PLAN, ['pages' => [['slug' => 'agenda', 'title' => 'Agenda']]]);

    $thrown = assert_throws(static fn () => personalize_step(personalize_llm([]))->run($project));

    assert_contains('speakers', $thrown->getMessage());
    assert_contains('agenda', $thrown->getMessage());
});

test('inputs from an older contract are refused rather than half-read', function () {
    $project = personalize_project();
    $project->writeJson(PatternArtifacts::NORMALIZED, ['version' => 0, 'facts' => []]);

    $thrown = assert_throws(static fn () => personalize_step(personalize_llm([]))->run($project));

    assert_contains('version', $thrown->getMessage());
});

/**
 * Observed against an inventory whose entries were empty markup: four pages
 * written, no model call, no warning, 0.00s, and a site that would go out
 * carrying whatever copy the patterns shipped with. The stage whose job is
 * writing content cannot write none and call it done.
 */
test('a build where no page had anything to write stops', function () {
    $project = personalize_project('<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->');

    $thrown = assert_throws(static fn () => personalize_step(personalize_llm([]))->run($project));

    assert_contains('No page offered a single piece of text', $thrown->getMessage());
});

/**
 * One such page among others is not a failure — a gallery strip holds no
 * copy — but it is the difference between a page that was written and a page
 * that was skipped, so it is said out loud.
 */
test('a single page with nothing to write is reported and the build goes on', function () {
    $project = personalize_project();
    $layouts = $project->readJson(PatternArtifacts::LAYOUTS);
    $layouts['pages'][] = ['slug' => 'gallery', 'sections' => [[
        'pattern' => 'theme/gallery',
        'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
    ]]];
    $project->writeJson(PatternArtifacts::LAYOUTS, $layouts);

    $plan = $project->readJson(PatternArtifacts::PLAN);
    $plan['pages'][] = ['slug' => 'gallery', 'title' => 'Gallery', 'front' => false, 'menu_order' => 10];
    $project->writeJson(PatternArtifacts::PLAN, $plan);

    personalize_step(personalize_llm([personalize_reply([
        'speakers-1' => 'Who is speaking',
        'speakers-2' => 'Sixty sessions over three days.',
    ])]))->run($project);

    assert_contains('gallery: no slot to write into', (string) json_encode($project->readJson('warnings.json')));
    assert_contains('Who is speaking', personalize_page($project)['content']);
});

function personalize_supplied_markup(): string
{
    return '<!-- wp:heading --><h2 class="wp-block-heading">Contact us</h2><!-- /wp:heading -->'
        . "\n" . '<!-- wp:paragraph --><p>Write to hello@example.com</p><!-- /wp:paragraph -->'
        . "\n" . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/old/">Book</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
}

/**
 * A supplied page in a project: the plan marks it supplied, the layout
 * carries its markup with blueprint provenance, and the normalized inputs
 * hold the slot declaration the host sent (null, a list, or []).
 *
 * @param list<array<string, mixed>>|null $slots
 * @param array<string, mixed>            $facts
 */
function personalize_supplied_project(?array $slots, array $facts = []): Project
{
    $project = personalize_project('', $facts, [[
        'slug' => 'contact',
        'title' => 'Contact',
        'front' => false,
        'menu_order' => 10,
        'description' => '',
        'supplied' => true,
    ]]);

    $inputs = $project->readJson(PatternArtifacts::NORMALIZED);
    $inputs['pages'] = [['slug' => 'contact', 'title' => 'Contact', 'markup' => personalize_supplied_markup(), 'slots' => $slots]];
    $project->writeJson(PatternArtifacts::NORMALIZED, $inputs);
    $project->writeJson(PatternArtifacts::LAYOUTS, ['pages' => [[
        'slug' => 'contact',
        'provenance' => 'blueprint',
        'sections' => [['pattern' => null, 'content' => personalize_supplied_markup()]],
    ]]]);

    return $project;
}

function personalize_declared_slots(): array
{
    return [
        ['id' => 'contact-heading', 'block_path' => [0], 'field' => 'text', 'fallback' => 'Contact us', 'instruction' => 'A warm heading', 'max_words' => 3],
        ['id' => 'contact-email', 'block_path' => [1], 'field' => 'text', 'fallback' => 'Write to hello@example.com', 'binding' => 'email'],
        ['id' => 'contact-url', 'block_path' => [2, 0], 'field' => 'url', 'fallback' => '/old/', 'binding' => 'contact_url'],
    ];
}

/**
 * Declared slots: the model is asked only for the slot with an instruction,
 * sees that instruction, never sees the bound ones, and the bound ones take
 * the facts' values. Every other byte of the page survives.
 */
test('declared slots change and nothing else does', function () {
    $project = personalize_supplied_project(personalize_declared_slots(), [
        'intent' => 'Book calls.',
        'email' => 'ola@northwind.example',
        'contact_url' => '/contact/',
    ]);
    $prompts = null;

    personalize_step(personalize_llm([personalize_reply(['contact-heading' => 'Vamos conversar'])], $prompts))->run($project);

    $page = personalize_page($project, 'contact');
    assert_contains('<h2 class="wp-block-heading">Vamos conversar</h2>', $page['content']);
    assert_contains('<p>ola@northwind.example</p>', $page['content']);
    assert_contains('href="/contact/"', $page['content']);
    assert_contains('class="wp-block-button__link wp-element-button"', $page['content']);
    assert_eq(1, count($prompts));
    assert_contains('A warm heading', (string) $prompts[0]);
    assert_eq(false, str_contains((string) $prompts[0], 'contact-email'), 'a bound slot is not offered to the model');
    assert_eq('blueprint', $page['provenance']);
    assert_eq(hash('sha256', personalize_supplied_markup()), $page['source_hash']);
});

/**
 * The author's limit is the author's. A declared three-word heading that
 * comes back as a sentence keeps the declared fallback, and the report says
 * which slot and why.
 */
test('a declared slot whose answer breaks its limit keeps the declared fallback', function () {
    $project = personalize_supplied_project(personalize_declared_slots(), ['email' => 'a@b.c', 'contact_url' => '/c/']);

    personalize_step(personalize_llm([
        personalize_reply(['contact-heading' => 'A heading that runs well past three words']),
        personalize_reply(['contact-heading' => 'Still far too long for the slot']),
    ]))->run($project);

    assert_contains('>Contact us<', personalize_page($project, 'contact')['content']);
    $warnings = (string) json_encode($project->readJson('warnings.json'));
    assert_contains('kept their fallback', $warnings);
    assert_contains('contact-heading', $warnings);
    assert_contains('room for 3', $warnings);
});

test('a bound slot whose fact is missing keeps its fallback and says so', function () {
    $project = personalize_supplied_project(personalize_declared_slots(), ['contact_url' => '/c/']);

    personalize_step(personalize_llm([personalize_reply(['contact-heading' => 'Say hello'])]))->run($project);

    assert_contains('>Write to hello@example.com<', personalize_page($project, 'contact')['content']);
    assert_contains('carry no email', (string) json_encode($project->readJson('warnings.json')));
});

/**
 * No `slots` key: the page is written the way a composed page is, every
 * text-bearing block a slot and ai-ignore the opt-out.
 */
test('a supplied page with no slot declaration has its slots discovered', function () {
    $project = personalize_supplied_project(null);
    $prompts = null;

    personalize_step(personalize_llm([personalize_reply([
        'contact-1' => 'Say hello',
        'contact-2' => 'Write to us any time.',
        'contact-3-1' => 'Book now',
    ])], $prompts))->run($project);

    $page = personalize_page($project, 'contact');
    assert_contains('>Say hello<', $page['content']);
    assert_contains('>Book now<', $page['content']);
    assert_contains('href="/old/"', $page['content']);
    assert_eq(1, count($prompts));
});

/**
 * `slots: []` is the host saying: this page is right as it is. No call, no
 * change, and the report says the page was frozen rather than silently
 * written as-is.
 */
test('a frozen page is written untouched with no model call', function () {
    $project = personalize_supplied_project([]);
    $prompts = null;

    personalize_step(personalize_llm([], $prompts))->run($project);

    $page = personalize_page($project, 'contact');
    assert_eq(personalize_supplied_markup(), $page['content']);
    assert_eq(true, $page['frozen']);
    assert_eq(0, count($prompts));
    assert_contains('contact: frozen by the host', (string) json_encode($project->readJson('warnings.json')));
});

/**
 * A site the host froze page by page has nothing to write, and that is what
 * was asked; the no-slot guard is for pages that were meant to be written.
 */
test('a site of only frozen pages is not a failure', function () {
    $project = personalize_supplied_project([]);

    personalize_step(personalize_llm([]))->run($project);

    assert_eq(true, $project->exists(rtrim(PatternArtifacts::PAGES, '*') . 'contact.json'));
});

/** The locale reaches the prompt as a language, not only as a code. */
test('the model is told which language to write in', function () {
    $project = personalize_project();
    $inputs = $project->readJson(PatternArtifacts::NORMALIZED);
    $inputs['locale'] = 'pt_BR';
    $project->writeJson(PatternArtifacts::NORMALIZED, $inputs);
    $prompts = null;

    personalize_step(personalize_llm([personalize_reply(['speakers-1' => 'Quem fala'])], $prompts))->run($project);

    assert_contains('Portuguese (Brazil) (pt_BR)', (string) $prompts[0]);
});

/**
 * A declared slot is one the author asked for by name. When the whole answer
 * came back empty it is still asked once more, and only then keeps its
 * fallback, named in the report.
 */
test('a declared slot the model skipped entirely is asked once more', function () {
    $project = personalize_supplied_project(personalize_declared_slots(), ['email' => 'a@b.c', 'contact_url' => '/c/']);
    $prompts = null;

    personalize_step(personalize_llm([['content' => []], ['content' => []]], $prompts))->run($project);

    assert_eq(2, count($prompts));
    assert_contains('>Contact us<', personalize_page($project, 'contact')['content']);
    assert_contains('contact-heading (the model returned nothing for it)', (string) json_encode($project->readJson('warnings.json')));
});

/** The hash a supplied page carries is of the markup exactly as the host sent it. */
test('a supplied page is hashed and written byte for byte', function () {
    $project = personalize_supplied_project([]);
    $padded = "\n\n" . personalize_supplied_markup() . "\n";
    $inputs = $project->readJson(PatternArtifacts::NORMALIZED);
    $inputs['pages'][0]['markup'] = $padded;
    $project->writeJson(PatternArtifacts::NORMALIZED, $inputs);
    $project->writeJson(PatternArtifacts::LAYOUTS, ['pages' => [[
        'slug' => 'contact', 'provenance' => 'blueprint', 'sections' => [['pattern' => null, 'content' => $padded]],
    ]]]);

    personalize_step(personalize_llm([]))->run($project);

    $page = personalize_page($project, 'contact');
    assert_eq($padded, $page['content']);
    assert_eq(hash('sha256', $padded), $page['source_hash']);
});
