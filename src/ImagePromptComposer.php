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
 * Both context clauses are recast into purely PICTORIAL guidance before they
 * reach the model (BIGR-768). Describing the image as part of a website —
 * "hero cover background on the website X, left third kept calm for overlaid
 * copy" — reads like a design-comp brief, and a typography-capable image model
 * "helpfully" completes the comp by typesetting a fake title block (wordmark,
 * tagline, gibberish URL) into the very region reserved for the site's real
 * HTML copy. So web-layout vocabulary is rewritten into pictorial
 * vocabulary: the generated page-context prose is reduced to a closed set of
 * pictorial placement facts (matching-set role and reserved negative-space
 * region), the site is described only by safe subject
 * matter — its identity never appears (GenerateImagesStep::siteContext) — and
 * the no-text guard states what a reserved region IS (continuous empty scenery)
 * instead of enumerating forbidden text artifacts, which image models follow
 * unreliably as negations while the enumeration plants those very concepts
 * into the prompt.
 *
 * The same literalization risk applies to PRINT vocabulary (BIGR-956): a
 * prompt that stacks "frame", "contained" and a repeated "series" of
 * photographs over a film grade sometimes comes back with a painted-in white
 * print border. Placement and crop guidance therefore describe the scene as
 * filling its canvas to all four edges, and never name frames, borders or a
 * series of photographs.
 *
 * The SUBJECT itself is the remaining text vector (BIGR-781): a subject whose
 * focal object is a text carrier — a storefront, sign, menu board, screen,
 * placard — invites the model to complete that carrier with garbled fake
 * lettering, at worst a wrong brand name painted over the site's own shopfront.
 * When (and only when) the subject names such a carrier, a lettering clause is
 * appended stating positively how those surfaces appear: distant, oblique or
 * defocused enough to read as shapes and texture. It is conditional because
 * on a clean subject the clause itself would plant the signage concept the
 * guard exists to keep out.
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
 * The request's exact aspect ratio remains a structured endpoint parameter.
 * A bounded site-wide image-crop commitment may add composition guidance so
 * independently generated subjects keep their focal content inside the same
 * recurring frame shape without restating the numeric ratio in prose.
 */
final class ImagePromptComposer
{
    /**
     * Orientation anchor for every opaque image. Short on purpose: it shares
     * the GeminiImage::MAX_PROMPT_TOKENS budget with the subject.
     */
    public const ORIENTATION_CLAUSE = 'Orientation: the view is upright, with the top of the scene'
        . ' at the top of the canvas and any visible horizon level.';

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
     * @param string             $imageCrop the bounded site-wide proportion
     *        commitment; mixed/absent adds no textual steering because each
     *        image role keeps its own authored ratio
     */
    public static function compose(
        string $subject,
        string $pageContext,
        string $style,
        string $siteContext = '',
        string $imageGrade = '',
        bool $transparent = false,
        ?PromptRenderer $renderer = null,
        string $imageCrop = '',
    ): string {
        $renderer ??= new PromptRenderer(Package::promptsDir());

        $subject     = trim($subject);
        $style       = trim($style);
        $pageContext = trim($pageContext);
        $siteContext = trim($siteContext);
        $imageGrade  = trim($imageGrade);
        if ($imageGrade !== '' && !$transparent) {
            $subject = self::stripCompetingGradeTokens($subject, $imageGrade)['subject'];
        }

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

        // The structured ratio still owns the canvas. This site-wide clause
        // only protects focal content for the recurring crop system, and is
        // meaningless for transparent assets whose keyed canvas is trimmed.
        $cropClause = $transparent ? '' : ImageCrop::promptClause($imageCrop);

        // The structured ratio sets the canvas shape but says nothing about
        // what is up (BIGR-979). Asked for a lateral copy reservation on a
        // scene that is organized top to bottom, the model once complied by
        // turning a portrait composition 90° inside the landscape canvas. This
        // render instruction anchors the orientation for every scene; a
        // transparent asset is an isolated object with no horizon to level.
        // "Canvas", never "frame" (BIGR-956).
        $orientationClause = $transparent ? '' : self::ORIENTATION_CLAUSE;

        // A subject naming a text carrier gets a render instruction stating how
        // such surfaces appear (BIGR-781). Conditional: on a clean subject the
        // clause would plant the very signage concept it constrains. Transparent
        // assets use a dedicated clause because their carrier is the isolated
        // focal subject rather than set dressing in a scene.
        $letteringClause = '';
        if (self::subjectNamesTextCarrier($subject)) {
            $letteringClause = $transparent
                ? 'The isolated subject has a plain, unmarked material surface; its'
                    . ' form is conveyed only through shape, color and texture.'
                : 'Any sign, board, screen or printed surface in the scene is quiet'
                    . ' set dressing: its face is unmarked — bare wood, clear glass,'
                    . ' dark glass or blank chalk — or kept so distant, obliquely angled'
                    . ' or softly out of focus that it reads as simple shapes, glare and'
                    . ' texture, and the image tells its story through form, light and'
                    . ' color alone.';
        }

        // Page and site context only steer mood/composition — they are NOT
        // drawn, so they are framed as guidance and omitted entirely when
        // there is none. Nothing here may describe the image as part of a
        // WEBSITE (BIGR-768): the page context arrives as generated prose, so
        // pictorialPageContext keeps only a closed set of placement facts
        // and never forwards the prose itself. The site context is already a
        // safe subject-matter sentence with identity deliberately withheld
        // (GenerateImagesStep::siteContext).
        $pageContext = rtrim(self::pictorialPageContext($pageContext, $style, $transparent), '.');
        if ($pageContext !== '' && $siteContext !== '') {
            $where = "Composition: {$pageContext}. {$siteContext}";
        } elseif ($pageContext !== '') {
            $where = "Composition: {$pageContext}.";
        } else {
            $where = $siteContext;
        }
        // The no-text guard precedes the context sentence so end-trimming
        // under token pressure sheds the context before the guard. Opaque
        // images describe reserved regions positively as continuous scenery.
        // Transparent assets need a different positive guard: telling those
        // prompts that every part of the frame is scenery contradicts the
        // flat-white isolation that ImageTransparency depends on.
        if ($where === '') {
            $guidance = '';
        } elseif ($transparent) {
            $guidance = 'Purely pictorial isolated asset: only the subject occupies'
                . ' the frame, and the surrounding field remains flat, even, empty'
                . ' pure white. The notes below steer the subject and its composition'
                . ' only and are never depicted literally: '
                . $where;
        } else {
            // "Canvas", never "frame" (BIGR-956): the model sometimes paints
            // "frame" literally as a white print border. The edge sentence is
            // the positive form of "no border" — it states what the edges ARE.
            $guidance = 'Purely pictorial imagery: the scene itself fills every'
                . ' part of the canvas and reaches all four edges, and any region'
                . ' described below as open, calm or'
                . ' low-detail is continuous unbroken scenery — open sky, plain wall,'
                . ' still water, bare ground or soft-focus depth — left completely'
                . ' empty. The notes below steer subject, mood and composition only'
                . ' and are never depicted literally: '
                . $where;
        }

        $prompt = $renderer->render('image-prompt.md', [
            'subject'             => $subject,
            'style_clause'        => $styleClause,
            'grade_clause'        => $gradeClause,
            'crop_clause'         => $cropClause,
            'orientation_clause'  => $orientationClause,
            'transparency_clause' => $transparencyClause,
            'lettering_clause'    => $letteringClause,
            'guidance'            => $guidance,
        ]);

        // Empty clauses leave stacked blank lines in the template; collapse them,
        // normalise the surrounding whitespace, then cap to the model's hard input
        // limit (sheds trailing context first — see class doc).
        $prompt = (string) preg_replace("/\n{3,}/", "\n\n", trim($prompt));
        return GeminiImage::fitToTokens($prompt, GeminiImage::MAX_PROMPT_TOKENS);
    }

    /**
     * Photographic-grade vocabulary a subject must never carry.
     *
     * prompts/image-generation.md:63 states the rule unconditionally: the
     * subject describes content and composition, never grade or style
     * treatment, because one site-wide grade is appended to every image. So
     * this list is not keyed on what the grade happens to say. Keying it that
     * way both disarmed on a legally-worded grade ("muted pastel color, soft
     * even daylight" left "no grain, catalog-lit" standing) and read negations
     * backwards, treating a subject that agreed with the grade as fighting it.
     */
    private const GRADE_VOCABULARY = [
        'grain', 'grainy', 'grainless', 'film', 'film grain', 'filmic', '35mm', 'portra', 'kodachrome',
        'tri-x', 'black and white', 'b&w', 'monochrome', 'greyscale', 'grayscale',
        'desaturated', 'muted grey tones', 'muted gray tones', 'sepia', 'duotone',
        'golden hour', 'available light', 'studio white', 'seamless white', 'cyclorama',
        'sweep', 'catalog-lit', 'catalog lit', 'flash-hard', 'hard on-camera flash',
        'digitally clean', 'colour graded', 'color graded', 'color grading',
        'saturated neon', 'neon-soaked', 'hyper-saturated', 'vivid neon',
        'high-contrast', 'low-contrast', 'washed out', 'crushed blacks',
    ];

    /**
     * Words that carry no scene content, so a clause left holding only these
     * after its grade vocabulary is removed was never describing anything.
     *
     * The second group is how much of the grade, not what the scene is: real
     * subjects write "fine 35mm grain" and "heavy 35mm film grain" far more
     * often than the bare term, and without these an adjective alone kept the
     * clause. They only ever apply to a clause that already matched grade
     * vocabulary, so a scene noun beside one ("a monochrome colour chart")
     * still holds the clause.
     */
    private const FILLER_WORDS = [
        'a', 'an', 'the', 'no', 'not', 'without', 'with', 'of', 'on', 'in', 'at', 'to',
        'and', 'or', 'but', 'for', 'from', 'by', 'over', 'under', 'into', 'onto',
        'is', 'are', 'was', 'were', 'be', 'all', 'its', 'it', 'very', 'quite', 'rather',
        'shot', 'shots', 'image', 'images', 'photo', 'photos', 'photograph',
        'photographed', 'rendered', 'look', 'style', 'treatment', 'tone', 'tones',
        'fine', 'faint', 'subtle', 'gentle', 'soft', 'heavy', 'strong', 'deep', 'hard',
        'visible', 'slight', 'slightly', 'strictly', 'natural', 'warm', 'cool',
        'color', 'colour', 'grade', 'graded', 'grading', 'lighting', 'lit',
    ];

    /**
     * Remove clauses of the subject that say nothing but photographic grade.
     *
     * Whole clauses only. Erasing matched words in place left grammar behind
     * and, worse, silently changed the scene — "a studio white sweep" became
     * "a sweep", a different backdrop sent to the image service. So a clause is
     * dropped only when grade vocabulary is all it holds; a clause that also
     * names something real is kept byte-for-byte and reported instead, and if
     * every clause would go the original subject is returned untouched.
     *
     * The vocabulary is English, matching prompts/image-generation.md's own
     * examples. On a site written in another language nothing matches and this
     * is a no-op, which is why an unedited subject still reports what it saw.
     *
     * @return array{subject:string,removed:list<string>,kept:list<string>}
     */
    public static function stripCompetingGradeTokens(string $subject, string $imageGrade): array
    {
        $original = trim($subject);
        if ($original === '') {
            return ['subject' => $original, 'removed' => [], 'kept' => []];
        }

        $clauses = self::splitClauses($original);
        if ($clauses === []) {
            // Nothing could be read — punctuation only, or bytes preg_split
            // rejected. No clause was examined, so there is no conflict to
            // report and nothing to deliver but what was authored.
            return ['subject' => $original, 'removed' => [], 'kept' => []];
        }

        $matchesPer = array_map(self::gradeVocabularyIn(...), $clauses);

        $removed = [];
        $kept = [];
        $survivors = [];
        foreach ($clauses as $index => $clause) {
            $matches = $matchesPer[$index];
            if ($matches === []) {
                $survivors[] = $clause;
                continue;
            }
            $isGrade = self::namesGradeOutright($matches) || self::negatesGrade($clause);
            if ($isGrade && self::clauseIsOnlyGrade($clause, $matches)) {
                $removed[] = trim($clause['text']);
                continue;
            }
            // Grade wording woven into a phrase that also names the scene.
            // There is no edit here that does not risk changing the subject.
            $kept[] = trim($clause['text']);
            $survivors[] = $clause;
        }

        if ($survivors === []) {
            // The subject was grade talk end to end. Delivering an empty
            // subject is worse than delivering the authored one.
            return ['subject' => $original, 'removed' => [], 'kept' => [$original]];
        }

        if ($removed === []) {
            // Nothing was dropped, so there is nothing to rejoin around.
            // Rebuilding anyway re-punctuates every other clause, and with no
            // removal there is no authored-vs-delivered row to record it —
            // the kept row would assert "delivered unchanged" over a subject
            // we had just changed.
            return ['subject' => $original, 'removed' => [], 'kept' => $kept];
        }

        return [
            'subject' => self::joinClauses($survivors),
            'removed' => $removed,
            'kept' => $kept,
        ];
    }

    /**
     * Split a subject into clauses, keeping each one's trailing separator so
     * the surviving text can be rejoined without inventing punctuation.
     *
     * @return list<array{text:string,separator:string}>
     */
    private static function splitClauses(string $subject): array
    {
        $parts = preg_split('/([,;]|\.\s+)/u', $subject, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $clauses = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $text = $parts[$i];
            if (trim($text) === '') {
                continue;
            }
            $clauses[] = ['text' => $text, 'separator' => $parts[$i + 1] ?? ''];
        }
        return $clauses;
    }

    /** @param list<array{text:string,separator:string}> $clauses */
    private static function joinClauses(array $clauses): string
    {
        $out = '';
        foreach ($clauses as $index => $clause) {
            $out .= trim($clause['text']);
            if ($index === count($clauses) - 1) {
                continue;
            }
            $separator = trim($clause['separator']);
            $out .= ($separator === '' || $separator === '.') ? '. ' : $separator . ' ';
        }
        return trim($out, " \t,;");
    }

    /**
     * The grade terms a clause contains, longest first so a multi-word term is
     * consumed before one of its own words matches.
     *
     * @param array{text:string,separator:string} $clause
     * @return list<string>
     */
    private static function gradeVocabularyIn(array $clause): array
    {
        $terms = self::GRADE_VOCABULARY;
        usort($terms, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $found = [];
        foreach ($terms as $term) {
            if (preg_match(self::termPattern($term), $clause['text']) === 1) {
                $found[] = $term;
            }
        }
        return $found;
    }

    /**
     * A whole-word pattern for one grade term. Any Unicode dash or horizontal
     * space stands in for the separators, so "neon-soaked" with a real em dash
     * and "no-grain" with a non-breaking hyphen match the same as plain ASCII.
     */
    private static function termPattern(string $term): string
    {
        $separator = '(?:\h|\p{Pd})+';
        $escaped = array_map(
            static fn (string $word): string => preg_quote($word, '/'),
            preg_split('/[\s-]+/u', $term) ?: [$term],
        );
        return '/(?<![\w-])' . implode($separator, $escaped) . '(?![\w-])/iu';
    }

    /**
     * Terms that also name ordinary subject matter: wood and leather have a
     * grain, a landscape has a sweep, a cat is black and white. On their own
     * they are not enough to call a clause grade talk — "fine grain" is a
     * walnut finish as often as a film stock. A clause carrying only these is
     * kept and reported instead of dropped.
     */
    private const AMBIGUOUS_VOCABULARY = [
        'grain', 'grainy', 'grainless', 'film', 'sweep', 'black and white',
    ];

    /**
     * Whether a clause's matches include a term that can only be photographic.
     * "fine film grain" says film stock; "fine grain" alone does not.
     *
     * @param list<string> $matches
     */
    private static function namesGradeOutright(array $matches): bool
    {
        foreach ($matches as $term) {
            if (!in_array($term, self::AMBIGUOUS_VOCABULARY, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a clause negates its grade term. "no grain" and "without grain"
     * only mean anything about a photograph — material is never described by
     * the grain it lacks — so a negated ambiguous term is grade talk on its own.
     *
     * @param array{text:string,separator:string} $clause
     */
    private static function negatesGrade(array $clause): bool
    {
        return preg_match('/(?<![\w-])(no|not|without|never)(?![\w-])/iu', $clause['text']) === 1;
    }

    /**
     * Whether removing the grade terms leaves a clause with nothing that names
     * anything — the test for "this clause was only grade talk".
     *
     * @param array{text:string,separator:string} $clause
     * @param list<string> $matches
     */
    private static function clauseIsOnlyGrade(array $clause, array $matches): bool
    {
        $residue = $clause['text'];
        foreach ($matches as $term) {
            $replaced = preg_replace(self::termPattern($term), ' ', $residue);
            if ($replaced === null) {
                // The residue could not be computed, so "nothing is left" is
                // not something we know. Keep the clause: the sibling match
                // above already fails this way, and the alternative is
                // deleting a scene we never managed to read.
                return false;
            }
            $residue = $replaced;
        }
        $residue = strtolower((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $residue));
        foreach (preg_split('/\s+/u', trim($residue)) ?: [] as $word) {
            if ($word !== '' && !in_array($word, self::FILLER_WORDS, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the authored subject names a text-carrying object — the surfaces
     * the image model reliably completes with garbled fake lettering
     * (BIGR-781). Subjects are written in the site's language, so common
     * Spanish carrier nouns are matched alongside English, mirroring the
     * page-context matchers above. Deliberately a noun allowlist, not prose
     * analysis: a false positive only adds a harmless render instruction,
     * while enumerating carriers unconditionally would plant the concept
     * into clean prompts.
     */
    private static function subjectNamesTextCarrier(string $subject): bool
    {
        return preg_match(
            '/\b(?:signs?|signages?|signboards?|storefronts?|shop\s?fronts?|facades?|fa[çc]ades?'
            . '|marquees?|billboards?|posters?|placards?|banners?|plaques?|awnings?'
            . '|men[uú]s?|menu\s?boards?|chalkboards?|blackboards?|whiteboards?'
            . '|screens?|smartphones?|phones?|tablets?|laptops?|monitors?|dashboards?'
            . '|newspapers?|magazines?|books?|labels?|packagings?|record\s?sleeves?|album\s?covers?'
            . '|letreros?|carteles?|pancartas?|r[oó]tulos?|vallas?|marquesinas?|toldos?'
            . '|pizarras?|pantallas?|tel[eé]fonos?|m[oó]viles?|peri[oó]dicos?|revistas?'
            . '|libros?|etiquetas?|placas?|fachadas?|portadas?|escaparates?)\b/iu',
            $subject
        ) === 1;
    }

    /**
     * Reduce one generated page context to a closed pictorial vocabulary.
     * The source prose never reaches the image model: arbitrary identity, UI
     * copy, possessives and non-English design-comp wording therefore cannot
     * leak through a partial regex rewrite. Only bounded placement facts are
     * recovered. Unknown prose is harmlessly omitted; subject and structured
     * aspect ratio remain the authoritative render instructions.
     */
    private static function pictorialPageContext(string $pageContext, string $style, bool $transparent): string
    {
        $pageContext = trim($pageContext);
        if ($pageContext === '') {
            return '';
        }

        // A transparent asset's canvas is trimmed after its flat background is
        // keyed out, so page-slot geometry and reserved in-frame space are not
        // meaningful. Keep only the safe fact that this is an isolated asset.
        if ($transparent) {
            return 'isolated pictorial asset';
        }

        // No placement adjective (BIGR-958). An A/B run measured "full-frame"
        // and "compact" changing nothing the adjectives were meant to steer:
        // copy-reservation adherence and thumbnail composition were identical
        // with and without them. The negative-space clause below carries the
        // reservation, the structured aspect ratio owns the canvas, and
        // "full-frame" was the last "frame" token the builder emitted
        // (BIGR-956). Slot wording still matters elsewhere: the ratio choice
        // reads it in ImageCrop::generationRatio.
        // Orientation likewise comes from the structured aspect-ratio
        // parameter. Page prose is untrusted and sometimes calls a subject a
        // "portrait" even when the requested canvas is landscape, so it must
        // not compete with the actual generation setting here.
        $result = preg_match('/\b(?:photorealistic|photo|photographic)\b/i', $style) === 1
            ? 'editorial photograph'
            : 'pictorial composition';
        if (preg_match(
            '/\b(?:grid|gallery|row|series|sequence|collection|matching\s+scenes'
            . '|grilla|cuadrícula|galería|fila|serie|secuencia|colección)\b/iu',
            $pageContext
        ) === 1) {
            // Not "within a repeated image series" (BIGR-956): a "series" of
            // "photographs" plus a film grade evokes a contact sheet, and the
            // model sometimes paints the print border between its frames. Say
            // the sibling-consistency intent in scene vocabulary instead.
            $result .= ', one of a set of matching scenes composed alike';
        }

        $negativeSpaceCue = self::negativeSpaceCue($pageContext);
        if ($negativeSpaceCue !== null) {
            $result .= ' with ' . self::negativeSpaceRegion($pageContext, $negativeSpaceCue)
                . ' kept as open, low-detail negative space';
        }
        return $result;
    }

    /**
     * Locate an explicit empty-space phrase or copy element with an overlay
     * verb. Bare adjectives and placement verbs are deliberately insufficient:
     * "open-air market", "calm-water", "subject floating" and "overlapping
     * photographs" describe image content, not an HTML-copy reservation.
     *
     * @return array{offset:int,length:int}|null Byte offsets into $pageContext.
     */
    private static function negativeSpaceCue(string $pageContext): ?array
    {
        $regionNoun = '(?:negative[- ]spaces?|areas?|spaces?|regions?|sk(?:y|ies)|walls?|water|ground'
            . '|depth|backgrounds?|backdrops?|fields?|scenery|surfaces?|concrete)';
        $direction = '(?:upper|lower|top|bottom|left|right|center|centre|middle)'
            . '(?:[- ](?:left|right))?(?:\s+(?:edge|side|third|area|corner|quadrant))?';
        $explicit = '/\b(?:negative[- ]spaces?'
            . '|(?:open|calm)\s*(?:,|and)?\s+low[- ]detail\s+'
            . '(?:(?:dark|light|plain|quiet|empty)\s+)?' . $regionNoun
            . '|low[- ]detail\s+(?:(?:dark|light|plain|quiet|empty)\s+)?' . $regionNoun
            . '|(?:open|calm)\s+(?:and\s+)?low(?:\s+in)?[- ]detail\s+'
            . '(?:toward|along|at|on)\s+(?:the\s+)?' . $direction
            . '|(?:open|calm)\s+(?:dark\s+)?(?:space|area|region)\s+'
            . '(?:toward|along|at|on)\s+(?:the\s+)?' . $direction
            . '|(?:empty|clear)\s+(?:areas?|spaces?|regions?)'
            . '|espacios?\s+negativos?|(?:zonas?|áreas?)\s+vacías?)\b/iu';
        if (preg_match($explicit, $pageContext, $match, PREG_OFFSET_CAPTURE) === 1) {
            if (!self::termIsNegated($pageContext, $match[0][1])) {
                return ['offset' => $match[0][1], 'length' => strlen($match[0][0])];
            }
        }

        $copy = '(?:names?|headlines?|headings?|titles?|taglines?|subtitles?|captions?|wordmarks?'
            . '|datelines?|eyebrows?|sublines?|texts?|cop(?:y|ies)|emails?|buttons?|ctas?'
            . '|nombres?|titular(?:es)?|encabezados?|títulos?|subtítulos?|textos?|fechas?'
            . '|correos?|bot(?:ón|ones))';
        $overlay = '(?:overlay(?:s|ed|ing)?|overlaid|overlap(?:s|ped|ping)?|pinned|floating'
            . '|anchored|layered|sits?|sitting|stacked|superpuest[oa]s?|solapad[oa]s?'
            . '|apilad[oa]s?)';
        $visualNoun = '(?:images?|photos?|photographs?|portraits?|subjects?|objects?|figures?'
            . '|persons?|people|imagen|imágenes|fotos?|fotografías?|retratos?|sujetos?'
            . '|objetos?|figuras?|personas?)';
        $adjacency = '(?:beside|next\s+to|adjacent(?:\s+to)?|near|outside|below|beneath|under'
            . '|above|opposite|junto\s+a|al\s+lado\s+de|cerca\s+de|debajo\s+de|bajo)';

        // Pair the nearest copy noun and placement verb in one short clause.
        // Inspecting the bounded substring procedurally avoids a large repeated
        // lookahead (which exceeds older PCRE2 compile limits) and lets us
        // reject visual nouns or adjacency language only BETWEEN the pair.
        $blocker = '/\b(?:' . $visualNoun . '|' . $adjacency . ')\b/iu';
        if (preg_match_all(
            '/\b' . $overlay . '\b/iu',
            $pageContext,
            $overlayMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            foreach ($overlayMatches as $overlayMatch) {
                $verb = $overlayMatch[0][0];
                $verbOffset = $overlayMatch[0][1];

                $beforeStart = max(0, $verbOffset - 120);
                $before = substr($pageContext, $beforeStart, $verbOffset - $beforeStart);
                $beforeParts = preg_split('/[,;.!?\n]/u', $before);
                $beforeClause = (string) ($beforeParts === false ? '' : end($beforeParts));
                $clauseStart = $verbOffset - strlen($beforeClause);
                if (preg_match_all(
                    '/\b' . $copy . '\b/iu',
                    $beforeClause,
                    $copyMatches,
                    PREG_SET_ORDER | PREG_OFFSET_CAPTURE
                )) {
                    $copyMatch = end($copyMatches);
                    $copyEnd = $copyMatch[0][1] + strlen($copyMatch[0][0]);
                    $between = substr($beforeClause, $copyEnd);
                    $offset = $clauseStart + $copyMatch[0][1];
                    $copyPrefix = substr($beforeClause, 0, $copyMatch[0][1]);
                    $verbTail = substr($pageContext, $verbOffset + strlen($verb), 48);
                    if (
                        !self::termIsNegated($pageContext, $offset)
                        && !self::termIsNegated($pageContext, $verbOffset)
                        && !self::containsNegation($between)
                        && preg_match('/\b' . $adjacency . '\b/iu', $copyPrefix) !== 1
                        && preg_match($blocker, $between) !== 1
                        && preg_match('/^\s*' . $adjacency . '\b/iu', $verbTail) !== 1
                    ) {
                        return [
                            'offset' => $offset,
                            'length' => $verbOffset + strlen($verb) - $offset,
                        ];
                    }
                }

                $after = substr($pageContext, $verbOffset + strlen($verb), 80);
                $afterParts = preg_split('/[,;.!?\n]/u', $after, 2);
                $afterClause = (string) ($afterParts[0] ?? '');
                if (preg_match(
                    '/\b' . $copy . '\b/iu',
                    $afterClause,
                    $copyMatch,
                    PREG_OFFSET_CAPTURE
                ) === 1) {
                    $between = substr($afterClause, 0, $copyMatch[0][1]);
                    $copyOffset = $verbOffset + strlen($verb) + $copyMatch[0][1];
                    if (
                        !self::termIsNegated($pageContext, $verbOffset)
                        && !self::termIsNegated($pageContext, $copyOffset)
                        && !self::containsNegation($between)
                        && preg_match($blocker, $between) !== 1
                    ) {
                        return [
                            'offset' => $verbOffset,
                            'length' => strlen($verb) + $copyMatch[0][1] + strlen($copyMatch[0][0]),
                        ];
                    }
                }
            }
        }

        // Copy and images can deliberately be layered together. This bounded
        // coordinated form permits the visual noun that the general matcher
        // rejects, without making an arbitrary floating subject an overlay.
        $coordinated = '/\b' . $copy . '\b[^,;.!?\n]{0,32}\b(?:and|with|y|con)\b'
            . '[^,;.!?\n]{0,48}\b'
            . '(?:images?|photos?|photographs?|imágenes?|fotos?|fotografías?)\b'
            . '[^,;.!?\n]{0,32}\b(?:layered|stacked|superpuest[oa]s?|apilad[oa]s?)\b/iu';
        if (preg_match($coordinated, $pageContext, $match, PREG_OFFSET_CAPTURE) === 1) {
            if (
                !self::termIsNegated($pageContext, $match[0][1])
                && !self::containsNegation($match[0][0])
                && preg_match('/\b' . $adjacency . '\b/iu', $match[0][0]) !== 1
            ) {
                return ['offset' => $match[0][1], 'length' => strlen($match[0][0])];
            }
        }

        $position = '(?:(?:upper|lower|top|bottom)\s*[- ]?)?(?:left|right)'
            . '(?:\s+(?:side|third|area|edge|corner|quadrant))?'
            . '|(?:upper|lower|top|bottom|center|centre|middle)(?:\s+(?:area|edge|third))?'
            . '|(?:tercio\s+)?(?:superior|inferior)?\s*(?:izquierd[oa]|derech[oa])'
            . '|(?:arriba|abajo|centro)(?:\s+a\s+la\s+(?:izquierda|derecha))?';

        // Explicitly reserved regions and terse "headline on the upper right"
        // shorthands are safe to recover because the region and copy are both
        // present in one bounded clause.
        $reservationAction = '/\b(?:kept\s+(?:as\s+)?(?:clear|empty|open)'
            . '|left\s+(?:clear|empty)|reserved\s+for\s+(?:copy|text))\b/iu';
        if (preg_match_all(
            $reservationAction,
            $pageContext,
            $reservationMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            foreach ($reservationMatches as $reservationMatch) {
                $action = $reservationMatch[0][0];
                $actionOffset = $reservationMatch[0][1];
                if (self::termIsNegated($pageContext, $actionOffset)) {
                    continue;
                }
                $beforeStart = max(0, $actionOffset - 96);
                $before = substr($pageContext, $beforeStart, $actionOffset - $beforeStart);
                $beforeParts = preg_split('/[,;.!?\n]/u', $before);
                $beforeClause = (string) ($beforeParts === false ? '' : end($beforeParts));
                $clauseStart = $actionOffset - strlen($beforeClause);
                if (preg_match_all(
                    '/\b(?:' . $position . ')\b/iu',
                    $beforeClause,
                    $positionMatches,
                    PREG_SET_ORDER | PREG_OFFSET_CAPTURE
                )) {
                    $positionMatch = end($positionMatches);
                    $offset = $clauseStart + $positionMatch[0][1];
                    return [
                        'offset' => $offset,
                        'length' => $actionOffset + strlen($action) - $offset,
                    ];
                }
            }
        }

        $copyPosition = '/\b' . $copy . '\b[^,;.!?\n]{0,64}\b'
            . '(?:on|at|in|across|along|en)\s+(?:(?:the|el|la)\s+)?(?:' . $position . ')\b/iu';
        if (preg_match($copyPosition, $pageContext, $match, PREG_OFFSET_CAPTURE) === 1) {
            $prefixStart = max(0, $match[0][1] - 64);
            $prefix = substr($pageContext, $prefixStart, $match[0][1] - $prefixStart);
            $prefixParts = preg_split('/[,;.!?\n]/u', $prefix);
            $prefix = (string) ($prefixParts === false ? '' : end($prefixParts));
            $suffix = substr($pageContext, $match[0][1] + strlen($match[0][0]), 64);
            $suffixParts = preg_split('/[,;.!?\n]/u', $suffix, 2);
            $suffix = (string) ($suffixParts[0] ?? '');
            if (
                !self::termIsNegated($pageContext, $match[0][1])
                && !self::containsNegation($match[0][0])
                && preg_match('/\b' . $adjacency . '\b/iu', $prefix) !== 1
                && preg_match('/\b' . $adjacency . '\b/iu', $suffix) !== 1
            ) {
                return ['offset' => $match[0][1], 'length' => strlen($match[0][0])];
            }
        }

        $copyOnSurface = '/\b' . $copy . '\b[^,;.!?\n]{0,64}\b'
            . '(?:over|on|sobre)\s+(?:(?:the|el|la|una?)\s+)?'
            . '(?:images?|photos?|photographs?|sky|background|backdrop|imagen|imágenes|fotos?|fondo)\b'
            . '|\b' . $copy . '\b[^,;.!?\n]{0,64}\bencima\b/iu';
        if (preg_match($copyOnSurface, $pageContext, $match, PREG_OFFSET_CAPTURE) === 1) {
            if (
                !self::termIsNegated($pageContext, $match[0][1])
                && !self::containsNegation($match[0][0])
            ) {
                return ['offset' => $match[0][1], 'length' => strlen($match[0][0])];
            }
        }

        return null;
    }

    /**
     * Return one canonical region associated with a matched empty-space cue.
     * Directions following the cue win; otherwise only its local preceding
     * clause is inspected. This keeps a focal subject on the opposite side
     * from being mistaken for the reserved region.
     *
     * @param array{offset:int,length:int} $cue
     */
    private static function negativeSpaceRegion(string $pageContext, array $cue): string
    {
        $after = substr($pageContext, $cue['offset'] + $cue['length']);
        $after = (string) (preg_split(
            '/[,;.!?\n]|\b(?:while|whereas|but|mientras|pero'
            . '|and\s+(?:a|an|the)|y\s+(?:un|una|el|la))\b/iu',
            $after,
            2
        )[0] ?? '');
        $region = self::regionInText($after);
        if ($region !== null) {
            return $region;
        }

        $matchedCue = substr($pageContext, $cue['offset'], $cue['length']);
        $region = self::regionInText($matchedCue);
        if ($region !== null) {
            return $region;
        }

        $before = substr($pageContext, 0, $cue['offset']);
        $clauses = preg_split(
            '/[,;.!?\n]|\b(?:with|while|whereas|but|con|mientras|pero)\b/iu',
            $before
        );
        $before = (string) ($clauses === false ? '' : end($clauses));
        return self::regionInText($before) ?? 'a reserved area';
    }

    /** Whether a matched cue term is explicitly negated immediately before it. */
    private static function termIsNegated(string $pageContext, int $termOffset): bool
    {
        $start = max(0, $termOffset - 48);
        $prefix = substr($pageContext, $start, $termOffset - $start);
        return preg_match(
            '/\b(?:no|not|never|sin|without)\b'
            . '(?:\s+[\p{L}\p{N}-]+){0,3}\s*$/iu',
            $prefix
        ) === 1;
    }

    /** Whether a bounded cue phrase contains an explicit negation. */
    private static function containsNegation(string $text): bool
    {
        return preg_match('/\b(?:no|not|never|without|sin)\b/iu', $text) === 1;
    }

    /** Return a canonical region found in a cue-local text fragment. */
    private static function regionInText(string $text): ?string
    {
        // "Layered on top" describes z-order, not the upper edge. Remove that
        // idiom while retaining location phrases such as "on the top edge".
        $text = (string) preg_replace(
            '/\bon\s+(?:the\s+)?top(?![- ](?:edge|left|right|side|corner|third|quadrant))\b/iu',
            '',
            $text
        );
        $left = preg_match('/\b(?:left(?!\s+(?:clear|empty)\b)|izquierd[oa])\b/iu', $text) === 1;
        $right = preg_match('/\b(?:right|derech[oa])\b/iu', $text) === 1;
        $lower = preg_match(
            '/\b(?:lower|bottom|low(?![- ]detail|\s+in\s+detail)|inferior|abajo|baj[oa])\b/iu',
            $text
        ) === 1;
        $upper = preg_match('/\b(?:upper|top|superior|arriba|alt[oa])\b/iu', $text) === 1;
        $third = preg_match('/\b(?:third|tercio)\b/iu', $text) === 1;

        if ($left && $lower) {
            return $third ? 'the lower-left third' : 'the lower-left area';
        }
        if ($right && $lower) {
            return $third ? 'the lower-right third' : 'the lower-right area';
        }
        if ($left && $upper) {
            return $third ? 'the upper-left third' : 'the upper-left area';
        }
        if ($right && $upper) {
            return $third ? 'the upper-right third' : 'the upper-right area';
        }
        if ($left) {
            return $third ? 'the left third' : 'the left side';
        }
        if ($right) {
            return $third ? 'the right third' : 'the right side';
        }
        if ($lower) {
            return 'the lower area';
        }
        if ($upper) {
            return 'the upper area';
        }
        if (preg_match('/\b(?:center|centre|middle|centro|central)\b/iu', $text) === 1) {
            return 'the center';
        }
        return null;
    }
}
