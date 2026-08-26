<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Deduplicates WordPress debug.log lines for warnings.json.
 *
 * A notice inside a template fires once per render; a twenty-page site
 * would otherwise flood warnings.json on a single defect. Grouping is
 * (file, line, message) with a count.
 */
final class DebugLogReader
{
    /**
     * @return list<string> one row per group: "<level> <message> — <file>:<line> (xN)"
     */
    public static function summarize(string $logContents, int $maxBytes = 262144): array
    {
        if ($maxBytes <= 0 || $logContents === '') {
            return [];
        }
        $slice = substr($logContents, 0, $maxBytes);
        $groups = [];
        $order = [];
        foreach (preg_split("/\r\n|\n|\r/", $slice) as $line) {
            if ($line === '') {
                continue;
            }
            if (!preg_match(
                '/PHP (Notice|Warning|Error|Deprecated|Parse error|Fatal error):\s*(.+?) in (.+) on line (\d+)\s*$/',
                $line,
                $m
            )) {
                continue;
            }
            $level = $m[1];
            $message = rtrim($m[2]);
            $file = $m[3];
            $lineNo = $m[4];
            $key = $file . "\0" . $lineNo . "\0" . $message;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'level'   => $level,
                    'message' => $message,
                    'file'    => $file,
                    'line'    => $lineNo,
                    'count'   => 0,
                ];
                $order[] = $key;
            }
            $groups[$key]['count']++;
        }
        $rows = [];
        foreach ($order as $key) {
            $g = $groups[$key];
            $rows[] = "{$g['level']} {$g['message']} — {$g['file']}:{$g['line']} (x{$g['count']})";
        }
        return $rows;
    }
}
