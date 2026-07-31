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
    public static function apply(Project $project, string $path, string $css, array $findings): string
    {
        $adjusted = $css;
        $warnings = [];
        foreach ($findings as $finding) {
            if ($finding['status'] === 'pass') {
                continue;
            }
            if ($finding['status'] === 'unverified') {
                $warnings[] = sprintf(
                    'file=%s selector=%s authored=unresolved (%s) delivered=unchanged disposition=unverified reason=selector-or-color-context-unresolved',
                    $path,
                    $finding['selector'],
                    CssContrastCheckEngine::authoredContext($css, $finding['selector']),
                );
                continue;
            }
            if ($finding['status'] !== 'fail'
                || !is_string($finding['fg'])
                || !is_string($finding['suggested'])) {
                $warnings[] = sprintf(
                    'file=%s selector=%s authored=unresolved delivered=unchanged disposition=unverified reason=invalid-finding',
                    $path,
                    $finding['selector'],
                );
                continue;
            }

            $result = CssContrastCheckEngine::adjustOne(
                $adjusted,
                $finding['selector'],
                $finding['fg'],
                $finding['suggested'],
            );
            $adjusted = $result['css'];
            if (!$result['replaced']) {
                $warnings[] = sprintf(
                    'file=%s selector=%s authored=%s delivered=unchanged disposition=unverified reason=text-color-declaration-not-safely-rewritable',
                    $path,
                    $finding['selector'],
                    $finding['fg'],
                );
                continue;
            }
            $warnings[] = sprintf(
                'file=%s selector=%s authored=%s delivered=%s disposition=adjusted ratio=%.4f threshold=%.1f',
                $path,
                $finding['selector'],
                $finding['fg'],
                $finding['suggested'],
                $finding['ratio'] ?? 0.0,
                ContrastMath::NORMAL_TEXT,
            );
        }
        $project->addWarnings('css_contrast', $warnings);
        return $adjusted;
    }
}
