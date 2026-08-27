<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TreeGraph\Gates;
use Automattic\SiteBuild\TreeGraph\Harness;
use Automattic\SiteBuild\TreeGraph\SandboxClient;
use Automattic\SiteBuild\TreeGraph\TokenMath;
use Automattic\SiteBuild\TreeGraph\TreeGate;
use Automattic\SiteBuild\TreeGraph\TreeGraphException;

/**
 * Tree graph step 7: publish (port of x-pipeline S8, brochure lane).
 *
 * Zero LLM calls. Assembles each page from its section trees at the final
 * fingerprint, mints the 1×1 placeholder pixel behind every image intent
 * (geometry is final, pixels are provisional), validates with an EMPTY
 * allow-set, compiles through the site's own wp.blocks, and publishes:
 * pages (per-page no-title template), site identity + front page, the
 * designed header/footer parts with nav links injected — with the
 * deterministic nav post and two-paragraph footer as the floor.
 */
final class TreePublishStep implements Step
{
    public function id(): string
    {
        return 'publish';
    }

    public function label(): string
    {
        return 'Publish pages to the sandbox';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                'meta.json',
                'brief.json',
                'artifacts.json',
                'trees/*',
                'sections-index.json',
                'tokens.json',
                'instance.json',
                'sandbox.json',
            ],
            writes: ['pages/*', 'trees/*', 'published.json', 'instance.json', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $meta = $project->readJson('meta.json');
        $brief = $project->readJson('brief.json');
        $index = $project->readJson('sections-index.json');
        $tokens = $project->readJson('tokens.json');
        $instance = $project->readJson('instance.json');
        $sandbox = $project->readJson('sandbox.json');
        $artifacts = $project->exists('artifacts.json') ? $project->readJson('artifacts.json') : [];

        $client = new SandboxClient((string) $sandbox['url']);
        $harness = new Harness(\repo_path());
        $gate = new TreeGate($client, $harness, $project);
        $withImages = (bool) ($meta['tree_images'] ?? false);

        // The final epoch, stamped into every assembled tree. Nothing installed
        // mid-run in the brochure lane, but the token write moved it — ask the
        // instance rather than trusting a stale record.
        $epoch = (string) $client->fingerprint()['fingerprint'];
        $instance['fingerprint'] = $epoch;
        $project->writeJson('instance.json', $instance);

        // Placeholder tone. When the pixels SHIP (no image pass), each follows
        // its own BAND: the band background nudged 12% toward its ink, so the
        // slot reads as intentional texture on light and dark bands alike. In
        // an imaged run the pixel is swapped within minutes, and accent makes
        // the swap easy to spot.
        $briefPalette = (array) ($brief['palette'] ?? []);
        $toneRoles = $withImages ? ['accent'] : ['surface', 'muted', 'secondary', 'accent'];
        $accent = null;
        foreach ($toneRoles as $role) {
            foreach ($briefPalette as $entry) {
                if (($entry['role'] ?? '') === $role) {
                    $accent = $entry;
                    break 2;
                }
            }
        }
        $accent ??= $briefPalette[0] ?? ['color' => '#888888'];
        $paletteBySlug = [];
        foreach ((array) ($tokens['palette'] ?? []) as $entry) {
            $paletteBySlug[(string) $entry['slug']] = (string) $entry['color'];
        }
        $placeholderTone = function (?string $bandSlug) use ($withImages, $paletteBySlug, $accent): string {
            $bandHex = (!$withImages && $bandSlug !== null) ? ($paletteBySlug[$bandSlug] ?? null) : null;
            if ($bandHex === null) {
                return (string) $accent['color'];
            }
            return TokenMath::mixHex($bandHex, TokenMath::toneOf($bandHex) === 'light' ? '#000000' : '#FFFFFF', 0.12);
        };

        $published = ['pages' => []];
        $pageTrees = [];
        foreach ((array) ($brief['pages'] ?? []) as $page) {
            $slug = (string) $page['slug'];
            $blocks = [];
            foreach ((array) $index as $row) {
                if ((string) $row['page'] !== $slug) {
                    continue;
                }
                $record = $project->readJson("trees/{$row['key']}.json");
                if (!is_array($record['tree'] ?? null)) {
                    $project->addWarnings($this->id(), ["section {$row['key']} had no tree at publish time; its slot was skipped"]);
                    continue;
                }
                array_push($blocks, ...(array) $record['tree']['blocks']);
            }
            $tree = ['version' => 1, 'epoch' => $epoch, 'blocks' => $blocks];

            // Mint the pixel that carries each image intent.
            $mints = 0;
            $this->walkImages($tree['blocks'], function (array &$node, ?string $bandSlug) use ($client, $placeholderTone, &$mints): void {
                $pixel = $client->placeholder($placeholderTone($bandSlug));
                $node['attributes']['url'] = (string) $pixel['url'];
                $node['attributes']['id'] = (int) $pixel['id'];
                $mints++;
            });

            // Every deferral must have resolved at the final epoch: empty allow-set.
            $validation = $client->validate($tree);
            $screen = Gates::screenTreeDiagnostics($validation, []);
            if ($screen['status'] !== 'pass') {
                throw new TreeGraphException(
                    'gate_failed',
                    "assembled page \"{$slug}\" failed validation at the final epoch",
                    'See diagnostics.',
                    ['failures' => $screen['failures'], 'diagnostics' => $validation['diagnostics'] ?? []],
                );
            }
            $pageTrees[$slug] = ['tree' => $tree, 'title' => (string) $page['title'], 'front_page' => (bool) ($page['front_page'] ?? false), 'mints' => $mints];
        }

        // One harness invocation compiles every page against the same warm registry.
        $compiled = $harness->compile(
            $project,
            $client->harnessUrl(),
            array_map(static fn (array $p): array => (array) $p['tree']['blocks'], $pageTrees),
        );
        $registry = array_map('strval', (array) ($compiled['registry'] ?? []));
        foreach ($pageTrees as $slug => $page) {
            $result = (array) ($compiled['results'][$slug] ?? []);
            $failures = TreeGate::compileFailures($page['tree'], ['result' => $result, 'registry' => $registry]);
            if ($failures !== []) {
                throw new TreeGraphException(
                    'gate_failed',
                    "page \"{$slug}\" failed its compile gate: "
                    . implode(' | ', array_map(static fn (array $f): string => (string) $f['message'], array_slice($failures, 0, 3))),
                    'Content for these blocks lives where their save() reads it (innerBlocks).',
                    ['failures' => $failures],
                );
            }
            $markup = (string) $result['markup'];
            $project->writeText("pages/{$slug}.html", $markup);
            $project->writeJson("trees/page--{$slug}.json", $page['tree']);

            // Designed pages carry their own h1, so each takes the theme's
            // no-title template — per page, the only lever with no
            // front-page-hierarchy side effects.
            $saved = $client->publishPage([
                'title'    => $page['title'],
                'slug'     => $slug,
                'content'  => $markup,
                'template' => 'page-no-title',
            ]);
            $published['pages'][] = [
                'slug'       => $slug,
                'id'         => (int) $saved['id'],
                'link'       => (string) $saved['link'],
                'front_page' => $page['front_page'],
                'has_images' => $page['mints'] > 0,
            ];
            Narrator::write("  published {$saved['link']}" . ($page['mints'] > 0 ? " ({$page['mints']} image slot(s))" : '') . "\n");
        }

        // Site identity + front page + Sample Page cleanup.
        $front = null;
        foreach ($published['pages'] as $page) {
            if ($page['front_page']) {
                $front = $page;
                break;
            }
        }
        $front ??= $published['pages'][0] ?? null;
        if ($front !== null) {
            $client->publishSettings([
                'title'         => (string) ($brief['identity']['site_title'] ?? ''),
                'description'   => (string) ($brief['identity']['tagline'] ?? ''),
                'show_on_front' => 'page',
                'page_on_front' => $front['id'],
            ]);
            $published['site_title'] = (string) ($brief['identity']['site_title'] ?? '');
        }
        $client->deleteSamplePage();
        Narrator::write('  site named "' . ($published['site_title'] ?? '') . "\", front page set, sample page removed\n");

        // Site furniture: the designed parts when they survived their gates,
        // deterministic floors otherwise.
        $navLinks = array_map(
            static fn (array $item): array => [
                'name'        => 'core/navigation-link',
                'attributes'  => ['label' => (string) $item['label'], 'url' => '/' . $item['page_slug'] . '/', 'kind' => 'custom'],
                'innerBlocks' => [],
            ],
            (array) ($brief['navigation']['items'] ?? []),
        );
        $furniture = (array) ($artifacts['furniture'] ?? []);

        $headerShipped = false;
        if (($furniture['header']['status'] ?? '') === 'pass' && $navLinks !== []) {
            $tree = $project->readJson('trees/furniture--header.json')['tree'];
            $tree['epoch'] = $epoch;
            // FLAT links, injected like placeholder urls: submenu nesting can
            // fail E_NEST_PARENT on instances whose navigation-link parent
            // list is ['core/navigation'] only.
            if ($this->injectNavLinks($tree['blocks'], $navLinks)) {
                $markup = $gate->compileMarkup($tree);
                if ($markup !== null) {
                    $headerShipped = (bool) ($client->publishTemplatePart('header', $markup)['written'] ?? false);
                }
            }
            Narrator::write($headerShipped
                ? "  header template part shipped from the design lane, nav links injected\n"
                : "  designed header could not ship — keeping the theme header and the nav post\n");
        }
        if (!$headerShipped && $navLinks !== []) {
            $navTree = ['version' => 1, 'epoch' => $epoch, 'blocks' => [[
                'name'        => 'core/navigation',
                'attributes'  => [],
                'innerBlocks' => $navLinks,
            ]]];
            $navCompiled = $gate->compile($navTree);
            if (isset($navCompiled['result']['markup'])) {
                // Strip ONLY the outer wrapper delimiters — first and last
                // LINE. A regex would also eat every navigation-link comment.
                $lines = explode("\n", (string) $navCompiled['result']['markup']);
                $inner = implode("\n", array_slice($lines, 1, max(0, count($lines) - 2)));
                $published['nav_id'] = (int) ($client->publishNavigation($inner)['id'] ?? 0);
            }
        }

        $footerShipped = false;
        if (($furniture['footer']['status'] ?? '') === 'pass') {
            $tree = $project->readJson('trees/furniture--footer.json')['tree'];
            $tree['epoch'] = $epoch;
            $markup = $gate->compileMarkup($tree);
            if ($markup !== null) {
                $footerShipped = (bool) ($client->publishTemplatePart('footer', $markup)['written'] ?? false);
            }
            if ($footerShipped) {
                Narrator::write("  footer template part shipped from the design lane — the brief's footer intent, built\n");
            }
        }
        $footerItems = (array) ($brief['footer']['items'] ?? []);
        if (!$footerShipped && ($footerItems !== [] || (string) ($brief['footer']['intent'] ?? '') !== '')) {
            $blocks = [[
                'name'        => 'core/group',
                'attributes'  => ['style' => ['spacing' => ['padding' => ['top' => 'var:preset|spacing|50', 'bottom' => 'var:preset|spacing|50']]]],
                'innerBlocks' => array_values(array_filter([
                    [
                        'name'        => 'core/paragraph',
                        'attributes'  => ['content' => ($brief['identity']['site_title'] ?? '') . ' — ' . ($brief['identity']['tagline'] ?? '')],
                        'innerBlocks' => [],
                    ],
                    $footerItems === [] ? null : [
                        'name'        => 'core/paragraph',
                        'attributes'  => ['content' => implode(' · ', array_map(
                            static fn (array $item): string => '<a href="/' . $item['page_slug'] . '/">' . $item['label'] . '</a>',
                            $footerItems,
                        ))],
                        'innerBlocks' => [],
                    ],
                ])),
            ]];
            $footerCompiled = $gate->compile(['version' => 1, 'epoch' => $epoch, 'blocks' => $blocks]);
            if (isset($footerCompiled['result']['markup'])) {
                $written = (bool) ($client->publishTemplatePart('footer', (string) $footerCompiled['result']['markup'])['written'] ?? false);
                if (!$written) {
                    Narrator::write("  this theme has no footer template part — footer skipped\n");
                }
            }
        }

        $project->writeJson('published.json', $published);
    }

    /**
     * Visit every core/image node carrying an imageIntent, by reference, with
     * the nearest ancestor backgroundColor slug (the image's band).
     *
     * @param array<int,array<string,mixed>> $blocks
     * @param callable(array<string,mixed>&, ?string): void $visit
     */
    private function walkImages(array &$blocks, callable $visit, ?string $bandSlug = null): void
    {
        foreach ($blocks as &$node) {
            if (!is_array($node)) {
                continue;
            }
            $band = $node['attributes']['backgroundColor'] ?? $bandSlug;
            if (($node['name'] ?? '') === 'core/image' && isset($node['attributes']['metadata']['imageIntent'])) {
                $visit($node, is_string($band) ? $band : null);
            }
            if (isset($node['innerBlocks']) && is_array($node['innerBlocks'])) {
                $this->walkImages($node['innerBlocks'], $visit, is_string($band) ? $band : null);
            }
        }
        unset($node);
    }

    /**
     * Replace the single bare core/navigation node's children with the flat
     * link list. True when a navigation node was found.
     *
     * @param array<int,array<string,mixed>> $blocks
     * @param array<int,array<string,mixed>> $navLinks
     */
    private function injectNavLinks(array &$blocks, array $navLinks): bool
    {
        foreach ($blocks as &$node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['name'] ?? '') === 'core/navigation') {
                $node['innerBlocks'] = $navLinks;
                return true;
            }
            if (isset($node['innerBlocks']) && is_array($node['innerBlocks']) && $this->injectNavLinks($node['innerBlocks'], $navLinks)) {
                return true;
            }
        }
        unset($node);
        return false;
    }
}
