<?php
declare(strict_types=1);

test('a static marquee inside a column reads at section-title scale (frm PR-8m)', function () {
    $css = (string) file_get_contents(repo_path('assets/motion/motion.css'));
    $flat = preg_replace('/\s+/', ' ', $css);
    assert_contains('@media screen and (prefers-reduced-motion: reduce) { .marquee { white-space: normal;', $flat, 'the reduced-motion branch still wraps the line');
    assert_contains('.wp-block-column .marquee { font-size: var(--wp--preset--font-size--section-title, 2rem); }', $flat);
    assert_contains('html:not(.motion-js) .wp-block-column .marquee { font-size: var(--wp--preset--font-size--section-title, 2rem); }', $flat, 'the no-script branch matches');
});
