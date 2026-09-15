<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The content document one section asks the model for in content mode, and
 * the JSON schema that bounds it.
 *
 * The document holds copy and image subjects only. Every structural decision
 * (archetype, background, placement, card style, item pattern) is already in
 * the page plan and the design direction, and SectionContentCompiler executes
 * it. One item shape serves every archetype; ITEM_KEYS names the subset each
 * archetype reads, so the schema stays small and the model writes no field the
 * compiler ignores.
 */
final class SectionContent
{
    public const IMAGE_STYLES = [
        'photorealistic', 'digital-art', 'illustration', 'minimalist',
        'flat-design', '3d-render', 'abstract', 'watercolor', 'ui-screenshot',
    ];

    /** Item fields each archetype reads, in the order the model should write them. */
    public const ITEM_KEYS = [
        'asymmetric-split'      => ['heading', 'text'],
        'bento-grid'            => ['heading', 'text', 'image_subject', 'image_context'],
        'cta-panel'             => [],
        'equal-card-grid'       => ['heading', 'text', 'list', 'link_label', 'link_href', 'image_subject', 'image_context'],
        'faq-split'             => ['heading', 'text'],
        'feature-row-hairlines' => ['heading', 'text'],
        'full-bleed-cover'      => [],
        'logo-strip'            => ['heading'],
        'offset-grid'           => ['text', 'image_subject', 'image_context'],
        'pricing-tiers'         => ['heading', 'meta', 'list', 'link_label'],
        'project-grid-2x2'      => ['heading', 'meta', 'image_subject', 'image_context'],
        'stat-ledger'           => ['heading', 'text'],
        'zigzag-steps'          => ['heading', 'text', 'image_subject', 'image_context'],
    ];

    /** Archetypes that render a ledger or chip pattern in their content region; elsewhere the pattern only marks the items. */
    public const LEDGER_ARCHETYPES = ['equal-card-grid', 'asymmetric-split'];

    /** Item fields a list-like item pattern reads instead of the archetype's. */
    private const PATTERN_ITEM_KEYS = [
        'rule-row'    => ['heading', 'text'],
        'spec-table'  => ['heading', 'text'],
        'tag-cluster' => ['heading'],
    ];

    /** [min, max] item counts per archetype. */
    private const ITEM_COUNTS = [
        'asymmetric-split'      => [0, 5],
        'bento-grid'            => [5, 5],
        'cta-panel'             => [0, 0],
        'equal-card-grid'       => [2, 4],
        'faq-split'             => [3, 7],
        'feature-row-hairlines' => [3, 4],
        'full-bleed-cover'      => [0, 0],
        'logo-strip'            => [4, 8],
        'offset-grid'           => [2, 6],
        'pricing-tiers'         => [2, 3],
        'project-grid-2x2'      => [2, 4],
        'stat-ledger'           => [3, 4],
        'zigzag-steps'          => [3, 5],
    ];

    /** Whether the archetype has one section-level image slot. */
    private const SECTION_IMAGE = [
        'asymmetric-split' => 'the supporting region beside the copy',
        'cta-panel'        => 'the panel image beside the copy',
        'faq-split'        => 'a small image under the introduction',
        'full-bleed-cover' => 'the full-width background the copy sits on',
    ];

    /** The content shape the model reads, per archetype. */
    private const SHAPES = [
        'asymmetric-split'      => 'One lead region with the heading, the lead line, and one to three paragraphs. Items are short label/value facts for the supporting region, or none.',
        'bento-grid'            => 'Exactly five cards. Each card has a short heading and one short paragraph. The first card is the highlight.',
        'cta-panel'             => 'One heading, one lead line, and the action label. No items.',
        'equal-card-grid'       => 'Two to four equal cards. Each card has a heading, one short paragraph or a short list, and at most one text link.',
        'faq-split'             => 'One heading and one lead line. Items are questions (heading) with one or two sentence answers (text).',
        'feature-row-hairlines' => 'One heading and at most one lead line. Items are three or four short columns: a two to four word heading and one or two short sentences.',
        'full-bleed-cover'      => 'One heading, at most one supporting paragraph, and the action label. No items.',
        'logo-strip'            => 'At most one lead line of eight words. Items are four to eight client, partner, or publication names of one to three words.',
        'offset-grid'           => 'One heading and one lead line. Items are pictures: one caption line (text) and one image subject each.',
        'pricing-tiers'         => 'One heading and one lead line. Items are plans: the plan name (heading), the price and period (meta), three to five short features (list), and a button label (link_label).',
        'project-grid-2x2'      => 'One heading and one lead line. Items are two or four projects: a two to five word name (heading), two or three short terms separated by " · " (meta), and one image subject.',
        'stat-ledger'           => 'One heading and one lead line. Items are three or four figures: the figure with its sign (heading) and a two to five word label (text). Use only figures the brief supplies.',
        'zigzag-steps'          => 'One heading and one lead line. Items are three to five steps: a two to five word heading, one or two short sentences, and one image subject.',
    ];

    private const PATTERN_SHAPES = [
        'rule-row'    => 'Items are ledger rows: a short name (heading) and its detail or value (text).',
        'spec-table'  => 'Items are label/value pairs: a short label (heading) and its value (text).',
        'tag-cluster' => 'Items are short categorical labels of one to three words (heading).',
    ];

    /** @return list<string> */
    public static function itemKeys(string $archetype, ?string $itemPattern): array
    {
        if (self::ledgers($archetype, $itemPattern)) {
            return self::PATTERN_ITEM_KEYS[$itemPattern];
        }
        return self::ITEM_KEYS[$archetype] ?? ['heading', 'text'];
    }

    /** Whether the pattern replaces the archetype's item shape. */
    public static function ledgers(string $archetype, ?string $itemPattern): bool
    {
        return $itemPattern !== null
            && isset(self::PATTERN_ITEM_KEYS[$itemPattern])
            && in_array($archetype, self::LEDGER_ARCHETYPES, true)
            && !($itemPattern === 'tag-cluster' && $archetype === 'equal-card-grid');
    }

    /** @return array{0:int,1:int} */
    public static function itemCounts(string $archetype, ?string $itemPattern): array
    {
        if (self::ledgers($archetype, $itemPattern)) {
            return [2, 12];
        }
        return self::ITEM_COUNTS[$archetype] ?? [0, 12];
    }

    public static function hasSectionImage(string $archetype): bool
    {
        return isset(self::SECTION_IMAGE[$archetype]);
    }

    /** The shape text the brief shows the model. */
    public static function shape(string $archetype, ?string $itemPattern, bool $hasAction): string
    {
        $lines = [self::SHAPES[$archetype] ?? 'One heading, one lead line, and short items.'];
        if (self::ledgers($archetype, $itemPattern)) {
            $lines[] = self::PATTERN_SHAPES[$itemPattern];
        }
        [$min, $max] = self::itemCounts($archetype, $itemPattern);
        $lines[] = $max === 0 ? 'Leave `items` empty.' : "Write {$min} to {$max} items.";
        if (isset(self::SECTION_IMAGE[$archetype])) {
            $lines[] = 'The section image is ' . self::SECTION_IMAGE[$archetype]
                . '. Fill `image_subject` and `image_context`, or leave both empty when the section reads better without a picture.';
        } else {
            $lines[] = 'The section has no section-level image; leave `image_subject` and `image_context` empty.';
        }
        $lines[] = $hasAction
            ? 'Fill `action_label` with the visitor-facing words for the planned primary action.'
            : 'Leave `action_label` empty: this section has no planned button.';
        return implode("\n", $lines);
    }

    /** @return array<string,mixed> */
    public static function schema(string $archetype, ?string $itemPattern): array
    {
        $string = ['type' => 'string'];
        $itemProperties = [];
        foreach (self::itemKeys($archetype, $itemPattern) as $key) {
            $itemProperties[$key] = $key === 'list' ? ['type' => 'array', 'items' => $string] : $string;
        }
        // Anthropic structured outputs reject minItems/maxItems on arrays and
        // an object with no properties; the brief states the counts, the
        // compiler slices to them, and an item-less archetype has no `items`.
        $properties = [
            'heading'          => $string,
            'heading_emphasis' => $string,
            'lead'             => $string,
            'paragraphs'       => ['type' => 'array', 'items' => $string],
        ];
        if ($itemProperties !== []) {
            $properties['items'] = [
                'type'  => 'array',
                'items' => [
                    'type'                 => 'object',
                    'properties'           => $itemProperties,
                    'required'             => array_keys($itemProperties),
                    'additionalProperties' => false,
                ],
            ];
        }
        $properties += [
            'image_subject'    => $string,
            'image_context'    => $string,
            'image_style'      => ['type' => 'string', 'enum' => self::IMAGE_STYLES],
            'action_label'     => $string,
            'link_label'       => $string,
            'link_href'        => $string,
        ];
        return [
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => array_keys($properties),
            'additionalProperties' => false,
        ];
    }
}
