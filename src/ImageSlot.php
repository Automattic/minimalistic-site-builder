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
        $heroAnchors = [];
        $plan = $project->exists('pages.json') ? $project->readJson('pages.json') : [];
        foreach ($plan['pages'] ?? [] as $page) {
            $sections = array_values($page['sections'] ?? []);
            foreach ($sections as $index => $section) {
                if (($section['role'] ?? SectionRole::forPosition($index, count($sections))) === SectionRole::HERO) {
                    $heroes['parts/' . SectionsStep::partSlug($page['slug'], $section['slug']) . '.html'] = true;
                    $heroAnchors['plugin/pages/' . $page['slug'] . '.html'][$section['slug']] = true;
                    if (!empty($page['front'])) {
                        $heroAnchors['theme/templates/front-page.html'][$section['slug']] = true;
                    }
                }
            }
        }
        $markup = [];
        if ($project->exists('plugin/pages.json')) {
            $markup = PreparedImageBatch::finalMarkup($project);
        } else {
            foreach (['theme/parts/*.html', 'theme/templates/*.html', 'plugin/pages/*.html'] as $pattern) {
                foreach (glob($project->root . '/' . $pattern) ?: [] as $file) {
                    $relative = substr($file, strlen($project->root) + 1);
                    $markup[$relative] = $project->readText($relative);
                }
            }
        }
        $documents = array_map(BlockMarkup::parse(...), $markup);
        foreach ($specs as &$spec) {
            $spec['hero_slot'] = array_intersect_key($heroes, array_flip($spec['sources'] ?? [])) !== [];
            $slot = null;
            foreach ($documents as $file => $doc) {
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
                        $candidate = $name === 'cover' ? 'cover' : (($attrs['align'] ?? '') === 'full' ? 'full-width' : 'image');
                        for ($parent = $index; $parent !== null; $parent = $doc->parent($parent)) {
                            $parentAttrs = $doc->attrs($parent) ?? [];
                            if (isset($heroAnchors[$file][$parentAttrs['anchor'] ?? ''])) {
                                $spec['hero_slot'] = true;
                            }
                            $classes = (string) ($parentAttrs['className'] ?? '');
                            if ($candidate === 'image'
                                && preg_match('/\b(?:card(?:-media(?:-tall)?)?|card-media-thumb|tile)\b/', $classes) === 1) {
                                $candidate = 'card';
                            }
                        }
                        // A shared asset must satisfy its largest slot.
                        if ($slot === null || in_array($candidate, ['cover', 'full-width'], true) || ($slot === 'card' && $candidate === 'image')) {
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
