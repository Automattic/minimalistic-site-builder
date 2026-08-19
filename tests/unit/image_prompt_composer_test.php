<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImagePromptComposer;
use Automattic\SiteBuild\GeminiImage;

/**
 * ImagePromptComposer turns the structured AI_IMAGE fields (subject | page-context
 * | style) plus the site context into the single text prompt sent to the endpoint.
 * The subject leads and is the priority; page/site context is appended as labelled
 * guidance and trimmed first to fit the model's token budget.
 */

test('compose leads with the subject and appends the style', function () {
    $out = ImagePromptComposer::compose('A sourdough loaf on a board', 'menu item card', 'photorealistic', '');
    assert_contains('A sourdough loaf on a board. Style: photorealistic', $out);
});

test('compose includes the page context and site context as labelled guidance', function () {
    $out = ImagePromptComposer::compose(
        'A sourdough loaf on a board',
        'menu item card in a 3-column grid',
        'photorealistic',
        'A neighborhood bakery selling sourdough and pastries.'
    );
    // Generated page prose is reduced to safe placement facts; page and site
    // context then read as adjacent guidance sentences.
    assert_contains(
        'Composition: contained editorial photograph within a repeated image series.'
        . ' A neighborhood bakery selling sourdough and pastries.',
        $out
    );
    assert_true(!str_contains($out, 'menu item'), 'raw page-context prose is not forwarded');
    // The guidance is explicitly framed as non-literal so the model doesn't draw it.
    assert_contains('never depicted literally', $out);
    // Subject is still present in full.
    assert_contains('A sourdough loaf on a board. Style: photorealistic', $out);
});

test('compose frames a site context without page context as its own sentence', function () {
    $out = ImagePromptComposer::compose(
        'A sourdough loaf on a board',
        '',
        'photorealistic',
        'A neighborhood bakery selling sourdough and pastries.'
    );
    assert_contains('never depicted literally: A neighborhood bakery selling sourdough and pastries.', $out);
    assert_true(!str_contains($out, 'Composition:'), 'no composition clause without a page context');
});

test('compose recasts web-layout page context into photographic language', function () {
    $out = ImagePromptComposer::compose(
        'A dense crowd filling Plaza de Mayo at golden hour',
        'full-bleed hero cover background with the left third kept as a calm low-detail area',
        'photorealistic',
        'A minimalist portfolio of twenty years of photojournalism.'
    );

    // The design-comp idiom that triggers painted-in title blocks (BIGR-768)
    // becomes a purely photographic brief before it reaches the model.
    assert_contains(
        'Composition: full-frame editorial photograph with the left third kept as open, low-detail negative space.',
        $out
    );
    foreach (['hero', 'cover', 'bleed', 'website', 'used as'] as $webTerm) {
        assert_true(
            !str_contains(strtolower($out), $webTerm),
            "web-design term “{$webTerm}” never reaches the image model"
        );
    }
});

test('compose recasts named overlay copy as reserved negative space', function () {
    // The authoring rules forbid naming overlay copy in the page context, but
    // an LLM slip here is exactly the painted-wordmark trigger — catch it.
    foreach (["photographer's", 'photographer’s'] as $possessive) {
        $out = ImagePromptComposer::compose(
            'A lone figure facing a dusk demonstration crowd',
            "full-bleed hero section with the {$possessive} name and tagline overlaid on the left",
            'photorealistic',
            ''
        );

        assert_contains(
            'Composition: full-frame editorial photograph with the left side kept as open, low-detail negative space.',
            $out
        );
        foreach (['photographer', 'name and tagline', 'overlaid', 'hero'] as $trigger) {
            assert_true(!str_contains(strtolower($out), $trigger), "copy trigger “{$trigger}” recast away");
        }
    }
});

test('compose drops website and arbitrary multi-item overlay copy', function () {
    $out = ImagePromptComposer::compose(
        'A construction crew crossing a job site at dawn',
        'full-bleed website hero with the focal subject on the left and the headline and CTA overlaid on the upper right',
        'photorealistic',
        ''
    );

    assert_contains(
        'Composition: full-frame editorial photograph with the upper-right area kept as open, low-detail negative space.',
        $out
    );
    foreach (['website', 'hero', 'headline', 'cta', 'overlaid'] as $trigger) {
        assert_true(!str_contains(strtolower($out), $trigger), "design-comp trigger “{$trigger}” omitted");
    }
});

test('compose fail-closes a non-English overlay context to fixed literals', function () {
    $out = ImagePromptComposer::compose(
        'Una multitud bailando bajo luces abstractas',
        'sección hero a sangre de un festival, con el titular colosal superpuesto en el tercio inferior izquierdo',
        'photorealistic',
        ''
    );

    assert_contains(
        'Composition: full-frame editorial photograph with the lower-left third kept as open, low-detail negative space.',
        $out
    );
    foreach (['sección', 'hero', 'titular', 'superpuesto', 'izquierdo'] as $trigger) {
        assert_true(!str_contains(strtolower($out), $trigger), "source-language trigger “{$trigger}” omitted");
    }
});

test('compose drops unknown page prose and its canonical result is a fixed point', function () {
    $sentinel = '極秘ブランド見出し';
    $out = ImagePromptComposer::compose(
        'A quiet winter landscape',
        "未知のウェブ配置 {$sentinel}",
        'photorealistic',
        ''
    );
    assert_contains('Composition: editorial photograph.', $out);
    assert_true(!str_contains($out, $sentinel), 'unknown generated prose is never forwarded');

    $canonicalContexts = [
        'full-frame editorial photograph with the left third kept as open, low-detail negative space',
        'compact editorial photograph within a repeated image series',
        'contained editorial photograph',
    ];
    foreach ($canonicalContexts as $canonical) {
        $fixed = ImagePromptComposer::compose('A quiet winter landscape', $canonical, 'photorealistic', '');
        assert_contains("Composition: {$canonical}.", $fixed);
    }
});

test('compose leaves orientation to the structured aspect ratio', function () {
    $out = ImagePromptComposer::compose(
        'A sculptural lamp on a cork plinth',
        'tall contained hero photograph filling the right two-thirds of an asymmetric hero beside the headline',
        'photorealistic',
        ''
    );

    assert_contains('Composition: contained editorial photograph.', $out);
    foreach (['wide', 'portrait', 'hero', 'headline', 'photograph photograph'] as $bad) {
        assert_true(!str_contains(strtolower($out), $bad), "page prose does not inject “{$bad}”");
    }
});

test('compose keeps non-photographic styles medium-neutral', function () {
    $out = ImagePromptComposer::compose(
        'A hand-drawn map of a vineyard',
        'wide feature card in a three-item row',
        'illustration',
        ''
    );

    assert_contains('Composition: contained pictorial composition within a repeated image series.', $out);
    assert_true(!str_contains($out, 'editorial photograph'), 'illustration guidance does not request photography');
});

test('compose does not mistake content adjectives or placement verbs for copy reservations', function () {
    $contexts = [
        'open-air market thumbnail',
        'calm-water destination card',
        'contained vertical portrait panel beside an open block of copy',
        'image-anchored opening photograph',
        'subject floating in water in a feature card',
        'two overlapping photographs in a gallery row',
        'pieza de la galería solapada con otra fotografía',
        'subject floating beside the title on the right',
        'full-bleed photographic band with no text over the image',
        'headline sits below the image edge',
        'small dish thumbnail beside the dish name in a stacked menu list',
        'right-side panel, calm and low in detail',
        'headings beside photographs in a layered gallery',
        'copy column on the left beside a portrait',
        'copy on the left next to an image',
        'two overlapping photographs with a caption below',
        'headline not overlaid on the image',
        'no overlaid headline on the image',
        'without any overlaid headline on the image',
        'no negative space in the composition',
    ];

    foreach ($contexts as $context) {
        $out = ImagePromptComposer::compose('A documentary scene', $context, 'photorealistic', '');
        assert_true(
            !str_contains($out, 'negative space'),
            "content context “{$context}” does not invent a copy reservation"
        );
    }
});

test('compose recognizes bounded copy-reservation phrasings', function () {
    $cases = [
        'left third kept clear' => 'the left third',
        'right side left empty' => 'the right side',
        'left third reserved for copy' => 'the left third',
        'headline and CTA on the upper right' => 'the upper-right area',
        'headline sitting on the sky at its top edge' => 'the upper area',
        'headings and photographs layered on top' => 'a reserved area',
        'títulos y pares de imágenes superpuestos' => 'a reserved area',
        'titular colosal sobre la imagen abajo a la izquierda' => 'the lower-left area',
        'titular gigante encima a la izquierda' => 'the left side',
        'titular gigante encima a la izquierda y un bloque de datos con botón abajo a la derecha' => 'the left side',
        'composition open and low-detail toward the upper edge' => 'the upper area',
        'focal geometry on the right with calm dark space at the left' => 'the left side',
        'calm and low in detail toward the upper edge' => 'the upper area',
        'lower left third kept as an open, low-detail dark area' => 'the lower-left third',
        'focal subject on the left with the right side kept clear' => 'the right side',
    ];

    foreach ($cases as $context => $region) {
        $out = ImagePromptComposer::compose('A documentary scene', $context, 'photorealistic', '');
        assert_contains(
            "with {$region} kept as open, low-detail negative space",
            $out,
            "copy reservation recovered from “{$context}”"
        );
    }
});

test('compose lets explicit containment outrank weak background nouns', function () {
    $cases = [
        'contained card image on a neutral background' => 'contained editorial photograph',
        'book cover thumbnail in a grid' => 'compact editorial photograph within a repeated image series',
        'banner detail inside a card' => 'contained editorial photograph',
    ];
    foreach ($cases as $context => $composition) {
        $out = ImagePromptComposer::compose('A documentary scene', $context, 'photorealistic', '');
        assert_contains("Composition: {$composition}.", $out);
        assert_true(!str_contains($out, 'full-frame'), "contained context “{$context}” is not full-frame");
    }

    $edge = ImagePromptComposer::compose(
        'A documentary scene',
        'edge-to-edge background with a status card overlaid on the left',
        'photorealistic',
        ''
    );
    assert_contains('Composition: full-frame editorial photograph', $edge);
});

test('compose scopes negative-space direction to the empty-space clause', function () {
    $right = ImagePromptComposer::compose(
        'A ceramic vessel in a sunlit studio',
        'focal subject on the left with open low-detail sky on the right',
        'photorealistic',
        ''
    );
    assert_contains('with the right side kept as open, low-detail negative space', $right);
    assert_true(!str_contains($right, 'with the left side kept'), 'subject direction is not reused');

    $left = ImagePromptComposer::compose(
        'A ceramic vessel in a sunlit studio',
        'open low-detail wall on the left, focal subject on the right',
        'photorealistic',
        ''
    );
    assert_contains('with the left side kept as open, low-detail negative space', $left);
    assert_true(!str_contains($left, 'with the right side kept'), 'later subject direction is not reused');
});

test('compose phrases the no-text guard positively', function () {
    $out = ImagePromptComposer::compose(
        'A misty mountain range at dawn',
        'full-bleed hero with text overlay',
        'photorealistic',
        'A travel journal.'
    );

    assert_contains('Purely pictorial imagery', $out);
    // The overlay slot is described as what the region IS…
    assert_contains('full-frame editorial photograph with a reserved area kept as open, low-detail negative space', $out);
    // …and the guard never enumerates the forbidden text artifacts, which
    // would plant those very concepts into the prompt context (BIGR-768).
    foreach (['headline', 'watermark', 'logo', 'lettering', 'caption', 'render no', 'website'] as $artifact) {
        assert_true(!str_contains(strtolower($out), $artifact), "guard does not name “{$artifact}”");
    }
});

test('compose adds the lettering clause when the subject names a text carrier', function () {
    $subjects = [
        'A bakery storefront at dusk with warm light spilling onto the pavement',
        'A vegetarian restaurant menu board beside the entrance',
        'A hand holding a phone over a wooden desk',
        'Un letrero de madera sobre la puerta de un restaurante',
        'Una pizarra junto al mostrador de la panadería',
    ];
    foreach ($subjects as $subject) {
        $out = ImagePromptComposer::compose($subject, 'wide feature band', 'photorealistic', '');
        assert_contains('quiet set dressing', $out);
        // The clause is a render instruction: it precedes the sheddable guidance.
        assert_true(
            strpos($out, 'quiet set dressing') < strpos($out, 'never depicted literally'),
            "lettering clause precedes the guidance for “{$subject}”"
        );
    }
});

test('compose omits the lettering clause for subjects without text carriers', function () {
    // An unconditional clause would plant the signage concept into clean
    // prompts (BIGR-781) — abstract and scenic subjects must never see it.
    $subjects = [
        'A misty mountain range at dawn seen from a low valley vantage',
        'Close-up of hands folding dough on a floured counter',
        'Sunlight raking across raw concrete columns in an empty atrium',
    ];
    foreach ($subjects as $subject) {
        $out = ImagePromptComposer::compose($subject, 'wide feature band', 'photorealistic', '');
        assert_true(!str_contains($out, 'quiet set dressing'), "no lettering clause for “{$subject}”");
        foreach (['sign', 'screen', 'printed'] as $carrier) {
            assert_true(!str_contains(strtolower($out), $carrier), "clean prompt does not name “{$carrier}”");
        }
    }
});

test('compose protects transparent text carriers without adding scene instructions', function () {
    $out = ImagePromptComposer::compose(
        'A hand-painted wooden sign shape',
        'isolated accent',
        'illustration',
        '',
        '',
        true
    );
    assert_contains('plain, unmarked material surface', $out);
    assert_true(!str_contains($out, 'quiet set dressing'), 'transparent asset has no scene to dress');
    assert_contains('plain solid pure white background', $out);
});

test('compose with no page or site context is just the subject + style', function () {
    $out = ImagePromptComposer::compose('A sourdough loaf', '', 'photorealistic', '');
    assert_eq('A sourdough loaf. Style: photorealistic', $out);
});

test('compose omits the style clause when no style is given', function () {
    $out = ImagePromptComposer::compose('A sourdough loaf', '', '', '');
    assert_eq('A sourdough loaf', $out);
});

test('compose keeps the subject in full when the site context is huge', function () {
    $subject = 'A specific sourdough loaf on a floured board, warm side light';
    $out = ImagePromptComposer::compose($subject, 'menu item card', 'photorealistic', str_repeat('context word ', 2000));

    assert_true(GeminiImage::estimateTokens($out) <= GeminiImage::MAX_PROMPT_TOKENS, 'within token cap');
    assert_contains("{$subject}. Style: photorealistic", $out);
});

test('compose injects the image grade as its own art-direction clause', function () {
    $grade = 'monochrome documentary, visible 35mm grain, charcoal midtones';
    $out = ImagePromptComposer::compose(
        'A sourdough loaf on a board',
        'menu item card',
        'photorealistic',
        'A neighborhood bakery selling sourdough and pastries.',
        $grade
    );

    assert_contains("Art direction for all site imagery: {$grade}.", $out);
    // The grade sits BEFORE the sheddable guidance so end-trimming keeps it.
    assert_true(
        strpos($out, 'Art direction for all site imagery') < strpos($out, 'Purely pictorial'),
        'grade clause precedes the guidance'
    );
});

test('compose omits the grade clause when no grade is given', function () {
    $out = ImagePromptComposer::compose('A sourdough loaf', 'menu item card', 'photorealistic', '', '');
    assert_true(!str_contains($out, 'Art direction'), 'no grade clause without a grade');
});

test('compose asks for a flat white background for transparent assets', function () {
    $out = ImagePromptComposer::compose(
        'A small symmetrical grapevine flourish, thin gold linework',
        'decorative accent beneath a section subheading',
        'illustration',
        'A neighborhood bakery.',
        '',
        true
    );

    // The image model cannot render alpha, so the prompt asks for the keyable isolation
    // it does honor: the subject alone on a flat solid white background.
    assert_contains('solid pure white background', $out);
    // Like the grade, this is a render instruction — it precedes the sheddable
    // guidance so end-trimming keeps it.
    assert_true(
        strpos($out, 'solid pure white background') < strpos($out, 'Purely pictorial'),
        'isolation clause precedes the guidance'
    );
    assert_contains('Composition: isolated pictorial asset. A neighborhood bakery.', $out);
    foreach (['every part of the frame is the scene itself', 'continuous unbroken scenery',
        'open sky', 'plain wall', 'still water', 'bare ground', 'soft-focus depth'] as $scenery) {
        assert_true(!str_contains($out, $scenery), "transparent guidance omits “{$scenery}”");
    }
});

test('compose omits the photographic grade for transparent assets', function () {
    $out = ImagePromptComposer::compose(
        'A small symmetrical grapevine flourish, thin gold linework',
        'decorative accent beneath a section subheading',
        'illustration',
        '',
        'warm chiaroscuro color, candlelit low-key lighting, deep shadow falloff',
        true
    );

    // The grade describes lighting/backdrop treatment, which the image model paints in
    // as a background scene — exactly what the white-background keying must
    // avoid, so transparent assets drop it.
    assert_true(!str_contains($out, 'Art direction'), 'no grade clause for transparent assets');
    assert_contains('solid pure white background', $out);
});

test('compose omits the transparency clause by default', function () {
    $out = ImagePromptComposer::compose('A sourdough loaf', 'menu item card', 'photorealistic', '');
    assert_true(!str_contains($out, 'transparent'), 'no transparency clause for opaque assets');
});

test('compose sheds the site context under token pressure but keeps transparency', function () {
    $out = ImagePromptComposer::compose(
        'A small grapevine flourish',
        'decorative accent',
        'illustration',
        str_repeat('blurb context word ', 2000), // far over budget — gets shed
        '',
        true
    );

    assert_true(GeminiImage::estimateTokens($out) <= GeminiImage::MAX_PROMPT_TOKENS, 'within token cap');
    assert_contains('solid pure white background', $out);
});

test('compose sheds the site context under token pressure but keeps the grade', function () {
    $subject = 'A specific sourdough loaf on a floured board, warm side light';
    $grade   = 'monochrome documentary, visible 35mm grain, charcoal midtones';
    $out = ImagePromptComposer::compose(
        $subject,
        'menu item card',
        'photorealistic',
        str_repeat('blurb context word ', 2000), // far over budget — gets shed
        $grade
    );

    assert_true(GeminiImage::estimateTokens($out) <= GeminiImage::MAX_PROMPT_TOKENS, 'within token cap');
    assert_contains("{$subject}. Style: photorealistic", $out);
    assert_contains("Art direction for all site imagery: {$grade}.", $out);
});

test('stripCompetingGradeTokens drops studio white and no grain on a Portra grade', function () {
    $subject = ImagePromptComposer::stripCompetingGradeTokens(
        'A loaf on a studio white cyclorama, no grain, catalog-lit',
        'warm Portra 400, visible 35mm grain, available light',
    );
    assert_true(!str_contains(strtolower($subject), 'studio white'));
    assert_true(!str_contains(strtolower($subject), 'no grain'));
    assert_true(!str_contains(strtolower($subject), 'catalog-lit'));
    assert_contains('A loaf on a', $subject);
});

test('compose strips competing grade tokens before the API prompt is built', function () {
    $out = ImagePromptComposer::compose(
        'A loaf on studio white, no grain',
        'menu item card',
        'photorealistic',
        '',
        'warm Portra 400, visible 35mm grain',
    );
    assert_true(!str_contains(strtolower($out), 'studio white'));
    assert_true(!str_contains(strtolower($out), 'no grain'));
    assert_contains('Art direction for all site imagery: warm Portra 400, visible 35mm grain.', $out);
});

