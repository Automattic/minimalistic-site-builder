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
 * deterministically what the model should have written: each recognizable
 * control becomes its Jetpack field block, labels, required flags and
 * select/radio options carry over, and the submit control becomes the
 * canonical core/button. The form's action is dropped on purpose — omitting
 * `to` makes Jetpack deliver submissions to the site's admin email instead
 * of an address the model invented. Every field block is emitted through
 * BlockMarkup::serializeComment(), so model-authored label text cannot smuggle
 * a `-->` into the comment delimiter.
 *
 * Bounded on purpose: only <form> markup inside a wp:html block is converted
 * (the shape generation emits); raw form controls anywhere else keep
 * tripping the validator's raw-form problem instead of being rewritten.
 * Forms that are not submissions-to-the-owner — search boxes, logins
 * (password fields) — and forms with no recognizable fields are left alone:
 * converting them would destroy behavior without producing anything that
 * works. Non-form content sharing the wp:html block survives in its own
 * wp:html block ahead of the converted form. The lossy edges — a dropped
 * file input, a non-mailto action rewritten to admin-email delivery — are
 * reported as warnings for warnings.json, per the repo's repair ladder.
 */
final class JetpackFormConverter
{
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

        $converted = [];
        $summaries = [];
        $warnings = [];
        $forms = iterator_to_array($dom->getElementsByTagName('form'));
        foreach ($forms as $form) {
            $result = self::convertForm($form);
            if ($result === null) {
                return null;
            }
            $converted[] = $result['markup'];
            $summaries[] = $result['summary'];
            foreach ($result['warnings'] as $warning) {
                $warnings[] = $warning;
            }
        }
        if ($converted === []) {
            return null;
        }

        // Content sharing the block with the form — an intro paragraph, a
        // hint below it — survives in its own wp:html block ahead of the
        // converted form instead of being silently destroyed with it.
        $remainder = self::remainderWithoutForms($dom, $forms);
        $prefix = $remainder === '' ? '' : "<!-- wp:html -->\n" . $remainder . "\n<!-- /wp:html -->\n";

        return [
            'markup'   => $prefix . implode("\n", $converted),
            'note'     => 'raw <form> in wp:html converted to jetpack/contact-form (' . implode('; ', $summaries) . ')',
            'warnings' => $warnings,
        ];
    }

    /** @param list<\DOMElement> $forms */
    private static function remainderWithoutForms(\DOMDocument $dom, array $forms): string
    {
        foreach ($forms as $form) {
            $form->parentNode?->removeChild($form);
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }
        $html = '';
        foreach ($body->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }
        $html = trim($html);
        // Empty wrappers the form left behind carry nothing worth a block.
        if (trim(strip_tags($html)) === '' && stripos($html, '<img') === false) {
            return '';
        }
        return $html;
    }

    /** @return array{markup:string, summary:string, warnings:list<string>}|null */
    private static function convertForm(\DOMElement $form): ?array
    {
        // A search box or a login is not a submission to the site owner;
        // rewriting it to a contact form destroys behavior instead of
        // repairing it.
        if (strtolower($form->getAttribute('role')) === 'search') {
            return null;
        }
        foreach ($form->getElementsByTagName('input') as $input) {
            if (in_array(strtolower($input->getAttribute('type')), ['password', 'search'], true)) {
                return null;
            }
        }

        $fields = [];
        $warnings = [];
        $seenRadioGroups = [];
        foreach ($form->getElementsByTagName('*') as $el) {
            $tag = strtolower($el->tagName);
            if ($tag === 'input') {
                $type = strtolower($el->getAttribute('type') ?: 'text');
                if ($type === 'file') {
                    $warnings[] = 'form conversion dropped a file upload input ("'
                        . self::label($form, $el) . '"): Jetpack Forms has no equivalent field';
                    continue;
                }
                if (in_array($type, ['hidden', 'submit', 'button', 'reset', 'image'], true)) {
                    continue;
                }
                if ($type === 'radio') {
                    $group = $el->getAttribute('name');
                    if (isset($seenRadioGroups[$group])) {
                        continue;
                    }
                    $seenRadioGroups[$group] = true;
                    $fields[] = self::radioField($form, $group);
                    continue;
                }
                $fields[] = self::inputField($form, $el, $type);
                continue;
            }
            if ($tag === 'textarea') {
                $fields[] = self::field('jetpack/field-textarea', self::label($form, $el), $el->hasAttribute('required'));
                continue;
            }
            if ($tag === 'select') {
                $fields[] = self::selectField($form, $el);
            }
        }
        $fields = array_values(array_filter($fields));
        if ($fields === []) {
            return null;
        }

        $markup = '<!-- wp:jetpack/contact-form --><div class="wp-block-jetpack-contact-form">'
            . implode('', $fields)
            . self::submitButton($form)
            . '</div><!-- /wp:jetpack/contact-form -->';

        $action = $form->getAttribute('action');
        $isMailto = str_starts_with($action, 'mailto:');
        if ($action !== '' && !$isMailto) {
            $warnings[] = "form conversion replaced the authored action \"{$action}\" with Jetpack's"
                . ' admin-email delivery';
        }
        $summary = count($fields) . ' field(s)' . ($isMailto ? ', mailto action dropped' : '');
        return ['markup' => $markup, 'summary' => $summary, 'warnings' => $warnings];
    }

    private static function inputField(\DOMElement $form, \DOMElement $el, string $type): string
    {
        $block = match ($type) {
            'email'    => 'jetpack/field-email',
            'tel'      => 'jetpack/field-telephone',
            'url'      => 'jetpack/field-url',
            'date'     => 'jetpack/field-date',
            'checkbox' => 'jetpack/field-checkbox',
            default    => null,
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

    private static function selectField(\DOMElement $form, \DOMElement $el): ?string
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
            $options[] = $text;
        }
        if ($options === []) {
            return null;
        }
        return self::field('jetpack/field-select', self::label($form, $el), $el->hasAttribute('required'), $options);
    }

    private static function radioField(\DOMElement $form, string $group): ?string
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
