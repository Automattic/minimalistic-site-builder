<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step 3 (deterministic): apply the project identity to the scaffolded theme
 * and content plugin.
 *
 * Input:  siteSpec.json (name, slug, factual fields) + scaffolded theme files
 * Output: theme/style.css and theme/readme.txt with {{placeholders}} replaced.
 *
 * Identity is purely factual, so it sources from siteSpec.json. The spec no
 * longer carries a fixed "description" field, so we compose one from the
 * factual fields that are present (description/tagline/topic), falling back to
 * the site name.
 */
final class ApplyIdentityStep implements Step
{
    public function id(): string
    {
        return 'apply-identity';
    }

    public function label(): string
    {
        return 'Apply project identity';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['siteSpec.json', 'theme/style.css', 'theme/readme.txt', ScaffoldPluginStep::MAIN_FILE],
            writes: ['theme/style.css', 'theme/readme.txt', ScaffoldPluginStep::MAIN_FILE],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $spec = $project->readJson('siteSpec.json');

        $vars = array_map([self::class, 'headerSafe'], [
            'THEME_NAME'  => (string) $spec['name'],
            'THEME_SLUG'  => (string) $spec['slug'],
            'DESCRIPTION' => self::description($spec),
            'AUTHOR'      => (string) ($spec['author'] ?? 'Builder'),
        ]);

        // The content plugin defines global functions and a constant. Two
        // generated sites on one host would collide on those names (a fatal
        // "cannot redeclare"), and — since every generated plugin's function
        // bodies are byte-identical and read their own __DIR__ — a bare
        // function_exists guard would silently run one site's seeder against
        // another's directory. So the symbols are namespaced per slug.
        $prefix = self::identifierPrefix((string) $spec['slug']);
        $vars['FN_PREFIX']    = $prefix;
        $vars['CONST_PREFIX'] = strtoupper($prefix);

        // The content plugin's header carries the same identity; it may be
        // absent in compositions that build a theme only.
        $files = ['theme/style.css', 'theme/readme.txt'];
        if ($project->exists(ScaffoldPluginStep::MAIN_FILE)) {
            $files[] = ScaffoldPluginStep::MAIN_FILE;
        }

        foreach ($files as $file) {
            $filled = PromptRenderer::fill($project->readText($file), $vars);
            $project->writeText($file, $filled);
        }
    }

    /**
     * Make a model-derived value inert inside the comment headers it fills:
     * every target is a line-oriented header block, and the plugin's is a PHP
     * docblock, where a value carrying a star-slash comment terminator would
     * close the header and whatever follows would execute BEFORE the ABSPATH
     * guard. Newlines and control characters collapse to spaces (they would
     * also forge extra header lines), then comment terminators are removed
     * until none can reassemble.
     */
    private static function headerSafe(string $value): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
        // These values are model-authored and land inside a PHP docblock, so a
        // comment terminator would end the comment early and make the rest of
        // the value executable. Removing '*/' in a loop is a denylist: it has
        // to be right about every sequence that can close a comment. Neutralise
        // the only character that can start one instead — a '*' cannot end a
        // block comment, and the header stays legible (BIGR-750).
        return str_replace('*', '', $value);
    }

    /**
     * A slug reduced to a valid, collision-resistant PHP identifier prefix:
     * lowercased, every non-[a-z0-9] run folded to one underscore, and a
     * leading letter guaranteed (a slug may start with a digit, which no PHP
     * function or constant name may).
     */
    public static function identifierPrefix(string $slug): string
    {
        $prefix = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($slug)), '_');
        if ($prefix === '' || !ctype_alpha($prefix[0])) {
            $prefix = 'builder_' . $prefix;
        }
        return $prefix;
    }

    /**
     * A short theme description from whatever factual fields the spec carries.
     *
     * @param array<mixed> $spec
     */
    private static function description(array $spec): string
    {
        foreach (['description', 'tagline', 'topic'] as $key) {
            $value = trim((string) ($spec[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return (string) $spec['name'];
    }
}
