<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The single place that turns a collected AI_IMAGE placeholder into the text
 * prompt we send to the image-generation endpoint.
 *
 * A placeholder's alt is authored as:
 *     AI_IMAGE: <subject> | <page_context> | <style> | <aspect_ratio>
 *
 * Only the SUBJECT (what the image shows and from what point of view) and the
 * STYLE describe what the model must actually render. The PAGE CONTEXT (where
 * the image sits on the page — e.g. "full-bleed hero with text overlay",
 * "portfolio gallery card") and the SITE CONTEXT (what the whole site is about)
 * are NOT things to draw: they only steer subject choice, mood and composition.
 *
 * The IMAGE GRADE — the design direction's one-sentence photographic treatment
 * shared by ALL of the site's imagery (color vs B&W, grain, light) — IS render
 * instruction: it is appended to every prompt so independently generated images
 * read as one photographic series — EXCEPT for transparent assets: the grade
 * describes lighting and backdrop treatment ("candlelit low-key", "deep shadow
 * falloff"), which Imagen paints in as a background scene, so it is omitted
 * for them.
 *
 * TRANSPARENCY is also render instruction, but Imagen cannot output an alpha
 * channel and ignores prompt-level "transparent background" requests. So a
 * `.png` placeholder's prompt asks for the one isolation the model DOES honor —
 * the subject alone on a flat solid white background — and ImageTransparency
 * keys that background out to real alpha after generation.
 *
 * The shape of the prompt lives in prompts/image-prompt.md (a composable template
 * with plain {{placeholders}}, like the other prompts). This class assembles the
 * optional clauses — the style suffix, the grade clause and the context guidance —
 * and caps the result at the model's input-token limit. Because the subject leads
 * the template and the grade sits BEFORE the trailing guidance, that cap sheds the
 * sheddable context first and keeps the subject and grade intact.
 *
 * The aspect ratio is intentionally absent from this text — it is sent to the
 * endpoint as a structured parameter (Imagen::aspectRatio / buildBody).
 */
final class ImagePromptComposer
{
    /**
     * @param string             $subject     what the image shows and from what POV
     * @param string             $pageContext where/how the image is used on the page
     * @param string             $style       one of the AI_IMAGE style keywords
     * @param string             $siteContext a factual site-context phrase that
     *        leads with a noun ("the website “X”. Description…" — see
     *        GenerateImagesStep::siteContext), so it reads after "used as … on"
     * @param string             $imageGrade  the project-wide photographic grade
     *        from the design direction, applied to every image
     * @param bool               $transparent whether the asset needs a transparent
     *        background (a `.png` placeholder — decorative flourishes, ornaments,
     *        logo marks). Imagen cannot render real transparency, so the prompt
     *        asks for a flat solid white background instead, which
     *        ImageTransparency keys out after generation; the photographic
     *        grade is omitted so no lighting/backdrop gets painted behind the
     *        subject.
     * @param PromptRenderer|null $renderer    defaults to the package's prompts/ dir;
     *        injectable so the template lookup can be redirected in tests
     */
    public static function compose(
        string $subject,
        string $pageContext,
        string $style,
        string $siteContext = '',
        string $imageGrade = '',
        bool $transparent = false,
        ?PromptRenderer $renderer = null
    ): string {
        $renderer ??= new PromptRenderer(Package::promptsDir());

        $subject     = trim($subject);
        $style       = trim($style);
        $pageContext = trim($pageContext);
        $siteContext = trim($siteContext);
        $imageGrade  = trim($imageGrade);

        // Style is appended to the subject as a suffix; absent when no style.
        $styleClause = $style !== '' ? ". Style: {$style}" : '';

        // The shared grade is a render instruction for every image on the site;
        // it precedes the guidance so end-trimming sheds the guidance first.
        // Transparent assets skip it: the grade describes lighting and backdrop
        // treatment, which Imagen would paint in as a background scene behind
        // the subject — exactly what the white-background keying must avoid.
        $gradeClause = ($imageGrade !== '' && !$transparent)
            ? 'Art direction for all site imagery: ' . rtrim($imageGrade, '.') . '.'
            : '';

        // Like the grade, this is a render instruction: it sits before the
        // guidance so end-trimming under token pressure never sheds it. Imagen
        // cannot render real alpha, so ask for the isolation it does honor — a
        // flat solid white backdrop — which is keyed out after generation.
        $transparencyClause = $transparent
            ? 'Render the subject fully isolated and centered on a plain solid pure white'
                . ' background: perfectly flat and even, with no gradients, no vignette,'
                . ' no glow, no shadows, no scenery and no backdrop of any kind.'
            : '';

        // Page and site context only steer mood/composition — they are NOT drawn,
        // so they are framed as guidance and omitted entirely when there is none.
        // The two fold into ONE sentence ("used as X on the website Y") — the
        // site context leads with a noun phrase (GenerateImagesStep::siteContext)
        // precisely so it reads after "on".
        if ($pageContext !== '' && $siteContext !== '') {
            $where = "This image is used as {$pageContext} on {$siteContext}";
        } elseif ($pageContext !== '') {
            $where = "This image is used as {$pageContext}.";
        } elseif ($siteContext !== '') {
            $where = "This image appears on {$siteContext}";
        } else {
            $where = '';
        }
        $guidance = $where === '' ? '' : 'Context to guide the subject, mood and '
            . 'composition only — do not render any of it as text or literal objects: '
            . $where;

        $prompt = $renderer->render('image-prompt.md', [
            'subject'             => $subject,
            'style_clause'        => $styleClause,
            'grade_clause'        => $gradeClause,
            'transparency_clause' => $transparencyClause,
            'guidance'            => $guidance,
        ]);

        // Empty clauses leave stacked blank lines in the template; collapse them,
        // normalise the surrounding whitespace, then cap to the model's hard input
        // limit (sheds trailing context first — see class doc).
        $prompt = (string) preg_replace("/\n{3,}/", "\n\n", trim($prompt));
        return Imagen::fitToTokens($prompt, Imagen::MAX_PROMPT_TOKENS);
    }

    /**
     * Lint a subject for a legible app/device screen as the dominant subject
     * (BIGR-738): "a scheduling app day view with stacked job cards, times and
     * crew assignments, screen filling most of the frame" renders as gibberish
     * pseudo-text. prompts/image-generation.md bans the shape; this is the
     * deterministic backstop that makes a slipped-through request visible in
     * warnings.json. Subjects that already defuse the screen (oblique, out of
     * focus, abstract, unreadable) pass. Returns null when the subject is fine.
     */
    public static function screenSubjectWarning(string $subject): ?string
    {
        $s = strtolower($subject);
        if (!preg_match('/\b(?:screen|phone|tablet|laptop|monitor|device|app|dashboard|interface|ui)\b/', $s)) {
            return null;
        }
        if (!preg_match('/\b(?:showing|shows|displaying|displays|filled?\s+with\s+(?:the\s+)?(?:app|ui|interface|dashboard))\b/', $s)) {
            return null;
        }
        if (preg_match('/\b(?:out of focus|blurred|blurry|oblique|unreadable|illegible|abstract|glowing)\b/', $s)) {
            return null;
        }
        return 'image subject requests a legible app/device screen ("'
            . trim($subject) . '") — generated UI text renders as gibberish; '
            . 'use a real HTML card, an oblique/defocused screen, or drop the device '
            . '(see prompts/image-generation.md)';
    }
}
