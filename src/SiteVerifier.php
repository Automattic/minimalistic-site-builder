<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Post-build checks against a booted Studio WordPress.
 *
 * Not a pipeline step: the pipeline is host-portable and wpcom/Linux CI
 * cannot boot a local Studio site. Findings warn; they never fail the build.
 *
 * @phpstan-type VerifierPayload array{pages:int, front_page:int|string, theme_errors:list<string>}
 */
final class SiteVerifier
{
    /**
     * @return list<string> finding strings, empty when the site is sound
     */
    public static function check(StudioCli $cli, string $siteDir): array
    {
        $php = <<<'PHP'
$pages = (int) (wp_count_posts('page')->publish ?? 0);
$front = get_option('page_on_front');
$theme = wp_get_theme();
$err = $theme->errors();
$theme_errors = $err instanceof WP_Error ? array_values($err->get_error_messages()) : [];
echo json_encode([
    'pages' => $pages,
    'front_page' => $front,
    'theme_errors' => $theme_errors,
], JSON_UNESCAPED_SLASHES);
PHP;
        try {
            $r = $cli->run(['wp', '--path', $siteDir, 'eval', $php]);
        } catch (\Throwable $e) {
            return ['site verifier failed: ' . $e->getMessage()];
        }
        if ($r['exitCode'] !== 0) {
            $reason = trim($r['stderr'] !== '' ? $r['stderr'] : $r['stdout']);
            return ['site verifier eval failed: ' . $reason];
        }
        $payload = json_decode(trim($r['stdout']), true);
        if (!is_array($payload)) {
            return ['site verifier returned output that is not JSON'];
        }
        $findings = [];
        $pages = (int) ($payload['pages'] ?? 0);
        if ($pages === 0) {
            $findings[] = 'no published pages';
        }
        // Live WP: unset front page is int 0; a set page id is a numeric string ("11").
        if ((int) ($payload['front_page'] ?? 0) === 0) {
            $findings[] = 'no front page set';
        }
        $errors = $payload['theme_errors'] ?? [];
        if (is_array($errors)) {
            foreach ($errors as $err) {
                if (is_string($err) && $err !== '') {
                    $findings[] = $err;
                }
            }
        }
        return $findings;
    }
}
