<?php
declare(strict_types=1);

/** @return array<string,string> label => repository-relative path */
function plugin_manifest_paths(): array
{
    return [
        'Claude plugin' => '.claude-plugin/plugin.json',
        'Claude marketplace' => '.claude-plugin/marketplace.json',
        'Codex plugin' => '.codex-plugin/plugin.json',
        'Gemini extension' => 'gemini-extension.json',
        'agents plugin marketplace' => '.agents/plugins/marketplace.json',
        'agents skill marketplace' => '.agents/skills/marketplace.json',
        'Codex skill marketplace' => '.agents/skills/codex.marketplace.json',
    ];
}

function plugin_manifest_root(): string
{
    return dirname(__DIR__, 2);
}

/** @return array<string,mixed> */
function decode_plugin_manifest(string $relativePath): array
{
    $bytes = file_get_contents(plugin_manifest_root() . '/' . $relativePath);
    assert_true(is_string($bytes), "{$relativePath} is readable");
    $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    assert_true(is_array($decoded), "{$relativePath} decodes to an object");
    return $decoded;
}

test('all seven plugin and marketplace manifests parse as JSON', function () {
    foreach (plugin_manifest_paths() as $label => $path) {
        assert_true(decode_plugin_manifest($path) !== [], "{$label} manifest is not empty");
    }
});

test('every harness plugin manifest exposes exactly the site-build skill', function () {
    foreach (['.claude-plugin/plugin.json', '.codex-plugin/plugin.json', 'gemini-extension.json'] as $path) {
        assert_eq(['./skills/site-build'], decode_plugin_manifest($path)['skills'] ?? null, $path);
    }
});

test('every skill and marketplace source path resolves inside the repository', function () {
    $root = plugin_manifest_root();
    foreach (['.claude-plugin/plugin.json', '.codex-plugin/plugin.json', 'gemini-extension.json'] as $path) {
        foreach (decode_plugin_manifest($path)['skills'] ?? [] as $skillPath) {
            assert_true(is_string($skillPath) && file_exists($root . '/' . $skillPath), "{$path}: {$skillPath}");
        }
    }

    foreach (['.claude-plugin/marketplace.json', '.agents/plugins/marketplace.json', '.agents/skills/marketplace.json', '.agents/skills/codex.marketplace.json'] as $path) {
        $plugins = decode_plugin_manifest($path)['plugins'] ?? null;
        assert_true(is_array($plugins), "{$path}: plugins is an array");
        assert_eq(1, count($plugins), "{$path}: exactly one plugin");
        foreach ($plugins as $plugin) {
            $source = $plugin['source'] ?? null;
            $sourcePath = is_array($source) ? ($source['path'] ?? null) : $source;
            assert_true(is_string($sourcePath) && file_exists($root . '/' . $sourcePath), "{$path}: marketplace source");
        }
    }
});

test('plugin name and version agree across Claude Codex and Gemini', function () {
    foreach (['.claude-plugin/plugin.json', '.codex-plugin/plugin.json', 'gemini-extension.json'] as $path) {
        $manifest = decode_plugin_manifest($path);
        assert_eq('site-builder', $manifest['name'] ?? null, "{$path} name");
        assert_eq('0.1.0', $manifest['version'] ?? null, "{$path} version");
    }
});

test('every marketplace entry names the site-builder plugin', function () {
    foreach (['.claude-plugin/marketplace.json', '.agents/plugins/marketplace.json', '.agents/skills/marketplace.json', '.agents/skills/codex.marketplace.json'] as $path) {
        $manifest = decode_plugin_manifest($path);
        assert_eq('site-builder', $manifest['plugins'][0]['name'] ?? null, "{$path} plugin name");
    }
});

test('site-build Skill has required YAML frontmatter', function () {
    $path = plugin_manifest_root() . '/skills/site-build/SKILL.md';
    $bytes = file_get_contents($path);
    assert_true(is_string($bytes), 'skills/site-build/SKILL.md is readable');
    assert_true(preg_match('/\A---\R(?<frontmatter>.*?)\R---\R/s', $bytes, $match) === 1, 'YAML frontmatter exists');
    assert_true(preg_match('/^name:\s*\S.+$/m', $match['frontmatter']) === 1, 'frontmatter name exists');
    assert_true(preg_match('/^description:\s*\S.+$/m', $match['frontmatter']) === 1, 'frontmatter description exists');
});

test('site-build Skill guards the complete plugin-root resolution order', function () {
    $bytes = file_get_contents(plugin_manifest_root() . '/skills/site-build/SKILL.md');
    assert_true(is_string($bytes), 'skills/site-build/SKILL.md is readable');

    $previousOffset = -1;
    foreach (['SITE_BUILD_HOME', 'CLAUDE_PLUGIN_ROOT', 'GROK_PLUGIN_ROOT', 'CODEX_PLUGIN_ROOT'] as $variable) {
        $guard = '[ -n "${' . $variable . ':-}" ] && [ -r "$' . $variable . '/bin/build.php" ]';
        $offset = strpos($bytes, $guard);
        assert_true($offset !== false, "{$variable} is guarded by bin/build.php");
        assert_true($offset > $previousOffset, "{$variable} keeps the frozen resolution order");
        $previousOffset = $offset;
    }

    assert_true(!str_contains($bytes, 'php bin/build.php'), 'no build invocation depends on the current directory');
    preg_match_all('/\bphp\s+(?<target>\S*bin\/build\.php\S*)/', $bytes, $matches);
    assert_true($matches['target'] !== [], 'Skill includes build CLI invocations');
    foreach ($matches['target'] as $target) {
        assert_eq('"$SITE_BUILD_HOME/bin/build.php"', $target, 'build invocation uses resolved root');
    }
});

test('site-builder symlink resolves to the repository root and build CLI', function () {
    $root = plugin_manifest_root();
    $link = $root . '/plugins/site-builder';
    assert_true(is_link($link), 'plugins/site-builder is a symlink');
    assert_eq(realpath($root), realpath($link), 'plugins/site-builder resolves to repository root');
    assert_true(is_readable($link . '/bin/build.php'), 'build CLI is readable through plugin symlink');
});

test('I-G5 site-build Skill creates multi-page projects only at creation time', function () {
    $bytes = file_get_contents(plugin_manifest_root() . '/skills/site-build/SKILL.md');
    assert_true(is_string($bytes));

    preg_match_all('/^SITE_BUILD_LLM=.*php .*bin\/build\.php.*$/m', $bytes, $matches);
    $commands = $matches[0];
    $creates = array_values(array_filter($commands, static fn (string $line): bool => str_contains($line, '"<site prompt>"')));
    $steps = array_values(array_filter($commands, static fn (string $line): bool => str_contains($line, '--step=STEP_ID')));

    assert_true($creates !== [], 'Skill includes a prompt-bearing create command');
    foreach ($creates as $create) {
        assert_contains('--multi-page', $create, 'every Skill create form is multi-page');
        assert_true(!str_contains($create, '--pages='), 'the Skill does not choose pages');
    }
    assert_eq(1, count($steps), 'Skill includes one per-step command template');
    assert_true(!str_contains($steps[0], '--multi-page'), 'per-step calls inherit the recorded mode');
});

test('I-G6 site-build Skill states the provisioned image limitation without denying authentication', function () {
    $bytes = file_get_contents(plugin_manifest_root() . '/skills/site-build/SKILL.md');
    assert_true(is_string($bytes));

    assert_contains('image placeholders', $bytes);
    assert_contains('Harness and plugin transports do not provide WPCOM proxy credentials', $bytes);
    assert_contains('GOOGLE_VERTEX_API_TOKEN', $bytes);
    assert_contains('this Skill never passes `--with-images`', $bytes);
    assert_contains('Neither the Codex nor the Grok CLI exposes image generation', $bytes);
    assert_contains('current limitation, not a defect', $bytes);
    assert_true(!str_contains(strtolower($bytes), 'cannot authenticate'), 'provisioning is not a harness identity claim');
});
