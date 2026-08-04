<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\AboveFoldPartFacts;
use Automattic\SiteBuild\HeaderFallback;
use Automattic\SiteBuild\HeroFallback;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Units\GeneratedMarkup;

/**
 * Step (deterministic): finalize the persisted two-phase above-fold contract
 * after section rhythm and layout normalization. Header, hero, and page
 * openings are independent generated units; this is their narrow objective
 * backstop, not a general aesthetic hero rewriter.
 *
 * Input:  delivery-phase aboveFold.json + pages.json + siteSpec.json + parts
 * Output: matching part/page repairs + final-phase aboveFold.json, actionable
 *         loss warnings, and logs/header-hero.txt for successful repairs.
 *
 * Objective repairs include:
 *  1. Overlay wiring — in overlay mode the header's top-level group MUST
 *     carry the `header-overlay` class (the theme CSS shipped unused 6/6
 *     audited builds) and must be transparent and non-sticky; in stacked
 *     mode a stray `header-overlay` class is removed.
 *  2. Fold budget — in stacked mode an opaque header consumes ~100px of the
     *     first viewport, so a page-opening cover taller than 80vh is lowered to
 *     80vh (header + cover must fit ~100vh; audited covers ran 86-92vh and
 *     pushed the hero H1 and CTA below the fold). Only the cover half is
 *     enforced here: the header's own height is asked for in prompts/header.md
 *     and never measured, so the cap assumes a compliant header. A header that
 *     busts its budget still pushes content below the fold, and this step will
 *     not say so — estimating header height the way estimatedRowWidth()
 *     estimates width would be the way to close that.
 *  3. Nav collapse — WordPress's hamburger only engages below 600px, while
 *     an over-wide title/nav row wraps into a 2-3 row header across the
 *     whole tablet range. When the estimated single-row width exceeds the
 *     budget, the navigation gets `"overlayMenu":"always"`.
 *  4. Title scale — a site title at `section-title`/`display` competes with
 *     the hero's display H1 (audited ratios collapsed to 1.33x); it is
 *     rewritten to `heading` unless the build forced the oversized-wordmark
 *     archetype, whose whole point is a display-scale wordmark.
 *  5. Exact root archetype marker and contract-owned foreground/protection
 *     tokens, plus authoritative primary-action correspondence.
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

    private const OVERLAY_CLASS = 'header-overlay';

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
            reads: ['pages.json', 'aboveFold.json', 'designDirection.json', 'siteSpec.json', 'theme/parts/*'],
            writes: ['theme/parts/*', 'pages.json', 'aboveFold.json', 'warnings.json'],
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

        $report = [];
        $warnings = [];
        $writes = [];
        $headerRel = 'parts/header.html';
        $header = $project->readText('theme/' . $headerRel);
        $result = self::fixHeader(
            $header,
            $mode,
            $siteName,
            $pageTitles,
            $archetype === 'oversized-wordmark',
            $archetype,
            $foreground,
            $protection,
        );
        $writes[$headerRel] = $result['markup'];
        foreach ($result['notes'] as $note) {
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
            $actionResult = GeneratedMarkup::reconcilePrimaryAction(
                $heroMarkup,
                is_array($delivery['primary_action'] ?? null) ? $delivery['primary_action'] : null,
                $heroPart,
            );
            $writes[$heroRel] = $actionResult['markup'];
            foreach ($actionResult['repairs'] as $repair) {
                $report[] = "[{$heroRel}] " . (is_string($repair)
                    ? $repair
                    : (string) json_encode($repair, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            array_push($warnings, ...$actionResult['warnings']);

            // With the hero's delivered copy and action known, drop the
            // header's duplicates of them (echoed caption lines, same-label
            // CTA) — the ownership split gives both to the hero.
            $dedupe = self::dedupeAgainstHero(
                $writes[$headerRel],
                $writes[$heroRel],
                is_array($delivery['primary_action'] ?? null) ? $delivery['primary_action'] : null,
            );
            $writes[$headerRel] = $dedupe['markup'];
            foreach ($dedupe['notes'] as $note) {
                $report[] = "[{$headerRel}] {$note}";
            }
        }

        $partBytes = self::partBytes($project, $writes);
        $facts = AboveFoldPartFacts::inspect($pages, $partBytes, $delivery);
        $degradationOffset = count((array) ($delivery['degradations'] ?? []));
        $final = AboveFoldContract::finalizeMarkup($delivery, $pages, $facts);

        // Rhythm may have invalidated image protection after delivery. Apply
        // only the resulting objective relation to the already-generated
        // header; no new aesthetic selection occurs here.
        if (($final['header']['mode'] ?? null) !== $mode
            || ($final['header']['archetype'] ?? null) !== $archetype
        ) {
            $mode = (string) $final['header']['mode'];
            $archetype = (string) $final['header']['archetype'];
            $foreground = (string) $final['header']['foreground_token'];
            $protection = (string) $final['header']['protection_token'];
            $headerResult = self::fixHeader(
                $writes[$headerRel],
                $mode,
                $siteName,
                $pageTitles,
                $archetype === 'oversized-wordmark',
                $archetype,
                $foreground,
                $protection,
            );
            $writes[$headerRel] = $headerResult['markup'];
            foreach ($headerResult['notes'] as $note) {
                $report[] = "[{$headerRel}] {$note}";
            }
        }

        // The height budget follows the delivered relation, not the initial
        // one. An overlay that degrades here must receive the same objective
        // stacked cover cap as a build that started stacked.
        if (($final['header']['mode'] ?? null) === AboveFoldContract::MODE_STACKED) {
            self::capOpeningCovers($project, $pages, $writes, $report);
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
        $facts = AboveFoldPartFacts::inspect($pages, $partBytes, $deliveryForRefinalize);
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
        $project->addWarnings($this->id(), $warnings);
        if ($report === []) {
            $report[] = "Header mode '{$mode}': header and page-opening sections already satisfy the "
                . 'repairs this step makes. Header height is prompt-enforced only and was not measured.';
        }
        $project->writeText('logs/' . self::REPORT_FILE, implode("\n", $report) . "\n");
        Narrator::write(sprintf("  header/hero: mode '%s' (details: logs/%s)\n", $mode, self::REPORT_FILE));
    }

    /**
     * Repair the header part against the contract. Pure — unit-testable.
     *
     * @param list<string> $pageTitles page-list nav labels (a wp:page-list
     *                                 renders one item per site page)
     * @return array{markup:string, notes:string[]}
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
    ): array {
        $doc = BlockMarkup::parse($markup);
        $notes = [];

        $top = $doc->topLevel();
        if ($top !== null && $mode === AboveFoldContract::MODE_OVERLAY) {
            self::wireOverlay($doc, $top, $notes);
        } elseif ($top !== null) {
            self::stripOverlay($doc, $top, $notes);
        }
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
            if ($mode === AboveFoldContract::MODE_STACKED && $protection !== '') {
                $surfaceNotes = [];
                $beforeSurface = $rendered;
                $rendered = GeneratedMarkup::withRootBackgroundColor(
                    $rendered,
                    $protection,
                    $surfaceNotes,
                    'header',
                );
                if ($rendered !== $beforeSurface) {
                    $notes[] = 'header background synchronized in block attributes and saved HTML';
                }
                array_push($notes, ...$surfaceNotes);
            }
            if ($foreground !== '') {
                $rendered = GeneratedMarkup::withRootTextColor(
                    $rendered,
                    $foreground,
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
            if ($mode === AboveFoldContract::MODE_OVERLAY) {
                $rendered = GeneratedMarkup::withRootClassToken(
                    $rendered,
                    self::OVERLAY_CLASS,
                    'header',
                    $classRepairs,
                );
            }
            if ($classRepairs !== []) {
                $notes[] = 'header root class hooks synchronized in block attributes and saved HTML';
            }
        }

        return ['markup' => $rendered, 'notes' => $notes];
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
     * Overlay mode: the top-level group must carry the `header-overlay` class
     * hook (the shipped `.header-overlay` CSS does the floating) and must be
     * transparent and non-sticky — an opaque or sticky overlay defeats it.
     */
    private static function wireOverlay(BlockMarkup $doc, int $top, array &$notes): void
    {
        $attrs = $doc->attrs($top) ?? [];
        $tokens = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $changed = [];
        if (!in_array(self::OVERLAY_CLASS, $tokens, true)) {
            $tokens[] = self::OVERLAY_CLASS;
            $attrs['className'] = implode(' ', $tokens);
            $changed[] = "added the '" . self::OVERLAY_CLASS . "' class hook";
        }
        $background = (string) ($attrs['backgroundColor'] ?? '');
        if ($background !== '') {
            unset($attrs['backgroundColor']);
            $doc->removeClassTokenInOwnHtml($top, 'has-background');
            $doc->removeClassTokenInOwnHtml($top, "has-{$background}-background-color");
            $changed[] = "removed the opaque '{$background}' background";
        }
        foreach (['base', 'contrast', 'primary', 'secondary', 'accent'] as $surface) {
            $doc->removeClassTokenInOwnHtml($top, "has-{$surface}-background-color");
        }
        $doc->removeClassTokenInOwnHtml($top, 'has-background');
        $gradient = (string) ($attrs['gradient'] ?? '');
        if ($gradient !== '') {
            unset($attrs['gradient']);
            $doc->removeClassTokenInOwnHtml($top, 'has-background');
            $doc->removeClassTokenInOwnHtml($top, "has-{$gradient}-gradient-background");
            $changed[] = "removed the '{$gradient}' gradient background";
        }
        if (isset($attrs['style']['color'])) {
            unset($attrs['style']['color']);
            $doc->removeClassTokenInOwnHtml($top, 'has-background');
            $changed[] = 'removed the custom background color';
        }
        if (isset($attrs['style']['position'])) {
            unset($attrs['style']['position']);
            $changed[] = 'removed the sticky position';
        }
        if (isset($attrs['style']) && $attrs['style'] === []) {
            unset($attrs['style']);
        }
        if ($changed !== []) {
            $doc->setAttrs($top, $attrs);
            $notes[] = 'overlay header wiring: ' . implode(', ', $changed)
                . ' (overlay mode floats the header transparently over the hero cover)';
        }
    }

    /**
     * Stacked mode: a stray `header-overlay` class would float the header
     * over content that never reserved space for it — remove the hook.
     */
    private static function stripOverlay(BlockMarkup $doc, int $top, array &$notes): void
    {
        $attrs = $doc->attrs($top) ?? [];
        $tokens = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!in_array(self::OVERLAY_CLASS, $tokens, true)) {
            return;
        }
        $kept = array_values(array_diff($tokens, [self::OVERLAY_CLASS]));
        if ($kept === []) {
            unset($attrs['className']);
        } else {
            $attrs['className'] = implode(' ', $kept);
        }
        $doc->setAttrs($top, $attrs);
        $doc->removeClassTokenInOwnHtml($top, self::OVERLAY_CLASS);
        $notes[] = "removed the '" . self::OVERLAY_CLASS . "' class "
            . '(stacked mode: the hero composed for an opaque bar above it, not a floating header)';
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
     * Pure — unit-testable.
     *
     * @param array{label:string,intent:string,destination:string}|null $primaryAction
     * @return array{markup:string, notes:string[]}
     */
    public static function dedupeAgainstHero(
        string $headerMarkup,
        string $heroMarkup,
        ?array $primaryAction,
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

        $doc = BlockMarkup::parse($headerMarkup);
        $notes = [];
        $removals = [];
        foreach ($doc->indices() as $i) {
            $name = $doc->name($i);
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
                    $removals[$doc->openingOffset($i)] = $end - $doc->openingOffset($i);
                    $notes[] = 'authored header line "' . implode(' ', $tokens) . '" removed: it echoes a '
                        . 'hero eyebrow/headline line rendered directly beneath the header '
                        . '(the hero owns that copy; the same words twice within one viewport read as a mistake)';
                    break;
                }
                continue;
            }
            if ($name === 'button' && $actionLabel !== []
                && self::textTokens($doc->innerHtml($i)) === $actionLabel
            ) {
                // Remove the enclosing wp:buttons wrapper when this is its
                // only button, so no empty container survives.
                $target = $i;
                $parent = $doc->parent($i);
                if ($parent !== null && $doc->name($parent) === 'buttons' && $doc->endOffset($parent) !== null) {
                    $siblings = array_filter(
                        $doc->children($parent),
                        fn (int $child): bool => $doc->name($child) === 'button',
                    );
                    if (count($siblings) === 1) {
                        $target = $parent;
                    }
                }
                $targetEnd = (int) $doc->endOffset($target);
                $removals[$doc->openingOffset($target)] = $targetEnd - $doc->openingOffset($target);
                $notes[] = 'header button "' . implode(' ', $actionLabel) . '" removed: its label duplicates '
                    . "the contract's primary action, which the hero delivers ~200px below "
                    . '(two identical calls to action within one viewport compete instead of converting)';
            }
        }
        if ($removals === []) {
            return ['markup' => $headerMarkup, 'notes' => []];
        }
        krsort($removals);
        foreach ($removals as $offset => $length) {
            $headerMarkup = substr_replace($headerMarkup, '', $offset, $length);
        }
        return ['markup' => $headerMarkup, 'notes' => $notes];
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
