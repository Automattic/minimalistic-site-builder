<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step: check the host's inputs and settle them into one artifact.
 *
 * The host decides how a request reaches it; the stages downstream should not
 * have to care. This is the boundary between the two — it reads the three
 * things a host supplies and writes the single shape everything after it
 * consumes, so a change to how requests arrive stops here.
 *
 * What it refuses is chosen by what would otherwise fail silently. An absent
 * theme composes a site for nothing in particular; an inventory entry with no
 * markup contributes an empty section that still passes every later check; a
 * Brand carrying keys outside theme.json lands in a global-styles post that
 * WordPress quietly strips. Each of those reports success, which is why each
 * of them stops the build here instead.
 *
 * Optional metadata is not held to that standard: a missing category list or
 * an absent navigation is a site with fewer things in it, not a wrong one.
 */
final class NormalizeInputsStep implements Step
{
    /**
     * Bumped when the normalized shape changes in a way a retained artifact
     * cannot satisfy, so a resumed run rejects stale inputs rather than
     * composing from half of an older contract.
     */
    public const INPUTS_VERSION = 1;

    /** theme.json's own top-level keys. A Brand is a partial of one. */
    private const BRAND_KEYS = ['settings', 'styles'];

    /**
     * Refuse normalized inputs this build cannot read.
     *
     * A run started partway through takes these from a fixture or an earlier
     * run, which may predate the shape the stages now expect. Reading them
     * anyway composes from whatever happens to still line up and reports
     * success, so the mismatch is caught where the artifact is first used.
     *
     * @param array<string, mixed> $inputs
     */
    public static function assertVersion(array $inputs): void
    {
        $version = $inputs['version'] ?? null;

        if ($version !== self::INPUTS_VERSION) {
            throw new \RuntimeException(sprintf(
                'Normalized inputs are version %s; this build reads version %d. Re-run from normalize-inputs.',
                var_export($version, true),
                self::INPUTS_VERSION,
            ));
        }
    }

    public function id(): string
    {
        return 'normalize-inputs';
    }

    public function label(): string
    {
        return 'Validate request, inventory, Brand and capabilities';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [PatternArtifacts::REQUEST, PatternArtifacts::INVENTORY, PatternArtifacts::BRAND],
            writes: [PatternArtifacts::NORMALIZED],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $request = $project->readJson(PatternArtifacts::REQUEST);
        $inventory = $project->readJson(PatternArtifacts::INVENTORY);
        $brand = $project->readJson(PatternArtifacts::BRAND);

        $problems = [];

        $theme = trim((string) ($request['theme'] ?? ''));
        if ($theme === '') {
            $problems[] = 'request names no theme, and content is composed for one';
        }

        $patterns = self::patterns($inventory, $problems);
        if ($patterns === [] && $problems === []) {
            $problems[] = 'the approved inventory is empty, so there is nothing to compose from';
        }

        foreach (array_keys($brand) as $key) {
            if (!in_array($key, self::BRAND_KEYS, true)) {
                $problems[] = sprintf(
                    'Brand carries "%s", which is not a theme.json key and would be dropped without a word',
                    $key,
                );
            }
        }

        if ($problems !== []) {
            throw new \RuntimeException(
                "These inputs cannot produce a site:\n  - " . implode("\n  - ", $problems)
            );
        }

        $project->writeJson(PatternArtifacts::NORMALIZED, [
            'version' => self::INPUTS_VERSION,
            'theme' => $theme,
            'locale' => (string) ($request['locale'] ?? 'en'),
            'brand' => $brand,
            'inventory' => $patterns,
            'pages' => $request['pages'] ?? [],
            'navigation' => $request['navigation'] ?? [],
            'facts' => $request['facts'] ?? [],
            'capabilities' => [
                'classes' => self::strings($request['capabilities']['classes'] ?? []),
                'template_parts' => $request['capabilities']['template_parts'] ?? [],
            ],
        ]);
    }

    /**
     * The inventory, with each entry reduced to what composition reads.
     *
     * An entry missing an id or its markup is reported rather than skipped.
     * Skipping it would shrink the vocabulary without saying so, and the build
     * would fail later at whichever section happened to need it — pointing at
     * the plan, which is not where the problem is.
     *
     * @param array<string, mixed> $inventory
     * @param list<string>         $problems
     * @return list<array<string, mixed>>
     */
    private static function patterns(array $inventory, array &$problems): array
    {
        $patterns = [];
        $seen = [];

        foreach ($inventory['patterns'] ?? [] as $index => $pattern) {
            $id = is_array($pattern) ? trim((string) ($pattern['id'] ?? '')) : '';
            $content = is_array($pattern) ? (string) ($pattern['content'] ?? '') : '';

            if ($id === '') {
                $problems[] = sprintf('inventory entry %s has no id', (string) $index);
                continue;
            }
            if (trim($content) === '') {
                $problems[] = sprintf('inventory entry "%s" has no markup', $id);
                continue;
            }
            if (isset($seen[$id])) {
                $problems[] = sprintf('inventory lists "%s" twice, so which one composes is a coin toss', $id);
                continue;
            }

            $seen[$id] = true;
            $patterns[] = [
                'id' => $id,
                'categories' => self::strings($pattern['categories'] ?? []),
                'content' => $content,
            ];
        }

        return $patterns;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item) => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }
}
