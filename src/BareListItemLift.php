<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Lifts bare HTML `<li>` children of an authored wp:list block into
 * wp:list-item inner blocks before the block fixer runs.
 *
 * The section author sometimes writes a list as plain markup —
 * `<!-- wp:list --><ul><li>…</li></ul><!-- /wp:list -->` — without the
 * wp:list-item delimiters Core's grammar requires. Re-serialization
 * regenerates a list's save output from its inner blocks only, so every
 * bare item would be dropped and an empty `<ul>` delivered. Mirroring the
 * authored HTML into real block structure ahead of the fixer preserves the
 * content without touching the pinned transform itself.
 *
 * The lift is deliberately bounded: it only rewrites a list whose body is
 * exactly one flat `<ul>`/`<ol>` of `<li>` elements with nothing else in
 * it. Nested lists, existing inner blocks, stray text between items, or
 * unbalanced markup leave the block byte-identical for the fixer's own
 * degrade-and-warn path. Lifted output is already block-structured, so a
 * second pass finds nothing to do (idempotent).
 */
final class BareListItemLift
{
    /** @return array{markup:string, notes:list<string>} */
    public static function fix(string $markup): array
    {
        if (!str_contains($markup, '<!-- wp:list')) {
            return ['markup' => $markup, 'notes' => []];
        }

        $notes = [];
        $ordinal = -1;
        $fixed = preg_replace_callback(
            '/(<!-- wp:list(?!-)[^>]*-->)([\s\S]*?)(<!-- \/wp:list -->)/',
            function (array $block) use (&$notes, &$ordinal): string {
                $ordinal++;
                $body = $block[2];
                if (str_contains($body, '<!-- wp:')) {
                    // Already block-structured (or a nested proper list made
                    // the non-greedy region ambiguous) — not ours to touch.
                    return $block[0];
                }
                if (!preg_match(
                    '/^(\s*)<(ul|ol)((?:\s[^>]*)?)>([\s\S]*?)<\/\2>(\s*)$/i',
                    $body,
                    $list,
                )) {
                    return $block[0];
                }
                $items = $list[4];
                if (preg_match('/<(?:ul|ol)\b/i', $items)) {
                    // A nested list needs inner wp:list blocks, not a flat
                    // lift; leave it to the fixer's own loss accounting.
                    return $block[0];
                }
                if (substr_count(strtolower($items), '<li') !== substr_count(strtolower($items), '</li>')) {
                    return $block[0];
                }
                $itemPattern = '/<li((?:\s[^>]*)?)>([\s\S]*?)<\/li>/i';
                $residue = trim((string) preg_replace($itemPattern, '', $items));
                if ($residue !== '') {
                    // Stray content between items would be silently dropped by
                    // re-serialization; keep the authored bytes instead.
                    return $block[0];
                }
                $count = 0;
                $lifted = preg_replace_callback(
                    $itemPattern,
                    function (array $li) use (&$count): string {
                        $count++;
                        return "<!-- wp:list-item -->\n<li{$li[1]}>{$li[2]}</li>\n<!-- /wp:list-item -->";
                    },
                    $items,
                );
                if ($count === 0 || $lifted === null) {
                    return $block[0];
                }
                $notes[] = "wp:list[{$ordinal}]: lifted {$count} bare <li> item(s) into wp:list-item blocks";
                return $block[1]
                    . $list[1] . '<' . strtolower($list[2]) . $list[3] . '>'
                    . "\n" . trim($lifted) . "\n"
                    . '</' . strtolower($list[2]) . '>' . $list[5]
                    . $block[3];
            },
            $markup,
        );

        return ['markup' => $fixed ?? $markup, 'notes' => $notes];
    }
}
