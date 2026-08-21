<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * One build-owned overlay stylesheet: `theme/assets/<folder>/<folder>.css`.
 *
 * Every commitment the build executes in CSS rather than in theme.json ships
 * the same way — one file the build alone writes, enqueued after the generated
 * `style.css` so the commitment outranks generated utilities, mirrored into the
 * editor so previews match, and pruned when the commitment is its `none` value
 * so a previous run's sheet cannot linger.
 *
 * The corner language was the first of these, and surface and device followed
 * it. Describing a kit as data instead of another `bool` parameter and another
 * `if` block in FinalizeThemeStep is what keeps adding the next one from being
 * a third copy of the same twenty lines.
 */
final class OverlayKit
{
    /**
     * @param string $folder both the directory under theme/assets/ and the
     *        stylesheet's basename, so one token names the whole kit
     * @param string $comment the note written above the enqueue in
     *        functions.php, explaining what the sheet is for
     */
    public function __construct(
        public readonly string $folder,
        public readonly string $comment,
    ) {}

    /** Theme-relative path, the form get_theme_file_uri() and add_editor_style() take. */
    public function themeRelPath(): string
    {
        return "assets/{$this->folder}/{$this->folder}.css";
    }

    /** Project-relative path, the form Project::writeText() takes. */
    public function projectRelPath(): string
    {
        return "theme/{$this->themeRelPath()}";
    }

    public function handle(string $slug): string
    {
        return "{$slug}-{$this->folder}";
    }

    /** The `writes:` glob a step declares for this kit. */
    public function declaredWrites(): string
    {
        return "theme/assets/{$this->folder}/*";
    }
}
