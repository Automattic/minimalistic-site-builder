<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

/** Exact PHP port of the pinned CLI's occurrence-counted loss detector. */
final class DroppedContentDetector
{
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
                $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
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
                $counts[$token] = ($counts[$token] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * @param array<string,int> $before
     * @param array<string,int> $after
     * @return list<array{0:string,1:int}>
     */
    private function decreased(array $before, array $after): array
    {
        $out = [];
        foreach ($before as $value => $count) {
            $lost = $count - ($after[$value] ?? 0);
            if ($lost > 0) {
                $out[] = [$value, $lost];
            }
        }
        return $out;
    }
}
