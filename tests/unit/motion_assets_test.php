<?php
declare(strict_types=1);

use Automattic\SiteBuild\Motion;

/**
 * Static motion assets are the executable profile contract. PageStyles must
 * not be able to flatten these choices after the profile stylesheet loads.
 */

/** @return array<string,string> */
function motion_asset_profile_tokens(string $profile): array
{
    $path = repo_path("assets/motion/profiles/{$profile}.css");
    $css = (string) file_get_contents($path);
    if (preg_match('/:root\s*\{([^}]*)\}/s', $css, $root) !== 1) {
        throw new RuntimeException("{$profile}.css has no :root token block");
    }
    preg_match_all('/(--motion-[a-z0-9-]+)\s*:\s*([^;]+);/i', $root[1], $matches, PREG_SET_ORDER);
    $tokens = [];
    foreach ($matches as $match) {
        $tokens[strtolower($match[1])] = trim($match[2]);
    }
    return $tokens;
}

function motion_asset_milliseconds(string $value): float
{
    if (preg_match('/^(\d+(?:\.\d+)?)\s*(ms|s)$/i', trim($value), $match) !== 1) {
        throw new RuntimeException("Not a plain motion duration: {$value}");
    }
    $amount = (float) $match[1];
    return strtolower($match[2]) === 's' ? $amount * 1000 : $amount;
}

/**
 * Return a complete CSS block, including its opening selector and closing
 * brace. This small brace walker handles the nested @media block used here.
 */
function motion_asset_css_block(string $css, string $needle): string
{
    $start = strpos($css, $needle);
    if ($start === false) {
        throw new RuntimeException("CSS block not found: {$needle}");
    }
    $open = strpos($css, '{', $start + strlen($needle));
    if ($open === false) {
        throw new RuntimeException("CSS block has no opening brace: {$needle}");
    }
    $depth = 1;
    $length = strlen($css);
    for ($i = $open + 1; $i < $length; $i++) {
        if ($css[$i] === '{') {
            $depth++;
        } elseif ($css[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($css, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException("CSS block is unbalanced: {$needle}");
}

test('motion profiles own a complete token set with distinct choreography', function () {
    $required = [
        '--motion-enter-duration',
        '--motion-hero-duration',
        '--motion-hero-delay',
        '--motion-hover-duration',
        '--motion-ambient-duration',
        '--motion-enter-ease',
        '--motion-hero-ease',
        '--motion-hover-ease',
        '--motion-ambient-ease',
        '--motion-reveal-keyframe',
        '--motion-rise-keyframe',
        '--motion-fade-keyframe',
        '--motion-scale-keyframe',
        '--motion-blur-keyframe',
        '--motion-wipe-keyframe',
        '--motion-wipe-up-keyframe',
        '--motion-aperture-keyframe',
        '--motion-zoom-keyframe',
        '--motion-hero-keyframe',
        '--motion-stagger-keyframe',
        '--motion-drift-keyframe',
        '--motion-ken-burns-keyframe',
        '--motion-gradient-keyframe',
        '--motion-distance',
        '--motion-stagger',
        '--motion-scale-from',
        '--motion-blur-amount',
        '--motion-zoom-from',
        '--motion-hero-scale-from',
        '--motion-ken-burns-scale',
        '--motion-ambient-distance',
        '--motion-hover-lift',
        '--motion-hover-scale',
        '--motion-hover-brightness',
        '--motion-hover-shadow-opacity',
    ];

    $profiles = [];
    foreach (['energetic', 'calm', 'dramatic', 'minimal'] as $profile) {
        $profiles[$profile] = motion_asset_profile_tokens($profile);
        foreach ($required as $token) {
            assert_true(
                isset($profiles[$profile][$token]) && $profiles[$profile][$token] !== '',
                "{$profile} defines {$token}"
            );
        }
        assert_true(!isset($profiles[$profile]['--motion-duration']), "{$profile} drops the shared legacy duration");
        assert_true(!isset($profiles[$profile]['--motion-ease']), "{$profile} drops the shared legacy easing");
    }

    // The three animated profiles must remain visibly ordered even when their
    // exact hand-tuned values evolve.
    foreach (['--motion-enter-duration', '--motion-hero-duration'] as $token) {
        $energetic = motion_asset_milliseconds($profiles['energetic'][$token]);
        $calm = motion_asset_milliseconds($profiles['calm'][$token]);
        $dramatic = motion_asset_milliseconds($profiles['dramatic'][$token]);
        assert_true($energetic < $calm, "{$token}: energetic is faster than calm");
        assert_true($calm < $dramatic, "{$token}: calm is faster than dramatic");
        assert_true($calm > 800, "{$token}: calm is slower than the old 800ms profile");
        assert_true($dramatic > 950, "{$token}: dramatic is slower than the old 950ms profile");
    }

    // Content must become readable promptly even while its transform keeps
    // settling. Bound both the hero and the ninth-and-later stagger cap so
    // "cinematic" never means blank.
    foreach (['energetic', 'calm', 'dramatic'] as $profile) {
        $enter = motion_asset_milliseconds($profiles[$profile]['--motion-enter-duration']);
        $hero = motion_asset_milliseconds($profiles[$profile]['--motion-hero-duration']);
        $delay = motion_asset_milliseconds($profiles[$profile]['--motion-hero-delay']);
        $stagger = motion_asset_milliseconds($profiles[$profile]['--motion-stagger']);
        assert_true($delay + ($hero * 0.4) <= 1000, "{$profile} hero reaches full opacity within one second");
        assert_true(($stagger * 8) + ($enter * 0.4) <= 2000, "{$profile} last stagger tier reaches full opacity within two seconds");
        assert_true(($stagger * 8) + $enter <= 3000, "{$profile} last stagger tier finishes settling within three seconds");
        assert_true(
            motion_asset_milliseconds($profiles[$profile]['--motion-hover-duration']) <= 500,
            "{$profile} hover remains responsive"
        );
    }

    // Team feedback: entrances that spring past their rest position read as
    // artificial, and strong ones read as dizzying. EVERY easing in EVERY
    // profile must therefore be a monotonic cubic-bezier — no control point
    // may leave [0, 1], on any clock, entrance and hover included.
    foreach (['energetic', 'calm', 'dramatic', 'minimal'] as $profile) {
        foreach ([
            '--motion-enter-ease',
            '--motion-hero-ease',
            '--motion-hover-ease',
            '--motion-ambient-ease',
        ] as $token) {
            $ease = $profiles[$profile][$token];
            assert_true(
                preg_match(
                    '/^cubic-bezier\(\s*([-\d.]+)\s*,\s*([-\d.]+)\s*,\s*([-\d.]+)\s*,\s*([-\d.]+)\s*\)$/',
                    $ease,
                    $match
                ) === 1,
                "{$profile} {$token} is a parseable cubic-bezier"
            );
            foreach ([1, 2, 3, 4] as $point) {
                assert_true(
                    (float) $match[$point] >= 0 && (float) $match[$point] <= 1,
                    "{$profile} {$token} is monotonic without spring overshoot"
                );
            }
        }
    }

    // Choreography is profile-owned too, not merely a duration rename. These
    // signatures permit individual token reuse while preventing all three
    // profiles from collapsing to the same easing/scale treatment.
    $signatures = [];
    foreach (['energetic', 'calm', 'dramatic'] as $profile) {
        $signatures[] = implode('|', [
            $profiles[$profile]['--motion-enter-ease'],
            $profiles[$profile]['--motion-hero-ease'],
            $profiles[$profile]['--motion-hover-ease'],
            $profiles[$profile]['--motion-ambient-ease'],
            $profiles[$profile]['--motion-reveal-keyframe'],
            $profiles[$profile]['--motion-rise-keyframe'],
            $profiles[$profile]['--motion-fade-keyframe'],
            $profiles[$profile]['--motion-scale-keyframe'],
            $profiles[$profile]['--motion-hero-keyframe'],
            $profiles[$profile]['--motion-stagger-keyframe'],
            $profiles[$profile]['--motion-drift-keyframe'],
            $profiles[$profile]['--motion-ken-burns-keyframe'],
            $profiles[$profile]['--motion-gradient-keyframe'],
            $profiles[$profile]['--motion-scale-from'],
            $profiles[$profile]['--motion-hero-delay'],
            $profiles[$profile]['--motion-hero-scale-from'],
            $profiles[$profile]['--motion-ken-burns-scale'],
            $profiles[$profile]['--motion-ambient-distance'],
            $profiles[$profile]['--motion-hover-lift'],
            $profiles[$profile]['--motion-hover-scale'],
            $profiles[$profile]['--motion-hover-brightness'],
            $profiles[$profile]['--motion-hover-shadow-opacity'],
        ]);
    }
    assert_eq(3, count(array_unique($signatures)), 'animated profiles have distinct choreography signatures');

    // Animation identity—not only scalar timing—is owned by the profile.
    // This is the regression that made dramatic look like slow calm motion.
    foreach ([
        '--motion-reveal-keyframe',
        '--motion-rise-keyframe',
        '--motion-fade-keyframe',
        '--motion-scale-keyframe',
        '--motion-hero-keyframe',
        '--motion-stagger-keyframe',
        '--motion-drift-keyframe',
        '--motion-ken-burns-keyframe',
        '--motion-gradient-keyframe',
    ] as $token) {
        $names = [];
        foreach (['energetic', 'calm', 'dramatic'] as $profile) {
            $name = $profiles[$profile][$token];
            assert_true(
                str_starts_with($name, "motion-{$profile}-"),
                "{$profile} owns a named {$token} family"
            );
            $names[] = $name;
        }
        assert_eq(3, count(array_unique($names)), "{$token} differs across animated profiles");
    }
});

test('motion kit consumes dedicated profile tokens for each motion family', function () {
    $css = (string) file_get_contents(repo_path('assets/motion/motion.css'));
    $withoutComments = (string) preg_replace('~/\*.*?\*/~s', '', $css);

    foreach ([
        '--motion-enter-duration',
        '--motion-hero-duration',
        '--motion-hero-delay',
        '--motion-hover-duration',
        '--motion-ambient-duration',
        '--motion-enter-ease',
        '--motion-hero-ease',
        '--motion-hover-ease',
        '--motion-ambient-ease',
        '--motion-reveal-keyframe',
        '--motion-rise-keyframe',
        '--motion-fade-keyframe',
        '--motion-scale-keyframe',
        '--motion-blur-keyframe',
        '--motion-wipe-keyframe',
        '--motion-wipe-up-keyframe',
        '--motion-aperture-keyframe',
        '--motion-zoom-keyframe',
        '--motion-hero-keyframe',
        '--motion-stagger-keyframe',
        '--motion-drift-keyframe',
        '--motion-ken-burns-keyframe',
        '--motion-gradient-keyframe',
        '--motion-distance',
        '--motion-stagger',
        '--motion-scale-from',
        '--motion-blur-amount',
        '--motion-zoom-from',
        '--motion-hero-scale-from',
        '--motion-ken-burns-scale',
        '--motion-ambient-distance',
        '--motion-hover-lift',
        '--motion-hover-scale',
        '--motion-hover-brightness',
        '--motion-hover-shadow-opacity',
    ] as $token) {
        assert_contains("var({$token}", $withoutComments, "kit consumes {$token}");
    }
    assert_true(!str_contains($withoutComments, 'var(--motion-duration'), 'shared legacy duration is unused');
    assert_true(!str_contains($withoutComments, 'var(--motion-ease)'), 'shared legacy easing is unused');

    assert_true(
        preg_match(
            '/\.reveal\.motion-target\.is-visible\s*\{[^}]*var\(--motion-enter-duration[^}]*var\(--motion-enter-ease/s',
            $withoutComments
        ) === 1,
        'scroll entrances consume enter duration and easing'
    );
    assert_true(
        preg_match(
            '/\.hero-entrance\s*\{[^}]*var\(--motion-hero-duration[^}]*var\(--motion-hero-ease[^}]*var\(--motion-hero-delay/s',
            $withoutComments
        ) === 1,
        'hero entrance consumes hero duration, easing, and delay'
    );
    foreach (['.ambient-drift', '.ken-burns img', '.gradient-shift.gradient-shift'] as $selector) {
        $quoted = preg_quote($selector, '/');
        assert_true(
            preg_match(
                '/' . $quoted . '\s*\{[^}]*var\(--motion-ambient-duration[^}]*var\(--motion-ambient-ease/s',
                $withoutComments
            ) === 1,
            "{$selector} consumes ambient duration and easing"
        );
    }

    foreach ([
        '.reveal.motion-target.is-visible' => '--motion-reveal-keyframe',
        '.reveal-up.motion-target.is-visible' => '--motion-rise-keyframe',
        '.reveal-fade.motion-target.is-visible' => '--motion-fade-keyframe',
        '.reveal-scale.motion-target.is-visible' => '--motion-scale-keyframe',
        '.reveal-blur.motion-target.is-visible' => '--motion-blur-keyframe',
        '.reveal-wipe.motion-target.is-visible' => '--motion-wipe-keyframe',
        '.reveal-wipe-up.motion-target.is-visible' => '--motion-wipe-up-keyframe',
        '.reveal-aperture.motion-target.is-visible' => '--motion-aperture-keyframe',
        '.reveal-zoom.motion-target.is-visible' => '--motion-zoom-keyframe',
        '.stagger-children > *.motion-target.is-visible' => '--motion-stagger-keyframe',
        '.hero-entrance' => '--motion-hero-keyframe',
        '.ambient-drift' => '--motion-drift-keyframe',
        '.ken-burns img' => '--motion-ken-burns-keyframe',
        '.gradient-shift.gradient-shift' => '--motion-gradient-keyframe',
    ] as $selector => $token) {
        $block = motion_asset_css_block($withoutComments, $selector);
        assert_contains("animation-name: var({$token})", $block, "{$selector} selects its profile keyframe");
    }
    $heroRule = motion_asset_css_block($withoutComments, '.hero-entrance');
    assert_contains(
        'animation-fill-mode: backwards',
        $heroRule,
        'hero entrance returns control of transform/filter to authored styles after it finishes'
    );

    $profiles = [];
    $nameTokens = [
        '--motion-reveal-keyframe',
        '--motion-rise-keyframe',
        '--motion-fade-keyframe',
        '--motion-scale-keyframe',
        '--motion-blur-keyframe',
        '--motion-wipe-keyframe',
        '--motion-wipe-up-keyframe',
        '--motion-aperture-keyframe',
        '--motion-zoom-keyframe',
        '--motion-hero-keyframe',
        '--motion-stagger-keyframe',
        '--motion-drift-keyframe',
        '--motion-ken-burns-keyframe',
        '--motion-gradient-keyframe',
    ];
    foreach (['calm', 'energetic', 'dramatic'] as $profile) {
        $profiles[$profile] = motion_asset_profile_tokens($profile);
        foreach ($nameTokens as $token) {
            $name = $profiles[$profile][$token];
            assert_contains("@keyframes {$name}", $withoutComments, "kit defines {$profile} {$token}");
        }

        foreach ([
            '--motion-reveal-keyframe',
            '--motion-rise-keyframe',
            '--motion-fade-keyframe',
            '--motion-scale-keyframe',
            '--motion-blur-keyframe',
            '--motion-wipe-keyframe',
            '--motion-wipe-up-keyframe',
            '--motion-aperture-keyframe',
            '--motion-zoom-keyframe',
            '--motion-hero-keyframe',
        ] as $token) {
            $name = $profiles[$profile][$token];
            $block = motion_asset_css_block($withoutComments, "@keyframes {$name}");
            assert_true(
                preg_match('/40%\s*\{\s*opacity:\s*1;/', $block) === 1,
                "{$name} makes content fully visible before its movement finishes"
            );
        }

        $scale = motion_asset_css_block(
            $withoutComments,
            '@keyframes ' . $profiles[$profile]['--motion-scale-keyframe']
        );
        assert_contains('scale(var(--motion-scale-from))', $scale, "{$profile} scale reveal uses its starting scale");

        $hero = motion_asset_css_block(
            $withoutComments,
            '@keyframes ' . $profiles[$profile]['--motion-hero-keyframe']
        );
        assert_contains('scale(var(--motion-hero-scale-from))', $hero, "{$profile} hero uses its starting scale");

        $kenBurns = motion_asset_css_block(
            $withoutComments,
            '@keyframes ' . $profiles[$profile]['--motion-ken-burns-keyframe']
        );
        assert_contains('scale(var(--motion-ken-burns-scale))', $kenBurns, "{$profile} Ken Burns uses its scale");

        $drift = motion_asset_css_block(
            $withoutComments,
            '@keyframes ' . $profiles[$profile]['--motion-drift-keyframe']
        );
        assert_contains('var(--motion-ambient-distance)', $drift, "{$profile} drift uses its travel distance");
    }

    $heroMotifs = [];
    foreach (['calm', 'energetic', 'dramatic'] as $profile) {
        $heroMotifs[$profile] = motion_asset_css_block(
            $withoutComments,
            '@keyframes ' . $profiles[$profile]['--motion-hero-keyframe']
        );
    }
    assert_contains('filter: blur(3px)', $heroMotifs['calm'], 'calm hero uses a soft-focus settle');
    assert_contains('translateY(var(--motion-distance))', $heroMotifs['energetic'], 'energetic hero rises');
    assert_contains('filter: blur(var(--motion-blur-amount))', $heroMotifs['dramatic'], 'dramatic hero is a focus pull');
    assert_true(!str_contains($heroMotifs['dramatic'], 'clip-path'), 'dramatic hero no longer wipes across the viewport');
    assert_eq(3, count(array_unique($heroMotifs)), 'hero profiles contain genuinely different choreography');
});

test('motion kit moves only vertically and never springs past rest', function () {
    $css = (string) file_get_contents(repo_path('assets/motion/motion.css'));
    $withoutComments = strtolower((string) preg_replace('~/\*.*?\*/~s', '', $css));

    // Team feedback (aug28test): sideways arrivals read as disorienting.
    // The kit may fade, rise, settle, mask, zoom, and sharpen — it may not
    // translate on the horizontal axis, rotate, or pan a background sideways.
    assert_true(!str_contains($withoutComments, 'translatex('), 'no keyframe or rule translates horizontally');
    assert_true(!str_contains($withoutComments, 'translate3d('), 'no keyframe hides horizontal travel inside translate3d');
    assert_true(!str_contains($withoutComments, 'rotate('), 'no keyframe rotates content');
    // Parse the whole background-position value so a keyword form (`left`,
    // `right center`) cannot slip past a digits-only pattern.
    preg_match_all('/background-position:\s*([^;}]+)/', $withoutComments, $m);
    assert_true(count($m[1]) > 0, 'gradient keyframes declare background-position');
    foreach ($m[1] as $position) {
        $parts = preg_split('/\s+/', trim($position)) ?: [];
        assert_eq('50%', $parts[0] ?? '', 'gradient drift holds its horizontal position');
    }

    // Masks may only wipe vertically: every animated clip-path keeps its left
    // and right insets at zero.
    preg_match_all('/clip-path:\s*inset\(([^)]*)\)/', $withoutComments, $insets);
    foreach ($insets[1] as $inset) {
        $parts = preg_split('/\s+/', trim($inset)) ?: [];
        if (count($parts) === 4) {
            assert_eq('0', $parts[1], 'clip mask keeps its right inset at zero');
            assert_eq('0', $parts[3], 'clip mask keeps its left inset at zero');
        }
    }
});

test('the JS driver and the screenshot harness observe every entrance class', function () {
    // Motion::SCROLL_CLASSES is the source of the entrance vocabulary, but
    // motion.js (ENTRANCE_SELECTOR) and bin/screenshot/screenshot.js
    // (settleMotion) each keep a hand-written selector copy. A class missing
    // from motion.js never reveals; a class missing from settleMotion lets a
    // full-page screenshot capture a section that is still at opacity:0.
    $sources = [
        'assets/motion/motion.js',
        'bin/screenshot/screenshot.js',
    ];
    foreach ($sources as $file) {
        $js = (string) file_get_contents(repo_path($file));
        foreach (Motion::SCROLL_CLASSES as $class) {
            if ($class === 'hero-entrance') {
                continue; // pure CSS on load; neither file needs to observe it
            }
            $needle = $class === 'stagger-children' ? '.stagger-children > *' : ".{$class}";
            assert_true(
                preg_match('/' . preg_quote($needle, '/') . '(?![\w-])/', $js) === 1,
                "{$file} observes {$needle}"
            );
        }
    }
});

test('motion kit owns both hover utilities inside the reduced-motion guard', function () {
    $css = (string) file_get_contents(repo_path('assets/motion/motion.css'));
    $withoutComments = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    $guard = motion_asset_css_block(
        $withoutComments,
        '@media screen and (prefers-reduced-motion: no-preference)'
    );

    foreach (['.hover-lift', '.hover-lift:hover', '.hover-reveal', '.hover-reveal img', '.hover-reveal:hover img'] as $selector) {
        assert_contains($selector, $guard, "{$selector} implemented inside motion guard");
    }
    assert_true(
        substr_count($guard, 'var(--motion-hover-duration') >= 2,
        'both hover utilities consume the profile-owned hover duration'
    );
    assert_true(
        substr_count($guard, 'var(--motion-hover-ease') >= 2,
        'both hover utilities consume the profile-owned hover easing'
    );
    assert_true(
        preg_match(
            '/\.hover-lift:hover\s*\{[^}]*var\(--motion-hover-lift\)[^}]*var\(--motion-hover-shadow-opacity\)/s',
            $guard
        ) === 1,
        'hover-lift consumes the profile-owned travel distance and shadow opacity'
    );
    assert_true(
        preg_match(
            '/\.hover-reveal:hover img\s*\{[^}]*var\(--motion-hover-scale\)[^}]*var\(--motion-hover-brightness\)/s',
            $guard
        ) === 1,
        'hover-reveal consumes the profile-owned scale and brightness'
    );

    // Removing the guard must remove every hover rule: reduced-motion users
    // receive no hover transform/zoom from an unguarded duplicate.
    $outside = str_replace($guard, '', $withoutComments);
    assert_true(preg_match('/\.hover-lift(?:\s|:|\{)/', $outside) !== 1, 'hover-lift has no unguarded rule');
    assert_true(preg_match('/\.hover-reveal(?:\s|:|\{)/', $outside) !== 1, 'hover-reveal has no unguarded rule');
});

test('motion kit persistently skips entrances reached by keyboard focus', function () {
    $css = (string) file_get_contents(repo_path('assets/motion/motion.css'));
    $withoutComments = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    $guard = motion_asset_css_block(
        $withoutComments,
        '@media screen and (prefers-reduced-motion: no-preference)'
    );
    $focusRule = motion_asset_css_block($guard, 'html.motion-js .reveal:focus-within');
    $skipRule = motion_asset_css_block($guard, '.reveal.motion-skip');

    foreach ([
        'html.motion-js .reveal:focus-within',
        'html.motion-js .reveal-up:focus-within',
        'html.motion-js .reveal-fade:focus-within',
        'html.motion-js .reveal-scale:focus-within',
        'html.motion-js .reveal-blur:focus-within',
        'html.motion-js .reveal-wipe:focus-within',
        'html.motion-js .reveal-wipe-up:focus-within',
        'html.motion-js .reveal-aperture:focus-within',
        'html.motion-js .reveal-zoom:focus-within',
        'html.motion-js .stagger-children > *:focus-within',
        '.hero-entrance:focus-within',
    ] as $selector) {
        assert_contains($selector, $focusRule, "focus escape covers {$selector}");
    }
    foreach (['opacity: 1 !important', 'transform: none !important', 'filter: none !important', 'clip-path: none !important'] as $override) {
        assert_contains($override, $focusRule, "focus escape applies {$override}");
    }
    assert_true(
        !str_contains($focusRule, 'animation:'),
        'the immediate bridge does not replace the animation before JS persists the skip'
    );
    foreach ([
        '.reveal.motion-skip',
        '.reveal-up.motion-skip',
        '.reveal-fade.motion-skip',
        '.reveal-scale.motion-skip',
        '.reveal-blur.motion-skip',
        '.reveal-wipe.motion-skip',
        '.reveal-wipe-up.motion-skip',
        '.reveal-aperture.motion-skip',
        '.reveal-zoom.motion-skip',
        '.stagger-children > *.motion-skip',
        '.hero-entrance.motion-skip',
    ] as $selector) {
        assert_contains($selector, $skipRule, "persistent focus skip covers {$selector}");
    }
    assert_contains('opacity: 1 !important', $skipRule);
    assert_contains('animation: none !important', $skipRule, 'focus-out cannot re-hide a delayed entrance');
    assert_true(
        strpos($guard, 'html.motion-js .reveal:focus-within') > strpos($guard, '--motion-stagger-order'),
        'focus escape follows and overrides the stagger animation rule'
    );
});

test('motion driver waits for the main viewport and observes stagger children independently', function () {
    $js = (string) file_get_contents(repo_path('assets/motion/motion.js'));

    // The activation line is 75% down the viewport. Use a height-derived pixel
    // value because IntersectionObserver percentages resolve against root
    // width, producing very different portrait and landscape timings.
    assert_contains('var VIEWPORT_INSET = 0.25;', $js);
    assert_contains('Math.round(height * VIEWPORT_INSET)', $js);
    assert_contains("rootMargin: '0px 0px -' + inset + 'px 0px'", $js);
    assert_contains('threshold: 0.000001', $js, 'near-zero threshold rejects edge contact without stranding tall targets');
    assert_true(!str_contains($js, "rootMargin: '0px 0px -10% 0px'"), 'old peripheral trigger is gone');

    // The authored class stays on the grid, but each card gets its own runtime
    // visibility state. Stacked responsive rows therefore wait for themselves.
    assert_contains('.stagger-children > *', $js);
    assert_contains("target.style.setProperty('--motion-stagger-order', Math.min(row.count, 8))", $js);
    assert_contains('Math.abs(candidate.top - top) <= ROW_TOLERANCE', $js);
    assert_contains('html.motion-js .stagger-children > *.motion-target.is-visible', (string) file_get_contents(
        repo_path('assets/motion/motion.css')
    ));

    // Initial/restored positions are classified synchronously; final-page
    // targets and broken observer implementations both fail open.
    assert_contains('target.getBoundingClientRect()', $js);
    assert_contains("window.addEventListener('scroll', revealAtDocumentEnd", $js);
    assert_contains('entry.intersectionRatio > 0', $js, 'older observer implementations have a ratio fallback');
    assert_contains("target.classList.add('is-visible')", $js);
    assert_contains("root.classList.remove('motion-js')", $js, 'observer failures disable the owned hiding scope');
    assert_contains('catch (error)', $js);
});

test('motion driver runtime contract covers geometry, stagger batches, resize, and fail-open', function () {
    $node = [];
    $nodeExit = 1;
    exec('command -v node 2>/dev/null', $node, $nodeExit);
    if ($nodeExit !== 0 || ($node[0] ?? '') === '') {
        skip_test('Node is unavailable; the static motion contract tests still ran');
    }

    $command = escapeshellarg($node[0]) . ' '
        . escapeshellarg(repo_path('tests/runtime/motion_driver_harness.js')) . ' '
        . escapeshellarg(repo_path('assets/motion/motion.js')) . ' 2>&1';
    $output = [];
    $exit = 0;
    exec($command, $output, $exit);

    assert_eq(0, $exit, implode("\n", $output));
    assert_contains('motion driver runtime harness passed', implode("\n", $output));
});

test('the motion kit reveals a headline word by word inside the reduced-motion guard (frm W8a)', function () {
    $css = (string) file_get_contents(repo_path('assets/motion/motion.css'));
    $guard = strpos($css, '@media screen and (prefers-reduced-motion: no-preference)');
    assert_true($guard !== false);
    foreach ([
        '.word-reveal--split.motion-target:not(.is-visible) .word-reveal__word',
        '.word-reveal--split.motion-target.is-visible .word-reveal__word',
        '@keyframes motion-kit-word',
        '.word-reveal.motion-skip .word-reveal__word',
        '.word-reveal:focus-within .word-reveal__word',
    ] as $needle) {
        $at = strpos($css, $needle);
        assert_true($at !== false && $at > $guard, "{$needle} lives inside the guard");
    }
    $rule = motion_asset_css_block($css, '.word-reveal--split.motion-target.is-visible .word-reveal__word');
    assert_contains('var(--word-index, 0) * var(--motion-word-stagger)', $rule, 'the stagger is a profile token times the word index');
    assert_contains('--motion-word-stagger: 70ms', $css, 'the kit declares the default token');
    foreach (['calm', 'energetic', 'dramatic', 'minimal'] as $profile) {
        $p = (string) file_get_contents(repo_path("assets/motion/profiles/{$profile}.css"));
        assert_contains('--motion-word-stagger:', $p, "{$profile} owns the word stagger");
        assert_contains('--motion-word-keyframe:', $p);
    }
    $keyframe = motion_asset_css_block($css, '@keyframes motion-kit-word');
    assert_true(!str_contains($keyframe, 'translateX') && !str_contains($keyframe, 'rotate'), 'words rise vertically only');

    $js = (string) file_get_contents(repo_path('assets/motion/motion.js'));
    assert_contains('function splitWords()', $js);
    assert_contains("'word-reveal__word'", $js);
    assert_contains("'--word-index'", $js);
    assert_contains("classList.add('word-reveal--split')", $js);
    assert_true(strpos($js, 'splitWords();') < strpos($js, 'querySelectorAll(ENTRANCE_SELECTOR)'), 'words split before targets register');

    assert_true(in_array('word-reveal', \Automattic\SiteBuild\Motion::SCROLL_CLASSES, true));
    assert_true(!in_array('word-reveal', \Automattic\SiteBuild\Motion::noteClasses(), true), 'hero-only: never in the site-wide note');
    assert_true(\Automattic\SiteBuild\Motion::looksLikeMotionClass('word-reveal-fast'), 'invented variants are still motion-flavored');
});
