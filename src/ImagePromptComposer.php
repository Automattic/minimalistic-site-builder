<?php
declare(strict_types=1);

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
 * read as one photographic series.
 *
 * The shape of the prompt lives in prompts/image-prompt.md (a composable template
 * with plain {{placeholders}}, like the other prompts). This class assembles the
 * optional clauses — the style suffix, the grade clause and the context guidance —
 * and caps the result at the model's input-token limit. Because the subject leads
 * the template and the grade sits BEFORE the trailing guidance, that cap sheds the
 * sheddable context first and keeps the subject and grade intact.
 *
 * The aspect ratio is intentionally absent from this text — it is sent to the
 * endpoint as a structured parameter (WpcomImageClient::aspectRatio / buildBody).
 */
final class ImagePromptComposer
{
    /**
     * @param string             $subject     what the image shows and from what POV
     * @param string             $pageContext where/how the image is used on the page
     * @param string             $style       one of the AI_IMAGE style keywords
     * @param string             $siteContext a factual sentence about the site
     * @param string             $imageGrade  the project-wide photographic grade
     *        from the design direction, applied to every image
     * @param PromptRenderer|null $renderer    defaults to the repo's prompts/ dir;
     *        injectable so the template lookup can be redirected in tests
     */
    public static function compose(
        string $subject,
        string $pageContext,
        string $style,
        string $siteContext = '',
        string $imageGrade = '',
        ?PromptRenderer $renderer = null
    ): string {
        $renderer ??= new PromptRenderer(repo_path('prompts'));

        $subject     = trim($subject);
        $style       = trim($style);
        $pageContext = trim($pageContext);
        $siteContext = trim($siteContext);
        $imageGrade  = trim($imageGrade);

        // Style is appended to the subject as a suffix; absent when no style.
        $styleClause = $style !== '' ? ". Style: {$style}" : '';

        // The shared grade is a render instruction for every image on the site;
        // it precedes the guidance so end-trimming sheds the guidance first.
        $gradeClause = $imageGrade !== ''
            ? 'Art direction for all site imagery: ' . rtrim($imageGrade, '.') . '.'
            : '';

        // Page and site context only steer mood/composition — they are NOT drawn,
        // so they are framed as guidance and omitted entirely when there is none.
        $sentences = [];
        if ($pageContext !== '') {
            $sentences[] = "This image is used as {$pageContext}.";
        }
        if ($siteContext !== '') {
            $sentences[] = "This is for a site about: {$siteContext}";
        }
        $guidance = $sentences === [] ? '' : 'Context to guide the subject, mood and '
            . 'composition only — do not render any of it as text or literal objects: '
            . implode(' ', $sentences);

        $prompt = $renderer->render('image-prompt.md', [
            'subject'      => $subject,
            'style_clause' => $styleClause,
            'grade_clause' => $gradeClause,
            'guidance'     => $guidance,
        ]);

        // Empty clauses leave stacked blank lines in the template; collapse them,
        // normalise the surrounding whitespace, then cap to the model's hard input
        // limit (sheds trailing context first — see class doc).
        $prompt = (string) preg_replace("/\n{3,}/", "\n\n", trim($prompt));
        return WpcomImageClient::fitToTokens($prompt, WpcomImageClient::MAX_PROMPT_TOKENS);
    }
}
