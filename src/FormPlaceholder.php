<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The JP_FORM placeholder contract, in one place.
 *
 * A build that runs with form placeholders enabled leaves a paragraph block
 * whose text is a form spec, for the host to replace with its own real form
 * (see prompts/jetpack-form.md, which teaches the model this same grammar).
 *
 * Both sides of that contract read it from here: ThemeValidator to refuse a
 * spec no host could parse, and the host itself to build the form. Two copies
 * of a grammar drift, and the failure when they do is silent — a spec the
 * library accepts and the host cannot read ships as visible body text.
 */
final class FormPlaceholder
{
    /** Prefix that opens a spec. */
    public const MARKER = 'JP_FORM:';

    /** Class the host locates a placeholder block by. */
    public const CLASS_NAME = 'jetpack-form-placeholder';

    public const PURPOSES = ['contact', 'booking', 'rsvp', 'enquiry', 'newsletter-signup'];

    public const TYPES = [
        'name', 'text', 'email', 'tel', 'url', 'date', 'textarea',
        'checkbox', 'select', 'radio',
    ];

    /** Types whose choices are written as a parenthesised list after the type. */
    public const CHOICE_TYPES = ['select', 'radio'];

    /**
     * Every placeholder block in some markup, whole block and spec text.
     *
     * @return list<array{block:string, spec:string}>
     */
    public static function find(string $markup): array
    {
        $pattern = '/<!--\s*wp:paragraph\b[^>]*?-->\s*<p[^>]*class="[^"]*\b'
            . preg_quote(self::CLASS_NAME, '/')
            . '\b[^"]*"[^>]*>(.*?)<\/p>\s*<!--\s*\/wp:paragraph\s*-->/is';
        if (preg_match_all($pattern, $markup, $matches, PREG_SET_ORDER) < 1) {
            return [];
        }

        $found = [];
        foreach ($matches as $match) {
            $spec = self::text($match[1]);
            if (str_starts_with($spec, self::MARKER)) {
                $found[] = ['block' => $match[0], 'spec' => $spec];
            }
        }

        return $found;
    }

    /** How many times the marker appears at all, placeholder or not. */
    public static function markerCount(string $markup): int
    {
        return substr_count($markup, self::MARKER);
    }

    /**
     * A spec, decoded — or a sentence saying why it cannot be.
     *
     * @return array{purpose:string, submit:string, fields:list<array{label:string, type:string, required:bool, options:list<string>}>}|string
     */
    public static function parse(string $spec): array|string
    {
        $body = trim(substr(trim($spec), strlen(self::MARKER)));
        $parts = explode('|', $body);
        if (count($parts) !== 3) {
            return count($parts) . ' pipe-separated parts, expected 3';
        }

        [$purpose, $fieldText, $submit] = array_map('trim', $parts);
        if (!in_array($purpose, self::PURPOSES, true)) {
            return "unknown purpose '{$purpose}'";
        }
        if ($submit === '') {
            return 'empty submit label';
        }

        $raw = self::splitFields($fieldText);
        if ($raw === []) {
            return 'no fields';
        }

        $fields = [];
        foreach ($raw as $field) {
            $options = [];
            if (preg_match('/\(([^)]*)\)/', $field, $choice) === 1) {
                $options = array_values(array_filter(
                    array_map('trim', explode(',', $choice[1])),
                    static fn (string $o): bool => $o !== '',
                ));
            }
            $bits = array_map('trim', explode(':', preg_replace('/\([^)]*\)/', '', $field) ?? $field));
            if (count($bits) < 2 || count($bits) > 3) {
                return "field '{$field}' is not label:type[:required]";
            }
            if ($bits[0] === '') {
                return "field '{$field}' has no label";
            }
            if (!in_array($bits[1], self::TYPES, true)) {
                return "field '{$field}' has unknown type '{$bits[1]}'";
            }
            if (count($bits) === 3 && $bits[2] !== 'required') {
                return "field '{$field}' third part is '{$bits[2]}', expected 'required'";
            }
            if (in_array($bits[1], self::CHOICE_TYPES, true) && $options === []) {
                return "field '{$field}' is a {$bits[1]} with no choices";
            }

            $fields[] = [
                'label'    => $bits[0],
                'type'     => $bits[1],
                'required' => count($bits) === 3,
                'options'  => $options,
            ];
        }

        return ['purpose' => $purpose, 'submit' => $submit, 'fields' => $fields];
    }

    /**
     * Split a field list on the commas that separate fields.
     *
     * A select's choices are a comma-separated list of their own, inside
     * parentheses, so a plain explode(',') cuts fields in half. Counting
     * depth is the whole trick.
     *
     * @return list<string>
     */
    private static function splitFields(string $fields): array
    {
        $out = [];
        $current = '';
        $depth = 0;
        foreach (str_split($fields) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            }
            if ($char === ',' && $depth === 0) {
                $out[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $out[] = $current;

        return array_values(array_filter(
            array_map('trim', $out),
            static fn (string $f): bool => $f !== '',
        ));
    }

    /** The readable text of a placeholder paragraph's inner HTML. */
    private static function text(string $inner): string
    {
        return trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
