<?php
declare(strict_types=1);

use Automattic\SiteBuild\Html;
use Automattic\SiteBuild\DesignMarkupSanitizerEngine;
use Automattic\SiteBuild\Steps\HomepageDesignStep;
use Automattic\SiteBuild\Steps\TransformSiteStep;

/** The punctuation libxml double-encodes when it guesses ISO-8859-1. */
const UTF8_SAMPLE = "en\xe2\x80\x93dash em\xe2\x80\x94dash mid\xc2\xb7dot"
    . " \xe2\x80\x9ccurly\xe2\x80\x9d \xe2\x80\x99apos";

/** The mojibake signatures: raw double-encoded bytes and their entity forms. */
function assert_no_mojibake(string $out, string $where): void
{
    // â as raw bytes (c3 a2) or as the &acirc; / numeric entity is the tell.
    assert_true(!str_contains($out, "\xc3\xa2"), "{$where}: raw double-encoded bytes present");
    assert_true(!str_contains($out, '&acirc;'), "{$where}: &acirc; mojibake entity present");
    assert_true(!str_contains($out, '&Acirc;'), "{$where}: &Acirc; mojibake entity present");
}

function assert_no_xml_leak(string $out, string $where): void
{
    assert_true(!str_contains($out, '<?xml'), "{$where}: stray <?xml processing-instruction leaked");
}

/** Reflect a private static method call. */
function call_private_static(string $class, string $method, array $args): mixed
{
    $ref = new ReflectionMethod($class, $method);
    $ref->setAccessible(true);
    return $ref->invoke(null, ...$args);
}

test('Html::loadUtf8Html keeps UTF-8 punctuation single-encoded on element saveHTML', function () {
    $dom = Html::loadUtf8Html(
        '<html><body><main><p>' . UTF8_SAMPLE . '</p></main></body></html>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
    );
    assert_true($dom !== null, 'document parsed');
    $main = $dom->getElementsByTagName('main')->item(0);
    $out = (string) $dom->saveHTML($main);
    assert_contains("\xe2\x80\x93", $out, 'en-dash stays single-encoded');
    assert_no_mojibake($out, 'element saveHTML');
    assert_no_xml_leak($out, 'element saveHTML');
});

test('Html::loadUtf8Html keeps UTF-8 punctuation single-encoded on whole-doc saveHTML', function () {
    $dom = Html::loadUtf8Html(
        '<html><body><main><p>' . UTF8_SAMPLE . '</p></main></body></html>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
    );
    assert_true($dom !== null, 'document parsed');
    $out = (string) $dom->saveHTML();
    assert_no_mojibake($out, 'whole-doc saveHTML');
    assert_no_xml_leak($out, 'whole-doc saveHTML');
});

test('TransformSiteStep::extractPage serializes sections without mojibake', function () {
    $html = '<html><body><main><section id="hero"><h2>' . UTF8_SAMPLE
        . '</h2><p>' . UTF8_SAMPLE . '</p></section></main></body></html>';
    $page = call_private_static(TransformSiteStep::class, 'extractPage', [$html, 'services']);
    $section = $page['sections'][0]['html'] ?? '';
    assert_contains("\xe2\x80\x93", $section, 'en-dash survives extractPage');
    assert_no_mojibake($section, 'extractPage section');
    assert_no_xml_leak($section, 'extractPage section');
});

test('HomepageDesignStep::loadDocument does not double-encode or leak <?xml', function () {
    $dom = call_private_static(
        HomepageDesignStep::class,
        'loadDocument',
        ['<html><body><main><p>' . UTF8_SAMPLE . '</p></main></body></html>'],
    );
    assert_true($dom !== null, 'document parsed');
    $out = (string) $dom->saveHTML();
    assert_no_mojibake($out, 'HomepageDesignStep whole-doc');
    assert_no_xml_leak($out, 'HomepageDesignStep whole-doc');
});

test('DesignMarkupSanitizerEngine::loadDocument does not double-encode attribute/text', function () {
    $dom = call_private_static(
        DesignMarkupSanitizerEngine::class,
        'loadDocument',
        ['<html><body><main><p title="' . UTF8_SAMPLE . '">' . UTF8_SAMPLE . '</p></main></body></html>'],
    );
    assert_true($dom !== null, 'document parsed');
    $out = (string) $dom->saveHTML();
    assert_no_mojibake($out, 'DesignMarkupSanitizerEngine whole-doc');
    assert_no_xml_leak($out, 'DesignMarkupSanitizerEngine whole-doc');
});
