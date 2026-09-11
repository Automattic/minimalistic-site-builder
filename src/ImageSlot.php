<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\Steps\SectionsStep;

/** Read image slots from block markup and hero roles from the page plan. */
final class ImageSlot
{
    public static function annotate(Project $project, array $specs): array
    {
        $heroes = [];
        $plan = $project->exists('pages.json') ? $project->readJson('pages.json') : [];
        foreach ($plan['pages'] ?? [] as $page) {
            $sections = array_values($page['sections'] ?? []);
            foreach ($sections as $index => $section) {
                if (($section['role'] ?? SectionRole::forPosition($index, count($sections))) === SectionRole::HERO) {
                    $heroes['parts/' . SectionsStep::partSlug($page['slug'], $section['slug']) . '.html'] = true;
                }
            }
        }
        $documents = [];
        foreach (['theme/parts/*.html', 'theme/templates/*.html', 'plugin/pages/*.html'] as $pattern) {
            foreach (glob($project->root . '/' . $pattern) ?: [] as $file) {
                $documents[] = BlockMarkup::parse((string) file_get_contents($file));
            }
        }
        foreach ($specs as &$spec) {
            $spec['hero_slot'] = array_intersect_key($heroes, array_flip($spec['sources'] ?? [])) !== [];
            $slot = null;
            foreach ($documents as $doc) {
                foreach ($doc->indices() as $index) {
                    $name = $doc->name($index);
                    if (!in_array($name, ['image', 'cover'], true)) {
                        continue;
                    }
                    $attrs = $doc->attrs($index) ?? [];
                    foreach (array_filter([$spec['src'] ?? '', $spec['url'] ?? '']) as $source) {
                        $matches = $name === 'cover'
                            ? ($attrs['url'] ?? '') === $source
                            : MediaReferenceRemoval::position($doc->innerHtml($index), $source) !== null;
                        if (!$matches) {
                            continue;
                        }
                        $candidate = $name === 'cover' ? 'cover' : 'image';
                        for ($parent = $index; $parent !== null; $parent = $doc->parent($parent)) {
                            $classes = (string) (($doc->attrs($parent) ?? [])['className'] ?? '');
                            if (preg_match('/\b(?:card(?:-media(?:-tall)?)?|card-media-thumb|tile)\b/', $classes) === 1) {
                                $candidate = 'card';
                                break;
                            }
                        }
                        // A shared asset must satisfy its largest slot.
                        if ($slot === null || $candidate === 'cover' || ($slot === 'card' && $candidate === 'image')) {
                            $slot = $candidate;
                        }
                    }
                }
            }
            if ($slot !== null) {
                $spec['image_slot'] = $slot;
            }
        }
        unset($spec);
        return $specs;
    }
}
