<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\LinkTargets;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\SectionPattern;
use Automattic\SiteBuild\SectionRole;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Warnings;

/**
 * Extract reusable theme patterns from the assembled generated pages.
 *
 * Generated-content defects are isolated to one page or section and recorded
 * as warnings. Required-input and filesystem failures remain fatal because no
 * truthful pattern output can be produced from missing artifacts or failed
 * writes.
 */
final class ExtractPatternsStep implements Step
{
    private const LOG_FILE = 'extract-patterns.log';
    private const SECTION_PATTERN_CAP = 10;
    private const COMPONENT_SOURCE_CAP = 6;
    private const BAND_CTA_EXEMPT_LABELS = ['cta', 'closing', 'contact'];

    /** @var array<string,string> normalized labels that map to core pattern categories */
    private const CORE_CATEGORIES = [
        'cta' => 'call-to-action',
        'service' => 'services',
        'testimonial' => 'testimonials',
        'about' => 'about',
        'contact' => 'contact',
        'gallery' => 'gallery',
        'team' => 'team',
        'portfolio' => 'portfolio',
        'hero' => 'banner',
        'banner' => 'banner',
        'text' => 'text',
        'featured' => 'featured',
    ];

    public function id(): string
    {
        return 'extract-patterns';
    }

    public function label(): string
    {
        return 'Extract theme patterns';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                'plugin/pages/*',
                'plugin/pages.json',
                'pages.json',
                'theme/style.css',
                'theme/theme.json',
            ],
            writes: ['theme/patterns/*', 'patterns.json', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $this->deletePatternDirectory($project);

        $plan = $project->readJson('pages.json');
        $pageManifest = $project->readJson('plugin/pages.json');
        $css = $project->readText('theme/style.css');
        // Validate this declared, required upstream artifact even though pattern
        // headers and markup do not otherwise need to inspect its values.
        $project->readJson('theme/theme.json');

        $planPages = $this->pagesBySlug($plan['pages'] ?? []);
        $manifestPages = is_array($pageManifest['pages'] ?? null) ? $pageManifest['pages'] : [];
        $routes = $this->routeSet($manifestPages, $planPages);
        $registeredBlocks = $this->registeredPluginBlocks($project);
        $warnings = [];
        if (
            $registeredBlocks === []
            && $project->exists(ScaffoldPluginStep::MAIN_FILE)
            && $project->readText(ScaffoldPluginStep::MAIN_FILE) !== ''
        ) {
            $warnings[] = 'plugin/site-content.php: block path "registration array"; authored value "non-empty plugin '
                . 'file"; delivered value "no registered companion blocks"; disposition: continued with companion '
                . 'blocks treated as unregistered';
        }
        $cssIds = self::idSelectorsIn($css);

        /** @var array<string,list<array<mixed>>> $sectionCandidatesByKey */
        $sectionCandidatesByKey = [];
        /** @var array<string,int> $sectionIneligibleByKey */
        $sectionIneligibleByKey = [];
        /** @var array<string,list<array<mixed>>> $componentCandidatesByKey */
        $componentCandidatesByKey = [];
        /** @var array<string,int> $componentIneligibleByKey */
        $componentIneligibleByKey = [];
        $log = [];

        foreach ($manifestPages as $manifestPage) {
            if (!is_array($manifestPage)) {
                continue;
            }
            $pageSlug = (string) ($manifestPage['slug'] ?? '');
            if ($pageSlug === '') {
                $warnings[] = 'plugin/pages.json: page entry has no slug; authored entry retained outside pattern library; '
                    . 'delivered no patterns; disposition: skipped unaddressable page entry';
                continue;
            }

            $relative = "plugin/pages/{$pageSlug}.html";
            if (!$project->exists($relative)) {
                $warnings[] = "{$relative}: authored page listed in plugin/pages.json; delivered no patterns; "
                    . 'disposition: skipped because required page markup is absent';
                continue;
            }
            $plannedPage = $planPages[$pageSlug] ?? null;
            if (!is_array($plannedPage)) {
                $warnings[] = "{$relative}: authored page has no matching pages.json plan; delivered no patterns; "
                    . 'disposition: skipped because section boundaries are unprovable';
                continue;
            }
            $plannedSections = is_array($plannedPage['sections'] ?? null) ? $plannedPage['sections'] : [];
            $pageMarkup = $project->readText($relative);

            try {
                $pageDocument = BlockMarkup::parse($pageMarkup);
            } catch (\Throwable $error) {
                $warnings[] = "{$relative}: authored page markup could not be parsed ({$error->getMessage()}); "
                    . 'delivered no patterns from this page; disposition: kept page bytes and skipped extraction';
                $log[] = "page {$pageSlug}: parse failed: {$error->getMessage()}";
                continue;
            }
            if (
                $pageDocument->hasMalformedDelimiters()
                || $pageDocument->hasMismatchedDelimiters()
                || $pageDocument->unclosedIndices() !== []
            ) {
                $warnings[] = "{$relative}: authored page has a structural block-delimiter defect; delivered no patterns "
                    . 'from this page; disposition: kept page bytes and skipped whole page';
                $log[] = "page {$pageSlug}: structural delimiter defect; skipped whole page";
                continue;
            }

            try {
                $split = SectionPattern::split($pageMarkup, $plannedSections);
            } catch (\Throwable $error) {
                $warnings[] = "{$relative}: authored page markup could not be split ({$error->getMessage()}); "
                    . 'delivered no patterns from this page; disposition: kept page bytes and skipped extraction';
                $log[] = "page {$pageSlug}: split failed: {$error->getMessage()}";
                continue;
            }

            foreach ($split['warnings'] as $warning) {
                $warnings[] = "{$relative}: {$warning} delivered page bytes unchanged; disposition: extraction degraded";
                $log[] = "page {$pageSlug}: {$warning}";
            }
            if ($split['sections'] === []) {
                $warnings[] = "{$relative}: authored page yielded zero safe sections; delivered no patterns from this page; "
                    . 'disposition: kept page bytes and skipped extraction';
                continue;
            }

            $plannedCount = count($plannedSections);
            foreach ($split['sections'] as $section) {
                $index = (int) ($section['index'] ?? -1);
                $planSection = $plannedSections[$index] ?? null;
                if (!is_array($planSection) || $index < 0 || $index >= $plannedCount) {
                    $warnings[] = "{$relative}: block path /{$index}; authored section lacks a matching plan entry; "
                        . 'delivered no pattern; disposition: skipped unclassifiable section';
                    continue;
                }

                $markup = (string) ($section['markup'] ?? '');
                $sectionSlug = (string) ($planSection['slug'] ?? $section['slug'] ?? "section-{$index}");
                try {
                    $classification = $this->classify($markup, $planSection, $index, $plannedCount);
                } catch (\Throwable $error) {
                    $warnings[] = "{$relative}: block path /{$index}, section '{$sectionSlug}'; authored markup could not "
                        . "be classified ({$error->getMessage()}); delivered no pattern; disposition: skipped section";
                    $log[] = "candidate {$pageSlug}/{$sectionSlug}: classification failed: {$error->getMessage()}";
                    continue;
                }

                $key = $classification['key'];
                $componentKey = in_array($classification['shape'], ['grid', 'quotes'], true)
                    ? (is_string($classification['label']) && $classification['label'] !== ''
                        ? $classification['label']
                        : $key)
                    : null;
                $components = null;
                if ($componentKey !== null) {
                    try {
                        $components = self::componentMarkup($markup, $classification['shape']);
                    } catch (\Throwable $error) {
                        $componentIneligibleByKey[$componentKey] = ($componentIneligibleByKey[$componentKey] ?? 0) + 1;
                        $warnings[] = "{$relative}: block path /{$index}, section '{$sectionSlug}'; component boundary "
                            . "could not be isolated ({$error->getMessage()}); delivered no component patterns; "
                            . 'disposition: kept section candidate and skipped component source';
                        $log[] = "component candidate {$pageSlug}/{$sectionSlug} key {$componentKey}: extraction failed: "
                            . $error->getMessage();
                    }
                }

                try {
                    $score = SectionPattern::score($markup, $routes);
                    $contains = self::contains($markup);
                } catch (\Throwable $error) {
                    $sectionIneligibleByKey[$key] = ($sectionIneligibleByKey[$key] ?? 0) + 1;
                    $warnings[] = "{$relative}: block path /{$index}, section '{$sectionSlug}'; authored candidate could "
                        . "not be scored ({$error->getMessage()}); delivered removed from pattern candidates; "
                        . 'disposition: skipped failed candidate';
                    $log[] = "candidate {$pageSlug}/{$sectionSlug} key {$key}: score failed: {$error->getMessage()}";
                    if ($components !== null && $componentKey !== null) {
                        $componentIneligibleByKey[$componentKey] = ($componentIneligibleByKey[$componentKey] ?? 0) + 1;
                        $warnings[] = "{$relative}: block path /{$index}, section '{$sectionSlug}'; component source "
                            . "could not be scored ({$error->getMessage()}); delivered no component pair; "
                            . 'disposition: skipped failed component source';
                        $log[] = "component candidate {$pageSlug}/{$sectionSlug} key {$componentKey}: score failed: "
                            . $error->getMessage();
                    }
                    continue;
                }

                $role = (string) ($planSection['role'] ?? '');
                if (!in_array($role, SectionRole::ALL, true)) {
                    $role = SectionRole::forPosition($index, $plannedCount);
                }
                $candidate = [
                    'key' => $key,
                    'kind' => 'section',
                    'label' => $classification['label'],
                    'shape' => $classification['shape'],
                    'markup' => $markup,
                    'page' => $pageSlug,
                    'section' => $sectionSlug,
                    'index' => $index,
                    'menu_order' => (int) ($manifestPage['menu_order'] ?? $plannedPage['menu_order'] ?? 0),
                    'slug' => $pageSlug,
                    'score' => $score,
                    'contains' => $contains,
                    'role' => $role,
                    'background' => self::background($markup),
                ];
                $eligibilityFailure = self::eligibilityFailure($markup, $registeredBlocks);
                if ($eligibilityFailure === null) {
                    $sectionCandidatesByKey[$key][] = $candidate;
                    $log[] = 'candidate ' . $pageSlug . '/' . $sectionSlug . ' key ' . $key . ': '
                        . json_encode($score, JSON_UNESCAPED_SLASHES);
                } else {
                    $sectionIneligibleByKey[$key] = ($sectionIneligibleByKey[$key] ?? 0) + 1;
                    $warnings[] = "{$relative}: block path /{$index}, section '{$sectionSlug}'; authored value "
                        . Warnings::value($eligibilityFailure['value'])
                        . '; delivered value "removed"; disposition: rejected section because '
                        . ($eligibilityFailure['kind'] === 'raw_php'
                            ? 'raw PHP open tag would execute from included pattern PHP'
                            : 'non-core block is absent from generated plugin registration list');
                    $log[] = "candidate {$pageSlug}/{$sectionSlug} key {$key}: ineligible "
                        . $eligibilityFailure['kind'] . ' ' . Warnings::value($eligibilityFailure['value']);
                }

                if ($components !== null && $componentKey !== null) {
                    $componentEligibilityFailure = null;
                    $componentContains = [];
                    try {
                        foreach ($components as $component => $componentMarkup) {
                            $componentEligibilityFailure ??= self::eligibilityFailure(
                                $componentMarkup,
                                $registeredBlocks,
                            );
                            $componentContains[$component] = self::contains($componentMarkup);
                        }
                    } catch (\Throwable $error) {
                        $componentIneligibleByKey[$componentKey] = ($componentIneligibleByKey[$componentKey] ?? 0) + 1;
                        $warnings[] = "{$relative}: block path /{$index}, section '{$sectionSlug}'; component source "
                            . "could not be inspected ({$error->getMessage()}); delivered no component pair; "
                            . 'disposition: skipped failed component source';
                        $log[] = "component candidate {$pageSlug}/{$sectionSlug} key {$componentKey}: inspection failed: "
                            . $error->getMessage();
                        continue;
                    }

                    if ($componentEligibilityFailure !== null) {
                        $componentIneligibleByKey[$componentKey] = ($componentIneligibleByKey[$componentKey] ?? 0) + 1;
                        $warnings[] = "{$relative}: block path /{$index}, section '{$sectionSlug}'; component source "
                            . 'authored value ' . Warnings::value($componentEligibilityFailure['value'])
                            . '; delivered value "removed"; disposition: rejected component pair because '
                            . ($componentEligibilityFailure['kind'] === 'raw_php'
                                ? 'raw PHP open tag would execute from included pattern PHP'
                                : 'non-core block is absent from generated plugin registration list');
                        $log[] = "component candidate {$pageSlug}/{$sectionSlug} key {$componentKey}: ineligible "
                            . $componentEligibilityFailure['kind'] . ' '
                            . Warnings::value($componentEligibilityFailure['value']);
                    } else {
                        $componentCandidate = $candidate;
                        $componentCandidate['key'] = $componentKey;
                        $componentCandidate['kind'] = 'component';
                        $componentCandidate['from'] = $key;
                        $componentCandidate['components'] = $components;
                        $componentCandidate['component_contains'] = $componentContains;
                        $componentCandidatesByKey[$componentKey][] = $componentCandidate;
                        $log[] = 'component candidate ' . $pageSlug . '/' . $sectionSlug . ' key '
                            . $componentKey . ' from ' . $key . ': '
                            . json_encode($score, JSON_UNESCAPED_SLASHES);
                    }
                }
            }
        }

        $dropped = [];
        $sectionWinners = $this->resolveWinners(
            $sectionCandidatesByKey,
            $sectionIneligibleByKey,
            'section',
            $dropped,
            $warnings,
            $log,
        );
        $componentSources = $this->resolveWinners(
            $componentCandidatesByKey,
            $componentIneligibleByKey,
            'component',
            $dropped,
            $warnings,
            $log,
        );

        [$sectionWinners, $overflowSections] = self::applySectionCap($sectionWinners);
        foreach ($overflowSections as $overflow) {
            $key = (string) $overflow['key'];
            $total = (int) ($overflow['score']['total'] ?? 0);
            $dropped[] = ['kind' => 'section', 'key' => $key, 'reason' => 'cap', 'total' => $total];
            $warnings[] = "section pattern key '{$key}': authored winner score {$total}; delivered removed; "
                . 'disposition: dropped by 10-section cap';
            $log[] = "drop section {$key}: cap, total {$total}";
        }

        [$componentSources, $overflowComponentSources] = self::applyComponentCap($componentSources);
        foreach ($overflowComponentSources as $overflow) {
            $key = (string) $overflow['key'];
            $total = (int) ($overflow['score']['total'] ?? 0);
            $dropped[] = ['kind' => 'component', 'key' => $key, 'reason' => 'cap', 'total' => $total];
            $warnings[] = "component source key '{$key}': authored winner score {$total}; delivered removed; "
                . 'disposition: dropped complete row/card pair by 6-source cap';
            $log[] = "drop component source {$key}: cap, total {$total}";
        }

        $componentWinners = [];
        foreach ($componentSources as $source) {
            foreach (['row', 'card'] as $component) {
                $winner = $source;
                $winner['key'] = (string) $source['key'] . '-' . $component;
                $winner['component'] = $component;
                $winner['markup'] = $source['components'][$component];
                $winner['contains'] = $source['component_contains'][$component];
                unset($winner['components'], $winner['component_contains']);
                $componentWinners[] = $winner;
            }
        }

        $winners = [...$sectionWinners, ...$componentWinners];
        usort($winners, static fn (array $left, array $right): int => strcmp($left['key'], $right['key']));
        $manifestPatterns = [];
        $deliveredSections = [];
        foreach ($winners as &$winner) {
            $deliveredMarkup = self::rewriteLinks(
                self::rewriteAnchors(self::rewriteAssets($winner['markup']), $cssIds),
                $routes,
            );
            $patternFile = 'theme/patterns/' . $winner['key'] . '.php';
            if (($winner['kind'] ?? null) === 'section') {
                try {
                    $strip = self::stripBandButtons(
                        $deliveredMarkup,
                        (string) $winner['label'],
                        $patternFile,
                    );
                    $deliveredMarkup = $strip['markup'];
                    array_push($warnings, ...$strip['warnings']);
                    if ($strip['removed'] > 0) {
                        $log[] = "repair {$winner['key']}: removed {$strip['removed']} band-level CTA block(s)";
                    }
                } catch (\Throwable $error) {
                    $warnings[] = "{$patternFile}: block path unprovable; authored value \"core/buttons\"; "
                        . 'delivered value "pre-transformation bytes"; disposition: kept emitted pattern because '
                        . 'band-level CTA removal could not be completed (' . $error->getMessage() . ')';
                    $log[] = "repair {$winner['key']}: band-level CTA removal failed: {$error->getMessage()}";
                }
            }
            $winner['delivered_markup'] = $deliveredMarkup;
            $project->writeText(
                $patternFile,
                $this->patternFile($project, $winner),
            );
            $manifestPatterns[] = $this->manifestPattern($project, $winner);
            if (($winner['kind'] ?? null) === 'section') {
                $deliveredSections[] = $winner;
            }
        }
        unset($winner);

        $starter = $this->writeStarter($project, $deliveredSections, $warnings, $log);
        $project->writeJson('patterns.json', [
            'version' => 2,
            'patterns' => $manifestPatterns,
            'starter' => $starter,
            'dropped' => $dropped,
        ]);
        $project->writeText('logs/' . self::LOG_FILE, implode("\n", $log) . ($log === [] ? '' : "\n"));
        $project->addWarnings($this->id(), $warnings);

        Narrator::write(sprintf(
            "  extracted %d pattern(s); %d dropped\n",
            count($manifestPatterns),
            count($dropped),
        ));
    }

    /**
     * Remove action rows belonging to a page band while retaining actions
     * nested in cards. The returned markup is assembled in memory so a failed
     * transformation cannot expose a partially edited pattern.
     *
     * @return array{markup:string,removed:int,warnings:list<string>}
     */
    private static function stripBandButtons(string $markup, string $label, string $patternFile): array
    {
        if (in_array($label, self::BAND_CTA_EXEMPT_LABELS, true)) {
            return ['markup' => $markup, 'removed' => 0, 'warnings' => []];
        }

        $document = BlockMarkup::parse($markup);
        if (
            $document->hasMalformedDelimiters()
            || $document->hasMismatchedDelimiters()
            || $document->unclosedIndices() !== []
        ) {
            throw new \RuntimeException('pattern block delimiters are not structurally safe');
        }

        $removable = [];
        foreach ($document->indices() as $index) {
            if (!self::isCoreBlock($document->name($index), 'buttons')) {
                continue;
            }
            $insideColumn = false;
            $insideBandButtons = false;
            for ($parent = $document->parent($index); $parent !== null; $parent = $document->parent($parent)) {
                if (self::isCoreBlock($document->name($parent), 'column')) {
                    $insideColumn = true;
                    break;
                }
                if (self::isCoreBlock($document->name($parent), 'buttons')) {
                    $insideBandButtons = true;
                }
            }
            // Removing an outer buttons block also removes any malformed
            // nested buttons subtree; avoid overlapping source splices.
            if (!$insideColumn && !$insideBandButtons) {
                $removable[] = $index;
            }
        }
        if ($removable === []) {
            return ['markup' => $markup, 'removed' => 0, 'warnings' => []];
        }

        foreach ($removable as $index) {
            if ($document->endOffset($index) === null) {
                throw new \RuntimeException(
                    'core/buttons at block path ' . self::blockPath($document, $index) . ' has no safe endpoint',
                );
            }
        }

        $removed = array_fill_keys($removable, true);
        foreach ($removable as $index) {
            for ($parent = $document->parent($index); $parent !== null; $parent = $document->parent($parent)) {
                if ($document->parent($parent) === null || !self::isPrunableContainer($document->name($parent))) {
                    break;
                }
                $remainingChildren = array_filter(
                    $document->children($parent),
                    static fn (int $child): bool => !isset($removed[$child]),
                );
                if ($remainingChildren !== [] || self::hasNonWhitespaceOwnedText($document, $parent, $markup)) {
                    break;
                }
                $removed[$parent] = true;
            }
        }

        $topmost = array_values(array_filter(
            array_keys($removed),
            static function (int $index) use ($document, $removed): bool {
                for ($parent = $document->parent($index); $parent !== null; $parent = $document->parent($parent)) {
                    if (isset($removed[$parent])) {
                        return false;
                    }
                }
                return true;
            },
        ));
        $splices = [];
        foreach ($topmost as $index) {
            $end = $document->endOffset($index);
            if ($end === null) {
                throw new \RuntimeException(
                    'container at block path ' . self::blockPath($document, $index) . ' has no safe endpoint',
                );
            }
            $start = $document->openingOffset($index);
            $splices[] = ['start' => $start, 'length' => $end - $start];
        }
        usort($splices, static fn (array $left, array $right): int => $right['start'] <=> $left['start']);
        $stripped = $markup;
        foreach ($splices as $splice) {
            $stripped = substr_replace($stripped, '', $splice['start'], $splice['length']);
        }

        if (!self::hasPatternContent($stripped)) {
            $warnings = [];
            foreach ($removable as $index) {
                $warnings[] = $patternFile . ': block path ' . self::blockPath($document, $index)
                    . '; authored value "core/buttons"; delivered value "retained"; '
                    . 'disposition: kept CTA because removal would leave pattern with no content blocks';
            }
            return ['markup' => $markup, 'removed' => 0, 'warnings' => $warnings];
        }

        return ['markup' => $stripped, 'removed' => count($removable), 'warnings' => []];
    }

    private static function isCoreBlock(string $name, string $block): bool
    {
        return $name === $block || $name === 'core/' . $block;
    }

    private static function isPrunableContainer(string $name): bool
    {
        return self::isCoreBlock($name, 'group')
            || self::isCoreBlock($name, 'columns')
            || self::isCoreBlock($name, 'column');
    }

    /** Whether one container owns visible text outside its child block spans. */
    private static function hasNonWhitespaceOwnedText(
        BlockMarkup $document,
        int $index,
        string $markup,
    ): bool {
        $innerStart = $document->openingOffset($index) + $document->openingLength($index);
        $innerEnd = $document->innerEndOffset($index);
        $owned = substr($markup, $innerStart, $innerEnd - $innerStart);
        $childSplices = [];
        foreach ($document->children($index) as $child) {
            $childEnd = $document->endOffset($child);
            if ($childEnd === null) {
                throw new \RuntimeException(
                    'child at block path ' . self::blockPath($document, $child) . ' has no safe endpoint',
                );
            }
            $childStart = $document->openingOffset($child);
            $childSplices[] = [
                'start' => $childStart - $innerStart,
                'length' => $childEnd - $childStart,
            ];
        }
        usort($childSplices, static fn (array $left, array $right): int => $right['start'] <=> $left['start']);
        foreach ($childSplices as $splice) {
            $owned = substr_replace($owned, '', $splice['start'], $splice['length']);
        }
        $withoutComments = preg_replace('/<!--.*?-->/s', '', $owned) ?? $owned;
        return self::hasNonWhitespaceText($withoutComments);
    }

    private static function hasNonWhitespaceText(string $markup): bool
    {
        $text = html_entity_decode(strip_tags($markup), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $hasText = preg_match('/[^\s\x{00A0}]/u', $text);
        if ($hasText === false) {
            throw new \RuntimeException('pattern text is not valid UTF-8');
        }
        return $hasText === 1;
    }

    /** A wrapper-only tree is not useful pattern content. */
    private static function hasPatternContent(string $markup): bool
    {
        $document = BlockMarkup::parse($markup);
        if (
            $document->hasMalformedDelimiters()
            || $document->hasMismatchedDelimiters()
            || $document->unclosedIndices() !== []
        ) {
            throw new \RuntimeException('CTA removal produced structurally unsafe block markup');
        }
        foreach ($document->indices() as $index) {
            $name = $document->name($index);
            if (!self::isPrunableContainer($name)) {
                return true;
            }
        }

        $withoutComments = preg_replace('/<!--.*?-->/s', '', $markup) ?? $markup;
        if (self::hasNonWhitespaceText($withoutComments)) {
            return true;
        }
        return preg_match('/<(?:img|video|audio|iframe|svg|canvas|object|embed)\b/i', $withoutComments) === 1;
    }

    /** Stable zero-based child path in authored block order. */
    private static function blockPath(BlockMarkup $document, int $index): string
    {
        $segments = [];
        for ($current = $index; ; $current = $parent) {
            $parent = $document->parent($current);
            $siblings = $parent === null
                ? array_values(array_filter(
                    $document->indices(),
                    static fn (int $candidate): bool => $document->parent($candidate) === null,
                ))
                : $document->children($parent);
            $position = array_search($current, $siblings, true);
            if ($position === false) {
                throw new \RuntimeException('could not resolve authored block path');
            }
            array_unshift($segments, (string) $position);
            if ($parent === null) {
                break;
            }
        }
        return '/' . implode('/', $segments);
    }

    /** @param array<string,true> $registeredBlocks */
    public static function isEligible(string $markup, array $registeredBlocks): bool
    {
        return self::eligibilityFailure($markup, $registeredBlocks) === null;
    }

    /**
     * @param array<string,true> $registeredBlocks
     * @return array{kind:'raw_php'|'unregistered_block',value:string}|null
     */
    private static function eligibilityFailure(string $markup, array $registeredBlocks): ?array
    {
        if (preg_match('/<\?(?:=|php\b)?/i', $markup, $phpMatch) === 1) {
            return ['kind' => 'raw_php', 'value' => $phpMatch[0]];
        }
        $doc = BlockMarkup::parse($markup);
        foreach ($doc->indices() as $index) {
            $name = $doc->name($index);
            if (!str_contains($name, '/')) {
                continue;
            }
            $canonical = str_starts_with($name, 'core/') ? substr($name, 5) : $name;
            if ($canonical !== $name) {
                continue;
            }
            if (!isset($registeredBlocks[$name])) {
                return ['kind' => 'unregistered_block', 'value' => $name];
            }
        }
        return null;
    }

    public static function rewriteAssets(string $markup): string
    {
        $replacement = static function (array $match): string {
            $filename = $match[1];
            return "<?php echo esc_url( get_theme_file_uri( 'assets/{$filename}' ) ); ?>";
        };
        $markup = preg_replace_callback(
            '/theme:\.\/assets\/([a-z0-9][a-z0-9._-]*)/i',
            $replacement,
            $markup,
        ) ?? $markup;
        return preg_replace_callback(
            '#/wp-content/themes/[^/"\'\\s]+/assets/([a-z0-9][a-z0-9._-]*)#i',
            $replacement,
            $markup,
        ) ?? $markup;
    }

    /** @return array<string,true> */
    public static function idSelectorsIn(string $css): array
    {
        $ids = [];
        $stack = [];
        $prelude = '';
        $length = strlen($css);
        $quote = null;
        $comment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];
            $next = $i + 1 < $length ? $css[$i + 1] : '';
            if ($comment) {
                if ($char === '*' && $next === '/') {
                    $comment = false;
                    $i++;
                }
                continue;
            }
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '/' && $next === '*') {
                $comment = true;
                $i++;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '{') {
                $trimmed = trim($prelude);
                $insideRule = in_array('rule', $stack, true);
                $isAtRule = str_starts_with($trimmed, '@');
                if (!$insideRule && !$isAtRule && $trimmed !== '') {
                    if (preg_match_all('/(?:^|[\s,>+~(])#([a-z_][a-z0-9_-]*)/i', $trimmed, $matches)) {
                        foreach ($matches[1] as $id) {
                            $ids[$id] = true;
                        }
                    }
                }
                $stack[] = $isAtRule ? 'at' : 'rule';
                $prelude = '';
                continue;
            }
            if ($char === '}') {
                array_pop($stack);
                $prelude = '';
                continue;
            }
            if ($char === ';' && !in_array('rule', $stack, true)) {
                $prelude = '';
                continue;
            }
            if (!in_array('rule', $stack, true)) {
                $prelude .= $char;
            }
        }
        return $ids;
    }

    /** @param array<string,true> $cssIds */
    public static function rewriteAnchors(string $markup, array $cssIds): string
    {
        $doc = BlockMarkup::parse($markup);
        foreach ($doc->indices() as $index) {
            $attrs = $doc->attrs($index);
            if (!is_array($attrs) || !isset($attrs['anchor']) || !is_string($attrs['anchor'])) {
                continue;
            }
            if (!isset($cssIds[$attrs['anchor']])) {
                unset($attrs['anchor']);
                $doc->setAttrs($index, $attrs);
            }
        }
        $markup = $doc->render();
        return preg_replace_callback(
            '/\s+id\s*=\s*(["\'])(.*?)\1/is',
            static fn (array $match): string => isset($cssIds[html_entity_decode(
                $match[2],
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            )]) ? $match[0] : '',
            $markup,
        ) ?? $markup;
    }

    /** @param array<string,true> $resolvableRoutes */
    public static function rewriteLinks(string $markup, array $resolvableRoutes): string
    {
        $anchors = LinkTargets::anchorsIn($markup);
        $outside = [];
        foreach (LinkTargets::allTargets($markup) as $target) {
            $decoded = html_entity_decode(trim($target), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!self::targetResolves($decoded, $resolvableRoutes, $anchors) && str_starts_with($decoded, '#')) {
                $outside[$decoded] = true;
            }
        }
        if ($outside === []) {
            return $markup;
        }

        $markup = preg_replace_callback(
            '/(\bhref\s*=\s*)(["\'])(.*?)\2/is',
            static function (array $match) use ($outside): string {
                $decoded = html_entity_decode(trim($match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return isset($outside[$decoded]) ? $match[1] . $match[2] . '#' . $match[2] : $match[0];
            },
            $markup,
        ) ?? $markup;
        $markup = preg_replace_callback(
            '/(\bhref\s*=\s*)(?!["\'])([^\s"\'=<>`]+)/is',
            static function (array $match) use ($outside): string {
                $decoded = html_entity_decode(trim($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return isset($outside[$decoded]) ? $match[1] . '#' : $match[0];
            },
            $markup,
        ) ?? $markup;
        return preg_replace_callback(
            '/("url"\s*:\s*")([^"]*)(")/i',
            static function (array $match) use ($outside): string {
                $decoded = str_replace('\\/', '/', $match[2]);
                return isset($outside[$decoded]) ? $match[1] . '#' . $match[3] : $match[0];
            },
            $markup,
        ) ?? $markup;
    }

    private function deletePatternDirectory(Project $project): void
    {
        $directory = $project->themePath('patterns');
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $removed = $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            if (!$removed) {
                throw new \RuntimeException("Could not remove stale pattern path: {$entry->getPathname()}");
            }
        }
        if (!rmdir($directory)) {
            throw new \RuntimeException("Could not remove stale pattern directory: {$directory}");
        }
    }

    /** @param mixed $pages @return array<string,array<mixed>> */
    private function pagesBySlug(mixed $pages): array
    {
        $out = [];
        if (!is_array($pages)) {
            return $out;
        }
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $slug = (string) ($page['slug'] ?? '');
            if ($slug !== '') {
                $out[$slug] = $page;
            }
        }
        return $out;
    }

    /** @return array<string,true> */
    private function registeredPluginBlocks(Project $project): array
    {
        if (!$project->exists(ScaffoldPluginStep::MAIN_FILE)) {
            return [];
        }

        $plugin = $project->readText(ScaffoldPluginStep::MAIN_FILE);
        $registrationRows = self::registrationRows($plugin);
        if ($registrationRows === []) {
            return [];
        }

        $registered = [];
        foreach ($registrationRows as $name => $directory) {
            $relative = "plugin/blocks/{$directory}/block.json";
            if (!$project->exists($relative)) {
                continue;
            }
            try {
                $definition = $project->readJson($relative);
            } catch (\Throwable) {
                return [];
            }
            if (($definition['name'] ?? null) === $name) {
                $registered[$name] = true;
            }
        }
        return $registered;
    }

    /**
     * Parse the one registration array from executable PHP tokens. Comment,
     * docblock, quoted-string, heredoc and nowdoc contents stay opaque tokens,
     * so row-shaped text in them cannot grant block eligibility.
     *
     * @return array<string,string> registered block name => metadata directory
     */
    private static function registrationRows(string $plugin): array
    {
        $tokens = array_values(array_filter(
            token_get_all($plugin),
            static fn (array|string $token): bool => !is_array($token)
                || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $assignments = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            if (!self::tokenIs($tokens[$index], T_VARIABLE, '$blocks')) {
                continue;
            }
            if (($tokens[$index + 1] ?? null) !== '=') {
                continue;
            }
            $assignments[] = $index;
        }
        if (count($assignments) !== 1) {
            return [];
        }

        $index = $assignments[0] + 2;
        if (!self::tokenIs($tokens[$index] ?? null, T_ARRAY) || ($tokens[$index + 1] ?? null) !== '(') {
            return [];
        }
        $index += 2;
        $rows = [];
        $directories = [];
        while (($tokens[$index] ?? null) !== ')') {
            $name = self::constantStringValue($tokens[$index] ?? null);
            if (
                $name === null
                || preg_match('/^[a-z0-9][a-z0-9_-]*\/[a-z0-9][a-z0-9_-]*$/', $name) !== 1
                || !self::tokenIs($tokens[$index + 1] ?? null, T_DOUBLE_ARROW)
                || !self::tokenIs($tokens[$index + 2] ?? null, T_DIR)
                || ($tokens[$index + 3] ?? null) !== '.'
            ) {
                return [];
            }
            $path = self::constantStringValue($tokens[$index + 4] ?? null);
            if (
                $path === null
                || preg_match('#^/blocks/([a-z0-9][a-z0-9_-]*)$#', $path, $pathMatch) !== 1
                || ($tokens[$index + 5] ?? null) !== ','
            ) {
                return [];
            }
            $directory = $pathMatch[1];
            if (isset($rows[$name]) || isset($directories[$directory])) {
                return [];
            }
            $rows[$name] = $directory;
            $directories[$directory] = true;
            $index += 6;
        }

        if ($rows === [] || ($tokens[$index + 1] ?? null) !== ';') {
            return [];
        }
        return $rows;
    }

    private static function tokenIs(array|string|null $token, int $kind, ?string $text = null): bool
    {
        return is_array($token)
            && $token[0] === $kind
            && ($text === null || $token[1] === $text);
    }

    private static function constantStringValue(array|string|null $token): ?string
    {
        if (!self::tokenIs($token, T_CONSTANT_ENCAPSED_STRING)) {
            return null;
        }
        $source = $token[1];
        $quote = $source[0] ?? '';
        if (($quote !== "'" && $quote !== '"') || substr($source, -1) !== $quote) {
            return null;
        }
        $value = substr($source, 1, -1);
        // Names and registration paths use a deliberately closed ASCII domain;
        // escapes would make source identity ambiguous, so fail closed.
        return str_contains($value, '\\') ? null : $value;
    }

    /**
     * @param list<array<mixed>> $manifestPages
     * @param array<string,array<mixed>> $planPages
     * @return array<string,true>
     */
    private function routeSet(array $manifestPages, array $planPages): array
    {
        $routes = [];
        foreach ($manifestPages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $slug = (string) ($page['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $path = $planPages[$slug]['path'] ?? null;
            if (is_string($path) && $path !== '') {
                $routes[$path] = true;
            }
            if (($page['front'] ?? false) === true) {
                $routes['/'] = true;
            }
            $routes['/' . trim($slug, '/') . '/'] = true;
            $routes['/' . trim($slug, '/')] = true;
        }
        return $routes;
    }

    /** @param array<mixed> $planSection @return array{key:string,label:?string,shape:string} */
    private function classify(string $markup, array $planSection, int $index, int $count): array
    {
        $doc = BlockMarkup::parse($markup);
        $root = $doc->topLevel();
        if ($root === null || !$doc->isStructurallySafe($root)) {
            throw new \InvalidArgumentException('section has no safe top-level block');
        }
        $label = SectionPattern::label($planSection, $index, $count);
        $shape = SectionPattern::shape($doc, $root);
        return [
            'key' => $label === null ? $shape : $label . '-' . $shape,
            'label' => $label,
            'shape' => $shape,
        ];
    }

    /** @return array{row:string,card:string}|null */
    private static function componentMarkup(string $markup, string $shape): ?array
    {
        if (!in_array($shape, ['grid', 'quotes'], true)) {
            return null;
        }

        $document = BlockMarkup::parse($markup);
        if (
            $document->hasMalformedDelimiters()
            || $document->hasMismatchedDelimiters()
            || $document->unclosedIndices() !== []
        ) {
            throw new \RuntimeException('component source block delimiters are not structurally safe');
        }

        if ($shape === 'grid') {
            $row = null;
            $rowColumns = [];
            foreach ($document->indices() as $index) {
                if (!self::isCoreBlock($document->name($index), 'columns')) {
                    continue;
                }
                $columns = array_values(array_filter(
                    $document->children($index),
                    static fn (int $child): bool => self::isCoreBlock($document->name($child), 'column'),
                ));
                if (count($columns) >= 3 && count($columns) > count($rowColumns)) {
                    $row = $index;
                    $rowColumns = $columns;
                }
            }
            if ($row === null) {
                return null;
            }

            $firstColumn = $rowColumns[0];
            $children = $document->children($firstColumn);
            if ($children === []) {
                return null;
            }
            $card = count($children) === 1 ? $children[0] : $firstColumn;
            return [
                'row' => self::blockSlice($document, $markup, $row),
                'card' => self::blockSlice($document, $markup, $card),
            ];
        }

        $quotes = array_values(array_filter(
            $document->indices(),
            static fn (int $index): bool => self::isCoreBlock($document->name($index), 'quote')
                || self::isCoreBlock($document->name($index), 'pullquote'),
        ));
        if ($quotes === []) {
            return null;
        }
        $container = self::nearestCommonBlockAncestor($document, $quotes);
        if ($container === null) {
            return null;
        }
        return [
            'row' => self::blockSlice($document, $markup, $container),
            'card' => self::blockSlice($document, $markup, $quotes[0]),
        ];
    }

    /** @param list<int> $indices */
    private static function nearestCommonBlockAncestor(BlockMarkup $document, array $indices): ?int
    {
        $candidate = $document->parent($indices[0]);
        while ($candidate !== null) {
            $containsAll = true;
            foreach (array_slice($indices, 1) as $index) {
                if (!self::isBlockAncestor($document, $candidate, $index)) {
                    $containsAll = false;
                    break;
                }
            }
            if ($containsAll) {
                return $candidate;
            }
            $candidate = $document->parent($candidate);
        }
        return null;
    }

    private static function isBlockAncestor(BlockMarkup $document, int $ancestor, int $index): bool
    {
        for ($parent = $document->parent($index); $parent !== null; $parent = $document->parent($parent)) {
            if ($parent === $ancestor) {
                return true;
            }
        }
        return false;
    }

    private static function blockSlice(BlockMarkup $document, string $markup, int $index): string
    {
        $end = $document->endOffset($index);
        if (!$document->isStructurallySafe($index) || $end === null) {
            throw new \RuntimeException('component block has no safe endpoint');
        }
        $start = $document->openingOffset($index);
        return substr($markup, $start, $end - $start);
    }

    /**
     * Apply the shared per-key exemplar selection and ineligible-key degrade
     * behavior to one pattern kind.
     *
     * @param array<string,list<array<mixed>>> $candidatesByKey
     * @param array<string,int> $ineligibleByKey
     * @param list<array<string,mixed>> $dropped
     * @param list<string> $warnings
     * @param list<string> $log
     * @return list<array<mixed>>
     */
    private function resolveWinners(
        array $candidatesByKey,
        array $ineligibleByKey,
        string $kind,
        array &$dropped,
        array &$warnings,
        array &$log,
    ): array {
        $winners = [];
        $allKeys = array_values(array_unique(array_merge(
            array_keys($candidatesByKey),
            array_keys($ineligibleByKey),
        )));
        sort($allKeys, SORT_STRING);
        foreach ($allKeys as $key) {
            $candidates = $candidatesByKey[$key] ?? [];
            if ($candidates === []) {
                $dropped[] = ['kind' => $kind, 'key' => $key, 'reason' => 'ineligible', 'total' => 0];
                $warnings[] = "{$kind} pattern key '{$key}': authored candidates " . ($ineligibleByKey[$key] ?? 0)
                    . '; delivered removed; disposition: every candidate was ineligible';
                $log[] = "drop {$kind} {$key}: every candidate ineligible";
                continue;
            }

            // Guard belongs here: SectionPattern::pickWinner() intentionally
            // rejects an empty list, while extraction must degrade instead.
            $winner = SectionPattern::pickWinner($candidates);
            $winner['alternates'] = $this->alternates($candidates, $winner);
            $winners[] = $winner;
            $log[] = "winner {$kind} {$key}: {$winner['page']}/{$winner['section']} "
                . "total {$winner['score']['total']}";
        }
        return $winners;
    }

    /** @param list<array<mixed>> $candidates @param array<mixed> $winner @return list<array<string,mixed>> */
    private function alternates(array $candidates, array $winner): array
    {
        $alternates = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate !== $winner,
        ));
        usort($alternates, [self::class, 'compareGlobalWinners']);
        return array_map(
            static fn (array $candidate): array => [
                'page' => $candidate['page'],
                'section' => $candidate['section'],
                'total' => (int) ($candidate['score']['total'] ?? 0),
            ],
            $alternates,
        );
    }

    /** @param array<mixed> $left @param array<mixed> $right */
    private static function compareGlobalWinners(array $left, array $right): int
    {
        $score = ((int) ($right['score']['total'] ?? 0)) <=> ((int) ($left['score']['total'] ?? 0));
        if ($score !== 0) {
            return $score;
        }
        $menu = ((int) ($left['menu_order'] ?? 0)) <=> ((int) ($right['menu_order'] ?? 0));
        if ($menu !== 0) {
            return $menu;
        }
        $index = ((int) ($left['index'] ?? 0)) <=> ((int) ($right['index'] ?? 0));
        if ($index !== 0) {
            return $index;
        }
        return strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? ''));
    }

    /**
     * Reserve semantic endpoint coverage before score-ranked cap fill.
     *
     * @param list<array<mixed>> $winners
     * @return array{0:list<array<mixed>>,1:list<array<mixed>>} kept, overflow
     */
    private static function applySectionCap(array $winners): array
    {
        if (count($winners) <= self::SECTION_PATTERN_CAP) {
            return [$winners, []];
        }

        $ranked = $winners;
        usort($ranked, [self::class, 'compareCapWinners']);
        $keptByKey = [];

        foreach ([['hero'], ['cta', 'closing']] as $reservedLabels) {
            $matches = array_values(array_filter(
                $winners,
                static fn (array $winner): bool => in_array($winner['label'] ?? null, $reservedLabels, true),
            ));
            if ($matches === []) {
                continue;
            }
            usort($matches, [self::class, 'compareGlobalWinners']);
            $reserved = $matches[0];
            $keptByKey[(string) $reserved['key']] = $reserved;
        }

        foreach ($ranked as $winner) {
            if (count($keptByKey) >= self::SECTION_PATTERN_CAP) {
                break;
            }
            $key = (string) $winner['key'];
            if (!isset($keptByKey[$key])) {
                $keptByKey[$key] = $winner;
            }
        }

        $overflow = array_values(array_filter(
            $ranked,
            static fn (array $winner): bool => !isset($keptByKey[(string) $winner['key']]),
        ));
        return [array_values($keptByKey), $overflow];
    }

    /**
     * Component budget counts source pairs, never individual emitted files.
     *
     * @param list<array<mixed>> $winners
     * @return array{0:list<array<mixed>>,1:list<array<mixed>>} kept, overflow
     */
    private static function applyComponentCap(array $winners): array
    {
        $ranked = $winners;
        usort($ranked, [self::class, 'compareCapWinners']);
        return [
            array_slice($ranked, 0, self::COMPONENT_SOURCE_CAP),
            array_slice($ranked, self::COMPONENT_SOURCE_CAP),
        ];
    }

    /** @param array<mixed> $left @param array<mixed> $right */
    private static function compareCapWinners(array $left, array $right): int
    {
        $score = ((int) ($right['score']['total'] ?? 0)) <=> ((int) ($left['score']['total'] ?? 0));
        return $score !== 0
            ? $score
            : strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? ''));
    }

    /** @param array<mixed> $winner */
    private function patternFile(Project $project, array $winner, bool $starter = false): string
    {
        $label = $starter ? 'page' : ($winner['label'] ?? null);
        $shape = $starter ? 'starter' : (string) $winner['shape'];
        $component = is_string($winner['component'] ?? null) ? $winner['component'] : null;
        $title = $starter ? 'Page starter' : self::patternTitle($label, $shape, $component);
        $slug = $starter ? 'page-starter' : (string) $winner['key'];
        $categories = $starter
            ? [$project->slug() . '-sections']
            : $this->categories(
                $project,
                is_string($label) ? $label : null,
                (string) ($winner['kind'] ?? 'section'),
            );
        $description = $starter
            ? 'A complete page starter layout.'
            : self::patternDescription(is_string($label) ? $label : null, $shape, $component);
        $keywords = $starter
            ? 'page, starter'
            : implode(', ', array_values(array_filter([
                is_string($label) ? $label : null,
                $component ?? $shape,
            ])));
        $extra = $starter ? " * Template Types: page\n * Inserter: no\n" : '';

        return "<?php\n/**\n"
            . " * Title: {$title}\n"
            . " * Slug: {$project->slug()}/{$slug}\n"
            . ' * Categories: ' . implode(', ', $categories) . "\n"
            . " * Description: {$description}\n"
            . " * Keywords: {$keywords}\n"
            . $extra
            . " * Viewport Width: 1400\n"
            . " */\n?>\n"
            . (string) $winner['delivered_markup']
            . "\n";
    }

    /** @param array<mixed> $winner @return array<string,mixed> */
    private function manifestPattern(Project $project, array $winner): array
    {
        $label = is_string($winner['label']) ? $winner['label'] : null;
        $kind = (string) ($winner['kind'] ?? 'section');
        $tail = [
            'label' => $label,
            'shape' => $winner['shape'],
            'title' => self::patternTitle(
                $label,
                (string) $winner['shape'],
                is_string($winner['component'] ?? null) ? $winner['component'] : null,
            ),
            'categories' => $this->categories($project, $label, $kind),
            'source' => [
                'page' => $winner['page'],
                'section' => $winner['section'],
                'index' => $winner['index'],
            ],
            'score' => $winner['score'],
            'contains' => $winner['contains'],
            'alternates' => $winner['alternates'],
        ];
        if ($kind === 'component') {
            return [
                'slug' => $winner['key'],
                'kind' => 'component',
                'component' => $winner['component'],
                'from' => $winner['from'],
                ...$tail,
            ];
        }
        return [
            'slug' => $winner['key'],
            'kind' => 'section',
            ...$tail,
        ];
    }

    /** @return list<string> */
    private function categories(Project $project, ?string $label, string $kind = 'section'): array
    {
        if ($kind === 'component') {
            return [$project->slug() . '-components'];
        }
        $categories = [$project->slug() . '-sections'];
        if ($label !== null && isset(self::CORE_CATEGORIES[$label])) {
            $categories[] = self::CORE_CATEGORIES[$label];
        }
        return $categories;
    }

    private static function patternTitle(?string $label, string $shape, ?string $component = null): string
    {
        if ($component !== null) {
            return 'Testimonial ' . $component;
        }
        $shapeTitle = self::displayName($shape);
        return $label === null
            ? ucfirst($shapeTitle) . ' section'
            : self::displayName($label) . ', ' . $shapeTitle;
    }

    private static function patternDescription(?string $label, string $shape, ?string $component = null): string
    {
        if ($component !== null) {
            return $label === null
                ? 'A ' . $component . ' component.'
                : 'A ' . strtolower(self::displayName($label)) . ' ' . $component . ' component.';
        }
        return $label === null
            ? 'A ' . self::displayName($shape) . ' section layout.'
            : 'A ' . strtolower(self::displayName($label)) . ' section, '
                . strtolower(self::displayName($shape)) . ' layout.';
    }

    private static function displayName(string $value): string
    {
        return match ($value) {
            'cta' => 'Call to action',
            'faq' => 'FAQ',
            'grid' => 'Card grid',
            default => ucfirst(str_replace('-', ' ', $value)),
        };
    }

    /**
     * @param list<array<mixed>> $winners
     * @param list<string> $warnings
     * @param list<string> $log
     * @return array{slug:string,sections:list<string>}|null
     */
    private function writeStarter(Project $project, array $winners, array &$warnings, array &$log): ?array
    {
        $byRole = [SectionRole::HERO => [], SectionRole::CONTENT => [], SectionRole::CLOSING => []];
        foreach ($winners as $winner) {
            $role = $winner['role'] ?? null;
            if (is_string($role) && isset($byRole[$role])) {
                $byRole[$role][] = $winner;
            }
        }
        foreach ($byRole as &$roleWinners) {
            usort($roleWinners, [self::class, 'compareSourceOrder']);
        }
        unset($roleWinners);

        if ($byRole[SectionRole::HERO] === []) {
            $warnings[] = 'theme/patterns/page-starter.php: authored starter has no eligible hero winner; '
                . 'delivered removed; disposition: starter omitted';
            $log[] = 'drop page-starter: no hero winner';
            return null;
        }

        $sections = [$byRole[SectionRole::HERO][0]];
        array_push($sections, ...array_slice($byRole[SectionRole::CONTENT], 0, 3));
        if ($byRole[SectionRole::CLOSING] !== []) {
            $sections[] = $byRole[SectionRole::CLOSING][0];
        }

        if (array_filter($sections, static fn (array $section): bool => $section['background'] !== null) !== []) {
            $sections = self::alternateBackgrounds($sections);
        }
        $markup = implode("\n", array_map(
            static fn (array $winner): string => (string) $winner['delivered_markup'],
            $sections,
        ));
        $starterWinner = [
            'key' => 'page-starter',
            'label' => 'page',
            'shape' => 'starter',
            'delivered_markup' => $markup,
        ];
        $project->writeText(
            'theme/patterns/page-starter.php',
            $this->patternFile($project, $starterWinner, true),
        );
        $slugs = array_values(array_map(static fn (array $winner): string => $winner['key'], $sections));
        $log[] = 'winner page-starter: ' . implode(', ', $slugs);
        return ['slug' => 'page-starter', 'sections' => $slugs];
    }

    /** @param array<mixed> $left @param array<mixed> $right */
    private static function compareSourceOrder(array $left, array $right): int
    {
        $menu = ((int) ($left['menu_order'] ?? 0)) <=> ((int) ($right['menu_order'] ?? 0));
        if ($menu !== 0) {
            return $menu;
        }
        $index = ((int) ($left['index'] ?? 0)) <=> ((int) ($right['index'] ?? 0));
        if ($index !== 0) {
            return $index;
        }
        return strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? ''));
    }

    /** @param list<array<mixed>> $sections @return list<array<mixed>> */
    private static function alternateBackgrounds(array $sections): array
    {
        if (count($sections) < 3) {
            return $sections;
        }

        $hero = $sections[0];
        $closing = ($sections[count($sections) - 1]['role'] ?? null) === SectionRole::CLOSING
            ? $sections[count($sections) - 1]
            : null;
        $contentEnd = $closing === null ? count($sections) : count($sections) - 1;
        $content = array_slice($sections, 1, $contentEnd - 1);
        if ($content === []) {
            return $sections;
        }

        $best = $content;
        $bestConflicts = PHP_INT_MAX;
        foreach (self::permutations($content) as $permutation) {
            $candidate = [$hero, ...$permutation];
            if ($closing !== null) {
                $candidate[] = $closing;
            }
            $conflicts = self::backgroundConflicts($candidate);
            if ($conflicts < $bestConflicts) {
                $best = $permutation;
                $bestConflicts = $conflicts;
            }
        }

        $ordered = [$hero, ...$best];
        if ($closing !== null) {
            $ordered[] = $closing;
        }
        return $ordered;
    }

    /** @param list<array<mixed>> $items @return list<list<array<mixed>>> */
    private static function permutations(array $items): array
    {
        if (count($items) < 2) {
            return [$items];
        }
        $out = [];
        foreach ($items as $index => $item) {
            $remaining = $items;
            array_splice($remaining, $index, 1);
            foreach (self::permutations($remaining) as $tail) {
                $out[] = [$item, ...$tail];
            }
        }
        return $out;
    }

    /** @param list<array<mixed>> $sections */
    private static function backgroundConflicts(array $sections): int
    {
        $conflicts = 0;
        for ($index = 1, $count = count($sections); $index < $count; $index++) {
            $previous = $sections[$index - 1]['background'] ?? null;
            $current = $sections[$index]['background'] ?? null;
            if ($previous !== null && $previous === $current) {
                $conflicts++;
            }
        }
        return $conflicts;
    }

    /** @return array{headings:int,media:int,actions:int,items:int} */
    private static function contains(string $markup): array
    {
        $doc = BlockMarkup::parse($markup);
        $headings = 0;
        $media = 0;
        $actions = 0;
        $columns = 0;
        $listItems = 0;
        foreach ($doc->indices() as $index) {
            $name = $doc->name($index);
            $name = str_starts_with($name, 'core/') ? substr($name, 5) : $name;
            if ($name === 'heading') {
                $headings++;
            }
            if (in_array($name, ['image', 'gallery', 'cover', 'video', 'audio', 'media-text'], true)) {
                $media++;
            }
            if (in_array($name, ['button', 'navigation-link'], true)) {
                $actions++;
            }
            if ($name === 'column') {
                $columns++;
            }
            if ($name === 'list-item') {
                $listItems++;
            }
        }
        if ($actions === 0) {
            $actions = preg_match_all('/<a\b[^>]*\bhref\s*=/i', $markup);
        }
        return [
            'headings' => $headings,
            'media' => $media,
            'actions' => $actions,
            'items' => max($columns, $listItems),
        ];
    }

    /** @param array<string,true> $routes @param array<string,true> $anchors */
    private static function targetResolves(string $target, array $routes, array $anchors): bool
    {
        if ($target === '' || $target === '#') {
            return true;
        }
        if (str_starts_with($target, '#')) {
            return isset($anchors[rawurldecode(substr($target, 1))]);
        }
        if (
            str_starts_with($target, '//')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) === 1
            || str_starts_with($target, 'theme:./assets/')
            || LinkTargets::isThemeAssetPath($target)
        ) {
            return true;
        }
        if (isset($routes[$target])) {
            return true;
        }
        $path = parse_url($target, PHP_URL_PATH);
        return is_string($path) && isset($routes[$path]);
    }

    private static function background(string $markup): ?string
    {
        $doc = BlockMarkup::parse($markup);
        $root = $doc->topLevel();
        if ($root === null) {
            return null;
        }
        $attrs = $doc->attrs($root) ?? [];
        $background = $attrs['backgroundColor'] ?? ($attrs['style']['color']['background'] ?? null);
        return is_string($background) && $background !== '' ? $background : null;
    }
}
