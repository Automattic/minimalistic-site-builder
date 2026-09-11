<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\Steps\PagePlanStep;

/** Check action promises against destinations that the site can deliver. */
final class ActionCapabilities
{
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
            . 'Omit actions without destinations. This overrides notes. URLs: '
            . json_encode(array_keys($context['contact_destinations']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed> $context */
    public static function transactionLabel(string $label, array $context): bool
    {
        $pattern = '/^(?:(?:start|begin|activate|try|get)\b.{0,30}\btrial|(?:make|place)\b.{0,20}\b(?:reservation|booking|order|purchase)|(?:schedule|arrange)\b.{0,20}\b(?:appointment|visit|call)|reserve|book|sign[ -]?up|register|buy|purchase|checkout|subscribe|download|request|send|order)\b/iu';
        return preg_match($pattern, $label) === 1
            || ($label !== '' && mb_strtolower($label) === mb_strtolower((string) ($context['primary_cta'] ?? ''))
                && preg_match('/\b(?:trial|signup|reservation|booking|purchase|subscription|download|request|order)\b/iu', (string) ($context['cta_type'] ?? '')) === 1);
    }

    /** Return a truthful label, or null when the action has no usable destination. */
    public static function label(string $label, string $destination, array $context, string $currentPath = '/'): ?string
    {
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
                $destination = html_entity_decode((string) (MarkupScan::tagAttribute($tag ?? '', 'href')[0] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
