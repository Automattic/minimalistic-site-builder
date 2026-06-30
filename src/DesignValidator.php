<?php
declare(strict_types=1);

/**
 * Validators V2–V5 — the markup-level design contract, computed (not trusted to
 * the model). Where ContrastValidator (V1) guards the palette at token time,
 * this lints the generated block markup of every part/template:
 *
 *   V2 — Paired color: a block that sets a background MUST set text color; a
 *        block with a border width MUST set border color; buttons set both.
 *   V3 — Grid/flex spacing: a grid/flex layout container MUST declare blockGap.
 *   V4 — Token discipline: no hardcoded hex / px font-size / literal font-family
 *        in block attributes — reference theme.json tokens by slug.
 *   V5 — Alignment / edge bleed: every top-level section MUST declare an align
 *        (full or wide) so full-bleed backgrounds reach the viewport edge.
 *
 * Authority: telex `shared-rules.md` (color pairing + token discipline + the
 * grid/flex gap rule) and `foundation.md` (alignment). Heuristic by nature —
 * run in report mode (counts + a written report), surfaced loudly but not
 * aborting a build, since a single false positive shouldn't sink an otherwise
 * good theme. Pure + static so it is unit-testable.
 *
 * Each finding is `['rule' => string, 'file' => string, 'detail' => string]`.
 */
final class DesignValidator
{
    /** Files whose OUTERMOST block must declare an align (V5). header/footer exempt. */
    private const SECTION_GLOB = 'theme/parts/section-*.html';

    /** Block markup files to lint for V2–V4. */
    private const MARKUP_GLOB = ['theme/parts/*.html', 'theme/templates/*.html'];

    /**
     * Run V2–V5 across a project's generated markup.
     *
     * @return array<int,array{rule:string,file:string,detail:string}>
     */
    public static function validate(Project $project): array
    {
        $findings = [];

        foreach (self::files($project, self::MARKUP_GLOB) as $rel) {
            $markup = $project->readText($rel);
            foreach (self::extractBlocks($markup) as $block) {
                self::checkBlock($rel, $block, $findings);
            }
        }

        // V5 — each top-level section's outer block declares an align.
        foreach (self::files($project, [self::SECTION_GLOB]) as $rel) {
            $blocks = self::extractBlocks($project->readText($rel));
            $outer = $blocks[0] ?? null;
            if ($outer === null) {
                continue;
            }
            $align = $outer['attrs']['align'] ?? null;
            if (!in_array($align, ['full', 'wide'], true)) {
                $findings[] = [
                    'rule'   => 'V5-alignment',
                    'file'   => $rel,
                    'detail' => "outer <!-- wp:{$outer['name']} --> has no \"align\":\"full\"|\"wide\" (renders as a narrow column)",
                ];
            }
        }

        return $findings;
    }

    /**
     * V2–V4 checks for a single block.
     *
     * @param array{name:string,attrs:array<mixed>,raw:string} $block
     * @param array<int,array{rule:string,file:string,detail:string}> $findings
     */
    private static function checkBlock(string $file, array $block, array &$findings): void
    {
        $name = $block['name'];
        $attrs = $block['attrs'];

        // Visual-only blocks carry color but render no text (separator = a rule,
        // spacer = empty, image = picture) or handle their own overlay text
        // (cover). They're exempt from the background/border ↔ text pairing.
        $textless = in_array($name, ['cover', 'separator', 'spacer', 'image'], true);

        $hasBg = isset($attrs['backgroundColor']) || isset($attrs['style']['color']['gradient'])
            || isset($attrs['style']['color']['background']);
        $hasText = isset($attrs['textColor']) || isset($attrs['style']['color']['text']);

        // V2a — background without text color (the invisible-text bug family).
        if ($hasBg && !$hasText && !$textless) {
            $findings[] = [
                'rule'   => 'V2-paired-color',
                'file'   => $file,
                'detail' => "wp:{$name} sets a background but no text color (invisible-text risk)",
            ];
        }

        // V2b — border width without border color.
        $borderWidth = $attrs['style']['border']['width'] ?? null;
        $hasBorderColor = isset($attrs['borderColor']) || isset($attrs['style']['border']['color']);
        if ($borderWidth !== null && !$hasBorderColor && !$textless) {
            $findings[] = [
                'rule'   => 'V2-paired-color',
                'file'   => $file,
                'detail' => "wp:{$name} sets a border width but no border color (inherits currentColor)",
            ];
        }

        // V2c — buttons must declare both text and background colors.
        if ($name === 'button' && !($hasBg && $hasText)) {
            $findings[] = [
                'rule'   => 'V2-paired-color',
                'file'   => $file,
                'detail' => 'wp:button does not declare both text and background colors',
            ];
        }

        // V3 — grid/flex container must declare a blockGap.
        $layoutType = $attrs['layout']['type'] ?? null;
        if (in_array($layoutType, ['grid', 'flex'], true)) {
            $hasGap = isset($attrs['style']['spacing']['blockGap']);
            // wp:navigation / wp:buttons get a site-wide default from theme.json,
            // so only flag the general grid/flex containers that don't.
            if (!$hasGap && !in_array($name, ['navigation', 'buttons'], true)) {
                $findings[] = [
                    'rule'   => 'V3-grid-flex-gap',
                    'file'   => $file,
                    'detail' => "wp:{$name} uses layout.type:{$layoutType} without style.spacing.blockGap",
                ];
            }
        }

        // V4 — token discipline: no hardcoded hex / px font-size / literal font in attrs.
        foreach (self::hardcodedValues($attrs) as $bad) {
            $findings[] = [
                'rule'   => 'V4-token-discipline',
                'file'   => $file,
                'detail' => "wp:{$name} hardcodes {$bad} (use a theme.json token slug instead)",
            ];
        }
    }

    /**
     * Hardcoded values inside a block's attributes that should be tokens:
     * a hex color, a px/rem font-size literal, or a literal font-family name.
     *
     * @param array<mixed> $attrs
     * @return string[]
     */
    private static function hardcodedValues(array $attrs): array
    {
        $bad = [];

        $bg = $attrs['style']['color']['background'] ?? null;
        $text = $attrs['style']['color']['text'] ?? null;
        foreach (['background' => $bg, 'text' => $text] as $role => $val) {
            if (is_string($val) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $val)) {
                $bad[] = "{$role} color {$val}";
            }
        }

        // The spec bans raw hex / px / font-family literals. A px font-size is a
        // hardcode the type scale should own; rem/em are tolerated for fine
        // typographic control (the model can't always hit a scale step exactly).
        $fontSize = $attrs['style']['typography']['fontSize'] ?? null;
        if (is_string($fontSize) && preg_match('/^[0-9.]+px$/', $fontSize)) {
            $bad[] = "font-size {$fontSize}";
        }

        $fontFamily = $attrs['style']['typography']['fontFamily'] ?? null;
        if (is_string($fontFamily) && $fontFamily !== '') {
            $bad[] = "font-family literal";
        }

        return $bad;
    }

    /**
     * Extract every block-comment opener from markup as
     * `['name' => 'group', 'attrs' => [...], 'raw' => '<!-- wp:group {...} -->']`.
     * Block names are returned without the `core/` prefix. Handles balanced JSON
     * (nested objects/arrays) and self-closing blocks. Pure — unit-testable.
     *
     * @return array<int,array{name:string,attrs:array<mixed>,raw:string}>
     */
    public static function extractBlocks(string $markup): array
    {
        $blocks = [];
        $len = strlen($markup);
        $offset = 0;
        while (($pos = strpos($markup, '<!-- wp:', $offset)) !== false) {
            $i = $pos + strlen('<!-- wp:');
            // Block name: letters, digits, slash, hyphen.
            $nameStart = $i;
            while ($i < $len && (ctype_alnum($markup[$i]) || $markup[$i] === '/' || $markup[$i] === '-')) {
                $i++;
            }
            $name = substr($markup, $nameStart, $i - $nameStart);
            // Closing openers (`<!-- /wp:` ) won't match here since we matched
            // `<!-- wp:` exactly; skip whitespace to an optional JSON object.
            while ($i < $len && ($markup[$i] === ' ' || $markup[$i] === "\t")) {
                $i++;
            }
            $attrs = [];
            if ($i < $len && $markup[$i] === '{') {
                $json = self::readBalancedBraces($markup, $i); // advances by ref via return end
                if ($json !== null) {
                    $decoded = json_decode($json[0], true);
                    if (is_array($decoded)) {
                        $attrs = $decoded;
                    }
                    $i = $json[1];
                }
            }
            $end = strpos($markup, '-->', $i);
            $rawEnd = $end === false ? $len : $end + 3;
            $blocks[] = [
                'name'  => preg_replace('#^core/#', '', $name) ?? $name,
                'attrs' => $attrs,
                'raw'   => substr($markup, $pos, $rawEnd - $pos),
            ];
            $offset = $rawEnd;
        }
        return $blocks;
    }

    /**
     * Read a balanced `{...}` JSON object starting at $start (which points at the
     * `{`). Returns [jsonString, endIndexExclusive] or null if unbalanced.
     * String-aware so a `}` inside a JSON string doesn't close the object.
     *
     * @return array{0:string,1:int}|null
     */
    private static function readBalancedBraces(string $s, int $start): ?array
    {
        $depth = 0;
        $inStr = false;
        $esc = false;
        $len = strlen($s);
        for ($i = $start; $i < $len; $i++) {
            $c = $s[$i];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($c === '\\') {
                    $esc = true;
                } elseif ($c === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($c === '"') {
                $inStr = true;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return [substr($s, $start, $i - $start + 1), $i + 1];
                }
            }
        }
        return null;
    }

    /**
     * Existing files matching any of the given repo-relative globs.
     *
     * @param string[] $globs
     * @return string[]
     */
    private static function files(Project $project, array $globs): array
    {
        $out = [];
        foreach ($globs as $glob) {
            foreach (glob($project->path($glob)) ?: [] as $abs) {
                $out[] = ltrim(str_replace($project->path(), '', $abs), '/');
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Render findings as a Markdown report (for logs/design-validation.md).
     *
     * @param array<int,array{rule:string,file:string,detail:string}> $findings
     */
    public static function report(array $findings): string
    {
        $rules = ['V2-paired-color', 'V3-grid-flex-gap', 'V4-token-discipline', 'V5-alignment'];
        $counts = array_fill_keys($rules, 0);
        foreach ($findings as $f) {
            if (isset($counts[$f['rule']])) {
                $counts[$f['rule']]++;
            }
        }

        $lines = ['# Design validation (V2–V5)', ''];
        $lines[] = $findings === []
            ? '**PASS** — zero violations across V2–V5.'
            : '**' . count($findings) . ' violation(s)** found.';
        $lines[] = '';
        foreach ($rules as $rule) {
            $lines[] = "- {$rule}: {$counts[$rule]}";
        }
        if ($findings !== []) {
            $lines[] = '';
            $lines[] = '## Details';
            foreach ($findings as $f) {
                $lines[] = "- `{$f['rule']}` **{$f['file']}** — {$f['detail']}";
            }
        }
        return implode("\n", $lines) . "\n";
    }
}
