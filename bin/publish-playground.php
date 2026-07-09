<?php
declare(strict_types=1);

use Automattic\SiteBuild\PlaygroundArtifact;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
/**
 * Package and publish a built project as a shareable WordPress Playground link.
 *
 *   php bin/publish-playground.php <slug> [--repo=OWNER/REPO] [--branch=playground-artifacts] [--name=<file.zip>] [--out=<path>] [--dry-run] [--clobber] [--open]
 *   php bin/publish-playground.php --list [--repo=OWNER/REPO] [--branch=playground-artifacts]
 *
 * The uploaded ZIP is a Playground Blueprint bundle:
 *   blueprint.json
 *   project.zip  (contains project/<slug>/..., including logs)
 *
 * GitHub Release assets are not used for the share URL because browsers cannot
 * fetch them from playground.wordpress.net without CORS failures. The command
 * publishes ZIPs to a dedicated artifact branch and uses raw.githubusercontent.com
 * URLs, which are browser-fetchable by Playground.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$slug = null;
$repo = null;
$branch = PlaygroundArtifact::DEFAULT_ARTIFACT_BRANCH;
$assetName = null;
$out = null;
$dryRun = false;
$list = false;
$open = false;
$clobber = false;

foreach (array_slice($argv, 1) as $a) {
    if ($a === '--help' || $a === '-h') {
        usage(0);
    } elseif ($a === '--dry-run') {
        $dryRun = true;
    } elseif ($a === '--list') {
        $list = true;
    } elseif ($a === '--open') {
        $open = true;
    } elseif ($a === '--clobber') {
        $clobber = true;
    } elseif (str_starts_with($a, '--repo=')) {
        $repo = substr($a, 7);
    } elseif (str_starts_with($a, '--branch=')) {
        $branch = substr($a, 9);
    } elseif (str_starts_with($a, '--name=')) {
        $assetName = substr($a, 7);
    } elseif (str_starts_with($a, '--out=')) {
        $out = substr($a, 6);
    } elseif ($slug === null && !str_starts_with($a, '--')) {
        $slug = $a;
    } else {
        fwrite(STDERR, "Unknown argument: {$a}\n");
        usage(1);
    }
}

try {
    assert_branch_name($branch);

    if ($list) {
        list_assets(resolve_repo($repo), $branch);
        exit(0);
    }

    if ($slug === null) {
        usage(1);
    }

    $store = new ProjectStore(repo_path('projects'));
    $project = $store->open(ProjectStore::slugify($slug));
    $assetName ??= PlaygroundArtifact::defaultAssetName($project);

    echo "Packaging '{$project->slug()}' for WordPress Playground\n";
    $bundle = PlaygroundArtifact::build($project, $assetName, $out);
    $size = PlaygroundArtifact::formatBytes((int) filesize($bundle));
    echo "  bundle: {$bundle} ({$size})\n";
    echo "  includes: blueprint.json, project.zip (project/{$project->slug()}/...)\n";

    if ($dryRun) {
        echo "\nDry run: not uploading to GitHub.\n";
        exit(0);
    }

    $repo = resolve_repo($repo);
    publish_to_branch($repo, $branch, $bundle, $assetName, $project, $clobber);

    $artifactUrl = PlaygroundArtifact::artifactUrl($repo, $branch, $assetName);
    $playgroundUrl = PlaygroundArtifact::playgroundUrl($artifactUrl);

    echo "\nPublished:\n";
    echo "  artifact:   {$artifactUrl}\n";
    echo "  playground: {$playgroundUrl}\n";
    echo "\nList uploaded artifacts:\n";
    echo "  php bin/publish-playground.php --list --repo={$repo} --branch={$branch}\n";

    if ($open) {
        open_url($playgroundUrl);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}

function usage(int $exit): never
{
    $branch = PlaygroundArtifact::DEFAULT_ARTIFACT_BRANCH;
    $themes = glob(repo_path('projects/*/theme/style.css')) ?: [];
    fwrite($exit === 0 ? STDOUT : STDERR, "Usage:\n");
    fwrite($exit === 0 ? STDOUT : STDERR, "  php bin/publish-playground.php <slug> [--repo=OWNER/REPO] [--branch={$branch}] [--name=<file.zip>] [--out=<path>] [--dry-run] [--clobber] [--open]\n");
    fwrite($exit === 0 ? STDOUT : STDERR, "  php bin/publish-playground.php --list [--repo=OWNER/REPO] [--branch={$branch}]\n");
    if ($themes !== []) {
        fwrite($exit === 0 ? STDOUT : STDERR, "\nAvailable projects:\n");
        foreach ($themes as $f) {
            fwrite($exit === 0 ? STDOUT : STDERR, '  - ' . basename(dirname(dirname($f))) . "\n");
        }
    }
    exit($exit);
}

function ensure_gh(): void
{
    if (trim((string) shell_exec('command -v gh 2>/dev/null')) === '') {
        throw new RuntimeException('gh is required to resolve GitHub repository metadata.');
    }
}

function ensure_git(): void
{
    if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
        throw new RuntimeException('git is required to publish Playground artifacts.');
    }
}

function resolve_repo(?string $repo): string
{
    ensure_gh();
    if ($repo !== null && trim($repo) !== '') {
        return trim($repo);
    }

    $result = run_command('gh repo view --json nameWithOwner --jq ' . escapeshellarg('.nameWithOwner'));
    if ($result['code'] !== 0 || trim($result['output']) === '') {
        throw new RuntimeException("Could not resolve GitHub repo from the current checkout. Pass --repo=OWNER/REPO.\n" . $result['output']);
    }
    return trim($result['output']);
}

function remote_url_for_repo(string $repo): string
{
    $result = run_command('gh repo view ' . escapeshellarg($repo) . ' --json sshUrl --jq ' . escapeshellarg('.sshUrl'));
    if ($result['code'] === 0 && trim($result['output']) !== '') {
        return trim($result['output']);
    }

    $fallback = run_command('gh repo view ' . escapeshellarg($repo) . ' --json url --jq ' . escapeshellarg('.url'));
    if ($fallback['code'] !== 0 || trim($fallback['output']) === '') {
        throw new RuntimeException("Could not resolve a push URL for {$repo}.\n" . $result['output'] . "\n" . $fallback['output']);
    }
    return trim($fallback['output']);
}

function assert_branch_name(string $branch): void
{
    if ($branch === '' || str_contains($branch, '/') || str_contains($branch, '..') || str_starts_with($branch, '-') || preg_match('/[^A-Za-z0-9._-]/', $branch)) {
        throw new RuntimeException('--branch must be a simple branch name using letters, numbers, dots, underscores, or hyphens.');
    }
}

function publish_to_branch(string $repo, string $branch, string $bundle, string $assetName, Project $project, bool $clobber): void
{
    ensure_git();
    ensure_gh();

    $remote = remote_url_for_repo($repo);
    $tmp = sys_get_temp_dir() . '/builder-playground-publish-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir_or_fail($tmp);

    try {
        run_or_fail('git init -q', $tmp);
        run_or_fail('git remote add origin ' . escapeshellarg($remote), $tmp);

        $head = run_command('git ls-remote --exit-code --heads origin ' . escapeshellarg('refs/heads/' . $branch), $tmp);
        $branchExists = $head['code'] === 0;
        if ($branchExists) {
            run_or_fail('git config core.sparseCheckout true', $tmp);
            file_put_contents($tmp . '/.git/info/sparse-checkout', "README.md\nindex.json\n{$assetName}\n");
            run_or_fail('git fetch -q --depth=1 --filter=blob:none origin ' . escapeshellarg('refs/heads/' . $branch), $tmp);
            run_or_fail('git checkout -q -B ' . escapeshellarg($branch) . ' FETCH_HEAD', $tmp);
        } else {
            run_or_fail('git checkout -q --orphan ' . escapeshellarg($branch), $tmp);
        }

        $dest = $tmp . '/' . $assetName;
        if ($branchExists) {
            $existingAsset = run_command('git ls-tree -r --name-only HEAD -- ' . escapeshellarg($assetName), $tmp);
            if ($existingAsset['code'] === 0 && trim($existingAsset['output']) !== '' && !$clobber) {
                throw new RuntimeException("Artifact {$assetName} already exists on {$branch}. Use --clobber or choose --name=...");
            }
        }
        if (!copy($bundle, $dest)) {
            throw new RuntimeException("Could not copy {$bundle} to artifact branch workspace.");
        }

        $artifactUrl = PlaygroundArtifact::artifactUrl($repo, $branch, $assetName);
        $playgroundUrl = PlaygroundArtifact::playgroundUrl($artifactUrl);
        update_index($tmp . '/index.json', [
            'project'        => $project->slug(),
            'slug'           => $project->slug(),
            'asset'          => $assetName,
            'artifact_url'   => $artifactUrl,
            'playground_url' => $playgroundUrl,
            'size_bytes'     => filesize($bundle) ?: 0,
            'created_at'     => gmdate('c'),
        ]);
        write_artifact_readme($tmp . '/README.md', read_index($tmp . '/index.json'));

        run_or_fail('git add README.md index.json ' . escapeshellarg($assetName), $tmp);
        $status = run_command('git status --porcelain', $tmp);
        if (trim($status['output']) === '') {
            echo "No artifact branch changes to publish.\n";
            return;
        }

        echo "Publishing artifact to {$repo}@{$branch}\n";
        run_or_fail(
            'git -c user.name=' . escapeshellarg('builder4')
            . ' -c user.email=' . escapeshellarg('builder4@users.noreply.github.com')
            . ' commit -q -m ' . escapeshellarg('Add Playground artifact ' . $assetName),
            $tmp
        );
        run_or_fail('git push -q origin HEAD:' . escapeshellarg('refs/heads/' . $branch), $tmp);
    } finally {
        run_command('rm -rf ' . escapeshellarg($tmp));
    }
}

/** @param array<string,mixed> $entry */
function update_index(string $path, array $entry): void
{
    $index = PlaygroundArtifact::updateIndex(read_index($path), $entry);

    $json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException("Could not write artifact index: {$path}");
    }
}

/** @return array<int,array<string,mixed>> */
function read_index(string $path): array
{
    return is_file($path)
        ? PlaygroundArtifact::parseIndex((string) file_get_contents($path))
        : [];
}

/** @param array<int,array<string,mixed>> $index */
function write_artifact_readme(string $path, array $index): void
{
    if (file_put_contents($path, PlaygroundArtifact::renderArtifactReadme($index)) === false) {
        throw new RuntimeException("Could not write artifact README: {$path}");
    }
}

function list_assets(string $repo, string $branch): void
{
    $path = 'repos/' . $repo . '/contents/index.json?ref=' . rawurlencode($branch);
    $view = run_command('gh api ' . escapeshellarg($path) . ' --jq ' . escapeshellarg('.content'));

    echo "Artifacts in {$repo} branch {$branch}:\n";
    if ($view['code'] !== 0 || trim($view['output']) === '') {
        echo "  (none)\n";
        return;
    }

    $json = base64_decode(preg_replace('/\s+/', '', $view['output']) ?? '', true);
    $assets = $json === false ? [] : PlaygroundArtifact::parseIndex($json);
    if ($assets === []) {
        echo "  (none)\n";
        return;
    }

    foreach ($assets as $asset) {
        $name = (string) ($asset['asset'] ?? '');
        if ($name === '') {
            continue;
        }
        $size = PlaygroundArtifact::formatBytes((int) ($asset['size_bytes'] ?? 0));
        $created = (string) ($asset['created_at'] ?? '');
        $artifactUrl = (string) ($asset['artifact_url'] ?? PlaygroundArtifact::artifactUrl($repo, $branch, $name));
        $playgroundUrl = (string) ($asset['playground_url'] ?? PlaygroundArtifact::playgroundUrl($artifactUrl));

        $meta = array_filter([$size, $created], static fn (string $v) => $v !== '');
        echo "- {$name}" . ($meta === [] ? '' : ' (' . implode(', ', $meta) . ')') . "\n";
        echo "  artifact:   {$artifactUrl}\n";
        echo "  playground: {$playgroundUrl}\n";
    }
}

/** @return array{code:int, output:string} */
function run_command(string $cmd, ?string $cwd = null): array
{
    $out = [];
    $code = 0;
    $prefix = $cwd === null ? '' : 'cd ' . escapeshellarg($cwd) . ' && ';
    exec($prefix . $cmd . ' 2>&1', $out, $code);
    return ['code' => $code, 'output' => implode("\n", $out)];
}

function run_or_fail(string $cmd, ?string $cwd = null): void
{
    $result = run_command($cmd, $cwd);
    if ($result['code'] !== 0) {
        throw new RuntimeException("Command failed ({$result['code']}): {$cmd}\n" . $result['output']);
    }
}

function mkdir_or_fail(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create directory: {$dir}");
    }
}

function open_url(string $url): void
{
    $opener = trim((string) shell_exec('command -v xdg-open 2>/dev/null'));
    if ($opener === '') {
        fwrite(STDERR, "No xdg-open found; open this URL manually:\n{$url}\n");
        return;
    }
    exec(escapeshellarg($opener) . ' ' . escapeshellarg($url) . ' >/dev/null 2>&1 &');
}
