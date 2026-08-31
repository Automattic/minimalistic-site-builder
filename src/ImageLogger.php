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
    use TranscriptLogger;

    /** Slug used when a label reduces to nothing. */
    private const SLUG_FALLBACK = 'image';

    /**
     * Write one image-request transcript. Never throws.
     *
     * A failed request is logged too: pass its message as $error and the file is
     * named `<label>-failed.log` with the error in place of the output details,
     * so a partial build is still inspectable.
     *
     * @param string $label the asset filename the request produced, e.g. "hero.jpg"
     * @param array{model?:string,prompt?:string,aspect_ratio?:string,sample_image_size?:string,subject?:string,subject_delivered?:string,page_context?:string,style?:string,image_grade?:string} $request
     *        the composed prompt and every parameter that shaped the request
     * @param array{path?:string,bytes?:int,border_trimmed?:int} $result output asset path, size and painted-border trim
     *        (ignored for a failed request)
     * @param ?string $error failure message, or null for a successful request
     */
    public static function log(string $label, array $request, array $result = [], ?string $error = null): void
    {
        self::writeTranscript(
            $label,
            $error,
            fn (): string => self::format($label, $request, $result, $error)
        );
    }

    /**
     * Render the full log file: a summary header (file, model, aspect ratio,
     * status, output), the spec fields that shaped the request (subject, the
     * delivered subject when the grade pass rewrote it, page context, style),
     * the full prompt text exactly as sent to the API, and —
     * for a failed request — the error last. Pure — unit-testable.
     *
     * @param array{model?:string,prompt?:string,aspect_ratio?:string,sample_image_size?:string,subject?:string,subject_delivered?:string,page_context?:string,style?:string,image_grade?:string} $request
     * @param array{path?:string,bytes?:int,border_trimmed?:int} $result
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
            'Logged at    : ' . gmdate('Y-m-d H:i:s'),
        ]);
        if ($error === null) {
            $path = (string) ($result['path'] ?? '');
            $bytes = (int) ($result['bytes'] ?? 0);
            if ($path !== '' || $bytes > 0) {
                $headerLines[] = 'Output       : '
                    . ($path !== '' ? $path : '(unknown)')
                    . ($bytes > 0 ? sprintf(' (%d bytes)', $bytes) : '');
            }
            $borderTrimmed = (int) ($result['border_trimmed'] ?? 0);
            if ($borderTrimmed > 0) {
                $headerLines[] = 'Border trim  : removed a painted '
                    . $borderTrimmed . 'px print border (BIGR-956)';
            }
        }
        $header = implode("\n", $headerLines);

        // The spec fields that steered the request, each as readable text. Only
        // the ones that were actually present are shown.
        $specSections = [];
        foreach ([
            'subject'           => 'SUBJECT',
            // Present only when the grade pass rewrote the subject. It sits
            // right after the authored one so a reader sees both, instead of
            // an authored SUBJECT beside a PROMPT built from a different one.
            'subject_delivered' => 'SUBJECT DELIVERED',
            'page_context'      => 'PAGE CONTEXT',
            'style'             => 'STYLE',
            'image_grade'       => 'IMAGE GRADE',
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
