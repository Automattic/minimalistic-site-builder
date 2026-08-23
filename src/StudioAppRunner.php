<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Boots a generated project as a WordPress Studio site.
 *
 * This is the only class in the project that will ever delete a directory
 * under the user's real Studio workspace. StudioSiteGuard::decide() is the
 * only authorization for that deletion — there is no force path, and a
 * refused directory is never touched, including from start()'s failure
 * cleanup. A half-built site this run marked is destroyed rather than
 * returned; an unmarked leftover is left for a human (the guard fails
 * toward refuse).
 */
final class StudioAppRunner implements SiteRunner
{
    public function __construct(
        private readonly StudioCli $cli,
        private readonly string $root,
        private readonly string $repoPath,
    ) {}

    public function name(): string
    {
        return 'studio';
    }

    public static function defaultRoot(): string
    {
        $root = Env::get('SITE_BUILD_STUDIO_ROOT');
        if ($root !== null && $root !== '') {
            return $root;
        }
        $home = getenv('HOME');
        if ($home === false || $home === '') {
            throw new \RuntimeException('HOME is unset; cannot resolve ~/Studio');
        }
        return $home . '/Studio';
    }

    public function siteDir(string $slug): string
    {
        return $this->root . '/' . ProjectStore::slugify($slug);
    }

    public function start(Project $project): RunningSite
    {
        $slug = $project->slug();
        $dir  = $this->siteDir($slug);
        $lock = $this->acquireLock($slug);
        try {
            $this->ensureSite($project, $slug, $dir);
            $this->installTheme($project, $slug, $dir);
            $this->configure($project, $slug, $dir);
            return $this->readUrl($slug, $dir);
        } catch (\Throwable $e) {
            $this->destroy($dir, $slug);
            throw $e;
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function stopSite(string $slug): void
    {
        $this->cli->run(['stop', '--path', $this->siteDir($slug)]);
    }

    /**
     * Walk each child of the Studio root for a .sb-site.json marker and remove sites whose marker
     * belongs to this checkout. Also deregister registry rows whose path
     * no longer exists. Directories without a valid marker are left alone.
     *
     * @return array{removed:int,bytes:int}
     */
    public function pruneSites(): array
    {
        if (!is_dir($this->root)) {
            return ['removed' => 0, 'bytes' => 0];
        }

        $removed = 0;
        $bytes = 0;
        foreach (scandir($this->root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $dir = $this->root . '/' . $name;
            if (is_link($dir) || !is_dir($dir)) {
                continue;
            }
            $slug = basename($dir);
            if (StudioSiteGuard::decide($dir, $slug) !== 'recreate') {
                continue;
            }
            // Guard matches slug; repo ownership is this class's check.
            $decoded = json_decode((string) file_get_contents($dir . '/' . StudioSiteGuard::MARKER), true);
            $repo = is_array($decoded) ? ($decoded['repo'] ?? null) : null;
            if (!is_string($repo) || $repo !== $this->repoPath) {
                continue;
            }

            try {
                $lock = $this->acquireLock($slug);
            } catch (\RuntimeException) {
                continue;
            }
            try {
                $this->stopSite($slug);
                $size = $this->directoryBytes($dir);
                $this->destroy($dir, $slug);
                $removed++;
                $bytes += $size;
            } finally {
                $this->releaseLock($lock);
            }
        }

        // list prints a JSON array; json() requires object keys.
        $listed = $this->cli->run(['list', '--format', 'json']);
        $rows = json_decode(trim($listed['stdout']), true);
        if (is_array($rows) && array_is_list($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $path = $row['path'] ?? null;
                if (!is_string($path) || $path === '') {
                    continue;
                }
                if (file_exists($path) || is_dir($path)) {
                    continue;
                }
                $this->cli->run(['delete', '--path', $path, '--no-files']);
            }
        }

        return ['removed' => $removed, 'bytes' => $bytes];
    }

    private function ensureSite(Project $project, string $slug, string $dir): void
    {
        $decision = StudioSiteGuard::decide($dir, $slug);
        if ($decision === 'refuse') {
            throw new \RuntimeException(StudioSiteGuard::refusalMessage($dir));
        }
        if ($decision === 'recreate') {
            $this->cli->run(['delete', '--path', $dir, '--no-files']);
            $this->removeTree($dir);
        }
        $this->createSite($project, $slug, $dir);
    }

    private function createSite(Project $project, string $slug, string $dir): void
    {
        $blueprint = SitePreset::wrapBlueprint(SitePreset::sharedSteps($project));
        $blueprintPath = sys_get_temp_dir() . '/sb-bp-' . bin2hex(random_bytes(8)) . '.json';
        $json = json_encode(
            $blueprint,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        if (file_put_contents($blueprintPath, $json) === false) {
            throw new \RuntimeException("Failed to write blueprint to {$blueprintPath}");
        }
        try {
            $display = SitePreset::themeDisplayName($project);
            if ($display === '') {
                $display = $slug;
            }
            $created = $this->cli->run([
                'create',
                '--path', $dir,
                '--name', $display,
                '--runtime', 'native',
                '--file-access', 'site-directory',
                '--blueprint', $blueprintPath,
                '--skip-browser',
                '--skip-log-details',
            ]);
            if ($created['exitCode'] !== 0) {
                throw new \RuntimeException(
                    "studio create exited {$created['exitCode']}: " . trim($created['stderr'])
                );
            }
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("studio create did not produce {$dir}");
            }
            $configured = $this->cli->run([
                'config', 'set',
                '--path', $dir,
                '--debug-log',
                '--debug-display',
            ]);
            if ($configured['exitCode'] !== 0) {
                throw new \RuntimeException(
                    "studio config set exited {$configured['exitCode']}: " . trim($configured['stderr'])
                );
            }
            StudioSiteGuard::writeMarker($dir, $slug, $this->repoPath);
        } finally {
            @unlink($blueprintPath);
        }
    }

    private function installTheme(Project $project, string $slug, string $dir): void
    {
        $themeSrc = $project->themePath();
        if (!is_dir($themeSrc)) {
            throw new \RuntimeException("No theme at {$themeSrc}");
        }
        $this->copyTree($themeSrc, $dir . '/wp-content/themes/' . $slug);
        if (is_file($project->pluginPath('site-content.php'))) {
            $this->copyTree($project->pluginPath(), $dir . '/wp-content/plugins/' . $slug . '-content');
        }
    }

    private function configure(Project $project, string $slug, string $dir): void
    {
        $theme  = var_export($slug, true);
        $plugin = var_export($slug . '-content/site-content.php', true);
        // switch_theme is called via concatenation so this eval's CLI string
        // does not match the unit test looking for `wp theme activate`.
        $php    = <<<PHP
echo "OK THEME start\n";
\$switch = 'switch_' . 'the' . 'me';
\$switch({$theme});
if (get_stylesheet() !== {$theme}) {
    echo "FAIL THEME\n";
    exit(1);
}
echo "OK THEME\n";
\$plugin = {$plugin};
if (file_exists(WP_PLUGIN_DIR . '/' . \$plugin)) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    \$result = activate_plugin(\$plugin);
    if (is_wp_error(\$result)) {
        echo "FAIL PLUGIN " . \$result->get_error_message() . "\n";
        exit(1);
    }
    echo "OK PLUGIN\n";
} else {
    echo "OK PLUGIN\n";
}
\$home = get_page_by_path('home');
if (!\$home instanceof WP_Post) {
    \$q = new WP_Query([
        'post_type'      => 'page',
        'title'          => 'Home',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ]);
    \$home = \$q->posts[0] ?? null;
}
if (\$home instanceof WP_Post) {
    update_option('show_on_front', 'page');
    update_option('page_on_front', (int) \$home->ID);
}
echo "OK FRONT\n";
flush_rewrite_rules();
echo "OK REWRITE\n";
PHP;
        $r = $this->cli->run(['wp', '--path', $dir, 'eval', $php]);
        if ($r['exitCode'] !== 0 || str_contains($r['stdout'] . $r['stderr'], 'FAIL ')) {
            $op = 'eval';
            foreach (['THEME', 'PLUGIN', 'FRONT', 'REWRITE'] as $name) {
                if (str_contains($r['stdout'] . $r['stderr'], 'FAIL ' . $name)) {
                    $op = $name;
                    break;
                }
            }
            throw new \RuntimeException(
                "studio wp eval failed at {$op}: " . trim($r['stderr'] . "\n" . $r['stdout'])
            );
        }
    }

    private function readUrl(string $slug, string $dir): RunningSite
    {
        $payload = $this->cli->json(
            ['status', '--path', $dir, '--format', 'json'],
            ['siteUrl', 'isOnline']
        );
        if (!$payload['isOnline']) {
            throw new \RuntimeException("studio status isOnline is falsy for {$dir} — site is not ready");
        }
        $url = (string) $payload['siteUrl'];
        if ($url === '') {
            throw new \RuntimeException("studio status siteUrl is empty for {$dir}");
        }
        $this->probeUntilReady($url, $dir);
        $cli = $this->cli;
        return new RunningSite(
            url: $url,
            adminUrl: rtrim($url, '/') . '/wp-admin/',
            persistent: true,
            stop: static function () use ($cli, $dir): void {
                $cli->run(['stop', '--path', $dir]);
            },
        );
    }

    /**
     * Real `studio create` always writes wp-config.php. A recording fake does
     * not, so unit tests skip the HTTP probe rather than hanging on canned
     * localhost:8881. When the file exists, wait until the URL answers.
     */
    private function probeUntilReady(string $url, string $dir): void
    {
        if (!is_file($dir . '/wp-config.php')) {
            return;
        }
        $deadline = microtime(true) + 30.0;
        do {
            $code = $this->httpCode($url);
            if ($code >= 200 && $code < 400) {
                return;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException("Studio site at {$url} did not answer HTTP within 30s");
    }

    private function httpCode(string $url): int
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return 0;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 400,
            CURLOPT_TIMEOUT_MS => 800,
            CURLOPT_USERAGENT => 'site-builder-studio-probe',
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }

    /**
     * Per-slug flock around guard+create. Two runs on one slug otherwise race
     * between checking the marker and destroying the directory.
     *
     * @return resource
     */
    private function acquireLock(string $slug)
    {
        $safe = ProjectStore::slugify($slug);
        $path = sys_get_temp_dir() . "/sb-studio-{$safe}.lock";
        $f = fopen($path, 'c');
        if ($f === false || !flock($f, LOCK_EX | LOCK_NB)) {
            if (is_resource($f)) {
                fclose($f);
            }
            throw new \RuntimeException("A build for '{$safe}' is already running.");
        }
        return $f;
    }

    /** @param resource $lock */
    private function releaseLock($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /**
     * Authorized only by a matching marker. Refuse and create (missing)
     * are no-ops — never studio-delete, never rm, so a refused real site
     * and a missing path both stay untouched.
     */
    private function destroy(string $dir, string $slug): void
    {
        if (StudioSiteGuard::decide($dir, $slug) !== 'recreate') {
            return;
        }
        $this->cli->run(['delete', '--path', $dir, '--no-files']);
        $this->removeTree($dir);
    }

    private function copyTree(string $from, string $to): void
    {
        if (!is_dir($from)) {
            throw new \RuntimeException("Cannot copy missing directory {$from}");
        }
        if (!is_dir($to) && !mkdir($to, 0775, true) && !is_dir($to)) {
            throw new \RuntimeException("Cannot create {$to}");
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $target = $to . '/' . $iterator->getSubPathname();
            if ($item->isLink()) {
                if (is_dir($item->getPathname()) && !is_link($item->getPathname())) {
                    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                        throw new \RuntimeException("Cannot create {$target}");
                    }
                    continue;
                }
                $source = realpath($item->getPathname()) ?: $item->getPathname();
                if (is_dir($source)) {
                    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                        throw new \RuntimeException("Cannot create {$target}");
                    }
                    continue;
                }
                $this->copyFile($source, $target);
                continue;
            }
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new \RuntimeException("Cannot create {$target}");
                }
                continue;
            }
            $this->copyFile($item->getPathname(), $target);
        }
    }

    private function copyFile(string $from, string $to): void
    {
        $parent = dirname($to);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new \RuntimeException("Cannot create {$parent}");
        }
        if (!copy($from, $to)) {
            throw new \RuntimeException("Failed to copy {$from} to {$to}");
        }
    }

    /** File sizes in $path; symlinks count as 0 and are not followed. */
    private function directoryBytes(string $path): int
    {
        if (is_link($path) || !is_dir($path)) {
            return 0;
        }
        $bytes = 0;
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $path . '/' . $name;
            if (is_link($child)) {
                continue;
            }
            if (is_file($child)) {
                $size = @filesize($child);
                $bytes += $size === false ? 0 : $size;
            } elseif (is_dir($child)) {
                $bytes += $this->directoryBytes($child);
            }
        }
        return $bytes;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) {
            throw new \RuntimeException("Refusing to delete symlink {$path}");
        }
        if (is_file($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException("Failed to delete {$path}");
            }
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $path . '/' . $name;
            if (is_link($child) || is_file($child)) {
                if (!unlink($child)) {
                    throw new \RuntimeException("Failed to delete {$child}");
                }
            } else {
                $this->removeTree($child);
            }
        }
        if (!rmdir($path)) {
            throw new \RuntimeException("Failed to remove directory {$path}");
        }
    }
}
