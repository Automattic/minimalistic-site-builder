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
 * The exact wording and shape of the prompt live in prompts/image-prompt.md (a
 * composable template, like the other prompts) — this class only supplies the
 * values and caps the result at the model's input-token limit. Because the
 * subject leads the template, that cap sheds trailing context first and keeps
 * the subject intact.
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
     * @param PromptRenderer|null $renderer    defaults to the repo's prompts/ dir;
     *        injectable so the template lookup can be redirected in tests
     */
    public static function compose(
        string $subject,
        string $pageContext,
        string $style,
        string $siteContext = '',
        ?PromptRenderer $renderer = null
    ): string {
        $renderer ??= new PromptRenderer(repo_path('prompts'));

        $pageContext = trim($pageContext);
        $siteContext = trim($siteContext);

        $prompt = $renderer->render('image-prompt.md', [
            'subject'      => trim($subject),
            'style'        => trim($style),
            'page_context' => $pageContext,
            'site_context' => $siteContext,
            // Gate for the whole guidance block: present only when there is some.
            'has_context'  => ($pageContext !== '' || $siteContext !== '') ? 'yes' : '',
        ]);

        // The template can leave surrounding whitespace; normalise then cap to the
        // model's hard input limit (sheds trailing context first — see class doc).
        return WpcomImageClient::fitToTokens(trim($prompt), WpcomImageClient::MAX_PROMPT_TOKENS);
    }
}
