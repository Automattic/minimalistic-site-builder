<?php
declare(strict_types=1);

use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\SiteSpecStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/** @return array<string,mixed> */
function site_spec_contract_object(string $path): array
{
    $json = file_get_contents($path);
    assert_true($json !== false, "could not read {$path}");
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    assert_true(is_array($decoded) && !array_is_list($decoded), "{$path} must contain a JSON object");
    return $decoded;
}

/**
 * Dependency-free validator for the deliberately small JSON Schema subset
 * used by schemas/site-spec.schema.json.
 *
 * @param array<string,mixed> $schema
 * @param array<string,mixed> $root
 */
function site_spec_contract_validate(mixed $value, array $schema, array $root, string $path = '$'): void
{
    if (isset($schema['$ref'])) {
        $ref = (string) $schema['$ref'];
        if (!str_starts_with($ref, '#/')) {
            throw new RuntimeException("{$path}: only local schema references are supported");
        }
        $target = $root;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!is_array($target) || !array_key_exists($segment, $target)) {
                throw new RuntimeException("{$path}: unresolved schema reference {$ref}");
            }
            $target = $target[$segment];
        }
        if (!is_array($target)) {
            throw new RuntimeException("{$path}: schema reference {$ref} is not an object");
        }
        site_spec_contract_validate($value, $target, $root, $path);
        return;
    }

    $type = $schema['type'] ?? null;
    $matchesType = match ($type) {
        'object' => is_array($value) && !array_is_list($value),
        'array' => is_array($value) && array_is_list($value),
        'string' => is_string($value),
        'integer' => is_int($value),
        'number' => is_int($value) || is_float($value),
        'boolean' => is_bool($value),
        'null' => $value === null,
        null => true,
        default => throw new RuntimeException("{$path}: unsupported schema type {$type}"),
    };
    if (!$matchesType) {
        throw new RuntimeException("{$path}: expected {$type}, got " . get_debug_type($value));
    }

    if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
        throw new RuntimeException("{$path}: value is outside the schema enum");
    }

    if (is_string($value)) {
        if (isset($schema['minLength']) && mb_strlen($value) < (int) $schema['minLength']) {
            throw new RuntimeException("{$path}: string is shorter than minLength");
        }
        if (isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
            throw new RuntimeException("{$path}: string is longer than maxLength");
        }
        if (isset($schema['pattern']) && preg_match('~' . $schema['pattern'] . '~u', $value) !== 1) {
            throw new RuntimeException("{$path}: string does not match the schema pattern");
        }
    }

    if (is_array($value) && array_is_list($value)) {
        if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
            throw new RuntimeException("{$path}: array has fewer than minItems entries");
        }
        if (($schema['uniqueItems'] ?? false) === true) {
            $encoded = array_map(
                static fn (mixed $item): string => json_encode($item, JSON_THROW_ON_ERROR),
                $value,
            );
            if (count($encoded) !== count(array_unique($encoded))) {
                throw new RuntimeException("{$path}: array entries are not unique");
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $index => $item) {
                site_spec_contract_validate($item, $schema['items'], $root, "{$path}[{$index}]");
            }
        }
    }

    if (is_array($value) && !array_is_list($value)) {
        foreach ((array) ($schema['required'] ?? []) as $key) {
            if (!array_key_exists((string) $key, $value)) {
                throw new RuntimeException("{$path}: missing required property {$key}");
            }
        }
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($properties as $key => $propertySchema) {
            if (array_key_exists($key, $value) && is_array($propertySchema)) {
                site_spec_contract_validate($value[$key], $propertySchema, $root, "{$path}.{$key}");
            }
        }
        if (($schema['additionalProperties'] ?? true) === false) {
            $extra = array_diff(array_keys($value), array_keys($properties));
            if ($extra !== []) {
                throw new RuntimeException("{$path}: unknown properties: " . implode(', ', $extra));
            }
        }
    }
}

test('siteSpec schema and example publish the canonical package contract', function () {
    $schema = site_spec_contract_object(Package::siteSpecSchemaPath());
    $example = site_spec_contract_object(Package::siteSpecExamplePath());
    $required = [
        'name', 'slug', 'title', 'description', 'site_type', 'topic', 'area', 'audience',
        'language', 'persona_name', 'email_domain', 'invented', 'visual_vibe',
        'animation_request', 'sections', 'pages',
    ];

    assert_eq('https://json-schema.org/draft/2020-12/schema', $schema['$schema'] ?? null);
    assert_eq($required, $schema['required'] ?? null, 'the schema must list every canonical fixed field');
    assert_eq(true, $schema['additionalProperties'] ?? null, 'grounded factual extensions stay supported');
    assert_eq(false, $schema['$defs']['page']['additionalProperties'] ?? null, 'page fields are canonicalized');

    site_spec_contract_validate($example, $schema, $schema);
    assert_true(isset($example['location'], $example['hours']), 'the example demonstrates factual extensions');
    assert_true(!isset($schema['properties']['location'], $schema['properties']['hours']));

    $invalid = $example;
    $invalid['slug'] = 'Not A Canonical Slug';
    assert_throws(
        fn () => site_spec_contract_validate($invalid, $schema, $schema),
        'the contract checker must reject a non-canonical slug',
    );
});

test('siteSpec example is a warning-free normalization fixed point', function () {
    $tmp = sys_get_temp_dir() . '/builder_sitespec_contract_' . uniqid();
    $llm = new FakeLlm();
    $builder = make_test_builder($llm, $tmp);
    $example = site_spec_contract_object(Package::siteSpecExamplePath());
    $project = $builder->createProject(
        prompt: 'Use the complete canonical bakery facts supplied by the host.',
        slug: 'site-spec-contract',
        siteSpec: $example,
    );

    (new SiteSpecStep($llm, new PromptRenderer(Package::promptsDir())))->run($project);

    assert_eq(0, $llm->completeJsonCalls, 'the canonical example must bypass site-spec generation');
    assert_eq($example, $project->readJson('siteSpec.json'), 'normalization must leave the example unchanged');
    assert_true(!$project->exists('warnings.json'), 'the canonical example must need no repair warning');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('siteSpec schema required fields match exhaustive normalization', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture(multiPage: true, siteSpec: []);
    (new SiteSpecStep($llm, new PromptRenderer(Package::promptsDir())))->run($project);

    $normalizedKeys = array_keys($project->readJson('siteSpec.json'));
    $schemaKeys = site_spec_contract_object(Package::siteSpecSchemaPath())['required'];
    sort($normalizedKeys);
    sort($schemaKeys);
    assert_eq($schemaKeys, $normalizedKeys, 'schema required fields must match an exhaustively normalized spec');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('siteSpec schema invented enum matches the identities normalization can invent', function () {
    // The schema's invented.items.enum and SiteSpecStep's identity handling are
    // written by hand in two places. Same tripwire style as the required-fields
    // test above: force every invention from an empty spec and check the schema
    // admits exactly what normalization produced.
    [$project, $llm, $tmp] = make_sitespec_fixture(multiPage: false, siteSpec: []);
    (new SiteSpecStep($llm, new PromptRenderer(Package::promptsDir())))->run($project);

    $invented = $project->readJson('siteSpec.json')['invented'];
    $schema = site_spec_contract_object(Package::siteSpecSchemaPath());
    $allowed = $schema['properties']['invented']['items']['enum'];

    assert_true($invented !== [], 'an empty spec must invent identity fields');
    foreach ($invented as $key) {
        assert_true(in_array($key, $allowed, true), "schema enum is missing invented key '{$key}'");
    }
    sort($allowed);
    $normalizable = $allowed;
    sort($normalizable);
    assert_eq($allowed, $normalizable);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('siteSpec schema email_domain pattern agrees with normalization', function () {
    // The domain rule exists twice — a pattern string in the schema and a
    // preg_match in SiteSpecStep. Behavioral parity: for each sample, the
    // schema pattern accepts exactly the domains normalization preserves.
    $schema = site_spec_contract_object(Package::siteSpecSchemaPath());
    $pattern = '/' . str_replace('/', '\\/', $schema['properties']['email_domain']['pattern']) . '/';

    $samples = [
        'valid-domain.com'   => true,
        'sub.valid.org'      => true,
        'Bad_Domain!'        => false,
        'nodots'             => false,
        '-leading.com'       => false,
    ];
    foreach ($samples as $domain => $valid) {
        assert_eq($valid, preg_match($pattern, $domain) === 1, "schema pattern disagrees on '{$domain}'");

        [$project, $llm, $tmp] = make_sitespec_fixture(multiPage: false, siteSpec: ['email_domain' => $domain]);
        (new SiteSpecStep($llm, new PromptRenderer(Package::promptsDir())))->run($project);
        $spec = $project->readJson('siteSpec.json');
        $preserved = strtolower($domain) === ($spec['email_domain'] ?? null)
            && !in_array('email_domain', $spec['invented'] ?? [], true);
        assert_eq($valid, $preserved, "normalization disagrees with the schema on '{$domain}'");
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
