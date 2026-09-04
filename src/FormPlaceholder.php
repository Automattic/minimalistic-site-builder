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
    /** The marker's name, with no spec behind it. */
    public const MARKER_NAME = 'JP_FORM';

    /** Prefix that opens a spec. */
    public const MARKER = self::MARKER_NAME . ':';

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

    /**
     * How many times the marker appears at all, placeholder or not.
     *
     * The name alone counts, without the colon a spec opens with: a paragraph
     * reading `JP_FORM` is machine text a visitor can read, and it is the
     * shape a marker takes when the model drops the spec it was supposed to
     * carry. Counting only well-formed prefixes would call that page clean.
     */
    public static function markerCount(string $markup): int
    {
        return substr_count($markup, self::MARKER_NAME);
    }

    /**
     * Drop the marker paragraphs no host will ever substitute.
     *
     * A marker only means something inside a placeholder block: that is the
     * class the host looks the block up by. Anywhere else the paragraph is
     * ordinary body copy that happens to read `JP_FORM`, and it ships to the
     * visitor as grey text. Removing it costs a form the section never had,
     * because nothing downstream could have built one from it.
     *
     * @return array{markup:string, removed:int}
     */
    public static function stripLooseMarkers(string $markup): array
    {
        $placeholders = [];
        foreach (self::find($markup) as $found) {
            $placeholders[$found['block']] = true;
        }

        $pattern = '/<!--\s*wp:paragraph\b[^>]*?-->\s*<p[^>]*>.*?<\/p>\s*<!--\s*\/wp:paragraph\s*-->/is';
        $removed = 0;
        $stripped = preg_replace_callback(
            $pattern,
            static function (array $match) use ($placeholders, &$removed): string {
                if (isset($placeholders[$match[0]]) || !str_contains($match[0], self::MARKER_NAME)) {
                    return $match[0];
                }
                ++$removed;
                return '';
            },
            $markup,
        );

        return [
            'markup' => $removed > 0 ? trim((string) $stripped) : $markup,
            'removed' => $removed,
        ];
    }

    /**
     * The fallback form's four visitor-facing strings, per language.
     *
     * Every other spec's labels are the model's own, written in the site's
     * language along with the rest of the page. This one is written here, so
     * without a mapping a Spanish site ships an English form. The mapping is
     * deliberately small and reviewed, like WritingDirection's: a language
     * that is not in it keeps English rather than guessing.
     *
     * @var array<string, array{name:string, email:string, message:string, submit:string}>
     */
    private const DEFAULT_LABELS = [
        'ca' => ['name' => 'Nom', 'email' => 'Correu electrònic', 'message' => 'Missatge', 'submit' => 'Envia el missatge'],
        'de' => ['name' => 'Name', 'email' => 'E-Mail', 'message' => 'Nachricht', 'submit' => 'Nachricht senden'],
        'es' => ['name' => 'Nombre', 'email' => 'Correo electrónico', 'message' => 'Mensaje', 'submit' => 'Enviar mensaje'],
        'fr' => ['name' => 'Nom', 'email' => 'E-mail', 'message' => 'Message', 'submit' => 'Envoyer le message'],
        'it' => ['name' => 'Nome', 'email' => 'Email', 'message' => 'Messaggio', 'submit' => 'Invia messaggio'],
        'ja' => ['name' => 'お名前', 'email' => 'メールアドレス', 'message' => 'メッセージ', 'submit' => '送信する'],
        'nl' => ['name' => 'Naam', 'email' => 'E-mail', 'message' => 'Bericht', 'submit' => 'Bericht versturen'],
        'pl' => ['name' => 'Imię', 'email' => 'E-mail', 'message' => 'Wiadomość', 'submit' => 'Wyślij wiadomość'],
        'pt' => ['name' => 'Nome', 'email' => 'E-mail', 'message' => 'Mensagem', 'submit' => 'Enviar mensagem'],
        'ru' => ['name' => 'Имя', 'email' => 'Эл. почта', 'message' => 'Сообщение', 'submit' => 'Отправить сообщение'],
        'sv' => ['name' => 'Namn', 'email' => 'E-post', 'message' => 'Meddelande', 'submit' => 'Skicka meddelande'],
        'tr' => ['name' => 'Ad', 'email' => 'E-posta', 'message' => 'Mesaj', 'submit' => 'Mesaj gönder'],
    ];

    /**
     * The plain language names SiteSpecStep also accepts, for the languages
     * above. The spec asks the model for a BCP-47 code, so a name is the
     * exception rather than the rule.
     *
     * @var array<string, string>
     */
    private const LANGUAGE_NAMES = [
        'catalan' => 'ca', 'dutch' => 'nl', 'french' => 'fr', 'german' => 'de',
        'italian' => 'it', 'japanese' => 'ja', 'polish' => 'pl', 'portuguese' => 'pt',
        'russian' => 'ru', 'spanish' => 'es', 'swedish' => 'sv', 'turkish' => 'tr',
    ];

    /**
     * A host-substitutable contact form. Used when a contact page's generated
     * sections omitted the placeholder the brief asked for (BIGR-858).
     *
     * @param string $language The site's language, as SiteSpecStep records it.
     */
    public static function defaultContactMarkup(string $language = ''): string
    {
        $labels = self::defaultLabels($language);
        $spec = self::MARKER . ' contact'
            . " | {$labels['name']}:name:required, {$labels['email']}:email:required,"
            . " {$labels['message']}:textarea:required"
            . " | {$labels['submit']}";
        return '<!-- wp:paragraph {"className":"' . self::CLASS_NAME . '"} -->' . "\n"
            . '<p class="' . self::CLASS_NAME . '">' . $spec . '</p>' . "\n"
            . '<!-- /wp:paragraph -->';
    }

    /**
     * The fallback strings for a language, English when it is not mapped.
     *
     * @return array{name:string, email:string, message:string, submit:string}
     */
    private static function defaultLabels(string $language): array
    {
        $english = ['name' => 'Name', 'email' => 'Email', 'message' => 'Message', 'submit' => 'Send message'];
        $normalized = strtolower(trim(str_replace('_', '-', $language)));
        if ($normalized === '') {
            return $english;
        }

        $code = self::LANGUAGE_NAMES[$normalized] ?? explode('-', $normalized, 2)[0];

        return self::DEFAULT_LABELS[$code] ?? $english;
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
        return trim(PlainText::fromMarkup($inner));
    }
}
