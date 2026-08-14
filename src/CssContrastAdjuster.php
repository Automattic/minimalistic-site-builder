<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Effectful boundary for applying pure CSS contrast findings. */
final class CssContrastAdjuster
{
    /**
     * @param list<array{
     *     selector:string,
     *     status:string,
     *     fg:?string,
     *     bg:?string,
     *     ratio:?float,
     *     suggested:?string
     * }> $findings
     */
    public static function apply(
        Project $project,
        string $path,
        string $css,
        string $markup,
        array $findings,
    ): string
    {
        $warnings = [];
        $plans = [];
        foreach ($findings as $finding) {
            if ($finding['status'] === 'pass') {
                continue;
            }
            if ($finding['status'] === 'unverified') {
                $warnings[] = sprintf(
                    'file=%s selector=%s authored=unresolved (%s) delivered=unchanged disposition=unverified reason=selector-or-color-context-unresolved',
                    self::safe($path),
                    self::safe($finding['selector']),
                    self::safe(CssContrastCheckEngine::authoredContext($css, $finding['selector'])),
                );
                continue;
            }
            if ($finding['status'] !== 'fail'
                || !is_string($finding['fg'])
                || !is_string($finding['suggested'])) {
                $warnings[] = sprintf(
                    'file=%s selector=%s authored=unresolved delivered=unchanged disposition=unverified reason=invalid-finding',
                    self::safe($path),
                    self::safe($finding['selector']),
                );
                continue;
            }
            $target = CssContrastCheckEngine::repairTarget($css, $markup, $finding);
            if ($target === null) {
                $warnings[] = sprintf(
                    'file=%s selector=%s authored=%s background=%s candidate=%s delivered=unchanged disposition=unverified reason=text-color-declaration-target-ambiguous',
                    self::safe($path),
                    self::safe($finding['selector']),
                    self::safe($finding['fg']),
                    self::safe($finding['bg'] ?? 'unresolved'),
                    self::safe($finding['suggested']),
                );
                continue;
            }
            $plans[] = ['finding' => $finding, 'target' => $target];
        }

        $byTarget = [];
        foreach ($plans as $plan) {
            $key = $plan['target']['value_start'] . ':' . $plan['target']['value_end'];
            $byTarget[$key][] = $plan;
        }
        $edits = [];
        foreach ($byTarget as $targetPlans) {
            $suggestions = array_values(array_unique(array_map(
                static fn (array $plan): string => $plan['finding']['suggested'],
                $targetPlans,
            )));
            if (count($suggestions) !== 1) {
                foreach ($targetPlans as $plan) {
                    $warnings[] = sprintf(
                        'file=%s selector=%s authored=%s background=%s candidate=%s delivered=unchanged disposition=unverified reason=text-color-declaration-target-ambiguous',
                        self::safe($path),
                        self::safe($plan['finding']['selector']),
                        self::safe($plan['finding']['fg']),
                        self::safe($plan['finding']['bg'] ?? 'unresolved'),
                        self::safe($plan['finding']['suggested']),
                    );
                }
                continue;
            }
            $first = $targetPlans[0];
            $edits[] = [
                'start' => $first['target']['value_start'],
                'end' => $first['target']['value_end'],
                'replacement' => $suggestions[0],
            ];
            foreach ($targetPlans as $plan) {
                $warnings[] = sprintf(
                    'file=%s selector=%s authored=%s delivered=%s disposition=adjusted ratio=%.4f threshold=%.1f',
                    self::safe($path),
                    self::safe($plan['finding']['selector']),
                    self::safe($plan['finding']['fg']),
                    self::safe($plan['finding']['suggested']),
                    $plan['finding']['ratio'] ?? 0.0,
                    ContrastMath::NORMAL_TEXT,
                );
            }
        }

        usort($edits, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        $adjusted = $css;
        foreach ($edits as $edit) {
            $adjusted = substr($adjusted, 0, $edit['start'])
                . $edit['replacement']
                . substr($adjusted, $edit['end']);
        }
        $project->addWarnings('css_contrast', $warnings);
        return $adjusted;
    }

    private static function safe(string $value): string
    {
        return preg_match('//u', $value) === 1 ? $value : mb_scrub($value, 'UTF-8');
    }
}
