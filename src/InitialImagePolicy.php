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

    /** @var array<string,true> every part the plan accounts for, eligible or not */
    private array $plannedSources = [
        'parts/header.html' => true,
        'parts/footer.html' => true,
    ];

    /**
     * @param ?array<string,mixed> $plan null for a theme-only, single-homepage composition
     * @param bool $generateAll caller opted out of deferral entirely
     */
    public function __construct(private ?array $plan, private bool $generateAll = false)
    {
        foreach ((array) ($plan['pages'] ?? []) as $page) {
            $sections = array_values((array) ($page['sections'] ?? []));
            foreach ($sections as $index => $section) {
                $role = $section['role'] ?? SectionRole::forPosition($index, count($sections));
                $part = SectionsStep::partSlug((string) ($page['slug'] ?? ''), (string) ($section['slug'] ?? ''));
                $this->plannedSources['parts/' . $part . '.html'] = true;
                if (!empty($page['front']) || $role === SectionRole::HERO) {
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
        if ($this->exempt($spec)) {
            return true;
        }
        foreach ((array) ($spec['sources'] ?? []) as $source) {
            if (isset($this->eligibleSources[$source])) {
                return true;
            }
        }
        // Planned but ineligible is a decision to defer. Unplaced is not a
        // decision at all, so it generates: see unplaced().
        return $this->unplaced($spec);
    }

    /**
     * Whether this image generates only because the policy could not place it.
     *
     * Assembly rewrites provenance — assemble-pages inlines the page parts and
     * deletes them, so a resumed build re-collects against templates/*.html and
     * arrives here with sources no plan names. Deferring those would gray out
     * the homepage this policy exists to protect, so they generate and say so.
     *
     * @param array<string,mixed> $spec
     */
    public function unplaced(array $spec): bool
    {
        if ($this->exempt($spec)) {
            return false;
        }
        foreach ((array) ($spec['sources'] ?? []) as $source) {
            if (isset($this->plannedSources[$source])) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $spec Outside the policy's remit entirely. */
    private function exempt(array $spec): bool
    {
        return $this->generateAll
            || $this->plan === null
            || ($spec['role'] ?? '') === 'site-logo';
    }
}
