<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ImageClient;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Generate theme photographs on composed pages. Supplied pages, protected
 * blocks and customer assets keep their authored pixels. Failed generation
 * keeps the original source and records an actionable warning per occurrence.
 * The host owns publishing the files and importing destination attachments.
 */
final class GenerateMediaStep implements Step
{
    public const MEDIA_DIR = 'bundle/media';
    public const SOURCE_PREFIX = 'media/';
    public const MANIFEST = 'patterns/generated-media.json';

    public function __construct(private readonly ?ImageClient $images = null) {}

    public function id(): string { return 'generate-media'; }
    public function label(): string { return 'Generate photographs for composed pages'; }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(), label: $this->label(),
            reads: [PatternArtifacts::NORMALIZED, PatternArtifacts::PAGES],
            writes: [PatternArtifacts::PAGES, self::MANIFEST, self::MEDIA_DIR . '/*', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $inputs = $project->readJson(PatternArtifacts::NORMALIZED);
        NormalizeInputsStep::assertVersion($inputs);
        $records = $project->exists(self::MANIFEST) ? $project->readJson(self::MANIFEST) : [];
        $project->writeJsonAtomic(self::MANIFEST, $records);
        $policy = is_array($inputs['image_generation'] ?? null) ? $inputs['image_generation'] : [];
        if ($this->images === null || false === ($policy['enabled'] ?? true)) {
            return;
        }
        $pages = PatternArtifacts::pages($project);
        $wanted = [];
        $targets = [];
        // What the approved patterns ship with is stock, wherever it is hosted:
        // a pattern that hotlinks its photograph off-site is still offering
        // stock. An image the inventory never mentions came from the customer.
        $stock = self::stockSources((array) ($inputs['inventory'] ?? []));
        foreach ($pages as $page) {
            if (!empty($page['frozen']) || ($page['provenance'] ?? '') === 'blueprint') {
                continue;
            }
            $slug = (string) $page['slug'];
            $targets[$slug] = self::targets((string) ($page['content'] ?? ''), (string) ($inputs['theme'] ?? ''), $stock);
            foreach ($targets[$slug] as $target) {
                $key = substr(hash('sha256', $target['source']), 0, 24);
                $wanted[$key] ??= $target + ['pages' => []];
                $wanted[$key]['pages'][$slug] = true;
            }
        }
        $specs = [];
        $keys = [];
        $made = [];
        foreach ($wanted as $key => $want) {
            $source = self::SOURCE_PREFIX . $key . '.jpg';
            if (isset($records[$source]) && is_file($project->path('bundle/' . $source))) {
                $made[$want['source']] = $source;
                continue;
            }
            $keys[] = $key;
            $specs[] = [
                'prompt' => self::prompt($want, $inputs, $pages),
                'aspect_ratio' => $want['block'] === 'core/cover' ? '16:9' : '4:3',
                'mime' => 'image/jpeg', 'asset' => $key . '.jpg',
            ];
        }
        $errors = [];
        // Stream each result to disk so a batch does not retain every image.
        $consume = function (int $index, array $result) use ($project, $keys, $wanted, &$records, &$made, &$errors): void {
            $key = $keys[$index];
            $want = $wanted[$key];
            $bytes = (string) ($result['bytes'] ?? '');
            $info = $bytes !== '' ? @getimagesizefromstring($bytes) : false;
            if (empty($result['ok']) || $info === false || ($info['mime'] ?? '') !== 'image/jpeg') {
                $errors[$key] = (string) ($result['error'] ?? 'The provider returned no valid JPEG.');
                return;
            }
            $source = self::SOURCE_PREFIX . $key . '.jpg';
            $project->writeText('bundle/' . $source, $bytes);
            $records[$source] = [
                'source' => $source, 'file' => $source, 'role' => 'generated',
                'fallback' => $want['source'], 'alt' => $want['alt'],
            ];
            $project->writeJsonAtomic(self::MANIFEST, $records);
            $made[$want['source']] = $source;
        };
        if ($specs !== []) {
            // Only provider errors degrade. Filesystem failures in the callback
            // must still propagate, so collect them separately.
            $ioFailure = null;
            try {
                $results = $this->images->generateBatch($specs, function (int $i, array $result) use ($consume, &$ioFailure): void {
                    try { $consume($i, $result); } catch (\Throwable $e) { $ioFailure = $e; throw $e; }
                });
                foreach ($keys as $i => $key) {
                    if (!isset($made[$wanted[$key]['source']])) {
                        $errors[$key] ??= (string) ($results[$i]['error'] ?? 'The provider returned no result.');
                    }
                }
            } catch (\Throwable $e) {
                if ($ioFailure !== null) { throw $ioFailure; }
                foreach ($keys as $key) {
                    if (!isset($made[$wanted[$key]['source']])) { $errors[$key] = $e->getMessage(); }
                }
            }
        }
        $warnings = [];
        foreach ($pages as $page) {
            $slug = (string) $page['slug'];
            $content = (string) ($page['content'] ?? '');
            $document = BlockMarkup::parse($content);
            foreach (array_reverse($targets[$slug] ?? []) as $target) {
                $key = substr(hash('sha256', $target['source']), 0, 24);
                if (!isset($made[$target['source']])) {
                    $warnings[] = json_encode([
                        'file' => 'patterns/pages/' . $slug . '.json', 'block_path' => $target['index'],
                        'authored' => $target['source'], 'delivered' => $target['source'],
                        'disposition' => 'kept-original', 'reason' => $errors[$key] ?? 'No image result.',
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    continue;
                }
                $i = $target['index'];
                $attrs = $document->attrs($i) ?? [];
                $field = $target['block'] === 'core/media-text' ? 'mediaUrl' : 'url';
                if (($attrs[$field] ?? '') === $target['source']) {
                    $attrs[$field] = $made[$target['source']];
                    $document->setAttrs($i, $attrs);
                }
                $own = $document->ownHtml($i);
                $rewritten = self::replaceSource($own, $target['source'], $made[$target['source']]);
                // A theme's responsive sources refer to the old pixels.
                $rewritten = preg_replace_callback('/<img\b[^>]*>/i', static fn (array $tag): string =>
                    str_contains($tag[0], $made[$target['source']])
                        ? (preg_replace('/\s(?:srcset|sizes)=(["\']).*?\1/is', '', $tag[0]) ?? $tag[0])
                        : $tag[0], $rewritten) ?? $rewritten;
                $document->spliceOwnHtml($i, 0, strlen($own), $rewritten);
            }
            $content = $document->render();
            if ($content !== ($page['content'] ?? '')) {
                $page['content'] = $content;
                $project->writeJsonAtomic('patterns/pages/' . $slug . '.json', $page);
            }
        }
        // A replay of already-rewritten pages must not erase prior failures.
        if ($wanted !== []) { $project->replaceWarnings($this->id(), $warnings); }
    }

    /** Replace URL spellings in a single media block, preserving other bytes. */
    public static function replaceSource(string $markup, string $source, string $replacement): string
    {
        return strtr($markup, [
            str_replace('/', '\\/', $source) => str_replace('/', '\\/', $replacement),
            htmlspecialchars($source, ENT_QUOTES | ENT_HTML5, 'UTF-8') => htmlspecialchars($replacement, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $source => $replacement,
        ]);
    }

    /** Eligible, structurally safe media blocks, in document order. */
    /**
     * @param array<string, true> $stock Image sources the approved inventory ships, as a set.
     */
    public static function targets(string $markup, string $theme, array $stock = []): array
    {
        if ($theme === '') { return []; }
        $doc = BlockMarkup::parse($markup);
        $targets = [];
        foreach ($doc->indices() as $i) {
            $name = BlockText::coreName($doc->name($i));
            if (!in_array($name, ['core/image', 'core/cover', 'core/media-text'], true) || !$doc->isStructurallySafe($i)) { continue; }
            for ($parent = $i; $parent !== null; $parent = $doc->parent($parent)) {
                if (preg_match('/(?:^|\s)ai-ignore(?:\s|$)/', (string) ($doc->attrs($parent)['className'] ?? '')) || preg_match('/class=["\'][^"\']*\bai-ignore\b/', $doc->ownHtml($parent))) { continue 2; }
            }
            $start = $doc->openingOffset($i);
            $block = substr($markup, $start, $doc->endOffset($i) - $start);
            $attrs = $doc->attrs($i) ?? [];
            $source = (string) ($attrs[$name === 'core/media-text' ? 'mediaUrl' : 'url'] ?? '');
            $alt = (string) ($attrs['alt'] ?? '');
            if (preg_match('/<img\b[^>]*>/i', $doc->ownHtml($i), $tag)) {
                if (preg_match('/\ssrc=(["\'])(.*?)\1/i', $tag[0], $src)) { $source = html_entity_decode($src[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
                if (preg_match('/\salt=(["\'])(.*?)\1/i', $tag[0], $a)) { $alt = html_entity_decode($a[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
                if (preg_match('/\bai-ignore\b/', $tag[0])) { continue; }
            }
            if ($source === '' || (!str_contains((string) parse_url($source, PHP_URL_PATH), '/themes/' . $theme . '/') && !isset($stock[$source]))) { continue; }
            // Logos, icons and vector illustrations are intentional identity assets.
            if (!preg_match('/\.(?:jpe?g|png|webp)(?:[?#]|$)/i', $source) || preg_match('/(?:logo|icon|avatar)/i', basename((string) parse_url($source, PHP_URL_PATH)))) { continue; }
            $context = '';
            for ($parent = $doc->parent($i); $parent !== null && $context === ''; $parent = $doc->parent($parent)) {
                $context = trim(preg_replace('/\s+/', ' ', strip_tags($doc->innerHtml($parent))) ?? '');
            }
            $targets[] = ['context' => mb_substr($context, 0, 1000), 'source' => $source, 'alt' => $alt, 'block' => $name, 'index' => $i, 'offset' => $start, 'markup' => $block];
        }
        return $targets;
    }

    /**
     * What to ask for: the business, then the job this particular picture does.
     *
     * The alt text is the pattern author's own description of the picture's
     * role, which is a better brief than anything derivable from the markup.
     *
     * @param array{source:string, alt:string, block:string, pages:array<string,bool>} $want
     * @param array<string, mixed>                                                     $inputs
     * @param list<array<string, mixed>>                                               $pages
     */
    /**
     * Every image source the approved inventory references, as a set. Both the
     * block attribute and the `src` are read, because a pattern carries the
     * same photograph in both and either one is what a page ends up holding.
     *
     * @param list<array<string, mixed>> $inventory
     * @return array<string, true>
     */
    private static function stockSources(array $inventory): array
    {
        $found = [];
        foreach ($inventory as $pattern) {
            $markup = is_array($pattern) ? (string) ($pattern['content'] ?? '') : '';
            if ($markup === '') { continue; }
            $doc = BlockMarkup::parse($markup);
            foreach ($doc->indices() as $i) {
                $attrs = $doc->attrs($i) ?? [];
                foreach (['url', 'mediaUrl'] as $field) {
                    $url = trim((string) ($attrs[$field] ?? ''));
                    if ($url !== '') { $found[$url] = true; }
                }
            }
            if (preg_match_all('/\ssrc=(["\'])(.*?)\1/i', $markup, $m)) {
                foreach ($m[2] as $src) { $found[html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8')] = true; }
            }
        }

        return $found;
    }

    public static function prompt(array $want, array $inputs, array $pages): string
    {
        $facts = is_array($inputs['facts'] ?? null) ? $inputs['facts'] : [];
        $lines = [];

        $title = trim((string) ($inputs['site']['title'] ?? ''));
        $about = trim((string) ($facts['description'] ?? $facts['intent'] ?? ''));
        $lines[] = 'A photograph for the website of ' . ($title !== '' ? $title : 'a business') . '.';
        if ($about !== '') {
            $lines[] = 'The business: ' . $about;
        }

        $lines[] = 'Site brief: ' . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!empty($inputs['image_generation']['instructions'])) {
            $lines[] = 'Image direction from the Blueprint: ' . trim((string) $inputs['image_generation']['instructions']);
        }
        $palette = $inputs['brand']['config']['settings']['color']['palette'] ?? [];
        if ($palette !== []) { $lines[] = 'Use these brand colors as subtle photographic accents: ' . json_encode($palette); }

        $voice = trim((string) ($facts['brand_context'] ?? ''));
        if ($voice !== '') {
            $lines[] = 'Its character: ' . $voice;
        }

        $where = array_keys($want['pages']);
        $titles = [];
        foreach ($pages as $page) {
            if (in_array((string) $page['slug'], $where, true)) {
                $titles[] = (string) ($page['title'] ?? $page['slug']);
                $lines[] = 'Page context: ' . mb_substr(trim(strip_tags((string) ($page['content'] ?? ''))), 0, 1000);
            }
        }
        if ($titles !== []) {
            $lines[] = 'It appears on the ' . implode(' and ', $titles) . ' page.';
        }

        if (($want['context'] ?? '') !== '') {
            $lines[] = 'The section this photograph illustrates: ' . $want['context'];
        }

        if ($want['alt'] !== '') {
            $lines[] = 'What it should show: ' . $want['alt'];
        }

        $lines[] = 'core/cover' === $want['block']
            ? 'Composed as a wide banner with calm, uncluttered space across the middle, because headline text is laid over it.'
            : 'A single clear subject, photographed plainly.';
        $lines[] = 'A real photograph. No text, no lettering, no logos, no watermarks, and no user interface.';

        return implode("\n", $lines);
    }
}
