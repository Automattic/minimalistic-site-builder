<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

/**
 * The version 2 contract a host must meet before the pattern composition runs.
 *
 * Three seeds: the request, the approved inventory, and the Brand. Every rule
 * here exists because the alternative reports success over a wrong site: a
 * page that is both supplied and composed builds twice, a slot whose path
 * points nowhere edits nothing, a Brand preset with no name is dropped by the
 * destination's save filter and the apply reports success over an empty post.
 *
 * Every problem is reported together, so a host fixes its request in one pass
 * rather than one round trip per rule.
 */
final class HostRequest
{
    public const VERSION = 2;

    /** theme.json's own top-level keys. A Brand's config is a partial of one. */
    private const CONFIG_KEYS = ['settings', 'styles'];

    /** What a Brand record carries. Anything else is not a Brand field. */
    private const BRAND_KEYS = ['id', 'name', 'logo_url', 'context', 'config'];

    /** The preset lists a Brand may carry, as theme.json lays them out. */
    private const PRESET_LISTS = [
        'color' => ['palette', 'gradients', 'duotone'],
        'typography' => ['fontSizes', 'fontFamilies'],
        'spacing' => ['spacingSizes'],
    ];

    /**
     * Refuse a request that cannot produce a site, naming every reason.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $patterns The inventory seed, `{ patterns: [...] }`.
     * @param array<string, mixed> $brand
     * @throws \InvalidArgumentException
     */
    public static function validate(array $request, array $patterns, array $brand): void
    {
        $problems = self::problems($request, $patterns, $brand);
        if ($problems !== []) {
            throw new \InvalidArgumentException(
                "These inputs cannot produce a site:\n  - " . implode("\n  - ", $problems)
            );
        }
    }

    /**
     * Every reason the inputs cannot produce a site, or an empty list.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $patterns
     * @param array<string, mixed> $brand
     * @return list<string>
     */
    public static function problems(array $request, array $patterns, array $brand): array
    {
        $problems = [];

        $version = $request['version'] ?? null;
        if ($version !== self::VERSION) {
            $problems[] = sprintf(
                'request is version %s; this build reads version %d, where each page carries `intent` or `markup` and the Brand is a record with `config`',
                var_export($version, true),
                self::VERSION,
            );
        }

        if (trim((string) ($request['theme'] ?? '')) === '') {
            $problems[] = 'request names no theme, and content is composed for one';
        }

        if (trim((string) ($request['site']['title'] ?? '')) === '') {
            $problems[] = 'request names no site.title, and the destination needs one';
        }

        $pages = $request['pages'] ?? null;
        if (!is_array($pages) || !array_is_list($pages)) {
            $problems[] = 'request.pages must be a list of pages, each carrying `intent` or `markup`; an empty list lets the plan choose';
            $pages = [];
        }
        $composed = 0;
        $seenSlugs = [];
        $seenSlots = [];
        foreach ($pages as $index => $page) {
            $composed += self::pageProblems($page, (string) $index, $seenSlugs, $seenSlots, $problems) ? 1 : 0;
        }

        $inventory = self::inventory($patterns, $problems);
        if ($inventory === [] && ($pages === [] || $composed > 0)) {
            $problems[] = $pages === []
                ? 'the approved inventory is empty and no page is supplied, so there is nothing to compose from'
                : 'the approved inventory is empty, and a page with an intent needs one to compose from';
        }

        self::brandProblems($brand, $problems);

        return $problems;
    }

    /**
     * Whether a page composes (true) or is supplied (false), recording what is
     * wrong with it on the way.
     *
     * @param mixed                 $page
     * @param array<string, true>   $seenSlugs
     * @param array<string, true>   $seenSlots
     * @param list<string>          $problems
     */
    private static function pageProblems(mixed $page, string $index, array &$seenSlugs, array &$seenSlots, array &$problems): bool
    {
        if (!is_array($page)) {
            $problems[] = sprintf('page %s is not an object', $index);
            return false;
        }

        $slug = trim((string) ($page['slug'] ?? ''));
        $name = $slug !== '' ? $slug : 'page ' . $index;
        if ($slug === '') {
            $problems[] = sprintf('page %s has no slug', $index);
        } elseif (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            $problems[] = sprintf('page "%s" needs a lowercase URL-safe slug', $slug);
        } elseif (isset($seenSlugs[$slug])) {
            $problems[] = sprintf('page "%s" is listed twice', $slug);
        }
        $seenSlugs[$slug] = true;

        $hasIntent = trim((string) ($page['intent'] ?? '')) !== '';
        $hasMarkup = array_key_exists('markup', $page);

        if ($hasIntent === $hasMarkup) {
            $problems[] = sprintf(
                '%s must carry exactly one of `intent` (composed from the inventory) or `markup` (supplied as is)',
                $name,
            );
            return $hasIntent;
        }

        if ($hasIntent) {
            return true;
        }

        $markup = $page['markup'];
        if (!is_string($markup) || trim($markup) === '') {
            $problems[] = sprintf('%s supplies empty markup', $name);
            return false;
        }

        if (!array_key_exists('slots', $page)) {
            return false;
        }

        $slots = $page['slots'];
        if (!is_array($slots) || !array_is_list($slots)) {
            $problems[] = sprintf('%s: slots must be a list; omit the key to discover slots, send [] to freeze the page', $name);
            return false;
        }

        foreach ($slots as $slot) {
            $id = is_array($slot) ? (string) ($slot['id'] ?? '') : '';
            if ($id !== '' && isset($seenSlots[$id])) {
                $problems[] = sprintf('%s: slot id "%s" is used by another page; slot ids are unique across the request', $name, $id);
            }
            $seenSlots[$id] = true;
        }

        try {
            new DeclaredSlots($markup, $slots);
        } catch (\InvalidArgumentException $e) {
            $problems[] = sprintf('%s: %s', $name, $e->getMessage());
        }

        return false;
    }

    /**
     * The inventory reduced to what composition reads, with each entry that
     * cannot be read recorded rather than skipped. Skipping it would shrink
     * the vocabulary without saying so, and the build would fail later at
     * whichever section happened to need it.
     *
     * @param array<string, mixed> $patterns
     * @param list<string>         $problems
     * @return list<array<string, mixed>>
     */
    public static function inventory(array $patterns, array &$problems): array
    {
        $inventory = [];
        $seen = [];

        foreach ($patterns['patterns'] ?? [] as $index => $pattern) {
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
            $inventory[] = [
                'id' => $id,
                'categories' => self::strings($pattern['categories'] ?? []),
                'content' => $content,
            ];
        }

        return $inventory;
    }

    /**
     * @param array<string, mixed> $brand
     * @param list<string>         $problems
     */
    private static function brandProblems(array $brand, array &$problems): void
    {
        foreach (array_keys($brand) as $key) {
            if (!in_array($key, self::BRAND_KEYS, true)) {
                $problems[] = sprintf(
                    'Brand carries "%s", which is not a Brand field; theme.json values go under `config`',
                    (string) $key,
                );
            }
        }

        $config = $brand['config'] ?? [];
        if (!is_array($config)) {
            $problems[] = 'Brand config must be a theme.json partial shaped { settings, styles }';
            return;
        }

        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::CONFIG_KEYS, true)) {
                $problems[] = sprintf(
                    'Brand config carries "%s", which is not a theme.json key and would be dropped without a word',
                    (string) $key,
                );
            }
        }

        // A preset without a name is a preset a destination drops. theme.json
        // requires one, and the save filter a site running Gutenberg applies to
        // global styles rejects each nameless preset before it persists.
        foreach (self::presets($config) as $path => $entries) {
            foreach ($entries as $index => $entry) {
                foreach (['slug', 'name'] as $field) {
                    if (!is_array($entry) || trim((string) ($entry[$field] ?? '')) === '') {
                        $problems[] = sprintf('Brand config %s[%d] has no %s, which theme.json requires', $path, $index, $field);
                    }
                }
            }
        }
    }

    /**
     * Every preset entry the config carries, keyed by where it sits.
     *
     * A theme writes a preset list flat; a user layer keys it by origin. Both
     * shapes reach here, and an entry is an entry either way.
     *
     * @param array<string, mixed> $config
     * @return array<string, list<mixed>>
     */
    private static function presets(array $config): array
    {
        $found = [];
        foreach (self::PRESET_LISTS as $group => $lists) {
            foreach ($lists as $list) {
                $value = $config['settings'][$group][$list] ?? null;
                if (!is_array($value)) {
                    continue;
                }
                $entries = array_is_list($value) ? $value : array_merge(...array_values(array_filter($value, 'is_array')) ?: [[]]);
                $found['settings.' . $group . '.' . $list] = array_values($entries);
            }
        }

        return $found;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    public static function strings(mixed $value): array
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
