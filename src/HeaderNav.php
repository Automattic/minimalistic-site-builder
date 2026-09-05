<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Header and footer chrome must not include a Home item on either graph.
 * Header chrome must also list every inner site page (BIGR-872).
 *
 * `wp:site-title` and `wp:site-logo` already link to the front page. A Home
 * entry — `wp:home-link`, a `wp:navigation-link` whose label is the front
 * page title, a `wp:page-list` (which always renders every page including
 * Home), or an HTML `<a>` with that label in the chrome part — is redundant.
 * HTML-first designs often put that item in a flex row rather than a `<nav>`
 * landmark; the strip covers both.
 *
 * Callers pass the site's pages so the front page title is language-accurate
 * and a replaced page-list can keep the inner pages. The site name is never
 * treated as a Home label (the wordmark may share the front page's title).
 * Idempotent.
 */
final class HeaderNav
{
    /**
     * The row an inserted navigation shares with the identity it follows. Only
     * used when the identity's own container stacks its children — a flex
     * parent already puts identity, navigation and an optional CTA side by side.
     */
    private const ROW_OPEN = '<!-- wp:group {"layout":{"type":"flex","orientation":"horizontal",'
        . '"justifyContent":"space-between","verticalAlignment":"center","flexWrap":"nowrap"}} -->'
        . '<div class="wp-block-group">';

    /** One start-side brand unit when generated identity leaves are siblings. */
    private const IDENTITY_OPEN = '<!-- wp:group {"layout":{"type":"flex","orientation":"horizontal",'
        . '"verticalAlignment":"center","flexWrap":"nowrap"}} --><div class="wp-block-group">';

    private const GROUP_CLOSE = '</div><!-- /wp:group -->';

    /** Header archetypes whose contract is one identity/navigation row. */
    private const SINGLE_ROW_ARCHETYPES = ['standard-row', 'branded-lockup', 'minimal-overlay', 'floating-pill', 'bar-center-cta'];

    /** The class the trusted header kit styles as the detached pill. */
    public const PILL_ROW_CLASS = 'header-pill';

    /** The kit hook for the centered bar's identity/navigation/CTA row (frm W1b). */
    public const BAR_CENTER_ROW_CLASS = 'header-bar-center';

    /**
     * @param list<array<string,mixed>> $pages
     * @param 'header'|'footer' $part
     * @return array{markup:string,notes:list<string>,warnings:list<string>}
     */
    public static function withoutHomeItems(
        string $markup,
        array $pages,
        string $part = 'header',
        string $siteName = '',
    ): array {
        $front = self::frontPage($pages);
        $notes = [];
        $warnings = [];

        [$markup, $blockNotes, $blockWarnings] = self::withoutHomeBlocks(
            $markup,
            $front,
            $pages,
            $part,
            $siteName,
        );
        array_push($notes, ...$blockNotes);
        array_push($warnings, ...$blockWarnings);

        [$markup, $htmlNotes] = self::withoutHtmlHomeAnchors($markup, $front, $part, $siteName);
        array_push($notes, ...$htmlNotes);

        return [
            'markup' => $markup,
            'notes' => $notes,
            'warnings' => $warnings,
        ];
    }

    /**
     * Every inner site page must appear in the header nav (BIGR-872).
     *
     * Footer chrome is out of scope: it is often already the more complete
     * list, and this pass exists to bring the header up to that bar. A
     * one-page site has nothing to add. Idempotent.
     *
     * @param list<array<string,mixed>> $pages
     * @param 'header'|'footer' $part
     * @return array{markup:string,notes:list<string>,warnings:list<string>}
     */
    public static function withCompleteInnerPages(
        string $markup,
        array $pages,
        string $part = 'header',
        string $siteName = '',
    ): array {
        if ($part !== 'header') {
            return ['markup' => $markup, 'notes' => [], 'warnings' => []];
        }

        $front = self::frontPage($pages);
        $needed = [];
        foreach ($pages as $page) {
            if (!empty($page['front']) || self::pagePath($page) === '/') {
                continue;
            }
            $title = trim((string) ($page['title'] ?? $page['label'] ?? ''));
            $path = trim((string) ($page['path'] ?? ''));
            if ($title === '' || $path === '' || self::labelsMatch($title, $front['title'])) {
                continue;
            }
            $key = self::destinationKey($path);
            if ($key === '' || $key === '/') {
                continue;
            }
            $needed[$key] = ['title' => $title, 'path' => $path];
        }
        if ($needed === []) {
            return ['markup' => $markup, 'notes' => [], 'warnings' => []];
        }

        $unprovenRawNav = self::unprovenRawNavOpening($markup);
        if ($unprovenRawNav !== null) {
            return [
                'markup' => $markup,
                'notes' => [],
                'warnings' => [self::unprovenRawNavWarning($part, $unprovenRawNav)],
            ];
        }

        $document = BlockMarkup::parse($markup);
        // An unprovable navigation is left exactly as authored. Filling around
        // it would splice a second wp:navigation beside the broken one — two
        // navs, a duplicated destination, and one hamburger each on mobile.
        foreach ($document->indices() as $index) {
            if (self::canonicalName($document->name($index)) === 'navigation'
                && !$document->isStructurallySafe($index)
            ) {
                return [
                    'markup' => $markup,
                    'notes' => [],
                    'warnings' => [
                        self::unprovenWarning($part, 'wp:navigation', $document->openingComment($index)),
                    ],
                ];
            }
        }

        [$markup, $labelNotes] = self::withExactInnerPageLabels($markup, $document, $needed);
        if ($labelNotes !== []) {
            $document = BlockMarkup::parse($markup);
        }

        $navIndices = [];
        foreach ($document->indices() as $index) {
            if (self::canonicalName($document->name($index)) !== 'navigation') {
                continue;
            }
            $navIndices[] = $index;
        }

        $present = $navIndices === []
            ? self::htmlNavDestinations($markup)
            : self::blockNavDestinations($document);
        $missing = [];
        foreach ($needed as $key => $page) {
            // A matching label does not prove reachability: the authored link
            // may point at a different page. Prefer a duplicate label over an
            // omitted required destination.
            if (isset($present[$key])) {
                continue;
            }
            $missing[] = $page;
        }
        if ($missing === []) {
            return ['markup' => $markup, 'notes' => $labelNotes, 'warnings' => []];
        }

        $titles = array_map(static fn (array $page): string => $page['title'], $missing);
        $labelList = implode(', ', $titles);

        if ($navIndices !== []) {
            // Spread across every nav in document order. split-nav carries two,
            // and dropping the whole remainder into the last one leaves the
            // lopsided halves that archetype exists to avoid.
            $chunks = self::partitionLinksByLoad(
                array_map(static fn (array $page): string => self::linkComments([$page]), $missing),
                array_map(
                    static fn (int $index): int => self::navigationItemCount($document, $index),
                    $navIndices,
                ),
            );
            $edits = [];
            foreach ($navIndices as $slot => $index) {
                $chunk = $chunks[$slot] ?? [];
                if ($chunk === []) {
                    continue;
                }
                $edit = self::blockSpan($document, $index, $markup);
                if ($edit === null) {
                    return [
                        'markup' => $markup,
                        'notes' => $labelNotes,
                        'warnings' => [
                            self::unprovenWarning($part, 'wp:navigation', $document->openingComment($index)),
                        ],
                    ];
                }
                $opening = $document->isVoid($index)
                    ? BlockMarkup::serializeComment('navigation', $document->attrs($index) ?? [], false)
                    : $document->openingComment($index);
                $inner = $document->isVoid($index) ? '' : $document->innerHtml($index);
                $edits[] = $edit + [
                    'replacement' => $opening . $inner . implode('', $chunk) . '<!-- /wp:navigation -->',
                ];
            }
            usort($edits, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
            foreach ($edits as $edit) {
                $markup = substr_replace(
                    $markup,
                    $edit['replacement'],
                    $edit['start'],
                    $edit['end'] - $edit['start'],
                );
            }
            return [
                'markup' => $markup,
                'notes' => [
                    ...$labelNotes,
                    "added missing inner-page links to header navigation ({$labelList})",
                ],
                'warnings' => [],
            ];
        }

        $htmlNavs = self::navElements($markup);
        if ($htmlNavs !== []) {
            $target = $htmlNavs[count($htmlNavs) - 1];
            $inner = substr($markup, $target['innerStart'], $target['innerEnd'] - $target['innerStart']);
            // When the nav delegates its items to a list, the new links are
            // list items too: a bare anchor beside the `<ul>` escapes every
            // `nav ul li a` rule the design authored.
            if (preg_match('~</ul\s*>\s*\z~i', $inner, $close) === 1) {
                $markup = substr_replace(
                    $markup,
                    self::htmlListItems($missing),
                    $target['innerEnd'] - strlen($close[0]),
                    0,
                );
            } else {
                $markup = substr_replace($markup, self::htmlAnchors($missing), $target['innerEnd'], 0);
            }
            return [
                'markup' => $markup,
                'notes' => [
                    ...$labelNotes,
                    "added missing inner-page links to header navigation ({$labelList})",
                ],
                'warnings' => [],
            ];
        }

        $identity = self::lastIdentityIndex($document);
        $navMarkup = '<!-- wp:navigation -->' . self::linkComments($missing) . '<!-- /wp:navigation -->';
        if ($identity === null) {
            return [
                'markup' => $markup . $navMarkup,
                'notes' => [
                    ...$labelNotes,
                    "inserted header navigation with inner-page links ({$labelList})",
                ],
                'warnings' => [],
            ];
        }

        $cluster = self::identityCluster($document);
        if ($cluster === null) {
            return [
                'markup' => $markup,
                'notes' => $labelNotes,
                'warnings' => [self::unprovenIdentityWarning($part, $markup)],
            ];
        }
        // Identity and navigation share one row. A constrained (or default)
        // container stacks its children, so dropping the nav in as a sibling
        // would ship the wordmark-above-nav masthead this pass exists to
        // remove — wrap the pair in a flex row instead.
        $note = "inserted header navigation with inner-page links ({$labelList})";
        $identityMarkup = substr($markup, $cluster['start'], $cluster['end'] - $cluster['start']);
        if (count($cluster['units']) > 1) {
            $identityMarkup = self::IDENTITY_OPEN . $identityMarkup . self::GROUP_CLOSE;
        }
        if (!self::laysOutAsRow($document, $cluster['units'][0])) {
            $replacement = self::ROW_OPEN . $identityMarkup . $navMarkup . self::GROUP_CLOSE;
            $note .= ' in a single row with the identity';
        } else {
            $replacement = $identityMarkup . $navMarkup;
        }
        $markup = substr_replace(
            $markup,
            $replacement,
            $cluster['start'],
            $cluster['end'] - $cluster['start'],
        );
        return [
            'markup' => $markup,
            'notes' => [...$labelNotes, $note],
            'warnings' => [],
        ];
    }

    /**
     * Mark the identity/navigation row of a floating-pill header with the
     * kit's pill class. The generated markup is asked to carry it; when the
     * model forgets, the row is still provable (the lowest group holding
     * both the identity and the one navigation), so the class is restored
     * deterministically instead of shipping a pill with no pill.
     *
     * @return array{markup:string,notes:list<string>,warnings:list<string>}
     */
    public static function withPillRow(string $markup): array
    {
        return self::withRowClass($markup, self::PILL_ROW_CLASS, 'floating-pill', 'pill');
    }

    /**
     * The centered bar's counterpart (frm W1b): the kit turns the marked row
     * into a three-column grid (identity, navigation, CTA).
     *
     * @return array{markup:string,notes:list<string>,warnings:list<string>}
     */
    public static function withBarCenterRow(string $markup): array
    {
        return self::withRowClass($markup, self::BAR_CENTER_ROW_CLASS, 'bar-center-cta', 'centered bar');
    }

    /**
     * @return array{markup:string,notes:list<string>,warnings:list<string>}
     */
    private static function withRowClass(string $markup, string $class, string $archetype, string $noun): array
    {
        if (preg_match('/(?:^|[\s"])' . $class . '(?:$|[\s"])/', $markup) === 1) {
            return ['markup' => $markup, 'notes' => [], 'warnings' => []];
        }
        $document = BlockMarkup::parse($markup);
        $cluster = self::identityCluster($document);
        $navigations = array_values(array_filter(
            $document->indices(),
            static fn (int $index): bool => self::canonicalName($document->name($index)) === 'navigation'
                && $document->isStructurallySafe($index),
        ));
        if ($cluster === null || count($navigations) !== 1) {
            return [
                'markup' => $markup,
                'notes' => [],
                'warnings' => ["file='theme/parts/header.html'; block='{$archetype} row'; authored=no provable"
                    . " identity/navigation row; delivered=unchanged; disposition=the {$noun} class was not restored"
                    . ' because the row could not be proven, so the header renders as a plain bar'],
            ];
        }
        $row = self::lowestCommonGroup($document, $cluster['units'][0], $navigations[0]);
        if ($row === null || $document->parent($row) === null) {
            // The root group is the shell's surface owner; the pill needs an
            // inner row of its own. withSingleRowForArchetype wraps one when
            // the identity and navigation are bare root children, so this
            // only remains reachable for shapes that pass proved unwrapped.
            return [
                'markup' => $markup,
                'notes' => [],
                'warnings' => ["file='theme/parts/header.html'; block='{$archetype} row'; authored=identity"
                    . " and navigation share only the root group; delivered=unchanged; disposition=the {$noun}"
                    . " class was not restored because the root cannot be the {$noun}"],
            ];
        }
        $attrs = $document->attrs($row) ?? [];
        $tokens = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens[] = $class;
        $attrs['className'] = implode(' ', array_values(array_unique($tokens)));
        $document->setAttrs($row, $attrs);
        if (preg_match('/^\s*<div class="wp-block-group(?=[" ])/', $document->ownHtml($row)) === 1) {
            $document->replaceInOwnHtml($row, 'class="wp-block-group', 'class="wp-block-group ' . $class);
        }
        return [
            'markup' => $document->render(),
            'notes' => [$archetype . ': restored the ' . $class . ' class on the identity/navigation row'],
            'warnings' => [],
        ];
    }

    /**
     * Normalize the delivered relation for archetypes whose identity, one
     * navigation and optional CTA must share a horizontal row. Completeness
     * and layout are separate facts: a nav can contain every page and still
     * sit below the wordmark in a constrained container.
     *
     * @return array{markup:string,notes:list<string>,warnings:list<string>}
     */
    public static function withSingleRowForArchetype(string $markup, string $archetype): array
    {
        if (!in_array($archetype, self::SINGLE_ROW_ARCHETYPES, true)) {
            return ['markup' => $markup, 'notes' => [], 'warnings' => []];
        }

        $resolve = static function (BlockMarkup $document): ?array {
            $cluster = self::identityCluster($document);
            $navigations = array_values(array_filter(
                $document->indices(),
                static fn (int $index): bool => self::canonicalName($document->name($index)) === 'navigation'
                    && $document->isStructurallySafe($index),
            ));
            if ($cluster === null || count($navigations) !== 1) {
                return null;
            }
            $navigation = $navigations[0];
            $parent = self::lowestCommonGroup($document, $cluster['units'][0], $navigation);
            if ($parent === null) {
                return null;
            }
            $identityUnits = [];
            foreach ($cluster['units'] as $identity) {
                $unit = self::childUnder($document, $identity, $parent);
                if ($unit === null) {
                    return null;
                }
                $identityUnits[$unit] = true;
            }
            $identityUnits = array_map('intval', array_keys($identityUnits));
            usort(
                $identityUnits,
                static fn (int $left, int $right): int => $document->openingOffset($left)
                    <=> $document->openingOffset($right),
            );
            $navigationUnit = self::childUnder($document, $navigation, $parent);
            if ($navigationUnit === null || in_array($navigationUnit, $identityUnits, true)) {
                return null;
            }
            $siblings = $document->children($parent);
            $positions = array_map(
                static fn (int $unit): int|false => array_search($unit, $siblings, true),
                $identityUnits,
            );
            if (in_array(false, $positions, true)) {
                return null;
            }
            $first = $positions[0];
            $last = $positions[count($positions) - 1];
            if (!is_int($first)
                || !is_int($last)
                || array_slice($siblings, $first, $last - $first + 1) !== $identityUnits
            ) {
                return null;
            }
            return [$identityUnits, $navigationUnit, $parent];
        };

        $document = BlockMarkup::parse($markup);
        $context = $resolve($document);
        if ($context === null) {
            return ['markup' => $markup, 'notes' => [], 'warnings' => []];
        }
        $authoredMarkup = $markup;
        [$identityUnits, $navigationUnit, $parent] = $context;
        if (count($identityUnits) > 1) {
            $identityStart = $document->openingOffset($identityUnits[0]);
            $identityEnd = $document->endOffset($identityUnits[count($identityUnits) - 1]);
            if ($identityEnd === null) {
                return [
                    'markup' => $markup,
                    'notes' => [],
                    'warnings' => [self::unprovenRowWarning($markup)],
                ];
            }
            $identityMarkup = substr($markup, $identityStart, $identityEnd - $identityStart);
            $markup = substr_replace(
                $markup,
                self::IDENTITY_OPEN . $identityMarkup . self::GROUP_CLOSE,
                $identityStart,
                $identityEnd - $identityStart,
            );
            $document = BlockMarkup::parse($markup);
            $context = $resolve($document);
            if ($context === null) {
                return [
                    'markup' => $authoredMarkup,
                    'notes' => [],
                    'warnings' => [self::unprovenRowWarning($authoredMarkup)],
                ];
            }
            [$identityUnits, $navigationUnit, $parent] = $context;
        }

        $identityUnit = $identityUnits[0];
        $reordered = false;
        if ($document->openingOffset($navigationUnit) < $document->openingOffset($identityUnit)) {
            $navigationEnd = $document->endOffset($navigationUnit);
            $identityEnd = $document->endOffset($identityUnit);
            if ($navigationEnd === null
                || $identityEnd === null
                || $navigationEnd > $document->openingOffset($identityUnit)
            ) {
                return [
                    'markup' => $markup,
                    'notes' => [],
                    'warnings' => [self::unprovenRowWarning($markup)],
                ];
            }
            $navigationStart = $document->openingOffset($navigationUnit);
            $identityStart = $document->openingOffset($identityUnit);
            $navigationMarkup = substr($markup, $navigationStart, $navigationEnd - $navigationStart);
            $between = substr($markup, $navigationEnd, $identityStart - $navigationEnd);
            if (trim($between) !== '') {
                return [
                    'markup' => $markup,
                    'notes' => [],
                    'warnings' => [self::unprovenRowWarning($markup)],
                ];
            }
            $identityMarkup = substr($markup, $identityStart, $identityEnd - $identityStart);
            $markup = substr_replace(
                $markup,
                $identityMarkup . $between . $navigationMarkup,
                $navigationStart,
                $identityEnd - $navigationStart,
            );
            $reordered = true;
            $document = BlockMarkup::parse($markup);
            $context = $resolve($document);
            if ($context === null) {
                return [
                    'markup' => $authoredMarkup,
                    'notes' => [],
                    'warnings' => [self::unprovenRowWarning($authoredMarkup)],
                ];
            }
            [$identityUnits, $navigationUnit, $parent] = $context;
            $identityUnit = $identityUnits[0];
        }

        $siblings = $document->children($parent);
        $identityPosition = array_search($identityUnit, $siblings, true);
        $navigationPosition = array_search($navigationUnit, $siblings, true);
        if (!is_int($identityPosition)
            || !is_int($navigationPosition)
            || $identityPosition >= $navigationPosition
        ) {
            return [
                'markup' => $authoredMarkup,
                'notes' => [],
                'warnings' => [self::unprovenRowWarning($authoredMarkup)],
            ];
        }
        foreach ($siblings as $position => $sibling) {
            if ($sibling === $identityUnit || $sibling === $navigationUnit) {
                continue;
            }
            if (self::canonicalName($document->name($sibling)) !== 'buttons'
                || $position <= $navigationPosition
            ) {
                return [
                    'markup' => $authoredMarkup,
                    'notes' => [],
                    'warnings' => [self::unprovenRowWarning($authoredMarkup)],
                ];
            }
        }
        for ($position = 1; $position < count($siblings); $position++) {
            $previousEnd = $document->endOffset($siblings[$position - 1]);
            if ($previousEnd === null
                || trim(substr(
                    $markup,
                    $previousEnd,
                    $document->openingOffset($siblings[$position]) - $previousEnd,
                )) !== ''
            ) {
                return [
                    'markup' => $authoredMarkup,
                    'notes' => [],
                    'warnings' => [self::unprovenRowWarning($authoredMarkup)],
                ];
            }
        }

        $top = $document->topLevel();
        if ($parent !== $top) {
            $attrs = $document->attrs($parent) ?? [];
            $layout = is_array($attrs['layout'] ?? null) ? $attrs['layout'] : [];
            $wanted = $layout;
            $wanted['type'] = 'flex';
            $wanted['orientation'] = 'horizontal';
            $wanted['flexWrap'] = 'nowrap';
            $wanted['justifyContent'] = 'space-between';
            $wanted['verticalAlignment'] = 'center';
            if ($wanted === $layout) {
                return [
                    'markup' => $markup,
                    'notes' => $reordered ? ['reordered header identity before navigation'] : [],
                    'warnings' => [],
                ];
            }
            $attrs['layout'] = $wanted;
            $document->setAttrs($parent, $attrs);
            return [
                'markup' => $document->render(),
                'notes' => ['repaired complete header navigation into one row with the identity'],
                'warnings' => [],
            ];
        }

        $start = $document->openingOffset($identityUnit);
        $end = $document->endOffset($siblings[count($siblings) - 1]);
        if ($end === null) {
            return ['markup' => $markup, 'notes' => [], 'warnings' => []];
        }
        $markup = substr_replace($markup, '</div><!-- /wp:group -->', $end, 0);
        $markup = substr_replace($markup, self::ROW_OPEN, $start, 0);
        return [
            'markup' => $markup,
            'notes' => ['repaired complete header navigation into one row with the identity'],
            'warnings' => [],
        ];
    }

    /**
     * @param list<array<string,mixed>> $pages
     * @return array{title:string,path:string,slug:string}
     */
    private static function frontPage(array $pages): array
    {
        foreach ($pages as $page) {
            if (!empty($page['front'])) {
                return self::pageIdentity($page);
            }
        }
        foreach ($pages as $page) {
            if (self::pagePath($page) === '/') {
                return self::pageIdentity($page);
            }
        }
        return ['title' => 'Home', 'path' => '/', 'slug' => 'home'];
    }

    /** @param array<string,mixed> $page */
    private static function pageIdentity(array $page): array
    {
        $title = trim((string) ($page['title'] ?? $page['label'] ?? ''));
        $slug = trim((string) ($page['slug'] ?? ''));
        $path = self::pagePath($page);
        return [
            'title' => $title !== '' ? $title : 'Home',
            'path' => $path !== '' ? $path : '/',
            'slug' => $slug !== '' ? $slug : 'home',
        ];
    }

    /** @param array<string,mixed> $page */
    private static function pagePath(array $page): string
    {
        $path = trim((string) ($page['path'] ?? ''));
        return $path === '' ? '' : ($path === '/' ? '/' : rtrim($path, '/'));
    }

    /**
     * @param array{title:string,path:string,slug:string} $front
     * @param list<array<string,mixed>> $pages
     * @param 'header'|'footer' $part
     * @return array{0:string,1:list<string>,2:list<string>}
     */
    private static function withoutHomeBlocks(
        string $markup,
        array $front,
        array $pages,
        string $part,
        string $siteName,
    ): array {
        $document = BlockMarkup::parse($markup);
        $edits = [];
        $pageLists = [];
        $notes = [];
        $warnings = [];
        $nav = self::navPhrase($part);

        foreach ($document->indices() as $index) {
            $name = self::canonicalName($document->name($index));
            if ($name === 'home-link') {
                $edit = self::blockSpan($document, $index, $markup);
                if ($edit === null) {
                    $warnings[] = self::unprovenWarning($part, 'wp:home-link', $document->openingComment($index));
                    continue;
                }
                $edits[] = $edit + [
                    'replacement' => '',
                    'note' => "removed wp:home-link from {$nav} (the site title already links home)",
                ];
                continue;
            }
            if (($name === 'navigation-link' || $name === 'navigation-submenu')
                && self::isHomeNavigationLink($document->attrs($index) ?? [], $front, $siteName)
            ) {
                $edit = self::blockSpan($document, $index, $markup);
                if ($edit === null) {
                    $warnings[] = self::unprovenWarning($part, 'wp:' . $name, $document->openingComment($index));
                    continue;
                }
                $children = $document->children($index);
                if ($children !== [] && !$document->isVoid($index)) {
                    $edits[] = $edit + [
                        'replacement' => $document->innerHtml($index),
                        'note' => "unwrapped Home {$name} in {$nav}; kept nested destinations",
                    ];
                    continue;
                }
                $edits[] = $edit + [
                    'replacement' => '',
                    'note' => 'removed navigation-link "' . $front['title']
                        . "\" from {$nav} (the site title already links home)",
                ];
                continue;
            }
            if ($name === 'page-list' && $pages !== []) {
                $edit = self::blockSpan($document, $index, $markup);
                if ($edit === null) {
                    $warnings[] = self::unprovenWarning($part, 'wp:page-list', $document->openingComment($index));
                    continue;
                }
                $pageLists[] = $edit;
            }
        }

        $inner = self::innerPageLinkComments($pages, $front);
        $chunks = self::partitionLinks($inner, max(1, count($pageLists)));
        foreach ($pageLists as $i => $edit) {
            $replacement = implode('', $chunks[$i] ?? []);
            $edits[] = $edit + [
                'replacement' => $replacement,
                'note' => $replacement === ''
                    ? "removed wp:page-list from {$nav} (it would render a self-referential Home link)"
                    : "replaced wp:page-list in {$nav} with inner-page links (page-list always includes the front page)",
            ];
        }

        $edits = self::outermostEdits($edits);
        usort($edits, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($edits as $edit) {
            $markup = substr_replace(
                $markup,
                $edit['replacement'],
                $edit['start'],
                $edit['end'] - $edit['start'],
            );
            $notes[] = $edit['note'];
        }

        return [$markup, $notes, $warnings];
    }

    /**
     * @param list<array<string,mixed>> $pages
     * @param array{title:string,path:string,slug:string} $front
     * @return list<string>
     */
    private static function innerPageLinkComments(array $pages, array $front): array
    {
        $links = [];
        foreach ($pages as $page) {
            if (!empty($page['front']) || self::pagePath($page) === '/') {
                continue;
            }
            $title = trim((string) ($page['title'] ?? $page['label'] ?? ''));
            $path = trim((string) ($page['path'] ?? ''));
            if ($title === '' || $path === '' || self::labelsMatch($title, $front['title'])) {
                continue;
            }
            $links[] = BlockMarkup::serializeComment(
                'navigation-link',
                ['label' => $title, 'url' => $path, 'kind' => 'custom'],
                true,
            );
        }
        return $links;
    }

    /**
     * @param list<array{title:string,path:string}> $pages
     */
    private static function linkComments(array $pages): string
    {
        $links = [];
        foreach ($pages as $page) {
            $links[] = BlockMarkup::serializeComment(
                'navigation-link',
                ['label' => $page['title'], 'url' => $page['path'], 'kind' => 'custom'],
                true,
            );
        }
        return implode('', $links);
    }

    /**
     * @param list<array{title:string,path:string}> $pages
     */
    private static function htmlAnchors(array $pages): string
    {
        $html = '';
        foreach ($pages as $page) {
            $html .= '<a href="' . htmlspecialchars($page['path'], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">'
                . htmlspecialchars($page['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</a>';
        }
        return $html;
    }

    /**
     * The same anchors wrapped as list items, for a nav that keeps its links
     * in a `<ul>`.
     *
     * @param list<array{title:string,path:string}> $pages
     */
    private static function htmlListItems(array $pages): string
    {
        $html = '';
        foreach ($pages as $page) {
            $html .= '<li>' . self::htmlAnchors([$page]) . '</li>';
        }
        return $html;
    }

    /**
     * Correct the visible label of a proven required-page destination. A URL
     * alone is not a complete navigation item: `Home` pointing at `/about/`
     * reaches the page but still hides About from visitors.
     *
     * @param array<string,array{title:string,path:string}> $needed
     * @return array{0:string,1:list<string>}
     */
    private static function withExactInnerPageLabels(
        string $markup,
        BlockMarkup $document,
        array $needed,
    ): array {
        $notes = [];
        foreach ($document->indices() as $index) {
            $name = self::canonicalName($document->name($index));
            if (!in_array($name, ['navigation-link', 'navigation-submenu'], true)
                || !self::hasNavigationAncestor($document, $index)
            ) {
                continue;
            }
            $attrs = $document->attrs($index) ?? [];
            $url = is_string($attrs['url'] ?? null) ? self::destinationKey($attrs['url']) : '';
            if (!isset($needed[$url])) {
                continue;
            }
            $label = is_string($attrs['label'] ?? null) ? $attrs['label'] : '';
            $title = $needed[$url]['title'];
            if (self::visibleLabel($label) === self::visibleLabel($title)) {
                continue;
            }
            $attrs['label'] = $title;
            $document->setAttrs($index, $attrs);
            $notes[] = "corrected inner-page navigation label for {$title}";
        }
        if ($notes !== []) {
            $markup = $document->render();
        }

        $edits = [];
        foreach (self::navElements($markup) as $nav) {
            foreach (self::anchorsIn($markup, $nav['innerStart'], $nav['innerEnd']) as $anchor) {
                $key = self::destinationKey($anchor['href']);
                if (!isset($needed[$key])) {
                    continue;
                }
                $title = $needed[$key]['title'];
                $authored = self::visibleLabel(substr(
                    $markup,
                    $anchor['innerStart'],
                    $anchor['innerEnd'] - $anchor['innerStart'],
                ));
                if ($authored === self::visibleLabel($title)) {
                    continue;
                }
                $edits[] = [
                    'start' => $anchor['innerStart'],
                    'end' => $anchor['innerEnd'],
                    'replacement' => htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
                $notes[] = "corrected inner-page navigation label for {$title}";
            }
        }
        usort($edits, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($edits as $edit) {
            $markup = substr_replace(
                $markup,
                $edit['replacement'],
                $edit['start'],
                $edit['end'] - $edit['start'],
            );
        }
        return [$markup, array_values(array_unique($notes))];
    }

    /** @return array<string,true> */
    private static function blockNavDestinations(BlockMarkup $document): array
    {
        $destinations = [];
        foreach ($document->indices() as $index) {
            $name = self::canonicalName($document->name($index));
            if (($name === 'navigation-link' || $name === 'navigation-submenu')
                && self::hasNavigationAncestor($document, $index)
            ) {
                $attrs = $document->attrs($index) ?? [];
                $url = is_string($attrs['url'] ?? null) ? self::destinationKey((string) $attrs['url']) : '';
                if ($url !== '' && $url !== '/') {
                    $destinations[$url] = true;
                }
            }
            if ($name !== 'navigation' || $document->isVoid($index)) {
                continue;
            }
            $inner = $document->innerHtml($index);
            foreach (self::anchorsIn($inner, 0, strlen($inner)) as $anchor) {
                $url = self::destinationKey($anchor['href']);
                if ($url === '' || $url === '/') {
                    continue;
                }
                $destinations[$url] = true;
            }
        }
        return $destinations;
    }

    /** @return array<string,true> */
    private static function htmlNavDestinations(string $markup): array
    {
        $destinations = [];
        foreach (self::navElements($markup) as $nav) {
            foreach (self::anchorsIn($markup, $nav['innerStart'], $nav['innerEnd']) as $anchor) {
                $url = self::destinationKey($anchor['href']);
                if ($url === '' || $url === '/') {
                    continue;
                }
                $destinations[$url] = true;
            }
        }
        return $destinations;
    }

    /**
     * @return list<array{start:int,end:int,innerStart:int,innerEnd:int}>
     */
    private static function navElements(string $markup): array
    {
        if (preg_match_all('/<nav\b[^>]*>/i', $markup, $opens, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $navs = [];
        foreach ($opens[0] as [$open, $start]) {
            $innerStart = $start + strlen($open);
            $close = self::matchingClose($markup, $innerStart, 'nav');
            if ($close === null) {
                continue;
            }
            $closeEnd = strpos($markup, '>', $close);
            if ($closeEnd === false) {
                continue;
            }
            $navs[] = [
                'start' => $start,
                'end' => $closeEnd + 1,
                'innerStart' => $innerStart,
                'innerEnd' => $close,
            ];
        }
        return $navs;
    }

    /** The first authored raw nav whose closing boundary cannot be proven. */
    private static function unprovenRawNavOpening(string $markup): ?string
    {
        $masked = preg_replace_callback(
            '~<!--[\s\S]*?-->|<(script|style)\b[^>]*>[\s\S]*?</\1\s*>~i',
            static fn (array $match): string => str_repeat(' ', strlen($match[0])),
            $markup,
        );
        if (!is_string($masked)
            || preg_match_all('/<nav\b[^>]*>/i', $masked, $opens, PREG_OFFSET_CAPTURE) === false
        ) {
            return null;
        }
        foreach ($opens[0] as [$open, $start]) {
            $innerStart = $start + strlen($open);
            $close = self::matchingClose($masked, $innerStart, 'nav');
            if ($close === null || strpos($masked, '>', $close) === false) {
                return substr($markup, $start, strlen($open));
            }
        }
        return null;
    }

    /**
     * The provably internal page path a link resolves to, or '' when it
     * resolves to none. An absolute or protocol-relative URL may share an
     * internal path while leading to another site, so it never proves that
     * the local page is reachable.
     *
     * A fragment-only href addresses the current document, never a page, so it
     * must not read as one: `#hero` is not the About page.
     */
    private static function destinationKey(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#')) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return '';
        }
        $path = is_string($parts['path'] ?? null) ? $parts['path'] : strtok($url, '?#');
        $normalized = trim((string) $path);
        if ($normalized === '' || $normalized === '/') {
            return '/';
        }
        return rtrim($normalized, '/');
    }

    /** The last provable identity block: the nav is inserted after it. */
    private static function lastIdentityIndex(BlockMarkup $document): ?int
    {
        $last = null;
        foreach ($document->indices() as $index) {
            $name = self::canonicalName($document->name($index));
            if (!in_array($name, ['site-title', 'site-logo', 'site-tagline'], true)) {
                continue;
            }
            if (!$document->isStructurallySafe($index)) {
                continue;
            }
            $last = $index;
        }
        return $last;
    }

    private static function hasNavigationAncestor(BlockMarkup $document, int $index): bool
    {
        while (($index = $document->parent($index)) !== null) {
            if (self::canonicalName($document->name($index)) === 'navigation') {
                return true;
            }
        }
        return false;
    }

    private static function lowestCommonGroup(BlockMarkup $document, int $left, int $right): ?int
    {
        $rightAncestors = [];
        for ($index = $right; $index !== null; $index = $document->parent($index)) {
            $rightAncestors[$index] = true;
        }
        for ($index = $left; $index !== null; $index = $document->parent($index)) {
            if (isset($rightAncestors[$index])
                && self::canonicalName($document->name($index)) === 'group'
            ) {
                return $index;
            }
        }
        return null;
    }

    private static function childUnder(BlockMarkup $document, int $index, int $ancestor): ?int
    {
        while (($parent = $document->parent($index)) !== null) {
            if ($parent === $ancestor) {
                return $index;
            }
            $index = $parent;
        }
        return null;
    }

    /**
     * Promote the last identity leaf to its complete lockup. A fallback header
     * may nest title and tagline in a constrained Group, or nest that pair
     * beside a logo. Navigation belongs beside the whole unit in the header
     * row, never between those identity pieces.
     */
    private static function identityUnitIndex(BlockMarkup $document, int $identity): int
    {
        $unit = $identity;
        while (($parent = $document->parent($unit)) !== null) {
            if (self::isHeaderRowContainer($document, $parent)) {
                break;
            }
            // Keep the root containment intact. If no row exists, the caller
            // wraps this highest nested identity unit and the nav inside it.
            if ($document->parent($parent) === null || !self::containsOnlyIdentity($document, $parent)) {
                break;
            }
            $unit = $parent;
        }
        return $unit;
    }

    /**
     * Every provable identity leaf, promoted to contiguous sibling units under
     * one parent. Multiple units are wrapped together before navigation is
     * inserted, so a logo cannot be stranded outside the title/tagline row.
     *
     * @return array{units:list<int>,start:int,end:int}|null
     */
    private static function identityCluster(BlockMarkup $document): ?array
    {
        $units = [];
        foreach ($document->indices() as $index) {
            if (!in_array(
                self::canonicalName($document->name($index)),
                ['site-title', 'site-logo', 'site-tagline'],
                true,
            ) || !$document->isStructurallySafe($index)) {
                continue;
            }
            $unit = self::identityUnitIndex($document, $index);
            if (!$document->isStructurallySafe($unit)) {
                return null;
            }
            $units[$unit] = true;
        }
        $units = array_map('intval', array_keys($units));
        if ($units === []) {
            return null;
        }

        $parent = $document->parent($units[0]);
        foreach ($units as $unit) {
            if ($document->parent($unit) !== $parent) {
                return null;
            }
        }
        $siblings = $parent === null
            ? array_values(array_filter(
                $document->indices(),
                static fn (int $index): bool => $document->parent($index) === null,
            ))
            : $document->children($parent);
        $positions = [];
        foreach ($units as $unit) {
            $position = array_search($unit, $siblings, true);
            if ($position === false) {
                return null;
            }
            $positions[$unit] = $position;
        }
        uasort($positions, static fn (int $left, int $right): int => $left <=> $right);
        $units = array_map('intval', array_keys($positions));
        $firstPosition = reset($positions);
        $lastPosition = end($positions);
        if (!is_int($firstPosition)
            || !is_int($lastPosition)
            || array_slice($siblings, $firstPosition, $lastPosition - $firstPosition + 1) !== $units
        ) {
            return null;
        }
        for ($offset = 1; $offset < count($units); $offset++) {
            $previousEnd = $document->endOffset($units[$offset - 1]);
            if ($previousEnd === null
                || trim(substr(
                    $document->render(),
                    $previousEnd,
                    $document->openingOffset($units[$offset]) - $previousEnd,
                )) !== ''
            ) {
                return null;
            }
        }
        $end = $document->endOffset($units[count($units) - 1]);
        if ($end === null) {
            return null;
        }
        return [
            'units' => $units,
            'start' => $document->openingOffset($units[0]),
            'end' => $end,
        ];
    }

    private static function isHeaderRowContainer(BlockMarkup $document, int $index): bool
    {
        if (self::canonicalName($document->name($index)) !== 'group') {
            return false;
        }
        $attrs = $document->attrs($index) ?? [];
        $layout = $attrs['layout'] ?? null;
        if (!is_array($layout) || ($layout['type'] ?? '') !== 'flex') {
            return false;
        }
        if (($layout['orientation'] ?? 'horizontal') === 'vertical') {
            return false;
        }
        return ($attrs['align'] ?? '') === 'wide'
            || ($layout['justifyContent'] ?? '') === 'space-between';
    }

    private static function containsOnlyIdentity(BlockMarkup $document, int $index): bool
    {
        if (self::canonicalName($document->name($index)) !== 'group') {
            return false;
        }
        $children = $document->children($index);
        if ($children === []) {
            return false;
        }
        foreach ($children as $child) {
            $name = self::canonicalName($document->name($child));
            if (in_array($name, ['site-title', 'site-logo', 'site-tagline'], true)) {
                continue;
            }
            if ($name !== 'group' || !self::containsOnlyIdentity($document, $child)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Does the identity block's own container already lay its children out as
     * a horizontal row? Only a flex layout does; `constrained`, `default` and
     * an absent layout all stack, which would put an inserted nav on its own
     * line under the wordmark.
     */
    private static function laysOutAsRow(BlockMarkup $document, int $index): bool
    {
        $parent = $document->parent($index);
        if ($parent === null) {
            return false;
        }
        $layout = ($document->attrs($parent) ?? [])['layout'] ?? null;
        if (!is_array($layout) || ($layout['type'] ?? '') !== 'flex') {
            return false;
        }
        return ($layout['orientation'] ?? 'horizontal') !== 'vertical';
    }

    /** Number of visible items already occupying one navigation row. */
    private static function navigationItemCount(BlockMarkup $document, int $navigation): int
    {
        $count = 0;
        foreach ($document->children($navigation) as $child) {
            if (in_array(
                self::canonicalName($document->name($child)),
                ['navigation-link', 'navigation-submenu'],
                true,
            )) {
                $count++;
            }
        }
        if ($count > 0 || $document->isVoid($navigation)) {
            return $count;
        }

        $inner = $document->innerHtml($navigation);
        return count(self::anchorsIn($inner, 0, strlen($inner)));
    }

    /**
     * Add links to the least-populated navigation in stable document order.
     *
     * @param list<string> $links
     * @param list<int> $loads
     * @return list<list<string>>
     */
    private static function partitionLinksByLoad(array $links, array $loads): array
    {
        if ($loads === []) {
            return [];
        }
        $chunks = array_fill(0, count($loads), []);
        foreach ($links as $link) {
            $slot = 0;
            foreach ($loads as $candidate => $load) {
                if ($load < $loads[$slot]) {
                    $slot = $candidate;
                }
            }
            $chunks[$slot][] = $link;
            $loads[$slot]++;
        }
        return $chunks;
    }

    /**
     * Split inner-page links across N page-list slots in document order
     * (split-nav's two navs each get a slice, not a full copy).
     *
     * @param list<string> $links
     * @return list<list<string>>
     */
    private static function partitionLinks(array $links, int $slots): array
    {
        if ($slots <= 1) {
            return [$links];
        }
        if ($links === []) {
            return array_fill(0, $slots, []);
        }
        $size = (int) ceil(count($links) / $slots);
        $chunks = array_values(array_chunk($links, max(1, $size)));
        while (count($chunks) < $slots) {
            $chunks[] = [];
        }
        return $chunks;
    }

    /**
     * Keep the outermost splice when a parent and a nested child both matched.
     * Inner edits run first (higher start) and would stale the parent's end.
     *
     * @param list<array{start:int,end:int,replacement:string,note:string}> $edits
     * @return list<array{start:int,end:int,replacement:string,note:string}>
     */
    private static function outermostEdits(array $edits): array
    {
        $kept = [];
        foreach ($edits as $edit) {
            foreach ($edits as $other) {
                if ($other === $edit) {
                    continue;
                }
                $contains = $other['start'] <= $edit['start'] && $other['end'] >= $edit['end'];
                $strict = $other['start'] < $edit['start'] || $other['end'] > $edit['end'];
                if ($contains && $strict) {
                    continue 2;
                }
            }
            $kept[] = $edit;
        }
        return $kept;
    }

    /** @return array{start:int,end:int}|null */
    private static function blockSpan(BlockMarkup $document, int $index, string $markup): ?array
    {
        if (!$document->isStructurallySafe($index)) {
            return null;
        }
        $start = $document->openingOffset($index);
        $end = $document->endOffset($index);
        if ($end === null) {
            return null;
        }
        while ($end < strlen($markup) && str_contains(" \t\n\r", $markup[$end])) {
            $next = $end + 1;
            if ($next < strlen($markup) && $markup[$next] === '<') {
                break;
            }
            $end++;
        }
        return ['start' => $start, 'end' => $end];
    }

    /** @param array<string,mixed> $attrs @param array{title:string,path:string,slug:string} $front */
    private static function isHomeNavigationLink(array $attrs, array $front, string $siteName): bool
    {
        $label = is_string($attrs['label'] ?? null) ? $attrs['label'] : '';
        if ($siteName !== '' && self::labelsMatch($label, $siteName)) {
            return false;
        }
        if (!self::labelsMatch($label, $front['title'])) {
            return false;
        }
        $url = is_string($attrs['url'] ?? null) ? $attrs['url'] : '';
        return self::isHomeUrl($url, $front);
    }

    /**
     * @param array{title:string,path:string,slug:string} $front
     * @param 'header'|'footer' $part
     * @return array{0:string,1:list<string>}
     */
    private static function withoutHtmlHomeAnchors(
        string $markup,
        array $front,
        string $part,
        string $siteName,
    ): array {
        $notes = [];
        $edits = [];
        $nav = self::navPhrase($part);
        $protected = self::identityRanges($markup);
        foreach (self::anchorsIn($markup, 0, strlen($markup)) as $anchor) {
            if (self::offsetIsProtected($anchor['start'], $protected)) {
                continue;
            }
            $label = self::visibleLabel(substr(
                $markup,
                $anchor['innerStart'],
                $anchor['innerEnd'] - $anchor['innerStart'],
            ));
            if ($siteName !== '' && self::labelsMatch($label, $siteName)) {
                continue;
            }
            if (!self::labelsMatch($label, $front['title'])) {
                continue;
            }
            if (!self::isHomeUrl($anchor['href'], $front)) {
                continue;
            }
            $edits[] = self::expandWrapper($markup, $anchor['start'], $anchor['end']);
        }
        if ($edits === []) {
            return [$markup, $notes];
        }
        usort($edits, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        $kept = [];
        foreach ($edits as $edit) {
            foreach ($kept as $prior) {
                if ($edit['start'] < $prior['end'] && $edit['end'] > $prior['start']) {
                    continue 2;
                }
            }
            $kept[] = $edit;
            $markup = substr_replace($markup, '', $edit['start'], $edit['end'] - $edit['start']);
            $notes[] = "removed HTML Home link from {$nav} (the site title already links home)";
        }
        return [$markup, $notes];
    }

    /**
     * Byte ranges of dynamic identity blocks whose homepage link must survive.
     *
     * @return list<array{0:int,1:int}>
     */
    private static function identityRanges(string $markup): array
    {
        $document = BlockMarkup::parse($markup);
        $ranges = [];
        foreach ($document->indices() as $index) {
            $name = self::canonicalName($document->name($index));
            if (!in_array($name, ['site-title', 'site-logo'], true)) {
                continue;
            }
            $end = $document->endOffset($index);
            if ($end === null) {
                continue;
            }
            $ranges[] = [$document->openingOffset($index), $end];
        }
        return $ranges;
    }

    /** @param list<array{0:int,1:int}> $ranges */
    private static function offsetIsProtected(int $offset, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($offset >= $start && $offset < $end) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<array{start:int,end:int,innerStart:int,innerEnd:int,href:string}>
     */
    private static function anchorsIn(string $markup, int $from, int $to): array
    {
        $slice = substr($markup, $from, $to - $from);
        if (preg_match_all('/<a\b[^>]*>/i', $slice, $opens, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $anchors = [];
        foreach ($opens[0] as [$open, $relative]) {
            $start = $from + $relative;
            $innerStart = $start + strlen($open);
            $selfClosing = preg_match('/\/\s*>\z/', $open) === 1;
            if ($selfClosing) {
                continue;
            }
            $close = self::matchingClose($markup, $innerStart, 'a');
            if ($close === null || $close > $to) {
                continue;
            }
            $closeEnd = strpos($markup, '>', $close);
            if ($closeEnd === false || $closeEnd + 1 > $to) {
                continue;
            }
            $anchors[] = [
                'start' => $start,
                'end' => $closeEnd + 1,
                'innerStart' => $innerStart,
                'innerEnd' => $close,
                'href' => self::anchorHref($open),
            ];
        }
        return $anchors;
    }

    private static function matchingClose(string $markup, int $from, string $name): ?int
    {
        $needle = '</' . $name;
        $offset = $from;
        $depth = 1;
        $length = strlen($markup);
        while ($offset < $length) {
            $nextOpen = stripos($markup, '<' . $name, $offset);
            $nextClose = stripos($markup, $needle, $offset);
            if ($nextClose === false) {
                return null;
            }
            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $after = $nextOpen + 1 + strlen($name);
                if ($after < $length && preg_match('/\A[\s\/>]/', $markup[$after]) !== 1) {
                    $offset = $after;
                    continue;
                }
                $depth++;
                $offset = $after;
                continue;
            }
            $after = $nextClose + strlen($needle);
            if ($after < $length && preg_match('/\A[\s>]/', $markup[$after]) !== 1) {
                $offset = $after;
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return $nextClose;
            }
            $offset = $after;
        }
        return null;
    }

    /** @return array{start:int,end:int} */
    private static function expandWrapper(string $markup, int $start, int $end): array
    {
        foreach (['li', 'p'] as $tag) {
            $prefix = substr($markup, 0, $start);
            if (preg_match('/<' . $tag . '\b[^>]*>\s*\z/i', $prefix, $open) !== 1) {
                continue;
            }
            $suffix = substr($markup, $end);
            if (preg_match('/\A\s*<\/' . $tag . '>/i', $suffix, $close) !== 1) {
                continue;
            }
            return [
                'start' => strlen($prefix) - strlen($open[0]),
                'end' => $end + strlen($close[0]),
            ];
        }
        return ['start' => $start, 'end' => $end];
    }

    private static function visibleLabel(string $html): string
    {
        $text = PlainText::fromMarkup($html);
        $collapsed = preg_replace('/\s+/u', ' ', trim($text));
        return $collapsed ?? trim($text);
    }

    private static function anchorHref(string $openTag): string
    {
        if (preg_match('/\bhref\s*=\s*(["\'])(.*?)\\1/i', $openTag, $match) === 1) {
            return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('/\bhref\s*=\s*([^\s>]+)/i', $openTag, $match) === 1) {
            return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    /** @param array{title:string,path:string,slug:string} $front */
    private static function isHomeUrl(string $url, array $front): bool
    {
        $url = trim($url);
        if ($url === '' || $url === '#' || $url === '/') {
            return true;
        }
        if (str_starts_with($url, '#')) {
            return false;
        }
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        if (is_string($fragment) && $fragment !== '') {
            return false;
        }
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : strtok($url, '?#');
        $normalized = trim((string) $path);
        $normalized = $normalized === '' || $normalized === '/' ? '/' : rtrim($normalized, '/');
        if ($normalized === '/') {
            return true;
        }
        $frontPath = $front['path'] === '/' ? '/' : rtrim($front['path'], '/');
        if ($normalized === $frontPath) {
            return true;
        }
        $slug = $front['slug'];
        return $slug !== '' && $normalized === '/' . $slug;
    }

    private static function labelsMatch(string $left, string $right): bool
    {
        return self::normalizedLabel($left) !== ''
            && self::normalizedLabel($left) === self::normalizedLabel($right);
    }

    private static function normalizedLabel(string $label): string
    {
        $decoded = PlainText::fromMarkup($label);
        $words = preg_replace('/[^\p{L}\p{N}]+/u', ' ', trim($decoded));
        $collapsed = preg_replace('/\s+/u', ' ', trim($words ?? $decoded));
        return mb_strtolower($collapsed ?? trim($decoded), 'UTF-8');
    }

    private static function canonicalName(string $name): string
    {
        return str_starts_with($name, 'core/') ? substr($name, 5) : $name;
    }

    /** @param 'header'|'footer' $part */
    private static function navPhrase(string $part): string
    {
        return $part === 'footer' ? 'footer navigation' : 'header navigation';
    }

    /** @param 'header'|'footer' $part */
    private static function unprovenWarning(string $part, string $block, string $authored): string
    {
        return "file='theme/parts/{$part}.html'; block='{$block}'; authored="
            . Warnings::value($authored)
            . '; delivered=unchanged; disposition=left the block untouched because its delimiter '
            . 'boundary could not be proven; removing it would risk an unmatched closing comment';
    }

    /** @param 'header'|'footer' $part */
    private static function unprovenRawNavWarning(string $part, string $authored): string
    {
        return "file='theme/parts/{$part}.html'; block='nav'; authored="
            . Warnings::value($authored)
            . '; delivered=unchanged; disposition=left the raw navigation untouched because its matching '
            . 'closing tag could not be proven; inserting another navigation would duplicate the header control';
    }

    /** @param 'header'|'footer' $part */
    private static function unprovenIdentityWarning(string $part, string $authored): string
    {
        return "file='theme/parts/{$part}.html'; block='identity lockup'; authored="
            . Warnings::value($authored)
            . '; delivered=unchanged; disposition=left the header untouched because all logo, title, and tagline '
            . 'pieces could not be proven as one contiguous identity unit; inserting navigation could split the brand';
    }

    private static function unprovenRowWarning(string $authored): string
    {
        return "file='theme/parts/header.html'; block='identity/navigation row'; authored="
            . Warnings::value($authored)
            . '; delivered=unchanged; disposition=left the row untouched because the identity and navigation byte '
            . 'boundaries could not be proven for deterministic source-order repair';
    }
}
