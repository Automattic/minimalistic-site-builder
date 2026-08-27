<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TreeGraph\Styles;
use Automattic\SiteBuild\TreeGraph\TokenMath;
use Automattic\SiteBuild\TreeGraph\TreeGraphException;
use Automattic\SiteBuild\TreeGraph\TreeLlm;

/**
 * Tree graph step 4: design tokens (port of x-pipeline S3).
 *
 * One LLM call authors the DesignTokens document; mechanical checks hold it
 * to the R9 discipline (the theme's own spacing and layout pass through
 * BYTE-FOR-BYTE — they are never redesigned), the brief's palette must
 * appear whole, and the theme's base/contrast pair must read at 4.5:1. The
 * companion then compiles it into user-origin global styles: a dry run is
 * the free rehearsal (preview + diff gates), the real apply moves the
 * fingerprint every later stage builds against.
 */
final class TreeTokensStep implements Step
{
    /**
     * One deliberate seam reset, stage-authored like the R9 passthrough.
     * Core injects margin-block-start: var(--wp--style--block-gap) between
     * top-level blocks even when the theme declares no blockGap; bands own
     * their vertical rhythm through their own padding, so the seams are pure
     * leakage, reset once here. Inner layouts keep their default gap.
     */
    private const SEAM_RESET = ".wp-site-blocks > * + * { margin-block-start: 0; }\n"
        . '.wp-block-post-content > * + * { margin-block-start: 0; }';

    public function __construct(
        private readonly Llm $llm,
        private readonly ?string $model = null,
        private readonly ?float $temperature = null,
    ) {}

    public function id(): string
    {
        return 'tokens';
    }

    public function label(): string
    {
        return 'Author + apply design tokens';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['brief.json', 'instance.json', 'sandbox.json', 'budget.json'],
            writes: ['tokens.json', 'tokens-dry-run.json', 'instance.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $brief = $project->readJson('brief.json');
        $instance = $project->readJson('instance.json');
        $sandbox = $project->readJson('sandbox.json');
        $client = new \Automattic\SiteBuild\TreeGraph\SandboxClient((string) $sandbox['url']);

        $themeTokens = (array) ($instance['theme_tokens'] ?? []);
        $themeSpacing = TokenMath::deriveThemeSpacing($themeTokens);
        $themeLayout = TokenMath::deriveThemeLayout($themeTokens);

        // No core default backs constrained layout: a theme that declares
        // neither contentSize nor wideSize gets NO max-width on
        // `.is-layout-constrained > *`, so "constrained" constrains nothing.
        // The values pass through verbatim (R9); assert there is something to
        // pass.
        if (($themeLayout['contentSize'] ?? '') === '' || ($themeLayout['wideSize'] ?? '') === '') {
            $missing = ($themeLayout['contentSize'] ?? '') !== '' ? 'wideSize' : 'contentSize';
            throw new TreeGraphException(
                'gate_failed',
                "the theme declares no settings.layout.{$missing} — constrained layout would constrain nothing"
                . ' and every "centered" section would silently run full width',
                "Declare both contentSize and wideSize in the theme's theme.json; the pipeline passes them"
                . ' through, it never invents them.',
            );
        }

        $contract = json_decode(
            (string) file_get_contents(\repo_path('schemas/tree/design-tokens.schema.json')),
            true,
        );

        $styleNote = Styles::renderStyleNote($brief['style'] ?? null);
        if ($styleNote !== '') {
            $styleNote .= "\nThe token system is where the combo becomes real: the palette carries the artistic"
                . " style's color story; the type scale carries its typographic attitude filtered through the UI"
                . " style's discipline. A token set that could belong to any other combo is not done.";
        }

        $briefPalette = (array) ($brief['palette'] ?? []);
        $lane = TreeLlm::forProject($this->llm, $project, $this->model === null ? [] : ['tokens' => $this->model], ['tokens' => $this->temperature]);
        $raw = $lane->generate(
            'tokens',
            'tokens',
            [
                'identity'      => $brief['identity'] ?? [],
                'art_direction' => (string) ($brief['art_direction'] ?? ''),
                'style_note'    => $styleNote,
                'palette'       => $briefPalette,
                'theme_spacing' => $themeSpacing,
                'theme_layout'  => $themeLayout,
                'contract_note' => [
                    'note'   => 'Your output must validate against this JSON Schema (design-tokens.schema.json):',
                    'schema' => $contract,
                ],
            ],
            static fn (array $v): array => TokenMath::tokenChecks($v, $themeSpacing, $themeLayout, $briefPalette),
        );

        // The companion's input validation is its own copy of the shape;
        // strip typography sizes to exactly what it accepts. tokens.json
        // keeps the strip too — it is the applied record.
        $tokens = $raw;
        if (isset($raw['typography']['sizes']) && is_array($raw['typography']['sizes'])) {
            $tokens['typography']['sizes'] = array_map(
                static function (array $size): array {
                    $out = ['slug' => $size['slug'] ?? '', 'size' => $size['size'] ?? ''];
                    if (array_key_exists('fluid', $size)) {
                        $out['fluid'] = $size['fluid'];
                    }
                    return $out;
                },
                $raw['typography']['sizes'],
            );
        }
        $globalCss = trim((string) ($tokens['css']['global'] ?? ''));
        $tokens['css'] = array_merge(
            (array) ($tokens['css'] ?? []),
            ['global' => $globalCss === '' ? self::SEAM_RESET : $globalCss . "\n" . self::SEAM_RESET],
        );

        // Gate, part 1: the free rehearsal — deterministic sanity on the diff.
        $dry = $client->tokensApply($tokens, true);
        $drift = array_values(array_filter(
            (array) ($dry['diff_against_instance'] ?? []),
            static fn (array $d): bool => in_array($d['group'] ?? '', ['spacing.spacingSizes', 'layout'], true)
                && ($d['kind'] ?? '') === 'value_differs',
        ));
        if ($drift !== []) {
            throw new TreeGraphException(
                'gate_failed',
                'R9 violation surfaced by the dry-run diff: theme spacing/layout moved',
                "The theme's own spacing and layout pass through verbatim; nothing may redesign them.",
                ['diffs' => $drift],
            );
        }
        $previewText = strtolower((string) json_encode($dry['theme_json_preview'] ?? []));
        $missing = array_values(array_filter(
            $briefPalette,
            static fn (array $p): bool => !str_contains($previewText, strtolower((string) $p['color'])),
        ));
        if ($missing !== []) {
            throw new TreeGraphException(
                'gate_failed',
                'brief palette entr' . (count($missing) === 1 ? 'y' : 'ies') . ' missing from the compiled preview: '
                . implode(', ', array_map(static fn (array $m): string => "{$m['name']} {$m['color']}", $missing)),
                '',
                ['missing' => $missing],
            );
        }
        $project->writeJson('tokens-dry-run.json', [
            'preview' => $dry['theme_json_preview'] ?? [],
            'diff'    => $dry['diff_against_instance'] ?? [],
        ]);

        // Gate, part 2: the real apply — the fingerprint moves.
        $applied = $client->tokensApply($tokens, false);
        if ((array) ($applied['css_rejected'] ?? []) !== []) {
            $rejected = array_map(
                static fn (array $r): string => "{$r['target']}: {$r['reason']}",
                (array) $applied['css_rejected'],
            );
            throw new TreeGraphException(
                'gate_failed',
                'the css sanitizer rejected part of the token css (' . implode(' | ', $rejected)
                . ') — the seam reset must land whole',
                '',
                ['css_rejected' => $applied['css_rejected']],
            );
        }
        $project->writeJson('tokens.json', $tokens);
        $instance['fingerprint'] = (string) ($applied['fingerprint'] ?? $instance['fingerprint']);
        $project->writeJson('instance.json', $instance);
        Narrator::write(sprintf(
            "  design tokens applied — %d colours, %d font families; fingerprint moved to %s…\n",
            count((array) ($tokens['palette'] ?? [])),
            count((array) ($tokens['typography']['families'] ?? [])),
            substr((string) $instance['fingerprint'], 0, 8),
        ));
    }
}
