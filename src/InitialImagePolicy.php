<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\Steps\SectionsStep;

/** Initial-build spending policy, using collection provenance that survives assembly. */
final class InitialImagePolicy
{
    /** @var array<string,true> */
    private array $eligibleSources = [
        'parts/header.html' => true,
        'parts/footer.html' => true,
    ];

    /** @param ?array<string,mixed> $plan null for a theme-only, single-homepage composition */
    public function __construct(private ?array $plan)
    {
        foreach ((array) ($plan['pages'] ?? []) as $page) {
            $sections = array_values((array) ($page['sections'] ?? []));
            foreach ($sections as $index => $section) {
                $role = $section['role'] ?? SectionRole::forPosition($index, count($sections));
                if (!empty($page['front']) || $role === SectionRole::HERO) {
                    $part = SectionsStep::partSlug((string) ($page['slug'] ?? ''), (string) ($section['slug'] ?? ''));
                    $this->eligibleSources['parts/' . $part . '.html'] = true;
                }
            }
        }
    }

    /** @param array<string,mixed> $spec */
    public function shouldGenerate(array $spec): bool
    {
        // Theme-only compositions have no interior pages to defer. The logo
        // is synthesized without source parts, but is visible on the homepage.
        if ($this->plan === null || ($spec['role'] ?? '') === 'site-logo') {
            return true;
        }
        foreach ((array) ($spec['sources'] ?? []) as $source) {
            if (isset($this->eligibleSources[$source])) {
                return true;
            }
        }
        return false;
    }
}
