<?php
declare(strict_types=1);

use Automattic\SiteBuild\TransformArtifacts;

test('transform artifact contract freezes shared paths and report keys', function (): void {
    assert_eq('design/site.css', TransformArtifacts::SITE_CSS);
    assert_eq(
        'design/transformer-carried-before-author.css',
        TransformArtifacts::CARRIED_CSS_BEFORE_AUTHOR,
    );
    assert_eq(
        'design/transformer-carried-after-author.css',
        TransformArtifacts::CARRIED_CSS_AFTER_AUTHOR,
    );
    assert_eq('design/transform-report.json', TransformArtifacts::REPORT);
    assert_eq('eval/transform-site-report.schema.json', TransformArtifacts::REPORT_SCHEMA);
    assert_eq('fragment-repair.md', TransformArtifacts::REPAIR_PROMPT);
    assert_eq(
        ['fallback_codes', 'repair_outcomes', 'dropped_fragments'],
        TransformArtifacts::REPORT_KEYS,
    );

    $schema = json_decode(
        (string) file_get_contents(repo_path(TransformArtifacts::REPORT_SCHEMA)),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    assert_eq(TransformArtifacts::REPORT_KEYS, $schema['required'] ?? []);
    assert_eq(false, $schema['additionalProperties'] ?? null);
});

test('fragment repair prompt freezes exact placeholders and output boundary', function (): void {
    $prompt = (string) file_get_contents(repo_path('prompts/' . TransformArtifacts::REPAIR_PROMPT));
    assert_contains('{{fragment}}', $prompt);
    assert_contains('{{supported_slice}}', $prompt);
    assert_contains('Return rewritten HTML only.', $prompt);
});
