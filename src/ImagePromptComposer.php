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
 * pictorial placement facts (frame, repeated-series role and reserved
 * negative-space region), the site is described only by safe subject
 * matter — its identity never appears (GenerateImagesStep::siteContext) — and
 * the no-text guard states what a reserved region IS (continuous empty scenery)
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
            $guidance = 'Purely pictorial imagery: every part of the frame is the'
                . ' scene itself, and any region described below as open, calm or'
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

        $edgeToEdge = preg_match(
            '/\b(?:full[- ](?:bleed|frame|width)|edge[- ]bleeding|edge[- ]to[- ]edge)\b'
            . '|\ba\s+sangre(?:\s+completa)?\b/iu',
            $pageContext
        ) === 1;
        $compact = !$edgeToEdge && preg_match(
            '/\b(?:compact|thumbnail|small|miniature|miniatura|accent|pequeñ[oa])\b/iu',
            $pageContext
        ) === 1;
        $contained = !$edgeToEdge && !$compact && preg_match(
            '/\b(?:contained|card|inset|column|grid|frame|panel|tile|tarjeta|recuadro|columna|grilla|cuadrícula|marco)\b/iu',
            $pageContext
        ) === 1;
        // Bare surface nouns are weak full-frame hints: a contained card may
        // have a background or show a book cover. Strong edge-to-edge wording
        // still wins when generated prose contains both kinds of term.
        $fullFrame = $edgeToEdge || (!$compact && !$contained && preg_match(
            '/\b(?:cover|banner|background|backdrop|fondo)\b/iu',
            $pageContext
        ) === 1);

        $parts = [];
        if ($fullFrame) {
            $parts[] = 'full-frame';
        } elseif ($compact) {
            $parts[] = 'compact';
        } elseif ($contained) {
            $parts[] = 'contained';
        }
        // Orientation comes from the structured aspect-ratio parameter. Page
        // prose is untrusted and sometimes calls a subject a "portrait" even
        // when the requested canvas is landscape, so it must not compete with
        // the actual generation setting here.
        $parts[] = preg_match('/\b(?:photorealistic|photo|photographic)\b/i', $style) === 1
            ? 'editorial photograph'
            : 'pictorial composition';

        $result = implode(' ', $parts);
        if (preg_match(
            '/\b(?:grid|gallery|row|series|sequence|collection|grilla|cuadrícula|galería|fila|serie|secuencia|colección)\b/iu',
            $pageContext
        ) === 1) {
            $result .= ' within a repeated image series';
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
