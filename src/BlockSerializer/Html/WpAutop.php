<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Html;

/** Dependency-free transliteration of the pinned @wordpress/autop routine. */
final class WpAutop
{
    public function format(string $text, bool $br = true): string
    {
        if (trim($text) === '') {
            return '';
        }
        $preTags = [];
        $text .= "\n";

        if (str_contains($text, '<pre')) {
            $parts = explode('</pre>', $text);
            $last = array_pop($parts);
            $rebuilt = '';
            foreach ($parts as $index => $part) {
                $start = strpos($part, '<pre');
                if ($start === false) {
                    $rebuilt .= $part;
                    continue;
                }
                $name = '<pre wp-pre-tag-' . $index . '></pre>';
                $preTags[$name] = substr($part, $start) . '</pre>';
                $rebuilt .= substr($part, 0, $start) . $name;
            }
            $text = $rebuilt . $last;
        }

        $text = preg_replace('/<br\s*\/?>\s*<br\s*\/?>/', "\n\n", $text) ?? $text;
        $blocks = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|math|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary)';
        $text = preg_replace('/(<' . $blocks . '[\s\/>])/', "\n\n$1", $text) ?? $text;
        $text = preg_replace('/(<\/' . $blocks . '>)/', "$1\n\n", $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace_callback(
            '/<!--[\s\S]*?(?:-->|$)|<[^>]*>?/',
            static fn (array $match): string => str_replace("\n", ' <!-- wpnl --> ', $match[0]),
            $text,
        ) ?? $text;

        if (str_contains($text, '<option')) {
            $text = preg_replace('/\s*<option/', '<option', $text) ?? $text;
            $text = preg_replace('/<\/option>\s*/', '</option>', $text) ?? $text;
        }
        if (str_contains($text, '</object>')) {
            $text = preg_replace('/(<object[^>]*>)\s*/', '$1', $text) ?? $text;
            $text = preg_replace('/\s*<\/object>/', '</object>', $text) ?? $text;
            $text = preg_replace('/\s*(<\/?(?:param|embed)[^>]*>)\s*/', '$1', $text) ?? $text;
        }
        if (str_contains($text, '<source') || str_contains($text, '<track')) {
            $text = preg_replace('/([<\[](?:audio|video)[^>\]]*[>\]])\s*/', '$1', $text) ?? $text;
            $text = preg_replace('/\s*([<\[]\/(?:audio|video)[>\]])/', '$1', $text) ?? $text;
            $text = preg_replace('/\s*(<(?:source|track)[^>]*>)\s*/', '$1', $text) ?? $text;
        }
        if (str_contains($text, '<figcaption')) {
            $text = preg_replace('/\s*(<figcaption[^>]*>)/', '$1', $text, 1) ?? $text;
            $text = preg_replace('/<\/figcaption>\s*/', '</figcaption>', $text, 1) ?? $text;
        }

        $text = preg_replace('/\n\n+/', "\n\n", $text) ?? $text;
        $pieces = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $text = '';
        foreach ($pieces as $piece) {
            $text .= '<p>' . preg_replace('/^\n*|\n*$/', '', $piece) . "</p>\n";
        }
        $text = preg_replace('/<p>\s*<\/p>/', '', $text) ?? $text;
        $text = preg_replace('/<p>([^<]+)<\/(div|address|form)>/', '<p>$1</p></$2>', $text) ?? $text;
        $text = preg_replace('/<p>\s*(<\/?' . $blocks . '[^>]*>)\s*<\/p>/', '$1', $text) ?? $text;
        $text = preg_replace('/<p>(<li.+?)<\/p>/', '$1', $text) ?? $text;
        $text = preg_replace('/<p><blockquote([^>]*)>/i', '<blockquote$1><p>', $text) ?? $text;
        $text = str_replace('</blockquote></p>', '</p></blockquote>', $text);
        $text = preg_replace('/<p>\s*(<\/?' . $blocks . '[^>]*>)/', '$1', $text) ?? $text;
        $text = preg_replace('/(<\/?' . $blocks . '[^>]*>)\s*<\/p>/', '$1', $text) ?? $text;

        if ($br) {
            $text = preg_replace_callback(
                '/<(script|style)[^>]*>[\s\S]*?<\/\1>/',
                static fn (array $match): string => str_replace("\n", '<WPPreserveNewline />', $match[0]),
                $text,
            ) ?? $text;
            $text = preg_replace('/<br\/?\s*>/', '<br />', $text) ?? $text;
            $text = preg_replace_callback(
                '/(<br \/>)?\s*\n/',
                static fn (array $match): string => ($match[1] ?? '') !== '' ? $match[0] : "<br />\n",
                $text,
            ) ?? $text;
            $text = str_replace('<WPPreserveNewline />', "\n", $text);
        }

        $text = preg_replace('/(<\/?' . $blocks . '[^>]*>)\s*<br \/>/', '$1', $text) ?? $text;
        $text = preg_replace('/<br \/>(\s*<\/?(?:p|li|div|dl|dd|dt|th|pre|td|ul|ol)[^>]*>)/', '$1', $text) ?? $text;
        $text = preg_replace('/\n<\/p>$/', '</p>', $text) ?? $text;
        foreach ($preTags as $name => $original) {
            $text = str_replace($name, $original, $text);
        }
        $text = preg_replace('/\s?<!-- wpnl -->\s?/', "\n", $text) ?? $text;
        return $text;
    }
}
