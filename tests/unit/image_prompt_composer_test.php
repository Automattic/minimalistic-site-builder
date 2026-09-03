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
        'Composition: editorial photograph, one of a set of matching scenes composed alike.'
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
        'Composition: editorial photograph with the left third kept as open, low-detail negative space.',
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
            'Composition: editorial photograph with the left side kept as open, low-detail negative space.',
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
        'Composition: editorial photograph with the upper-right area kept as open, low-detail negative space.',
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
        'Composition: editorial photograph with the lower-left third kept as open, low-detail negative space.',
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
        'editorial photograph with the left third kept as open, low-detail negative space',
        'editorial photograph, one of a set of matching scenes composed alike',
        'editorial photograph',
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

    assert_contains('Composition: editorial photograph.', $out);
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

    assert_contains('Composition: pictorial composition, one of a set of matching scenes composed alike.', $out);
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

test('compose emits no placement adjective for any slot wording', function () {
    // BIGR-958: an A/B run measured "full-frame" and "compact" changing
    // nothing they were meant to steer, so no slot wording may reintroduce
    // a placement adjective — including the strong edge-to-edge idiom.
    $cases = [
        'contained card image on a neutral background' => 'editorial photograph',
        'book cover thumbnail in a grid' => 'editorial photograph, one of a set of matching scenes composed alike',
        'banner detail inside a card' => 'editorial photograph',
        'edge-to-edge background with a status card overlaid on the left' => 'editorial photograph',
        'compact thumbnail in a stacked index' => 'editorial photograph',
    ];
    foreach ($cases as $context => $composition) {
        $out = ImagePromptComposer::compose('A documentary scene', $context, 'photorealistic', '');
        assert_contains("Composition: {$composition}", $out);
        foreach (['full-frame', 'compact', 'contained'] as $adjective) {
            assert_true(!str_contains($out, $adjective), "“{$context}” does not emit “{$adjective}”");
        }
    }
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

test('compose keeps print vocabulary out of a bounded-slot prompt', function () {
    // The tbilisi footer repro (BIGR-956): a square-crop commitment, a grid-row
    // page context and a film-grain grade stacked "contained", "frame" and
    // "repeated image series" into one prompt, and the model sometimes painted
    // a literal white print border. The scaffolding must not name any of it.
    $out = ImagePromptComposer::compose(
        'A clay jug tipped to pour deep amber wine into a shallow drinking bowl,'
            . ' two hands steadying it at the edge of a supra table',
        'one of three equal square frames in a footer contact-sheet row',
        'photorealistic',
        'The subject matter is traditional Georgian and Caucasus cuisine.',
        'Warm low-key color, gold and amber highlights, fine 400-speed film grain throughout',
        false,
        null,
        'square'
    );

    foreach (['frame', 'border', 'contained', 'image series', 'contact'] as $print) {
        assert_true(!str_contains(strtolower($out), $print), "prompt does not say “{$print}”");
    }
    // The crop system still steers composition, in canvas vocabulary…
    assert_contains('fill its square canvas out to every edge', $out);
    // …the guard states positively what the edges are…
    assert_contains('fills every part of the canvas and reaches all four edges', $out);
    // …and the sibling-consistency intent survives in scene vocabulary.
    assert_contains('Composition: editorial photograph, one of a set of matching scenes composed alike.', $out);
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
    assert_contains('editorial photograph with a reserved area kept as open, low-detail negative space', $out);
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

test('compose with no page or site context is the subject + style and the orientation anchor', function () {
    $out = ImagePromptComposer::compose('A sourdough loaf', '', 'photorealistic', '');
    assert_eq("A sourdough loaf. Style: photorealistic\n\n" . ImagePromptComposer::ORIENTATION_CLAUSE, $out);
});

test('compose omits the style clause when no style is given', function () {
    $out = ImagePromptComposer::compose('A sourdough loaf', '', '', '');
    assert_eq("A sourdough loaf\n\n" . ImagePromptComposer::ORIENTATION_CLAUSE, $out);
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

test('compose reads the site image crop as protected focal-area guidance', function () {
    $out = ImagePromptComposer::compose(
        'A sourdough loaf on a board',
        'menu item card',
        'photorealistic',
        imageCrop: 'portrait',
    );
    assert_contains('Site-wide crop direction:', $out);
    assert_contains('central portrait safe area', $out);

    $mixed = ImagePromptComposer::compose(
        'A sourdough loaf on a board',
        'menu item card',
        'photorealistic',
        imageCrop: 'mixed',
    );
    assert_true(!str_contains($mixed, 'Site-wide crop direction:'), 'mixed keeps per-role composition');

    $transparent = ImagePromptComposer::compose(
        'A flour-dusted wheat stem',
        'isolated accent',
        'illustration',
        transparent: true,
        imageCrop: 'portrait',
    );
    assert_true(!str_contains($transparent, 'Site-wide crop direction:'), 'trimmed alpha assets ignore site crop');
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
    foreach (['fills every part of the canvas', 'continuous unbroken scenery',
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

test('compose preserves competing grade tokens for transparent assets', function () {
    $subject = 'A badge on studio white, no grain';
    $out = ImagePromptComposer::compose(
        $subject,
        'isolated accent',
        'illustration',
        '',
        'warm Portra 400, visible 35mm grain',
        true
    );

    assert_contains("{$subject}. Style: illustration", $out);
    assert_true(!str_contains($out, 'Art direction'), 'transparent assets bypass the grade and its cleanup');
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

test('stripCompetingGradeTokens drops a clause that is only grade talk', function () {
    $result = ImagePromptComposer::stripCompetingGradeTokens(
        'A loaf on a linen cloth, no grain, catalog-lit',
        'warm Portra 400, visible 35mm grain, available light',
    );
    assert_eq('A loaf on a linen cloth', $result['subject']);
    assert_eq(['no grain', 'catalog-lit'], $result['removed']);
    assert_eq([], $result['kept']);
});

test('stripCompetingGradeTokens never cuts grade wording out of a scene clause', function () {
    // Word-level removal turned this into "A loaf on a sweep" — a different
    // backdrop, sent to the image service with nothing recording the change.
    $result = ImagePromptComposer::stripCompetingGradeTokens(
        'A loaf on a studio white sweep',
        'Warm 35mm film grain',
    );
    assert_eq('A loaf on a studio white sweep', $result['subject'], 'the scene survives byte-for-byte');
    assert_eq([], $result['removed']);
    assert_eq(['A loaf on a studio white sweep'], $result['kept'], 'and the conflict is reported');
});

test('stripCompetingGradeTokens keeps the clauses around one it removes', function () {
    // This used to collapse to "A loaf on,, resting on a wooden board".
    $result = ImagePromptComposer::stripCompetingGradeTokens(
        'A loaf on studio white, no grain, resting on a wooden board',
        'Warm 35mm film grain',
    );
    assert_eq('A loaf on studio white, resting on a wooden board', $result['subject']);
    assert_eq(['no grain'], $result['removed']);
    assert_eq(['A loaf on studio white'], $result['kept']);
});

test('stripCompetingGradeTokens returns the authored subject when nothing is left', function () {
    // This used to reduce to an empty string with nothing guarding it.
    foreach (['no grain, catalog-lit', 'black and white', 'muted grey tones'] as $subject) {
        $result = ImagePromptComposer::stripCompetingGradeTokens($subject, 'Warm 35mm film grain');
        assert_eq($subject, $result['subject'], "'{$subject}' is delivered rather than emptied");
        assert_eq([], $result['removed']);
        assert_eq([$subject], $result['kept']);
    }
});

test('stripCompetingGradeTokens reads the vocabulary, not the grade wording', function () {
    // The lists used to arm only when the grade itself carried the trigger
    // word, so a legally-worded grade disarmed all of them.
    $result = ImagePromptComposer::stripCompetingGradeTokens(
        'A ceramic bowl on a walnut table, no grain',
        'muted pastel color, soft even daylight',
    );
    assert_eq('A ceramic bowl on a walnut table', $result['subject']);
    assert_eq(['no grain'], $result['removed']);
});

test('stripCompetingGradeTokens never reads a negation as its own opposite', function () {
    // "no grain" agrees with a clean grade. It still leaves the subject,
    // because image-generation.md:63 keeps grade vocabulary out of subjects
    // unconditionally — but it leaves as a whole clause, not as erased words.
    $result = ImagePromptComposer::stripCompetingGradeTokens(
        'A loaf on a linen cloth, no grain',
        'Clean digital product shots, no grain, studio white',
    );
    assert_eq('A loaf on a linen cloth', $result['subject']);
    assert_eq(['no grain'], $result['removed']);
});

test('stripCompetingGradeTokens leaves a subject in another language alone', function () {
    // The vocabulary is English, so this is a documented no-op rather than a
    // silent partial edit.
    $subject = 'Un pan sobre un paño de lino junto a una ventana';
    $result = ImagePromptComposer::stripCompetingGradeTokens($subject, 'Warm 35mm film grain');
    assert_eq($subject, $result['subject']);
    assert_eq([], $result['removed']);
    assert_eq([], $result['kept']);
});

test('stripCompetingGradeTokens is idempotent over what it delivered', function () {
    $grade = 'warm Portra 400, visible 35mm grain';
    $once = ImagePromptComposer::stripCompetingGradeTokens('A loaf on a linen cloth, no grain', $grade);
    $twice = ImagePromptComposer::stripCompetingGradeTokens($once['subject'], $grade);
    assert_eq($once['subject'], $twice['subject']);
    assert_eq([], $twice['removed']);
});

test('compose strips competing grade tokens before the API prompt is built', function () {
    $out = ImagePromptComposer::compose(
        'A loaf on a linen cloth, no grain',
        'menu item card',
        'photorealistic',
        '',
        'warm Portra 400, visible 35mm grain',
    );
    assert_true(!str_contains($out, 'linen cloth, no grain'));
    assert_contains('A loaf on a linen cloth', $out);
    assert_contains('Art direction for all site imagery: warm Portra 400, visible 35mm grain.', $out);
});

test('stripCompetingGradeTokens accepts Unicode dash punctuation', function () {
    $cases = [
        ['A portrait, no‑grain', 'tri‑x contrast', 'A portrait'],
        ['A city, neon—soaked', 'black–and—white documentary', 'A city'],
        ['A product, studio–white, catalog—lit', 'warm available–light', 'A product'],
    ];
    foreach ($cases as [$subject, $grade, $expected]) {
        assert_eq($expected, ImagePromptComposer::stripCompetingGradeTokens($subject, $grade)['subject']);
    }
});

test('stripCompetingGradeTokens removes a grade clause carrying an intensity adjective', function () {
    // How much grain is not what the picture shows. Real subjects write
    // "fine 35mm grain" far more often than the bare term, and an adjective
    // alone used to hold the whole clause, so the pass fired on 3% of a real
    // corpus while reporting a conflict on the rest.
    $cases = [
        'fine 35mm grain',
        'heavy 35mm film grain',
        'visible 35mm film grain',
        'faint film grain',
        'subtle film grain',
        'gentle 35mm grain',
        'strictly monochrome no colour',
        'natural available light',
        'monochrome black-and-white with 35mm grain',
    ];
    foreach ($cases as $clause) {
        $result = ImagePromptComposer::stripCompetingGradeTokens(
            "A ceramic bowl on a walnut table, {$clause}",
            'muted pastel color, soft even daylight',
        );
        assert_eq('A ceramic bowl on a walnut table', $result['subject'], "'{$clause}' is grade talk only");
        assert_eq([$clause], $result['removed']);
        assert_eq([], $result['kept']);
    }
});

test('stripCompetingGradeTokens keeps an adjective clause that also names the scene', function () {
    // The adjectives above only ever apply to a clause that already matched
    // grade vocabulary. A scene noun beside one still holds the clause, so
    // widening the filler list cannot start cutting into a subject.
    $cases = [
        'A monochrome colour chart pinned to the wall',
        'A fine grain of sand between two fingers',
        'Warm light raking across the grain of an oak plank',
        'A hard black and white tile floor',
    ];
    foreach ($cases as $subject) {
        $result = ImagePromptComposer::stripCompetingGradeTokens($subject, 'warm Portra 400, visible 35mm grain');
        assert_eq($subject, $result['subject'], "the scene in '{$subject}' survives byte-for-byte");
        assert_eq([], $result['removed']);
    }
});

test('stripCompetingGradeTokens keeps a clause whose only grade word names material', function () {
    // Wood and leather have a grain, a landscape has a sweep. "fine grain" is
    // a walnut finish as often as a film stock, so an ambiguous term alone is
    // not enough to drop a clause — only a term that can only be photographic.
    $cases = [
        'Fine grain, hand-rubbed walnut finish',
        'The grain is fine and natural, close-up',
        'Natural grain, a dovetail joint',
        'Deep grain, oiled oak',
        'The grain is visible, a chisel resting on it',
        'The sweep is soft and deep, dancers mid-turn',
    ];
    foreach ($cases as $subject) {
        $result = ImagePromptComposer::stripCompetingGradeTokens($subject, 'warm Portra 400, visible 35mm grain');
        assert_eq($subject, $result['subject'], "'{$subject}' describes material, not grade");
        assert_eq([], $result['removed']);
    }

    // A photographic anchor beside the ambiguous term still strips.
    $film = ImagePromptComposer::stripCompetingGradeTokens(
        'A loaf on a board, fine film grain',
        'clean digital product shots',
    );
    assert_eq('A loaf on a board', $film['subject']);
    assert_eq(['fine film grain'], $film['removed']);
});

test('stripCompetingGradeTokens returns a subject it found no grade in byte-for-byte', function () {
    // Rejoining re-punctuates, so a subject with no grade wording at all came
    // back edited with nothing in removed or kept to record it — a delivered
    // change with no receipt, which is the defect this pass exists to prevent.
    $cases = [
        'A loaf,on a board',
        'A cat.  A dog.  A bird.',
        'A loaf on a board;',
        "A red bicycle.\nA blue door.",
        'Two friends laughing, arm in arm , outdoors',
    ];
    foreach ($cases as $subject) {
        $result = ImagePromptComposer::stripCompetingGradeTokens($subject, 'warm Portra 400, visible 35mm grain');
        assert_eq($subject, $result['subject'], "'{$subject}' is delivered exactly as authored");
        assert_eq([], $result['removed']);
        assert_eq([], $result['kept']);
    }
});

test('stripCompetingGradeTokens reports nothing for a subject it could not read', function () {
    // Punctuation only, and bytes preg_split rejects. No clause is examined,
    // so claiming the subject "names photographic grade" is a false receipt —
    // and on the invalid-UTF-8 case it put raw bad bytes in warnings.json.
    foreach ([',,,', ';', "\xC3\x28 a loaf on a linen cloth, no grain"] as $subject) {
        $result = ImagePromptComposer::stripCompetingGradeTokens($subject, 'warm Portra 400, visible 35mm grain');
        assert_eq([], $result['kept'], 'no conflict is reported for a subject that was never read');
        assert_eq([], $result['removed']);
    }
});

test('stripCompetingGradeTokens keeps a clause PCRE could not read', function () {
    // A run long enough to exhaust the PCRE JIT stack. Both passes over a
    // clause can fail on it, and both must fail the same way — toward keeping
    // the text. The match pass already does (a failed preg_match reads as "no
    // term", so the clause survives here); clauseIsOnlyGrade's replace pass is
    // guarded to match it, because `(string) null` there is an empty residue,
    // which is the "only grade talk" signal that deletes the whole clause.
    $clause = 'black' . str_repeat(' ', 60000) . 'andx a ceramic vase on a walnut table';
    $result = ImagePromptComposer::stripCompetingGradeTokens("A scene, {$clause}", 'black and white');
    assert_true(
        str_contains($result['subject'], 'a ceramic vase on a walnut table'),
        'a clause PCRE could not read keeps its scene instead of losing it'
    );
});

test('stripCompetingGradeTokens judges an ambiguous term by its own clause only', function () {
    $grade = 'clean digital product shots, studio white';

    // "subtle grain" beside a walnut table is the walnut, and stays.
    $material = ImagePromptComposer::stripCompetingGradeTokens(
        'A ceramic bowl on a walnut table, subtle grain',
        $grade,
    );
    assert_eq('A ceramic bowl on a walnut table, subtle grain', $material['subject']);
    assert_eq([], $material['removed']);

    // A photographic word ELSEWHERE in the subject does not make it film
    // stock. Reading the whole subject deleted an oak table's grain because
    // the framing clause happened to say monochrome, so each clause is judged
    // on what it says itself.
    $elsewhere = ImagePromptComposer::stripCompetingGradeTokens(
        'A monochrome photograph of an oak table, fine grain, overhead framing',
        $grade,
    );
    assert_eq(
        'A monochrome photograph of an oak table, fine grain, overhead framing',
        $elsewhere['subject'],
        'the oak keeps its grain',
    );
    assert_eq([], $elsewhere['removed']);

    // An outright photographic term in the clause itself does strip it.
    $photographic = ImagePromptComposer::stripCompetingGradeTokens(
        'A ceramic bowl on a walnut table, subtle film grain',
        $grade,
    );
    assert_eq('A ceramic bowl on a walnut table', $photographic['subject']);
    assert_eq(['subtle film grain'], $photographic['removed']);

    // So does a negation: material is never described by the grain it lacks.
    $negated = ImagePromptComposer::stripCompetingGradeTokens(
        'A ceramic bowl on a walnut table, no grain',
        $grade,
    );
    assert_eq('A ceramic bowl on a walnut table', $negated['subject']);
    assert_eq(['no grain'], $negated['removed']);
});

test('stripCompetingGradeTokens does not re-punctuate around a clause it only reported', function () {
    // A kept clause still routed the whole subject through the rejoin, which
    // re-punctuates every OTHER clause. With nothing removed there is no
    // authored-vs-delivered row, so the kept row asserted "delivered
    // unchanged" over a subject that had just been changed.
    $cases = [
        'A loaf,on a board, a black and white cat',
        'A vase (studio white sweep) on a table,beside a lamp',
    ];
    foreach ($cases as $subject) {
        $result = ImagePromptComposer::stripCompetingGradeTokens($subject, 'warm Portra 400, visible 35mm grain');
        assert_eq($subject, $result['subject'], "'{$subject}' is delivered exactly as authored");
        assert_eq([], $result['removed']);
        assert_true($result['kept'] !== [], 'the conflict is still reported');
    }
});

test('compose anchors the orientation of every opaque image (BIGR-979)', function () {
    // The structured ratio sets the canvas shape only. A cover asked for a
    // lateral copy reservation on a top-to-bottom scene once came back as a
    // portrait composition turned 90° inside the landscape canvas.
    $cover = ImagePromptComposer::compose(
        'A dense crowd filling a wide avenue at dusk seen from a raised vantage',
        'full-frame editorial photograph with the left third kept as open, low-detail negative space',
        'photorealistic',
        'A portfolio of twenty years of photojournalism.',
        'Monochrome documentary in stark blacks and silvers.',
        imageCrop: 'landscape',
    );
    assert_contains(ImagePromptComposer::ORIENTATION_CLAUSE, $cover);
    assert_contains('upright', ImagePromptComposer::ORIENTATION_CLAUSE);
    assert_contains('horizon level', ImagePromptComposer::ORIENTATION_CLAUSE);
    foreach (['frame', 'border', 'contained', 'portrait', 'wide'] as $bad) {
        assert_true(!str_contains(strtolower(ImagePromptComposer::ORIENTATION_CLAUSE), $bad), "anchor never says “{$bad}”");
    }
    // A render instruction sits before the guidance so end-trimming under
    // token pressure sheds the context first.
    assert_true(strpos($cover, 'Orientation:') < strpos($cover, 'Purely pictorial'), 'anchor precedes the guidance');

    $card = ImagePromptComposer::compose('A sourdough loaf on a board', 'menu item card', 'photorealistic');
    assert_contains(ImagePromptComposer::ORIENTATION_CLAUSE, $card, 'every opaque image is anchored, not only covers');

    $transparent = ImagePromptComposer::compose(
        'A flour-dusted wheat stem',
        'isolated accent',
        'illustration',
        transparent: true,
    );
    assert_true(!str_contains($transparent, 'Orientation:'), 'an isolated asset has no horizon to level');
});

test('compose keeps the orientation anchor under token pressure', function () {
    $out = ImagePromptComposer::compose(
        'A sourdough loaf on a board',
        'menu item card',
        'photorealistic',
        str_repeat('A neighborhood bakery selling sourdough and pastries. ', 200),
        'Warm natural light with soft film grain.',
        imageCrop: 'landscape',
    );
    assert_true(GeminiImage::estimateTokens($out) <= GeminiImage::MAX_PROMPT_TOKENS, 'prompt fits the budget');
    assert_contains(ImagePromptComposer::ORIENTATION_CLAUSE, $out);
    assert_contains('Site-wide crop direction:', $out);
});
