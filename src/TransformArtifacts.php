<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Shared artifact paths between transform-site and downstream style merging. */
final class TransformArtifacts
{
    public const SITE_CSS = 'design/site.css';
    public const CARRIED_CSS_BEFORE_AUTHOR = 'design/transformer-carried-before-author.css';
    public const CARRIED_CSS_AFTER_AUTHOR = 'design/transformer-carried-after-author.css';
    public const REPORT = 'design/transform-report.json';
    public const REPORT_SCHEMA = 'eval/transform-site-report.schema.json';
    public const REPAIR_PROMPT = 'fragment-repair.md';

    /** @var list<string> */
    public const REPORT_KEYS = [
        'fallback_codes',
        'repair_outcomes',
        'dropped_fragments',
    ];
}
