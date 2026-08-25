<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Remove the smallest complete blocks that publish contact facts absent from siteSpec. */
final class GroundedContactMarkup
{
    private const MAX_SVG_DATA_BYTES = 1048576;
    private const CONTEXT_OFFSET_ATTRIBUTE = 'data-msb-grounding-offset-7d42a1';
    private const CONTEXT_SCOPE_ATTRIBUTE = 'data-msb-grounding-scope-7d42a1';
    private const FORM_CONTEXT_ATTRIBUTE = 'data-msb-grounding-form-7d42a1';

    /**
     * @param array<mixed> $siteSpec
     * @param list<string> $warnings
     */
    public static function scrub(
        string $markup,
        array $siteSpec,
        string $file,
        array &$warnings = [],
    ): string {
        $allowed = ContactFacts::candidateSetFromSpec($siteSpec);
        $document = BlockMarkup::parse($markup);
        $targets = [];

        foreach ($document->indices() as $index) {
            $ungrounded = ContactFacts::ungroundedAgainstSet(
                self::blockCandidates($document, $index, []),
                $allowed,
            );
            if ($ungrounded === []) {
                continue;
            }
            $end = $document->endOffset($index);
            if ($end === null || !$document->isStructurallySafe($index)) {
                continue;
            }
            $targets[$index] = $ungrounded;
        }

        // A parent removal already contains every selected descendant. Keep one
        // isolated range and one truthful warning rather than overlapping edits.
        foreach (array_keys($targets) as $index) {
            $parent = $document->parent($index);
            while ($parent !== null) {
                if (isset($targets[$parent])) {
                    unset($targets[$index]);
                    break;
                }
                $parent = $document->parent($parent);
            }
        }
        $targets = self::expandNewlyEmptyStructuralAncestors($document, $targets, $markup);

        $ops = [];
        foreach ($targets as $index => $ungrounded) {
            $start = $document->openingOffset($index);
            $end = (int) $document->endOffset($index);
            $ops[] = ['start' => $start, 'length' => $end - $start];
            $warnings[] = self::warning(
                $file,
                self::blockPath($document, $index),
                array_values(array_unique(array_column($ungrounded, 'authored'))),
            );
        }
        usort($ops, static fn (array $left, array $right): int => $right['start'] <=> $left['start']);
        foreach ($ops as $op) {
            $markup = substr_replace($markup, '', $op['start'], $op['length']);
        }

        // Contextual exposure can disappear when an intrinsically unsafe
        // carrier is removed (for example an image that activated a map).
        // Recompute after intrinsic removals and isolate one contextual block
        // at a time so every later decision observes the delivered document.
        while (true) {
            $contextDocument = BlockMarkup::parse($markup);
            $documentContext = self::documentContextCandidates($markup, $contextDocument);
            $contextTargets = [];
            foreach ($contextDocument->indices() as $index) {
                $ungrounded = ContactFacts::ungroundedAgainstSet(
                    self::blockCandidates($contextDocument, $index, $documentContext[$index] ?? []),
                    $allowed,
                );
                $end = $contextDocument->endOffset($index);
                if ($ungrounded === [] || $end === null || !$contextDocument->isStructurallySafe($index)) {
                    continue;
                }
                $contextTargets[$index] = $ungrounded;
                break;
            }
            if ($contextTargets === []) {
                break;
            }
            $contextTargets = self::expandNewlyEmptyStructuralAncestors(
                $contextDocument,
                $contextTargets,
                $markup,
            );
            $index = array_key_first($contextTargets);
            if (!is_int($index)) {
                break;
            }
            $start = $contextDocument->openingOffset($index);
            $end = $contextDocument->endOffset($index);
            if ($end === null) {
                break;
            }
            $warnings[] = self::warning(
                $file,
                self::blockPath($contextDocument, $index),
                array_values(array_unique(array_column($contextTargets[$index], 'authored'))),
            );
            $ops[] = ['start' => $start, 'length' => $end - $start];
            $markup = substr_replace($markup, '', $start, $end - $start);
        }

        // Generated markup may contain non-block or malformed residue. If an
        // ungrounded contact token cannot be isolated to a complete block, fail
        // closed at this file boundary rather than deliver the invented fact.
        $residualDocument = BlockMarkup::parse($markup);
        $residualCandidates = [];
        $documentContext = self::documentContextCandidates($markup, $residualDocument);
        foreach ($residualDocument->indices() as $index) {
            array_push(
                $residualCandidates,
                ...self::blockCandidates($residualDocument, $index, $documentContext[$index] ?? []),
            );
        }
        $orphanMarkup = $markup;
        $rootRanges = [];
        foreach ($residualDocument->indices() as $index) {
            if ($residualDocument->parent($index) !== null || $residualDocument->endOffset($index) === null) {
                continue;
            }
            $start = $residualDocument->openingOffset($index);
            $rootRanges[] = ['start' => $start, 'length' => (int) $residualDocument->endOffset($index) - $start];
        }
        usort($rootRanges, static fn (array $left, array $right): int => $right['start'] <=> $left['start']);
        foreach ($rootRanges as $range) {
            $orphanMarkup = substr_replace($orphanMarkup, '', $range['start'], $range['length']);
        }
        array_push($residualCandidates, ...ContactFacts::visibleCandidates(PlainText::fromMarkup($orphanMarkup, true)));
        array_push($residualCandidates, ...self::destinationCandidates($orphanMarkup));
        array_push($residualCandidates, ...self::htmlAccessibleAttributeCandidates($orphanMarkup));
        array_push($residualCandidates, ...($documentContext['orphan'] ?? []));
        $residualCandidates = self::dedupe($residualCandidates);
        $residual = ContactFacts::ungroundedAgainstSet($residualCandidates, $allowed);
        if ($residual !== []) {
            $warnings[] = self::warning(
                $file,
                'document (contact token was outside a complete block)',
                array_values(array_unique(array_column($residual, 'authored'))),
            );
            return '';
        }

        if ($ops !== [] && !self::hasMaterialContent($markup)) {
            return '';
        }

        return $markup;
    }

    /** An empty layout shell is not a usable generated part. */
    private static function hasMaterialContent(string $markup): bool
    {
        if (trim(PlainText::fromMarkup($markup, true)) !== '') {
            return true;
        }
        if (preg_match('/<(?:audio|button|canvas|embed|form|hr|iframe|img|input|object|picture|svg|video)\b/iu', $markup) === 1) {
            return true;
        }
        $document = BlockMarkup::parse($markup);
        foreach ($document->indices() as $index) {
            $name = $document->name($index);
            if ($name === 'navigation' && isset($document->attrs($index)['ref'])) {
                return true;
            }
            if (!in_array($name, [
                    'button', 'buttons', 'column', 'columns', 'group', 'list', 'navigation', 'pullquote',
                    'quote', 'social-links',
                ], true)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,list<array{type:string,authored:string,canonical:string}>> $targets
     * @return array<int,list<array{type:string,authored:string,canonical:string}>>
     */
    private static function expandNewlyEmptyStructuralAncestors(
        BlockMarkup $document,
        array $targets,
        string $markup,
    ): array {
        while (true) {
            foreach (array_keys($targets) as $index) {
                $parent = $document->parent($index);
                if ($parent === null
                    || !in_array(
                        $document->name($parent),
                        [
                            'buttons', 'column', 'columns', 'group', 'list', 'navigation', 'pullquote', 'quote',
                            'social-links',
                        ],
                        true,
                    )
                ) {
                    continue;
                }
                $parentStart = $document->openingOffset($parent);
                $parentEnd = $document->endOffset($parent);
                if ($parentEnd === null) {
                    continue;
                }
                $contained = [];
                $facts = [];
                foreach ($targets as $target => $ungrounded) {
                    $targetStart = $document->openingOffset($target);
                    $targetEnd = $document->endOffset($target);
                    if ($targetEnd === null || $targetStart < $parentStart || $targetEnd > $parentEnd) {
                        continue;
                    }
                    $contained[$target] = [
                        'start' => $targetStart - $parentStart,
                        'length' => $targetEnd - $targetStart,
                    ];
                    array_push($facts, ...$ungrounded);
                }
                $candidate = substr($markup, $parentStart, $parentEnd - $parentStart);
                uasort(
                    $contained,
                    static fn (array $left, array $right): int => $right['start'] <=> $left['start'],
                );
                foreach ($contained as $range) {
                    $candidate = substr_replace($candidate, '', $range['start'], $range['length']);
                }
                if (self::hasMaterialContent($candidate)) {
                    continue;
                }
                foreach (array_keys($contained) as $target) {
                    unset($targets[$target]);
                }
                $targets[$parent] = self::dedupe($facts);
                continue 2;
            }
            return $targets;
        }
    }

    /**
     * Read contact-shaped copy from visible text, while limiting block-comment
     * and tag attributes to actual URL/email destinations. This keeps numeric
     * block IDs and CSS values from masquerading as phone numbers.
     *
     * @return list<array{type:string,authored:string,canonical:string}>
     */
    private static function blockCandidates(
        BlockMarkup $document,
        int $index,
        array $documentContext,
    ): array
    {
        $ownHtml = $document->ownHtml($index);
        // Hidden is not a durable visibility boundary: separately authored CSS
        // can override it through any selector, so contact copy there must be
        // grounded just like initially visible copy.
        $visible = ContactFacts::visibleCandidates(PlainText::fromMarkup($ownHtml, true));
        $structural = self::destinationCandidates($ownHtml);
        array_push($structural, ...self::htmlAccessibleAttributeCandidates($ownHtml));
        array_push($structural, ...$documentContext);
        array_push($structural, ...self::selectedBlockAttributeCandidates(
            $document->name($index),
            $document->attrs($index),
        ));

        return self::dedupe(array_merge($visible, $structural));
    }

    /** @return list<array{type:string,authored:string,canonical:string}> */
    private static function destinationCandidates(
        string $fragment,
        bool $isolatedValue = false,
        bool $cssValue = false,
        string $listKind = 'single',
    ): array
    {
        if (!$isolatedValue) {
            $candidates = [];
            HtmlBlockContext::rewriteOpeningTags(
                $fragment,
                static function (string $tag, string $namespace) use (&$candidates): string {
                    preg_match('/^<\s*([^\s\/>]+)/u', $tag, $tagMatch);
                    $tagName = strtolower((string) ($tagMatch[1] ?? ''));
                    $attributes = [];
                    foreach (MarkupSanitizer::openingTagAttributes($tag) as $attribute) {
                        if (!isset($attributes[$attribute['name']])) {
                            $attributes[$attribute['name']] = $attribute;
                        }
                    }
                    foreach ($attributes as $attribute) {
                        if ($attribute['valueStart'] === null
                            || !self::isHtmlDestinationAttribute(
                                $tagName,
                                $attribute['name'],
                                $tag,
                                $attributes,
                                $namespace,
                            )
                        ) {
                            continue;
                        }
                        $value = substr(
                            $tag,
                            $attribute['valueStart'],
                            $attribute['valueEnd'] - $attribute['valueStart'],
                        );
                        $presentation = self::isSvgPresentationAttribute(
                            $tagName,
                            $attribute['name'],
                            $namespace,
                        );
                        array_push($candidates, ...self::destinationCandidates(
                            $presentation ? $attribute['name'] . ':' . $value : $value,
                            true,
                            $attribute['name'] === 'style' || $presentation,
                            in_array($attribute['name'], ['imagesrcset', 'srcset'], true)
                                ? 'srcset'
                                : (in_array($attribute['name'], ['attributionsrc', 'ping'], true)
                                    ? 'space-urls'
                                    : 'single'),
                        ));
                    }
                    return $tag;
                },
                true,
            );
            return self::dedupe($candidates);
        }

        $fragment = LinkTargets::decodeBrowserEntities($fragment);
        if ($cssValue) {
            return self::cssDestinationCandidates($fragment);
        }
        if ($listKind === 'srcset') {
            $candidates = [];
            foreach (self::srcsetUrls($fragment) as $url) {
                array_push($candidates, ...self::destinationCandidates($url, true));
            }
            return self::dedupe($candidates);
        }
        if ($listKind === 'space-urls') {
            $candidates = [];
            foreach (preg_split('/[\x09\x0A\x0C\x0D\x20]+/', $fragment, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $url) {
                array_push($candidates, ...self::destinationCandidates($url, true));
            }
            return self::dedupe($candidates);
        }
        $fragment = preg_replace('/[\x09\x0A\x0D]/', '', $fragment) ?? $fragment;
        $fragment = preg_replace('/^[\x00-\x20]+|[\x00-\x20]+$/', '', $fragment) ?? $fragment;
        if (preg_match('/^data:/iu', $fragment) === 1) {
            return self::dedupe(self::svgDataCandidates($fragment));
        }
        $structural = ContactFacts::exactDestinationCandidates($fragment);
        array_push($structural, ...self::svgDataCandidates($fragment));
        preg_match_all(
            '/^([a-z][a-z0-9+.-]*):([^"\'<>\s]+)/iu',
            $fragment,
            $schemeValues,
            PREG_SET_ORDER,
        );
        foreach ($schemeValues as $schemeValue) {
            $scheme = strtolower((string) ($schemeValue[1] ?? ''));
            $payload = (string) ($schemeValue[2] ?? '');
            if ($scheme === 'mailto') {
                array_push($structural, ...self::mailtoCandidates($payload));
            } elseif (in_array($scheme, ['tel', 'fax', 'sms', 'smsto', 'mms', 'mmsto', 'callto'], true)
            ) {
                array_push($structural, ...self::telephoneUriCandidates($payload));
            } elseif (in_array($scheme, [
                    'facetime', 'facetime-audio', 'irc', 'signal', 'sip', 'sips', 'skype', 'webcal',
                    'whatsapp', 'xmpp',
                ], true)
            ) {
                continue;
            } elseif (in_array($scheme, ['ftp', 'http', 'https'], true)) {
                continue;
            } else {
                $payloadCandidates = ContactFacts::visibleCandidates(rawurldecode($payload));
                array_push($structural, ...array_values(array_filter(
                    $payloadCandidates,
                    static fn (array $candidate): bool => $candidate['type'] !== 'phone',
                )));
            }
        }

        return self::dedupe($structural);
    }

    /**
     * Parse the URL token from each srcset candidate. Commas inside the URL
     * remain URL bytes; only a descriptor-level comma ends the candidate.
     *
     * @return list<string>
     */
    private static function srcsetUrls(string $value): array
    {
        $urls = [];
        $length = strlen($value);
        $offset = 0;

        while ($offset < $length) {
            while ($offset < $length
                && (str_contains("\t\n\f\r ", $value[$offset]) || $value[$offset] === ',')
            ) {
                $offset++;
            }
            if ($offset >= $length) {
                break;
            }

            $start = $offset;
            while ($offset < $length && !str_contains("\t\n\f\r ", $value[$offset])) {
                $offset++;
            }
            $url = substr($value, $start, $offset - $start);
            if (str_ends_with($url, ',')) {
                $url = rtrim($url, ',');
                if ($url !== '') {
                    $urls[] = $url;
                }
                continue;
            }
            $descriptorStart = $offset;
            $parentheses = 0;
            while ($offset < $length) {
                $byte = $value[$offset];
                if ($byte === '(') {
                    $parentheses++;
                } elseif ($byte === ')' && $parentheses > 0) {
                    $parentheses--;
                } elseif ($byte === ',' && $parentheses === 0) {
                    $offset++;
                    break;
                }
                $offset++;
            }
            $descriptorEnd = $offset;
            if ($descriptorEnd > $descriptorStart && $value[$descriptorEnd - 1] === ',') {
                $descriptorEnd--;
            }
            $descriptors = substr($value, $descriptorStart, $descriptorEnd - $descriptorStart);
            if ($url !== '' && self::validSrcsetDescriptors($descriptors)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private static function validSrcsetDescriptors(string $value): bool
    {
        $tokens = preg_split(
            '/[\x09\x0A\x0C\x0D\x20]+/',
            trim($value, "\x09\x0A\x0C\x0D\x20"),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $width = false;
        $density = false;
        $height = false;
        foreach ($tokens as $token) {
            if (preg_match('/^[1-9][0-9]*w$/', $token) === 1) {
                if ($width || $density) {
                    return false;
                }
                $width = true;
                continue;
            }
            if (preg_match('/^(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:e[+-]?[0-9]+)?x$/i', $token) === 1) {
                if ($density || $width || (float) substr($token, 0, -1) <= 0) {
                    return false;
                }
                $density = true;
                continue;
            }
            if (preg_match('/^[1-9][0-9]*h$/', $token) === 1) {
                if ($height) {
                    return false;
                }
                $height = true;
                continue;
            }
            return false;
        }
        return !$height || $width;
    }

    /** @param array<string,array{name:string,valueStart:?int,valueEnd:?int}> $attributes */
    private static function isHtmlDestinationAttribute(
        string $tagName,
        string $attribute,
        string $tag,
        array $attributes,
        string $namespace,
    ): bool
    {
        if ($attribute === 'style' || self::isSvgPresentationAttribute($tagName, $attribute, $namespace)) {
            return true;
        }
        if ($namespace === 'math') {
            return in_array($attribute, ['href', 'xlink:href'], true);
        }
        if ($namespace === 'svg') {
            $svgTags = match ($attribute) {
                'href', 'xlink:href' => [
                    'a', 'animate', 'feimage', 'filter', 'image', 'lineargradient', 'mpath', 'pattern',
                    'radialgradient', 'textpath', 'use',
                ],
                default => [],
            };
            return in_array($tagName, $svgTags, true);
        }
        if ($tagName === 'input' && $attribute === 'src') {
            return self::htmlInputType($tag, $attributes) === 'image';
        }
        if ($attribute === 'formaction') {
            return ($tagName === 'button' && self::htmlButtonType($tag, $attributes) === 'submit')
                || ($tagName === 'input'
                    && in_array(self::htmlInputType($tag, $attributes), ['image', 'submit'], true));
        }
        $tags = match ($attribute) {
            'action' => ['form'],
            'attributionsrc' => ['a', 'img'],
            'background' => ['body', 'table', 'td', 'th'],
            'cite' => ['blockquote', 'del', 'ins', 'q'],
            'data' => ['object'],
            'href' => ['a', 'area', 'base', 'link'],
            'imagesrcset' => ['link'],
            'longdesc' => ['iframe', 'img'],
            'ping' => ['a', 'area'],
            'poster' => ['video'],
            'src' => ['audio', 'embed', 'iframe', 'img', 'script', 'source', 'track', 'video'],
            'srcset' => ['img', 'source'],
            default => [],
        };
        return in_array($tagName, $tags, true);
    }

    /** @param array<string,array{name:string,valueStart:?int,valueEnd:?int}> $attributes */
    private static function htmlAttributeValue(string $tag, array $attributes, string $name): string
    {
        $attribute = $attributes[$name] ?? null;
        if (!is_array($attribute) || $attribute['valueStart'] === null) {
            return '';
        }
        return strtolower(LinkTargets::decodeBrowserEntities(substr(
            $tag,
            $attribute['valueStart'],
            $attribute['valueEnd'] - $attribute['valueStart'],
        )));
    }

    /** @param array<string,array{name:string,valueStart:?int,valueEnd:?int}> $attributes */
    private static function htmlInputType(string $tag, array $attributes): string
    {
        $type = self::htmlAttributeValue($tag, $attributes, 'type');
        return in_array($type, [
            'button', 'checkbox', 'color', 'date', 'datetime-local', 'email', 'file', 'hidden', 'image',
            'month', 'number', 'password', 'radio', 'range', 'reset', 'search', 'submit', 'tel', 'text',
            'time', 'url', 'week',
        ], true) ? $type : 'text';
    }

    /** @param array<string,array{name:string,valueStart:?int,valueEnd:?int}> $attributes */
    private static function htmlButtonType(string $tag, array $attributes): string
    {
        $type = self::htmlAttributeValue($tag, $attributes, 'type');
        return in_array($type, ['button', 'reset', 'submit'], true) ? $type : 'submit';
    }

    private static function isSvgPresentationAttribute(
        string $tagName,
        string $attribute,
        string $namespace,
    ): bool
    {
        if ($namespace !== 'svg' || !in_array($attribute, [
                'clip-path', 'cursor', 'fill', 'filter', 'marker', 'marker-end', 'marker-mid', 'marker-start',
                'mask', 'stroke',
            ], true)
        ) {
            return false;
        }
        return in_array($tagName, [
            'a', 'animate', 'animatemotion', 'animatetransform', 'circle', 'clippath', 'ellipse', 'feimage',
            'defs', 'filter', 'foreignobject', 'g', 'image', 'line', 'marker', 'mask', 'path', 'pattern', 'polygon',
            'polyline', 'rect', 'set', 'svg', 'switch', 'symbol', 'text', 'textpath', 'tspan', 'use',
        ], true);
    }

    /** @return list<array{type:string,authored:string,canonical:string}> */
    public static function svgDataCandidates(string $url): array
    {
        if (preg_match('/^data:([^,]*),(.*)$/isu', $url, $match) !== 1) {
            return [];
        }
        $meta = explode(';', $match[1]);
        $mediaType = strtolower(trim((string) array_shift($meta), " \t\n\r\f"));
        if ($mediaType !== 'image/svg+xml') {
            return [];
        }
        $body = explode('#', $match[2], 2)[0];
        $body = rawurldecode($body);
        $base64 = $meta !== []
            && strtolower(trim((string) $meta[array_key_last($meta)], " \t\n\r\f")) === 'base64';
        $payload = $base64
            ? base64_decode(preg_replace('/[\x09\x0A\x0C\x0D\x20]+/', '', $body) ?? $body, true)
            : $body;
        if (!is_string($payload)) {
            return [];
        }
        if (strlen($payload) > self::MAX_SVG_DATA_BYTES) {
            return [self::uninspectableSvgCandidate($payload, 'oversize')];
        }
        if (self::hasUnsafeSvgEntityDeclaration(self::xmlGuardText($payload))) {
            return [self::uninspectableSvgCandidate($payload, 'external-entity')];
        }
        if (!class_exists(\DOMDocument::class)) {
            return [self::uninspectableSvgCandidate($payload, 'dom-unavailable')];
        }
        $normalizedDocument = self::parseSvgDocument($payload, false);
        if ($normalizedDocument === null) {
            return [];
        }
        $normalizedPayload = $normalizedDocument->saveXML();
        if (!is_string($normalizedPayload)) {
            return [self::uninspectableSvgCandidate($payload, 'normalization')];
        }
        if (self::hasUnsafeSvgEntityDeclaration($normalizedPayload)) {
            return [self::uninspectableSvgCandidate($payload, 'external-entity')];
        }
        $document = self::parseSvgDocument($normalizedPayload, true);
        if ($document === null) {
            return [];
        }

        $styleSheets = self::svgStylesheetInstructionCss($document);
        $candidates = self::svgStylesheetInstructionCandidates($document);
        $remove = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            $name = strtolower($element->localName);
            if ($name === 'style'
                && in_array(strtolower(trim($element->getAttribute('type'))), ['', 'text/css'], true)
                && self::normalizedCssMedia($element->getAttribute('media')) !== 'not all'
            ) {
                $media = trim($element->getAttribute('media'));
                $styleSheets[] = $media === '' || strtolower($media) === 'all'
                    ? $element->textContent
                    : '@media ' . $media . '{' . $element->textContent . '}';
            }
            if (in_array($name, ['desc', 'metadata', 'script', 'style', 'title'], true)) {
                $remove[] = $element;
            }
        }
        foreach ($remove as $element) {
            $element->parentNode?->removeChild($element);
        }

        $markup = $document->saveXML($document->documentElement);
        if (!is_string($markup)) {
            return [self::uninspectableSvgCandidate($payload, 'serialization')];
        }
        array_push($candidates, ...ContactFacts::visibleCandidates(PlainText::fromMarkup($markup, true)));
        array_push($candidates, ...self::destinationCandidates($markup));
        array_push($candidates, ...self::svgXhtmlNativeAttributeCandidates($document, $styleSheets));
        foreach ($styleSheets as $css) {
            array_push($candidates, ...CssScrub::generatedTextCandidates($css, false, true, $markup));
            foreach (CssScrub::scrub($css)['removals'] as $removal) {
                if ($removal['kind'] === 'import') {
                    $candidates[] = [
                        'type' => 'generated_text',
                        'authored' => $removal['authored_value'],
                        'canonical' => 'uninspectable-svg-css-import:'
                            . hash('sha256', $removal['authored_value']),
                    ];
                }
            }
            if (CssScrub::hasResourceIndirection($css)) {
                $candidates[] = [
                    'type' => 'url',
                    'authored' => $css,
                    'canonical' => 'unresolved-svg-css-resource:' . hash('sha256', $css),
                ];
            }
            foreach (CssScrub::resourceUrls($css) as $resource) {
                array_push($candidates, ...self::destinationCandidates($resource, true));
            }
        }
        return self::dedupe($candidates);
    }

    /** @return list<array{type:string,authored:string,canonical:string}> */
    private static function svgXhtmlNativeAttributeCandidates(\DOMDocument $document, array $styleSheets = []): array
    {
        $candidates = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->namespaceURI !== 'http://www.w3.org/1999/xhtml'
                || ($element->parentNode instanceof \DOMElement
                    && $element->parentNode->namespaceURI === 'http://www.w3.org/1999/xhtml')
            ) {
                continue;
            }
            $fragment = $document->saveXML($element);
            if (is_string($fragment)) {
                array_push($candidates, ...self::htmlNativeAttributeCandidates($fragment));
                $styledFragment = '';
                foreach ($styleSheets as $css) {
                    $styledFragment .= '<style>' . str_replace('</style', '<\/style', $css) . '</style>';
                }
                $styledFragment .= $fragment;
                foreach (self::documentContextCandidates(
                    $styledFragment,
                    BlockMarkup::parse($styledFragment),
                    false,
                ) as $context) {
                    array_push($candidates, ...$context);
                }
            }
        }
        return self::dedupe($candidates);
    }

    /** @return list<string> */
    private static function svgStylesheetInstructionCss(\DOMDocument $document): array
    {
        $css = [];
        foreach ($document->childNodes as $node) {
            if (!$node instanceof \DOMProcessingInstruction
                || strtolower($node->target) !== 'xml-stylesheet'
                || !self::svgStylesheetInstructionIsActive($node)
            ) {
                continue;
            }
            $href = self::xmlPseudoAttribute($node->data, 'href');
            if ($href === null) {
                continue;
            }
            $payload = self::cssDataPayload($href);
            if ($payload !== null) {
                $media = trim((string) self::xmlPseudoAttribute($node->data, 'media'));
                $css[] = $media === '' || strtolower($media) === 'all'
                    ? $payload
                    : '@media ' . $media . '{' . $payload . '}';
            }
        }
        return $css;
    }

    /** @return list<array{type:string,authored:string,canonical:string}> */
    private static function svgStylesheetInstructionCandidates(\DOMDocument $document): array
    {
        $candidates = [];
        foreach ($document->childNodes as $node) {
            if (!$node instanceof \DOMProcessingInstruction
                || strtolower($node->target) !== 'xml-stylesheet'
                || !self::svgStylesheetInstructionIsActive($node)
            ) {
                continue;
            }
            $href = self::xmlPseudoAttribute($node->data, 'href');
            if ($href !== null) {
                array_push($candidates, ...self::destinationCandidates($href, true));
                if (preg_match('/^data:text\/css(?:[;,]|$)/iu', $href) === 1
                    && self::cssDataPayload($href) === null
                ) {
                    $candidates[] = [
                        'type' => 'generated_text',
                        'authored' => $href,
                        'canonical' => 'uninspectable-svg-css-data:' . hash('sha256', $href),
                    ];
                }
            }
        }
        return self::dedupe($candidates);
    }

    private static function svgStylesheetInstructionIsActive(\DOMProcessingInstruction $node): bool
    {
        $type = self::xmlPseudoAttribute($node->data, 'type');
        if ($type !== null && strtolower(trim($type)) !== 'text/css') {
            return false;
        }
        $media = self::xmlPseudoAttribute($node->data, 'media');
        if ($media !== null && self::normalizedCssMedia($media) === 'not all') {
            return false;
        }
        $alternate = self::xmlPseudoAttribute($node->data, 'alternate');
        return $alternate === null || strtolower(trim($alternate)) !== 'yes';
    }

    private static function normalizedCssMedia(string $media): string
    {
        $media = preg_replace('/\/\*.*?\*\//su', ' ', $media) ?? $media;
        $queries = self::cssMediaQueries($media);
        if ($queries === null) {
            return $media;
        }
        $hasQuery = false;
        foreach ($queries as $query) {
            if (trim($query, "\x09\x0A\x0C\x0D\x20") === '') {
                continue;
            }
            $hasQuery = true;
            if (!self::cssMediaQueryIsProvablyInactive($query)) {
                return $media;
            }
        }
        return $hasQuery || count($queries) > 1 ? 'not all' : $media;
    }

    public static function cssMediaIsInactive(string $media): bool
    {
        return self::normalizedCssMedia($media) === 'not all';
    }

    /** @return list<string>|null */
    private static function cssMediaQueries(string $media): ?array
    {
        $queries = [];
        $query = '';
        $length = strlen($media);
        for ($offset = 0; $offset < $length;) {
            if ($media[$offset] === '\\') {
                $end = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSyntaxScanner::escapeEnd(
                    $media,
                    $offset,
                );
                if ($end === null) {
                    return null;
                }
                $query .= substr($media, $offset, $end - $offset);
                $offset = $end;
                continue;
            }
            if ($media[$offset] === ',') {
                $queries[] = $query;
                $query = '';
                $offset++;
                continue;
            }
            $query .= $media[$offset];
            $offset++;
        }
        $queries[] = $query;
        return $queries;
    }

    private static function cssMediaQueryIsProvablyInactive(string $query): bool
    {
        $offset = self::skipCssMediaWhitespace($query, 0);
        $first = self::cssMediaIdentifierAt($query, $offset);
        if ($first === null) {
            return false;
        }
        $name = strtolower($first['value']);
        if ($name === 'not') {
            $second = self::cssMediaIdentifierAt(
                $query,
                self::skipCssMediaWhitespace($query, $first['end']),
            );
            return $second !== null && strtolower($second['value']) === 'all';
        }
        if ($name === 'only') {
            $second = self::cssMediaIdentifierAt(
                $query,
                self::skipCssMediaWhitespace($query, $first['end']),
            );
            return $second !== null
                && !in_array(strtolower($second['value']), ['all', 'print', 'screen', 'speech'], true);
        }
        return !in_array($name, ['all', 'print', 'screen', 'speech'], true);
    }

    /** @return array{end:int,value:string}|null */
    private static function cssMediaIdentifierAt(string $query, int $offset): ?array
    {
        $start = $offset;
        $length = strlen($query);
        while ($offset < $length) {
            if ($query[$offset] === '\\') {
                $end = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSyntaxScanner::escapeEnd(
                    $query,
                    $offset,
                );
                if ($end === null) {
                    return null;
                }
                $offset = $end;
                continue;
            }
            $byte = ord($query[$offset]);
            if (!(($byte >= 48 && $byte <= 57)
                || ($byte >= 65 && $byte <= 90)
                || ($byte >= 97 && $byte <= 122)
                || $query[$offset] === '-'
                || $query[$offset] === '_'
                || $byte >= 128)
            ) {
                break;
            }
            $offset++;
        }
        if ($offset === $start) {
            return null;
        }
        $value = CssScrub::decodeCssEscapes(substr($query, $start, $offset - $start));
        return $value === null ? null : ['end' => $offset, 'value' => $value];
    }

    private static function skipCssMediaWhitespace(string $query, int $offset): int
    {
        $length = strlen($query);
        while ($offset < $length && str_contains("\x09\x0A\x0C\x0D\x20", $query[$offset])) {
            $offset++;
        }
        return $offset;
    }

    private static function xmlPseudoAttribute(string $data, string $name): ?string
    {
        if (preg_match(
            '/(?:^|[\x09\x0A\x0D\x20])' . preg_quote($name, '/')
                . '[\x09\x0A\x0D\x20]*=[\x09\x0A\x0D\x20]*(["\'])(.*?)\1/isu',
            $data,
            $match,
        ) !== 1) {
            return null;
        }
        return html_entity_decode((string) $match[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function cssDataPayload(string $url): ?string
    {
        if (preg_match('/^data:([^,]*),(.*)$/isu', $url, $match) !== 1) {
            return null;
        }
        $meta = explode(';', $match[1]);
        if (strtolower(trim((string) array_shift($meta), " \t\n\r\f")) !== 'text/css') {
            return null;
        }
        $charset = null;
        foreach ($meta as $parameter) {
            if (preg_match('/^\s*charset\s*=\s*["\']?([^"\'\s;]+)["\']?\s*$/iu', $parameter, $charsetMatch) === 1) {
                $charset = (string) $charsetMatch[1];
            }
        }
        $body = explode('#', $match[2], 2)[0];
        $body = rawurldecode($body);
        $base64 = $meta !== []
            && strtolower(trim((string) $meta[array_key_last($meta)], " \t\n\r\f")) === 'base64';
        $payload = $base64
            ? base64_decode(preg_replace('/[\x09\x0A\x0C\x0D\x20]+/', '', $body) ?? $body, true)
            : $body;
        if (!is_string($payload)) {
            return null;
        }
        $encoding = null;
        $offset = 0;
        if (str_starts_with($payload, "\xEF\xBB\xBF")) {
            [$encoding, $offset] = ['UTF-8', 3];
        } elseif (str_starts_with($payload, "\xFE\xFF")) {
            [$encoding, $offset] = ['UTF-16BE', 2];
        } elseif (str_starts_with($payload, "\xFF\xFE")) {
            [$encoding, $offset] = ['UTF-16LE', 2];
        } elseif ($charset !== null) {
            $encoding = $charset;
        }
        if ($encoding === null) {
            return mb_scrub($payload, 'UTF-8');
        }
        try {
            return mb_convert_encoding(substr($payload, $offset), 'UTF-8', $encoding);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function parseSvgDocument(string $payload, bool $substituteEntities): ?\DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new \DOMDocument();
            try {
                $options = LIBXML_NONET | LIBXML_COMPACT;
                if ($substituteEntities) {
                    $options |= LIBXML_NOENT;
                }
                $loaded = $document->loadXML($payload, $options);
            } catch (\Throwable) {
                $loaded = false;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $document->documentElement === null
            || strtolower($document->documentElement->localName) !== 'svg'
            || $document->documentElement->namespaceURI !== 'http://www.w3.org/2000/svg'
        ) {
            return null;
        }
        return $document;
    }

    private static function hasUnsafeSvgEntityDeclaration(string $xml): bool
    {
        return preg_match(
            '/<!DOCTYPE\s+[^\s>]+\s+(?:PUBLIC|SYSTEM)\s|'
                . '<!ENTITY\s+(?:%\s*)?[^\s>]+\s+(?:PUBLIC|SYSTEM)\s|<!ENTITY\s+%/i',
            $xml,
        ) === 1;
    }

    private static function xmlGuardText(string $payload): string
    {
        $encoding = null;
        $offset = 0;
        if (str_starts_with($payload, "\x00\x00\xFE\xFF")) {
            [$encoding, $offset] = ['UTF-32BE', 4];
        } elseif (str_starts_with($payload, "\xFF\xFE\x00\x00")) {
            [$encoding, $offset] = ['UTF-32LE', 4];
        } elseif (str_starts_with($payload, "\xFE\xFF")) {
            [$encoding, $offset] = ['UTF-16BE', 2];
        } elseif (str_starts_with($payload, "\xFF\xFE")) {
            [$encoding, $offset] = ['UTF-16LE', 2];
        } elseif (str_starts_with($payload, "\x00\x00\x00<")) {
            $encoding = 'UTF-32BE';
        } elseif (str_starts_with($payload, "<\x00\x00\x00")) {
            $encoding = 'UTF-32LE';
        } elseif (str_starts_with($payload, "\x00<\x00")) {
            $encoding = 'UTF-16BE';
        } elseif (str_starts_with($payload, "<\x00")) {
            $encoding = 'UTF-16LE';
        }
        if ($encoding === null) {
            return $payload;
        }
        try {
            return mb_convert_encoding(substr($payload, $offset), 'UTF-8', $encoding);
        } catch (\Throwable) {
            return $payload;
        }
    }

    /** @return array{type:string,authored:string,canonical:string} */
    private static function uninspectableSvgCandidate(string $payload, string $reason): array
    {
        return [
            'type' => 'generated_text',
            'authored' => 'data:image/svg+xml (' . $reason . ')',
            'canonical' => 'uninspectable-svg:' . $reason . ':' . hash('sha256', $payload),
        ];
    }

    /** @return list<array{type:string,authored:string,canonical:string}> */
    private static function cssDestinationCandidates(string $value): array
    {
        // `content` on an element's own style attribute is inert; generated
        // text on pseudos and marker/quote properties are still active.
        $candidates = CssScrub::generatedTextCandidates($value, true, false);
        if (CssScrub::hasResourceIndirection($value, true)) {
            $candidates[] = [
                'type' => 'url',
                'authored' => $value,
                'canonical' => 'unresolved-css-resource:' . hash('sha256', $value),
            ];
        }
        foreach (CssScrub::resourceUrls($value) as $url) {
            array_push($candidates, ...self::destinationCandidates($url, true));
        }
        return self::dedupe($candidates);
    }

    /** @return list<array{type:string,authored:string,canonical:string}> */
    private static function telephoneUriCandidates(string $value): array
    {
        [$recipients, $query] = array_pad(explode('?', trim($value), 2), 2, '');
        $recipients = rawurldecode($recipients);
        $recipients = preg_replace('#^//+#', '', $recipients) ?? $recipients;
        $candidates = [];
        foreach (preg_split('/\s*,\s*/', $recipients) ?: [] as $authored) {
            $authored = trim($authored);
            if ($authored === '') {
                continue;
            }
            $destination = ContactFacts::canonicalDestination('tel:' . $authored);
            $candidates[] = [
                'type' => 'phone',
                'authored' => $authored,
                'canonical' => $destination === null
                    ? 'invalid:' . mb_strtolower($authored)
                    : substr($destination, strlen('tel:')),
            ];
        }
        foreach (preg_split('/[&;]/', $query) ?: [] as $parameter) {
            if ($parameter === '') {
                continue;
            }
            [, $parameterValue] = array_pad(explode('=', $parameter, 2), 2, '');
            array_push($candidates, ...ContactFacts::visibleCandidates(rawurldecode($parameterValue)));
        }
        return self::dedupe($candidates);
    }

    /** @return list<array{type:string,authored:string,canonical:string}> */
    private static function mailtoCandidates(string $value): array
    {
        $value = LinkTargets::decodeBrowserEntities(trim($value));
        [$recipientList, $query] = array_pad(explode('?', $value, 2), 2, '');
        $candidates = self::mailtoRecipientCandidates(rawurldecode($recipientList));
        foreach (preg_split('/[&;]/', $query) ?: [] as $parameter) {
            if ($parameter === '') {
                continue;
            }
            [$key, $parameterValue] = array_pad(explode('=', $parameter, 2), 2, '');
            $key = strtolower(rawurldecode($key));
            $parameterValue = rawurldecode($parameterValue);
            if (in_array($key, ['to', 'cc', 'bcc'], true)) {
                array_push($candidates, ...self::mailtoRecipientCandidates($parameterValue));
            } else {
                array_push($candidates, ...ContactFacts::visibleCandidates($parameterValue));
            }
        }
        return self::dedupe($candidates);
    }

    /** @return list<array{type:string,authored:string,canonical:string}> */
    private static function mailtoRecipientCandidates(string $recipients): array
    {
        $candidates = [];
        foreach (preg_split('/\s*,\s*/', trim($recipients)) ?: [] as $authored) {
            $authored = trim($authored);
            if ($authored === '') {
                continue;
            }
            $destination = ContactFacts::canonicalDestination('mailto:' . $authored);
            $candidates[] = [
                'type' => 'email',
                'authored' => $authored,
                'canonical' => $destination === null
                    ? 'invalid:' . mb_strtolower($authored)
                    : substr($destination, strlen('mailto:')),
            ];
        }
        return $candidates;
    }

    /**
     * Resolve document-wide IDREF and image-map semantics, then attribute each
     * exposed value to the smallest block containing the referencing element.
     *
     * @return array<int|string,list<array{type:string,authored:string,canonical:string}>>
     */
    private static function documentContextCandidates(
        string $html,
        BlockMarkup $blocks,
        bool $accessibilityContext = true,
    ): array
    {
        $hasContext = preg_match(
            '/\b(?:aria-describedby|aria-description|aria-labelledby|title|usemap)\s*=|'
                . '\blist\s*=|'
                . '\bcontenteditable(?:\s*=|(?=[\x09\x0A\x0C\x0D\x20\/>]))|'
                . '<\s*(?:area|button|datalist|input|label|map|output|select|textarea)\b/iu',
            $html,
        ) === 1;
        if (!$hasContext) {
            return [];
        }
        if (!class_exists(\DOMDocument::class)) {
            return ['orphan' => [self::uninspectableDocumentContextCandidate($html, 'DOM unavailable')]];
        }

        $scope = 0;
        $collision = false;
        $instrumented = HtmlBlockContext::rewriteOpeningTags(
            $html,
            static function (string $tag, string $namespace, int $offset) use (&$scope, &$collision): string {
                $names = array_column(MarkupSanitizer::openingTagAttributes($tag), 'name');
                if (in_array(self::CONTEXT_OFFSET_ATTRIBUTE, $names, true)
                    || in_array(self::CONTEXT_SCOPE_ATTRIBUTE, $names, true)
                    || in_array(self::FORM_CONTEXT_ATTRIBUTE, $names, true)
                ) {
                    $collision = true;
                    return $tag;
                }
                $tag = self::injectContextAttribute($tag, self::CONTEXT_OFFSET_ATTRIBUTE, (string) $offset);
                if ($namespace === 'html'
                    && (preg_match('/^<\s*(?:button|input|label|output|select|textarea)\b/iu', $tag) === 1
                        || in_array('contenteditable', $names, true)
                        || in_array('role', $names, true))
                ) {
                    $tag = self::injectContextAttribute($tag, self::FORM_CONTEXT_ATTRIBUTE, '1');
                }
                if ($namespace === 'html' && preg_match('/^<\s*template\b/iu', $tag) === 1) {
                    $tag = self::injectContextAttribute(
                        $tag,
                        self::CONTEXT_SCOPE_ATTRIBUTE,
                        'shadow-' . $scope++,
                    );
                }
                return $tag;
            },
            true,
        );
        if ($collision) {
            return ['orphan' => [self::uninspectableDocumentContextCandidate($html, 'marker collision')]];
        }
        $dom = Html::loadUtf8Html(
            '<!doctype html><html><body>' . $instrumented . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        if (!$dom instanceof \DOMDocument) {
            return ['orphan' => [self::uninspectableDocumentContextCandidate($html, 'DOM parse failed')]];
        }

        /** @var list<\DOMElement> $elements */
        $elements = [];
        foreach ($dom->getElementsByTagName('*') as $element) {
            if ($element->hasAttribute(self::CONTEXT_OFFSET_ATTRIBUTE)) {
                $elements[] = $element;
            }
        }

        /** @var array<string,array<string,\DOMElement>> $ids */
        $ids = [];
        foreach ($dom->getElementsByTagName('*') as $element) {
            if (!$element->hasAttribute(self::CONTEXT_OFFSET_ATTRIBUTE)
                && strtolower($element->tagName) !== 'template'
            ) {
                continue;
            }
            $id = $element->getAttribute('id');
            if ($id === '') {
                continue;
            }
            $treeScope = self::contextTreeScope($element);
            $ids[$treeScope][$id] ??= $element;
        }

        $byBlock = [];
        foreach (self::stylesheetElementStyles($dom, $instrumented, $elements) as $stylesheetStyles) {
            self::appendFormControlContextCandidates(
                $byBlock,
                $blocks,
                $dom,
                $elements,
                $ids,
                $accessibilityContext,
                $stylesheetStyles,
            );
            if ($accessibilityContext) {
                self::appendDatalistContextCandidates($byBlock, $blocks, $elements, $ids, $stylesheetStyles);
            }
        }
        if (!$accessibilityContext) {
            foreach ($byBlock as $owner => $candidates) {
                $byBlock[$owner] = self::dedupe($candidates);
            }
            return $byBlock;
        }
        foreach ($elements as $element) {
            if (strtolower($element->tagName) === 'template'
                || strtolower($element->tagName) === 'area'
                || self::contextReferenceElementIsHidden($element)
            ) {
                continue;
            }
            $treeScope = self::contextTreeScope($element);
            if (!$element->hasAttribute('aria-labelledby')
                && !$element->hasAttribute('aria-describedby')
                && !$element->hasAttribute('aria-description')
                && !$element->hasAttribute('title')
            ) {
                continue;
            }
            $visited = [];
            $parts = [self::accessibleReferenceText(
                $element,
                $dom,
                $visited,
                true,
                $ids,
                $treeScope,
            )];
            $parts[] = self::accessibleDescriptionText($element, $dom, $ids, $treeScope);
            self::appendDocumentContextCandidates(
                $byBlock,
                $blocks,
                (int) $element->getAttribute(self::CONTEXT_OFFSET_ATTRIBUTE),
                ContactFacts::visibleCandidates(implode(' ', array_filter(
                    $parts,
                    self::accessibleTextIsNotEmpty(...),
                ))),
            );
        }

        /** @var array<string,array<string,true>> $mapReferences */
        $mapReferences = [];
        foreach ($elements as $element) {
            if (!in_array(strtolower($element->tagName), ['img', 'object'], true)
                || self::contextReferenceElementIsHidden($element)
            ) {
                continue;
            }
            $usemap = $element->getAttribute('usemap');
            if (preg_match('/^#(.+)$/su', $usemap, $match) === 1) {
                $mapReferences[self::contextTreeScope($element)][(string) $match[1]] = true;
            }
        }
        $resolvedMaps = [];
        foreach ($elements as $map) {
            if (strtolower($map->tagName) !== 'map') {
                continue;
            }
            $treeScope = self::contextTreeScope($map);
            $identities = array_values(array_unique(array_filter([
                $map->getAttribute('name'),
                $map->getAttribute('id'),
            ], static fn (string $identity): bool => $identity !== '')));
            $referenced = array_values(array_filter(
                $identities,
                static fn (string $identity): bool => isset($mapReferences[$treeScope][$identity]),
            ));
            if ($referenced === []) {
                continue;
            }
            $active = false;
            foreach ($referenced as $identity) {
                if (!isset($resolvedMaps[$treeScope][$identity])) {
                    $active = true;
                }
                $resolvedMaps[$treeScope][$identity] = true;
            }
            if (!$active) {
                continue;
            }
            if (self::contextElementHasInertAncestor($map)) {
                continue;
            }
            foreach ($map->getElementsByTagName('area') as $area) {
                if (!$area->hasAttribute(self::CONTEXT_OFFSET_ATTRIBUTE)
                    || self::contextTreeScope($area) !== $treeScope
                    || !$area->hasAttribute('href')
                    || self::contextElementHasInertAncestor($area)
                    || strtolower(trim($area->getAttribute('aria-hidden'))) === 'true'
                ) {
                    continue;
                }
                $visited = [];
                $parts = [self::accessibleReferenceText(
                    $area,
                    $dom,
                    $visited,
                    true,
                    $ids,
                    $treeScope,
                )];
                $parts[] = self::accessibleDescriptionText($area, $dom, $ids, $treeScope);
                $parts[] = $area->getAttribute('title');
                self::appendDocumentContextCandidates(
                    $byBlock,
                    $blocks,
                    (int) $area->getAttribute(self::CONTEXT_OFFSET_ATTRIBUTE),
                    ContactFacts::visibleCandidates(implode(' ', array_filter(
                        $parts,
                        self::accessibleTextIsNotEmpty(...),
                    ))),
                );
            }
        }
        foreach ($byBlock as $owner => $candidates) {
            $byBlock[$owner] = self::dedupe($candidates);
        }
        return $byBlock;
    }

    private static function injectContextAttribute(string $tag, string $name, string $value): string
    {
        if (preg_match('/\s*\/?\s*>\z/D', $tag, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $tag;
        }
        $offset = $match[0][1];
        return substr($tag, 0, $offset) . ' ' . $name . '="' . $value . '"' . substr($tag, $offset);
    }

    private static function contextTreeScope(\DOMElement $element): string
    {
        $parent = $element->parentNode;
        while ($parent instanceof \DOMElement) {
            if (strtolower($parent->tagName) === 'template'
                && $parent->hasAttribute(self::CONTEXT_SCOPE_ATTRIBUTE)
            ) {
                return $parent->getAttribute(self::CONTEXT_SCOPE_ATTRIBUTE);
            }
            $parent = $parent->parentNode;
        }
        return 'document';
    }

    private static function contextReferenceElementIsHidden(\DOMElement $element): bool
    {
        $node = $element;
        while ($node instanceof \DOMElement) {
            if ($node->hasAttribute('inert')
                || strtolower(trim($node->getAttribute('aria-hidden'))) === 'true'
            ) {
                return true;
            }
            $node = $node->parentNode;
        }
        return false;
    }

    private static function contextElementHasInertAncestor(\DOMElement $element): bool
    {
        $node = $element;
        while ($node instanceof \DOMElement) {
            if ($node->hasAttribute('inert')) {
                return true;
            }
            $node = $node->parentNode;
        }
        return false;
    }

    /**
     * Evaluate a form control's rendered value together with the name a browser
     * computes for it. Labels can live in another Gutenberg block, and a label
     * with `for` belongs only to the first matching id in its tree scope.
     *
     * @param array<int|string,list<array{type:string,authored:string,canonical:string}>> $byBlock
     * @param list<\DOMElement>                                                         $elements
     * @param array<string,array<string,\DOMElement>>                                   $ids
     */
    private static function appendFormControlContextCandidates(
        array &$byBlock,
        BlockMarkup $blocks,
        \DOMDocument $dom,
        array $elements,
        array $ids,
        bool $accessibilityContext,
        array $stylesheetStyles,
    ): void {
        /** @var array<int,list<string>> $labelsByControl */
        $labelsByControl = [];
        foreach ($elements as $label) {
            if (strtolower($label->tagName) !== 'label'
                || !$label->hasAttribute(self::FORM_CONTEXT_ATTRIBUTE)
                || self::contextLabelIsHidden($label, $stylesheetStyles)
                || (!$accessibilityContext && self::contextElementHasAncestorTag($label, 'template'))
            ) {
                continue;
            }
            $control = null;
            if ($label->hasAttribute('for')) {
                $target = $ids[self::contextTreeScope($label)][$label->getAttribute('for')] ?? null;
                if ($target instanceof \DOMElement && self::isComposedFormControl($target)) {
                    $control = $target;
                }
            } else {
                foreach ($label->getElementsByTagName('*') as $descendant) {
                    if (!self::isLabelableElement($descendant)) {
                        continue;
                    }
                    if (self::isComposedFormControl($descendant)
                        && self::contextTreeScope($descendant) === self::contextTreeScope($label)
                    ) {
                        $control = $descendant;
                    }
                    // An implicit label belongs only to its first labelable descendant.
                    break;
                }
            }
            if ($control instanceof \DOMElement) {
                $labelText = self::nativeLabelText($label, $accessibilityContext, $stylesheetStyles);
                if (self::accessibleTextIsNotEmpty($labelText)) {
                    $labelsByControl[spl_object_id($control)][] = $labelText;
                }
            }
        }

        foreach ($elements as $control) {
            if (!self::isComposedFormControl($control)
                || (!$accessibilityContext && self::contextElementHasAncestorTag($control, 'template'))
            ) {
                continue;
            }
            $value = self::renderedFormControlValue($control, $stylesheetStyles);
            if ($value === null || !self::accessibleTextIsNotEmpty($value)) {
                continue;
            }
            $treeScope = self::contextTreeScope($control);
            $nativeName = isset($labelsByControl[spl_object_id($control)])
                ? implode(' ', $labelsByControl[spl_object_id($control)])
                : '';
            $name = $accessibilityContext
                ? self::resolvedAriaName($control, $dom, $ids, $treeScope, $nativeName)
                : $nativeName;
            if ($accessibilityContext && !self::accessibleTextIsNotEmpty($name)) {
                $name = self::nativeFormControlFallbackName($control);
            }
            self::appendDocumentContextCandidates(
                $byBlock,
                $blocks,
                (int) $control->getAttribute(self::CONTEXT_OFFSET_ATTRIBUTE),
                ContactFacts::visibleCandidates($name . ' ' . $value),
            );
        }
    }

    /**
     * Datalist options are published only through a live input whose `list`
     * resolves to the first matching datalist in the same tree scope.
     *
     * @param array<int|string,list<array{type:string,authored:string,canonical:string}>> $byBlock
     * @param list<\DOMElement>                                                         $elements
     * @param array<string,array<string,\DOMElement>>                                   $ids
     */
    private static function appendDatalistContextCandidates(
        array &$byBlock,
        BlockMarkup $blocks,
        array $elements,
        array $ids,
        array $stylesheetStyles,
    ): void {
        foreach ($elements as $input) {
            if (strtolower($input->tagName) !== 'input'
                || !$input->hasAttribute(self::FORM_CONTEXT_ATTRIBUTE)
                || !$input->hasAttribute('list')
                || $input->hasAttribute('disabled')
                || ($input->hasAttribute('readonly') && in_array(
                    strtolower(trim($input->getAttribute('type'))),
                    ['', 'date', 'datetime-local', 'email', 'month', 'number', 'password', 'search', 'tel', 'text', 'time', 'url', 'week'],
                    true,
                ))
                || in_array(strtolower(trim($input->getAttribute('type'))), [
                    'button', 'checkbox', 'file', 'hidden', 'image', 'password', 'radio', 'reset', 'submit',
                ], true)
                || self::contextElementHasAncestorTag($input, 'template')
                || self::contextElementHasInertAncestor($input)
                || self::contextLabelIsHidden($input, $stylesheetStyles)
            ) {
                continue;
            }
            $treeScope = self::contextTreeScope($input);
            $datalist = $ids[$treeScope][$input->getAttribute('list')] ?? null;
            if (!$datalist instanceof \DOMElement
                || strtolower($datalist->tagName) !== 'datalist'
                || self::contextElementHasAncestorTag($datalist, 'template')
            ) {
                continue;
            }
            $text = [];
            $inputType = strtolower(trim($input->getAttribute('type')));
            $inputType = $inputType === '' ? 'text' : $inputType;
            foreach ($datalist->getElementsByTagName('option') as $option) {
                $value = $option->hasAttribute('value') ? $option->getAttribute('value') : $option->textContent;
                $label = $option->getAttribute('label');
                if ($option->hasAttribute('disabled')
                    || !self::datalistValueIsUsable($inputType, $value)
                ) {
                    continue;
                }
                if (self::accessibleTextIsNotEmpty($value)) {
                    $text[] = $value;
                }
                if (self::accessibleTextIsNotEmpty($label)) {
                    $text[] = $label;
                }
            }
            self::appendDocumentContextCandidates(
                $byBlock,
                $blocks,
                (int) $input->getAttribute(self::CONTEXT_OFFSET_ATTRIBUTE),
                ContactFacts::visibleCandidates(implode(' ', $text)),
            );
        }
    }

    private static function datalistValueIsUsable(string $type, string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if (in_array($type, ['number', 'range'], true)) {
            return preg_match('/^-?(?:[0-9]+|[0-9]*\.[0-9]+)(?:e[+-]?[0-9]+)?$/iD', $value) === 1
                && is_finite((float) $value);
        }
        if ($type === 'color') {
            return preg_match('/^#[0-9a-f]{6}$/iD', $value) === 1;
        }
        if ($type === 'date') {
            if (preg_match('/^(\d{4,})-(\d{2})-(\d{2})$/D', $value, $date) !== 1) {
                return false;
            }
            return checkdate((int) $date[2], (int) $date[3], (int) $date[1]);
        }
        if ($type === 'month') {
            return preg_match('/^\d{4,}-(?:0[1-9]|1[0-2])$/D', $value) === 1;
        }
        if ($type === 'time') {
            return preg_match(
                '/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d+)?)?$/D',
                $value,
            ) === 1;
        }
        if ($type === 'datetime-local') {
            [$date, $time] = array_pad(explode('T', $value, 2), 2, '');
            return self::datalistValueIsUsable('date', $date)
                && self::datalistValueIsUsable('time', $time);
        }
        if ($type === 'week') {
            if (preg_match('/^(\d{4,})-W(\d{2})$/D', $value, $week) !== 1) {
                return false;
            }
            $year = (int) $week[1];
            $number = (int) $week[2];
            $maximum = (int) (new \DateTimeImmutable(sprintf('%04d-12-28', $year)))->format('W');
            return $number >= 1 && $number <= $maximum;
        }
        return true;
    }

    private static function nativeLabelText(
        \DOMElement $label,
        bool $accessibilityContext,
        array $stylesheetStyles,
    ): string
    {
        $text = '';
        foreach ($label->childNodes as $child) {
            $text .= self::nativeLabelNodeText($child, $accessibilityContext, $stylesheetStyles);
        }
        return $text;
    }

    private static function nativeLabelNodeText(
        \DOMNode $node,
        bool $accessibilityContext,
        array $stylesheetStyles,
    ): string
    {
        if ($node instanceof \DOMText) {
            return $node->data;
        }
        if (!$node instanceof \DOMElement || self::contextLabelIsHidden($node, $stylesheetStyles)) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'template'], true)
            || self::isLabelableElement($node)
        ) {
            return '';
        }
        if ($accessibilityContext
            && self::accessibleTextIsNotEmpty($node->getAttribute('aria-label'))
        ) {
            return $node->getAttribute('aria-label');
        }
        if ($accessibilityContext
            && ($tag === 'img' || ($tag === 'input' && strtolower($node->getAttribute('type')) === 'image'))
            && $node->hasAttribute('alt')
        ) {
            return $node->getAttribute('alt');
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::nativeLabelNodeText($child, $accessibilityContext, $stylesheetStyles);
        }
        return $text;
    }

    private static function nativeFormControlFallbackName(\DOMElement $control): string
    {
        if (self::accessibleTextIsNotEmpty($control->getAttribute('title'))) {
            return $control->getAttribute('title');
        }
        $tag = strtolower($control->tagName);
        if ($tag === 'textarea') {
            return $control->getAttribute('placeholder');
        }
        if ($tag !== 'input') {
            return '';
        }
        $type = strtolower(trim($control->getAttribute('type')));
        $type = in_array($type, [
            'button', 'checkbox', 'color', 'date', 'datetime-local', 'email', 'file', 'hidden', 'image',
            'month', 'number', 'password', 'radio', 'range', 'reset', 'search', 'submit', 'tel', 'text',
            'time', 'url', 'week',
        ], true) ? $type : 'text';
        return in_array(
            $type,
            ['date', 'datetime-local', 'email', 'month', 'number', 'password', 'search', 'tel', 'text', 'time', 'url', 'week'],
            true,
        ) ? $control->getAttribute('placeholder') : '';
    }

    private static function isComposedFormControl(\DOMElement $element): bool
    {
        if (self::domContenteditableHost($element)) {
            return true;
        }
        if (!$element->hasAttribute(self::FORM_CONTEXT_ATTRIBUTE)) {
            return false;
        }
        $tag = strtolower($element->tagName);
        if (in_array($tag, ['button', 'input', 'output', 'select', 'textarea'], true)) {
            return $tag !== 'input' || strtolower(trim($element->getAttribute('type'))) !== 'hidden';
        }
        return false;
    }

    private static function isLabelableElement(\DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);
        return in_array($tag, ['button', 'meter', 'output', 'progress', 'select', 'textarea'], true)
            || ($tag === 'input' && strtolower(trim($element->getAttribute('type'))) !== 'hidden');
    }

    private static function domContenteditableHost(\DOMElement $element): bool
    {
        if (!$element->hasAttribute('contenteditable')) {
            return false;
        }
        return in_array(strtolower(trim($element->getAttribute('contenteditable'))), [
            '', 'true', 'plaintext-only',
        ], true);
    }

    /** @param array<string,array<string,\DOMElement>> $ids */
    private static function resolvedAriaName(
        \DOMElement $control,
        \DOMDocument $dom,
        array $ids,
        string $treeScope,
        string $nativeName,
    ): string {
        $ariaLabel = self::accessibleTextIsNotEmpty($control->getAttribute('aria-label'))
            ? $control->getAttribute('aria-label')
            : '';
        $parts = [];
        foreach (self::splitAriaIdRefs($control->getAttribute('aria-labelledby')) as $id) {
            $target = $ids[$treeScope][$id] ?? null;
            if (!$target instanceof \DOMElement) {
                continue;
            }
            if ($target === $control) {
                $text = $ariaLabel !== '' ? $ariaLabel : $nativeName;
                if (self::accessibleTextIsNotEmpty($text)) {
                    $parts[] = $text;
                }
                continue;
            }
            $visited = [];
            $text = self::accessibleReferenceText(
                $target,
                $dom,
                $visited,
                true,
                $ids,
                $treeScope,
                null,
                false,
            );
            if (self::accessibleTextIsNotEmpty($text)) {
                $parts[] = $text;
            }
        }
        if ($parts !== []) {
            return implode(' ', $parts);
        }
        return $ariaLabel !== '' ? $ariaLabel : $nativeName;
    }

    /** @param array<int,array<string,string>> $stylesheetStyles */
    private static function contextLabelIsHidden(\DOMElement $label, array $stylesheetStyles): bool
    {
        $node = $label;
        $visibilitySettled = false;
        while ($node instanceof \DOMElement) {
            if (strtolower(trim($node->getAttribute('aria-hidden'))) === 'true') {
                return true;
            }
            $computed = $stylesheetStyles[spl_object_id($node)] ?? [];
            $display = $computed['display'] ?? null;
            $visibility = $computed['visibility'] ?? null;
            if ($display === 'none'
                || ($node->hasAttribute('hidden') && $display === null)
            ) {
                return true;
            }
            if (!$visibilitySettled && $visibility !== null) {
                if (in_array($visibility, ['collapse', 'hidden'], true)) {
                    return true;
                }
                $visibilitySettled = true;
            }
            $node = $node->parentNode;
        }
        return false;
    }

    /**
     * Resolve the small author-CSS cascade that can alter whether a form name
     * or value is visibly published. The shared selector parser covers type,
     * class, id, attribute, child, and descendant selectors.
     *
     * @param list<\DOMElement> $elements
     * @return list<array<int,array<string,string>>>
     */
    private static function stylesheetElementStyles(
        \DOMDocument $dom,
        string $instrumented,
        array $elements,
    ): array
    {
        $byOffset = [];
        foreach ($elements as $element) {
            $byOffset[$element->getAttribute(self::CONTEXT_OFFSET_ATTRIBUTE)] = $element;
        }
        $fragment = \Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment::parse($instrumented);
        $baseWinners = [];
        $baseCandidates = [];
        $conditionalCandidates = [];
        $order = 0;
        $styleSheets = [];
        $combinedCss = '';
        foreach ($dom->getElementsByTagName('style') as $style) {
            if (self::contextElementHasAncestorTag($style, 'template')
                || strtolower(trim($style->getAttribute('type'))) !== ''
                    && strtolower(trim($style->getAttribute('type'))) !== 'text/css'
                || self::normalizedCssMedia($style->getAttribute('media')) === 'not all'
            ) {
                continue;
            }
            $css = $style->textContent;
            $media = trim($style->getAttribute('media'));
            $styleSheets[] = [
                'css' => $css,
                'offset' => strlen($combinedCss),
                'condition' => $media === '' || strtolower($media) === 'all'
                    ? ''
                    : '@media ' . $media,
            ];
            $combinedCss .= $css . "\n";
        }
        $layerModel = CssScrub::cascadeLayerModel($combinedCss);
        $layerOrder = $layerModel['order'];
        $registrationConditions = CssScrub::registeredCustomPropertyConditions($combinedCss);
        foreach ($styleSheets as $styleSheet) {
            foreach (CssChecks::scanDeclarations($styleSheet['css']) as $declaration) {
                if ($declaration['kind'] !== 'style'
                    || self::cssDeclarationIsInactive($declaration['ancestors'])
                ) {
                    continue;
                }
                $priority = CssChecks::splitDeclarationPriority($declaration['value']);
                $authoredProperty = str_starts_with($declaration['property'], '--')
                    ? $declaration['property']
                    : strtolower($declaration['property']);
                if ($authoredProperty === 'all') {
                    if (!in_array(
                        strtolower(CssChecks::decodeIdentifier($priority['value'])),
                        ['initial', 'inherit', 'revert', 'revert-layer', 'unset'],
                        true,
                    )) {
                        continue;
                    }
                    $properties = ['display', 'visibility'];
                } elseif (in_array($authoredProperty, ['display', 'visibility'], true)
                    || str_starts_with($authoredProperty, '--')
                ) {
                    $properties = [$authoredProperty];
                } else {
                    continue;
                }
                $value = $priority['value'];
                $important = $priority['important'];
                $globalStart = $styleSheet['offset'] + $declaration['start'];
                $layer = CssScrub::cascadeLayerForOffset($layerModel, $globalStart);
                $inline = false;
                $layerRank = CssScrub::cascadeLayerRank(
                    compact('layer', 'important', 'inline'),
                    $layerOrder,
                );
                $conditionScope = implode("\0", array_values(array_filter(
                    [
                        $styleSheet['condition'],
                        self::cssDeclarationConditionScope($declaration['ancestors']),
                    ],
                    static fn (string $condition): bool => $condition !== '',
                )));
                foreach (\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel(
                    $declaration['context'],
                    [','],
                ) as $selector) {
                    $selector = trim($selector);
                    if ($selector === '') {
                        continue;
                    }
                    $selectorConditionScope = implode("\0", array_values(array_filter(
                        [$conditionScope, self::cssSelectorStateScope($selector, $fragment)],
                        static fn (string $condition): bool => $condition !== '',
                    )));
                    $specificity = self::cssSelectorSpecificity($selector);
                    foreach ($properties as $property) {
                        $matchedElements = [];
                        if (str_starts_with($property, '--')
                            && preg_match('/^(?::root|html|body)$/iu', trim($selector)) === 1
                        ) {
                            $matchedElements = [0];
                        } else {
                            $matches = self::matchedCssElements($fragment, $selector);
                            foreach ($matches as $match) {
                                $offset = $match->attribute(self::CONTEXT_OFFSET_ATTRIBUTE);
                                $element = $offset === null ? null : ($byOffset[$offset] ?? null);
                                if ($element instanceof \DOMElement) {
                                    $matchedElements[] = $element;
                                }
                            }
                        }
                        foreach ($matchedElements as $element) {
                            $key = is_int($element) ? $element : spl_object_id($element);
                            $candidate = compact(
                                'value',
                                'important',
                                'layer',
                                'layerRank',
                                'specificity',
                                'order',
                            );
                            if ($selectorConditionScope === '') {
                                $baseCandidates[$key][$property][] = $candidate;
                                if (self::groundingCascadeCandidateWins(
                                    $candidate,
                                    $baseWinners[$key][$property] ?? null,
                                )) {
                                    $baseWinners[$key][$property] = $candidate;
                                }
                            } else {
                                $conditionalCandidates[$selectorConditionScope][] = [
                                    'key' => $key,
                                    'property' => $property,
                                    'candidate' => $candidate,
                                ];
                            }
                        }
                    }
                }
                $order++;
            }
        }
        foreach ($elements as $element) {
            foreach (CssChecks::scanDeclarations($element->getAttribute('style'), true) as $declaration) {
                $priority = CssChecks::splitDeclarationPriority($declaration['value']);
                $authoredProperty = str_starts_with($declaration['property'], '--')
                    ? $declaration['property']
                    : strtolower($declaration['property']);
                if ($authoredProperty === 'all') {
                    if (!in_array(
                        strtolower(CssChecks::decodeIdentifier($priority['value'])),
                        ['initial', 'inherit', 'revert', 'revert-layer', 'unset'],
                        true,
                    )) {
                        continue;
                    }
                    $properties = ['display', 'visibility'];
                } elseif (in_array($authoredProperty, ['display', 'visibility'], true)
                    || str_starts_with($authoredProperty, '--')
                ) {
                    $properties = [$authoredProperty];
                } else {
                    continue;
                }
                foreach ($properties as $property) {
                    $important = $priority['important'];
                    $layer = null;
                    $inline = true;
                    $candidate = [
                        'value' => $priority['value'],
                        'important' => $important,
                        'layer' => $layer,
                        'layerRank' => CssScrub::cascadeLayerRank(
                            compact('layer', 'important', 'inline'),
                            $layerOrder,
                        ),
                        'specificity' => 1000,
                        'order' => $order++,
                    ];
                    $key = spl_object_id($element);
                    $baseCandidates[$key][$property][] = $candidate;
                    if (self::groundingCascadeCandidateWins(
                        $candidate,
                        $baseWinners[$key][$property] ?? null,
                    )) {
                        $baseWinners[$key][$property] = $candidate;
                    }
                }
            }
        }
        foreach ($registrationConditions as $condition) {
            $conditionalCandidates[$condition] ??= [];
        }
        $conditionScenarios = CssScrub::feasibleConditionScenarios(
            array_keys($conditionalCandidates),
        );
        $conditionOverflow = $conditionScenarios === null;
        if ($conditionScenarios === null) {
            $conditionScenarios = [[], array_keys($conditionalCandidates)];
            foreach (array_keys($conditionalCandidates) as $condition) {
                $conditionScenarios[] = [$condition];
            }
        }
        $scenarioWinners = [];
        foreach ($conditionScenarios as $activeConditions) {
            $scenario = $baseWinners;
            $scenarioCandidates = $baseCandidates;
            foreach ($activeConditions as $condition) {
                foreach ($conditionalCandidates[$condition] ?? [] as $entry) {
                    $scenarioCandidates[$entry['key']][$entry['property']][] = $entry['candidate'];
                    if (self::groundingCascadeCandidateWins(
                        $entry['candidate'],
                        $scenario[$entry['key']][$entry['property']] ?? null,
                    )) {
                        $scenario[$entry['key']][$entry['property']] = $entry['candidate'];
                    }
                }
            }
            foreach ($scenario as $key => $properties) {
                foreach ($properties as $property => $winner) {
                    $scenario[$key][$property] = CssScrub::cascadeRevertLayerWinner(
                        $winner,
                        $scenarioCandidates[$key][$property] ?? [],
                    );
                    if ($scenario[$key][$property] === null) {
                        unset($scenario[$key][$property]);
                    }
                }
            }
            $scenarioWinners[] = [
                'winners' => $scenario,
                'registrations' => CssScrub::registeredCustomProperties(
                    $combinedCss,
                    $activeConditions,
                ),
            ];
        }
        $elementsById = [];
        foreach ($elements as $element) {
            $elementsById[spl_object_id($element)] = $element;
        }
        $styleScenarios = [];
        foreach ($scenarioWinners as $scenarioState) {
            $scenario = $scenarioState['winners'];
            $registrations = $scenarioState['registrations'];
            $styles = [];
            foreach ($scenario as $key => $properties) {
                foreach ($properties as $property => $winner) {
                    if (!in_array($property, ['display', 'visibility'], true)) {
                        continue;
                    }
                    $element = $elementsById[$key] ?? null;
                    if ($element instanceof \DOMElement) {
                        $styles[$key][$property] = self::groundingResolvedCssValue(
                            $winner['value'],
                            $property,
                            $element,
                            $scenario,
                            $registrations,
                        );
                    }
                }
            }
            $styleScenarios[] = $styles;
        }
        if ($conditionOverflow) {
            // An unbounded conditional cascade must fail closed. Include both
            // the all-visible document and each single hidden element so
            // contact extraction cannot be suppressed by sampled CSS state.
            $styleScenarios[] = [];
            foreach ($elements as $element) {
                $styleScenarios[] = [
                    spl_object_id($element) => ['display' => 'none'],
                ];
            }
        }
        return $styleScenarios;
    }

    /** @param list<string> $ancestors */
    private static function cssDeclarationConditionScope(array $ancestors): string
    {
        $conditional = [];
        foreach ($ancestors as $ancestor) {
            $ancestor = trim($ancestor);
            if (!str_starts_with($ancestor, '@')) {
                continue;
            }
            if (preg_match('/^@layer\b/iu', $ancestor) === 1) {
                continue;
            }
            if (preg_match('/^@media\s*(.*)$/isu', $ancestor, $media) === 1) {
                $query = trim((string) $media[1], " \t\n\r\f()");
                if ($query === '' || strtolower($query) === 'all') {
                    continue;
                }
            }
            $conditional[] = $ancestor;
        }
        return implode("\0", $conditional);
    }

    /** @param list<string> $ancestors */
    private static function cssDeclarationIsInactive(array $ancestors): bool
    {
        foreach ($ancestors as $ancestor) {
            if (preg_match('/^\s*@media\b(.*)$/isu', $ancestor, $media) === 1
                && self::normalizedCssMedia((string) $media[1]) === 'not all'
            ) {
                return true;
            }
        }
        return false;
    }

    public static function cssSelectorSpecificity(string $selector): int
    {
        $selector = preg_replace('/\/\*.*?\*\//su', ' ', $selector) ?? $selector;
        [$selector] = self::groundingProtectAttributeSelectors($selector);
        $selector = self::groundingNormalizeAnalysisEscapes($selector);
        $functional = 0;
        while (true) {
            $positions = [];
            foreach (['has', 'is', 'not', 'nth-child', 'nth-last-child', 'where'] as $kind) {
                $position = self::groundingPseudoFunctionPosition($selector, $kind);
                if ($position !== null) {
                    $positions[] = compact('kind', 'position');
                }
            }
            if ($positions === []) {
                break;
            }
            usort(
                $positions,
                static fn (array $left, array $right): int =>
                    $left['position'][0] <=> $right['position'][0],
            );
            [$start, $open] = $positions[0]['position'];
            $close = self::groundingClosingParenthesis($selector, $open);
            if ($close === null) {
                break;
            }
            $arguments = substr($selector, $open + 1, $close - $open - 1);
            $kind = $positions[0]['kind'];
            if ($kind === 'nth-child' || $kind === 'nth-last-child') {
                $functional += 10;
                $selectorList = null;
                if (preg_match(
                    '/[\x09\x0A\x0C\x0D\x20]+of[\x09\x0A\x0C\x0D\x20]+(.+)$/isu',
                    $arguments,
                    $of,
                ) === 1) {
                    $selectorList = (string) $of[1];
                }
            } else {
                $selectorList = $kind === 'where' ? null : $arguments;
            }
            if ($selectorList !== null) {
                $branchSpecificities = [];
                foreach (\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel(
                    $selectorList,
                    [','],
                ) as $branch) {
                    if (in_array($kind, ['has', 'is'], true)
                        && self::groundingSpecificityBranchIsInvalid($branch)
                    ) {
                        continue;
                    }
                    $branchSpecificities[] = self::cssSelectorSpecificity($branch);
                }
                $functional += $branchSpecificities === [] ? 0 : max($branchSpecificities);
            }
            $selector = substr($selector, 0, $start) . substr($selector, $close + 1);
        }
        while (preg_match('/:(?!:)[a-z_][\w-]*\s*\(/iu', $selector, $match, PREG_OFFSET_CAPTURE) === 1) {
            $start = (int) $match[0][1];
            $open = $start + strlen((string) $match[0][0]) - 1;
            $close = self::groundingClosingParenthesis($selector, $open);
            if ($close === null) {
                break;
            }
            $selector = substr($selector, 0, $start) . ':x' . substr($selector, $close + 1);
        }
        $pseudoElements = preg_match_all(
            '/::[a-z_][\w-]*|:(?:after|before|first-letter|first-line)\b/iu',
            $selector,
        );
        $withoutPseudoElements = preg_replace(
            '/::[a-z_][\w-]*|:(?:after|before|first-letter|first-line)\b/iu',
            '',
            $selector,
        ) ?? $selector;
        $ids = preg_match_all('/#[\p{L}_][\p{L}\p{N}_-]*/u', $withoutPseudoElements);
        $classes = preg_match_all(
            '/\.[\p{L}_][\p{L}\p{N}_-]*|\[[^\]]+\]|:(?!:)[a-z_][\w-]*/iu',
            $withoutPseudoElements,
        );
        $withoutAttributes = preg_replace('/\[[^\]]+\]/u', '', $selector) ?? $selector;
        $types = preg_match_all('/(?:^|[>+~\s])[a-z][\w-]*(?=[.#[:\s>+~]|$)/iu', $withoutAttributes);
        return $functional + (is_int($ids) ? $ids : 0) * 100
            + (is_int($classes) ? $classes : 0) * 10
            + (is_int($types) ? $types : 0)
            + (is_int($pseudoElements) ? $pseudoElements : 0);
    }

    private static function groundingSpecificityBranchIsInvalid(string $selector): bool
    {
        preg_match_all('/:{1,2}([a-z_][\w-]*)/iu', $selector, $matches);
        $known = [
            'active', 'active-view-transition', 'active-view-transition-type', 'after', 'any-link',
            'autofill', 'before', 'checked', 'current', 'default',
            'defined', 'dir', 'disabled', 'empty', 'enabled', 'first-child', 'first-letter',
            'first-line', 'first-of-type', 'focus', 'focus-visible', 'focus-within', 'fullscreen', 'future',
            'has', 'host', 'host-context', 'hover', 'in-range', 'indeterminate', 'interest-source',
            'interest-target', 'invalid', 'is', 'lang', 'last-child', 'last-of-type', 'link', 'modal',
            'muted', 'not', 'nth-child', 'nth-last-child', 'nth-last-of-type',
            'nth-of-type', 'only-child', 'only-of-type', 'open', 'optional', 'out-of-range', 'paused',
            'past', 'picture-in-picture', 'placeholder-shown', 'playing', 'popover-open', 'read-only',
            'read-write', 'required', 'root', 'scope', 'seeking', 'stalled', 'state', 'target',
            'target-current',
            'user-invalid', 'user-valid', 'valid', 'visited', 'volume-locked', 'where',
        ];
        foreach ($matches[1] ?? [] as $pseudo) {
            $pseudo = strtolower((string) $pseudo);
            if (!in_array($pseudo, $known, true) && !str_starts_with($pseudo, '-webkit-')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the dynamic selector state that must not suppress the ordinary
     * cascade. Static predicates handled by matchedCssElements() contribute no
     * state; unknown/stateful predicates are analyzed as a possible state in
     * addition to the base state.
     */
    public static function cssSelectorStateScope(
        string $selector,
        ?\Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment $fragment = null,
    ): string
    {
        $compounds = self::groundingSelectorCompounds(self::groundingBroadenUnsupportedSelectorEscapes(
            self::groundingNormalizeEscapedIdentifierSelectors($selector),
        ));
        $scopedStates = [];
        foreach ($compounds as $index => $subject) {
            [$positiveSubject, $notSelectors] = self::groundingWithoutNotSelectors($subject);
            $states = [];
            foreach ($notSelectors as $notSelector) {
                $negated = self::cssSelectorStateTokens($notSelector);
                if (count($negated) === 1) {
                    $states[] = str_starts_with($negated[0], '!')
                        ? substr($negated[0], 1)
                        : '!' . $negated[0];
                } elseif ($negated !== []) {
                    $states[] = '!(' . implode('&', $negated) . ')';
                }
            }
            array_push($states, ...self::cssSelectorStateTokens($positiveSubject));
            $states = array_values(array_unique($states));
            sort($states);
            if ($states === []) {
                continue;
            }
            $owner = self::groundingSelectorStateOwner(
                $subject,
                count($compounds) - $index - 1,
                $fragment,
            );
            foreach ($states as $state) {
                $scopedStates[] = $owner . '|' . $state;
            }
        }
        $scopedStates = array_values(array_unique($scopedStates));
        sort($scopedStates);
        if ($scopedStates === []) {
            return '';
        }
        return '@selector-state ' . implode(',', $scopedStates);
    }

    /** @return list<string> */
    private static function groundingSelectorCompounds(string $selector): array
    {
        $compounds = [];
        $compound = '';
        $depth = 0;
        $quote = null;
        for ($index = 0, $length = strlen($selector); $index < $length; $index++) {
            $byte = $selector[$index];
            if ($quote !== null) {
                $compound .= $byte;
                if ($byte === '\\') {
                    if (++$index < $length) {
                        $compound .= $selector[$index];
                    }
                } elseif ($byte === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
                $compound .= $byte;
            } elseif ($byte === '[' || $byte === '(') {
                $depth++;
                $compound .= $byte;
            } elseif ($byte === ']' || $byte === ')') {
                $depth = max(0, $depth - 1);
                $compound .= $byte;
            } elseif ($depth === 0 && ($byte === '>' || $byte === '+' || $byte === '~'
                || ctype_space($byte))
            ) {
                $compound = trim($compound);
                if ($compound !== '') {
                    $compounds[] = $compound;
                }
                $compound = '';
            } else {
                $compound .= $byte;
            }
        }
        $compound = trim($compound);
        if ($compound !== '') {
            $compounds[] = $compound;
        }
        return $compounds;
    }

    private static function groundingSelectorStateOwner(
        string $subject,
        int $distanceFromSubject,
        ?\Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment $fragment,
    ): string {
        [$owner] = self::groundingWithoutNotSelectors($subject);
        $owner = trim(self::groundingWithoutPseudoClasses($owner));
        $provableOwner = $owner === '' ? '*' : $owner;
        $ownerGrammarIsProvable = preg_match(
            '/^(?:[a-z][\w-]*|\*)?(?:'
                . '#[\p{L}_][\p{L}\p{N}_-]*|'
                . '\.[\p{L}_][\p{L}\p{N}_-]*|'
                . '\[\s*[\w:-]+\s*\]'
                . ')*$/iuD',
            $provableOwner,
        ) === 1;
        if ($fragment !== null) {
            if ($ownerGrammarIsProvable) {
                $matchedOwners = self::matchedCssElements($fragment, $provableOwner);
                if (count($matchedOwners) === 1) {
                    return substr(hash('sha256', 'node=' . spl_object_id($matchedOwners[0])), 0, 16);
                }
            }
            // A selector such as `.control` can put :disabled and :enabled on
            // different matching nodes at the same time. Preserve overlap
            // unless the delivered DOM proves that this owner is unique.
            return substr(hash(
                'sha256',
                'ambiguous=' . $subject . ';slot=' . $distanceFromSubject,
            ), 0, 16);
        }
        $attributes = [];
        preg_match_all('/\[([^\]]+)\]/u', $owner, $attributeMatches);
        foreach ($attributeMatches[1] ?? [] as $attribute) {
            $attributes[] = trim((string) $attribute);
        }
        sort($attributes);
        $withoutAttributes = preg_replace('/\[[^\]]+\]/u', '', $owner) ?? $owner;
        preg_match_all('/(?<!\\\\)#([\p{L}\p{N}_-]+)/u', $withoutAttributes, $idMatches);
        preg_match_all('/(?<!\\\\)\.([\p{L}\p{N}_-]+)/u', $withoutAttributes, $classMatches);
        $ids = array_values(array_unique($idMatches[1] ?? []));
        $classes = array_values(array_unique($classMatches[1] ?? []));
        sort($ids);
        sort($classes);
        if ($ids !== [] || $classes !== [] || $attributes !== []) {
            $canonical = 'ids=' . implode(',', $ids)
                . ';classes=' . implode(',', $classes)
                . ';attrs=' . implode(',', $attributes);
        } else {
            $canonical = preg_match('/(?:^|\|)\s*([a-z][\w-]*|\*)/iu', $withoutAttributes, $tag) === 1
                ? 'tag=' . strtolower((string) $tag[1])
                : 'tag=*';
        }
        return substr(hash('sha256', $canonical . ';slot=' . $distanceFromSubject), 0, 16);
    }

    /** @return list<string> */
    private static function cssSelectorStateTokens(string $selector): array
    {
        $selector = preg_replace('/\/\*.*?\*\//su', ' ', $selector) ?? $selector;
        $selector = preg_replace('/\[[^\]]*\]/u', '', $selector) ?? $selector;
        $selector = preg_replace('/::?(?:before|after)\b/iu', '', $selector) ?? $selector;
        $selector = preg_replace('/:(?:has)\(\s*\+\s*[a-z][\w-]*\s*\)/iu', '', $selector)
            ?? $selector;
        $selector = preg_replace(
            '/:(?:nth-child|nth-last-child|nth-of-type|nth-last-of-type)\(\s*\d+\s*\)/iu',
            '',
            $selector,
        ) ?? $selector;
        $selector = preg_replace(
            '/:(?:nth-child|nth-last-child)\(\s*\d+\s+of\s+[^)]*\)/iu',
            '',
            $selector,
        ) ?? $selector;
        $selector = preg_replace(
            '/:(?:root|first-child|last-child|only-child|first-of-type|last-of-type|only-of-type|empty)\b/iu',
            '',
            $selector,
        ) ?? $selector;
        // :is() and :where() are wrappers; any stateful branch inside remains
        // in the string and is captured below.
        $selector = preg_replace('/:(?:is|where)\s*(?=\()/iu', '', $selector) ?? $selector;
        preg_match_all(
            '/:(?!:)[a-z_][\w-]*(?:\([^()]*(?:\([^()]*\)[^()]*)*\))?/iu',
            $selector,
            $matches,
        );
        return array_values(array_unique(array_map(
            static fn (string $state): string => strtolower(preg_replace('/\s+/u', '', $state) ?? $state),
            $matches[0] ?? [],
        )));
    }

    /**
     * Match the generated-CSS selector subset that can affect grounding.
     * Structural predicates are evaluated after the shared selector engine;
     * an unknown remaining pseudo is stripped to a conservative subject
     * superset so supported browser selectors never fail open.
     *
     * @return list<\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode>
     */
    public static function matchedCssElements(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment $fragment,
        string $selector,
    ): array {
        $matched = [];
        $selector = self::groundingBroadenUnsupportedSelectorEscapes(
            self::groundingNormalizeEscapedIdentifierSelectors($selector),
        );
        $hasMatches = self::groundingHasSelectorMatches($fragment, $selector);
        if ($hasMatches !== null) {
            return $hasMatches;
        }
        $functionalMatches = self::groundingFunctionalSelectorMatches($fragment, $selector);
        if ($functionalMatches !== null) {
            return $functionalMatches;
        }
        foreach (self::groundingExpandedSelectors($selector) as $branch) {
            $subjectOffset = self::groundingFinalSelectorCompoundOffset($branch);
            if ($subjectOffset > 0) {
                $ancestor = substr($branch, 0, $subjectOffset);
                [$ancestor] = self::groundingWithoutNotSelectors($ancestor);
                $ancestor = preg_replace(
                    '/\[\s*([\w:-]+)\s*[~|^$*]?=\s*'
                        . '(?:"(?:\\\\.|[^"])*"|\'(?:\\\\.|[^\'])*\'|[^\]\s]+)'
                        . '(?:\s+[is])?\s*\]/iu',
                    '[$1]',
                    $ancestor,
                ) ?? $ancestor;
                $branch = self::groundingWithoutPseudoClasses($ancestor)
                    . substr($branch, $subjectOffset);
            }
            $conditions = [];
            [$branch, $notSelectors] = self::groundingWithoutNotSelectors($branch);
            foreach ($notSelectors as $notSelector) {
                if (self::cssSelectorStateTokens($notSelector) !== []) {
                    // A dynamic negation is true in at least one browser
                    // state. Keep the broad subject match; cssSelectorStateScope
                    // keeps it out of the unconditional cascade.
                    continue;
                }
                $notNodes = [];
                foreach (\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel(
                    $notSelector,
                    [','],
                ) as $alternative) {
                    $alternative = trim($alternative);
                    if ($alternative === '') {
                        continue;
                    }
                    if (str_starts_with($alternative, ':')) {
                        $alternative = '*' . $alternative;
                    }
                    foreach (self::matchedCssElements($fragment, $alternative) as $notNode) {
                        $notNodes[spl_object_id($notNode)] = true;
                    }
                }
                $conditions[] = ['kind' => 'not-nodes', 'nodes' => $notNodes];
            }
            [$branch, $protectedAttributes] = self::groundingProtectAttributeSelectors($branch);
            $branch = preg_replace_callback(
                '/:first-child\b/iu',
                static function () use (&$conditions): string {
                    $conditions[] = ['kind' => 'nth', 'value' => 1];
                    return '';
                },
                $branch,
            ) ?? $branch;
            $branch = preg_replace_callback(
                '/:last-child\b/iu',
                static function () use (&$conditions): string {
                    $conditions[] = ['kind' => 'nth-last', 'value' => 1];
                    return '';
                },
                $branch,
            ) ?? $branch;
            $branch = preg_replace_callback(
                '/:only-child\b/iu',
                static function () use (&$conditions): string {
                    $conditions[] = ['kind' => 'only-child'];
                    return '';
                },
                $branch,
            ) ?? $branch;
            $branch = preg_replace_callback(
                '/:nth-child\(\s*(\d+)\s*\)/iu',
                static function (array $match) use (&$conditions): string {
                    $conditions[] = ['kind' => 'nth', 'value' => (int) $match[1]];
                    return '';
                },
                $branch,
            ) ?? $branch;
            $branch = preg_replace_callback(
                '/:nth-last-child\(\s*(\d+)\s*\)/iu',
                static function (array $match) use (&$conditions): string {
                    $conditions[] = ['kind' => 'nth-last', 'value' => (int) $match[1]];
                    return '';
                },
                $branch,
            ) ?? $branch;
            foreach (
                [
                    'first-of-type' => ['kind' => 'nth-of-type', 'value' => 1],
                    'last-of-type' => ['kind' => 'nth-last-of-type', 'value' => 1],
                    'only-of-type' => ['kind' => 'only-of-type'],
                ] as $pseudo => $condition
            ) {
                $branch = preg_replace_callback(
                    '/:' . preg_quote($pseudo, '/') . '\b/iu',
                    static function () use (&$conditions, $condition): string {
                        $conditions[] = $condition;
                        return '';
                    },
                    $branch,
                ) ?? $branch;
            }
            $branch = preg_replace_callback(
                '/:nth-of-type\(\s*(\d+)\s*\)/iu',
                static function (array $match) use (&$conditions): string {
                    $conditions[] = ['kind' => 'nth-of-type', 'value' => (int) $match[1]];
                    return '';
                },
                $branch,
            ) ?? $branch;
            $branch = preg_replace_callback(
                '/:nth-last-of-type\(\s*(\d+)\s*\)/iu',
                static function (array $match) use (&$conditions): string {
                    $conditions[] = ['kind' => 'nth-last-of-type', 'value' => (int) $match[1]];
                    return '';
                },
                $branch,
            ) ?? $branch;
            $branch = preg_replace_callback(
                '/:empty\b/iu',
                static function () use (&$conditions): string {
                    $conditions[] = ['kind' => 'empty'];
                    return '';
                },
                $branch,
            ) ?? $branch;
            $branch = preg_replace_callback(
                '/:has\(\s*\+\s*([a-z][\w-]*)\s*\)/iu',
                static function (array $match) use (&$conditions): string {
                    $conditions[] = ['kind' => 'next-tag', 'value' => strtolower((string) $match[1])];
                    return '';
                },
                $branch,
            ) ?? $branch;
            $branch = strtr($branch, $protectedAttributes);
            $branch = preg_replace_callback(
                '/\[\s*([\w:-]+)\s*([~|^$*]?=)\s*'
                    . '(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]+))'
                    . '(?:\s+(i))?\s*\]/iu',
                static function (array $match) use (&$conditions): string {
                    if (($match[6] ?? '') === ''
                        && !in_array(strtolower((string) $match[1]), [
                            'accept', 'accept-charset', 'align', 'alink', 'allowfullscreen', 'async',
                            'autofocus', 'autoplay', 'axis', 'bgcolor', 'charset', 'checked', 'clear',
                            'codetype', 'color', 'compact', 'controls', 'declare', 'default', 'defer',
                            'dir', 'direction', 'disabled', 'enctype', 'face', 'formnovalidate', 'frame',
                            'hidden', 'hreflang', 'http-equiv', 'inert', 'ismap', 'itemscope', 'lang',
                            'language', 'link', 'loop', 'media', 'method', 'multiple', 'muted', 'nohref',
                            'nomodule', 'noresize', 'noshade', 'novalidate', 'nowrap', 'open',
                            'playsinline', 'readonly', 'rel', 'required', 'rev', 'reversed', 'rules',
                            'scope', 'scrolling', 'selected', 'shape', 'target', 'text', 'type', 'valign',
                            'valuetype', 'vlink',
                        ], true)
                    ) {
                        return (string) $match[0];
                    }
                    $value = (string) (($match[3] ?? '') !== ''
                        ? $match[3]
                        : (($match[4] ?? '') !== '' ? $match[4] : ($match[5] ?? '')));
                    $conditions[] = [
                        'kind' => 'attr-i',
                        'name' => strtolower((string) $match[1]),
                        'operator' => (string) $match[2],
                        'value' => $value,
                    ];
                    return '[' . $match[1] . ']';
                },
                $branch,
            ) ?? $branch;
            $branch = self::groundingWithoutPseudoClasses($branch);
            try {
                $siblingNodes = self::groundingSiblingSelectorMatches($fragment, trim($branch));
                $nodes = $siblingNodes ?? $fragment->querySelectorAll(trim($branch));
            } catch (\Throwable) {
                continue;
            }
            foreach ($nodes as $node) {
                $keep = true;
                foreach ($conditions as $condition) {
                    if ($condition['kind'] === 'not-nodes') {
                        $keep = !isset($condition['nodes'][spl_object_id($node)]);
                    } elseif ($condition['kind'] === 'not-class') {
                        $classes = preg_split('/\s+/u', trim((string) $node->attribute('class'))) ?: [];
                        $keep = !in_array($condition['value'], $classes, true);
                    } elseif ($condition['kind'] === 'nth') {
                        $siblings = $node->parent()?->elementChildren() ?? [];
                        $keep = array_search($node, $siblings, true) === $condition['value'] - 1;
                    } elseif ($condition['kind'] === 'nth-last') {
                        $siblings = $node->parent()?->elementChildren() ?? [];
                        $position = array_search($node, $siblings, true);
                        $keep = is_int($position)
                            && count($siblings) - $position === $condition['value'];
                    } elseif ($condition['kind'] === 'only-child') {
                        $siblings = $node->parent()?->elementChildren() ?? [];
                        $keep = count($siblings) === 1 && $siblings[0] === $node;
                    } elseif ($condition['kind'] === 'not-nth') {
                        $siblings = $node->parent()?->elementChildren() ?? [];
                        $keep = array_search($node, $siblings, true) !== $condition['value'] - 1;
                    } elseif ($condition['kind'] === 'not-nth-last') {
                        $siblings = $node->parent()?->elementChildren() ?? [];
                        $position = array_search($node, $siblings, true);
                        $keep = !is_int($position)
                            || count($siblings) - $position !== $condition['value'];
                    } elseif ($condition['kind'] === 'not-only-child') {
                        $siblings = $node->parent()?->elementChildren() ?? [];
                        $keep = count($siblings) !== 1 || $siblings[0] !== $node;
                    } elseif (in_array(
                        $condition['kind'],
                        ['nth-of-type', 'nth-last-of-type', 'only-of-type'],
                        true,
                    )) {
                        $siblings = array_values(array_filter(
                            $node->parent()?->elementChildren() ?? [],
                            static fn ($sibling): bool => $sibling->tagName() === $node->tagName(),
                        ));
                        $position = array_search($node, $siblings, true);
                        $keep = $condition['kind'] === 'only-of-type'
                            ? count($siblings) === 1 && $position === 0
                            : (is_int($position) && ($condition['kind'] === 'nth-of-type'
                                ? $position + 1
                                : count($siblings) - $position) === $condition['value']);
                    } elseif ($condition['kind'] === 'empty') {
                        $keep = $node->elementChildren() === [] && $node->textContent() === '';
                    } elseif ($condition['kind'] === 'next-tag') {
                        $siblings = $node->parent()?->elementChildren() ?? [];
                        $position = array_search($node, $siblings, true);
                        $next = is_int($position) ? ($siblings[$position + 1] ?? null) : null;
                        $keep = $next?->tagName() === $condition['value'];
                    } elseif ($condition['kind'] === 'attr-i') {
                        $authored = strtolower((string) $node->attribute($condition['name']));
                        $expected = strtolower((string) $condition['value']);
                        $keep = match ($condition['operator']) {
                            '|=' => $authored === $expected
                                || str_starts_with($authored, $expected . '-'),
                            '^=' => str_starts_with($authored, $expected),
                            '$=' => str_ends_with($authored, $expected),
                            '*=' => str_contains($authored, $expected),
                            '~=' => in_array(
                                $expected,
                                preg_split('/[\x09\x0A\x0C\x0D\x20]+/u', trim($authored)) ?: [],
                                true,
                            ),
                            default => $authored === $expected,
                        };
                    }
                    if (!$keep) {
                        break;
                    }
                }
                if ($keep) {
                    $matched[spl_object_id($node)] = $node;
                }
            }
        }
        return array_values($matched);
    }

    /**
     * @return list<\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode>|null
     */
    private static function groundingHasSelectorMatches(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment $fragment,
        string $selector,
    ): ?array {
        $chain = self::groundingSelectorChain($selector);
        if ($chain === null) {
            return null;
        }
        $ownerIndex = null;
        $position = null;
        foreach ($chain['compounds'] as $index => $compound) {
            $candidate = self::groundingOutermostPseudoFunctionPosition($compound, 'has');
            if ($candidate !== null) {
                $ownerIndex = $index;
                $position = $candidate;
                break;
            }
        }
        if ($ownerIndex === null || $position === null) {
            return null;
        }
        [$start, $open] = $position;
        $compound = $chain['compounds'][$ownerIndex];
        $close = self::groundingClosingParenthesis($compound, $open);
        if ($close === null) {
            return [];
        }
        $arguments = substr($compound, $open + 1, $close - $open - 1);
        $baseCompound = substr($compound, 0, $start) . substr($compound, $close + 1);
        if (trim(self::groundingWithoutPseudoClasses($baseCompound)) === '') {
            $baseCompound = '*' . $baseCompound;
        }
        $ownerSelector = $chain['compounds'][0];
        for ($index = 0; $index < $ownerIndex; $index++) {
            $ownerSelector .= $chain['combinators'][$index] === ' '
                ? ' '
                : $chain['combinators'][$index];
            $ownerSelector .= $index + 1 === $ownerIndex
                ? $baseCompound
                : $chain['compounds'][$index + 1];
        }
        if ($ownerIndex === 0) {
            $ownerSelector = $baseCompound;
        }
        $owners = [];
        foreach (self::matchedCssElements($fragment, $ownerSelector) as $owner) {
            foreach (\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel(
                $arguments,
                [','],
            ) as $branch) {
                $branch = trim($branch);
                if ($branch === '' || self::groundingSpecificityBranchIsInvalid($branch)) {
                    continue;
                }
                if (self::groundingRelativeSelectorMatches($fragment, [$owner], $branch) !== []) {
                    $owners[spl_object_id($owner)] = $owner;
                    break;
                }
            }
        }
        $current = $owners;
        for ($index = $ownerIndex; $index < count($chain['combinators']); $index++) {
            $current = self::groundingRelatedSelectorMatches(
                $fragment,
                array_values($current),
                $chain['combinators'][$index],
                $chain['compounds'][$index + 1],
            );
        }
        return array_values($current);
    }

    /**
     * @param list<\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode> $owners
     * @return array<int,\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode>
     */
    private static function groundingRelativeSelectorMatches(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment $fragment,
        array $owners,
        string $selector,
    ): array {
        $selector = trim($selector);
        $leading = ' ';
        if ($selector !== '' && str_contains('>+~', $selector[0])) {
            $leading = $selector[0];
            $selector = ltrim(substr($selector, 1));
        }
        $chain = self::groundingSelectorChain($selector);
        if ($chain === null) {
            return [];
        }
        $current = $owners;
        foreach ($chain['compounds'] as $index => $compound) {
            $current = array_values(self::groundingRelatedSelectorMatches(
                $fragment,
                $current,
                $index === 0 ? $leading : $chain['combinators'][$index - 1],
                $compound,
            ));
        }
        return $current;
    }

    /**
     * @param list<\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode> $nodes
     * @return array<int,\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode>
     */
    private static function groundingRelatedSelectorMatches(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment $fragment,
        array $nodes,
        string $combinator,
        string $compound,
    ): array {
        $wanted = [];
        foreach (self::matchedCssElements($fragment, $compound) as $node) {
            $wanted[spl_object_id($node)] = true;
        }
        $matches = [];
        foreach ($nodes as $node) {
            $related = match ($combinator) {
                '>' => $node->elementChildren(),
                '+' => self::groundingFollowingSiblings($node, true),
                '~' => self::groundingFollowingSiblings($node, false),
                default => self::groundingDescendants($node),
            };
            foreach ($related as $candidate) {
                if (isset($wanted[spl_object_id($candidate)])) {
                    $matches[spl_object_id($candidate)] = $candidate;
                }
            }
        }
        return $matches;
    }

    /**
     * Match :is()/:where() at any point in a complex selector. An ancestor
     * owner is resolved first, then the remaining relative chain is walked
     * from those exact nodes.
     *
     * @return list<\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode>|null
     */
    private static function groundingFunctionalSelectorMatches(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment $fragment,
        string $selector,
    ): ?array {
        $chain = self::groundingSelectorChain($selector);
        if ($chain === null) {
            return null;
        }
        $ownerIndex = null;
        foreach ($chain['compounds'] as $index => $compound) {
            foreach (['is', 'where'] as $kind) {
                if (self::groundingOutermostPseudoFunctionPosition($compound, $kind) !== null) {
                    $ownerIndex = $index;
                    break 2;
                }
            }
        }
        if ($ownerIndex === null) {
            return null;
        }
        if ($ownerIndex === count($chain['compounds']) - 1) {
            return self::groundingSubjectFunctionalMatches($fragment, $selector);
        }
        $ownerSelector = $chain['compounds'][0];
        for ($index = 0; $index < $ownerIndex; $index++) {
            $ownerSelector .= $chain['combinators'][$index] === ' '
                ? ' '
                : $chain['combinators'][$index];
            $ownerSelector .= $chain['compounds'][$index + 1];
        }
        $current = [];
        foreach (self::matchedCssElements($fragment, $ownerSelector) as $owner) {
            $current[spl_object_id($owner)] = $owner;
        }
        for ($index = $ownerIndex; $index < count($chain['combinators']); $index++) {
            $wanted = [];
            foreach (self::matchedCssElements($fragment, $chain['compounds'][$index + 1]) as $node) {
                $wanted[spl_object_id($node)] = true;
            }
            $next = [];
            foreach ($current as $node) {
                $related = match ($chain['combinators'][$index]) {
                    '>' => $node->elementChildren(),
                    '+' => self::groundingFollowingSiblings($node, true),
                    '~' => self::groundingFollowingSiblings($node, false),
                    default => self::groundingDescendants($node),
                };
                foreach ($related as $candidate) {
                    if (isset($wanted[spl_object_id($candidate)])) {
                        $next[spl_object_id($candidate)] = $candidate;
                    }
                }
            }
            $current = $next;
        }
        return array_values($current);
    }

    /** @return list<\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode> */
    private static function groundingDescendants(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlNode $node,
    ): array {
        $descendants = [];
        $pending = $node->elementChildren();
        while ($pending !== []) {
            $candidate = array_shift($pending);
            if (!$candidate instanceof \Automattic\SiteBuild\BlockSerializer\Html\HtmlNode) {
                continue;
            }
            $descendants[] = $candidate;
            array_push($pending, ...$candidate->elementChildren());
        }
        return $descendants;
    }

    /** @return list<\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode> */
    private static function groundingFollowingSiblings(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlNode $node,
        bool $adjacentOnly,
    ): array {
        $siblings = $node->parent()?->elementChildren() ?? [];
        $position = array_search($node, $siblings, true);
        if (!is_int($position)) {
            return [];
        }
        return $adjacentOnly
            ? array_slice($siblings, $position + 1, 1)
            : array_slice($siblings, $position + 1);
    }

    /**
     * Match subject-position :is()/:where() as an intersection of node sets.
     * This preserves divergent ancestor constraints that cannot be flattened
     * into one equivalent complex selector.
     *
     * @return list<\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode>|null
     */
    private static function groundingSubjectFunctionalMatches(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment $fragment,
        string $selector,
    ): ?array {
        $subjectOffset = self::groundingFinalSelectorCompoundOffset($selector);
        $subject = substr($selector, $subjectOffset);
        $positions = [];
        foreach (['is', 'where'] as $kind) {
            $position = self::groundingOutermostPseudoFunctionPosition($subject, $kind);
            if ($position !== null) {
                $positions[] = [
                    'start' => $subjectOffset + $position[0],
                    'open' => $subjectOffset + $position[1],
                ];
            }
        }
        if ($positions === []) {
            return null;
        }
        usort($positions, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        $function = $positions[0];
        $close = self::groundingClosingParenthesis($selector, $function['open']);
        if ($close === null) {
            return [];
        }
        $base = substr($selector, 0, $function['start']) . substr($selector, $close + 1);
        $baseOffset = self::groundingFinalSelectorCompoundOffset($base);
        $baseSubject = substr($base, $baseOffset);
        if (trim(self::groundingWithoutPseudoClasses($baseSubject)) === '') {
            $base = substr($base, 0, $baseOffset) . '*' . $baseSubject;
        }
        $branchIds = [];
        $arguments = substr($selector, $function['open'] + 1, $close - $function['open'] - 1);
        foreach (\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel(
            $arguments,
            [','],
        ) as $branch) {
            $branch = trim($branch);
            if ($branch === '' || self::groundingSpecificityBranchIsInvalid($branch)) {
                continue;
            }
            foreach (self::matchedCssElements($fragment, $branch) as $node) {
                $branchIds[spl_object_id($node)] = true;
            }
        }
        $matches = [];
        foreach (self::matchedCssElements($fragment, $base) as $node) {
            if (isset($branchIds[spl_object_id($node)])) {
                $matches[spl_object_id($node)] = $node;
            }
        }
        return array_values($matches);
    }

    /**
     * @return list<\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode>|null
     */
    private static function groundingSiblingSelectorMatches(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment $fragment,
        string $selector,
    ): ?array {
        $depth = 0;
        $quote = null;
        $combinator = null;
        for ($index = strlen($selector) - 1; $index >= 0; $index--) {
            $byte = $selector[$index];
            if ($quote !== null) {
                if ($byte === $quote && ($index === 0 || $selector[$index - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
            } elseif ($byte === ']' || $byte === ')') {
                $depth++;
            } elseif ($byte === '[' || $byte === '(') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && ($byte === '+' || $byte === '~')) {
                $combinator = ['offset' => $index, 'kind' => $byte];
                break;
            }
        }
        if ($combinator === null) {
            return null;
        }
        $left = trim(substr($selector, 0, $combinator['offset']));
        $right = trim(substr($selector, $combinator['offset'] + 1));
        if ($left === '' || $right === '') {
            return [];
        }
        $leftIds = [];
        foreach (self::matchedCssElements($fragment, $left) as $node) {
            $leftIds[spl_object_id($node)] = true;
        }
        $matches = [];
        foreach (self::matchedCssElements($fragment, $right) as $node) {
            $siblings = $node->parent()?->elementChildren() ?? [];
            $position = array_search($node, $siblings, true);
            if (!is_int($position) || $position === 0) {
                continue;
            }
            $candidates = $combinator['kind'] === '+'
                ? [$siblings[$position - 1]]
                : array_slice($siblings, 0, $position);
            foreach ($candidates as $candidate) {
                if (isset($leftIds[spl_object_id($candidate)])) {
                    $matches[] = $node;
                    break;
                }
            }
        }
        return $matches;
    }

    /** @return array{0:string,1:list<string>} */
    private static function groundingWithoutNotSelectors(string $selector): array
    {
        $arguments = [];
        while (($position = self::groundingPseudoFunctionPosition($selector, 'not')) !== null) {
            [$start, $open] = $position;
            $close = self::groundingClosingParenthesis($selector, $open);
            if ($close === null) {
                break;
            }
            $arguments[] = substr($selector, $open + 1, $close - $open - 1);
            $selector = substr($selector, 0, $start) . substr($selector, $close + 1);
        }
        return [$selector, $arguments];
    }

    /** @return array{0:int,1:int}|null */
    private static function groundingPseudoFunctionPosition(string $selector, string $name): ?array
    {
        $quote = null;
        $brackets = 0;
        for ($index = 0, $length = strlen($selector); $index < $length; $index++) {
            $byte = $selector[$index];
            if ($quote !== null) {
                if ($byte === '\\') {
                    $index++;
                } elseif ($byte === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
                continue;
            }
            if ($byte === '[') {
                $brackets++;
                continue;
            }
            if ($byte === ']') {
                $brackets = max(0, $brackets - 1);
                continue;
            }
            if ($brackets !== 0 || $byte !== ':') {
                continue;
            }
            if (preg_match('/^:' . preg_quote($name, '/') . '\s*\(/iu', substr($selector, $index), $match) === 1) {
                return [$index, $index + strlen((string) $match[0]) - 1];
            }
        }
        return null;
    }

    /** @return array{0:int,1:int}|null */
    private static function groundingOutermostPseudoFunctionPosition(
        string $selector,
        string $name,
    ): ?array {
        $quote = null;
        $brackets = 0;
        $parentheses = 0;
        for ($index = 0, $length = strlen($selector); $index < $length; $index++) {
            $byte = $selector[$index];
            if ($quote !== null) {
                if ($byte === '\\') {
                    $index++;
                } elseif ($byte === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
                continue;
            }
            if ($byte === '[') {
                $brackets++;
                continue;
            }
            if ($byte === ']') {
                $brackets = max(0, $brackets - 1);
                continue;
            }
            if ($brackets !== 0) {
                continue;
            }
            if ($byte === '(') {
                $parentheses++;
                continue;
            }
            if ($byte === ')') {
                $parentheses = max(0, $parentheses - 1);
                continue;
            }
            if ($parentheses === 0 && $byte === ':'
                && preg_match(
                    '/^:' . preg_quote($name, '/') . '\s*\(/iu',
                    substr($selector, $index),
                    $match,
                ) === 1
            ) {
                return [$index, $index + strlen((string) $match[0]) - 1];
            }
        }
        return null;
    }

    /** @return array{0:string,1:array<string,string>} */
    private static function groundingProtectAttributeSelectors(string $selector): array
    {
        $result = '';
        $protected = [];
        $salt = substr(hash('sha256', $selector), 0, 12);
        for ($index = 0, $length = strlen($selector); $index < $length; $index++) {
            if ($selector[$index] !== '[') {
                $result .= $selector[$index];
                continue;
            }
            $quote = null;
            $close = null;
            for ($cursor = $index + 1; $cursor < $length; $cursor++) {
                $byte = $selector[$cursor];
                if ($quote !== null) {
                    if ($byte === '\\') {
                        $cursor++;
                    } elseif ($byte === $quote) {
                        $quote = null;
                    }
                    continue;
                }
                if ($byte === '\\') {
                    $cursor++;
                    continue;
                }
                if ($byte === '"' || $byte === "'") {
                    $quote = $byte;
                } elseif ($byte === ']') {
                    $close = $cursor;
                    break;
                }
            }
            if ($close === null) {
                $result .= substr($selector, $index);
                break;
            }
            $token = '[_grounding_' . $salt . '_' . count($protected) . ']';
            $protected[$token] = substr($selector, $index, $close - $index + 1);
            $result .= $token;
            $index = $close;
        }
        return [$result, $protected];
    }

    private static function groundingWithoutPseudoClasses(string $selector): string
    {
        $result = '';
        $quote = null;
        $brackets = 0;
        for ($index = 0, $length = strlen($selector); $index < $length; $index++) {
            $byte = $selector[$index];
            if ($quote !== null) {
                $result .= $byte;
                if ($byte === '\\' && ++$index < $length) {
                    $result .= $selector[$index];
                } elseif ($byte === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
                $result .= $byte;
                continue;
            }
            if ($byte === '[') {
                $brackets++;
                $result .= $byte;
                continue;
            }
            if ($byte === ']') {
                $brackets = max(0, $brackets - 1);
                $result .= $byte;
                continue;
            }
            if ($brackets === 0 && $byte === ':') {
                $nameStart = $index + 1;
                if ($nameStart < $length && $selector[$nameStart] === ':') {
                    $nameStart++;
                }
                if (preg_match(
                    '/\G[\p{L}_-][\p{L}\p{N}_-]*/u',
                    $selector,
                    $name,
                    0,
                    $nameStart,
                ) === 1) {
                    $next = $nameStart + strlen((string) $name[0]);
                    while ($next < $length && ctype_space($selector[$next])) {
                        $next++;
                    }
                    if ($next < $length && $selector[$next] === '(') {
                        $close = self::groundingClosingParenthesis($selector, $next);
                        $index = $close ?? $length - 1;
                    } else {
                        $index = $next - 1;
                    }
                    continue;
                }
            }
            $result .= $byte;
        }
        return $result;
    }

    private static function groundingFinalSelectorCompoundOffset(string $selector): int
    {
        $depth = 0;
        $quote = null;
        $start = 0;
        for ($index = 0, $length = strlen($selector); $index < $length; $index++) {
            $byte = $selector[$index];
            if ($quote !== null) {
                if ($byte === '\\') {
                    $index++;
                } elseif ($byte === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
            } elseif ($byte === '[' || $byte === '(') {
                $depth++;
            } elseif ($byte === ']' || $byte === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && ($byte === '>' || $byte === '+' || $byte === '~'
                || ctype_space($byte))
            ) {
                $start = $index + 1;
            }
        }
        return $start;
    }

    private static function groundingDecodeSelectorEscapes(string $selector): string
    {
        return preg_replace_callback(
            '/\\\\([0-9a-f]{1,6})(?:[\x09\x0A\x0C\x0D\x20])?|\\\\(.)/isu',
            static function (array $match): string {
                if (($match[1] ?? '') !== '') {
                    $codepoint = hexdec((string) $match[1]);
                    return $codepoint === 0 || $codepoint > 0x10FFFF
                        ? "\u{FFFD}"
                        : mb_chr($codepoint, 'UTF-8');
                }
                return (string) ($match[2] ?? '');
            },
            $selector,
        ) ?? $selector;
    }

    private static function groundingNormalizeAnalysisEscapes(string $selector): string
    {
        return preg_replace_callback(
            '/\\\\([0-9a-f]{1,6})(?<delimiter>[\x09\x0A\x0C\x0D\x20])?'
                . '(?=(?<next>[\s\S]|$))|\\\\(?<escaped>.)/isu',
            static function (array $match): string {
                if (($match[1] ?? '') !== '') {
                    $codepoint = hexdec((string) $match[1]);
                    $decoded = $codepoint === 0 || $codepoint > 0x10FFFF
                        ? "\u{FFFD}"
                        : mb_chr($codepoint, 'UTF-8');
                    if (preg_match('/^[\p{L}\p{N}_-]+$/u', $decoded) !== 1) {
                        return 'z';
                    }
                    $delimiter = (string) ($match['delimiter'] ?? '');
                    $next = (string) ($match['next'] ?? '');
                    if ($delimiter !== '' && $next !== ''
                        && preg_match('/^[\p{L}\p{N}_-]/u', $next) !== 1
                    ) {
                        return $decoded . $delimiter;
                    }
                    return $decoded;
                }
                $decoded = (string) ($match['escaped'] ?? '');
                return preg_match('/^[\p{L}\p{N}_-]+$/u', $decoded) === 1 ? $decoded : 'z';
            },
            $selector,
        ) ?? $selector;
    }

    private static function groundingNormalizeEscapedIdentifierSelectors(string $selector): string
    {
        $result = '';
        $quote = null;
        $brackets = 0;
        for ($index = 0, $length = strlen($selector); $index < $length; $index++) {
            $byte = $selector[$index];
            if ($quote !== null) {
                $result .= $byte;
                if ($byte === '\\' && ++$index < $length) {
                    $result .= $selector[$index];
                } elseif ($byte === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
                $result .= $byte;
                continue;
            }
            if ($byte === '[') {
                $brackets++;
                $result .= $byte;
                continue;
            }
            if ($byte === ']') {
                $brackets = max(0, $brackets - 1);
                $result .= $byte;
                continue;
            }
            if ($brackets !== 0 || ($byte !== '#' && $byte !== '.')) {
                $result .= $byte;
                continue;
            }
            $cursor = $index + 1;
            $identifier = '';
            $hasEscape = false;
            while ($cursor < $length) {
                if ($selector[$cursor] === '\\' && preg_match(
                    '/^\\\\(?:[0-9a-f]{1,6}(?:[\x09\x0A\x0C\x0D\x20])?|.)/isu',
                    substr($selector, $cursor),
                    $escape,
                ) === 1) {
                    $identifier .= (string) $escape[0];
                    $cursor += strlen((string) $escape[0]);
                    $hasEscape = true;
                    continue;
                }
                $ordinal = ord($selector[$cursor]);
                if (!(ctype_alnum($selector[$cursor])
                    || $selector[$cursor] === '_'
                    || $selector[$cursor] === '-'
                    || $ordinal >= 0x80)
                ) {
                    break;
                }
                $identifier .= $selector[$cursor++];
            }
            if (!$hasEscape) {
                $result .= $byte;
                continue;
            }
            $decoded = self::groundingDecodeSelectorEscapes($identifier);
            $decoded = str_replace(['\\', '"'], ['\\\\', '\\"'], $decoded);
            $result .= $byte === '#'
                ? '[id="' . $decoded . '"]'
                : '[class~="' . $decoded . '"]';
            $index = $cursor - 1;
        }
        return $result;
    }

    private static function groundingBroadenUnsupportedSelectorEscapes(string $selector): string
    {
        [$selector, $attributes] = self::groundingProtectAttributeSelectors($selector);
        foreach ($attributes as $token => $attribute) {
            $attributes[$token] = str_contains($attribute, '\\') ? '' : $attribute;
        }
        $selector = strtr($selector, $attributes);
        if (trim($selector) === '') {
            $selector = '*';
        }
        if (preg_match('/^\s*[>+~]/u', $selector) === 1) {
            $selector = '*' . $selector;
        }
        if (preg_match('/[>+~]\s*$/u', $selector) === 1) {
            $selector .= '*';
        }
        do {
            $repaired = preg_replace('/([>+~])(\s*)(?=[>+~])/u', '$1$2*', $selector) ?? $selector;
            $changed = $repaired !== $selector;
            $selector = $repaired;
        } while ($changed);
        $result = '';
        $atCompoundStart = true;
        $parentheses = 0;
        $brackets = 0;
        $quote = null;
        for ($index = 0, $length = strlen($selector); $index < $length;) {
            $byte = $selector[$index];
            if ($quote !== null) {
                $result .= $byte;
                if ($byte === '\\' && ++$index < $length) {
                    $result .= $selector[$index];
                } elseif ($byte === $quote) {
                    $quote = null;
                }
                $index++;
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
                $result .= $byte;
                $index++;
                continue;
            }
            if ($byte === '[') {
                $brackets++;
            } elseif ($byte === ']') {
                $brackets = max(0, $brackets - 1);
            } elseif ($brackets === 0 && $byte === '(') {
                $parentheses++;
            } elseif ($brackets === 0 && $byte === ')') {
                $parentheses = max(0, $parentheses - 1);
            }
            if ($brackets !== 0 || $parentheses !== 0 || $byte === ')') {
                $result .= $byte;
                $index++;
                continue;
            }
            if (ctype_space($byte) || $byte === '>' || $byte === '+' || $byte === '~') {
                $result .= $byte;
                $atCompoundStart = true;
                $index++;
                continue;
            }
            if ($atCompoundStart && !str_contains('*#.[::', $byte)) {
                $cursor = $index;
                $identifier = '';
                $hasEscape = false;
                while ($cursor < $length) {
                    if ($selector[$cursor] === '\\' && preg_match(
                        '/^\\\\(?:[0-9a-f]{1,6}(?:[\x09\x0A\x0C\x0D\x20])?|.)/isu',
                        substr($selector, $cursor),
                        $escape,
                    ) === 1) {
                        $identifier .= (string) $escape[0];
                        $cursor += strlen((string) $escape[0]);
                        $hasEscape = true;
                        continue;
                    }
                    $ordinal = ord($selector[$cursor]);
                    if (!(ctype_alnum($selector[$cursor])
                        || $selector[$cursor] === '_'
                        || $selector[$cursor] === '-'
                        || $ordinal >= 0x80)
                    ) {
                        break;
                    }
                    $identifier .= $selector[$cursor++];
                }
                if ($identifier !== '') {
                    $result .= $hasEscape ? '*' : $identifier;
                    $index = $cursor;
                    $atCompoundStart = false;
                    continue;
                }
            }
            $result .= $byte;
            $atCompoundStart = false;
            $index++;
        }
        return self::groundingNormalizeAnalysisEscapes($result);
    }

    /** @return list<string> */
    private static function groundingExpandedSelectors(string $selector): array
    {
        $positions = array_values(array_filter(
            [
                self::groundingPseudoFunctionPosition($selector, 'is'),
                self::groundingPseudoFunctionPosition($selector, 'where'),
            ],
            is_array(...),
        ));
        if ($positions === []) {
            return [$selector];
        }
        usort($positions, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        [$start, $open] = $positions[0];
        $close = self::groundingClosingParenthesis($selector, $open);
        if ($close === null) {
            return [];
        }
        $expanded = [];
        $prefix = substr($selector, 0, $start);
        $suffix = substr($selector, $close + 1);
        $prefixSubjectOffset = self::groundingFinalSelectorCompoundOffset($prefix);
        $prefixAncestor = substr($prefix, 0, $prefixSubjectOffset);
        $prefixSubject = substr($prefix, $prefixSubjectOffset);
        foreach (\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel(
            substr($selector, $open + 1, $close - $open - 1),
            [','],
        ) as $branch) {
            $branch = trim($branch);
            $intersection = self::groundingIntersectSelectorSubjects($prefix, $branch);
            if ($intersection === '') {
                continue;
            }
            if ($intersection === null) {
                $branchSubjectOffset = self::groundingFinalSelectorCompoundOffset($branch);
                $intersection = $prefixAncestor
                    . substr($branch, 0, $branchSubjectOffset)
                    . $prefixSubject
                    . substr($branch, $branchSubjectOffset);
            }
            $replaced = $intersection . $suffix;
            array_push($expanded, ...self::groundingExpandedSelectors($replaced));
        }
        return array_values(array_unique($expanded));
    }

    /**
     * Intersect two complex selectors that share their final subject. Chains
     * with the same right-to-left combinators can merge each corresponding
     * compound instead of serializing constraints that belong to one node.
     *
     * @return string|null Empty means the compounds cannot select one element;
     *     null means the relationship cannot be represented by one chain.
     */
    private static function groundingIntersectSelectorSubjects(string $left, string $right): ?string
    {
        $leftChain = self::groundingSelectorChain($left);
        $rightChain = self::groundingSelectorChain($right);
        if ($leftChain === null || $rightChain === null) {
            return null;
        }
        $leftCount = count($leftChain['compounds']);
        $rightCount = count($rightChain['compounds']);
        $overlap = min($leftCount, $rightCount);
        for ($depth = 1; $depth < $overlap; $depth++) {
            if ($leftChain['combinators'][$leftCount - $depth - 1]
                !== $rightChain['combinators'][$rightCount - $depth - 1]
            ) {
                return null;
            }
        }
        $base = $leftCount >= $rightCount ? $leftChain : $rightChain;
        $baseCount = count($base['compounds']);
        for ($depth = 0; $depth < $overlap; $depth++) {
            $compound = self::groundingIntersectSelectorCompounds(
                $leftChain['compounds'][$leftCount - $depth - 1],
                $rightChain['compounds'][$rightCount - $depth - 1],
            );
            if ($compound === null) {
                return '';
            }
            $base['compounds'][$baseCount - $depth - 1] = $compound;
        }
        $selector = $base['compounds'][0];
        foreach ($base['combinators'] as $index => $combinator) {
            $selector .= $combinator === ' ' ? ' ' : $combinator;
            $selector .= $base['compounds'][$index + 1];
        }
        return $selector;
    }

    /**
     * @return array{compounds:list<string>,combinators:list<string>}|null
     */
    private static function groundingSelectorChain(string $selector): ?array
    {
        $compounds = [];
        $combinators = [];
        $compound = '';
        $depth = 0;
        $quote = null;
        for ($index = 0, $length = strlen($selector); $index < $length;) {
            $byte = $selector[$index];
            if ($quote !== null) {
                $compound .= $byte;
                if ($byte === '\\' && ++$index < $length) {
                    $compound .= $selector[$index];
                } elseif ($byte === $quote) {
                    $quote = null;
                }
                $index++;
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
                $compound .= $byte;
                $index++;
                continue;
            }
            if ($byte === '\\' && $index + 1 < $length) {
                $compound .= $byte . $selector[$index + 1];
                $index += 2;
                continue;
            }
            if ($byte === '[' || $byte === '(') {
                $depth++;
            } elseif ($byte === ']' || $byte === ')') {
                $depth = max(0, $depth - 1);
            }
            if ($depth !== 0) {
                $compound .= $byte;
                $index++;
                continue;
            }
            if (ctype_space($byte)) {
                $next = $index + 1;
                while ($next < $length && ctype_space($selector[$next])) {
                    $next++;
                }
                if (trim($compound) === '') {
                    $index = $next;
                    continue;
                }
                $compounds[] = trim($compound);
                $compound = '';
                if ($next < $length && str_contains('>+~', $selector[$next])) {
                    $combinators[] = $selector[$next];
                    $next++;
                    while ($next < $length && ctype_space($selector[$next])) {
                        $next++;
                    }
                } else {
                    $combinators[] = ' ';
                }
                $index = $next;
                continue;
            }
            if (str_contains('>+~', $byte)) {
                if (trim($compound) === '') {
                    return null;
                }
                $compounds[] = trim($compound);
                $combinators[] = $byte;
                $compound = '';
                $index++;
                while ($index < $length && ctype_space($selector[$index])) {
                    $index++;
                }
                continue;
            }
            $compound .= $byte;
            $index++;
        }
        if (trim($compound) === '') {
            return null;
        }
        $compounds[] = trim($compound);
        return count($combinators) === count($compounds) - 1
            ? compact('compounds', 'combinators')
            : null;
    }

    private static function groundingIntersectSelectorCompounds(string $left, string $right): ?string
    {
        $pattern = '/^(?<type>[a-z][\\w-]*|\\*)(?<tail>.*)$/isu';
        $leftHasType = preg_match($pattern, $left, $leftParts) === 1;
        $rightHasType = preg_match($pattern, $right, $rightParts) === 1;
        if ($leftHasType && $rightHasType
            && strcasecmp((string) $leftParts['type'], (string) $rightParts['type']) !== 0
            && $leftParts['type'] !== '*'
            && $rightParts['type'] !== '*'
        ) {
            return null;
        }
        if ($leftHasType || $rightHasType) {
            $type = $leftHasType && $leftParts['type'] !== '*'
                ? (string) $leftParts['type']
                : (string) $rightParts['type'];
            return $type
                . ($leftHasType ? (string) $leftParts['tail'] : $left)
                . ($rightHasType ? (string) $rightParts['tail'] : $right);
        }
        return $left . $right;
    }

    private static function groundingClosingParenthesis(string $value, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        for ($index = $open, $length = strlen($value); $index < $length; $index++) {
            $byte = $value[$index];
            if ($quote !== null) {
                if ($byte === '\\') {
                    $index++;
                } elseif ($byte === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
            } elseif ($byte === '(') {
                $depth++;
            } elseif ($byte === ')' && --$depth === 0) {
                return $index;
            }
        }
        return null;
    }

    /**
     * @param array<int,array<string,array{value:string}>> $winners
     * @param array<string,array{inherits:bool,initial:string}> $registrations
     */
    private static function groundingResolvedCssValue(
        ?string $value,
        string $property,
        \DOMElement $element,
        array $winners,
        array $registrations,
    ): string {
        if ($value === null) {
            if ($property !== 'visibility' || !$element->parentNode instanceof \DOMElement) {
                return '<initial>';
            }
            $parent = $element->parentNode;
            return self::groundingResolvedCssValue(
                $winners[spl_object_id($parent)][$property]['value'] ?? null,
                $property,
                $parent,
                $winners,
                $registrations,
            );
        }
        $resolved = self::groundingTryResolveCssValue(
            $value,
            $element,
            $winners,
            $registrations,
            [],
            false,
            0,
        )['value'] ?? strtolower(trim(CssChecks::decodeIdentifier($value)));
        if ($resolved === 'inherit'
            || ($property === 'visibility' && in_array($resolved, ['revert', 'unset'], true))
        ) {
            if (!$element->parentNode instanceof \DOMElement) {
                return '<initial>';
            }
            $parent = $element->parentNode;
            return self::groundingResolvedCssValue(
                $winners[spl_object_id($parent)][$property]['value'] ?? null,
                $property,
                $parent,
                $winners,
                $registrations,
            );
        }
        return in_array($resolved, ['initial', 'revert', 'revert-layer', 'unset'], true)
            ? '<initial>'
            : $resolved;
    }

    /**
     * @param array<int,array<string,array{value:string}>> $winners
     * @param array<string,array{inherits:bool,initial:string}> $registrations
     * @param array<string,true> $resolving
     * @return array{value:?string,cycle:bool}
     */
    private static function groundingTryResolveCssValue(
        string $value,
        \DOMElement $element,
        array $winners,
        array $registrations,
        array $resolving,
        bool $customPropertyValue,
        int $depth,
    ): array {
        if ($depth >= 32) {
            return ['value' => null, 'cycle' => true];
        }
        $value = preg_replace('~/\*.*?\*/~su', ' ', $value) ?? $value;
        $value = CssChecks::decodeIdentifier($value);
        if (preg_match(
            '/(?<![-\w])(var|env|attr)\s*\(/iu',
            $value,
            $match,
            PREG_OFFSET_CAPTURE,
        ) !== 1) {
            $resolved = strtolower(trim($value));
            return ['value' => $customPropertyValue && in_array(
                $resolved,
                ['initial', 'inherit', 'revert', 'revert-layer', 'unset'],
                true,
            ) ? null : $resolved, 'cycle' => false];
        }
        $function = strtolower((string) $match[1][0]);
        $start = (int) $match[0][1];
        $open = $start + strlen((string) $match[0][0]) - 1;
        $close = self::groundingClosingParenthesis($value, $open);
        if ($close === null) {
            return ['value' => null, 'cycle' => false];
        }
        $arguments = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel(
            substr($value, $open + 1, $close - $open - 1),
            [','],
        );
        $replacement = ['value' => null, 'cycle' => false];
        if ($function === 'var') {
            $name = trim((string) ($arguments[0] ?? ''));
            if (preg_match('/^--[-_a-z0-9\x{80}-\x{10ffff}]+$/iuD', $name) !== 1) {
                $name = '';
            }
            $node = $element;
            while ($name !== '' && $node instanceof \DOMElement) {
                $winner = $winners[spl_object_id($node)][$name] ?? null;
                if (!is_array($winner)) {
                    if (isset($registrations[$name]) && !$registrations[$name]['inherits']) {
                        if ($registrations[$name]['initial'] !== null) {
                            $replacement = self::groundingTryResolveCssValue(
                                $registrations[$name]['initial'],
                                $node,
                                $winners,
                                $registrations,
                                $resolving,
                                true,
                                $depth + 1,
                            );
                        }
                        break;
                    }
                    $node = $node->parentNode;
                    continue;
                }
                $wide = strtolower(trim(CssChecks::decodeIdentifier((string) $winner['value'])));
                if ($wide === 'inherit'
                    || (in_array($wide, ['revert', 'revert-layer', 'unset'], true)
                        && ($registrations[$name]['inherits'] ?? true))
                ) {
                    $node = $node->parentNode;
                    continue;
                }
                if ($wide === 'initial'
                    || in_array($wide, ['revert', 'revert-layer', 'unset'], true)
                ) {
                    if (isset($registrations[$name])
                        && $registrations[$name]['initial'] !== null
                    ) {
                        $replacement = self::groundingTryResolveCssValue(
                            $registrations[$name]['initial'],
                            $node,
                            $winners,
                            $registrations,
                            $resolving,
                            true,
                            $depth + 1,
                        );
                    }
                    break;
                }
                $key = spl_object_id($node) . "\0" . $name;
                if (isset($resolving[$key])) {
                    $replacement = ['value' => null, 'cycle' => true];
                    break;
                }
                $nextResolving = $resolving;
                $nextResolving[$key] = true;
                $replacement = self::groundingTryResolveCssValue(
                    (string) $winner['value'],
                    $node,
                    $winners,
                    $registrations,
                    $nextResolving,
                    true,
                    $depth + 1,
                );
                if ($replacement['value'] !== null
                    && isset($registrations[$name])
                    && !CssScrub::customPropertyValueMatchesSyntax(
                        $replacement['value'],
                        $registrations[$name]['syntax'],
                    )
                ) {
                    $replacement = $registrations[$name]['initial'] === null
                        ? ['value' => null, 'cycle' => false]
                        : self::groundingTryResolveCssValue(
                            $registrations[$name]['initial'],
                            $node,
                            $winners,
                            $registrations,
                            $resolving,
                            true,
                            $depth + 1,
                        );
                }
                break;
            }
            if ($name !== ''
                && $replacement['value'] === null
                && !$replacement['cycle']
                && ($registrations[$name]['inherits'] ?? true)
                && is_array($winners[0][$name] ?? null)
            ) {
                $replacement = self::groundingTryResolveCssValue(
                    (string) $winners[0][$name]['value'],
                    $element,
                    $winners,
                    $registrations,
                    $resolving,
                    true,
                    $depth + 1,
                );
                if ($replacement['value'] !== null
                    && isset($registrations[$name])
                    && !CssScrub::customPropertyValueMatchesSyntax(
                        $replacement['value'],
                        $registrations[$name]['syntax'],
                    )
                ) {
                    $replacement = $registrations[$name]['initial'] === null
                        ? ['value' => null, 'cycle' => false]
                        : self::groundingTryResolveCssValue(
                            $registrations[$name]['initial'],
                            $element,
                            $winners,
                            $registrations,
                            $resolving,
                            true,
                            $depth + 1,
                        );
                }
            }
            if ($name !== ''
                && $replacement['value'] === null
                && !$replacement['cycle']
                && isset($registrations[$name])
                && $registrations[$name]['initial'] !== null
            ) {
                $replacement = self::groundingTryResolveCssValue(
                    $registrations[$name]['initial'],
                    $element,
                    $winners,
                    $registrations,
                    $resolving,
                    true,
                    $depth + 1,
                );
            }
            if ($replacement['cycle'] && $customPropertyValue) {
                return $replacement;
            }
        } elseif ($function === 'env') {
            $environment = trim((string) ($arguments[0] ?? ''));
            $known = CssScrub::knownEnvironmentValue($environment);
            if ($known !== null) {
                $replacement = ['value' => $known, 'cycle' => false];
            }
        } else {
            $attributeArgument = trim((string) ($arguments[0] ?? ''));
            $attribute = preg_split('/\s+/u', $attributeArgument, 2)[0] ?? '';
            $type = preg_match('/\btype\(\s*([^)]*?)\s*\)\s*$/iu', $attributeArgument, $typeMatch) === 1
                ? trim((string) $typeMatch[1])
                : null;
            if ($attribute !== ''
                && $element->hasAttribute($attribute)
                && CssScrub::attributeValueMatchesType($element->getAttribute($attribute), $type)
            ) {
                $replacement = [
                    'value' => trim($element->getAttribute($attribute)),
                    'cycle' => false,
                ];
            }
        }
        if ($replacement['value'] === null && count($arguments) > 1) {
            $replacement = self::groundingTryResolveCssValue(
                implode(',', array_slice($arguments, 1)),
                $element,
                $winners,
                $registrations,
                $resolving,
                $customPropertyValue,
                $depth + 1,
            );
        }
        if ($replacement['value'] === null) {
            return $replacement;
        }
        return self::groundingTryResolveCssValue(
            substr($value, 0, $start) . $replacement['value'] . substr($value, $close + 1),
            $element,
            $winners,
            $registrations,
            $resolving,
            $customPropertyValue,
            $depth + 1,
        );
    }

    /** @param array{important:bool,layerRank:int,specificity:int,order:int} $candidate */
    private static function groundingCascadeCandidateWins(array $candidate, ?array $winner): bool
    {
        if ($winner === null) {
            return true;
        }
        if ($candidate['important'] !== $winner['important']) {
            return $candidate['important'];
        }
        if ($candidate['layerRank'] !== $winner['layerRank']) {
            return $candidate['layerRank'] > $winner['layerRank'];
        }
        if ($candidate['specificity'] !== $winner['specificity']) {
            return $candidate['specificity'] > $winner['specificity'];
        }
        return $candidate['order'] >= $winner['order'];
    }

    private static function contextElementHasAncestorTag(\DOMElement $element, string $tag): bool
    {
        $parent = $element->parentNode;
        while ($parent instanceof \DOMElement) {
            if (strtolower($parent->tagName) === $tag) {
                return true;
            }
            $parent = $parent->parentNode;
        }
        return false;
    }

    /** @param array<int,array<string,string>> $stylesheetStyles */
    private static function renderedFormControlValue(\DOMElement $control, array $stylesheetStyles): ?string
    {
        $tag = strtolower($control->tagName);
        if ($tag === 'textarea') {
            return $control->textContent;
        }
        if ($tag === 'output') {
            return self::renderedControlText($control, $stylesheetStyles);
        }
        if ($tag === 'select') {
            $options = [];
            foreach ($control->getElementsByTagName('option') as $option) {
                $options[] = $option;
            }
            if ($options === []) {
                return null;
            }
            if ($control->hasAttribute('multiple')) {
                // Every option is visibly published in a multi-select listbox,
                // irrespective of its selected state.
                $selected = array_values(array_filter(
                    $options,
                    static fn (\DOMElement $option): bool => !self::contextLabelIsHidden(
                        $option,
                        $stylesheetStyles,
                    ),
                ));
            } else {
                $selected = [];
                foreach ($options as $option) {
                    if ($option->hasAttribute('selected')) {
                        $selected = [$option];
                    }
                }
                $selected = $selected === [] ? [$options[0]] : $selected;
            }
            return implode(' ', array_map(
                static fn (\DOMElement $option): string => $option->hasAttribute('label')
                    ? $option->getAttribute('label')
                    : $option->textContent,
                $selected,
            ));
        }
        if ($tag !== 'input') {
            return self::renderedControlText($control, $stylesheetStyles);
        }
        $type = strtolower(trim($control->getAttribute('type')));
        $type = in_array($type, [
            'button', 'checkbox', 'color', 'date', 'datetime-local', 'email', 'file', 'hidden', 'image',
            'month', 'number', 'password', 'radio', 'range', 'reset', 'search', 'submit', 'tel', 'text',
            'time', 'url', 'week',
        ], true) ? $type : 'text';
        if ($type === 'image') {
            return $control->getAttribute('alt');
        }
        if ($type === 'range') {
            return self::renderedRangeValue($control);
        }
        if (!$control->hasAttribute('value')) {
            return null;
        }
        if (!in_array(
            $type,
            ['button', 'email', 'number', 'range', 'reset', 'search', 'submit', 'tel', 'text', 'url'],
            true,
        )) {
            return null;
        }
        $value = $control->getAttribute('value');
        if (in_array($type, ['email', 'search', 'tel', 'text', 'url'], true)) {
            $value = str_replace(["\r", "\n"], '', $value);
        }
        if (in_array($type, ['email', 'url'], true)) {
            $value = trim($value, "\x09\x0A\x0C\x0D\x20");
        }
        if ($type === 'number'
            && preg_match('/^-?(?:[0-9]+|[0-9]*\.[0-9]+)(?:e[+-]?[0-9]+)?$/iD', $value) !== 1
        ) {
            return null;
        }
        return $value;
    }

    private static function renderedRangeValue(\DOMElement $control): string
    {
        $authoredMin = self::browserFloatingAttribute($control, 'min');
        $authoredValue = self::browserFloatingAttribute($control, 'value');
        $min = $authoredMin ?? 0.0;
        $max = self::browserFloatingAttribute($control, 'max') ?? 100.0;
        if ($max < $min) {
            $max = $min;
        }
        $value = $authoredValue ?? (($min + $max) / 2);
        $value = max($min, min($max, $value));
        $stepValue = strtolower(trim($control->getAttribute('step')));
        $step = $stepValue === 'any' ? null : (self::browserFloatingAttribute($control, 'step') ?? 1.0);
        if ($step !== null && $step > 0) {
            $stepBase = $authoredMin ?? $authoredValue ?? 0.0;
            $value = $stepBase + round(($value - $stepBase) / $step, 0, PHP_ROUND_HALF_UP) * $step;
            $value = max($min, min($max, $value));
        }
        if (floor($value) === $value) {
            return sprintf('%.0F', $value);
        }
        return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
    }

    /** @param array<int,array<string,string>> $stylesheetStyles */
    private static function renderedControlText(\DOMElement $control, array $stylesheetStyles): string
    {
        $text = '';
        foreach ($control->childNodes as $child) {
            $text .= self::renderedControlNodeText($child, $stylesheetStyles);
        }
        return $text;
    }

    /** @param array<int,array<string,string>> $stylesheetStyles */
    private static function renderedControlNodeText(\DOMNode $node, array $stylesheetStyles): string
    {
        if ($node instanceof \DOMText) {
            return $node->data;
        }
        if (!$node instanceof \DOMElement || self::contextLabelIsHidden($node, $stylesheetStyles)) {
            return '';
        }
        if (in_array(strtolower($node->tagName), ['script', 'style', 'template'], true)) {
            return '';
        }
        $text = self::accessibleTextIsNotEmpty($node->getAttribute('aria-label'))
            ? $node->getAttribute('aria-label') . ' '
            : '';
        foreach ($node->childNodes as $child) {
            $text .= self::renderedControlNodeText($child, $stylesheetStyles);
        }
        return $text;
    }

    private static function browserFloatingAttribute(\DOMElement $element, string $name): ?float
    {
        if (!$element->hasAttribute($name)) {
            return null;
        }
        $value = trim($element->getAttribute($name));
        if (preg_match('/^-?(?:[0-9]+|[0-9]*\.[0-9]+)(?:e[+-]?[0-9]+)?$/iD', $value) !== 1) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }

    /**
     * @param array<int|string,list<array{type:string,authored:string,canonical:string}>> $byBlock
     * @param list<array{type:string,authored:string,canonical:string}> $candidates
     */
    private static function appendDocumentContextCandidates(
        array &$byBlock,
        BlockMarkup $blocks,
        int $offset,
        array $candidates,
    ): void {
        if ($candidates === []) {
            return;
        }
        $owner = 'orphan';
        $ownerLength = PHP_INT_MAX;
        foreach ($blocks->indices() as $index) {
            $end = $blocks->endOffset($index);
            if ($end === null) {
                continue;
            }
            $start = $blocks->openingOffset($index);
            $length = $end - $start;
            if ($start <= $offset && $offset < $end && $length < $ownerLength) {
                $owner = $index;
                $ownerLength = $length;
            }
        }
        $byBlock[$owner] ??= [];
        array_push($byBlock[$owner], ...$candidates);
    }

    /** @return array{type:string,authored:string,canonical:string} */
    private static function uninspectableDocumentContextCandidate(string $html, string $reason): array
    {
        return [
            'type' => 'generated_text',
            'authored' => 'document accessibility context (' . $reason . ')',
            'canonical' => 'uninspectable-document-context:' . hash('sha256', $html . "\0" . $reason),
        ];
    }

    /** @return list<array{type:string,authored:string,canonical:string}> */
    private static function htmlAccessibleAttributeCandidates(string $html): array
    {
        $candidates = self::htmlNativeAttributeCandidates($html);
        array_push(
            $candidates,
            ...self::htmlAccessibleAttributeCandidatesByKind(
                HtmlBlockContext::withoutHiddenSubtrees($html, true, false),
                false,
            ),
        );
        return self::dedupe($candidates);
    }

    /** @return list<array{type:string,authored:string,canonical:string}> */
    private static function htmlNativeAttributeCandidates(string $html): array
    {
        return self::htmlAccessibleAttributeCandidatesByKind($html, true);
    }

    /** @return list<array{type:string,authored:string,canonical:string}> */
    private static function htmlAccessibleAttributeCandidatesByKind(string $html, bool $native): array
    {
        $candidates = [];
        HtmlBlockContext::rewriteOpeningTags(
            $html,
            static function (string $tag, string $namespace) use (&$candidates, $native): string {
                if ($namespace !== 'html') {
                    return $tag;
                }
                preg_match('/^<\s*([^\s\/>]+)/u', $tag, $tagMatch);
                $tagName = strtolower((string) ($tagMatch[1] ?? ''));
                $attributes = MarkupSanitizer::openingTagAttributes($tag);
                $first = [];
                foreach ($attributes as $attribute) {
                    if (!isset($first[$attribute['name']])) {
                        $first[$attribute['name']] = $attribute;
                    }
                }
                foreach ($first as $attribute) {
                    $isNative = in_array(
                        $attribute['name'],
                        ['alt', 'label', 'placeholder', 'title', 'value'],
                        true,
                    );
                    if ($attribute['valueStart'] === null
                        || $isNative !== $native
                        || !self::isHtmlAccessibleAttribute($tagName, $attribute['name'], $tag, $first)
                    ) {
                        continue;
                    }
                    $value = substr(
                        $tag,
                        $attribute['valueStart'],
                        $attribute['valueEnd'] - $attribute['valueStart'],
                    );
                    array_push(
                        $candidates,
                        ...ContactFacts::visibleCandidates(LinkTargets::decodeBrowserEntities($value)),
                    );
                }
                return $tag;
            },
            true,
        );
        return self::dedupe($candidates);
    }

    /** Hidden referenced nodes still contribute to an exposed accessible name. */
    private static function referencedAccessibleTextCandidates(
        string $html,
        ?bool $domAvailable = null,
    ): array
    {
        $references = [];
        $visible = HtmlBlockContext::withoutHiddenSubtrees($html, true, false);
        HtmlBlockContext::rewriteOpeningTags(
            $visible,
            static function (string $tag) use (&$references): string {
                $seen = [];
                foreach (MarkupSanitizer::openingTagAttributes($tag) as $attribute) {
                    if (isset($seen[$attribute['name']])) {
                        continue;
                    }
                    $seen[$attribute['name']] = true;
                    if (!in_array($attribute['name'], ['aria-describedby', 'aria-labelledby'], true)
                        || $attribute['valueStart'] === null
                    ) {
                        continue;
                    }
                    $value = LinkTargets::decodeBrowserEntities(substr(
                        $tag,
                        $attribute['valueStart'],
                        $attribute['valueEnd'] - $attribute['valueStart'],
                    ));
                    foreach (self::splitAriaIdRefs($value) as $id) {
                        $references[$id] = true;
                    }
                }
                return $tag;
            },
            true,
        );
        if ($references === []) {
            return [];
        }
        $domAvailable ??= class_exists(\DOMDocument::class);
        if (!$domAvailable) {
            return [self::uninspectableAriaCandidate(array_keys($references), 'DOM unavailable')];
        }
        $dom = Html::loadUtf8Html(
            '<!doctype html><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        if (!$dom instanceof \DOMDocument) {
            return [self::uninspectableAriaCandidate(array_keys($references), 'DOM parse failed')];
        }
        $candidates = [];
        foreach (array_keys($references) as $id) {
            $node = $dom->getElementById($id);
            if ($node instanceof \DOMElement) {
                $visited = [];
                array_push(
                    $candidates,
                    ...ContactFacts::visibleCandidates(self::accessibleReferenceText(
                        $node,
                        $dom,
                        $visited,
                        true,
                        null,
                        'document',
                        null,
                        false,
                    )),
                );
            }
        }
        return self::dedupe($candidates);
    }

    /**
     * @param list<string> $referenceIds
     * @return array{type:string,authored:string,canonical:string}
     */
    private static function uninspectableAriaCandidate(array $referenceIds, string $reason): array
    {
        $authored = implode(' ', array_map(
            static fn (string $id): string => '#' . $id,
            $referenceIds,
        ));
        return [
            'type' => 'generated_text',
            'authored' => 'aria references ' . $authored . ' (' . $reason . ')',
            'canonical' => 'uninspectable-aria-reference:' . hash('sha256', $authored . "\0" . $reason),
        ];
    }

    /**
     * @param array<int,true> $visited
     * @param array<string,array<string,\DOMElement>>|null $ids
     */
    private static function accessibleReferenceText(
        \DOMNode $node,
        \DOMDocument $dom,
        array &$visited,
        bool $directReference = false,
        ?array $ids = null,
        string $treeScope = 'document',
        ?\DOMElement $titleSuppressedFor = null,
        bool $followLabelledBy = true,
    ): string {
        if ($node instanceof \DOMText) {
            return $node->data;
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $identity = spl_object_id($node);
        if (isset($visited[$identity])) {
            return '';
        }
        $visited[$identity] = true;
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'template'], true)) {
            return '';
        }

        $labelledBy = $node->getAttribute('aria-labelledby');
        if ($followLabelledBy && $labelledBy !== '') {
            $referenceIds = self::splitAriaIdRefs($labelledBy);
            $hasDirectSelfReference = false;
            foreach ($referenceIds as $id) {
                $target = $ids === null ? $dom->getElementById($id) : ($ids[$treeScope][$id] ?? null);
                if ($target === $node) {
                    $hasDirectSelfReference = true;
                    break;
                }
            }
            $parts = [];
            foreach ($referenceIds as $id) {
                $target = $ids === null ? $dom->getElementById($id) : ($ids[$treeScope][$id] ?? null);
                if ($target instanceof \DOMElement) {
                    if ($target !== $node && self::accessibleReferencePathReturnsToNode(
                        $target,
                        $node,
                        $dom,
                        $ids,
                        $treeScope,
                        [],
                    )) {
                        if (!$hasDirectSelfReference) {
                            return self::accessibleReferenceFallbackText(
                                $node,
                                $dom,
                                $visited,
                                $directReference,
                                $ids,
                                $treeScope,
                                $titleSuppressedFor,
                                $followLabelledBy,
                            );
                        }
                        $visited[spl_object_id($target)] = true;
                        $text = self::accessibleReferenceFallbackText(
                            $target,
                            $dom,
                            $visited,
                            true,
                            $ids,
                            $treeScope,
                            $titleSuppressedFor,
                            false,
                        );
                        if (self::accessibleTextIsNotEmpty($text)) {
                            $parts[] = $text;
                        }
                        continue;
                    }
                    $text = $target === $node
                        ? self::accessibleReferenceFallbackText(
                            $node,
                            $dom,
                            $visited,
                            true,
                            $ids,
                            $treeScope,
                            $titleSuppressedFor,
                            $followLabelledBy,
                        )
                        : self::accessibleReferenceText(
                            $target,
                            $dom,
                            $visited,
                            true,
                            $ids,
                            $treeScope,
                            $titleSuppressedFor,
                            false,
                        );
                    if (self::accessibleTextIsNotEmpty($text)) {
                        $parts[] = $text;
                    }
                }
            }
            if ($parts !== []) {
                return implode(' ', $parts);
            }
        }
        return self::accessibleReferenceFallbackText(
            $node,
            $dom,
            $visited,
            $directReference,
            $ids,
            $treeScope,
            $titleSuppressedFor,
            $followLabelledBy,
        );
    }

    /**
     * @param array<int,true> $visited
     * @param array<string,array<string,\DOMElement>>|null $ids
     */
    private static function accessibleReferenceFallbackText(
        \DOMElement $node,
        \DOMDocument $dom,
        array &$visited,
        bool $directReference,
        ?array $ids,
        string $treeScope,
        ?\DOMElement $titleSuppressedFor,
        bool $followLabelledBy,
    ): string {
        $tag = strtolower($node->tagName);
        if ($node->hasAttribute('aria-label')
            && self::accessibleTextIsNotEmpty($node->getAttribute('aria-label'))
        ) {
            return $node->getAttribute('aria-label');
        }
        if (($tag === 'img' || $tag === 'area'
                || ($tag === 'input' && strtolower($node->getAttribute('type')) === 'image'))
            && ($directReference || !self::domPresentationalRoleApplies($node))
            && $node->hasAttribute('alt')
        ) {
            return $node->getAttribute('alt');
        }
        if ($tag === 'input'
            && in_array(strtolower($node->getAttribute('type')), ['button', 'reset', 'submit'], true)
            && $node->hasAttribute('value')
        ) {
            return $node->getAttribute('value');
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::accessibleReferenceText(
                $child,
                $dom,
                $visited,
                false,
                $ids,
                $treeScope,
                $titleSuppressedFor,
                $followLabelledBy,
            );
        }
        return self::accessibleTextIsNotEmpty($text)
            || !$node->hasAttribute('title')
            || $node === $titleSuppressedFor
            ? $text
            : $node->getAttribute('title');
    }

    /** @return list<string> */
    private static function splitAriaIdRefs(string $value): array
    {
        $value = trim($value, "\x09\x0A\x0C\x0D\x20");
        if ($value === '') {
            return [];
        }
        return preg_split('/[\x09\x0A\x0C\x0D\x20]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param array<string,array<string,\DOMElement>> $ids
     */
    private static function accessibleDescriptionText(
        \DOMElement $node,
        \DOMDocument $dom,
        array $ids,
        string $treeScope,
    ): string {
        $parts = [];
        $resolvedReference = false;
        foreach (self::splitAriaIdRefs($node->getAttribute('aria-describedby')) as $id) {
            $target = $ids[$treeScope][$id] ?? null;
            if (!$target instanceof \DOMElement) {
                continue;
            }
            $resolvedReference = true;
            $visited = [];
            $text = self::accessibleReferenceText(
                $target,
                $dom,
                $visited,
                true,
                $ids,
                $treeScope,
                null,
                false,
            );
            if (self::accessibleTextIsNotEmpty($text)) {
                $parts[] = $text;
            }
        }
        if ($resolvedReference) {
            return implode(' ', $parts);
        }
        $description = $node->getAttribute('aria-description');
        if (self::accessibleTextIsNotEmpty($description)) {
            return $description;
        }
        $title = $node->getAttribute('title');
        if (!self::accessibleTextIsNotEmpty($title)) {
            return '';
        }
        $visited = [];
        $nameWithoutOwnTitle = self::accessibleReferenceText(
            $node,
            $dom,
            $visited,
            true,
            $ids,
            $treeScope,
            $node,
        );
        return self::accessibleTextIsNotEmpty($nameWithoutOwnTitle) ? $title : '';
    }

    /**
     * @param array<string,array<string,\DOMElement>>|null $ids
     * @param array<int,true>                              $path
     */
    private static function accessibleReferencePathReturnsToNode(
        \DOMElement $current,
        \DOMElement $origin,
        \DOMDocument $dom,
        ?array $ids,
        string $treeScope,
        array $path,
    ): bool {
        $identity = spl_object_id($current);
        if (isset($path[$identity])) {
            return false;
        }
        $path[$identity] = true;
        foreach (self::splitAriaIdRefs($current->getAttribute('aria-labelledby')) as $id) {
            $target = $ids === null ? $dom->getElementById($id) : ($ids[$treeScope][$id] ?? null);
            if (!$target instanceof \DOMElement || $target === $current) {
                continue;
            }
            if ($target === $origin || self::accessibleReferencePathReturnsToNode(
                $target,
                $origin,
                $dom,
                $ids,
                $treeScope,
                $path,
            )) {
                return true;
            }
        }
        return false;
    }

    private static function accessibleTextIsNotEmpty(string $text): bool
    {
        return preg_match('/[^\x09\x0A\x0C\x0D\x20]/', $text) === 1;
    }

    /** @param array<string,array{name:string,valueStart:?int,valueEnd:?int}> $attributes */
    private static function isHtmlAccessibleAttribute(
        string $tagName,
        string $attribute,
        string $tag,
        array $attributes,
    ): bool {
        if ($tagName === 'area') {
            return false;
        }
        // ARIA-only values follow cross-attribute precedence in the DOM pass.
        // Native values remain visible independently (text field content,
        // placeholder, broken-image fallback, option labels, and tooltips).
        if ($attribute === 'aria-description') {
            return false;
        }
        $hasLabelledBy = isset($attributes['aria-labelledby']);
        if ($attribute === 'aria-label' && $hasLabelledBy) {
            return false;
        }
        $inputType = $tagName === 'input' ? self::htmlInputType($tag, $attributes) : '';
        if ($tagName === 'input' && $inputType === 'hidden') {
            return false;
        }
        if (in_array($attribute, [
                'aria-braillelabel', 'aria-brailleroledescription', 'aria-label',
                'aria-placeholder', 'aria-roledescription', 'aria-valuetext',
            ], true)
        ) {
            return true;
        }
        return match ($attribute) {
            'alt' => $tagName === 'img' || ($tagName === 'input' && $inputType === 'image'),
            'placeholder' => $tagName === 'textarea'
                || ($tagName === 'input'
                    && !in_array(
                        $inputType,
                        ['button', 'checkbox', 'color', 'file', 'hidden', 'image', 'radio', 'range', 'reset', 'submit'],
                        true,
                    )),
            'label' => in_array($tagName, ['optgroup', 'option', 'track'], true),
            'title' => true,
            'value' => $tagName === 'input'
                    && !in_array(
                        $inputType,
                        [
                            'checkbox', 'color', 'date', 'datetime-local', 'file', 'hidden', 'image', 'month',
                            'number', 'password', 'radio', 'range', 'time', 'week',
                        ],
                        true,
                    ),
            default => false,
        };
    }

    /** @param array<string,array{name:string,valueStart:?int,valueEnd:?int}> $attributes */
    private static function htmlPresentationalRoleApplies(
        string $tagName,
        string $tag,
        array $attributes,
    ): bool {
        if (!in_array(self::effectiveAriaRole(self::htmlAttributeValue($tag, $attributes, 'role')), [
                'none', 'presentation',
            ], true)
        ) {
            return false;
        }
        if ((isset($attributes['tabindex'])
                && self::browserParsesTabindex(self::htmlAttributeValue($tag, $attributes, 'tabindex')))
            || in_array($tagName, ['button', 'input', 'select', 'textarea'], true)
            || (in_array($tagName, ['a', 'area'], true) && isset($attributes['href']))
        ) {
            return false;
        }
        foreach (array_keys($attributes) as $name) {
            if (self::isGlobalAriaConflict($name)) {
                return false;
            }
        }
        return true;
    }

    private static function domPresentationalRoleApplies(\DOMElement $node): bool
    {
        if (!in_array(self::effectiveAriaRole($node->getAttribute('role')), ['none', 'presentation'], true)) {
            return false;
        }
        $tagName = strtolower($node->tagName);
        if (($node->hasAttribute('tabindex')
                && self::browserParsesTabindex($node->getAttribute('tabindex')))
            || in_array($tagName, ['button', 'input', 'select', 'textarea'], true)
            || (in_array($tagName, ['a', 'area'], true) && $node->hasAttribute('href'))
        ) {
            return false;
        }
        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            if (self::isGlobalAriaConflict($name)) {
                return false;
            }
        }
        return true;
    }

    private static function browserParsesTabindex(string $value): bool
    {
        if (preg_match('/^[\x09\x0A\x0C\x0D\x20]*([+-]?\d+)/', $value, $match) !== 1) {
            return false;
        }
        $number = (string) $match[1];
        $negative = str_starts_with($number, '-');
        $digits = ltrim($number, '+-0');
        if ($digits === '') {
            return true;
        }
        $limit = $negative ? '2147483648' : '2147483647';
        return strlen($digits) < strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) <= 0);
    }

    private static function isGlobalAriaConflict(string $name): bool
    {
        return in_array($name, [
            'aria-atomic', 'aria-braillelabel', 'aria-brailleroledescription', 'aria-busy',
            'aria-controls', 'aria-current', 'aria-describedby',
            'aria-description', 'aria-details', 'aria-disabled', 'aria-errormessage', 'aria-flowto', 'aria-haspopup',
            'aria-invalid', 'aria-keyshortcuts', 'aria-label', 'aria-labelledby', 'aria-live',
            'aria-owns', 'aria-relevant', 'aria-roledescription',
        ], true);
    }

    private static function effectiveAriaRole(string $value): string
    {
        $valid = [
            'alert', 'alertdialog', 'application', 'article', 'banner', 'blockquote', 'button', 'caption',
            'cell', 'checkbox', 'code', 'columnheader', 'combobox', 'complementary', 'contentinfo',
            'definition', 'deletion', 'dialog', 'directory', 'document', 'emphasis', 'feed', 'figure',
            'doc-abstract', 'doc-acknowledgments', 'doc-afterword', 'doc-appendix', 'doc-backlink',
            'doc-biblioentry', 'doc-bibliography', 'doc-biblioref', 'doc-chapter', 'doc-colophon',
            'doc-conclusion', 'doc-cover', 'doc-credit', 'doc-credits', 'doc-dedication', 'doc-endnote',
            'doc-endnotes', 'doc-epigraph', 'doc-epilogue', 'doc-errata', 'doc-example', 'doc-footnote',
            'doc-foreword', 'doc-glossary', 'doc-glossref', 'doc-index', 'doc-introduction', 'doc-noteref',
            'doc-notice', 'doc-pagebreak', 'doc-pagelist', 'doc-part', 'doc-preface', 'doc-prologue',
            'doc-pullquote', 'doc-qna', 'doc-subtitle', 'doc-tip', 'doc-toc', 'form', 'generic',
            'graphics-document', 'graphics-object', 'graphics-symbol', 'grid', 'gridcell', 'group', 'heading',
            'comment', 'image', 'img', 'insertion', 'link', 'list',
            'listbox', 'listitem', 'log', 'main', 'marquee', 'math', 'menu', 'menubar', 'menuitem',
            'mark', 'menuitemcheckbox', 'menuitemradio', 'meter', 'navigation', 'none', 'note', 'option', 'paragraph',
            'presentation', 'progressbar', 'radio', 'radiogroup', 'region', 'row', 'rowgroup', 'rowheader',
            'scrollbar', 'search', 'searchbox', 'separator', 'slider', 'spinbutton', 'status', 'strong',
            'subscript', 'superscript', 'switch', 'tab', 'table', 'tablist', 'tabpanel', 'term', 'textbox',
            'suggestion', 'time', 'timer', 'toolbar', 'tooltip', 'tree', 'treegrid', 'treeitem',
        ];
        foreach (preg_split('/[\x09\x0A\x0C\x0D\x20]+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY)
            ?: [] as $role
        ) {
            if (in_array($role, $valid, true)) {
                return $role;
            }
        }
        return '';
    }

    /**
     * @return list<array{type:string,authored:string,canonical:string}>
     */
    private static function selectedBlockAttributeCandidates(string $blockName, mixed $attrs): array
    {
        if (!is_array($attrs)) {
            return [];
        }
        $candidates = [];
        $walk = static function (mixed $value, string $key = '') use (&$walk, &$candidates): void {
            if (is_array($value)) {
                foreach ($value as $childKey => $child) {
                    $walk($child, (string) $childKey);
                }
                return;
            }
            $normalized = strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', '-', $key) ?? $key);
            if (!in_array($normalized, [
                    'alt',
                    'aria-label',
                    'button-text',
                    'caption',
                    'description',
                    'label',
                    'placeholder',
                    'title',
                ], true)
            ) {
                return;
            }
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (!is_string($value)) {
                return;
            }
            array_push($candidates, ...ContactFacts::visibleCandidates($value));
        };
        foreach ($attrs as $key => $value) {
            $normalized = strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', '-', (string) $key) ?? (string) $key);
            if (self::isDecodedDestinationAttribute($blockName, $normalized)) {
                if (is_string($value)) {
                    array_push($candidates, ...self::destinationCandidates(
                        $value,
                        true,
                        false,
                        in_array($normalized, ['imagesrcset', 'srcset'], true)
                            ? 'srcset'
                            : (in_array($normalized, ['attributionsrc', 'ping'], true)
                                ? 'space-urls'
                                : 'single'),
                    ));
                }
                continue;
            }
            if ($normalized !== 'metadata') {
                $walk($value, (string) $key);
            }
        }
        return self::dedupe($candidates);
    }

    private static function isDecodedDestinationAttribute(string $blockName, string $attribute): bool
    {
        if (in_array($attribute, [
            'action', 'attributionsrc', 'background', 'cite', 'data', 'formaction', 'href', 'imagesrcset', 'longdesc',
            'ping', 'poster', 'src', 'srcset', 'text-link-href', 'url',
        ], true)) {
            return true;
        }
        return ($blockName === 'rss' && $attribute === 'feed-url')
            || ($blockName === 'media-text' && $attribute === 'media-url');
    }

    /**
     * @param list<array{type:string,authored:string,canonical:string}> $candidates
     * @return list<array{type:string,authored:string,canonical:string}>
     */
    private static function dedupe(array $candidates): array
    {
        $deduped = [];
        foreach ($candidates as $candidate) {
            $deduped[$candidate['type'] . "\0" . $candidate['canonical']] = $candidate;
        }
        return array_values($deduped);
    }

    /** @param list<string> $authored */
    private static function warning(string $file, string $block, array $authored): string
    {
        return 'file=' . Warnings::value($file)
            . '; block=' . Warnings::value($block)
            . '; authored=' . Warnings::value($authored)
            . '; delivered=removed; disposition=removed the smallest complete generated block that published '
            . 'an email, street address, phone number, domain, or URL absent from siteSpec.json';
    }

    private static function blockPath(BlockMarkup $document, int $index): string
    {
        $segments = [];
        do {
            $name = $document->name($index);
            $parent = $document->parent($index);
            $siblings = $parent === null
                ? array_values(array_filter(
                    $document->indices(),
                    static fn (int $candidate): bool => $document->parent($candidate) === null,
                ))
                : $document->children($parent);
            $ordinal = 0;
            foreach ($siblings as $sibling) {
                if ($sibling === $index) {
                    break;
                }
                if ($document->name($sibling) === $name) {
                    $ordinal++;
                }
            }
            array_unshift($segments, "wp:{$name}[{$ordinal}]");
            $index = $parent;
        } while ($index !== null);

        return implode(' > ', $segments);
    }
}
