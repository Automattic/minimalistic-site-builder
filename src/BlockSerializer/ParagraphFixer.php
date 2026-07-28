<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

/** PHP port of lib/paragraphFixer.js, restricted to wp:paragraph bodies. */
final class ParagraphFixer
{
    public function fix(string $html): ParagraphFixResult
    {
        if (!str_contains($html, '<!-- wp:paragraph')) {
            return new ParagraphFixResult($html, 0);
        }

        $total = 0;
        $paragraphOrdinal = -1;
        $repairedParagraphOrdinals = [];
        $fixed = preg_replace_callback(
            '/(<!-- wp:paragraph[^>]*-->)([\s\S]*?)(<!-- \/wp:paragraph -->)/',
            function (array $block) use (
                &$total,
                &$paragraphOrdinal,
                &$repairedParagraphOrdinals,
            ): string {
                $paragraphOrdinal++;
                $blockFixCount = 0;
                $content = $block[2];
                do {
                    $before = $content;
                    $content = preg_replace_callback(
                        '/<p((?:\s+(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*)?)>(\s*)'
                            . '<p((?:\s+(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*)?)>'
                            . '([\s\S]*?)<\/p>(\s*)<\/p>/i',
                        function (array $nested) use (&$total, &$blockFixCount): string {
                            $total++;
                            $blockFixCount++;
                            return '<p' . $this->serializeAttributes($this->mergeAttributes(
                                $this->parseAttributes($nested[1] ?? ''),
                                $this->parseAttributes($nested[3] ?? ''),
                            )) . '>' . $nested[4] . '</p>';
                        },
                        $content,
                    ) ?? $content;
                } while ($content !== $before);
                if ($blockFixCount > 0) {
                    $repairedParagraphOrdinals[] = $paragraphOrdinal;
                }
                return $block[1] . $content . $block[3];
            },
            $html,
        );

        return new ParagraphFixResult($fixed ?? $html, $total, $repairedParagraphOrdinals);
    }

    /** @return array<string,string> */
    private function parseAttributes(string $source): array
    {
        $attrs = [];
        preg_match_all('/(\S+)="([^"]*)"/', $source, $double, PREG_SET_ORDER);
        foreach ($double as $match) {
            $attrs[$match[1]] = $match[2];
        }
        preg_match_all("/(\\S+)='([^']*)'/", $source, $single, PREG_SET_ORDER);
        foreach ($single as $match) {
            if (!array_key_exists($match[1], $attrs)) {
                $attrs[$match[1]] = $match[2];
            }
        }
        return $attrs;
    }

    /** @param array<string,string> $outer @param array<string,string> $inner @return array<string,string> */
    private function mergeAttributes(array $outer, array $inner): array
    {
        $merged = $outer;
        foreach ($inner as $key => $value) {
            if ($key === 'class') {
                $tokens = [];
                foreach (preg_split('/\s+/', ($outer['class'] ?? '') . ' ' . $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                    $tokens[$token] = true;
                }
                $merged[$key] = implode(' ', array_keys($tokens));
            } elseif ($key === 'style') {
                $styles = [];
                foreach (array_merge(explode(';', $outer['style'] ?? ''), explode(';', $value)) as $style) {
                    $colon = strpos($style, ':');
                    if ($colon !== false && $colon > 0) {
                        $styles[trim(substr($style, 0, $colon))] = trim(substr($style, $colon + 1));
                    }
                }
                $pairs = [];
                foreach ($styles as $property => $styleValue) {
                    $pairs[] = $property . ':' . $styleValue;
                }
                $merged[$key] = implode(';', $pairs);
            } elseif (!array_key_exists($key, $outer)) {
                $merged[$key] = $value;
            }
        }
        return $merged;
    }

    /** @param array<string,string> $attrs */
    private function serializeAttributes(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $key => $value) {
            $parts[] = $key . '="' . $value . '"';
        }
        return $parts === [] ? '' : ' ' . implode(' ', $parts);
    }
}
