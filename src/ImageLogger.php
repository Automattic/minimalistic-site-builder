<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Per-request transcript logger for every image the builder generates.
 *
 * The sibling of LlmLogger, for the image-generation path. Each AI_IMAGE
 * request (its full prompt, the model, the aspect ratio and the rest of the
 * spec) is written to its own file under the active project's logs/images/
 * directory, named after the asset filename it produced — e.g. `01-hero.jpg.log`,
 * `02-about-portrait.jpg.log`. Files are prefixed with the request's position in
 * the run so the listing reflects generation order, and a request that fails is
 * logged too, as `<file>-failed.log`, so a partial build is still inspectable.
 *
 * The target directory is set per run by GenerateImagesStep from the project
 * being built (projects/<slug>/logs/images/). Until a directory is set there is
 * no project context, so logging is simply a no-op — nothing is ever written to
 * the repo root.
 *
 * Logging is strictly best-effort: any failure (unwritable dir, etc.) is
 * swallowed so a logging problem can never break a build.
 */
final class ImageLogger
{
    /** Target dir for logs, set per run from the active project; null = no-op. */
    private static ?string $dir = null;
    private static bool $disabled = false;

    /** Count of requests logged this run, used to prefix files in call order. */
    private static int $seq = 0;

    /** Where logs are written, or null when no project context is set. */
    public static function dir(): ?string
    {
        return self::$dir;
    }

    /** Point logging at the active project's logs/images/ dir (null disables it). */
    public static function setDir(?string $dir): void
    {
        self::$dir = $dir;
        self::$seq = 0; // new run → restart the request-order numbering
    }

    /** Turn logging on/off (off is handy for tests). */
    public static function setEnabled(bool $enabled): void
    {
        self::$disabled = !$enabled;
    }

    /**
     * Write one image-request transcript. Never throws.
     *
     * A failed request is logged too: pass its message as $error and the file is
     * named `<label>-failed.log` with the error in place of the output details,
     * so a partial build is still inspectable.
     *
     * @param string $label the asset filename the request produced, e.g. "hero.jpg"
     * @param array{model?:string,prompt?:string,aspect_ratio?:string,sample_image_size?:string,subject?:string,page_context?:string,style?:string,image_grade?:string} $request
     *        the composed prompt and every parameter that shaped the request
     * @param array{path?:string,bytes?:int} $result output asset path and size
     *        (ignored for a failed request)
     * @param ?string $error failure message, or null for a successful request
     */
    public static function log(string $label, array $request, array $result = [], ?string $error = null): void
    {
        if (self::$disabled) {
            return;
        }
        $dir = self::$dir;
        if ($dir === null) {
            // No active project context — nowhere to log (and never the repo root).
            return;
        }
        try {
            if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                return;
            }
            // Prefix the filename with the request's position this run (01, 02, …)
            // so the directory listing reflects generation order, and tag failures
            // so they stand out in the listing.
            $prefix = sprintf('%02d', ++self::$seq);
            $name = $prefix . '-' . $label . ($error !== null ? '-failed' : '');
            $path = self::uniquePath($dir, $name);
            @file_put_contents($path, self::format($label, $request, $result, $error));
        } catch (\Throwable $e) {
            // Best-effort: a logging failure must never break a build.
        }
    }

    /**
     * Next free path for a label: `<label>.log`, then `<label>-02.log`, … so two
     * requests that produced the same asset name never collide. Pure apart from
     * the filesystem existence checks — unit-testable.
     */
    public static function uniquePath(string $dir, string $label): string
    {
        $base = self::slug($label);
        $path = "{$dir}/{$base}.log";
        if (!file_exists($path)) {
            return $path;
        }
        for ($n = 2; ; $n++) {
            $candidate = sprintf('%s/%s-%02d.log', $dir, $base, $n);
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }
    }

    /** Make a label safe as a filename: lowercase, only [a-z0-9._-]. Pure. */
    public static function slug(string $label): string
    {
        $label = strtolower(trim($label));
        $label = preg_replace('/[^a-z0-9._-]+/', '-', $label) ?? '';
        $label = trim($label, '-');
        return $label === '' ? 'image' : $label;
    }

    /**
     * Render the full log file: a summary header (file, model, aspect ratio,
     * status, output), the spec fields that shaped the request (subject, page
     * context, style), the full prompt text exactly as sent to the API, and —
     * for a failed request — the error last. Pure — unit-testable.
     *
     * @param array{model?:string,prompt?:string,aspect_ratio?:string,sample_image_size?:string,subject?:string,page_context?:string,style?:string,image_grade?:string} $request
     * @param array{path?:string,bytes?:int} $result
     * @param ?string $error failure message, or null for a successful request
     */
    public static function format(string $label, array $request, array $result = [], ?string $error = null): string
    {
        $rule = str_repeat('=', 80);
        $sub = str_repeat('-', 80);

        $model  = (string) ($request['model'] ?? 'unknown');
        $aspect = (string) ($request['aspect_ratio'] ?? '');
        $size   = (string) ($request['sample_image_size'] ?? '');

        $headerLines = [
            $rule,
            'IMAGE REQUEST LOG',
            $rule,
            'File         : ' . $label,
            'Model        : ' . $model,
            'Aspect ratio : ' . ($aspect === '' ? '(default)' : $aspect),
        ];
        if ($size !== '') {
            $headerLines[] = 'Sample size  : ' . $size;
        }
        $headerLines = array_merge($headerLines, [
            'Status       : ' . ($error !== null ? 'FAILED' : 'OK'),
            'Logged at    : ' . date('Y-m-d H:i:s'),
        ]);
        if ($error === null) {
            $path = (string) ($result['path'] ?? '');
            $bytes = (int) ($result['bytes'] ?? 0);
            if ($path !== '' || $bytes > 0) {
                $headerLines[] = 'Output       : '
                    . ($path !== '' ? $path : '(unknown)')
                    . ($bytes > 0 ? sprintf(' (%d bytes)', $bytes) : '');
            }
        }
        $header = implode("\n", $headerLines);

        // The spec fields that steered the request, each as readable text. Only
        // the ones that were actually present are shown.
        $specSections = [];
        foreach ([
            'subject'      => 'SUBJECT',
            'page_context' => 'PAGE CONTEXT',
            'style'        => 'STYLE',
            'image_grade'  => 'IMAGE GRADE',
        ] as $key => $heading) {
            $value = trim((string) ($request[$key] ?? ''));
            if ($value !== '') {
                $specSections[] = $sub;
                $specSections[] = $heading;
                $specSections[] = $sub;
                $specSections[] = $value;
            }
        }

        // The prompt is logged verbatim — exactly the text sent to the API —
        // whether the request succeeded or failed; a failure appends its error.
        $tail = [$sub, 'PROMPT', $sub, (string) ($request['prompt'] ?? '')];
        if ($error !== null) {
            $tail = array_merge($tail, [$sub, 'ERROR', $sub, $error]);
        }

        return implode("\n", array_merge(
            [$header],
            $specSections,
            $tail,
            [$rule, '']
        ));
    }
}
