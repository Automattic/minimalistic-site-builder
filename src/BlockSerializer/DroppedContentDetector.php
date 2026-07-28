<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

/** Exact PHP port of the pinned CLI's occurrence-counted loss detector. */
final class DroppedContentDetector
{
    /** Prefix keeps numeric-string values from being coerced to integer array keys. */
    private const COUNT_KEY_PREFIX = "\0value:";

    /** @return list<DroppedValue> */
    public function detect(string $original, string $fixed): array
    {
        $dropped = [];
        foreach ($this->decreased($this->styleCounts($original), $this->styleCounts($fixed)) as [$value, $lost]) {
            $dropped[] = new DroppedValue('style', $value, $lost);
        }
        foreach ($this->decreased($this->classCounts($original), $this->classCounts($fixed)) as [$value, $lost]) {
            $dropped[] = new DroppedValue('class', $value, $lost);
        }
        return $dropped;
    }

    /** @return array<string,int> */
    private function styleCounts(string $html): array
    {
        $counts = [];
        preg_match_all('/\bstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $value = $match[1] !== '' || !isset($match[2]) ? $match[1] : $match[2];
            foreach (explode(';', $value) as $raw) {
                $declaration = trim($raw);
                if ($declaration === '') {
                    continue;
                }
                $colon = strpos($declaration, ':');
                $normalized = $colon === false
                    ? $declaration
                    : strtolower(trim(substr($declaration, 0, $colon))) . ':'
                        . trim(substr($declaration, $colon + 1));
                $this->increment($counts, $normalized);
            }
        }
        return $counts;
    }

    /** @return array<string,int> */
    private function classCounts(string $html): array
    {
        $counts = [];
        preg_match_all('/\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $value = $match[1] !== '' || !isset($match[2]) ? $match[1] : $match[2];
            foreach (preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $this->increment($counts, $token);
            }
        }
        return $counts;
    }

    /** @param array<string,int> $counts */
    private function increment(array &$counts, string $value): void
    {
        $key = self::COUNT_KEY_PREFIX . $value;
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    /**
     * @param array<string,int> $before
     * @param array<string,int> $after
     * @return list<array{0:string,1:int}>
     */
    private function decreased(array $before, array $after): array
    {
        $out = [];
        foreach ($before as $key => $count) {
            $lost = $count - ($after[$key] ?? 0);
            if ($lost > 0) {
                $out[] = [substr($key, strlen(self::COUNT_KEY_PREFIX)), $lost];
            }
        }
        return $out;
    }
}
