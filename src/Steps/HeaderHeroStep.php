<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\AboveFoldPartFacts;
use Automattic\SiteBuild\ContrastFix;
use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\HeaderBehavior;
use Automattic\SiteBuild\HeaderFallback;
use Automattic\SiteBuild\HeaderNavDestinations;
use Automattic\SiteBuild\HeroFallback;
use Automattic\SiteBuild\HeroHeadlineFit;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\PlaygroundArtifact;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Units\GeneratedMarkup;
use Automattic\SiteBuild\Warnings;

/**
 * Step (deterministic): finalize the persisted two-phase above-fold contract
 * after section rhythm and layout normalization, and resolve the header's
 * runtime behavior. Header, hero, and page openings are independent generated
 * units; this is their narrow objective backstop, not a general aesthetic
 * hero rewriter.
 *
 * Input:  delivery-phase aboveFold.json + pages.json + designDirection.json
 *         + siteSpec.json + theme/theme.json + parts
 * Output: matching part/page repairs + final-phase aboveFold.json + the
 *         closed headerBehavior.json artifact, actionable loss warnings, and
 *         logs/header-hero.txt for successful repairs.
 *
 * Objective repairs/contracts:
 *  1. Header behavior — resolve static/sticky-soft/overlay-to-solid from the
 *     final contract relation, the actual palette, and the motion profile,
 *     then persist the closed six-field headerBehavior.json artifact. The
 *     contract's foreground/protection tokens are the authored color inputs,
 *     so resolved states stay consistent with the persisted relation.
 *  2. Position ownership — remove the legacy inner `header-overlay` hook and
 *     root/nested persistent position declarations (attrs and saved HTML).
 *     The assembled outer shell exclusively owns absolute/sticky/fixed
 *     geometry; the inner group owns visual states.
 *  3. State colors — canonicalize the root visual-state classes and enforce
 *     one palette-token foreground that remains readable on both opaque
 *     states. Overlay starts transparent and stacked modes start opaque.
 *  4. Nested Group padding — a legacy/generated theme may put vertical
 *     padding on the global core/group style, which matches every structural
 *     Group nested inside the header and compounds into a giant bar. Missing
 *     descendant top/bottom values are set to zero while explicit padding
 *     and the root's compact padding survive.
 *  5. Fold budget — in stacked mode an opaque header consumes ~100px of the
 *     first viewport, so a page-opening cover taller than 80vh is lowered to
 *     80vh (header + cover must fit ~100vh; audited covers ran 86-92vh and
 *     pushed the hero H1 and CTA below the fold). Only the cover half is
 *     enforced here: the header's own height is asked for in prompts/header.md
 *     and never measured, so the cap assumes a compliant header.
 *  6. Nav collapse — WordPress's hamburger only engages below 600px, while
 *     an over-wide title/nav row wraps into a 2-3 row header across the
 *     whole tablet range. When the estimated single-row width exceeds the
 *     budget, the navigation gets `"overlayMenu":"always"`.
 *  7. Title scale — a site title at `section-title`/`display` competes with
 *     the hero's display H1 (audited ratios collapsed to 1.33x); it is
 *     rewritten to `heading` unless the build forced the oversized-wordmark
 *     archetype, whose whole point is a display-scale wordmark.
 *  8. Exact root archetype marker and contract-owned foreground/protection
 *     tokens, plus authoritative primary-action correspondence, header echo
 *     and duplicate-CTA dedupe against the delivered hero, and objective
 *     overlay-evidence checks on the generated opening markup.
 *
 * Runs BEFORE fix-blocks on purpose (same rationale as contrast-fix and
 * motion-sanity): repairs rewrite only the block-comment JSON attributes,
 * and the fix-blocks re-serialization regenerates the saved HTML from those
 * attributes. Class tokens obsoleted in the saved HTML are removed here too,
 * so the fixer's fixCustomClassname cannot resurrect them.
 */
final class HeaderHeroStep implements Step
{
    private const REPORT_FILE = 'header-hero.txt';

    /** The retired inner positioning hook; the outer shell owns geometry. */
    private const LEGACY_OVERLAY_CLASS = 'header-overlay';

    /** A stacked-mode page-opening cover above this many vh is lowered… */
    public const MAX_STACKED_COVER_VH = 80;

    /** …to this height, so header + cover fit one ~100vh viewport. */
    public const STACKED_COVER_VH = 80;

    /**
     * The single-row width the header must fit (a ~1024px viewport minus
     * gutters). Above it the nav collapses to a menu instead of wrapping.
     */
    public const ROW_BUDGET_PX = 1000;

    /**
     * Rough per-element widths for the row estimate, in px. Deliberately
     * coarse: uppercase caption nav labels track wide (~11px/char at 14px +
     * 0.14em letter-spacing), a heading-size wordmark runs ~17px/char, and a
     * caption button label ~9px/char inside ~56px of padding. The estimate
     * only has to separate "fits comfortably" from "wraps on tablets".
     */
    private const NAV_CHAR_PX = 11;
    private const NAV_GAP_PX = 28;
    private const TITLE_CHAR_PX = 17;
    private const BUTTON_CHAR_PX = 9;
    private const BUTTON_PAD_PX = 56;
    private const LOGO_GAP_PX = 16;
    private const CLUSTER_GAP_PX = 32;
    private const GUTTERS_PX = 64;

    public function id(): string
    {
        return 'header-hero';
    }

    public function label(): string
    {
        return 'Header/hero composition pass';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                'pages.json',
                'aboveFold.json',
                'designDirection.json',
                'siteSpec.json',
                'theme/theme.json',
                'theme/parts/*',
            ],
            writes: ['theme/parts/*', 'pages.json', 'aboveFold.json', HeaderBehavior::FILE, 'warnings.json'],
            concurrent: false,
        );
    }


    public function run(Project $project): void
    {
        if (!$project->exists('theme/parts/header.html')) {
            throw new \RuntimeException('header-hero: missing required theme/parts/header.html');
        }
        $pages = array_values(array_filter(
            (array) ($project->readJson('pages.json')['pages'] ?? []),
            'is_array',
        ));
        if ($pages === []) {
            throw new \RuntimeException('header-hero: pages.json has no pages');
        }
        $delivery = $project->readJson('aboveFold.json');
        AboveFoldContract::assertPhase($delivery, AboveFoldContract::PHASE_DELIVERY);
        $mode = (string) ($delivery['header']['mode'] ?? '');
        $archetype = (string) ($delivery['header']['archetype'] ?? '');
        $foreground = (string) ($delivery['header']['foreground_token'] ?? 'contrast');
        $protection = (string) ($delivery['header']['protection_token'] ?? 'base');
        $siteSpec = $project->exists('siteSpec.json') ? $project->readJson('siteSpec.json') : [];
        $siteName = (string) ($siteSpec['name'] ?? '');
        $pageTitles = array_map(static fn (array $p): string => (string) ($p['title'] ?? ''), $pages);

        // Behavior inputs (BIGR-762): the theme palette, motion-derived
        // transition, and page background feed the state resolver. The
        // contract's tokens are the authored color inputs, so resolved states
        // stay consistent with the persisted above-fold relation.
        $theme = $project->exists('theme/theme.json') ? $project->readJson('theme/theme.json') : [];
        $palette = ContrastFixStep::paletteMap($theme);
        $transition = HeaderBehavior::transitionFor(DesignDirectionStep::motionProfileFor($project));
        $pageBackground = self::pageBackgroundSlug($theme);
        $resolveBehavior = static function (
            string $behaviorMode,
            string $behaviorArchetype,
            string $behaviorForeground,
            string $behaviorProtection,
        ) use ($pages, $palette, $transition, $pageBackground): array {
            return HeaderBehavior::resolve(
                $pages,
                $behaviorMode,
                $palette,
                $behaviorArchetype !== '' ? $behaviorArchetype : null,
                $transition,
                $behaviorMode === AboveFoldContract::MODE_STACKED && $behaviorProtection !== ''
                    ? $behaviorProtection
                    : null,
                $behaviorForeground !== '' ? $behaviorForeground : null,
                $pageBackground,
            );
        };

        $report = [];
        $warnings = [];
        $writes = [];
        $headerRel = 'parts/header.html';
        $header = $project->readText('theme/' . $headerRel);
        $authoredPositions = self::removedAuthoredPositions($header);

        // Objective overlay evidence (BIGR-762): a planned overlay must be
        // earned by the generated opening markup. Problems do not flip the
        // relation here — they are injected into the contract facts below so
        // the finalizer records the downgrade through its one reviewed path.
        $openingProblems = $mode === AboveFoldContract::MODE_OVERLAY
            ? self::overlayOpeningProblems($project, $pages, $protection)
            : [];
        $withOverlayEvidence = static function (array $facts) use ($openingProblems): array {
            if ($openingProblems === []) {
                return $facts;
            }
            $support = is_array($facts['opening_overlay_support'] ?? null)
                ? $facts['opening_overlay_support']
                : [];
            foreach ($openingProblems as $problem) {
                $file = (string) $problem['file'];
                if (!str_starts_with($file, 'theme/parts/')) {
                    continue;
                }
                $support[basename($file, '.html')] = false;
            }
            $facts['opening_overlay_support'] = $support;
            return $facts;
        };

        $requestedBehavior = HeaderBehavior::behaviorFor($pages, $mode, $archetype !== '' ? $archetype : null);
        $behavior = $resolveBehavior($mode, $archetype, $foreground, $protection);
        if ($openingProblems === []
            && ($behavior['behavior'] !== $requestedBehavior || $behavior['mode'] !== $mode)) {
            $warnings[] = "file='" . HeaderBehavior::FILE . "'; block='behavior'; authored='"
                . $requestedBehavior . "' in mode '{$mode}'; delivered='"
                . $behavior['behavior'] . "' in mode '{$behavior['mode']}'; disposition=behavior downgraded "
                . 'because no palette-token foreground/surface pair could preserve readable top and scrolled states';
        }
        $result = self::fixHeader(
            $header,
            $mode,
            $siteName,
            $pageTitles,
            $archetype === 'oversized-wordmark',
            $archetype,
            $foreground,
            $protection,
            $behavior,
            $theme,
        );
        $writes[$headerRel] = $result['markup'];
        foreach ($result['notes'] as $note) {
            $report[] = "[{$headerRel}] {$note}";
        }
        [$headerNav, $navRepairs] = HeaderNavDestinations::rewrite($writes[$headerRel], $pages);
        $writes[$headerRel] = $headerNav;
        foreach ($navRepairs as $note) {
            $report[] = "[{$headerRel}] {$note}";
        }

        $headerFacts = AboveFoldPartFacts::headerFacts($writes[$headerRel]);
        if (($headerFacts['mode'] ?? null) === null) {
            $fallback = HeaderFallback::render(
                [],
                $delivery,
                'post-generation header no longer has one inspectable wp:group root',
            );
            $writes[$headerRel] = $fallback->markup;
            array_push($warnings, ...$fallback->warnings);
        }

        // Reconcile the authoritative CTA once more after rhythm/layout passes.
        // A unique paraphrase is restored as a successful repair. Loss or
        // ambiguity is kept separate so pages.json and the final contract can
        // be narrowed atomically below.
        $heroPart = (string) ($delivery['hero_part'] ?? '');
        $heroRel = 'parts/' . $heroPart . '.html';
        if ($heroPart !== '') {
            $front = self::frontPage($pages);
            $fallbackInput = [
                'site_spec' => $siteSpec,
                'page' => $front,
                'section' => SectionsStep::openingSection($front),
                'hero_blueprint' => [
                    'recipe' => $delivery['recipe'],
                    'mobile_transformation' => $delivery['mobile_transformation'],
                ],
            ];
            if ($project->exists('theme/' . $heroRel)) {
                $heroMarkup = $writes[$heroRel] ?? $project->readText('theme/' . $heroRel);
            } else {
                $fallback = HeroFallback::render(
                    $fallbackInput,
                    $delivery,
                    'the contract-owned hero part was absent at finalization',
                );
                $heroMarkup = $fallback->markup;
                array_push($warnings, ...$fallback->warnings);
            }
            $heroRepairs = [];
            try {
                $heroMarkup = GeneratedMarkup::withRootClassMarker(
                    $heroMarkup,
                    'hero-composition--',
                    'hero-composition--' . (string) $delivery['recipe'],
                    $heroPart,
                    $heroRepairs,
                );
                $heroMarkup = GeneratedMarkup::withRootClassMarker(
                    $heroMarkup,
                    'hero-mobile--',
                    'hero-mobile--' . (string) $delivery['mobile_transformation'],
                    $heroPart,
                    $heroRepairs,
                );
                $beforeLayout = $heroMarkup;
                $heroMarkup = GeneratedMarkup::constrainedPart($heroMarkup);
                if ($heroMarkup !== $beforeLayout) {
                    $heroRepairs[] = [
                        'code' => 'root-layout-constrained',
                        'part' => $heroPart,
                        'disposition' => 'repaired',
                    ];
                }
            } catch (\RuntimeException $error) {
                $fallback = HeroFallback::render($fallbackInput, $delivery, $error->getMessage());
                $heroMarkup = $fallback->markup;
                array_push($warnings, ...$fallback->warnings);
            }
            foreach ($heroRepairs as $repair) {
                $report[] = "[{$heroRel}] " . (string) json_encode(
                    $repair,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
            }
            $action = is_array($delivery['primary_action'] ?? null) ? $delivery['primary_action'] : null;
            // HTML-first authors the hero as a document. There is no planned
            // primary_action to reconcile against; stripping those buttons
            // leaves an empty first viewport.
            if ($action === null && $project->exists('design/home.html')) {
                $writes[$heroRel] = $heroMarkup;
                $report[] = "[{$heroRel}] kept authored hero buttons (HTML-first design; no planned primary_action)";
            } else {
                $actionResult = GeneratedMarkup::reconcilePrimaryAction(
                    $heroMarkup,
                    $action,
                    $heroPart,
                );
                $writes[$heroRel] = $actionResult['markup'];
                foreach ($actionResult['repairs'] as $repair) {
                    $report[] = "[{$heroRel}] " . (is_string($repair)
                        ? $repair
                        : (string) json_encode($repair, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
                array_push($warnings, ...$actionResult['warnings']);
            }

            // With the hero copy final, guarantee its longest headline word
            // fits the measure its layout chain implies — the CSS mid-word
            // break guard must stay dormant (BIGR-798).
            $fit = HeroHeadlineFit::apply($writes[$heroRel], $theme);
            $writes[$heroRel] = $fit['markup'];
            foreach ($fit['notes'] as $note) {
                $report[] = "[{$heroRel}] {$note}";
            }

            // With the hero's delivered copy and action known, drop the
            // header's duplicates of them (echoed caption lines, same-label
            // CTA, a tagline block that renders blank or paraphrases a hero
            // line) — the ownership split gives copy and action to the hero.
            $dedupe = self::dedupeAgainstHero(
                $writes[$headerRel],
                $writes[$heroRel],
                is_array($delivery['primary_action'] ?? null) ? $delivery['primary_action'] : null,
                PlaygroundArtifact::blogDescription($siteSpec),
            );
            $writes[$headerRel] = $dedupe['markup'];
            foreach ($dedupe['notes'] as $note) {
                $report[] = "[{$headerRel}] {$note}";
            }
            array_push($warnings, ...$dedupe['warnings']);
        } else {
            // No contract-owned hero part to compare against, but a blank
            // tagline block still renders a dead line — strip it here too.
            $dedupe = self::dedupeAgainstHero(
                $writes[$headerRel],
                '',
                null,
                PlaygroundArtifact::blogDescription($siteSpec),
            );
            $writes[$headerRel] = $dedupe['markup'];
            foreach ($dedupe['notes'] as $note) {
                $report[] = "[{$headerRel}] {$note}";
            }
            array_push($warnings, ...$dedupe['warnings']);
        }


        $partBytes = self::partBytes($project, $writes);
        $facts = $withOverlayEvidence(AboveFoldPartFacts::inspect($pages, $partBytes, $delivery));
        $degradationOffset = count((array) ($delivery['degradations'] ?? []));
        $final = AboveFoldContract::finalizeMarkup($delivery, $pages, $facts);

        // Rhythm may have invalidated image protection after delivery. Apply
        // only the resulting objective relation to the already-generated
        // header; no new aesthetic selection occurs here. The behavior
        // artifact is re-resolved for the final relation so the persisted
        // shell states never describe a mode the contract abandoned.
        if (($final['header']['mode'] ?? null) !== $mode
            || ($final['header']['archetype'] ?? null) !== $archetype
        ) {
            $mode = (string) $final['header']['mode'];
            $archetype = (string) $final['header']['archetype'];
            $foreground = (string) $final['header']['foreground_token'];
            $protection = (string) $final['header']['protection_token'];
            $behavior = $resolveBehavior($mode, $archetype, $foreground, $protection);
            $headerResult = self::fixHeader(
                $writes[$headerRel],
                $mode,
                $siteName,
                $pageTitles,
                $archetype === 'oversized-wordmark',
                $archetype,
                $foreground,
                $protection,
                $behavior,
                $theme,
            );
            $writes[$headerRel] = $headerResult['markup'];
            foreach ($headerResult['notes'] as $note) {
                $report[] = "[{$headerRel}] {$note}";
            }
        }

        // Overlay-evidence rows report the final resolved landing state, so
        // they are emitted once the final behavior is known.
        foreach ($openingProblems as $problem) {
            $warnings[] = "file='{$problem['file']}'; block='page-opening composition'; authored="
                . Warnings::value($problem['authored'])
                . "; delivered='{$behavior['behavior']}' in mode '{$behavior['mode']}'; "
                . 'disposition=overlay downgraded because the generated opening ' . $problem['reason'];
        }

        // Authored positioning is genuine loss only when the final resolved
        // shell has no equivalent behavior for it.
        foreach ($authoredPositions as $position) {
            if (self::outerShellPreservesPosition($position, $behavior)) {
                continue;
            }
            $warnings[] = "file='theme/{$headerRel}'; block='{$position['block']}'; authored="
                . Warnings::value($position['value'])
                . "; delivered=removed; disposition=inner {$position['type']} behavior removed because the "
                . "resolved '{$behavior['behavior']}' outer shell has no equivalent behavior for that block";
        }

        // The height budget follows the delivered relation, not the initial
        // one. An overlay that degrades here must receive the same objective
        // stacked cover cap as a build that started stacked.
        if (($final['header']['mode'] ?? null) === AboveFoldContract::MODE_STACKED) {
            self::capOpeningCovers($project, $pages, $writes, $report);
        }

        // An overlay that survived to the final relation may earn a truly
        // transparent resting state: when every delivered opening cover's own
        // dim proves the persisted foreground, the kit scrim is redundant
        // double darkening over an already-protected image (BIGR-778). A
        // just-short dim is raised as a recorded repair; any unprovable
        // opening keeps the scrim veil. The clear treatment snaps its
        // background paint on scroll (while its shadow may still transition),
        // because a fixed header's underlay can change between frames.
        if ($behavior['behavior'] === HeaderBehavior::OVERLAY_TO_SOLID
            && ($final['header']['mode'] ?? null) === AboveFoldContract::MODE_OVERLAY
            && self::grantClearOverlayTop($project, $final, $behavior, $palette, $writes, $report)
        ) {
            $behavior['topTreatment'] = HeaderBehavior::TREATMENT_TRANSPARENT;
            $headerResult = self::fixHeader(
                $writes[$headerRel],
                $mode,
                $siteName,
                $pageTitles,
                $archetype === 'oversized-wordmark',
                $archetype,
                $foreground,
                $protection,
                $behavior,
                $theme,
            );
            $writes[$headerRel] = $headerResult['markup'];
            foreach ($headerResult['notes'] as $note) {
                $report[] = "[{$headerRel}] {$note}";
            }
            $report[] = "[{$headerRel}] overlay resting scrim dropped: every opening cover's own dim "
                . 'proves the foreground, so the header starts truly transparent and gains its '
                . 'surface immediately when scrolled (the shadow retains the configured transition)';
        }

        if (is_array($delivery['primary_action'] ?? null) && !is_array($final['primary_action'] ?? null)) {
            if ($heroPart !== '' && ($project->exists('theme/' . $heroRel) || isset($writes[$heroRel]))) {
                $removedAction = GeneratedMarkup::withoutPrimaryAction(
                    $writes[$heroRel] ?? $project->readText('theme/' . $heroRel),
                    $delivery['primary_action'],
                    $heroPart,
                );
                $writes[$heroRel] = $removedAction['markup'];
                array_push($warnings, ...$removedAction['warnings']);
            }
            $pages = self::withoutFrontPrimaryAction($pages, $warnings);
        }

        // Re-read the complete in-memory transaction after relation-dependent
        // repairs. The pure finalizer is independently idempotent, so this is
        // an assertion-by-construction that the bytes, plan, and final
        // contract describe the same delivered state before persistence.
        $deliveryForRefinalize = $final;
        $deliveryForRefinalize['phase'] = AboveFoldContract::PHASE_DELIVERY;
        $partBytes = self::partBytes($project, $writes);
        $facts = $withOverlayEvidence(AboveFoldPartFacts::inspect($pages, $partBytes, $deliveryForRefinalize));
        $final = AboveFoldContract::finalizeMarkup($deliveryForRefinalize, $pages, $facts);
        $mode = (string) $final['header']['mode'];
        array_push($warnings, ...AboveFoldContract::warningRows($final, $degradationOffset));

        // Compute everything above before the first write: parts, pages and
        // final contract describe the same delivered state at the boundary.
        foreach ($writes as $rel => $markup) {
            $project->writeText('theme/' . $rel, $markup);
        }
        $pagesArtifact = $project->readJson('pages.json');
        $pagesArtifact['pages'] = $pages;
        $project->writeJson('pages.json', $pagesArtifact);
        $project->writeJson('aboveFold.json', $final);
        $project->writeJson(HeaderBehavior::FILE, $behavior);
        $project->addWarnings($this->id(), $warnings);
        if ($report === []) {
            $report[] = "Header mode '{$mode}', behavior '{$behavior['behavior']}': header and page-opening "
                . 'sections already satisfy the '
                . 'repairs this step makes. Header height is prompt-enforced only and was not measured.';
        }
        $project->writeText('logs/' . self::REPORT_FILE, implode("\n", $report) . "\n");
        Narrator::write(sprintf(
            "  header/hero: mode '%s', behavior '%s' (details: logs/%s)\n",
            $mode,
            $behavior['behavior'],
            self::REPORT_FILE,
        ));
    }

    /**
     * Repair the header part against the persisted contract and the resolved
     * behavior. Pure — unit-testable.
     *
     * @param list<string> $pageTitles page-list nav labels (a wp:page-list
     *                                 renders one item per site page)
     * @param array<mixed>|null $behavior resolved HeaderBehavior artifact;
     *                                    null keeps this helper useful for
     *                                    isolated legacy composition tests
     * @param array<mixed> $theme generated theme.json; used only to neutralize
     *                            recursive core/group padding on structural
     *                            header descendants
     * @return array{markup:string,notes:string[]}
     */
    public static function fixHeader(
        string $markup,
        string $mode,
        string $siteName,
        array $pageTitles = [],
        bool $oversizedForced = false,
        string $archetype = '',
        string $foreground = '',
        string $protection = '',
        ?array $behavior = null,
        array $theme = [],
    ): array {
        $doc = BlockMarkup::parse($markup);
        $notes = [];

        $top = $doc->topLevel();
        if ($archetype === 'centered-masthead' && $top !== null && self::looksLikeRowHeader($doc, $top)) {
            $archetype = 'standard-row';
            $notes[] = 'header archetype remapped to standard-row (designed row: wordmark, nav, optional CTA)';
        }
        if ($top !== null && $mode === AboveFoldContract::MODE_OVERLAY) {
            self::wireOverlay($doc, $top, $notes);
        } elseif ($top !== null) {
            self::stripOverlay($doc, $top, $notes);
        }
        self::stripInnerPositions($doc, $top, $notes);
        if ($top !== null) {
            self::enforceContractIdentity(
                $doc,
                $top,
                $mode,
                $archetype,
                $foreground,
                $protection,
                $notes,
            );
        }
        if ($top !== null && $behavior !== null) {
            $behavior = HeaderBehavior::validateArtifact($behavior);
            self::applyBehaviorClasses($doc, $top, $behavior, $notes);
            self::applyRootSurfaces($doc, $top, $behavior, $notes);
        }
        if ($top !== null) {
            self::neutralizeInheritedGroupPadding(
                $doc,
                $top,
                self::inheritedCoreGroupPaddingSides($theme),
                $notes,
            );
        }

        foreach ($doc->indices() as $i) {
            if ($doc->name($i) !== 'site-title' || $oversizedForced) {
                continue;
            }
            $attrs = $doc->attrs($i) ?? [];
            $size = (string) ($attrs['fontSize'] ?? '');
            if (in_array($size, ['section-title', 'display'], true)) {
                $attrs['fontSize'] = 'heading';
                $doc->setAttrs($i, $attrs);
                $doc->replaceInOwnHtml($i, "has-{$size}-font-size", 'has-heading-font-size');
                $notes[] = "site-title fontSize '{$size}' lowered to 'heading' "
                    . "(a wordmark at section scale competes with the hero's display headline)";
            }
        }

        $width = self::estimatedRowWidth($doc, $siteName, $pageTitles);
        if ($width > self::ROW_BUDGET_PX) {
            foreach ($doc->indices() as $i) {
                if ($doc->name($i) !== 'navigation') {
                    continue;
                }
                $attrs = $doc->attrs($i) ?? [];
                if (($attrs['overlayMenu'] ?? '') !== 'always') {
                    $attrs['overlayMenu'] = 'always';
                    $doc->setAttrs($i, $attrs);
                    $notes[] = "navigation set to overlayMenu:always (estimated row width ~{$width}px "
                        . 'exceeds the ' . self::ROW_BUDGET_PX . 'px budget; the core hamburger only '
                        . 'engages below 600px, so an over-wide row wraps across the whole tablet range)';
                }
                break;
            }
        }

        $rendered = $doc->render();
        if ($top !== null && $doc->name($top) === 'group') {
            $classRepairs = [];
            // The behavior artifact may deliberately start transparent (a
            // glass/transparent top treatment); only a solid top state
            // re-syncs an opaque surface into the saved HTML.
            $topSurface = $behavior === null ? $protection : (string) $behavior['topSurface'];
            if ($mode === AboveFoldContract::MODE_STACKED
                && $topSurface !== ''
                && $topSurface !== HeaderBehavior::TRANSPARENT
            ) {
                $surfaceNotes = [];
                $beforeSurface = $rendered;
                $rendered = GeneratedMarkup::withRootBackgroundColor(
                    $rendered,
                    $topSurface,
                    $surfaceNotes,
                    'header',
                );
                if ($rendered !== $beforeSurface) {
                    $notes[] = 'header background synchronized in block attributes and saved HTML';
                }
                array_push($notes, ...$surfaceNotes);
            }
            $rootForeground = $behavior === null ? $foreground : (string) $behavior['foreground'];
            if ($rootForeground !== '') {
                $rendered = GeneratedMarkup::withRootTextColor(
                    $rendered,
                    $rootForeground,
                    'header',
                    $classRepairs,
                );
            }
            if ($archetype !== '') {
                $rendered = GeneratedMarkup::withRootClassMarker(
                    $rendered,
                    'header-archetype--',
                    'header-archetype--' . $archetype,
                    'header',
                    $classRepairs,
                );
            }
            if ($classRepairs !== []) {
                $notes[] = 'header root class hooks synchronized in block attributes and saved HTML';
            }
        }
        [$rendered, $removedInlinePosition] = self::withoutInlinePosition($rendered);
        if ($removedInlinePosition) {
            $notes[] = 'removed inline position declaration(s) from the header part '
                . '(the assembled outer shell exclusively owns header positioning)';
        }
        return ['markup' => $rendered, 'notes' => $notes];
    }

    /**
     * Verify the generated first visual band, not only the plan that prompted
     * it. A missing image or a light leading group makes overlay aesthetically
     * wrong even though the trusted scrim would keep its text technically
     * readable, so the deterministic fallback uses ordinary stacked chrome.
     *
     * @param array<int,array<string,mixed>> $pages
     * @return list<array{file:string,authored:string,reason:string}>
     */
    private static function overlayOpeningProblems(Project $project, array $pages, string $protection = 'contrast'): array
    {
        $problems = [];
        foreach ($pages as $page) {
            $pageSlug = trim((string) ($page['slug'] ?? ''));
            $opening = SectionsStep::openingSection($page);
            $sectionSlug = is_array($opening) ? trim((string) ($opening['slug'] ?? '')) : '';
            if ($pageSlug === '' || $sectionSlug === '') {
                $problems[] = [
                    'file' => HeaderBehavior::FILE,
                    'authored' => "overlay-to-solid for page '{$pageSlug}'",
                    'reason' => 'has no locatable first section',
                ];
                continue;
            }
            $rel = 'theme/parts/' . SectionsStep::partSlug($pageSlug, $sectionSlug) . '.html';
            if (!$project->exists($rel)) {
                $problems[] = [
                    'file' => $rel,
                    'authored' => "overlay-to-solid for page '{$pageSlug}'",
                    'reason' => 'part is missing',
                ];
                continue;
            }
            $evidence = self::overlayOpeningEvidence($project->readText($rel), $protection);
            if ($evidence === null) {
                $problems[] = [
                    'file' => $rel,
                    'authored' => "overlay-to-solid for page '{$pageSlug}'",
                    'reason' => 'does not begin with an image-backed cover or a protection-token surface',
                ];
            }
        }
        return $problems;
    }

    /** Return the qualifying first-band evidence, or null when none exists. */
    private static function overlayOpeningEvidence(string $markup, string $protection = 'contrast'): ?string
    {
        $doc = BlockMarkup::parse($markup);
        $i = $doc->topLevel();
        if ($i === null || self::hasVisibleLeadingMarkup($markup, $doc->openingOffset($i))) {
            return null;
        }
        while ($i !== null) {
            $attrs = $doc->attrs($i) ?? [];
            if ($doc->name($i) === 'cover') {
                $hasMedia = trim((string) ($attrs['url'] ?? '')) !== ''
                    || isset($attrs['id'])
                    || ($attrs['useFeaturedImage'] ?? false) === true
                    || str_contains($doc->ownHtml($i), 'wp-block-cover__image-background');
                if ($hasMedia) {
                    return ($attrs['align'] ?? null) === 'full' ? 'image-backed cover' : null;
                }
                if (($attrs['overlayColor'] ?? null) === $protection
                    || ($attrs['backgroundColor'] ?? null) === $protection) {
                    return 'protection-token cover';
                }
                return null;
            }
            if (($attrs['backgroundColor'] ?? null) === $protection) {
                return 'protection-token surface';
            }
            if (!self::isTransparentZeroOffsetWrapper($doc, $i, $attrs)) {
                return null;
            }
            $children = $doc->children($i);
            $i = $children[0] ?? null;
        }
        return null;
    }

    /** Raw text or HTML before the first block would occupy the overlay's top edge. */
    private static function hasVisibleLeadingMarkup(string $markup, int $firstBlockOffset): bool
    {
        $leading = substr($markup, 0, $firstBlockOffset);
        $leading = preg_replace('/<!--.*?-->/s', '', $leading) ?? $leading;
        $leading = str_starts_with($leading, "\xEF\xBB\xBF") ? substr($leading, 3) : $leading;
        return trim($leading) !== '';
    }

    /**
     * Only a neutral group may be traversed on the way to the first visual
     * band. Any authored surface or top spacing means the nested cover does
     * not actually meet the page's top edge beneath the viewport-wide header.
     *
     * @param array<mixed> $attrs
     */
    private static function isTransparentZeroOffsetWrapper(BlockMarkup $doc, int $i, array $attrs): bool
    {
        if ($doc->name($i) !== 'group') {
            return false;
        }

        foreach ([
            $attrs['backgroundColor'] ?? null,
            $attrs['gradient'] ?? null,
            $attrs['style']['color']['background'] ?? null,
            $attrs['style']['color']['gradient'] ?? null,
            $attrs['style']['background'] ?? null,
        ] as $surface) {
            if (self::hasAuthoredValue($surface)) {
                return false;
            }
        }

        $spacing = is_array($attrs['style']['spacing'] ?? null)
            ? $attrs['style']['spacing']
            : [];
        foreach (['padding', 'margin'] as $property) {
            $value = $spacing[$property] ?? null;
            $top = is_array($value) ? ($value['top'] ?? null) : $value;
            if (self::hasAuthoredValue($top) && !self::isZeroCssLength($top)) {
                return false;
            }
        }

        $ownHtml = $doc->ownHtml($i);
        $neutralWrapper = preg_replace('/<!--(?!\s*wp:).*?-->/s', '', $ownHtml) ?? $ownHtml;
        if (preg_match('/^\s*<(?:div|main|section|article|aside)\b[^>]*>\s*$/is', $neutralWrapper) !== 1) {
            // Text, media, or raw HTML before the first child block occupies
            // the top edge just as surely as document-level leading markup.
            return false;
        }
        if (preg_match('/\bbackground(?:-color|-image)?\s*:/i', $ownHtml)
            || preg_match('/\bclass\s*=\s*(["\'])[^"\']*\bhas-background\b[^"\']*\1/is', $ownHtml)
            || preg_match('/\bhas-[a-z0-9-]+-background-color\b/i', $ownHtml)) {
            return false;
        }
        if (preg_match_all(
            '/\b(?:padding|margin)(?:-top|-block-start|-block)?\s*:\s*([^;"\']+)/i',
            $ownHtml,
            $matches,
        )) {
            foreach ($matches[1] as $value) {
                $top = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';
                if ($top !== '' && !self::isZeroCssLength($top)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function hasAuthoredValue(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    private static function isZeroCssLength(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value === 0.0;
        }
        return is_string($value)
            && preg_match('/^[+-]?(?:0+(?:\.0*)?|\.0+)(?:[a-z%]+)?(?:\s*!important)?$/i', trim($value)) === 1;
    }

    /** Designed HTML-first headers are a brand + nav (+ CTA) row, not a stacked masthead. */
    public static function looksLikeRowHeader(BlockMarkup $doc, int $top): bool
    {
        $hasNav = false;
        $hasBrand = false;
        foreach ($doc->children($top) as $child) {
            $name = $doc->name($child);
            if ($name === 'navigation') {
                $hasNav = true;
            }
            if (in_array($name, ['site-title', 'buttons'], true)) {
                $hasBrand = true;
            }
            $class = (string) (($doc->attrs($child) ?? [])['className'] ?? '');
            $html = $doc->ownHtml($child);
            if (str_contains($class, 'brand') || str_contains($html, 'class="brand"') || str_contains($html, "class='brand'")) {
                $hasBrand = true;
            }
        }
        return $hasNav && $hasBrand;
    }

    /**
     * Persist the exact contract identity on the header root. This is a
     * relation marker, not an aesthetic rewrite: stale generated markers and
     * colors are normalized to the already-selected above-fold contract.
     *
     * @param list<string> $notes
     */
    private static function enforceContractIdentity(
        BlockMarkup $doc,
        int $top,
        string $mode,
        string $archetype,
        string $foreground,
        string $protection,
        array &$notes,
    ): void {
        $attrs = $doc->attrs($top) ?? [];
        $tokens = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];
        $removed = [];
        foreach ($tokens as $token) {
            if (str_starts_with($token, 'header-archetype--')) {
                $removed[] = $token;
                $doc->removeClassTokenInOwnHtml($top, $token);
                continue;
            }
            $kept[] = $token;
        }
        $marker = $archetype === '' ? '' : 'header-archetype--' . $archetype;
        if ($marker !== '') {
            $kept[] = $marker;
        }
        $kept = array_values(array_unique($kept));
        if ($kept === []) {
            unset($attrs['className']);
        } else {
            $attrs['className'] = implode(' ', $kept);
        }
        if ($marker !== '' && ($removed !== [$marker] || !in_array($marker, $tokens, true))) {
            $notes[] = "header root marked '{$marker}' from the persisted above-fold contract";
        }

        if ($foreground !== '' && (string) ($attrs['textColor'] ?? '') !== $foreground) {
            $previous = trim((string) ($attrs['textColor'] ?? ''));
            if ($previous !== '') {
                $doc->removeClassTokenInOwnHtml($top, "has-{$previous}-color");
            }
            $attrs['textColor'] = $foreground;
            $notes[] = "header foreground normalized to '{$foreground}' from the persisted above-fold contract";
        }

        if ($mode === AboveFoldContract::MODE_STACKED && $protection !== '') {
            $previous = trim((string) ($attrs['backgroundColor'] ?? ''));
            if ($previous !== '' && $previous !== $protection) {
                $doc->removeClassTokenInOwnHtml($top, "has-{$previous}-background-color");
            }
            if ($previous !== $protection) {
                $attrs['backgroundColor'] = $protection;
                $notes[] = "stacked header surface normalized to '{$protection}' from the persisted above-fold contract";
            }
            $gradient = trim((string) ($attrs['gradient'] ?? ''));
            if ($gradient !== '') {
                unset($attrs['gradient']);
                $doc->removeClassTokenInOwnHtml($top, "has-{$gradient}-gradient-background");
                $notes[] = 'stacked header gradient removed so its protection token remains authoritative';
            }
            if (isset($attrs['style']['color']['background'])) {
                unset($attrs['style']['color']['background']);
                if (($attrs['style']['color'] ?? []) === []) {
                    unset($attrs['style']['color']);
                }
                if (($attrs['style'] ?? []) === []) {
                    unset($attrs['style']);
                }
                $notes[] = 'stacked header custom background removed so its protection token remains authoritative';
            }
        }

        $doc->setAttrs($top, $attrs);
    }


    /**
     * Return the non-zero vertical sides a global core/group style applies to
     * every Group block. Scalar padding is a four-side shorthand, so it
     * activates both vertical sides. Malformed containers are left for the
     * theme normalizer/validator instead of guessed at here.
     *
     * @param array<mixed> $theme
     * @return list<string>
     */
    private static function inheritedCoreGroupPaddingSides(array $theme): array
    {
        $padding = $theme['styles']['blocks']['core/group']['spacing']['padding'] ?? null;
        if (is_string($padding) || is_int($padding) || is_float($padding)) {
            return self::isZeroCssLength($padding) ? [] : ['top', 'bottom'];
        }
        if (!is_array($padding) || ($padding !== [] && array_is_list($padding))) {
            return [];
        }

        $sides = [];
        foreach (['top', 'bottom'] as $side) {
            if (self::hasAuthoredValue($padding[$side] ?? null)
                && !self::isZeroCssLength($padding[$side])) {
                $sides[] = $side;
            }
        }
        return $sides;
    }

    /**
     * Structural Groups beneath the header root must not inherit section-scale
     * vertical padding. Add a zero only when a side has no authored value;
     * explicit component padding survives unchanged. The root is excluded so
     * its prompt-owned sm/md breathing room remains authoritative.
     *
     * @param list<string> $sides
     * @param list<string> $notes
     */
    private static function neutralizeInheritedGroupPadding(
        BlockMarkup $doc,
        int $top,
        array $sides,
        array &$notes,
    ): void {
        if ($sides === []) {
            return;
        }

        $changed = 0;
        foreach ($doc->indices() as $i) {
            if ($i === $top || $doc->name($i) !== 'group' || !self::isDescendantOf($doc, $i, $top)) {
                continue;
            }
            $attrs = $doc->attrs($i) ?? [];
            $style = $attrs['style'] ?? null;
            if ($style !== null
                && (!is_array($style) || ($style !== [] && array_is_list($style)))) {
                continue;
            }
            $style = is_array($style) ? $style : [];
            $spacing = $style['spacing'] ?? null;
            if ($spacing !== null
                && (!is_array($spacing) || ($spacing !== [] && array_is_list($spacing)))) {
                continue;
            }
            $spacing = is_array($spacing) ? $spacing : [];
            $padding = $spacing['padding'] ?? null;
            // A scalar is an explicit four-side value. Preserve it rather than
            // replacing authored component spacing under the guise of repair.
            if ($padding !== null && !is_array($padding)) {
                continue;
            }
            if (is_array($padding) && $padding !== [] && array_is_list($padding)) {
                continue;
            }

            $nodeChanged = false;
            foreach ($sides as $side) {
                if (self::hasAuthoredValue($padding[$side] ?? null)) {
                    continue;
                }
                $padding[$side] = '0';
                $nodeChanged = true;
            }
            if ($nodeChanged) {
                $spacing['padding'] = $padding;
                $style['spacing'] = $spacing;
                $attrs['style'] = $style;
                $doc->setAttrs($i, $attrs);
                $changed++;
            }
        }

        if ($changed > 0) {
            $notes[] = 'neutralized inherited core/group ' . implode('/', $sides)
                . " padding on {$changed} descendant header group"
                . ($changed === 1 ? '' : 's')
                . ' (explicit component and root padding preserved)';
        }
    }

    private static function isDescendantOf(BlockMarkup $doc, int $i, int $ancestor): bool
    {
        for ($parent = $doc->parent($i); $parent !== null; $parent = $doc->parent($parent)) {
            if ($parent === $ancestor) {
                return true;
            }
        }
        return false;
    }

    /**
     * Overlay mode: the top-level group remains visually transparent. The
     * legacy `header-overlay` hook is removed because it positioned the inner
     * group absolutely; the assembled outer shell now owns that geometry.
     */
    private static function wireOverlay(BlockMarkup $doc, int $top, array &$notes): void
    {
        $attrs = $doc->attrs($top) ?? [];
        $tokens = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $changed = [];
        if (in_array(self::LEGACY_OVERLAY_CLASS, $tokens, true)) {
            $tokens = array_values(array_diff($tokens, [self::LEGACY_OVERLAY_CLASS]));
            if ($tokens === []) {
                unset($attrs['className']);
            } else {
                $attrs['className'] = implode(' ', $tokens);
            }
            $doc->removeClassTokenInOwnHtml($top, self::LEGACY_OVERLAY_CLASS);
            $changed[] = "removed the legacy '" . self::LEGACY_OVERLAY_CLASS . "' positioning hook";
        }
        $background = (string) ($attrs['backgroundColor'] ?? '');
        if ($background !== '') {
            unset($attrs['backgroundColor']);
            $doc->removeClassTokenInOwnHtml($top, 'has-background');
            $doc->removeClassTokenInOwnHtml($top, "has-{$background}-background-color");
            $changed[] = "removed the opaque '{$background}' background";
        }
        $gradient = (string) ($attrs['gradient'] ?? '');
        if ($gradient !== '') {
            unset($attrs['gradient']);
            $doc->removeClassTokenInOwnHtml($top, 'has-background');
            $doc->removeClassTokenInOwnHtml($top, "has-{$gradient}-gradient-background");
            $changed[] = "removed the '{$gradient}' gradient background";
        }
        if (isset($attrs['style']['color']['background'])) {
            unset($attrs['style']['color']['background']);
            if (($attrs['style']['color'] ?? null) === []) {
                unset($attrs['style']['color']);
            }
            $doc->removeClassTokenInOwnHtml($top, 'has-background');
            $changed[] = 'removed the custom background color';
        }
        if (isset($attrs['style']) && $attrs['style'] === []) {
            unset($attrs['style']);
        }
        if ($changed !== []) {
            $doc->setAttrs($top, $attrs);
            $notes[] = 'overlay header wiring: ' . implode(', ', $changed)
                . ' (overlay mode starts transparently over the hero; the outer shell owns positioning)';
        }
    }

    /**
     * Remove the obsolete inner overlay-positioning class in every mode.
     */
    private static function stripOverlay(BlockMarkup $doc, int $top, array &$notes): void
    {
        $attrs = $doc->attrs($top) ?? [];
        $tokens = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!in_array(self::LEGACY_OVERLAY_CLASS, $tokens, true)) {
            return;
        }
        $kept = array_values(array_diff($tokens, [self::LEGACY_OVERLAY_CLASS]));
        if ($kept === []) {
            unset($attrs['className']);
        } else {
            $attrs['className'] = implode(' ', $kept);
        }
        $doc->setAttrs($top, $attrs);
        $doc->removeClassTokenInOwnHtml($top, self::LEGACY_OVERLAY_CLASS);
        $notes[] = "removed the legacy '" . self::LEGACY_OVERLAY_CLASS . "' class "
            . '(the assembled outer shell exclusively owns header positioning)';
    }

    /**
     * The part root may never position the header shell. Descendant relative
     * positioning can be meaningful local layout and survives; only nested
     * sticky/fixed behavior is removed as unsupported persistent chrome.
     */
    private static function stripInnerPositions(BlockMarkup $doc, ?int $top, array &$notes): void
    {
        $removed = [];
        foreach ($doc->indices() as $i) {
            $attrs = $doc->attrs($i) ?? [];
            if (!isset($attrs['style']['position'])) {
                continue;
            }
            $position = $attrs['style']['position'];
            $type = is_array($position) ? strtolower(trim((string) ($position['type'] ?? ''))) : '';
            if ($i !== $top && !in_array($type, ['sticky', 'fixed'], true)) {
                continue;
            }
            unset($attrs['style']['position']);
            if (($attrs['style'] ?? null) === []) {
                unset($attrs['style']);
            }
            $doc->setAttrs($i, $attrs);
            $removed[] = $doc->name($i) . '=' . json_encode($position, JSON_UNESCAPED_SLASHES);
        }
        if ($removed !== []) {
            $notes[] = 'removed inner block position attribute(s) [' . implode(', ', $removed) . '] '
                . '(the assembled outer shell exclusively owns header positioning)';
        }
    }

    /**
     * Canonicalize the generated root's visual-state hooks. Stale tokens are
     * removed from both comment attrs and saved HTML so fix-blocks cannot
     * resurrect an obsolete behavior; new tokens are written to className
     * and mirrored onto the saved wrapper in the same pass.
     *
     * @param array<mixed> $behavior
     */
    private static function applyBehaviorClasses(
        BlockMarkup $doc,
        int $top,
        array $behavior,
        array &$notes,
    ): void {
        $expected = HeaderBehavior::rootClasses($behavior);
        $attrs = $doc->attrs($top) ?? [];
        $tokens = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $isOwned = static fn (string $token): bool => $token === self::LEGACY_OVERLAY_CLASS
            || $token === 'header-transition-instant'
            || (bool) preg_match('/^header-(?:behavior|start|scrolled|foreground|top)-[a-z0-9-]+$/', $token);
        $kept = array_values(array_filter($tokens, static fn (string $token): bool => !$isOwned($token)));
        $canonical = array_values(array_unique(array_merge($kept, $expected)));
        $changed = $canonical !== $tokens;
        if ($canonical === []) {
            unset($attrs['className']);
        } else {
            $attrs['className'] = implode(' ', $canonical);
        }
        if ($changed) {
            $doc->setAttrs($top, $attrs);
        }

        $removedHtml = [];
        if (preg_match_all(
            '/\bheader-(?:behavior|start|scrolled|foreground|top)-[a-z0-9-]+\b'
                . '|\bheader-transition-instant\b|\bheader-overlay\b/',
            $doc->ownHtml($top),
            $matches,
        )) {
            foreach (array_values(array_unique($matches[0])) as $token) {
                if (!in_array($token, $expected, true)) {
                    $doc->removeClassTokenInOwnHtml($top, $token);
                    $removedHtml[] = $token;
                }
            }
        }
        $addedHtml = self::addSavedRootClasses($doc, $top, $expected);
        if ($changed || $removedHtml !== [] || $addedHtml !== []) {
            $notes[] = "root behavior classes canonicalized for '{$behavior['behavior']}'"
                . ($removedHtml === [] ? '' : '; removed stale saved-HTML tokens ' . implode(', ', $removedHtml))
                . ($addedHtml === [] ? '' : '; mirrored onto saved HTML: ' . implode(', ', $addedHtml));
        }
    }

    /**
     * Comment JSON is not enough: validate-theme and the header kit both
     * read the saved wrapper. Mirror missing expected tokens onto the
     * root's own class attribute so a later fix-blocks miss cannot leave
     * sticky/glass hooks comment-only.
     *
     * @param list<string> $expected
     * @return list<string>
     */
    private static function addSavedRootClasses(BlockMarkup $doc, int $top, array $expected): array
    {
        if ($expected === []) {
            return [];
        }
        $own = $doc->ownHtml($top);
        if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/is', $own, $match) !== 1) {
            return [];
        }
        $htmlTokens = preg_split('/\s+/', trim($match[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $missing = [];
        foreach ($expected as $class) {
            if ($class !== '' && !in_array($class, $htmlTokens, true)) {
                $missing[] = $class;
            }
        }
        if ($missing === []) {
            return [];
        }
        $anchor = in_array('wp-block-group', $htmlTokens, true)
            ? 'wp-block-group'
            : ($htmlTokens[0] ?? '');
        if ($anchor === '') {
            return [];
        }
        $doc->replaceClassTokenInOwnHtml($top, $anchor, $anchor . ' ' . implode(' ', $missing));
        return $missing;
    }

    /** @param array<mixed> $behavior */
    private static function applyRootSurfaces(
        BlockMarkup $doc,
        int $top,
        array $behavior,
        array &$notes,
    ): void {
        $attrs = $doc->attrs($top) ?? [];
        $changed = [];
        $oldBackground = trim((string) ($attrs['backgroundColor'] ?? ''));
        $oldForeground = trim((string) ($attrs['textColor'] ?? ''));
        $topSurface = (string) $behavior['topSurface'];
        $foreground = (string) $behavior['foreground'];

        if ($topSurface === HeaderBehavior::TRANSPARENT) {
            if ($oldBackground !== '') {
                unset($attrs['backgroundColor']);
                $doc->removeClassTokenInOwnHtml($top, "has-{$oldBackground}-background-color");
                $doc->removeClassTokenInOwnHtml($top, 'has-background');
                $changed[] = "background '{$oldBackground}' removed for the transparent top state";
            }
        } elseif ($oldBackground !== $topSurface) {
            $attrs['backgroundColor'] = $topSurface;
            if ($oldBackground !== '') {
                $doc->removeClassTokenInOwnHtml($top, "has-{$oldBackground}-background-color");
            }
            $changed[] = "background set to safe palette surface '{$topSurface}'";
        }
        if (isset($attrs['gradient'])) {
            $gradient = (string) $attrs['gradient'];
            unset($attrs['gradient']);
            $doc->removeClassTokenInOwnHtml($top, "has-{$gradient}-gradient-background");
            $changed[] = "gradient '{$gradient}' removed so the deterministic surface is authoritative";
        }
        if (isset($attrs['style']['color']['background'])) {
            unset($attrs['style']['color']['background']);
            $changed[] = 'custom background removed so the deterministic palette surface is authoritative';
        }
        if (isset($attrs['style']['color']['text'])) {
            unset($attrs['style']['color']['text']);
            $changed[] = 'custom text color removed so the deterministic foreground is authoritative';
        }
        if (($attrs['style']['color'] ?? null) === []) {
            unset($attrs['style']['color']);
        }
        if (($attrs['style'] ?? null) === []) {
            unset($attrs['style']);
        }
        if ($oldForeground !== $foreground) {
            $attrs['textColor'] = $foreground;
            if ($oldForeground !== '') {
                $doc->removeClassTokenInOwnHtml($top, "has-{$oldForeground}-color");
            }
            $changed[] = "foreground set to safe palette token '{$foreground}'";
        }
        if ($changed !== []) {
            $doc->setAttrs($top, $attrs);
            $notes[] = 'root top/scrolled color contract: ' . implode(', ', $changed);
        }
    }

    /**
     * An HTML tag with its attributes; quoted values may contain `<`/`>`
     * without ending the match. Both saved-HTML position passes below use it
     * so only real tag attributes are ever inspected or rewritten.
     */
    private const HTML_TAG_PATTERN = '/<[a-z][a-z0-9-]*(?:[^<>"\']|"[^"]*"|\'[^\']*\')*>/i';

    /**
     * Inline HTML can repeat a block comment's `style.position`. Remove any
     * root position, plus nested sticky/fixed declarations, while preserving
     * local descendant relative positioning and every neighboring property.
     *
     * Regions come from the block parser: each block contributes only the
     * HTML it owns, and only tags inside that HTML are edited — visible text
     * that merely mentions `style="position:fixed"` is never rewritten
     * (BlockMarkup::replaceInOwnHtml documents the same contract), and a
     * leading non-block comment cannot displace the true root region.
     *
     * @return array{0:string,1:bool}
     */
    private static function withoutInlinePosition(string $markup): array
    {
        $doc = BlockMarkup::parse($markup);
        $top = $doc->topLevel();
        $edits = [];
        foreach ($doc->indices() as $i) {
            $own = $doc->ownHtml($i);
            [$stripped, $nodeChanged] = self::stripInlinePositionFromTags($own, $i !== $top);
            if ($nodeChanged) {
                $edits[] = [
                    'start' => $doc->openingOffset($i) + $doc->openingLength($i),
                    'length' => strlen($own),
                    'content' => $stripped,
                ];
            }
        }
        if ($edits === []) {
            return [$markup, false];
        }
        usort($edits, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($edits as $edit) {
            $markup = substr_replace($markup, $edit['content'], $edit['start'], $edit['length']);
        }
        return [$markup, true];
    }

    /**
     * Apply the position strip only inside HTML tags, so text content in the
     * same owned region survives verbatim.
     *
     * @return array{0:string,1:bool}
     */
    private static function stripInlinePositionFromTags(string $html, bool $persistentOnly): array
    {
        $changed = false;
        $result = preg_replace_callback(
            self::HTML_TAG_PATTERN,
            static function (array $match) use (&$changed, $persistentOnly): string {
                [$tag, $tagChanged] = self::stripInlinePositionDeclarations($match[0], $persistentOnly);
                $changed = $changed || $tagChanged;
                return $tag;
            },
            $html,
        );
        return [is_string($result) ? $result : $html, $changed];
    }

    /** @return array{0:string,1:bool} */
    private static function stripInlinePositionDeclarations(string $html, bool $persistentOnly): array
    {
        $changed = false;
        $result = preg_replace_callback(
            '/\sstyle\s*=\s*(["\'])(.*?)\1/is',
            static function (array $match) use (&$changed, $persistentOnly): string {
                $kept = [];
                $removed = false;
                foreach (explode(';', $match[2]) as $declaration) {
                    if (trim($declaration) === '') {
                        continue;
                    }
                    $property = strtolower(trim((string) strstr($declaration, ':', true)));
                    if ($property === 'position') {
                        $value = strtolower(trim((string) substr($declaration, strpos($declaration, ':') + 1)));
                        if ($persistentOnly && !in_array($value, ['sticky', 'fixed'], true)) {
                            $kept[] = trim($declaration);
                            continue;
                        }
                        $removed = true;
                        continue;
                    }
                    $kept[] = trim($declaration);
                }
                if (!$removed) {
                    return $match[0];
                }
                $changed = true;
                return $kept === [] ? '' : ' style=' . $match[1] . implode(';', $kept) . $match[1];
            },
            $html,
        );
        return [is_string($result) ? $result : $html, $changed];
    }

    /**
     * The palette slug theme.json paints behind the page body, in either of
     * the preset spellings the pipeline emits. A transparent sticky start
     * reveals exactly this color, so the resolver verifies against it; null
     * (raw hex or absent) lets the resolver use its base-token convention.
     *
     * @param array<string,mixed> $theme
     */
    private static function pageBackgroundSlug(array $theme): ?string
    {
        $value = (string) ($theme['styles']['color']['background'] ?? '');
        if (preg_match('/^var\(--wp--preset--color--([a-z0-9-]+)\)$/', trim($value), $match)
            || preg_match('/^var:preset\|color\|([a-z0-9-]+)$/', trim($value), $match)) {
            return $match[1];
        }
        return null;
    }

    /** @return array{0:?string,1:?string} */
    private static function authoredRootColors(string $markup): array
    {
        $doc = BlockMarkup::parse($markup);
        $top = $doc->topLevel();
        if ($top === null) {
            return [null, null];
        }
        $attrs = $doc->attrs($top) ?? [];
        $background = trim((string) ($attrs['backgroundColor'] ?? ''));
        $foreground = trim((string) ($attrs['textColor'] ?? ''));
        return [$background !== '' ? $background : null, $foreground !== '' ? $foreground : null];
    }


    /**
     * Sticky/fixed authored behavior becomes genuine loss only when the final
     * behavior is static. Return compact source evidence for actionable rows.
     *
     * @return list<array{block:string,type:string,value:mixed,root:bool}>
     */
    private static function removedAuthoredPositions(string $markup): array
    {
        $doc = BlockMarkup::parse($markup);
        $top = $doc->topLevel();
        $findings = [];
        foreach ($doc->indices() as $i) {
            $position = ($doc->attrs($i) ?? [])['style']['position'] ?? null;
            $type = is_array($position) ? strtolower(trim((string) ($position['type'] ?? ''))) : '';
            if ($position === null || ($i !== $top && !in_array($type, ['sticky', 'fixed'], true))) {
                continue;
            }
            $findings[] = [
                'block' => 'wp:' . $doc->name($i) . '[' . $i . ']',
                'type' => $type,
                'value' => $position,
                'root' => $i === $top,
            ];
        }
        foreach ($doc->indices() as $i) {
            $root = $i === $top;
            foreach (self::inlineStylePositionTypes($doc->ownHtml($i)) as [$type, $evidence]) {
                if (!$root && !in_array($type, ['sticky', 'fixed'], true)) {
                    continue;
                }
                $duplicate = false;
                foreach ($findings as $finding) {
                    if ($finding['root'] === $root && $finding['type'] === $type) {
                        $duplicate = true;
                        break;
                    }
                }
                if (!$duplicate) {
                    $findings[] = [
                        'block' => $root ? 'root saved HTML style attribute' : 'descendant saved HTML style attribute',
                        'type' => $type,
                        'value' => $evidence,
                        'root' => $root,
                    ];
                }
            }
        }
        return $findings;
    }

    /**
     * Position declarations found in `style` attributes of real tags. Text
     * content that mentions a declaration is not authored positioning.
     *
     * @return list<array{0:string,1:string}> [type, matched declaration]
     */
    private static function inlineStylePositionTypes(string $html): array
    {
        $found = [];
        if (!preg_match_all(self::HTML_TAG_PATTERN, $html, $tags)) {
            return $found;
        }
        foreach ($tags[0] as $tag) {
            if (!preg_match_all('/\sstyle\s*=\s*(["\'])(.*?)\1/is', $tag, $styles, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($styles as $style) {
                if (preg_match_all('/\bposition\s*:\s*([a-z-]+)\b/i', $style[2], $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $found[] = [strtolower($match[1]), $match[0]];
                    }
                }
            }
        }
        return $found;
    }

    /** @param array{root:bool,type:string} $position @param array<mixed> $behavior */
    private static function outerShellPreservesPosition(array $position, array $behavior): bool
    {
        if (!$position['root']) {
            return false;
        }
        return match ($behavior['behavior']) {
            HeaderBehavior::STICKY_SOFT => in_array($position['type'], ['sticky', 'fixed'], true),
            HeaderBehavior::OVERLAY_TO_SOLID => in_array($position['type'], ['absolute', 'sticky', 'fixed'], true),
            default => false,
        };
    }



    /**
     * Remove authored header lines that duplicate the hero's copy inside the
     * same first viewport. Two audited defect classes:
     *  1. Echo — an authored header caption line repeating a short hero
     *     eyebrow/location/heading line (prompts/header.md's NO ECHO rule,
     *     violated twice in audited builds: the pair renders ~150px apart and
     *     reads as a rendering bug).
     *  2. Duplicate CTA — a header button whose label repeats the contract's
     *     primary action; the ownership split gives the primary action to the
     *     hero, so the header copy is the redundant one.
     *  3. Tagline collision (BIGR-773) — wp:site-tagline is a dynamic block,
     *     so its rendered text is invisible in the markup; the caller passes
     *     the one string WordPress will render (the blogdescription). A blank
     *     tagline renders as a dead line inside the lockup, and a stated one
     *     that paraphrases a hero eyebrow/short line duplicates copy the
     *     ownership split gives to the hero — identity is the site NAME, so
     *     the tagline block is the one removed in both cases.
     * Pure — unit-testable.
     *
     * @param array{label:string,intent:string,destination:string}|null $primaryAction
     * @param string $taglineText the text wp:site-tagline renders at runtime
     * @return array{markup:string,notes:string[],warnings:string[]}
     */
    public static function dedupeAgainstHero(
        string $headerMarkup,
        string $heroMarkup,
        ?array $primaryAction,
        string $taglineText = '',
    ): array {
        $heroLines = [];
        $hero = BlockMarkup::parse($heroMarkup);
        foreach ($hero->indices() as $i) {
            $name = $hero->name($i);
            if ($name !== 'paragraph' && $name !== 'heading') {
                continue;
            }
            $tokens = self::textTokens($hero->innerHtml($i));
            // Only short lines are echo candidates: eyebrows, location lines,
            // headlines. Standfirst-length paragraphs never legitimately
            // reappear in a header, and matching them would risk stripping
            // unrelated chrome text on incidental word overlap.
            if (count($tokens) >= 2 && count($tokens) <= 12) {
                $heroLines[] = $tokens;
            }
        }
        $actionLabel = $primaryAction === null
            ? []
            : self::textTokens((string) ($primaryAction['label'] ?? ''));
        $taglineText = trim($taglineText);
        $taglineLeafTokens = self::textTokens($taglineText);
        $taglineTokens = self::contentTokens($taglineLeafTokens);

        $doc = BlockMarkup::parse($headerMarkup);
        /**
         * Discover every duplicate before deciding which generated boundary
         * can be removed. A candidate may contain another candidate, and an
         * outer candidate is only removable when every descendant block is
         * also covered by this transaction.
         *
         * @var list<array{
         *     index:int,
         *     block_start:int,
         *     target:int,
         *     start:int,
         *     end:?int,
         *     raw_survivor:bool,
         *     path:string,
         *     authored:mixed,
         *     wrapper?:array{index:int,path:string,markup:string},
         *     removal_note:string,
         *     removal_disposition:string,
         *     retained_note:string,
         *     retained_disposition:string
         * }> $candidates
         */
        $candidates = [];
        $ordinals = [];
        foreach ($doc->indices() as $i) {
            $name = $doc->name($i);
            $ordinals[$name] = ($ordinals[$name] ?? 0) + 1;
            $path = "wp:{$name}[{$ordinals[$name]}]";
            if ($name === 'site-tagline') {
                $start = $doc->openingOffset($i);
                $end = $doc->endOffset($i);
                $capturedEnd = $end ?? ($start + $doc->openingLength($i));
                $wrapperIndex = $end === null ? null : self::dedupeDedicatedTaglineWrapper($doc, $i);
                $target = $wrapperIndex ?? $i;
                $targetStart = $doc->openingOffset($target);
                $targetEnd = $doc->endOffset($target);
                $wrapper = null;
                if ($wrapperIndex !== null && $targetEnd !== null) {
                    $wrapper = [
                        'index' => $wrapperIndex,
                        'path' => self::dedupeBlockPath($doc, $wrapperIndex),
                        'markup' => substr($headerMarkup, $targetStart, $targetEnd - $targetStart),
                    ];
                }
                $authored = [
                    'rendered_text' => $taglineText,
                    'markup' => substr($headerMarkup, $start, $capturedEnd - $start),
                ];
                if ($taglineText === '') {
                    $candidates[] = [
                        'index' => $i,
                        'block_start' => $start,
                        'target' => $target,
                        'start' => $targetStart,
                        'end' => $targetEnd,
                        'raw_survivor' => self::dedupeTaglineHasRawSurvivor(
                            $doc,
                            $i,
                            $taglineLeafTokens,
                        ),
                        'path' => $path,
                        'authored' => $authored,
                        'wrapper' => $wrapper,
                        'removal_note' => 'wp:site-tagline removed: the site spec states no tagline, so the '
                            . 'block renders as a blank line inside the masthead lockup',
                        'removal_disposition' => 'the dynamic tagline rendered blank and left dead masthead '
                            . 'space, so its complete block boundary was removed',
                        'retained_note' => 'wp:site-tagline retained: it would render blank, but its generated '
                            . 'boundary also contains nested or raw header content selected to survive; original '
                            . 'bytes delivered',
                        'retained_disposition' => 'the dynamic tagline rendered blank, but its generated boundary '
                            . 'could not be isolated from nested or raw header content selected to survive',
                    ];
                    continue;
                }
                foreach ($heroLines as $line) {
                    if (!self::linesParaphrase($taglineTokens, self::contentTokens($line))) {
                        continue;
                    }
                    $candidates[] = [
                        'index' => $i,
                        'block_start' => $start,
                        'target' => $target,
                        'start' => $targetStart,
                        'end' => $targetEnd,
                        'raw_survivor' => self::dedupeTaglineHasRawSurvivor(
                            $doc,
                            $i,
                            $taglineLeafTokens,
                        ),
                        'path' => $path,
                        'authored' => $authored,
                        'wrapper' => $wrapper,
                        'removal_note' => 'wp:site-tagline removed: its rendered text "' . $taglineText . '" '
                            . 'paraphrases the hero line "' . implode(' ', $line) . '" directly beneath it '
                            . '(the hero owns the proposition; header identity is the site name)',
                        'removal_disposition' => 'the tagline paraphrased hero-owned copy directly beneath it, '
                            . 'so the duplicate header identity line was removed',
                        'retained_note' => 'wp:site-tagline retained: its rendered text "' . $taglineText . '" '
                            . 'paraphrases hero-owned copy, but its generated boundary also contains nested or '
                            . 'raw header content selected to survive; original bytes delivered',
                        'retained_disposition' => 'the tagline paraphrased hero-owned copy, but its generated '
                            . 'boundary could not be isolated from nested or raw header content selected to survive',
                    ];
                    break;
                }
                continue;
            }
            $end = $doc->endOffset($i);
            if ($end === null) {
                continue;
            }
            if ($name === 'paragraph') {
                $tokens = self::textTokens($doc->innerHtml($i));
                foreach ($heroLines as $line) {
                    if (!self::linesEcho($tokens, $line)) {
                        continue;
                    }
                    $start = $doc->openingOffset($i);
                    $candidates[] = [
                        'index' => $i,
                        'block_start' => $start,
                        'target' => $i,
                        'start' => $start,
                        'end' => $end,
                        'raw_survivor' => self::dedupeTextLeafHasRawSurvivor($doc, $i),
                        'path' => $path,
                        'authored' => [
                            'text' => implode(' ', $tokens),
                            'markup' => substr($headerMarkup, $start, $end - $start),
                        ],
                        'removal_note' => 'authored header line "' . implode(' ', $tokens) . '" removed: it echoes a '
                            . 'hero eyebrow/headline line rendered directly beneath the header '
                            . '(the hero owns that copy; the same words twice within one viewport read as a mistake)',
                        'removal_disposition' => 'the generated header line echoed hero-owned copy in the same '
                            . 'viewport, so only the duplicate paragraph block was removed',
                        'retained_note' => 'authored header line "' . implode(' ', $tokens) . '" retained: it echoes '
                            . 'hero-owned copy, but its generated boundary also contains nested or raw header '
                            . 'content selected to survive; original bytes delivered',
                        'retained_disposition' => 'the generated header line echoed hero-owned copy, but its '
                            . 'boundary could not be isolated from nested or raw header content selected to survive',
                    ];
                    break;
                }
                continue;
            }
            if ($name === 'button' && $actionLabel !== []
                && self::textTokens($doc->innerHtml($i)) === $actionLabel
            ) {
                // Remove the enclosing wp:buttons wrapper when this is its
                // only child and its saved-HTML shell becomes empty, so no
                // empty container survives and no non-button sibling is lost.
                $target = $i;
                $parent = $doc->parent($i);
                if ($parent !== null
                    && $doc->name($parent) === 'buttons'
                    && self::dedupeButtonsWrapperBecomesEmpty($doc, $parent, $i)
                ) {
                    $target = $parent;
                }
                $targetEnd = $doc->endOffset($target);
                $targetStart = $doc->openingOffset($target);
                $capturedEnd = $targetEnd ?? ($targetStart + $doc->openingLength($target));
                $candidates[] = [
                    'index' => $i,
                    'block_start' => $doc->openingOffset($i),
                    'target' => $target,
                    'start' => $targetStart,
                    'end' => $targetEnd,
                    'raw_survivor' => self::dedupeButtonHasRawSurvivor($doc, $i),
                    'path' => $path,
                    'authored' => [
                        'label' => implode(' ', $actionLabel),
                        'markup' => substr($headerMarkup, $targetStart, $capturedEnd - $targetStart),
                    ],
                    'removal_note' => 'header button "' . implode(' ', $actionLabel) . '" removed: its label '
                        . "duplicates the contract's primary action, which the hero delivers ~200px below "
                        . '(two identical calls to action within one viewport compete instead of converting)',
                    'removal_disposition' => 'the header control duplicated the authoritative hero primary '
                        . 'action in the same viewport, so the duplicate control'
                        . ($target !== $i ? ' and its now-empty wp:buttons wrapper were' : ' was')
                        . ' removed',
                    'retained_note' => 'header button "' . implode(' ', $actionLabel) . '" retained: it duplicates '
                        . 'the hero primary action, but its generated boundary also contains nested or raw header '
                        . 'content selected to survive; original bytes delivered',
                    'retained_disposition' => 'the header control duplicated the authoritative hero primary '
                        . 'action, but its generated boundary could not be isolated from nested or raw header '
                        . 'content selected to survive',
                ];
            }
        }
        if ($candidates === []) {
            return ['markup' => $headerMarkup, 'notes' => [], 'warnings' => []];
        }

        // A target owns every descendant that is itself scheduled by this
        // pass. Any other descendant is surviving content, so deleting the
        // target would exceed the smallest safe unit.
        $covered = [];
        foreach ($candidates as $candidate) {
            $covered[$candidate['index']] = true;
            $covered[$candidate['target']] = true;
        }
        $safe = [];
        foreach ($candidates as $offset => $candidate) {
            $safe[$offset] = $candidate['end'] !== null
                && !$candidate['raw_survivor']
                && !self::dedupeTargetContainsSurvivor($doc, $candidate['target'], $covered);
        }

        // Keep an unsafe generated containment component byte-for-byte. The
        // closure runs in both directions: editing a nested candidate would
        // partially mutate its retained ancestor, while deleting an ancestor
        // would erase the unsafe descendant that caused this transaction to
        // be abandoned in the first place.
        do {
            $changed = false;
            $unsafeTargets = [];
            foreach ($candidates as $offset => $candidate) {
                if (!$safe[$offset]) {
                    $unsafeTargets[$candidate['target']] = true;
                }
            }
            foreach ($candidates as $offset => $candidate) {
                if (!$safe[$offset]) {
                    continue;
                }
                foreach (array_keys($unsafeTargets) as $unsafeTarget) {
                    if ($candidate['target'] === $unsafeTarget
                        || self::dedupeIsDescendantOf($doc, $candidate['target'], $unsafeTarget)
                        || self::dedupeIsDescendantOf($doc, $unsafeTarget, $candidate['target'])
                    ) {
                        $safe[$offset] = false;
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);

        $notes = [];
        $removals = [];
        foreach ($candidates as $offset => $candidate) {
            if (!$safe[$offset]) {
                $notes[] = $candidate['retained_note'];
                continue;
            }
            $notes[] = $candidate['removal_note'];
            $removals[] = ['start' => $candidate['start'], 'end' => (int) $candidate['end']];
        }

        usort($removals, static function (array $left, array $right): int {
            $start = $left['start'] <=> $right['start'];
            return $start !== 0 ? $start : $right['end'] <=> $left['end'];
        });
        $outermost = [];
        foreach ($removals as $removal) {
            $last = array_key_last($outermost);
            if ($last === null || $removal['start'] >= $outermost[$last]['end']) {
                $outermost[] = $removal;
                continue;
            }
            if ($removal['end'] > $outermost[$last]['end']) {
                $outermost[$last]['end'] = $removal['end'];
            }
        }
        foreach (array_reverse($outermost) as $removal) {
            $headerMarkup = substr_replace(
                $headerMarkup,
                '',
                $removal['start'],
                $removal['end'] - $removal['start'],
            );
        }

        // Retained paths describe the delivered artifact, not the pre-repair
        // input. Rebind their original offsets after earlier safe removals so
        // a mixed safe/unsafe pass and its fixed-point rerun identify the same
        // residual block ordinal.
        $delivered = BlockMarkup::parse($headerMarkup);
        $deliveredPaths = self::dedupePathsByOffset($delivered);
        $warnings = [];
        $removedWrappers = [];
        foreach ($candidates as $offset => $candidate) {
            if ($safe[$offset]) {
                $warnings[] = "file='theme/parts/header.html'; block='{$candidate['path']}'; authored="
                    . Warnings::value($candidate['authored'])
                    . '; delivered=removed; disposition=' . $candidate['removal_disposition'];
                $wrapper = $candidate['wrapper'] ?? null;
                if (is_array($wrapper)) {
                    $removedWrappers[$wrapper['index']] = $wrapper;
                }
                continue;
            }
            $deliveredOffset = self::dedupeOffsetAfterRemovals(
                $candidate['block_start'],
                $outermost,
            );
            $path = $deliveredPaths[$deliveredOffset] ?? $candidate['path'];
            $warnings[] = "file='theme/parts/header.html'; block='{$path}'; authored="
                . Warnings::value($candidate['authored'])
                . '; delivered="original block bytes"; disposition='
                . $candidate['retained_disposition']
                . ($candidate['raw_survivor']
                    ? '; removing the target leaf would also discard visible raw/non-block payload'
                    : '')
                . '; the complete generated unit was retained transactionally without partial edits, and '
                . 'the residual duplicate is queued for repair';
        }
        foreach ($removedWrappers as $wrapper) {
            $warnings[] = "file='theme/parts/header.html'; block='{$wrapper['path']}'; authored="
                . (string) json_encode(
                    $wrapper['markup'],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
                )
                . '; delivered=removed; disposition=the dedicated generated wp:group became empty after its '
                . 'duplicate tagline child was removed in the same transaction; its complete painted/layout '
                . 'boundary was removed so it could not leave dead header UI, while sibling blocks were preserved';
        }
        return ['markup' => $headerMarkup, 'notes' => $notes, 'warnings' => $warnings];
    }

    /**
     * Find the outermost complete group shell dedicated only to one tagline.
     * Header roots are objective delivery boundaries and are never widened
     * through, even when the tagline is their sole generated child.
     */
    private static function dedupeDedicatedTaglineWrapper(BlockMarkup $doc, int $tagline): ?int
    {
        $wrapper = null;
        $child = $tagline;
        $top = $doc->topLevel();
        for ($parent = $doc->parent($child); $parent !== null; $parent = $doc->parent($parent)) {
            $classes = ($doc->attrs($parent) ?? [])['className'] ?? '';
            $objectiveRoot = $parent === $top
                || (is_string($classes) && preg_match('/(?:^|\s)header-archetype--/', $classes) === 1);
            if ($objectiveRoot
                || $doc->name($parent) !== 'group'
                || $doc->children($parent) !== [$child]
                || !self::dedupeGroupBecomesEmptyAroundChild($doc, $parent, $child)
            ) {
                break;
            }
            $wrapper = $parent;
            $child = $parent;
        }
        return $wrapper;
    }

    /** Whether removing the sole child leaves one inert saved-HTML shell. */
    private static function dedupeGroupBecomesEmptyAroundChild(
        BlockMarkup $doc,
        int $group,
        int $child,
    ): bool {
        $groupEnd = $doc->endOffset($group);
        $childEnd = $doc->endOffset($child);
        if ($groupEnd === null || $childEnd === null) {
            return false;
        }
        $innerStart = $doc->openingOffset($group) + $doc->openingLength($group);
        $childStart = $doc->openingOffset($child);
        $shell = substr_replace(
            $doc->innerHtml($group),
            '',
            $childStart - $innerStart,
            $childEnd - $childStart,
        );
        $shell = preg_replace('/<!--(?!\s*\/?wp:).*?-->/s', '', $shell) ?? $shell;
        return preg_match(
            '~\A\s*<(?<tag>div|section|article|main|aside|header|footer|nav)\b[^>]*>\s*</\k<tag>>\s*\z~is',
            $shell,
        ) === 1;
    }

    /** Stable one-based block path in the pre-repair header artifact. */
    private static function dedupeBlockPath(BlockMarkup $doc, int $index): string
    {
        $name = $doc->name($index);
        $ordinal = 0;
        foreach ($doc->indices() as $candidate) {
            if ($doc->name($candidate) === $name) {
                $ordinal++;
            }
            if ($candidate === $index) {
                break;
            }
        }
        return "wp:{$name}[{$ordinal}]";
    }

    /** @return array<int,string> opening byte offset to delivered block path */
    private static function dedupePathsByOffset(BlockMarkup $doc): array
    {
        $paths = [];
        $ordinals = [];
        foreach ($doc->indices() as $index) {
            $name = $doc->name($index);
            $ordinals[$name] = ($ordinals[$name] ?? 0) + 1;
            $paths[$doc->openingOffset($index)] = "wp:{$name}[{$ordinals[$name]}]";
        }
        return $paths;
    }

    /** @param list<array{start:int,end:int}> $removals */
    private static function dedupeOffsetAfterRemovals(int $offset, array $removals): int
    {
        $originalOffset = $offset;
        $shift = 0;
        foreach ($removals as $removal) {
            if ($removal['end'] > $originalOffset) {
                break;
            }
            $shift += $removal['end'] - $removal['start'];
        }
        return $originalOffset - $shift;
    }

    /** @param array<int,true> $covered */
    private static function dedupeTargetContainsSurvivor(
        BlockMarkup $doc,
        int $target,
        array $covered,
    ): bool {
        foreach ($doc->indices() as $index) {
            if ($index === $target || isset($covered[$index])) {
                continue;
            }
            if (self::dedupeIsDescendantOf($doc, $index, $target)) {
                return true;
            }
        }
        return false;
    }

    private static function dedupeIsDescendantOf(BlockMarkup $doc, int $index, int $ancestor): bool
    {
        for ($parent = $doc->parent($index); $parent !== null; $parent = $doc->parent($parent)) {
            if ($parent === $ancestor) {
                return true;
            }
        }
        return false;
    }

    /** Inline phrasing tags belong to a text/control leaf rather than a raw survivor. */
    private const DEDUPE_INLINE_TAGS = [
        'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'del', 'em', 'i',
        'ins', 'kbd', 'mark', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'small',
        'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr',
    ];

    /** @param list<string> $renderedTokens */
    private static function dedupeTaglineHasRawSurvivor(
        BlockMarkup $doc,
        int $tagline,
        array $renderedTokens,
    ): bool {
        if ($doc->isVoid($tagline)) {
            return false;
        }
        $shell = self::dedupeShellWithoutChildBlocks($doc, $tagline);
        if ($shell === null) {
            return true;
        }
        $shell = self::dedupeWithoutInertComments($shell);
        if (trim($shell) === '') {
            return false;
        }
        if (preg_match('/<\s*\/?\s*[a-z][a-z0-9-]*\b/i', $shell) !== 1) {
            return self::textTokens($shell) !== $renderedTokens;
        }
        if (preg_match(
            '/\A\s*<(?<root>p|div|span)\b[^>]*>(?<body>.*)<\/\k<root>>\s*\z/is',
            $shell,
            $match,
        ) !== 1) {
            return true;
        }
        $body = (string) ($match['body'] ?? '');
        return self::dedupeHasNonTextPayload($body)
            || self::textTokens($body) !== $renderedTokens;
    }

    private static function dedupeTextLeafHasRawSurvivor(BlockMarkup $doc, int $paragraph): bool
    {
        $shell = self::dedupeShellWithoutChildBlocks($doc, $paragraph);
        if ($shell === null) {
            return true;
        }
        $shell = self::dedupeWithoutInertComments($shell);
        if (preg_match('/\A\s*<p\b[^>]*>(?<body>.*)<\/p>\s*\z/is', $shell, $match) !== 1) {
            return true;
        }
        return self::dedupeHasNonTextPayload((string) ($match['body'] ?? ''));
    }

    private static function dedupeButtonHasRawSurvivor(BlockMarkup $doc, int $button): bool
    {
        $shell = self::dedupeShellWithoutChildBlocks($doc, $button);
        if ($shell === null) {
            return true;
        }
        $shell = self::dedupeWithoutInertComments($shell);
        if (preg_match(
            '/\A\s*<div\b[^>]*>\s*<a\b[^>]*>(?<body>.*)<\/a>\s*<\/div>\s*\z/is',
            $shell,
            $match,
        ) !== 1) {
            return true;
        }
        return self::dedupeHasNonTextPayload((string) ($match['body'] ?? ''));
    }

    private static function dedupeHasNonTextPayload(string $html): bool
    {
        if (!preg_match_all('/<\s*\/?\s*([a-z][a-z0-9-]*)\b[^>]*>/i', $html, $tags)) {
            return false;
        }
        foreach ($tags[1] as $tag) {
            if (!in_array(strtolower($tag), self::DEDUPE_INLINE_TAGS, true)) {
                return true;
            }
        }
        return false;
    }

    private static function dedupeWithoutInertComments(string $html): string
    {
        return preg_replace('/<!--(?!\s*\/?wp:).*?-->/s', '', $html) ?? $html;
    }

    private static function dedupeShellWithoutChildBlocks(BlockMarkup $doc, int $index): ?string
    {
        $innerStart = $doc->openingOffset($index) + $doc->openingLength($index);
        $shell = $doc->innerHtml($index);
        $children = $doc->children($index);
        for ($position = count($children) - 1; $position >= 0; $position--) {
            $child = $children[$position];
            $end = $doc->endOffset($child);
            if ($end === null) {
                return null;
            }
            $start = $doc->openingOffset($child);
            $shell = substr_replace($shell, '', $start - $innerStart, $end - $start);
        }
        return $shell;
    }

    /**
     * Whether removing one button leaves only an inert empty wrapper shell.
     * Requiring the exact child list prevents a paragraph/navigation sibling
     * from being discarded just because it is not another wp:button.
     */
    private static function dedupeButtonsWrapperBecomesEmpty(
        BlockMarkup $doc,
        int $wrapper,
        int $button,
    ): bool {
        $wrapperEnd = $doc->endOffset($wrapper);
        $buttonEnd = $doc->endOffset($button);
        if ($wrapperEnd === null || $buttonEnd === null || $doc->children($wrapper) !== [$button]) {
            return false;
        }

        $innerStart = $doc->openingOffset($wrapper) + $doc->openingLength($wrapper);
        $buttonStart = $doc->openingOffset($button);
        $shell = substr_replace(
            $doc->innerHtml($wrapper),
            '',
            $buttonStart - $innerStart,
            $buttonEnd - $buttonStart,
        );
        // Plain comments and whitespace are inert; a real saved-HTML node is
        // a survivor, even when the parser sees no additional child block.
        $shell = preg_replace('/<!--(?!\s*\/?wp:).*?-->/s', '', $shell) ?? $shell;
        return preg_match('/\A\s*<div\b[^>]*>\s*<\/div>\s*\z/is', $shell) === 1;
    }

    /**
     * Normalized comparison tokens for one rendered text line: tags stripped,
     * entities decoded, lowercased, split on every non-alphanumeric run — so
     * "San Telmo, Buenos Aires" and "SAN TELMO · BUENOS AIRES" compare equal.
     *
     * @return list<string>
     */
    private static function textTokens(string $html): array
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');
        return array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [],
            static fn (string $token): bool => $token !== '',
        ));
    }

    /**
     * Whether two short lines say the same thing: at least two shared distinct
     * tokens covering 80%+ of the shorter line. Reordering and punctuation
     * differences match; lines merely sharing a place name do not.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function linesEcho(array $a, array $b): bool
    {
        $a = array_unique($a);
        $b = array_unique($b);
        if ($a === [] || $b === []) {
            return false;
        }
        $shared = count(array_intersect($a, $b));
        return $shared >= 2 && $shared / min(count($a), count($b)) >= 0.8;
    }

    /**
     * Common function words that carry no meaning for the paraphrase check.
     * Tokens shorter than four characters are dropped outright, which also
     * covers most non-English articles and prepositions.
     */
    private const STOP_TOKENS = [
        'about', 'across', 'after', 'against', 'also', 'among', 'and', 'are',
        'been', 'before', 'being', 'between', 'during', 'each', 'every',
        'from', 'have', 'into', 'more', 'most', 'only', 'onto', 'other',
        'over', 'since', 'some', 'than', 'that', 'their', 'them', 'then',
        'they', 'this', 'through', 'toward', 'towards', 'under', 'until',
        'upon', 'very', 'were', 'what', 'when', 'where', 'while', 'will',
        'with', 'within', 'without', 'your',
    ];

    /**
     * The meaning-bearing subset of a token line: function words and tokens
     * shorter than four characters removed, duplicates collapsed.
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    private static function contentTokens(array $tokens): array
    {
        return array_values(array_unique(array_filter(
            $tokens,
            static fn (string $token): bool => mb_strlen($token, 'UTF-8') >= 4
                && !in_array($token, self::STOP_TOKENS, true),
        )));
    }

    /**
     * Whether two lines say the same thing once function words are ignored
     * and near-identical word forms count as the same word ("argentine" ~
     * "argentina", "photography" ~ "photographs"). Looser than linesEcho on
     * purpose: a header tagline and a hero eyebrow are independently written
     * paraphrases of the same spec fact, never byte-identical.
     *
     * @param list<string> $a content tokens
     * @param list<string> $b content tokens
     */
    private static function linesParaphrase(array $a, array $b): bool
    {
        if (count($a) < 2 || count($b) < 2) {
            return false;
        }
        [$small, $large] = count($a) <= count($b) ? [$a, $b] : [$b, $a];
        $shared = 0;
        foreach ($small as $x) {
            foreach ($large as $y) {
                if (self::tokenKin($x, $y)) {
                    $shared++;
                    break;
                }
            }
        }
        return $shared >= 2 && $shared / count($small) >= 0.6;
    }

    /**
     * Whether two tokens are inflections of the same word: identical, or
     * sharing a prefix of at least five characters that reaches within two
     * characters of the shorter token's end. "argentina"/"argentine" and
     * "photography"/"photographs" match; "station"/"state" does not.
     */
    private static function tokenKin(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $limit = min(mb_strlen($a, 'UTF-8'), mb_strlen($b, 'UTF-8'));
        $shared = 0;
        while ($shared < $limit
            && mb_substr($a, $shared, 1, 'UTF-8') === mb_substr($b, $shared, 1, 'UTF-8')
        ) {
            $shared++;
        }
        return $shared >= 5 && $shared >= $limit - 2;
    }

    /**
     * Lower page-opening covers that cannot share a ~100vh viewport with a
     * stacked header. Pure — unit-testable.
     *
     * @return array{markup:string, notes:string[]}
     */
    public static function capCovers(string $markup): array
    {
        $doc = BlockMarkup::parse($markup);
        $notes = [];
        foreach ($doc->indices() as $i) {
            if ($doc->name($i) !== 'cover') {
                continue;
            }
            $attrs = $doc->attrs($i) ?? [];
            $height = $attrs['minHeight'] ?? null;
            if (!is_numeric($height)
                || (string) ($attrs['minHeightUnit'] ?? '') !== 'vh'
                || (float) $height <= self::MAX_STACKED_COVER_VH) {
                continue;
            }
            // Attribute-only edit: the stale inline min-height style survives
            // until fix-blocks re-serializes the saved HTML from these attrs.
            $attrs['minHeight'] = self::STACKED_COVER_VH;
            $doc->setAttrs($i, $attrs);
            $notes[] = "cover minHeight {$height}vh lowered to " . self::STACKED_COVER_VH . 'vh '
                . '(an opaque header stacks above this page-opening section; together they must fit one viewport)';
        }
        return ['markup' => $doc->render(), 'notes' => $notes];
    }

    /**
     * Rough single-row width of the header bar at desktop: wordmark cluster +
     * navigation labels + button labels + gaps. Hand-authored
     * wp:navigation-link labels are read from the markup; a wp:page-list
     * renders one item per site page, so page titles stand in for its labels.
     * Pure — unit-testable.
     *
     * @param list<string> $pageTitles
     */
    public static function estimatedRowWidth(BlockMarkup $doc, string $siteName, array $pageTitles = []): int
    {
        $width = self::GUTTERS_PX;
        $labels = [];
        $hasPageList = false;
        $hasTitle = false;
        $hasButton = false;

        foreach ($doc->indices() as $i) {
            $name = $doc->name($i);
            if ($name === 'navigation-link') {
                $labels[] = (string) (($doc->attrs($i) ?? [])['label'] ?? '');
            } elseif ($name === 'page-list') {
                $hasPageList = true;
            } elseif ($name === 'site-title') {
                $hasTitle = true;
            } elseif ($name === 'site-logo') {
                $width += (int) (($doc->attrs($i) ?? [])['width'] ?? 48) + self::LOGO_GAP_PX;
            } elseif ($name === 'button') {
                $label = trim(strip_tags($doc->innerHtml($i)));
                $width += self::BUTTON_PAD_PX + mb_strlen($label) * self::BUTTON_CHAR_PX;
                $hasButton = true;
            }
        }
        if ($hasPageList) {
            $labels = array_merge($labels, $pageTitles);
        }
        if ($hasTitle) {
            $width += mb_strlen($siteName) * self::TITLE_CHAR_PX + self::CLUSTER_GAP_PX;
        }
        foreach ($labels as $label) {
            $width += mb_strlen(trim($label)) * self::NAV_CHAR_PX + self::NAV_GAP_PX;
        }
        if ($hasButton) {
            $width += self::CLUSTER_GAP_PX;
        }
        return $width;
    }

    /**
     * Prove — and where a small dim raise makes it provable, repair — the
     * clear overlay resting state against every delivered opening. Returns
     * true only when each opening either passes clearOverlayTopIsSafe at its
     * delivered cover dim or is raised to the minimal passing dim; edits are
     * applied transactionally so a failing opening leaves every part
     * untouched and the scrim veil in place.
     *
     * @param array<string,mixed>  $final    delivery-final contract
     * @param array<string,mixed>  $behavior resolved behavior artifact
     * @param array<string,string> $palette  slug => hex map
     * @param array<string,string> $writes
     * @param list<string>         $report
     */
    private static function grantClearOverlayTop(
        Project $project,
        array $final,
        array $behavior,
        array $palette,
        array &$writes,
        array &$report,
    ): bool {
        $foreground = ContrastMath::hexToRgb((string) ($palette[(string) $behavior['foreground']] ?? ''));
        $scrolled = ContrastMath::hexToRgb((string) ($palette[(string) $behavior['scrolledSurface']] ?? ''));
        $protectionToken = (string) ($final['header']['protection_token'] ?? '');
        $protectionHex = (string) (
            $final['theme_tokens'][$protectionToken]['hex'] ?? ($palette[$protectionToken] ?? '')
        );
        $protection = ContrastMath::hexToRgb($protectionHex);
        if ($foreground === null || $scrolled === null || $protection === null) {
            return false;
        }
        // CSS switches background paint immediately for an earned-clear
        // fixed header: a timed transparent transition cannot prove a page
        // section that may scroll underneath it. Motion-enabled themes keep
        // their smooth shadow transition, which does not affect contrast.
        $smooth = false;
        $minimalDim = HeaderBehavior::minimalClearOverlayDim($foreground, $protection, $scrolled, $smooth);

        $edits = [];
        foreach ((array) ($final['openings'] ?? []) as $opening) {
            if (!is_array($opening)) {
                return false;
            }
            $rel = 'parts/' . (string) ($opening['part'] ?? '') . '.html';
            $markup = $writes[$rel]
                ?? ($project->exists('theme/' . $rel) ? $project->readText('theme/' . $rel) : null);
            if ($markup === null) {
                return false;
            }
            $surface = (string) ($opening['surface'] ?? '');
            if (!AboveFoldPartFacts::supportsClearOverlayTop(
                $markup,
                $surface,
                $protectionToken,
                $protectionHex,
            )) {
                return false;
            }
            $doc = BlockMarkup::parse($markup);
            $root = $doc->topLevel();
            if ($root === null) {
                return false;
            }
            $cover = null;
            foreach ($doc->children($root) as $child) {
                if ($doc->name($child) === 'cover' && $doc->endOffset($child) !== null) {
                    if ($cover !== null) {
                        return false;
                    }
                    $cover = $child;
                }
            }
            if ($cover === null) {
                // A solid protection-token band: the clear state reveals that
                // solid directly (a full-opacity dim is the same composite).
                if (!HeaderBehavior::clearOverlayTopIsSafe($foreground, $protection, 100.0, $scrolled, $smooth)) {
                    return false;
                }
                continue;
            }
            $attrs = $doc->attrs($cover) ?? [];
            $authoredDim = $attrs['dimRatio'] ?? 100;
            if (!is_int($authoredDim) && !is_float($authoredDim)) {
                return false;
            }
            $dim = (float) $authoredDim;
            $renderedDim = HeaderBehavior::renderedCoverDim($dim);
            if ($renderedDim === null) {
                return false;
            }
            if (HeaderBehavior::clearOverlayTopIsSafe($foreground, $protection, $dim, $scrolled, $smooth)) {
                continue;
            }
            if ($minimalDim === null || $renderedDim >= $minimalDim) {
                return false;
            }
            $attrs['dimRatio'] = $minimalDim;
            $doc->setAttrs($cover, $attrs);
            // Keep the file safe even if the later fix-blocks transaction has
            // to retain its input bytes: the clear header and the repaired
            // Cover opacity become effective in the same unit.
            ContrastFix::swapDimClass($doc, $cover, $renderedDim, $minimalDim);
            $edits[$rel] = [
                'markup' => $doc->render(),
                'note' => "cover dimRatio {$dim} raised to {$minimalDim} (the smallest dim whose own "
                    . 'composite proves the foreground, letting the overlay header rest without its scrim)',
            ];
        }
        foreach ($edits as $rel => $edit) {
            $writes[$rel] = $edit['markup'];
            $report[] = "[{$rel}] {$edit['note']}";
        }
        return true;
    }

    /**
     * Apply the shared stacked first-viewport cap to every positional opening
     * in the pending transaction.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,string> $writes
     * @param list<string> $report
     */
    private static function capOpeningCovers(
        Project $project,
        array $pages,
        array &$writes,
        array &$report,
    ): void {
        foreach ($pages as $page) {
            $pageSlug = trim((string) ($page['slug'] ?? ''));
            $slug = trim((string) (SectionsStep::openingSection($page)['slug'] ?? ''));
            if ($pageSlug === '' || $slug === '') {
                continue;
            }
            $rel = 'parts/' . SectionsStep::partSlug($pageSlug, $slug) . '.html';
            if (!isset($writes[$rel]) && !$project->exists('theme/' . $rel)) {
                continue;
            }
            $markup = $writes[$rel] ?? $project->readText('theme/' . $rel);
            $result = self::capCovers($markup);
            $writes[$rel] = $result['markup'];
            foreach ($result['notes'] as $note) {
                $report[] = "[{$rel}] {$note}";
            }
        }
    }

    /** @param array<int,array<string,mixed>> $pages @return array<string,mixed> */
    private static function frontPage(array $pages): array
    {
        foreach ($pages as $page) {
            if (($page['front'] ?? false) === true) {
                return $page;
            }
        }
        return $pages[0] ?? [];
    }

    /**
     * Overlay pending writes on the current part bytes without mutating the
     * project, so final contract facts are computed transactionally.
     *
     * @param array<string,string> $writes paths relative to theme/
     * @return array<string,string> part key => markup
     */
    private static function partBytes(Project $project, array $writes): array
    {
        $parts = [];
        foreach (glob($project->themePath('parts/*.html')) ?: [] as $path) {
            $key = basename($path, '.html');
            $parts[$key] = $project->readText('theme/parts/' . basename($path));
        }
        foreach ($writes as $relative => $markup) {
            if (!str_starts_with($relative, 'parts/') || !str_ends_with($relative, '.html')) {
                continue;
            }
            $parts[basename($relative, '.html')] = $markup;
        }
        return $parts;
    }

    /**
     * Remove only the front hero's advertised action after delivered markup
     * could not preserve it. Siblings and all other section fields remain
     * byte-for-byte equivalent at the data level.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param list<string> $warnings
     * @return array<int,array<string,mixed>>
     */
    private static function withoutFrontPrimaryAction(array $pages, array &$warnings): array
    {
        foreach ($pages as $pageIndex => $page) {
            if (($page['front'] ?? false) !== true) {
                continue;
            }
            $sections = array_values(array_filter((array) ($page['sections'] ?? []), 'is_array'));
            if ($sections === []) {
                return $pages;
            }
            $authored = $sections[0]['primary_action'] ?? null;
            $sections[0]['primary_action'] = null;
            $pages[$pageIndex]['sections'] = $sections;
            $warnings[] = "header-hero: file='pages.json'; path=\"pages[{$pageIndex}].sections[0].primary_action\"; "
                . 'authored=' . (string) json_encode($authored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                . '; delivered=null; disposition=removed the undelivered front-page action while retaining the hero and all sibling sections';
            return $pages;
        }
        return $pages;
    }
}
