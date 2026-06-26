<?php
declare(strict_types=1);

/**
 * Step 1 (deterministic): scaffold a new block theme.
 *
 * Input:  none
 * Output: theme/style.css and theme/readme.txt with {{placeholders}} that the
 *         ApplyIdentityStep fills once the site name/slug are known.
 */
final class ScaffoldThemeStep implements Step
{
    public function id(): string
    {
        return 'scaffold-theme';
    }

    public function label(): string
    {
        return 'Scaffold theme';
    }

    public function run(Project $project): void
    {
        $project->writeText('theme/style.css', self::STYLE_CSS);
        $project->writeText('theme/readme.txt', self::README);
    }

    private const STYLE_CSS = <<<CSS
        /*
        Theme Name: {{THEME_NAME}}
        Theme URI:
        Author: {{AUTHOR}}
        Author URI:
        Description: {{DESCRIPTION}}
        Version: 0.1.0
        Requires at least: 6.5
        Tested up to: 6.5
        Requires PHP: 7.4
        License: GNU General Public License v2 or later
        License URI: https://www.gnu.org/licenses/gpl-2.0.html
        Text Domain: {{THEME_SLUG}}
        */

        CSS;

    private const README = <<<TXT
        === {{THEME_NAME}} ===

        Contributors: {{AUTHOR}}
        Requires at least: 6.5
        Tested up to: 6.5
        Requires PHP: 7.4
        License: GNU General Public License v2 or later
        License URI: https://www.gnu.org/licenses/gpl-2.0.html

        == Description ==

        {{DESCRIPTION}}

        TXT;
}
