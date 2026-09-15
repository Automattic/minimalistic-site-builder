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
 * have to care. This is the boundary between the two: it reads the three seeds
 * a host supplies and writes the single shape everything after it consumes, so
 * a change to how requests arrive stops here.
 *
 * The rules are `HostRequest`'s, and every problem is reported together. What
 * this adds is the settling: defaults filled, the Brand's context copied into
 * the facts, each page marked as composed or supplied so no later stage has to
 * infer that from which keys happen to be present.
 */
final class NormalizeInputsStep implements Step
{
    /**
     * Bumped when the normalized shape changes in a way a retained artifact
     * cannot satisfy, so a resumed run rejects stale inputs rather than
     * composing from half of an older contract.
     *
     * 2: pages are composed or supplied, the Brand is a record with `config`,
     * the site has a title.
     */
    public const INPUTS_VERSION = 2;

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
        $patterns = $project->readJson(PatternArtifacts::INVENTORY);
        $brand = $project->readJson(PatternArtifacts::BRAND);

        try {
            HostRequest::validate($request, $patterns, $brand);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $unused = [];
        $inventory = HostRequest::inventory($patterns, $unused);

        $facts = is_array($request['facts'] ?? null) ? $request['facts'] : [];
        $context = trim((string) ($brand['context'] ?? ''));
        if ($context !== '' && trim((string) ($facts['brand_context'] ?? '')) === '') {
            $facts['brand_context'] = $context;
        }

        $project->writeJson(PatternArtifacts::NORMALIZED, [
            'version' => self::INPUTS_VERSION,
            'theme' => trim((string) $request['theme']),
            'locale' => trim((string) ($request['locale'] ?? '')) ?: 'en',
            'site' => ['title' => trim((string) $request['site']['title'])],
            'brand' => [
                'id' => $brand['id'] ?? null,
                'name' => (string) ($brand['name'] ?? ''),
                'logo_url' => (string) ($brand['logo_url'] ?? ''),
                'context' => $context,
                'config' => is_array($brand['config'] ?? null) ? $brand['config'] : [],
            ],
            'inventory' => $inventory,
            'pages' => array_map([self::class, 'page'], $request['pages'] ?? []),
            'navigation' => $request['navigation'] ?? [],
            'image_generation' => is_array($request['image_generation'] ?? null)
                ? $request['image_generation']
                : ['enabled' => true, 'scope' => 'composed'],
            'facts' => $facts,
            'capabilities' => [
                'classes' => HostRequest::strings($request['capabilities']['classes'] ?? []),
                // Which of the theme's page templates a generated page renders
                // in. A theme's default page template usually prints the
                // title, and a front page titled "Home" then opens with the
                // word Home above its hero. The host knows its theme's
                // templates; generation only carries the choice through.
                'page_template' => trim((string) ($request['capabilities']['page_template'] ?? '')),
            ],
        ]);
    }

    /**
     * One page, settled: `intent` means composed; `markup` means supplied,
     * with `slots` null (discover them), a list (only these), or [] (frozen).
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private static function page(array $page): array
    {
        $settled = [
            'slug' => trim((string) $page['slug']),
            'title' => trim((string) ($page['title'] ?? '')) ?: ucfirst(trim((string) $page['slug'])),
        ];

        if (array_key_exists('markup', $page)) {
            $settled['markup'] = (string) $page['markup'];
            $settled['slots'] = array_key_exists('slots', $page) ? array_values((array) $page['slots']) : null;
        } else {
            $settled['intent'] = trim((string) $page['intent']);
            if (array_key_exists('sections', $page) && is_array($page['sections'])) {
                $settled['sections'] = array_values($page['sections']);
            }
        }

        return $settled;
    }

    /** Whether a settled page was supplied by the host rather than composed. */
    public static function isSupplied(array $page): bool
    {
        return array_key_exists('markup', $page);
    }
}
