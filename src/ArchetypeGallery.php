<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The gallery page: what the generator can draw today, and what it could draw
 * next.
 *
 * Two halves. The catalog half shows every archetype in the four code-owned
 * catalogs, illustrated with a committed screenshot of a real generated site,
 * so "what can we build" is answered by evidence rather than by reading four
 * PHP files. The proposal half shows mockups of archetypes nobody has built,
 * each selectable with a notes box, so a review session ends with a prompt that
 * implements the picks instead of a verbal decision nobody wrote down.
 *
 * Pure: data in, one self-contained HTML string out. The command owns the
 * files, the server and the model calls.
 */
final class ArchetypeGallery
{
    /**
     * @param list<array<string,mixed>> $entries   catalog rows (ArchetypeCatalog::entries)
     * @param array<string,mixed>       $shots     shots/index.json contents
     * @param list<array<string,mixed>> $proposals stored proposal records
     * @param bool                      $live      true when served by the dev server, which
     *                                             is what makes composing and saving possible
     */
    public static function render(array $entries, array $shots, array $proposals, bool $live): string
    {
        $shotIndex = self::normalizeShots($shots['shots'] ?? []);
        $width = (int) ($shots['width'] ?? 1366);

        $catalogSections = '';
        $counts = [];
        $samples = 0;
        $thin = [];
        foreach (ArchetypeCatalog::FAMILIES as $family) {
            $rows = array_values(array_filter($entries, static fn (array $e): bool => $e['family'] === $family));
            if ($rows === []) {
                continue;
            }
            $shown = 0;
            $cards = '';
            foreach ($rows as $entry) {
                $entryShots = $shotIndex[$entry['key']] ?? [];
                $shown += $entryShots === [] ? 0 : 1;
                $samples += count($entryShots);
                if (count($entryShots) === 1) {
                    $thin[] = $entry['key'];
                }
                $cards .= self::catalogCard($entry, $entryShots);
            }
            $counts[$family] = ['total' => count($rows), 'shown' => $shown];
            [$title, $blurb] = array_values(ArchetypeCatalog::familyLabel($family));
            $catalogSections .= '<section class="family" id="family-' . self::esc($family) . '">'
                . '<h2>' . self::esc($title) . ' <span class="count">' . $shown . '/' . count($rows)
                . ' illustrated</span></h2>'
                . '<p class="blurb">' . self::esc($blurb) . '</p>'
                . '<div class="grid catalog">' . $cards . '</div></section>';
        }

        // A shot naming an archetype no catalog owns would otherwise be
        // invisible: the gallery draws catalog rows, not the index.
        $known = array_flip(array_column($entries, 'key'));
        $orphans = array_values(array_filter(
            array_keys($shotIndex),
            static fn (string $key): bool => !isset($known[$key]),
        ));

        $proposalSections = '';
        $waiting = 0;
        foreach (ArchetypeCatalog::FAMILIES as $family) {
            $rows = array_values(array_filter($proposals, static fn (array $p): bool => $p['family'] === $family));
            if ($rows === []) {
                continue;
            }
            $cards = '';
            $familyWaiting = 0;
            foreach ($rows as $proposal) {
                $familyWaiting += ($proposal['status'] ?? 'waiting') === 'waiting' ? 1 : 0;
                $cards .= self::proposalCard($proposal);
            }
            $waiting += $familyWaiting;
            $label = ArchetypeCatalog::familyLabel($family);
            $settled = count($rows) - $familyWaiting;
            $proposalSections .= '<section class="family" id="proposals-' . self::esc($family) . '">'
                . '<h2>' . self::esc($label['title']) . ' <span class="count">' . $familyWaiting . ' waiting'
                . ($settled > 0 ? ', ' . $settled . ' settled' : '') . '</span></h2>'
                . '<div class="grid proposals">' . $cards . '</div></section>';
        }

        $mockupCss = '';
        foreach ($proposals as $proposal) {
            $mockupCss .= "\n" . $proposal['mockup']['css'];
        }

        $totals = [
            'archetypes' => count($entries),
            'illustrated' => array_sum(array_column($counts, 'shown')),
            'samples' => $samples,
            'proposals' => $waiting,
            'thin' => $thin,
            'orphans' => $orphans,
        ];
        $composePanel = $live ? self::composePanel() : self::composeOffline();
        $liveFlag = $live ? 'true' : 'false';

        return self::page($catalogSections, $proposalSections, $mockupCss, $totals, $width, $composePanel, $liveFlag);
    }

    /**
     * Accept either shape of `shots/index.json`: one entry per archetype, as the
     * first version of the tool wrote it, or the list of examples it writes now.
     *
     * @param mixed $shots
     * @return array<string,list<array<string,mixed>>>
     */
    public static function normalizeShots(mixed $shots): array
    {
        if (!is_array($shots)) {
            return [];
        }
        $normalized = [];
        foreach ($shots as $key => $value) {
            if (!is_array($value) || $value === []) {
                continue;
            }
            $list = array_is_list($value) ? $value : [$value];
            $entries = array_values(array_filter(
                $list,
                static fn (mixed $entry): bool => is_array($entry) && is_string($entry['file'] ?? null),
            ));
            if ($entries !== []) {
                $normalized[(string) $key] = $entries;
            }
        }
        return $normalized;
    }

    /** @param list<array<string,mixed>> $shots */
    private static function catalogCard(array $entry, array $shots): string
    {
        $figures = '<p class="gap">No screenshot yet. No built site under <code>projects/</code> has drawn it — '
            . 'pin it and build: <code>php bin/archetypes.php fill --only=&lt;brief&gt;</code>, then '
            . '<code>capture</code>.</p>';
        if ($shots !== []) {
            $figures = '';
            foreach ($shots as $shot) {
                $file = self::esc((string) $shot['file']);
                $figures .= '<figure><a href="shots/' . $file . '" target="_blank">'
                    . '<img loading="lazy" src="shots/' . $file . '" alt="'
                    . self::esc($entry['id'] . ' as built on ' . ($shot['site'] ?? '')) . '"></a>'
                    . '<figcaption>' . self::esc((string) ($shot['site'] ?? '')) . ' <span>('
                    . self::esc((string) ($shot['slug'] ?? '')) . ')</span></figcaption></figure>';
            }
            if (count($shots) === 1) {
                $figures .= '<p class="gap thin">One example only — it shows the archetype exists, not how much '
                    . 'it varies. <code>php bin/archetypes.php fill</code> builds more sites that draw it.</p>';
            }
        }
        $facts = '';
        foreach (($entry['facts'] ?? []) as $label => $value) {
            $facts .= '<div><dt>' . self::esc((string) $label) . '</dt><dd>' . self::esc((string) $value) . '</dd></div>';
        }
        $note = ($entry['note'] ?? '') === ''
            ? ''
            : '<p class="note">' . self::esc((string) $entry['note']) . '</p>';
        // The brief is the fragment's opening line, so the disclosure is offered
        // whenever the fragment says more than that — including when the brief
        // is itself a truncated sentence, which is when it is wanted most.
        $brief = (string) ($entry['brief'] ?? $entry['summary']);
        $fragment = $brief === (string) $entry['summary']
            ? ''
            : '<details class="fragment"><summary>prompt fragment</summary><p>'
                . self::esc((string) $entry['summary']) . '</p></details>';

        return '<article class="card' . ($shots === [] ? ' is-empty' : '') . '" id="' . self::esc($entry['key']) . '">'
            . '<div class="meta"><h3>' . self::esc($entry['id'])
            . (count($shots) > 1 ? ' <span class="count">' . count($shots) . ' examples</span>' : '') . '</h3>'
            . '<p class="summary">' . self::esc($brief) . '</p>'
            . $fragment
            . $note
            . ($facts === '' ? '' : '<dl class="facts">' . $facts . '</dl>')
            . '<p class="source">' . self::esc((string) $entry['source']) . '</p></div>'
            . '<div class="shots">' . $figures . '</div></article>';
    }

    private static function proposalCard(array $proposal): string
    {
        $origin = $proposal['origin'] === 'hand'
            ? 'hand-drawn'
            : ($proposal['origin'] === 'auto' ? 'model, variety pass' : 'model, from a prompt');
        $promptLine = ($proposal['prompt'] ?? '') === ''
            ? ''
            : '<p class="prompted">Asked for: “' . self::esc((string) $proposal['prompt']) . '”</p>';

        // A settled proposal keeps its card, because a record deleted from disk
        // is one the variety pass draws again next week. It is not selectable:
        // the queue is what is still waiting.
        $status = (string) ($proposal['status'] ?? 'waiting');
        $settled = $status !== 'waiting';
        $statusNote = trim((string) ($proposal['status_note'] ?? ''));
        $badge = $settled
            ? '<span class="badge badge-' . self::esc($status) . '">' . self::esc($status) . '</span>'
            : '';
        $control = $settled
            ? '<span class="pick settled">not in the queue</span>'
            : '<label class="pick"><input type="checkbox"> build this</label>';
        $noteLine = $statusNote === ''
            ? ''
            : '<p class="meta-line"><b>Status:</b> ' . self::esc($statusNote) . '</p>';

        return '<article class="card proposal' . ($settled ? ' is-settled' : '') . '" data-id="'
            . self::esc($proposal['id'])
            . '" data-family="' . self::esc($proposal['family'])
            . '" data-status="' . self::esc($status)
            . '" data-title="' . self::esc($proposal['title']) . '">'
            . '<div class="meta"><header><h3>' . self::esc($proposal['id']) . '</h3>' . $badge . $control . '</header>'
            . '<p class="summary">' . self::esc($proposal['idea']) . '</p>'
            . $promptLine
            . $noteLine
            . '<p class="meta-line"><b>New because:</b> ' . self::esc($proposal['why_new']) . '</p>'
            . '<p class="meta-line"><b>Built from:</b> ' . self::esc($proposal['built_from']) . '</p>'
            . '<p class="meta-line"><b>Risk:</b> ' . self::esc($proposal['risk']) . '</p>'
            . '<p class="source">' . self::esc($origin) . '</p>'
            . ($settled ? '' : '<textarea placeholder="Notes on ' . self::esc($proposal['id']) . '…"></textarea>')
            . '</div>'
            . '<div class="shots">' . $proposal['mockup']['html'] . '</div></article>';
    }

    private static function composePanel(): string
    {
        return '<section class="compose" id="compose">'
            . '<h2>Compose a new archetype</h2>'
            . '<p class="blurb">The model draws a mockup and argues for it against everything already in the '
            . 'catalog. Records land in <code>docs/archetypes/proposals/</code>, so keep the good ones and delete '
            . 'the rest in a diff.</p>'
            . '<div class="composer">'
            . '<select id="compose-family">'
            . '<option value="section">section</option><option value="hero">hero</option>'
            . '<option value="header">header</option><option value="footer">footer</option></select>'
            . '<input id="compose-prompt" type="text" placeholder="Describe the composition you want — or leave empty and press Add variety">'
            . '<button id="compose-run" class="primary">Draw it</button>'
            . '<button id="compose-auto">Add variety</button>'
            . '<span class="status" id="compose-status"></span>'
            . '</div></section>';
    }

    private static function composeOffline(): string
    {
        return '<section class="compose"><h2>Compose a new archetype</h2>'
            . '<p class="blurb">Composing needs the dev server: run <code>php bin/archetypes.php serve</code> '
            . 'instead of opening this file directly.</p></section>';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function page(
        string $catalog,
        string $proposals,
        string $mockupCss,
        array $totals,
        int $width,
        string $composePanel,
        string $liveFlag,
    ): string {
        $archetypes = $totals['archetypes'];
        $illustrated = $totals['illustrated'];
        $samples = $totals['samples'];
        $proposalCount = $totals['proposals'];

        $warnings = '';
        if ($totals['thin'] !== []) {
            $warnings .= '<p class="warn"><b>' . count($totals['thin']) . ' archetype(s) have one example only.</b> '
                . 'One image shows an archetype exists; it cannot show how much it varies. Run '
                . '<code>php bin/archetypes.php fill</code> to build sites that draw them, then '
                . '<code>capture</code>. ' . self::esc(implode(', ', $totals['thin'])) . '</p>';
        }
        if ($totals['orphans'] !== []) {
            $warnings .= '<p class="warn"><b>Stale shots.</b> These name archetypes no catalog owns any more, so '
                . 'nothing below shows them; <code>php bin/archetypes.php capture</code> drops them. '
                . self::esc(implode(', ', $totals['orphans'])) . '</p>';
        }

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Archetype gallery</title>
<style>
:root {
  color-scheme: light dark;
  --bg: #fbfaf8; --fg: #17160f; --muted: #6d6a5f; --line: #e2dfd5;
  --card: #ffffff; --accent: #8a4b18; --pick: #1f6f4a;
}
@media (prefers-color-scheme: dark) {
  :root { --bg: #131210; --fg: #f1efe9; --muted: #a8a396; --line: #302e28;
          --card: #1b1a17; --accent: #e0ac6c; --pick: #6cc79a; }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--fg);
  font: 16px/1.55 ui-sans-serif, -apple-system, "Segoe UI", Roboto, sans-serif; }
code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .92em; }
.wrap { max-width: 1560px; margin: 0 auto; padding: 2.5rem 1.5rem 15rem; }
h1 { font-size: clamp(1.9rem, 3.4vw, 2.8rem); margin: 0 0 .4rem; letter-spacing: -.025em; }
.lede { color: var(--muted); max-width: 78ch; margin: 0 0 1.5rem; }
.legend { display: flex; flex-wrap: wrap; gap: 1.75rem; margin: 0 0 1.5rem;
  padding: 1rem 1.25rem; border: 1px solid var(--line); border-radius: 10px; background: var(--card); }
.legend div { font-size: .8rem; color: var(--muted); }
.legend strong { display: block; font-size: 1.5rem; color: var(--fg); font-weight: 600; line-height: 1.2; }
nav.tabs { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 2rem;
  position: sticky; top: 0; padding: .75rem 0; background: var(--bg); z-index: 6; }
nav.tabs a, nav.tabs button { text-decoration: none; color: var(--fg); border: 1px solid var(--line);
  border-radius: 999px; padding: .35rem .95rem; font: inherit; font-size: .85rem;
  background: var(--card); cursor: pointer; }
nav.tabs a:hover, nav.tabs button:hover { border-color: var(--accent); color: var(--accent); }
nav.tabs .on { border-color: var(--accent); color: var(--accent); font-weight: 600; }
.pane { display: none; }
.pane.on { display: block; }
.family { margin: 0 0 3.5rem; }
.family h2 { font-size: 1.5rem; margin: 0 0 .3rem; border-top: 1px solid var(--line); padding-top: 1.5rem; }
.family h2 .count { font-size: .8rem; font-weight: 400; color: var(--muted); }
.blurb { color: var(--muted); margin: 0 0 1.5rem; max-width: 80ch; }
.grid { display: grid; gap: 1.5rem; }
/* One catalog card per row: the examples of one archetype belong side by side,
   because comparing them is the whole point of the page. */
.grid.catalog { grid-template-columns: 1fr; }
.grid.proposals { grid-template-columns: repeat(auto-fill, minmax(560px, 1fr)); }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 14px;
  padding: 1.2rem 1.3rem; display: grid; grid-template-columns: minmax(230px, 300px) 1fr;
  gap: 1.5rem; align-items: start; }
.grid.catalog .card { grid-template-columns: minmax(260px, 340px) 1fr; }
.card.is-empty { background: color-mix(in srgb, var(--card) 88%, var(--muted)); }
.card.is-picked { border-color: var(--pick); box-shadow: 0 0 0 1px var(--pick); }
.card.is-settled { opacity: .62; }
.card.is-settled:hover { opacity: 1; }
.card header { display: flex; align-items: baseline; gap: .8rem; }
.badge { font: .66rem/1 ui-monospace, monospace; text-transform: uppercase; letter-spacing: .1em;
  border: 1px solid var(--line); border-radius: 999px; padding: .28rem .6rem; color: var(--muted); }
.badge-built { border-color: var(--pick); color: var(--pick); }
.badge-dropped { border-color: var(--accent); color: var(--accent); }
.pick.settled { font-size: .74rem; font-style: italic; }
.warn { border: 1px solid var(--accent); border-left-width: 3px; border-radius: 8px;
  background: color-mix(in srgb, var(--card) 92%, var(--accent)); color: var(--fg);
  padding: .7rem 1rem; margin: 0 0 1rem; font-size: .82rem; max-width: 100ch; }
.warn b { font-weight: 600; }
details.fragment { margin: 0 0 .8rem; }
details.fragment summary { font-size: .74rem; color: var(--muted); cursor: pointer; }
details.fragment p { font-size: .82rem; margin: .5rem 0 0; }
.card h3 { font: 600 1.02rem/1.3 ui-monospace, SFMono-Regular, Menlo, monospace; margin: 0; }
.pick { margin-left: auto; display: flex; align-items: center; gap: .4rem;
  font-size: .76rem; color: var(--muted); white-space: nowrap; cursor: pointer; }
.pick input { inline-size: 1.05rem; block-size: 1.05rem; accent-color: var(--pick); cursor: pointer; }
.summary { font-size: .88rem; margin: .6rem 0 .8rem; }
.meta-line { font-size: .78rem; color: var(--muted); margin: 0 0 .35rem; }
.meta-line b { color: var(--fg); font-weight: 600; }
.prompted { font-size: .78rem; color: var(--accent); margin: 0 0 .6rem; }
.note { font-size: .78rem; color: var(--accent); border-left: 2px solid var(--accent);
  padding-left: .7rem; margin: 0 0 .8rem; }
.facts { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
  gap: .4rem .9rem; margin: 0 0 .9rem; }
.facts div { border-top: 1px solid var(--line); padding-top: .3rem; }
.facts dt { color: var(--muted); font-size: .72rem; }
.facts dd { margin: 0; font: .72rem/1.4 ui-monospace, SFMono-Regular, Menlo, monospace; overflow-wrap: anywhere; }
.source { font: .68rem/1.45 ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--muted);
  margin: .4rem 0 .6rem; overflow-wrap: anywhere; }
textarea { width: 100%; min-height: 4rem; resize: vertical; font: inherit; font-size: .82rem;
  padding: .5rem .65rem; border-radius: 8px; border: 1px solid var(--line);
  background: var(--bg); color: var(--fg); }
figure { margin: 0; }
figure img { width: 100%; height: auto; display: block; border: 1px solid var(--line);
  border-radius: 8px; background: var(--bg); }
figcaption { font-size: .74rem; color: var(--muted); margin-top: .4rem; }
/* Several examples of one archetype sit in a row so the variance between them
   is readable at a glance. */
.grid.catalog .shots { display: grid; gap: 1rem;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); align-items: start; }
.gap { font-size: .82rem; color: var(--muted); border: 1px dashed var(--line);
  border-radius: 10px; padding: 2rem 1.2rem; text-align: center; margin: 0; }
.gap.thin { padding: 1rem; align-self: center; }
.compose { border: 1px solid var(--line); border-radius: 14px; background: var(--card);
  padding: 1.2rem 1.3rem; margin: 0 0 2.5rem; }
.compose h2 { font-size: 1.15rem; margin: 0 0 .4rem; }
.composer { display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; }
.composer input { flex: 1 1 28rem; font: inherit; font-size: .88rem; padding: .5rem .7rem;
  border-radius: 8px; border: 1px solid var(--line); background: var(--bg); color: var(--fg); }
select, button { font: inherit; font-size: .88rem; padding: .5rem .9rem; border-radius: 8px;
  border: 1px solid var(--line); background: var(--bg); color: var(--fg); cursor: pointer; }
button.primary { background: var(--pick); border-color: var(--pick); color: #fff; font-weight: 600; }
button:disabled { opacity: .5; cursor: not-allowed; }
.status { font-size: .8rem; color: var(--muted); }
.bar { position: fixed; inset-inline: 0; bottom: 0; background: var(--card);
  border-top: 1px solid var(--line); padding: .85rem 1.5rem; z-index: 9;
  box-shadow: 0 -10px 30px rgba(0,0,0,.09); }
.bar .row { max-width: 1560px; margin: 0 auto; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
.count-live { font-size: .88rem; color: var(--muted); }
.count-live b { color: var(--fg); font-size: 1.05rem; }
#out { width: 100%; min-height: 7rem; margin-top: .7rem; display: none;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .74rem; }
#out.show { display: block; }

/* ---- mockup canvas: shared chrome every proposal draws inside ---- */
.preview { aspect-ratio: 16 / 10; border-radius: 10px; overflow: hidden; position: relative;
  container-type: inline-size; isolation: isolate; border: 1px solid var(--line);
  background: #f3efe6; color: #1b1a17; font-family: Georgia, "Times New Roman", serif; }
.preview * { box-sizing: border-box; }
.preview .chrome { position: absolute; inset-inline: 0; top: 0; z-index: 4;
  display: flex; align-items: center; justify-content: space-between;
  padding: 2.4cqw 4cqw; font-family: ui-sans-serif, system-ui, sans-serif;
  font-size: 1.5cqw; letter-spacing: .1em; text-transform: uppercase; }
.preview .chrome nav { display: flex; gap: 2.4cqw; opacity: .78; }
.preview .ph { font-family: ui-sans-serif, system-ui, sans-serif; }
.preview .h { font-weight: 700; letter-spacing: -.02em; line-height: .95; }
.preview .sub { font-family: ui-sans-serif, system-ui, sans-serif; opacity: .72; line-height: 1.35; }
.preview .lbl { font-family: ui-monospace, monospace; text-transform: uppercase; letter-spacing: .14em; }
.preview .img { background: linear-gradient(140deg, #6f7f79 0%, #47565f 45%, #2b3239 100%); position: relative; }
.preview .img.b { background: linear-gradient(140deg, #b08a5a 0%, #7c5a37 60%, #3a2b1c 100%); }
.preview .img.c { background: linear-gradient(160deg, #8d99a6 0%, #55606c 100%); }
.preview .ph-sea { background: radial-gradient(120% 80% at 20% 8%, rgba(255,255,255,.35), transparent 55%),
  linear-gradient(#9fb3bd 0%, #7d97a5 34%, #4d6b78 35%, #2c4653 68%, #16262e 100%); }
.preview .ph-kiln { background: radial-gradient(60% 55% at 62% 42%, rgba(255,214,150,.85), transparent 62%),
  linear-gradient(160deg, #7d5a35 0%, #4a3320 55%, #221609 100%); }
.preview .ph-room { background: linear-gradient(105deg, rgba(255,246,225,.9) 0 18%, rgba(255,240,205,.25) 26%, transparent 42%),
  linear-gradient(200deg, #7a7364 0%, #4d4739 55%, #23201a 100%); }
.preview .ph-press { background: radial-gradient(90% 70% at 78% 22%, rgba(255,255,255,.28), transparent 60%),
  linear-gradient(150deg, #4a5766 0%, #2b3440 60%, #14181f 100%); }
.preview .ph-figure { background: radial-gradient(38% 46% at 50% 58%, #2a2724 0 46%, transparent 47%),
  linear-gradient(180deg, #cfc4b0 0%, #a08f76 70%, #6f6350 100%); }
.preview.grain::before { content: ""; position: absolute; inset: 0; z-index: 3; pointer-events: none;
  opacity: .5; mix-blend-mode: overlay;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='3'/%3E%3C/filter%3E%3Crect width='120' height='120' filter='url(%23n)' opacity='.42'/%3E%3C/svg%3E"); }
{$mockupCss}
@media (max-width: 900px) { .card { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="wrap">
<h1>Archetype gallery</h1>
<p class="lede">Every layout archetype this generator can draw, illustrated with real generated sites,
beside mockups of the ones it cannot draw yet. Screenshots come from built demo sites at {$width}px and
are committed with the tool. Pick the proposals worth building and copy the prompt at the bottom.</p>

<div class="legend">
<div><strong>{$archetypes}</strong>archetypes in the generator</div>
<div><strong>{$illustrated}</strong>illustrated by a real build</div>
<div><strong>{$samples}</strong>screenshots across them</div>
<div><strong>{$proposalCount}</strong>proposals waiting</div>
</div>

{$warnings}

<nav class="tabs">
<button data-pane="catalog" class="on">What we can build</button>
<button data-pane="proposals">What we could build</button>
<button data-pane="proposals" data-scroll="compose">Compose</button>
</nav>

<div class="pane on" id="pane-catalog">{$catalog}</div>
<div class="pane" id="pane-proposals">{$composePanel}{$proposals}</div>
</div>

<div class="bar">
  <div class="row">
    <span class="count-live"><b id="n">0</b> proposals selected</span>
    <label class="status"><input type="checkbox" id="research"> research current design trends first</label>
    <button id="none">Clear</button>
    <button class="primary" id="copy" disabled>Copy prompt for Claude Code</button>
    <span class="status" id="status"></span>
  </div>
  <div class="row"><textarea id="out" readonly></textarea></div>
</div>

<script>
const LIVE = {$liveFlag};
// Only a waiting proposal is in the queue; a built or dropped one keeps its
// card as a record and carries no checkbox.
const cards = [...document.querySelectorAll('.card.proposal:not(.is-settled)')];
const out = document.getElementById('out');
const copyBtn = document.getElementById('copy');
const status = document.getElementById('status');
const picked = () => cards.filter((card) => card.querySelector('.pick input').checked);

document.querySelectorAll('nav.tabs button[data-pane]').forEach((tab) => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('nav.tabs button[data-pane]').forEach((t) => t.classList.toggle('on', t === tab));
    document.querySelectorAll('.pane').forEach((pane) => {
      pane.classList.toggle('on', pane.id === 'pane-' + tab.dataset.pane);
    });
    // The composer lives inside the proposals pane, so it can only be scrolled
    // to after that pane is the visible one.
    if (tab.dataset.scroll) {
      const target = document.getElementById(tab.dataset.scroll);
      if (target) target.scrollIntoView({ block: 'start' });
    }
  });
});

function buildPrompt() {
  const chosen = picked();
  const families = [...new Set(chosen.map((c) => c.dataset.family))];
  const what = families.length === 1 ? families[0] + ' archetypes' : 'layout archetypes';
  const lines = [
    'Build these ' + what + ' for the builder2 generator, one Linear issue and one PR each.',
    '',
    'I picked them in the archetype gallery (php bin/archetypes.php serve). For each one:',
    '1. Open a Linear issue in the "Generated themes replace Assembler in Big Sky" project (team Big River), as a sub-issue of BIGR-885.',
    '2. Branch from fresh trunk and implement it: the catalog entry with complete metadata, the prompt fragment, the CSS hook in ScaffoldThemeStep, the objective-failure check in markupWarnings() with its regression test, and tests.',
    '3. Build at least four demo sites from different briefs with the recipe pinned, screenshot the heroes or sections, and put the images in a gist referenced from the PR description.',
    '4. Stop and show me the screenshots before opening the next PR.',
    '',
    'Archetypes, in the order I want them built:',
    '',
  ];
  chosen.forEach((card, index) => {
    const note = card.querySelector('textarea').value.trim();
    lines.push(`\${index + 1}. [\${card.dataset.family}] \${card.dataset.title}`);
    lines.push('   ' + card.querySelector('.summary').textContent.replace(/\s+/g, ' ').trim());
    lines.push('   Mockup and rationale: docs/archetypes/proposals/' + card.dataset.family + '--' + card.dataset.id + '.json');
    if (note !== '') lines.push('   MY NOTES: ' + note);
    lines.push('');
  });
  if (document.getElementById('research').checked) {
    lines.push('Before implementing, research current design trends on real sites and tell me which of my picks that changes, if any.');
    lines.push('');
  }
  // Qualified by family on purpose: an id is unique inside a family only —
  // `contact-sheet` is both a proposed hero and a footer already in the catalog.
  const skipped = cards
    .filter((c) => !c.querySelector('.pick input').checked)
    .map((c) => c.dataset.family + '/' + c.dataset.id);
  if (skipped.length) lines.push('Not chosen, do not build: ' + skipped.join(', ') + '.');
  lines.push('');
  lines.push('When one is built and merged, record it so the gallery stops offering it:');
  chosen.forEach((card) => {
    lines.push('  php bin/archetypes.php status ' + card.dataset.family + '/' + card.dataset.id + ' built');
  });
  return lines.join('\\n');
}

function refresh() {
  const chosen = picked();
  document.getElementById('n').textContent = String(chosen.length);
  cards.forEach((c) => c.classList.toggle('is-picked', c.querySelector('.pick input').checked));
  copyBtn.disabled = chosen.length === 0;
  if (out.classList.contains('show')) out.value = buildPrompt();
}
document.body.addEventListener('change', refresh);
document.body.addEventListener('input', refresh);
document.getElementById('none').addEventListener('click', () => {
  cards.forEach((c) => { c.querySelector('.pick input').checked = false; });
  refresh();
});
copyBtn.addEventListener('click', async () => {
  const text = buildPrompt();
  out.value = text;
  out.classList.add('show');
  try {
    await navigator.clipboard.writeText(text);
    status.textContent = 'Copied — paste it into Claude Code.';
  } catch {
    out.select();
    status.textContent = 'Clipboard blocked; the text is selected below.';
  }
  if (LIVE) {
    fetch('/api/select', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ prompt: text, ids: picked().map((c) => c.dataset.family + '/' + c.dataset.id) }),
    }).catch(() => {});
  }
  setTimeout(() => { status.textContent = ''; }, 6000);
});

const composeRun = document.getElementById('compose-run');
if (composeRun) {
  const composeStatus = document.getElementById('compose-status');
  const compose = async (auto) => {
    const family = document.getElementById('compose-family').value;
    const prompt = document.getElementById('compose-prompt').value.trim();
    if (!auto && prompt === '') { composeStatus.textContent = 'Describe it, or press Add variety.'; return; }
    composeRun.disabled = true;
    composeStatus.textContent = auto ? 'Looking for a gap in the catalog…' : 'Drawing…';
    try {
      const res = await fetch('/api/propose', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ family, prompt, auto }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'the model call failed');
      composeStatus.textContent = 'Added ' + data.id + ' — reloading…';
      setTimeout(() => window.location.reload(), 700);
    } catch (err) {
      composeStatus.textContent = String(err.message || err);
    } finally {
      composeRun.disabled = false;
    }
  };
  composeRun.addEventListener('click', () => compose(false));
  document.getElementById('compose-auto').addEventListener('click', () => compose(true));
}
refresh();
</script>
</body>
</html>
HTML;
    }
}
