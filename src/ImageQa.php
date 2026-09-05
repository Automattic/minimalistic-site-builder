<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Pure helpers for the post-generation look at a delivered hero image
 * (BIGR-979). Nothing in the pipeline used to see the pixels: a rotated,
 * text-bearing or off-subject cover shipped with `status: completed`. These
 * helpers decide which images earn a vision check, read the model's answer,
 * write the corrected subject for the one regeneration, and word the
 * warnings.json row for an image that still fails. GenerateImagesStep owns
 * the I/O and the escalation.
 */
final class ImageQa
{
    /** Model answer keys, each a boolean. */
    private const KEYS = ['upright', 'rendered_text', 'matches_subject'];

    /**
     * Only heroes and viewport-spanning images are inspected: they are the
     * few images a visitor cannot miss, and the check costs one vision call
     * each. A transparent asset is an isolated object with no orientation.
     *
     * @param array<string,mixed> $spec one images.json row
     */
    public static function applies(array $spec): bool
    {
        $filename = basename((string) ($spec['filename'] ?? ''));
        if ($filename === '' || GeminiImage::mimeForFilename($filename) === 'image/png') {
            return false;
        }
        // A product-screen site inspects every picture (frm W7b): painted
        // words in a mockup read as the site's own copy.
        if (ImageKind::inspectsEveryImage((string) ($spec['image_kind'] ?? ''))) {
            return true;
        }
        if (preg_match('/^hero(?:[-_.]|$)/i', $filename) === 1) {
            return true;
        }
        $pageContext = (string) ($spec['pageContext'] ?? '');
        if (ImageCrop::fullFrameSlot(
            GeminiImage::aspectRatio((string) ($spec['aspectRatio'] ?? 'landscape')),
            $pageContext,
        )) {
            return true;
        }
        // A copy reservation only exists over a cover.
        return preg_match('/\b(?:cover|overlay|overlaid|negative[- ]space)\b/iu', $pageContext) === 1;
    }

    /**
     * Read the model's JSON answer. Null when the answer cannot be read: the
     * caller treats that as "no verdict" and delivers the image unchanged,
     * because an unreadable check is not evidence of a defect.
     *
     * @return array{ok:bool,findings:list<string>,note:string}|null
     */
    public static function verdict(string $answer): ?array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $data = json_decode(substr($answer, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            return null;
        }
        $seen = false;
        foreach (self::KEYS as $key) {
            if (is_bool($data[$key] ?? null)) {
                $seen = true;
            }
        }
        if (!$seen) {
            return null;
        }
        $findings = [];
        if (($data['upright'] ?? true) === false) {
            $findings[] = 'camera not upright (scene rotated or tilted)';
        }
        if (($data['rendered_text'] ?? false) === true) {
            $findings[] = 'rendered text or lettering in the picture';
        }
        if (($data['matches_subject'] ?? true) === false) {
            $findings[] = 'picture does not show the requested subject';
        }
        $note = is_string($data['note'] ?? null) ? trim($data['note']) : '';
        return ['ok' => $findings === [], 'findings' => $findings, 'note' => $note];
    }

    /**
     * The subject for the one regeneration: the authored subject plus a
     * positive correction for each finding. The wording never names lettering
     * or signage — naming it plants it (prompts/image-generation.md) — and
     * never says "frame" (BIGR-956). An off-subject picture gets a fresh
     * sample of the same subject; there is no better instruction than the
     * subject itself.
     *
     * @param array{ok:bool,findings:list<string>,note:string} $verdict
     */
    public static function correctedSubject(string $subject, array $verdict): string
    {
        $authored = trim($subject);
        $subject = rtrim($authored, '.');
        $clauses = [];
        foreach ($verdict['findings'] as $finding) {
            if (str_starts_with($finding, 'camera not upright')) {
                $clauses[] = 'The camera is upright and level: the sky or ceiling is at the top of the canvas,'
                    . ' the ground at the bottom, and people and buildings stand vertically.';
            } elseif (str_starts_with($finding, 'rendered text')) {
                $clauses[] = 'Every surface in the scene is plain and unmarked.';
            }
        }
        if ($clauses === []) {
            return $authored;
        }
        return $subject === ''
            ? implode(' ', $clauses)
            : $subject . '. ' . implode(' ', $clauses);
    }

    /**
     * The warnings.json row for an image that failed the check twice, or
     * failed once and could not be regenerated. Actionable on its own per
     * AGENTS.md: file, authored subject, finding, disposition.
     *
     * @param list<string> $findings
     */
    public static function warningRow(string $filename, string $subject, array $findings, string $disposition): string
    {
        return "theme/assets/{$filename}: image QA finding "
            . Warnings::value(implode('; ', $findings))
            . '; authored subject ' . Warnings::value($subject)
            . "; delivered as generated; disposition: delivered, {$disposition}";
    }
}
