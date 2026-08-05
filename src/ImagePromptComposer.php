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
 * Both context clauses are recast into purely PHOTOGRAPHIC language before
 * they reach the model (BIGR-768). Describing the image as part of a website —
 * "hero cover background on the website X, left third kept calm for overlaid
 * copy" — reads like a design-comp brief, and a typography-capable image model
 * "helpfully" completes the comp by typesetting a fake title block (wordmark,
 * tagline, gibberish URL) into the very region reserved for the site's real
 * HTML copy. So web-layout vocabulary is rewritten into photographic
 * vocabulary (PAGE_CONTEXT_RECASTS), the site is described only by its subject
 * matter — its name never appears (GenerateImagesStep::siteContext) — and the
 * no-text guard states what a reserved region IS (continuous empty scenery)
 * instead of enumerating forbidden text artifacts, which image models follow
 * unreliably as negations while the enumeration plants those very concepts
 * into the prompt.
 *
 * The IMAGE GRADE — the design direction's one-sentence photographic treatment
 * shared by ALL of the site's imagery (color vs B&W, grain, light) — IS render
 * instruction: it is appended to every prompt so independently generated images
 * read as one photographic series — EXCEPT for transparent assets: the grade
 * describes lighting and backdrop treatment ("candlelit low-key", "deep shadow
 * falloff"), which the image model paints in as a background scene, so it is omitted
 * for them.
 *
 * TRANSPARENCY is also render instruction, but the image model cannot output an alpha
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
 * endpoint as a structured parameter (GeminiImage::aspectRatio / buildBody).
 */
final class ImagePromptComposer
{
    /**
     * Web-design placement vocabulary → photographic vocabulary, applied to
     * the page context before it reaches the image model. A page context that
     * reads like a design comp ("full-bleed hero cover background with the
     * left third kept as a calm low-detail area" for overlaid copy) is the
     * audited trigger for the model typesetting a fake title block into the
     * reserved region (BIGR-768). Ordered: compound idioms rewrite before
     * their parts, and no replacement contains a term a later pattern
     * matches, so a single sequential pass is stable.
     */
    private const PAGE_CONTEXT_RECASTS = [
        // Design-comp slot idioms → photographic framing.
        '/\bhero(?:[- ](?:cover|banner|image|section))?(?:[- ]background)?\b/i' => 'wide editorial photograph',
        '/\b(?:cover|banner)[- ]background\b/i' => 'wide editorial photograph',
        '/\bbanner\b/i'                         => 'wide photograph',
        '/\bfull[- ]bleed\b/i'                  => 'full-frame',
        // Overlay/copy machinery names text; what the region it points at IS,
        // photographically, is open negative space.
        '/\bwith (?:an? |the )?text overlay\b/i'      => 'with open, low-detail negative space',
        '/\bwith (?:the )?overlaid (?:copy|text)\b/i' => 'with open, low-detail negative space',
        '/\b(?:text|copy) overlay\b/i'                => 'open, low-detail negative space',
        '/\boverlaid (?:copy|text)\b/i'               => 'open, low-detail negative space',
        // "the <site/photographer/brand> name and tagline overlaid …" — the
        // authoring rules forbid naming overlay copy, but an LLM slip here is
        // exactly the painted-wordmark trigger, so catch it too.
        '/\b(?:the |a |its )?(?:\w+ )?(?:name|headline|title|tagline|subtitle|caption|wordmark)s?'
        . '(?: and (?:the |its )?(?:\w+ )?(?:name|headline|title|tagline|subtitle|caption|wordmark)s?)?'
        . ' overlaid\b/i' => 'open, low-detail negative space kept',
        // "kept as a calm low-detail area" and variants.
        '/\b(?:an? )?calm,? low-detail area\b/i' => 'open, low-detail negative space',
    ];

    /**
     * @param string             $subject     what the image shows and from what POV
     * @param string             $pageContext where/how the image is used on the page
     * @param string             $style       one of the AI_IMAGE style keywords
     * @param string             $siteContext a terminally punctuated
     *        subject-matter sentence ("A neighborhood bakery selling sourdough
     *        and pastries." — see GenerateImagesStep::siteContext). It never
     *        names the site: a name in the prompt is what painted-in fake
     *        wordmarks stand in for (BIGR-768)
     * @param string             $imageGrade  the project-wide photographic grade
     *        from the design direction, applied to every image
     * @param bool               $transparent whether the asset needs a transparent
     *        background (a `.png` placeholder — decorative flourishes, ornaments,
     *        logo marks). the image model cannot render real transparency, so the prompt
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
        // treatment, which the image model would paint in as a background scene behind
        // the subject — exactly what the white-background keying must avoid.
        $gradeClause = ($imageGrade !== '' && !$transparent)
            ? 'Art direction for all site imagery: ' . rtrim($imageGrade, '.') . '.'
            : '';

        // Like the grade, this is a render instruction: it sits before the
        // guidance so end-trimming under token pressure never sheds it. The model
        // cannot render real alpha, so ask for the isolation it does honor — a
        // flat solid white backdrop — which is keyed out after generation.
        $transparencyClause = $transparent
            ? 'Render the subject fully isolated and centered on a plain solid pure white'
                . ' background: perfectly flat and even, with no gradients, no vignette,'
                . ' no glow, no shadows, no scenery and no backdrop of any kind.'
            : '';

        // Page and site context only steer mood/composition — they are NOT
        // drawn, so they are framed as guidance and omitted entirely when
        // there is none. Nothing here may describe the image as part of a
        // WEBSITE (BIGR-768): the page context arrives in web-design terms
        // and is recast into photographic language (PAGE_CONTEXT_RECASTS),
        // and the site context is already a subject-matter sentence with the
        // site name deliberately withheld (GenerateImagesStep::siteContext).
        $pageContext = rtrim(self::photographicPageContext($pageContext), '.');
        if ($pageContext !== '' && $siteContext !== '') {
            $where = "Composition: {$pageContext}. {$siteContext}";
        } elseif ($pageContext !== '') {
            $where = "Composition: {$pageContext}.";
        } else {
            $where = $siteContext;
        }
        // The no-text guard precedes the context sentence so end-trimming
        // under token pressure sheds the trigger (the context) before the
        // guard. It is phrased POSITIVELY — what a reserved region IS,
        // continuous empty scenery — because image models follow negations
        // unreliably, and an enumeration of forbidden artifacts ("headlines,
        // watermarks, logos") plants those very concepts into the prompt.
        $guidance = $where === '' ? '' : 'Purely pictorial imagery: every part of'
            . ' the frame is the scene itself, and any region described below as'
            . ' open, calm or low-detail is continuous unbroken scenery — open'
            . ' sky, plain wall, still water, bare ground or soft-focus depth —'
            . ' left completely empty. The notes below steer subject, mood and'
            . ' composition only and are never depicted literally: '
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
        return GeminiImage::fitToTokens($prompt, GeminiImage::MAX_PROMPT_TOKENS);
    }

    /**
     * Recast one page context from web-design vocabulary into photographic
     * vocabulary (PAGE_CONTEXT_RECASTS): "full-bleed hero cover background
     * with the left third kept as a calm low-detail area" becomes "full-frame
     * wide editorial photograph with the left third kept as open, low-detail
     * negative space".
     */
    private static function photographicPageContext(string $pageContext): string
    {
        return trim((string) preg_replace(
            array_keys(self::PAGE_CONTEXT_RECASTS),
            array_values(self::PAGE_CONTEXT_RECASTS),
            $pageContext
        ));
    }
}
