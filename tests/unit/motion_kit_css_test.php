<?php
declare(strict_types=1);

use Automattic\SiteBuild\HeaderBehavior;
use Automattic\SiteBuild\Motion;

test('Motion strips authored kit animations so the kit is the only player', function () {
    $css = <<<CSS
.hero-copy { max-width: 40rem; }
.reveal-up { animation: revealUp .9s both; }
.stagger-children > * { animation: revealUp .8s both; }
.stagger-children > *:nth-child(1) { animation-delay: .05s; }
.ken-burns img { animation: kenburns 26s infinite; }
@keyframes kenburns { from { transform: scale(1); } to { transform: scale(1.06); } }
@keyframes revealUp { from { opacity: 0; } to { opacity: 1; } }
.card-title { font-size: 1.2rem; }
CSS;
    $out = Motion::stripAuthoredKitCss($css);
    assert_contains('.hero-copy { max-width: 40rem; }', $out);
    assert_contains('.card-title { font-size: 1.2rem; }', $out);
    assert_true(!str_contains($out, '.reveal-up'));
    assert_true(!str_contains($out, '.stagger-children'));
    assert_true(!str_contains($out, '.ken-burns'));
    assert_true(!str_contains($out, '@keyframes kenburns'));
    assert_true(!str_contains($out, '@keyframes revealUp'));
});

test('header chrome rejects chromatic support hues and keeps near-grays', function () {
    assert_true(HeaderBehavior::isChromeBarColor('base', '#EFE3D2'));
    assert_true(HeaderBehavior::isChromeBarColor('contrast', '#2E211C'));
    assert_true(HeaderBehavior::isChromeBarColor('secondary', '#E5E7EB'));
    assert_true(!HeaderBehavior::isChromeBarColor('secondary', '#7E9070'));
    assert_true(!HeaderBehavior::isChromeBarColor('accent', '#5B2E63'));
    assert_true(!HeaderBehavior::isChromeBarColor('primary', '#C05A3B'));
});
