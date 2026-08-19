<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Converts a model-authored raw HTML form into a working Jetpack Form.
 *
 * The section prompt forbids raw <form> markup and spells out the canonical
 * jetpack/contact-form shape, but a generation can still ship one inside a
 * wp:html block — typically wired to an invented mailto: address, which is
 * exactly the silently-dead form BIGR-657 exists to kill (observed on a live
 * production build with the prompt rule verifiably in the served prompt). A
 * prompt rule cannot be the last line of defense; this pass converts
 * deterministically what the model should have written.
 *
 * Document order is preserved: the block is split at each converted form, so
 * content before and after every form stays exactly where it was, and each
 * form converts independently — one unconvertible form leaves only itself
 * unchanged. Content INSIDE a form (instructions, privacy text, links)
 * becomes paragraph/heading blocks inside the jetpack/contact-form, in
 * place. Field constraints Jetpack markup cannot express (numeric limits,
 * patterns, pre-filled values, preselected choices) and dropped values
 * (hidden inputs, mailto recipients) are reported per form in warnings.json:
 * which form, the original value, and what visitors get instead.
 *
 * Refused, not converted: search forms (role="search", type="search", or
 * WordPress's `s` query field — converting one would email visitors' search
 * words to the owner), login forms (password fields), and forms with no
 * recognizable fields. Emission goes through BlockMarkup::serializeComment(),
 * so model-authored text cannot smuggle `-->` into a comment delimiter.
 */
final class JetpackFormConverter
{
    private const PLACEHOLDER = '@@JETPACK_FORM_%d@@';

    /** @return array{markup:string, notes:list<string>, warnings:list<string>} */
    public static function fix(string $markup): array
    {
        if (stripos($markup, '<form') === false) {
            return ['markup' => $markup, 'notes' => [], 'warnings' => []];
        }

        $blocks = BlockMarkup::parse($markup);
        $notes = [];
        $warnings = [];
        $replacements = [];
        foreach ($blocks->indices() as $index) {
            if ($blocks->name($index) !== 'html' || !$blocks->isStructurallySafe($index)) {
                continue;
            }
            $inner = $blocks->innerHtml($index);
            if (stripos($inner, '<form') === false) {
                continue;
            }
            $converted = self::convert($inner);
            if ($converted === null) {
                continue;
            }
            $replacements[] = [$blocks->openingOffset($index), $blocks->endOffset($index), $converted];
        }
        if ($replacements === []) {
            return ['markup' => $markup, 'notes' => [], 'warnings' => []];
        }

        // A wp:html nested inside another wp:html parses as two safe nodes
        // with overlapping ranges; splicing both would corrupt the document.
        // Keep the outermost replacement — its DOM already contains (and
        // converts) every nested form.
        $replacements = self::withoutNestedRanges($replacements);

        // Apply back to front so earlier offsets stay valid.
        usort($replacements, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($replacements as [$start, $end, $converted]) {
            $markup = substr($markup, 0, $start) . $converted['markup'] . substr($markup, $end);
            $notes[] = $converted['note'];
            foreach ($converted['warnings'] as $warning) {
                $warnings[] = $warning;
            }
        }
        return ['markup' => $markup, 'notes' => $notes, 'warnings' => $warnings];
    }

    /**
     * @param list<array{0:int,1:int,2:array}> $replacements
     * @return list<array{0:int,1:int,2:array}>
     */
    private static function withoutNestedRanges(array $replacements): array
    {
        return array_values(array_filter(
            $replacements,
            static function (array $candidate) use ($replacements): bool {
                foreach ($replacements as $other) {
                    if ($other !== $candidate && $candidate[0] >= $other[0] && $candidate[1] <= $other[1]) {
                        return false;
                    }
                }
                return true;
            }
        ));
    }

    /** @return array{markup:string, note:string, warnings:list<string>}|null */
    private static function convert(string $html): ?array
    {
        $dom = Html::loadUtf8Html($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($dom === null) {
            return null;
        }

        // Convert each form independently, replacing convertible ones with a
        // placeholder IN the DOM so document order survives serialization.
        // Refused forms stay in the DOM untouched.
        $converted = [];
        $summaries = [];
        $warnings = [];
        foreach (iterator_to_array($dom->getElementsByTagName('form')) as $i => $form) {
            $result = self::convertForm($form);
            if ($result === null) {
                continue;
            }
            $placeholder = sprintf(self::PLACEHOLDER, $i);
            $form->parentNode?->replaceChild($dom->createTextNode($placeholder), $form);
            $converted[$placeholder] = $result['markup'];
            $summaries[] = $result['summary'];
            foreach ($result['warnings'] as $warning) {
                $warnings[] = $warning;
            }
        }
        if ($converted === []) {
            return null;
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $serialized = '';
        foreach ($body?->childNodes ?? [] as $child) {
            $serialized .= $dom->saveHTML($child);
        }

        // Split at the placeholders: surviving HTML segments keep their own
        // wp:html blocks in their original positions around the converted
        // forms.
        $parts = preg_split('/(@@JETPACK_FORM_\d+@@)/', $serialized, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = [];
        foreach ($parts as $part) {
            if (isset($converted[$part])) {
                $out[] = $converted[$part];
                continue;
            }
            $part = trim($part);
            if ($part === '' || (trim(strip_tags($part)) === '' && stripos($part, '<img') === false)) {
                continue;
            }
            $out[] = "<!-- wp:html -->\n" . $part . "\n<!-- /wp:html -->";
        }

        return [
            'markup'   => implode("\n", $out),
            'note'     => 'raw <form> in wp:html converted to jetpack/contact-form (' . implode('; ', $summaries) . ')',
            'warnings' => $warnings,
        ];
    }

    /** @return array{markup:string, summary:string, warnings:list<string>}|null */
    private static function convertForm(\DOMElement $form): ?array
    {
        // A search box or a login is not a submission to the site owner;
        // rewriting it would destroy behavior (and email visitors' search
        // words to the owner) instead of repairing anything. WordPress's
        // search field is named `s`.
        if (strtolower($form->getAttribute('role')) === 'search') {
            return null;
        }
        foreach ($form->getElementsByTagName('input') as $input) {
            if (in_array(strtolower($input->getAttribute('type')), ['password', 'search'], true)
                || strtolower($input->getAttribute('name')) === 's'
            ) {
                return null;
            }
        }

        $formName = self::formName($form);
        $inner = self::innerBlocks($form, $form, $formName);
        if ($inner['fields'] === 0) {
            return null;
        }

        $warnings = $inner['warnings'];
        $action = $form->getAttribute('action');
        $isMailto = str_starts_with($action, 'mailto:');
        if ($isMailto) {
            $warnings[] = "form \"{$formName}\": recipient \"" . substr($action, strlen('mailto:'))
                . '" removed (model-invented address); visitors\' submissions go to the site\'s admin email instead';
        } elseif ($action !== '') {
            $warnings[] = "form \"{$formName}\": authored action \"{$action}\" replaced;"
                . " visitors' submissions go to the site's admin email instead";
        }

        $markup = '<!-- wp:jetpack/contact-form --><div class="wp-block-jetpack-contact-form">'
            . implode('', $inner['blocks'])
            . self::submitButton($form)
            . '</div><!-- /wp:jetpack/contact-form -->';

        $summary = $inner['fields'] . ' field(s)' . ($isMailto ? ', mailto action dropped' : '');
        return ['markup' => $markup, 'summary' => $summary, 'warnings' => $warnings];
    }

    /**
     * Walk the form's content in document order: controls become Jetpack
     * field blocks; instructions, headings, privacy text and links become
     * paragraph/heading blocks between them, so nothing the model wrote
     * inside the form is silently destroyed.
     *
     * @return array{blocks: list<string>, fields: int, warnings: list<string>}
     */
    private static function innerBlocks(\DOMElement $form, \DOMElement $scope, string $formName): array
    {
        $blocks = [];
        $fields = 0;
        $warnings = [];
        $seenRadioGroups = [];
        foreach ($scope->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);
            if ($tag === 'input' || $tag === 'textarea' || $tag === 'select') {
                $field = self::fieldFor($form, $node, $tag, $formName, $seenRadioGroups, $warnings);
                if ($field !== null) {
                    $blocks[] = $field;
                    $fields++;
                }
                continue;
            }
            if (self::containsControl($node)) {
                // Wrapping labels included: the control inside converts, and
                // its label text travels with the field via label().

                $nested = self::innerBlocks($form, $node, $formName);
                foreach ($nested['blocks'] as $block) {
                    $blocks[] = $block;
                }
                $fields += $nested['fields'];
                foreach ($nested['warnings'] as $warning) {
                    $warnings[] = $warning;
                }
                continue;
            }
            if ($tag === 'button' || $tag === 'label') {
                // The submit control is emitted separately; a control-less
                // label is field labeling, not content.
                continue;
            }
            $text = self::inlineHtml($node);
            if ($text === '') {
                continue;
            }
            if (preg_match('/^h([1-6])$/', $tag, $m) === 1) {
                $level = (int) $m[1];
                $blocks[] = "<!-- wp:heading {\"level\":{$level}} -->"
                    . "<h{$level} class=\"wp-block-heading\">{$text}</h{$level}><!-- /wp:heading -->";
                continue;
            }
            $blocks[] = "<!-- wp:paragraph --><p>{$text}</p><!-- /wp:paragraph -->";
        }
        return ['blocks' => $blocks, 'fields' => $fields, 'warnings' => $warnings];
    }

    /**
     * @param array<string,bool> $seenRadioGroups
     * @param list<string>       $warnings
     */
    private static function fieldFor(
        \DOMElement $form,
        \DOMElement $el,
        string $tag,
        string $formName,
        array &$seenRadioGroups,
        array &$warnings
    ): ?string {
        if ($tag === 'textarea') {
            if (trim($el->textContent) !== '') {
                $warnings[] = "form \"{$formName}\": pre-filled text of \"" . self::label($form, $el)
                    . '" not carried; the field starts empty';
            }
            return self::field('jetpack/field-textarea', self::label($form, $el), $el->hasAttribute('required'));
        }
        if ($tag === 'select') {
            return self::selectField($form, $el, $formName, $warnings);
        }

        $type = strtolower($el->getAttribute('type') ?: 'text');
        if ($type === 'file') {
            $warnings[] = "form \"{$formName}\": file upload input \"" . self::label($form, $el)
                . '" removed; Jetpack Forms has no equivalent field';
            return null;
        }
        if ($type === 'hidden') {
            $warnings[] = "form \"{$formName}\": hidden field \"" . $el->getAttribute('name')
                . '" (value "' . $el->getAttribute('value') . '") removed; Jetpack submissions carry no hidden values';
            return null;
        }
        if (in_array($type, ['submit', 'button', 'reset', 'image'], true)) {
            return null;
        }
        if ($type === 'radio') {
            $group = $el->getAttribute('name');
            if (isset($seenRadioGroups[$group])) {
                return null;
            }
            $seenRadioGroups[$group] = true;
            return self::radioField($form, $group, $formName, $warnings);
        }
        if ($type === 'checkbox') {
            if ($el->hasAttribute('checked')) {
                $warnings[] = "form \"{$formName}\": \"" . self::label($form, $el)
                    . '" was pre-checked; the converted checkbox starts unchecked';
            }
            return self::field('jetpack/field-checkbox', self::label($form, $el), $el->hasAttribute('required'));
        }

        foreach (['min', 'max', 'step', 'pattern', 'maxlength'] as $constraint) {
            if ($el->hasAttribute($constraint)) {
                $warnings[] = "form \"{$formName}\": {$constraint}=\"" . $el->getAttribute($constraint)
                    . '" on "' . self::label($form, $el) . '" not carried; the field accepts any value';
            }
        }
        if (trim($el->getAttribute('value')) !== '') {
            $warnings[] = "form \"{$formName}\": pre-filled value \"" . $el->getAttribute('value')
                . '" of "' . self::label($form, $el) . '" not carried; the field starts empty';
        }

        $block = match ($type) {
            'email'  => 'jetpack/field-email',
            'tel'    => 'jetpack/field-telephone',
            'url'    => 'jetpack/field-url',
            'date'   => 'jetpack/field-date',
            'number' => 'jetpack/field-number',
            default  => null,
        };
        if ($block === null) {
            // A generic text input: the name field when it says so, plain
            // text otherwise. Everything unrecognized degrades to field-text
            // rather than being dropped — a working generic field beats a
            // silently missing one.
            $isName = strtolower($el->getAttribute('autocomplete')) === 'name'
                || preg_match('/\b(name|nombre|nome|full[-_]?name)\b/i', $el->getAttribute('name') . ' ' . $el->getAttribute('id')) === 1;
            $block = $isName ? 'jetpack/field-name' : 'jetpack/field-text';
        }
        return self::field($block, self::label($form, $el), $el->hasAttribute('required'));
    }

    private static function containsControl(\DOMElement $el): bool
    {
        foreach (['input', 'textarea', 'select'] as $tag) {
            if ($el->getElementsByTagName($tag)->length > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * A short human identifier for warnings: the form's aria-label, its id,
     * or its (mailto-stripped) action — enough to find it on the page.
     */
    private static function formName(\DOMElement $form): string
    {
        foreach (['aria-label', 'id', 'name'] as $attr) {
            if (trim($form->getAttribute($attr)) !== '') {
                return trim($form->getAttribute($attr));
            }
        }
        $action = $form->getAttribute('action');
        return $action !== '' ? $action : 'unnamed form';
    }

    /** @param list<string> $warnings */
    private static function selectField(\DOMElement $form, \DOMElement $el, string $formName, array &$warnings): ?string
    {
        $options = [];
        foreach ($el->getElementsByTagName('option') as $option) {
            $text = self::collapse($option->textContent);
            // Only an explicitly empty value attribute marks the placeholder:
            // valid markup omits `value` on every real option (the text then
            // IS the submitted value), and treating those as placeholders
            // would eat the whole list.
            $isPlaceholder = $options === []
                && $option->hasAttribute('value')
                && $option->getAttribute('value') === '';
            if ($text === '' || $isPlaceholder) {
                continue;
            }
            if ($option->hasAttribute('selected')) {
                $warnings[] = "form \"{$formName}\": preselected choice \"{$text}\" of \""
                    . self::label($form, $el) . '" not carried; the converted select starts unselected';
            }
            $options[] = $text;
        }
        if ($options === []) {
            return null;
        }
        return self::field('jetpack/field-select', self::label($form, $el), $el->hasAttribute('required'), $options);
    }

    /** @param list<string> $warnings */
    private static function radioField(\DOMElement $form, string $group, string $formName, array &$warnings): ?string
    {
        $options = [];
        $required = false;
        foreach ($form->getElementsByTagName('input') as $input) {
            if (strtolower($input->getAttribute('type')) !== 'radio' || $input->getAttribute('name') !== $group) {
                continue;
            }
            $required = $required || $input->hasAttribute('required');
            $text = self::label($form, $input);
            if ($text !== '') {
                if ($input->hasAttribute('checked')) {
                    $warnings[] = "form \"{$formName}\": preselected choice \"{$text}\" of \"{$group}\""
                        . ' not carried; the converted options start unselected';
                }
                $options[] = $text;
            }
        }
        if ($options === []) {
            return null;
        }
        return self::field('jetpack/field-radio', ucfirst(str_replace(['-', '_'], ' ', $group)), $required, $options);
    }

    /** @param list<string>|null $options */
    private static function field(string $block, string $label, bool $required, ?array $options = null): string
    {
        $attrs = [];
        if ($label !== '') {
            $attrs['label'] = $label;
        }
        if ($required) {
            $attrs['required'] = true;
        }
        if ($options !== null) {
            $attrs['options'] = $options;
        }
        // serializeComment escapes the way WP serialize_block_attributes()
        // does — a model-authored label containing `-->` must not be able to
        // terminate the comment delimiter.
        return BlockMarkup::serializeComment($block, $attrs, true);
    }

    /**
     * The control's label: its for/id association first, then the wrapping
     * <label>Nombre <input></label> shape — in both cases the label's own
     * text, excluding child elements (required stars, "(optional)" hints).
     */
    private static function label(\DOMElement $form, \DOMElement $el): string
    {
        $id = $el->getAttribute('id');
        if ($id !== '') {
            foreach ($form->getElementsByTagName('label') as $label) {
                if ($label->getAttribute('for') === $id) {
                    return self::ownText($label);
                }
            }
        }
        for ($parent = $el->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
            if (strtolower($parent->tagName) === 'label') {
                return self::ownText($parent);
            }
            if (strtolower($parent->tagName) === 'form') {
                break;
            }
        }
        return '';
    }

    private static function ownText(\DOMElement $el): string
    {
        $text = '';
        foreach ($el->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= $child->textContent;
            }
        }
        return self::collapse($text);
    }

    private static function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * Element content reduced to paragraph-safe inline HTML: text plus
     * links and emphasis, everything else stripped to its text. Links keep
     * only safe destinations, so authored javascript: URLs cannot ride
     * along into the converted block.
     */
    private static function inlineHtml(\DOMElement $el): string
    {
        $html = '';
        foreach ($el->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $html .= htmlspecialchars($child->textContent, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            $inner = self::inlineHtml($child);
            if ($tag === 'a') {
                $href = trim($child->getAttribute('href'));
                $safe = preg_match('#^(https?:|mailto:|tel:|/|\#)#i', $href) === 1;
                $html .= $safe
                    ? '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') . '">' . $inner . '</a>'
                    : $inner;
                continue;
            }
            if (in_array($tag, ['strong', 'em', 'b', 'i'], true)) {
                $html .= "<{$tag}>{$inner}</{$tag}>";
                continue;
            }
            if ($tag === 'br') {
                $html .= '<br>';
                continue;
            }
            $html .= $inner;
        }
        return self::collapse($html);
    }

    private static function submitButton(\DOMElement $form): string
    {
        // First submit control in document order wins.
        $label = 'Send';
        foreach ($form->getElementsByTagName('*') as $el) {
            $tag = strtolower($el->tagName);
            if ($tag === 'button' && strtolower($el->getAttribute('type') ?: 'submit') === 'submit') {
                $text = self::collapse($el->textContent);
                if ($text !== '') {
                    $label = $text;
                }
                break;
            }
            if ($tag === 'input' && strtolower($el->getAttribute('type')) === 'submit') {
                $text = self::collapse($el->getAttribute('value'));
                if ($text !== '') {
                    $label = $text;
                }
                break;
            }
        }
        $escaped = htmlspecialchars($label, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        return '<!-- wp:button {"tagName":"button","type":"submit","className":"form-button-submit is-submit"} -->'
            . '<div class="wp-block-button form-button-submit is-submit">'
            . '<button type="submit" class="wp-block-button__link wp-element-button">' . $escaped . '</button>'
            . '</div><!-- /wp:button -->';
    }
}
