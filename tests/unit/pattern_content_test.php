<?php
declare(strict_types=1);

use Automattic\SiteBuild\Patterns\ApprovedPattern;
use Automattic\SiteBuild\Patterns\ContentPersonalizer;
use Automattic\SiteBuild\Patterns\PatternInputs;
use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Pipeline;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\SiteBuilder;
use Automattic\SiteBuild\StepComposition;
use Automattic\SiteBuild\Tests\FakeLlm;

function pattern_proof_inputs(): array
{
    return json_decode(file_get_contents(__DIR__ . '/../fixtures/patterns/input.json'), true, flags: JSON_THROW_ON_ERROR);
}

function pattern_proof_response(): array
{
    return json_decode(file_get_contents(__DIR__ . '/../fixtures/patterns/responses.json'), true, flags: JSON_THROW_ON_ERROR)[0];
}

function pattern_proof_project(string $dir): Project
{
    $project = new Project($dir);
    $project->writeJson('meta.json', ['graph' => 'patterns']);
    $project->writeJson('pattern-inputs.json', pattern_proof_inputs());
    $project->writeText('media/studio.png', 'fixture-image-bytes');
    return $project;
}

test('pattern input schema example and runtime publish the same versioned contract', function () {
    $schema = json_decode(file_get_contents(Package::patternInputsSchemaPath()), true, flags: JSON_THROW_ON_ERROR);
    $example = json_decode(file_get_contents(Package::patternInputsExamplePath()), true, flags: JSON_THROW_ON_ERROR);
    assert_eq('https://json-schema.org/draft/2020-12/schema', $schema['$schema'] ?? null);
    assert_eq(['version', 'facts', 'brand', 'delivery', 'media', 'layouts'], $schema['required'] ?? null);
    assert_eq(1, $schema['properties']['version']['const'] ?? null);
    assert_eq($example, PatternInputs::validate($example));
    $bundleSchema = json_decode(file_get_contents(Package::contentBundleSchemaPath()), true, flags: JSON_THROW_ON_ERROR);
    assert_eq('wordpress-content', $bundleSchema['properties']['kind']['const'] ?? null);
    assert_eq(1, $bundleSchema['properties']['version']['const'] ?? null);
    assert_eq('wordpress-content', \Automattic\SiteBuild\Patterns\ContentBundle::build($example, [
        'input_hash' => PatternInputs::fingerprint($example),
        'layouts' => array_map(static fn (array $layout): array => [
            'id' => $layout['id'],
            'role' => $layout['role'],
            'markup' => $layout['markup'],
            'source_hash' => hash('sha256', $layout['markup']),
        ], $example['layouts']),
    ])['kind']);
});

test('pattern inputs reject undeclared structure and unsafe destination identities', function () {
    $input = pattern_proof_inputs();
    $input['layouts'][0]['style'] = ['color' => 'red'];
    assert_contains('undeclared field', assert_throws(fn () => PatternInputs::validate($input))->getMessage());

    $input = pattern_proof_inputs();
    $input['layouts'][0]['page']['slug'] = '../home';
    assert_contains('URL-safe slug', assert_throws(fn () => PatternInputs::validate($input))->getMessage());

    $input = pattern_proof_inputs();
    $input['media'][0]['source'] = 'outside.png';
    assert_contains('under media/', assert_throws(fn () => PatternInputs::validate($input))->getMessage());

    $input = pattern_proof_inputs();
    $input['layouts'] = [$input['layouts'][1]];
    assert_contains('one page', assert_throws(fn () => PatternInputs::validate($input))->getMessage());

    $input = pattern_proof_inputs();
    $input['media'][0]['source'] = 'media/*';
    assert_contains('concrete files', assert_throws(fn () => PatternInputs::validate($input))->getMessage());
});

test('approved pattern edits only declared text image and link bytes in nested blocks', function () {
    $input = pattern_proof_inputs();
    $layout = $input['layouts'][0];
    $pattern = new ApprovedPattern($layout['markup'], $layout['slots']);
    $prepared = ContentPersonalizer::prepare($pattern, $input['facts']);
    $consumed = ContentPersonalizer::consume($prepared['pending'], pattern_proof_response());
    $values = $prepared['values'] + $consumed['values'];
    $expected = strtr($layout['markup'], [
        'Example heading' => 'Make room for good work', 'Example address' => '12 Harbor Street',
        'https://example.test/placeholder.png' => 'https://example.test/media/studio.png',
        'Example image' => 'A sunlit studio', 'Example action' => 'Visit our studio',
        'href="https://example.test/"' => 'href="https://example.test/contact/"',
    ]);
    assert_eq($expected, $pattern->serialize($values));
    assert_eq([], $consumed['pending']);
    assert_eq([], $consumed['warnings']);
    $again = new ApprovedPattern($expected, $layout['slots']);
    assert_eq($expected, $again->serialize($values), 'serialization reaches a fixed point');
    assert_eq($layout['markup'], $pattern->serialize([]));
});

test('approved pattern synchronizes sourced comment strings without normalizing other JSON', function () {
    $markup = '<!-- wp:paragraph { "content" : "Old", "style": {"color":{"text":"#abcdef"}}, "className" : "custom" } --><p class="custom" style="color:#abcdef">Old</p><!-- /wp:paragraph -->';
    $slot = ['id' => 'copy', 'block_path' => [0], 'field' => 'text', 'fallback' => 'Fallback'];
    $pattern = new ApprovedPattern($markup, [$slot]);
    $result = $pattern->serialize(['copy' => 'New & clear']);
    $expected = str_replace('"Old"', '"New \\u0026amp; clear"', $markup);
    $expected = str_replace('>Old<', '>New &amp; clear<', $expected);
    assert_eq($expected, $result);
    assert_eq($result, (new ApprovedPattern($result, [$slot]))->serialize(['copy' => 'New & clear']));
});

test('approved pattern mirrors strings with Gutenberg block-comment escaping', function () {
    $markup = '<!-- wp:paragraph {"content":"Old"} --><p>Old</p><!-- /wp:paragraph -->';
    $slot = ['id' => 'copy', 'block_path' => [0], 'field' => 'text', 'fallback' => 'Fallback'];
    $value = 'Path \\ -- & "quoted"';
    $result = (new ApprovedPattern($markup, [$slot]))->serialize(['copy' => $value]);
    assert_contains('"content":"Path \\u005c \\u002d\\u002d \\u0026amp; \\u0026quot;quoted\\u0026quot;"', $result);
    assert_contains('>Path \\ -- &amp; &quot;quoted&quot;<', $result);
});

test('pattern export requires every declared media source', function () {
    with_temp_dir('pattern-proof-', function ($dir) {
        $project = pattern_proof_project($dir);
        unlink($project->path('media/studio.png'));
        $llm = new FakeLlm();
        $llm->queueJson(pattern_proof_response());
        $composition = StepComposition::patterns($llm);
        $error = assert_throws(fn () => (new Pipeline($composition->steps(), $composition->seeds()))->runThrough($project));
        assert_contains('Missing pattern media source', $error->getMessage());
        assert_true(!$project->exists('content-bundle.json'));
    });
});

test('approved pattern rejects slots on protected content and protected ancestors', function () {
    $layout = pattern_proof_inputs()['layouts'][0];
    $slot = ['id' => 'protected', 'block_path' => [0, 1], 'field' => 'text', 'fallback' => 'Fallback'];
    assert_contains('protected', assert_throws(fn () => new ApprovedPattern($layout['markup'], [$slot]))->getMessage());
    $markup = str_replace('approved-hero', 'ai-ignore', $layout['markup']);
    assert_contains('protected', assert_throws(fn () => new ApprovedPattern($markup, $layout['slots']))->getMessage());
});

test('approved pattern refuses whole rich-text replacement instead of destroying inline structure', function () {
    $markup = '<!-- wp:paragraph --><p>Keep <strong>this</strong> formatting</p><!-- /wp:paragraph -->';
    assert_contains('inline markup', assert_throws(fn () => new ApprovedPattern($markup, [
        ['id' => 'copy', 'block_path' => [0], 'field' => 'text', 'fallback' => 'Fallback'],
    ]))->getMessage());
});

test('approved pattern rejects structural fields overlapping slots bad paths and broken source', function () {
    $layout = pattern_proof_inputs()['layouts'][0];
    foreach (['style', 'className', 'layout', 'color'] as $field) {
        $slot = $layout['slots'][0];
        $slot['field'] = $field;
        assert_throws(fn () => new ApprovedPattern($layout['markup'], [$slot]));
    }
    $duplicate = $layout['slots'][0];
    $duplicate['id'] = 'same-target';
    assert_contains('overlap', assert_throws(fn () => new ApprovedPattern($layout['markup'], [$layout['slots'][0], $duplicate]))->getMessage());
    $duplicate['block_path'] = [99];
    assert_throws(fn () => new ApprovedPattern($layout['markup'], [$duplicate]));
    $broken = str_replace('<!-- /wp:heading -->', '<!-- /wp:paragraph -->', $layout['markup']);
    assert_throws(fn () => new ApprovedPattern($broken, $layout['slots']));
});

test('approved pattern preserves unregistered siblings byte for byte', function () {
    $layout = pattern_proof_inputs()['layouts'][0];
    $custom = "\n<!-- wp:acme/card {\"style\":{\"color\":\"red\"}} --><aside data-x='a'>Custom</aside><!-- /wp:acme/card -->";
    $pattern = new ApprovedPattern($layout['markup'] . $custom, $layout['slots']);
    assert_true(str_ends_with($pattern->serialize(['headline' => 'Updated']), $custom));
});

test('pattern bindings stay outside model requests and missing facts use explicit fallbacks', function () {
    $layout = pattern_proof_inputs()['layouts'][0];
    $prepared = ContentPersonalizer::prepare(new ApprovedPattern($layout['markup'], $layout['slots']), []);
    assert_eq(['headline', 'hero-alt', 'contact-label'], array_column($prepared['pending'], 'id'));
    assert_eq('Contact us for directions', $prepared['values']['address']);
    assert_eq(3, count($prepared['warnings']));
    assert_eq([0, 2], $prepared['warnings'][0]['block_path']);
    assert_eq(null, $prepared['warnings'][0]['authored']);
});

test('pattern content maps by stable ID and retries only missing duplicate and invalid values', function () {
    $layout = pattern_proof_inputs()['layouts'][0];
    $pending = ContentPersonalizer::prepare(new ApprovedPattern($layout['markup'], $layout['slots']), [])['pending'];
    $result = ContentPersonalizer::consume($pending, ['content' => [
        ['id' => 'contact-label', 'e' => 'Contact us'],
        ['id' => 'headline', 'e' => 'One'], ['id' => 'headline', 'e' => 'Two'],
        ['id' => 'hero-alt', 'e' => ['bad' => 'shape']], ['id' => 'address', 'e' => 'Invented address'],
    ]]);
    assert_eq(['contact-label' => 'Contact us'], $result['values']);
    assert_eq(['headline', 'hero-alt'], array_column($result['pending'], 'id'));
    assert_eq('removed', $result['warnings'][0]['delivered']);
});

test('pattern content rejects markup overlong text and unsafe URLs', function () {
    $slot = ['field' => 'text', 'max_words' => 2];
    foreach (['<script>alert(1)</script>', "bad\nvalue", 'one two three', '', ['style' => 'red']] as $value) {
        assert_true(ApprovedPattern::valueError($slot, $value) !== null);
    }
    foreach (['javascript:alert(1)', '//evil.test/a', "https://example.test/\" onclick=\"bad", 'data:image/svg+xml,test', 'https://'] as $value) {
        assert_true(ApprovedPattern::valueError(['field' => 'url'], $value) !== null, $value);
    }
    assert_eq(null, ApprovedPattern::valueError(['field' => 'alt'], ''));
    assert_eq(null, ApprovedPattern::valueError(['field' => 'url'], 'media/studio.png'));
});

test('pattern composition runs fixture clients and preserves Brand and shared-part roles', function () {
    with_temp_dir('pattern-proof-', function ($dir) {
        $project = pattern_proof_project($dir);
        $llm = new FakeLlm();
        $llm->queueJson(pattern_proof_response());
        $composition = StepComposition::patterns($llm);
        $pipeline = new Pipeline($composition->steps(), $composition->seeds());
        assert_eq(['prepare-pattern-content', 'personalize-pattern-content', 'serialize-pattern-content', 'export-content-bundle'], $pipeline->stepIds());
        $pipeline->runThrough($project);
        $output = $project->readJson('pattern-output.json');
        assert_eq(pattern_proof_inputs()['brand'], $output['brand']);
        assert_eq('shared-part', $output['layouts'][1]['role']);
        assert_contains('Harbor Studio', $output['layouts'][1]['markup']);
        assert_eq(1, $llm->completeJsonBatchCalls);
        assert_true(!$project->exists('theme'));
        assert_true(!$project->exists('plugin'));
        assert_true(!$project->exists('warnings.json'));
        $bundle = $project->readJson('content-bundle.json');
        assert_eq('wordpress-content', $bundle['kind']);
        assert_eq('twentytwentyfive', $bundle['requirements']['theme']);
        assert_eq('home', $bundle['pages'][0]['slug']);
        assert_eq('footer', $bundle['shared_parts'][0]['area']);
        assert_eq('media/studio.png', $bundle['media'][0]['source']);
        assert_eq(strlen('fixture-image-bytes'), $bundle['media'][0]['bytes']);
        assert_eq(hash('sha256', 'fixture-image-bytes'), $bundle['media'][0]['sha256']);
        assert_eq(hash('sha256', $bundle['pages'][0]['content']), $bundle['pages'][0]['content_hash']);
        $before = $project->readText('pattern-output.json');
        $pipeline->runThrough($project, fromId: 'serialize-pattern-content');
        assert_eq($before, $project->readText('pattern-output.json'));
        assert_eq(1, $llm->completeJsonBatchCalls, 'resume must not repeat model work');
    });
});

test('pattern composition retries a completely empty response then delivers reviewed fallbacks', function () {
    with_temp_dir('pattern-proof-', function ($dir) {
        $project = pattern_proof_project($dir);
        $llm = new FakeLlm();
        $llm->queueJson(['content' => []]);
        $llm->queueJson(['content' => []]);
        $composition = StepComposition::patterns($llm);
        (new Pipeline($composition->steps(), $composition->seeds()))->runThrough($project);
        assert_eq(2, $llm->completeJsonBatchCalls);
        $output = $project->readJson('pattern-output.json');
        assert_contains('Welcome to our studio', $output['layouts'][0]['markup']);
        assert_contains('Approved <strong>protected</strong> wording.', $output['layouts'][0]['markup']);
        $warnings = $project->readJson('warnings.json')['personalize-pattern-content'];
        assert_eq(3, count($warnings));
        foreach ($warnings as $warning) {
            $row = json_decode($warning, true);
            foreach (['file', 'layout', 'block_path', 'slot', 'authored', 'delivered', 'disposition'] as $key) {
                assert_true(array_key_exists($key, $row));
            }
        }
    });
});

test('pattern retry preserves good siblings and clears stale warnings on rerun', function () {
    with_temp_dir('pattern-proof-', function ($dir) {
        $project = pattern_proof_project($dir);
        $llm = new FakeLlm();
        $llm->queueJson(['content' => [['id' => 'headline', 'e' => 'Good heading']]]);
        $llm->queueJson(['content' => []]);
        $composition = StepComposition::patterns($llm);
        $pipeline = new Pipeline($composition->steps(), $composition->seeds());
        $pipeline->runThrough($project);
        $records = $project->readJson('logs/pattern-content-requests.json');
        assert_true(!str_contains($records[1]['requests']['home']['prompt'], '"id":"headline"'));
        assert_contains('Good heading', $project->readJson('pattern-output.json')['layouts'][0]['markup']);
        $llm->queueJson(pattern_proof_response());
        $pipeline->runThrough($project, fromId: 'personalize-pattern-content');
        assert_eq([], $project->readJson('warnings.json'));
    });
});

test('pattern resume refuses changed inputs and graph before model work', function () {
    with_temp_dir('pattern-proof-', function ($dir) {
        $project = pattern_proof_project($dir);
        $llm = new FakeLlm();
        $composition = StepComposition::patterns($llm);
        $pipeline = new Pipeline($composition->steps(), $composition->seeds());
        $pipeline->runThrough($project, untilId: 'prepare-pattern-content');
        $input = pattern_proof_inputs();
        $input['brand']['name'] = 'Changed';
        $project->writeJson('pattern-inputs.json', $input);
        assert_contains('Stale', assert_throws(fn () => $pipeline->runThrough($project, fromId: 'personalize-pattern-content'))->getMessage());
        assert_eq(0, $llm->completeJsonBatchCalls);
        $project->writeJson('meta.json', ['graph' => 'blocks']);
        assert_contains('graph=patterns', assert_throws(fn () => $pipeline->runThrough($project))->getMessage());
    });
});

test('pattern delivery rejects retained overrides of bindings and invalid generated values per slot', function () {
    with_temp_dir('pattern-proof-', function ($dir) {
        $project = pattern_proof_project($dir);
        $llm = new FakeLlm();
        $llm->queueJson(pattern_proof_response());
        $composition = StepComposition::patterns($llm);
        $pipeline = new Pipeline($composition->steps(), $composition->seeds());
        $pipeline->runThrough($project, untilId: 'personalize-pattern-content');
        $values = $project->readJson('pattern-values.json');
        $values['layouts']['home']['address'] = 'Invented address';
        $values['layouts']['home']['headline'] = '<script>bad()</script>';
        $values['layouts']['home']['style'] = ['color' => 'red'];
        $project->writeJson('pattern-values.json', $values);
        $pipeline->runThrough($project, fromId: 'serialize-pattern-content');
        $output = $project->readJson('pattern-output.json')['layouts'][0]['markup'];
        assert_contains('12 Harbor Street', $output);
        assert_contains('Welcome to our studio', $output);
        assert_contains('Visit our studio', $output);
        assert_true(!str_contains($output, 'Invented address'));
        assert_eq(3, count($project->readJson('warnings.json')['serialize-pattern-content']));
        $before = $project->readText('pattern-output.json');
        $pipeline->runThrough($project, fromId: 'serialize-pattern-content');
        assert_eq($before, $project->readText('pattern-output.json'));
    });
});

test('pattern content records forbidden response styling while retaining valid copy', function () {
    $layout = pattern_proof_inputs()['layouts'][0];
    $pending = ContentPersonalizer::prepare(new ApprovedPattern($layout['markup'], $layout['slots']), [])['pending'];
    $response = pattern_proof_response();
    $response['style'] = ['color' => 'red'];
    $response['content'][0]['className'] = 'unapproved';
    $result = ContentPersonalizer::consume($pending, $response);
    assert_eq([], $result['pending']);
    assert_eq(2, count($result['warnings']));
    assert_eq('Make room for good work', $result['values']['headline']);
});

test('pattern terminal JSON failures retain valid batch siblings', function () {
    with_temp_dir('pattern-proof-', function ($dir) {
        $project = pattern_proof_project($dir);
        $input = pattern_proof_inputs();
        unset($input['layouts'][1]['slots'][0]['binding']);
        $project->writeJson('pattern-inputs.json', $input);
        $llm = new class implements \Automattic\SiteBuild\Llm {
            public int $calls = 0;
            public function complete(string $prompt, array $opts = []): string { throw new LogicException('unexpected'); }
            public function completeJson(string $prompt, array $opts = []): array { throw new LogicException('unexpected'); }
            public function completeBatch(array $requests): \Automattic\SiteBuild\TextBatchResult { throw new LogicException('unexpected'); }
            public function completeJsonBatch(array $requests): array {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new \Automattic\SiteBuild\GeneratedJsonException(['home' => 'invalid JSON'], ['footer' => ['content' => [['id' => 'business-name', 'e' => 'Retained sibling']]]]);
                }
                assert_eq(['home'], array_keys($requests));
                throw new \Automattic\SiteBuild\GeneratedJsonException(['home' => 'still invalid']);
            }
        };
        $composition = StepComposition::patterns($llm);
        (new Pipeline($composition->steps(), $composition->seeds()))->runThrough($project);
        $output = $project->readJson('pattern-output.json');
        assert_contains('Retained sibling', $output['layouts'][1]['markup']);
        assert_contains('Welcome to our studio', $output['layouts'][0]['markup']);
        assert_eq(2, $llm->calls);
    });
});

test('approved pattern prevents double attributes stale image IDs and unbound links', function () {
    $layout = pattern_proof_inputs()['layouts'][0];
    $imageSlot = $layout['slots'][2];
    $markup = str_replace('src="https://example.test/placeholder.png"', 'src="https://example.test/placeholder.png" src="second"', $layout['markup']);
    assert_throws(fn () => new ApprovedPattern($markup, [$imageSlot]));
    $markup = str_replace('"sizeSlug":"large"', '"id":42,"sizeSlug":"large"', $layout['markup']);
    assert_throws(fn () => new ApprovedPattern($markup, [$imageSlot]));
    $linkSlot = $layout['slots'][5];
    unset($linkSlot['binding']);
    assert_throws(fn () => new ApprovedPattern($layout['markup'], [$linkSlot]));
});

test('approved pattern preserves single-quoted attributes and escapes quote injection', function () {
    $markup = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href=\'https://example.test/\'>Visit</a></div><!-- /wp:button -->';
    $slot = ['id' => 'link', 'block_path' => [0], 'field' => 'url', 'binding' => 'url', 'fallback' => 'https://example.test/'];
    $pattern = new ApprovedPattern($markup, [$slot]);
    assert_eq(str_replace("https://example.test/", 'https://example.test/?a=1&amp;b=2', $markup), $pattern->serialize(['link' => 'https://example.test/?a=1&b=2']));
    assert_throws(fn () => $pattern->serialize(['link' => "https://example.test/' onmouseover='bad"]));
});

test('SiteBuilder creates and runs a pattern project through its public API', function () {
    with_temp_dir('pattern-store-', function ($dir) {
        $llm = new FakeLlm();
        $llm->queueJson(pattern_proof_response());
        $fixer = new class implements BlockFixer {
            public function fix(string $themeDir): string { return ''; }
        };
        $builder = new SiteBuilder($llm, dirname(__DIR__, 2) . '/prompts', $dir, $fixer);
        $project = $builder->createPatternProject(pattern_proof_inputs(), 'proof');
        assert_eq('patterns', $project->readJson('meta.json')['graph']);
        $project->writeText('media/studio.png', 'fixture-image-bytes');
        $builder->patternPipeline()->runThrough($project);
        assert_true($project->exists('content-bundle.json'));
    });
});

test('SiteBuilder validates pattern inputs before claiming a project directory', function () {
    with_temp_dir('pattern-store-', function ($dir) {
        $llm = new FakeLlm();
        $fixer = new class implements BlockFixer {
            public function fix(string $themeDir): string { return ''; }
        };
        $builder = new SiteBuilder($llm, dirname(__DIR__, 2) . '/prompts', $dir, $fixer);
        $input = pattern_proof_inputs();
        $input['delivery']['theme'] = '../unsafe';
        assert_throws(fn () => $builder->createPatternProject($input, 'must-not-exist'));
        assert_true(!is_dir($dir . '/must-not-exist'));
    });
});
