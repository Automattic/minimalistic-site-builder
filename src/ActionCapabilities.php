<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\Steps\PagePlanStep;

/** Check action promises against destinations that the site can deliver. */
final class ActionCapabilities
{
    private const CONTACT_INSTRUCTION = '/^(?:please\s+)?(?:(?:call|phone|email|message|contact|write(?:\s+to)?|reach\s+out\s+to)(?:\s+or\s+(?:call|write)(?:\s+to)?)?\s+us(?:\s+(?:for|to|about|with|at)\b|[.!?:,]|$)|(?:enquire|inquire)\b|send\s+us\b.*\b(?:message|enquiry|inquiry)\b)/iu';

    /** @param array<mixed> $spec @param array<mixed> $pages @return array<string,mixed> */
    public static function context(array $spec, array $pages): array
    {
        $context = PagePlanStep::primaryActionContext($spec, $pages);
        $context['primary_cta'] = trim((string) ($spec['primary_cta'] ?? ''));
        $context['cta_type'] = trim((string) ($spec['cta_type'] ?? ''));
        $context['destination_titles'] = [];
        foreach ($pages as $page) {
            $path = self::destinationKey((string) ($page['path'] ?? '/'));
            $context['destination_titles'][$path] = self::contentLabel((string) ($page['title'] ?? ''));
            foreach ((array) ($page['sections'] ?? []) as $section) {
                if (!is_array($section) || empty($section['slug'])) {
                    continue;
                }
                $context['destination_titles'][$path . '#' . $section['slug']] = self::contentLabel((string) ($section['title'] ?? ''));
            }
        }
        return $context;
    }

    private static function contentLabel(string $title): string
    {
        $label = trim(PlainText::fromMarkup($title));
        return mb_strlen($label) > 80 ? rtrim(mb_substr($label, 0, 79)) . '…' : $label;
    }

    /** @param array<mixed> $spec */
    public static function prompt(array $spec): string
    {
        $context = self::context($spec, []);
        return 'ACTION CAPABILITIES: Content links use destination titles. Transactions need a verified URL or host form. '
            . 'Enquiry labels and contact instructions need a real contact channel. Without one, omit promises to call, write, or answer. '
            . 'Omit actions without destinations. This overrides notes. URLs: '
            . json_encode(array_keys($context['contact_destinations']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed> $context */
    public static function transactionLabel(string $label, array $context): bool
    {
        $pattern = '/^(?:(?:start|begin|activate|try|get)\b.{0,30}\btrial|(?:make|place)\b.{0,20}\b(?:reservation|booking|order|purchase)|(?:schedule|arrange)\b.{0,20}\b(?:appointment|visit|call)|(?:contact|call|email|message|write to)\s+us|enquire|inquire|reserve|book|sign[ -]?up|register|buy|purchase|checkout|subscribe|download|request|send|order)\b/iu';
        return preg_match($pattern, $label) === 1
            || ($label !== '' && mb_strtolower($label) === mb_strtolower((string) ($context['primary_cta'] ?? ''))
                && preg_match('/\b(?:trial|signup|reservation|booking|purchase|subscription|download|request|order)\b/iu', (string) ($context['cta_type'] ?? '')) === 1);
    }

    /** Return a truthful label, or null when the action has no usable destination. */
    public static function label(string $label, string $destination, array $context, string $currentPath = '/'): ?string
    {
        $destination = trim($destination);
        if ($destination === '' || $destination === '#') {
            return null;
        }
        if (!self::transactionLabel($label, $context)) {
            return $label;
        }
        if (isset($context['contact_destinations'][$destination])) {
            return $label;
        }
        $key = self::destinationKey($destination, $currentPath);
        if (isset($context['form_destinations'][$key])) {
            return $label;
        }
        $title = trim((string) ($context['destination_titles'][$key] ?? ''));
        return $title !== '' && !self::transactionLabel($title, $context) ? $title : null;
    }

    /** Use one key for equivalent internal page destinations. */
    private static function destinationKey(string $destination, string $currentPath = '/'): string
    {
        if (str_starts_with($destination, '#')) {
            $destination = rtrim($currentPath, '/') . '/' . $destination;
        }
        if (!str_starts_with($destination, '/') || str_starts_with($destination, '//')) {
            return $destination;
        }
        $url = parse_url($destination);
        if (!is_array($url)) {
            return $destination;
        }
        return rtrim((string) ($url['path'] ?? '/'), '/') . '/'
            . (isset($url['fragment']) ? '#' . $url['fragment'] : '');
    }

    /** Remove unsupported contact instructions and correct a heading from its actual content link. */
    public static function repairContactCopy(string $markup, array $context, string $file, string $currentPath = '/'): array
    {
        if (!empty($context['contact_destinations']) || isset($context['form_destinations'][self::destinationKey($currentPath)])) {
            return ['markup' => $markup, 'warnings' => []];
        }
        $document = BlockMarkup::parse($markup);
        $changes = [];
        $warnings = [];
        foreach ($document->indices() as $index) {
            $name = $document->name($index);
            if (!in_array($name, ['paragraph', 'heading'], true) || !$document->isStructurallySafe($index)) {
                continue;
            }
            $inner = $document->innerHtml($index);
            $text = trim(PlainText::fromMarkup($inner));
            $instruction = preg_match(self::CONTACT_INSTRUCTION, $text) === 1;
            if (($name === 'paragraph' && !$instruction) || ($name === 'heading' && !self::transactionLabel($text, $context))) {
                continue;
            }
            $start = $document->openingOffset($index);
            $length = (int) $document->endOffset($index) - $start;
            $replacement = '';
            $delivered = 'removed';
            if ($name === 'paragraph') {
                $tag = MarkupScan::wrapperTag($inner, 0);
                $close = strripos($inner, '</p>');
                $copy = $tag === null || $close === false ? null : substr($inner, strlen($tag), $close - strlen($tag));
                if ($copy === null || str_contains($copy, '<')) {
                    $warnings[] = "file={$file}; block=blocks[{$index}]; authored=" . Warnings::value($text)
                        . '; delivered=unchanged; disposition=unsupported contact instruction has a complex text boundary';
                    continue;
                }
                $rest = $copy;
                do {
                    $rest = preg_match('/^.*?[.!?](?:\s+|$)/us', $rest, $sentence)
                        ? substr($rest, strlen($sentence[0])) : '';
                } while ($rest !== '' && preg_match(self::CONTACT_INSTRUCTION, trim(html_entity_decode($rest, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) === 1);
                if (trim($rest) !== '') {
                    $block = substr($markup, $start, $length);
                    $replacement = str_replace($inner, $tag . $rest . substr($inner, $close), $block);
                    $delivered = trim(html_entity_decode($rest, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
            }
            if ($name === 'heading') {
                $title = self::nearbyContentTitle($document, $index, $context, $currentPath);
                $tag = MarkupScan::wrapperTag($inner, 0);
                if ($title === null || $tag === null || !preg_match('/^\s*<h([1-6])\b/i', $tag, $match)) {
                    $warnings[] = "file={$file}; block=blocks[{$index}]; authored=" . Warnings::value($text)
                        . '; delivered=unchanged; disposition=unsupported contact heading has no unambiguous content destination';
                    continue;
                }
                $close = stripos($inner, '</h' . $match[1] . '>', strlen($tag));
                if ($close === false) {
                    continue;
                }
                $block = substr($markup, $start, $length);
                $heading = substr($inner, 0, $close);
                $replacement = str_replace($heading, $tag . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $block);
                $delivered = $title;
            }
            $changes[] = ['start' => $start, 'length' => $length, 'text' => $replacement];
            $warnings[] = "file={$file}; block=blocks[{$index}]; authored=" . Warnings::value($text)
                . '; delivered=' . Warnings::value($delivered) . '; disposition=corrected contact copy without a verified channel';
        }
        foreach (array_reverse($changes) as $change) {
            $markup = substr_replace($markup, $change['text'], $change['start'], $change['length']);
        }
        return ['markup' => $markup, 'warnings' => $warnings];
    }

    private static function nearbyContentTitle(BlockMarkup $document, int $index, array $context, string $currentPath): ?string
    {
        for ($parent = $document->parent($index); $parent !== null; $parent = $document->parent($parent)) {
            $content = $document->innerHtml($parent);
            preg_match_all('/<a\b/i', $content, $anchors, PREG_OFFSET_CAPTURE);
            $titles = [];
            foreach ($anchors[0] as [$_anchor, $offset]) {
                $tag = MarkupScan::wrapperTag($content, $offset);
                $href = html_entity_decode((string) (MarkupScan::tagAttribute($tag ?? '', 'href')[0] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $key = self::destinationKey(trim($href), $currentPath);
                $title = $context['destination_titles'][$key] ?? null;
                if (is_string($title) && $title !== '' && !self::transactionLabel($title, $context)) {
                    $titles[$title] = true;
                } else {
                    return null;
                }
            }
            if ($anchors[0] !== []) {
                return count($titles) === 1 ? (string) array_key_first($titles) : null;
            }
        }
        return null;
    }

    /**
     * Change only the affected action block. Preserve all other bytes.
     *
     * @return array{markup:string,warnings:list<string>}
     */
    public static function repairMarkup(string $markup, array $context, string $file, string $currentPath = '/', bool $deadOnly = false): array
    {
        $doc = BlockMarkup::parse($markup);
        $changes = [];
        $warnings = [];
        foreach ($doc->indices() as $index) {
            $name = $doc->name($index);
            $attrs = $doc->attrs($index) ?? [];
            if ($name !== 'button' && !($name === 'paragraph'
                && in_array('text-action', preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: [], true))) {
                continue;
            }
            if (!$doc->isStructurallySafe($index)) {
                $warnings[] = "file={$file}; block=blocks[{$index}]; authored=unsafe action block; delivered=unchanged; disposition=repair skipped";
                continue;
            }
            $start = $doc->openingOffset($index);
            $length = (int) $doc->endOffset($index) - $start;
            $block = substr($markup, $start, $length);
            $inner = $doc->innerHtml($index);
            if (preg_match('/<a\b/i', $inner, $match, PREG_OFFSET_CAPTURE) !== 1) {
                if ($name !== 'button') {
                    continue;
                }
                $label = trim(PlainText::fromMarkup($inner));
                $destination = '';
                $tag = null;
            } else {
                $anchorOffset = $match[0][1];
                $tag = MarkupScan::wrapperTag($inner, $anchorOffset);
                $destination = trim(html_entity_decode((string) (MarkupScan::tagAttribute($tag ?? '', 'href')[0] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $close = stripos($inner, '</a>', $anchorOffset + strlen($tag ?? ''));
                if ($tag === null || $close === false) {
                    $warnings[] = "file={$file}; block=blocks[{$index}]; authored=malformed action; delivered=unchanged; disposition=repair skipped";
                    continue;
                }
                $labelHtml = substr($inner, $anchorOffset + strlen($tag), $close - $anchorOffset - strlen($tag));
                $label = trim(PlainText::fromMarkup($labelHtml));
            }
            $delivered = $deadOnly && $destination !== '' && $destination !== '#'
                ? $label : self::label($label, $destination, $context, $currentPath);
            if ($delivered === $label) {
                continue;
            }
            $replacement = '';
            if ($delivered !== null && $tag !== null) {
                $replacement = str_replace($tag . $labelHtml . '</a>', $tag . htmlspecialchars($delivered, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</a>', $block);
            }
            $changes[] = ['start' => $start, 'length' => $length, 'text' => $replacement];
            $authored = json_encode(['label' => $label, 'destination' => $destination], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $value = $delivered === null ? 'removed' : json_encode(['label' => $delivered, 'destination' => $destination], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $warnings[] = "file={$file}; block=blocks[{$index}]; authored={$authored}; delivered={$value}; disposition=unsupported action corrected";
        }
        foreach (array_reverse($changes) as $change) {
            $markup = substr_replace($markup, $change['text'], $change['start'], $change['length']);
        }
        return ['markup' => $markup, 'warnings' => $warnings];
    }
}
