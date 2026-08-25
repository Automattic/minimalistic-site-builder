<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSyntaxScanner;

/**
 * Removes CSS dependencies that would make a generated theme fetch third-party
 * resources.
 *
 * This is a deliberately small lexical scrubber, not a CSS formatter. It
 * preserves every byte outside a removed @import statement or declaration.
 * Malformed boundaries are left unchanged because retaining the pre-transform
 * bytes is safer than guessing where generated CSS ends.
 */
final class CssScrub
{
    private const MAX_GENERATED_CONDITION_SCENARIOS = 512;
    private const MAX_GENERATED_CONDITION_SCOPES = 32;
    private const MAX_GENERATED_FALLBACK_CONDITION_SCOPES = 256;
    private const MAX_GENERATED_CONDITION_SCENARIO_SCOPE_WORK = 4096;
    private const MAX_GENERATED_MATH_NESTING = 64;

    /** CSS properties whose string values can become visible browser text. */
    private const GENERATED_TEXT_PROPERTIES = [
        'additive-symbols', 'content', 'list-style', 'list-style-type', 'negative',
        'pad', 'prefix', 'quotes', 'suffix', 'symbols',
    ];

    /** Built-in counter styles and non-style keywords valid in list-style declarations. */
    private const BUILT_IN_LIST_STYLE_KEYWORDS = [
        'arabic-indic', 'armenian', 'bengali', 'cambodian', 'circle', 'cjk-decimal',
        'cjk-earthly-branch', 'cjk-heavenly-stem', 'cjk-ideographic', 'decimal',
        'decimal-leading-zero', 'devanagari', 'disc', 'disclosure-closed', 'disclosure-open',
        'ethiopic-numeric', 'georgian', 'gujarati', 'gurmukhi', 'hebrew', 'hiragana',
        'hiragana-iroha', 'inherit', 'initial', 'inside', 'japanese-formal', 'japanese-informal',
        'kannada', 'katakana', 'katakana-iroha', 'khmer', 'korean-hangul-formal',
        'korean-hanja-formal', 'korean-hanja-informal', 'lao', 'lower-alpha', 'lower-armenian',
        'lower-greek', 'lower-latin', 'lower-roman', 'malayalam', 'mongolian', 'myanmar',
        'none', 'oriya', 'outside', 'persian', 'revert', 'revert-layer', 'simp-chinese-formal',
        'simp-chinese-informal', 'square', 'tamil', 'telugu', 'thai', 'tibetan',
        'trad-chinese-formal', 'trad-chinese-informal', 'unset', 'upper-alpha', 'upper-armenian',
        'upper-latin', 'upper-roman',
    ];

    /**
     * @return array{
     *     css:string,
     *     removals:list<array{
     *         kind:string,
     *         authored_value:string,
     *         delivered_value:string,
     *         disposition:string
     *     }>
     * }
     */
    public static function scrub(string $css): array
    {
        $length = strlen($css);
        if ($length === 0) {
            return ['css' => '', 'removals' => []];
        }

        /** @var array<string,array{start:int,end:int,kind:string}> $ranges */
        $ranges = [];
        /** @var list<array{function:string|null,closer:string}> $functionStack */
        $functionStack = [];

        for ($offset = 0; $offset < $length;) {
            if (self::startsComment($css, $offset)) {
                $offset = self::commentEnd($css, $offset);
                continue;
            }

            $byte = $css[$offset];
            if ($byte === '"' || $byte === "'") {
                $stringEnd = self::completeStringEnd($css, $offset);
                if ($stringEnd === null) {
                    $offset = self::stringEnd($css, $offset);
                    continue;
                }

                $function = $functionStack === []
                    ? null
                    : $functionStack[array_key_last($functionStack)]['function'];
                if (
                    $function !== null
                    && self::stringCanBeUrlIn($function)
                    && self::isExternalUrl(substr($css, $offset + 1, $stringEnd - $offset - 2))
                ) {
                    $declaration = self::declarationRange($css, $offset);
                    if ($declaration !== null) {
                        $key = "{$declaration['start']}:{$declaration['end']}";
                        $ranges[$key] = [
                            'start' => $declaration['start'],
                            'end'   => $declaration['end'],
                            'kind'  => 'external_url_declaration',
                            'selector' => self::selectorBeforeDeclaration($css, $declaration['start']),
                        ];
                    }
                }

                $offset = $stringEnd;
                continue;
            }

            $importIdentifierEnd = $byte === '@'
                ? self::importIdentifierEndAt($css, $offset)
                : null;
            if ($importIdentifierEnd !== null) {
                $end = self::atRuleStatementEnd($css, $importIdentifierEnd);
                if ($end !== null) {
                    $ranges["{$offset}:{$end}"] = [
                        'start' => $offset,
                        'end'   => $end,
                        'kind'  => 'import',
                        'selector' => '@import',
                    ];
                    $offset = $end;
                    continue;
                }
            }

            $url = self::urlAt($css, $offset);
            if ($url !== null) {
                if (self::isExternalUrl($url['value'])) {
                    $declaration = self::declarationRange($css, $offset);
                    if ($declaration !== null) {
                        $key = "{$declaration['start']}:{$declaration['end']}";
                        $ranges[$key] = [
                            'start' => $declaration['start'],
                            'end'   => $declaration['end'],
                            'kind'  => 'external_url_declaration',
                            'selector' => self::selectorBeforeDeclaration($css, $declaration['start']),
                        ];
                    }
                }

                $offset = $url['end'];
                continue;
            }

            $identifier = self::identifierAt($css, $offset);
            if ($identifier !== null) {
                $functionOpen = self::skipComments($css, $identifier['end']);
                if (
                    !self::startsHashOrAtKeyword($css, $offset)
                    && $functionOpen < $length
                    && $css[$functionOpen] === '('
                ) {
                    $functionStack[] = [
                        'function' => $identifier['value'],
                        'closer'   => ')',
                    ];
                    $offset = $functionOpen + 1;
                    continue;
                }
                $offset = $identifier['end'];
                continue;
            }

            if ($byte === '{' || $byte === '}') {
                $functionStack = [];
            } elseif ($byte === '(' || $byte === '[') {
                $functionStack[] = [
                    'function' => null,
                    'closer'   => $byte === '(' ? ')' : ']',
                ];
            } elseif (($byte === ')' || $byte === ']') && $functionStack !== []) {
                $context = $functionStack[array_key_last($functionStack)];
                if ($context['closer'] === $byte) {
                    array_pop($functionStack);
                } else {
                    $functionStack = [];
                }
            }
            $offset++;
        }

        if ($ranges === []) {
            return ['css' => $css, 'removals' => []];
        }

        $ordered = array_values($ranges);
        usort(
            $ordered,
            static fn (array $left, array $right): int =>
                [$left['start'], $left['end']] <=> [$right['start'], $right['end']]
        );

        $removals = [];
        foreach ($ordered as $range) {
            $authored = substr($css, $range['start'], $range['end'] - $range['start']);
            $removals[] = [
                'kind'            => $range['kind'],
                'authored_value'  => $authored,
                'delivered_value' => 'removed',
                'disposition'     => $range['kind'] === 'import'
                    ? 'removed_import'
                    : 'removed_external_url',
                'selector' => $range['selector'],
            ];
        }

        for ($index = count($ordered) - 1; $index >= 0; $index--) {
            $range = $ordered[$index];
            $css = substr($css, 0, $range['start']) . substr($css, $range['end']);
        }

        return ['css' => $css, 'removals' => $removals];
    }

    /**
     * Extract resource strings with the same token boundaries used by scrub().
     *
     * @return list<string>
     */
    public static function resourceUrls(string $css): array
    {
        $length = strlen($css);
        $urls = [];
        /** @var list<array{function:string|null,closer:string}> $functionStack */
        $functionStack = [];

        for ($offset = 0; $offset < $length;) {
            if (self::startsComment($css, $offset)) {
                $offset = self::commentEnd($css, $offset);
                continue;
            }

            $byte = $css[$offset];
            if ($byte === '"' || $byte === "'") {
                $stringEnd = self::completeStringEnd($css, $offset);
                if ($stringEnd === null) {
                    $offset = self::stringEnd($css, $offset);
                    continue;
                }
                $function = $functionStack === []
                    ? null
                    : $functionStack[array_key_last($functionStack)]['function'];
                if ($function !== null && self::stringCanBeUrlIn($function)) {
                    $decoded = self::decodeCssEscapes(substr($css, $offset + 1, $stringEnd - $offset - 2));
                    if ($decoded !== null) {
                        $urls[] = $decoded;
                    }
                }
                $offset = $stringEnd;
                continue;
            }

            $url = self::urlAt($css, $offset);
            if ($url !== null) {
                $decoded = self::decodeCssEscapes($url['value']);
                if ($decoded !== null) {
                    $urls[] = $decoded;
                }
                $offset = $url['end'];
                continue;
            }

            $identifier = self::identifierAt($css, $offset);
            if ($identifier !== null) {
                $functionOpen = self::skipComments($css, $identifier['end']);
                if (!self::startsHashOrAtKeyword($css, $offset)
                    && $functionOpen < $length
                    && $css[$functionOpen] === '('
                ) {
                    $functionStack[] = ['function' => $identifier['value'], 'closer' => ')'];
                    $offset = $functionOpen + 1;
                    continue;
                }
                $offset = $identifier['end'];
                continue;
            }

            if ($byte === '{' || $byte === '}') {
                $functionStack = [];
            } elseif ($byte === '(' || $byte === '[') {
                $functionStack[] = ['function' => null, 'closer' => $byte === '(' ? ')' : ']'];
            } elseif (($byte === ')' || $byte === ']') && $functionStack !== []) {
                $context = $functionStack[array_key_last($functionStack)];
                if ($context['closer'] === $byte) {
                    array_pop($functionStack);
                } else {
                    $functionStack = [];
                }
            }
            $offset++;
        }

        return array_values(array_unique($urls));
    }

    /**
     * Remove content declarations that would render an ungrounded contact fact.
     *
     * @param array<string,array<string,true>> $allowed
     * @return array{
     *     css:string,
     *     removals:list<array{kind:string,authored_value:string,delivered_value:string,disposition:string}>
     * }
     */
    public static function scrubContactContent(string $css, array $allowed, string $markup = ''): array
    {
        /** @var array<string,array{start:int,end:int}> $ranges */
        $ranges = [];
        $contentDeclarations = [];
        $layerModel = self::generatedLayerModel($css);
        foreach (CssChecks::scanDeclarations($css) as $declaration) {
            $property = strtolower($declaration['property']);
            if (!in_array($property, self::GENERATED_TEXT_PROPERTIES, true)) {
                continue;
            }
            $rendered = self::contentText($css, $declaration['start'], $declaration['end']);
            $subjects = $property === 'content' ? self::generatedContentSubjects($declaration) : [];
            if ($property === 'content') {
                foreach ($subjects as $subject) {
                    if ($subject['pseudo'] === 'element') {
                        continue;
                    }
                    $contentDeclarations[] = [
                        'start' => $declaration['start'],
                        'end' => $declaration['end'],
                        'rendered' => $rendered,
                        'selector' => $subject['selector'],
                        'scope' => $subject['scope'],
                        'layer' => self::generatedLayerForOffset($layerModel, $declaration['start']),
                        'pseudo' => $subject['pseudo'],
                        'important' => preg_match('/!important\s*$/iu', $declaration['value']) === 1,
                        'condition' => self::generatedSubjectCondition($subject),
                    ];
                }
            }
            if ($property === 'content'
                && array_filter($subjects, static fn (array $subject): bool => $subject['pseudo'] !== 'element') === []
            ) {
                continue;
            }
            $candidates = self::generatedContactCandidates($property, $rendered);
            if ($property === 'content'
                && $markup !== ''
                && self::generatedNumericFragmentIsNamedByMarkup(
                    $rendered,
                    $declaration,
                    $markup,
                )
            ) {
                $candidates = [];
            }
            if (self::hasUnresolvedGeneratedTextValue($property, $declaration['value'])
                || ContactFacts::ungroundedAgainstSet(
                $candidates,
                $allowed,
            ) !== []) {
                $ranges[$declaration['start'] . ':' . $declaration['end']] = [
                    'start' => $declaration['start'],
                    'end' => $declaration['end'],
                    'selector' => $declaration['context'],
                ];
            }
        }
        $selectedCompositionRanges = [];
        $baselineMarkupWarnings = $markup === '' ? 0 : self::markupWarningCountAgainstAllowed($markup, $allowed);
        foreach (self::generatedContentCompositions($contentDeclarations, $markup, $css) as $composition) {
            $harmful = ($composition['fail_closed'] ?? false) || ContactFacts::ungroundedAgainstSet(
                ContactFacts::visibleCandidates($composition['rendered']),
                $allowed,
            ) !== [];
            if (!$harmful && isset($composition['markup'])
                && ($composition['grounding_fallback'] ?? false)
            ) {
                $harmful = self::markupWarningCountAgainstAllowed($composition['markup'], $allowed)
                    > $baselineMarkupWarnings;
            }
            if (!$harmful) {
                continue;
            }
            $alreadyIsolated = false;
            foreach ($composition['participants'] as $declaration) {
                if (isset($selectedCompositionRanges[$declaration['start'] . ':' . $declaration['end']])) {
                    $alreadyIsolated = true;
                    break;
                }
            }
            if ($alreadyIsolated) {
                continue;
            }
            $declaration = $composition['participants'][array_key_last($composition['participants'])];
            $key = $declaration['start'] . ':' . $declaration['end'];
            $selectedCompositionRanges[$key] = true;
            $ranges[$key] = [
                'start' => $declaration['start'],
                'end' => $declaration['end'],
                'selector' => $declaration['selector'],
            ];
        }

        if ($ranges === []) {
            return ['css' => $css, 'removals' => []];
        }
        $ordered = array_values($ranges);
        usort(
            $ordered,
            static fn (array $left, array $right): int =>
                [$left['start'], $left['end']] <=> [$right['start'], $right['end']],
        );
        $removals = [];
        foreach ($ordered as $range) {
            $removals[] = [
                'kind' => 'ungrounded_contact_content',
                'authored_value' => substr($css, $range['start'], $range['end'] - $range['start']),
                'delivered_value' => 'removed',
                'disposition' => 'removed_ungrounded_contact',
                'selector' => $range['selector'],
            ];
        }
        $segments = [];
        $cursor = 0;
        foreach ($ordered as $range) {
            $segments[] = substr($css, $cursor, $range['start'] - $cursor);
            $cursor = $range['end'];
        }
        $segments[] = substr($css, $cursor);
        $css = implode('', $segments);
        return ['css' => $css, 'removals' => $removals];
    }

    /**
     * Remove only hiding declarations whose delivered cascade makes an
     * otherwise grounded form value publish an invented contact fact.
     *
     * @param array<mixed> $siteSpec
     * @return array{css:string,removals:list<array{kind:string,authored_value:string,delivered_value:string,disposition:string}>}
     */
    public static function scrubContactHiding(
        string $css,
        string $markup,
        array $siteSpec,
    ): array {
        if ($markup === '' || $css === '') {
            return ['css' => $css, 'removals' => []];
        }
        $candidates = [];
        foreach (CssChecks::scanDeclarations($css) as $declaration) {
            $property = strtolower($declaration['property']);
            $value = strtolower(trim(preg_replace('/\s*!important\s*$/iu', '', $declaration['value'])
                ?? $declaration['value']));
            if (($property === 'display' && ($value === 'none' || str_contains($value, 'var(')))
                || ($property === 'visibility'
                    && (in_array($value, ['collapse', 'hidden'], true) || str_contains($value, 'var(')))
            ) {
                $candidates[$declaration['start'] . ':' . $declaration['end']] = [
                    'start' => $declaration['start'],
                    'end' => $declaration['end'],
                    'selector' => $declaration['context'],
                ];
            }
        }
        if ($candidates === []) {
            return ['css' => $css, 'removals' => []];
        }

        $baseline = self::contactHidingWarningCount($markup, '', $siteSpec);
        if (self::contactHidingWarningCount($markup, $css, $siteSpec) <= $baseline) {
            return ['css' => $css, 'removals' => []];
        }
        $removing = $candidates;
        $withoutAll = self::withoutDeclarationRanges($css, array_values($removing));
        if (self::contactHidingWarningCount($markup, $withoutAll, $siteSpec) > $baseline) {
            return ['css' => $css, 'removals' => []];
        }

        // Re-add every declaration that is not required for safety. What
        // remains is a deterministic locally minimal removal set, including
        // duplicate declarations that would otherwise shelter one another.
        foreach (array_keys($candidates) as $key) {
            $trial = $removing;
            unset($trial[$key]);
            if (self::contactHidingWarningCount(
                $markup,
                self::withoutDeclarationRanges($css, array_values($trial)),
                $siteSpec,
            ) <= $baseline) {
                $removing = $trial;
            }
        }
        $ordered = array_values($removing);
        usort($ordered, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        $removals = array_map(
            static fn (array $range): array => [
                'kind' => 'ungrounded_contact_hiding',
                'authored_value' => substr($css, $range['start'], $range['end'] - $range['start']),
                'delivered_value' => 'removed',
                'disposition' => 'removed_ungrounded_contact_hiding',
                'selector' => $range['selector'],
            ],
            $ordered,
        );
        return [
            'css' => self::withoutDeclarationRanges($css, $ordered),
            'removals' => $removals,
        ];
    }

    /** @param array<mixed> $siteSpec */
    private static function contactHidingWarningCount(string $markup, string $css, array $siteSpec): int
    {
        $warnings = [];
        $style = $css === '' ? '' : '<!-- wp:html --><style>' . $css . '</style><!-- /wp:html -->';
        GroundedContactMarkup::scrub($markup . $style, $siteSpec, '<delivered-markup>', $warnings);
        return count($warnings);
    }

    /** @param list<array{start:int,end:int}> $ranges */
    private static function withoutDeclarationRanges(string $css, array $ranges): string
    {
        usort($ranges, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        for ($index = count($ranges) - 1; $index >= 0; $index--) {
            $range = $ranges[$index];
            $css = substr($css, 0, $range['start']) . substr($css, $range['end']);
        }
        return $css;
    }

    /**
     * Remove resource declarations whose var()/attr() value cannot be
     * inspected at this delivery boundary.
     *
     * @return array{css:string,removals:list<array{kind:string,authored_value:string,delivered_value:string,disposition:string}>}
     */
    public static function scrubResourceIndirection(string $css): array
    {
        [$repaired, $dropped] = CssChecks::dropDeclarations(
            $css,
            static function (array $declaration): bool {
                if ($declaration['kind'] !== 'style'
                    || !self::hasUnresolvedResourceValue(
                        strtolower($declaration['property']),
                        $declaration['value'],
                    )
                ) {
                    return false;
                }
                return true;
            },
        );
        return [
            'css' => $repaired,
            'removals' => array_map(
                static fn (array $declaration): array => [
                    'kind' => 'unresolved_resource_indirection',
                    'authored_value' => $declaration['raw'],
                    'delivered_value' => 'removed',
                    'disposition' => 'removed_uninspectable_resource',
                ],
                $dropped,
            ),
        ];
    }

    public static function hasResourceIndirection(string $css, bool $bareDeclarationList = false): bool
    {
        if ($bareDeclarationList) {
            $css = ':root{' . $css . '}';
        }
        foreach (CssChecks::scanDeclarations($css) as $declaration) {
            if ($declaration['kind'] === 'style'
                && self::hasUnresolvedResourceValue(
                    strtolower($declaration['property']),
                    $declaration['value'],
                )
            ) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{type:string,authored:string,canonical:string}> */
    public static function generatedTextCandidates(
        string $css,
        bool $bareDeclarationList = false,
        bool $includeElementContent = true,
        string $markup = '',
    ): array
    {
        if ($bareDeclarationList) {
            $css = ':root{' . $css . '}';
        }
        $candidates = [];
        $contentDeclarations = [];
        $layerModel = self::generatedLayerModel($css);
        foreach (CssChecks::scanDeclarations($css) as $declaration) {
            $property = strtolower($declaration['property']);
            if (!in_array($property, self::GENERATED_TEXT_PROPERTIES, true)
                || (!$includeElementContent && $property === 'content')
            ) {
                continue;
            }
            if (self::hasUnresolvedGeneratedTextValue($property, $declaration['value'])) {
                $candidates[] = [
                    'type' => 'generated_text',
                    'authored' => $declaration['raw'],
                    'canonical' => 'unresolved:' . hash('sha256', $declaration['raw']),
                ];
            }
            $rendered = self::contentText($css, $declaration['start'], $declaration['end']);
            $subjects = $property === 'content' ? self::generatedContentSubjects($declaration) : [];
            if ($property === 'content') {
                foreach ($subjects as $subject) {
                    if ($subject['pseudo'] === 'element') {
                        continue;
                    }
                    $contentDeclarations[] = [
                        'start' => $declaration['start'],
                        'end' => $declaration['end'],
                        'rendered' => $rendered,
                        'selector' => $subject['selector'],
                        'scope' => $subject['scope'],
                        'layer' => self::generatedLayerForOffset($layerModel, $declaration['start']),
                        'pseudo' => $subject['pseudo'],
                        'important' => preg_match('/!important\s*$/iu', $declaration['value']) === 1,
                        'condition' => self::generatedSubjectCondition($subject),
                    ];
                }
            }
            if ($property === 'content'
                && array_filter($subjects, static fn (array $subject): bool => $subject['pseudo'] !== 'element') === []
            ) {
                continue;
            }
            $generatedCandidates = self::generatedContactCandidates($property, $rendered);
            if ($property === 'content' && $markup !== ''
                && self::generatedNumericFragmentIsNamedByMarkup($rendered, $declaration, $markup)
            ) {
                $generatedCandidates = [];
            }
            array_push($candidates, ...$generatedCandidates);
        }
        $baselineMarkupWarnings = $markup === '' ? 0 : self::markupWarningCountAgainstAllowed($markup, []);
        foreach (self::generatedContentCompositions($contentDeclarations, $markup, $css) as $composition) {
            if ($composition['fail_closed'] ?? false) {
                $candidates[] = [
                    'type' => 'generated_text',
                    'authored' => $composition['rendered'],
                    'canonical' => 'condition-overflow:' . hash(
                        'sha256',
                        implode(':', array_column($composition['participants'], 'start')),
                    ),
                ];
                continue;
            }
            $composed = ContactFacts::visibleCandidates($composition['rendered']);
            array_push($candidates, ...$composed);
            if ($composed === [] && isset($composition['markup'])
                && ($composition['grounding_fallback'] ?? false)
                && self::markupWarningCountAgainstAllowed($composition['markup'], []) > $baselineMarkupWarnings
            ) {
                $candidates[] = [
                    'type' => 'generated_text',
                    'authored' => $composition['rendered'],
                    'canonical' => 'markup-composition:' . hash('sha256', $composition['markup']),
                ];
            }
        }
        $deduped = [];
        foreach ($candidates as $candidate) {
            $deduped[$candidate['type'] . "\0" . $candidate['canonical']] = $candidate;
        }
        return array_values($deduped);
    }

    /** @return list<array{type:string,authored:string,canonical:string}> */
    private static function generatedContactCandidates(string $property, string $rendered): array
    {
        $candidates = ContactFacts::visibleCandidates($rendered);
        if ($candidates !== [] || $property !== 'content') {
            return $candidates;
        }
        $identifierLabel = null;
        if (preg_match(
            '/^\s*(?<label>[\p{L}\p{M}][\p{L}\p{M}\s_-]{0,40})\s+[#:]?\s*'
                . '(?:\p{N}[\s.-]*){6,}\s*$/u',
            $rendered,
            $identifier,
        ) === 1 || preg_match(
            '/^\s*(?:\p{N}[\s.-]*){6,}\s+(?<label>[\p{L}\p{M}][\p{L}\p{M}\s_-]{0,40})\s*$/u',
            $rendered,
            $identifier,
        ) === 1) {
            $identifierLabel = (string) $identifier['label'];
        }
        if ($identifierLabel !== null && preg_match(
            '/\b(?:call|contact|dial|fax|hotline|mobile|phone|reach|reservations?|ring|sms|tel(?:ephone)?|text|whatsapp)\b/iu',
            $identifierLabel,
        ) !== 1) {
            return [];
        }
        $digitCount = preg_match_all('/\p{N}/u', $rendered);
        if (!is_int($digitCount) || $digitCount < 6) {
            return [];
        }
        return [[
            'type' => 'generated_text',
            'authored' => $rendered,
            'canonical' => 'contact-fragment:' . hash('sha256', $rendered),
        ]];
    }

    /**
     * Browser generated text composes only across pseudo-elements that can
     * select the same subject. Scope stays metadata for cascade deduplication;
     * active and potentially-active scopes may overlap at runtime.
     *
     * @param array{context:string,ancestors:list<string>} $declaration
     * @return list<array{selector:string,scope:string,layer:?string,pseudo:'marker'|'before'|'element'|'after'}>
     */
    private static function generatedContentSubjects(array $declaration): array
    {
        if ($declaration['context'] === '<declaration-list>') {
            return [[
                'selector' => ':root',
                'scope' => '<declaration-list>',
                'layer' => null,
                'pseudo' => 'element',
            ]];
        }
        $scope = [];
        $layers = [];
        $outerSelectors = [];
        foreach ($declaration['ancestors'] as $ancestor) {
            if (preg_match('/^\s*@media\b(.*)$/isu', $ancestor, $media) === 1
                && GroundedContactMarkup::cssMediaIsInactive((string) $media[1])
            ) {
                return [];
            }
            if (str_starts_with(ltrim($ancestor), '@')) {
                if (preg_match('/^\s*@layer\s+([^\s{]+)/iu', $ancestor, $layer) === 1) {
                    $layers[] = trim((string) $layer[1]);
                } elseif (preg_match('/^\s*@layer\b/iu', $ancestor) !== 1) {
                    $scope[] = trim($ancestor);
                }
            } else {
                $outerSelectors[] = trim($ancestor);
            }
        }
        $subjects = [];
        foreach (\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel(
            $declaration['context'],
            [','],
        ) as $selector) {
            $selector = trim($selector);
            if ($selector === '') {
                continue;
            }
            $pseudo = 'element';
            if (preg_match('/::marker\s*$/iu', $selector) === 1) {
                $pseudo = 'marker';
                $selector = preg_replace('/::marker\s*$/iu', '', $selector) ?? $selector;
            } elseif (preg_match('/::?before\s*$/iu', $selector) === 1) {
                $pseudo = 'before';
                $selector = preg_replace('/::?before\s*$/iu', '', $selector) ?? $selector;
            } elseif (preg_match('/::?after\s*$/iu', $selector) === 1) {
                $pseudo = 'after';
                $selector = preg_replace('/::?after\s*$/iu', '', $selector) ?? $selector;
            }
            for ($index = count($outerSelectors) - 1; $index >= 0; $index--) {
                $outer = $outerSelectors[$index];
                $selector = str_contains($selector, '&')
                    ? str_replace('&', $outer, $selector)
                    : $outer . ' ' . $selector;
            }
            $subjects[] = [
                'selector' => trim($selector),
                'scope' => implode("\0", $scope),
                'layer' => $layers === [] ? null : implode('.', $layers),
                'pseudo' => $pseudo,
            ];
        }
        return $subjects;
    }

    /** @param array{selector:string,scope:string,pseudo:string} $subject */
    private static function generatedSubjectCondition(
        array $subject,
        ?\Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment $fragment = null,
    ): string {
        return implode("\0", array_values(array_filter(
            [
                $subject['scope'],
                GroundedContactMarkup::cssSelectorStateScope($subject['selector'], $fragment),
            ],
            static fn (string $condition): bool => $condition !== '',
        )));
    }

    /**
     * Read real layer at-rules in source order. Strings, comments, functions,
     * and custom-property blocks are opaque; anonymous and nested layers get
     * stable internal names so declarations retain their actual cascade scope.
     *
     * @return array{
     *     order:array<string,int>,
     *     intervals:list<array{start:int,end:int,name:string}>
     * }
     */
    public static function cascadeLayerModel(string $css): array
    {
        return self::generatedLayerModel($css);
    }

    private static function generatedLayerModel(string $css): array
    {
        $children = [];
        $intervals = [];
        $anonymous = 0;
        self::generatedScanLayerRegion(
            $css,
            0,
            strlen($css),
            null,
            $children,
            $intervals,
            $anonymous,
        );
        $order = [];
        foreach ($children[''] ?? [] as $layer) {
            self::generatedFlattenLayer($layer, $children, $order);
        }
        return compact('order', 'intervals');
    }

    /**
     * @param array<string,list<string>> $children
     * @param list<array{start:int,end:int,name:string}> $intervals
     */
    private static function generatedScanLayerRegion(
        string $css,
        int $start,
        int $end,
        ?string $parent,
        array &$children,
        array &$intervals,
        int &$anonymous,
    ): void {
        $itemStart = true;
        $segmentStart = $start;
        for ($index = $start; $index < $end;) {
            $byte = $css[$index];
            if (ctype_space($byte)) {
                $index++;
                continue;
            }
            if ($byte === '/' && ($css[$index + 1] ?? '') === '*') {
                $index = self::generatedCssSkipComment($css, $index, $end);
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $itemStart = false;
                $index = self::generatedCssSkipQuoted($css, $index, $end, $byte);
                continue;
            }
            if ($byte === '\\') {
                $itemStart = false;
                $index = min($end, $index + 2);
                continue;
            }
            if ($byte === '(' || $byte === '[') {
                $itemStart = false;
                $index = self::generatedCssSkipDelimited($css, $index, $end);
                continue;
            }
            if ($itemStart && $byte === '@') {
                $nameEnd = self::generatedCssIdentifierEnd($css, $index + 1, $end);
                $atName = strtolower(CssChecks::decodeIdentifier(
                    substr($css, $index + 1, $nameEnd - $index - 1),
                ));
                if ($atName === 'layer') {
                    $delimiter = self::generatedLayerPreludeEnd($css, $nameEnd, $end);
                    if ($delimiter === null) {
                        return;
                    }
                    $prelude = substr($css, $nameEnd, $delimiter - $nameEnd);
                    $paths = self::generatedLayerPaths($prelude);
                    if ($css[$delimiter] === ';') {
                        foreach ($paths as $path) {
                            self::generatedRecordLayerPath($path, $parent, $children);
                        }
                        $index = $delimiter + 1;
                        $segmentStart = $index;
                        $itemStart = true;
                        continue;
                    }
                    $close = self::generatedCssMatchingBrace($css, $delimiter, $end);
                    if ($close === null) {
                        return;
                    }
                    $name = count($paths) === 1
                        ? self::generatedRecordLayerPath($paths[0], $parent, $children)
                        : self::generatedRecordLayerPath(
                            ['#anonymous-layer-' . $anonymous++],
                            $parent,
                            $children,
                        );
                    $intervals[] = [
                        'start' => $delimiter + 1,
                        'end' => $close,
                        'name' => $name,
                    ];
                    self::generatedScanLayerRegion(
                        $css,
                        $delimiter + 1,
                        $close,
                        $name,
                        $children,
                        $intervals,
                        $anonymous,
                    );
                    $index = $close + 1;
                    $segmentStart = $index;
                    $itemStart = true;
                    continue;
                }
            }
            if ($byte === ';') {
                $index++;
                $segmentStart = $index;
                $itemStart = true;
                continue;
            }
            if ($byte === '{') {
                $close = self::generatedCssMatchingBrace($css, $index, $end);
                if ($close === null) {
                    return;
                }
                $prefix = preg_replace(
                    '~/\*.*?\*/~su',
                    ' ',
                    substr($css, $segmentStart, $index - $segmentStart),
                ) ?? '';
                if (preg_match('/^\s*--[^:;{}]+\s*:/u', $prefix) !== 1) {
                    self::generatedScanLayerRegion(
                        $css,
                        $index + 1,
                        $close,
                        $parent,
                        $children,
                        $intervals,
                        $anonymous,
                    );
                }
                $index = $close + 1;
                $segmentStart = $index;
                $itemStart = true;
                continue;
            }
            $itemStart = false;
            $index++;
        }
    }

    private static function generatedCssIdentifierEnd(string $css, int $start, int $end): int
    {
        $index = $start;
        while ($index < $end) {
            if ($css[$index] === '\\') {
                $index++;
                $hex = 0;
                while ($index < $end && $hex < 6 && ctype_xdigit($css[$index])) {
                    $index++;
                    $hex++;
                }
                if ($hex > 0 && $index < $end && ctype_space($css[$index])) {
                    $index++;
                } elseif ($hex === 0 && $index < $end) {
                    $index++;
                }
                continue;
            }
            if (preg_match('/[-_a-zA-Z0-9]/', $css[$index]) !== 1) {
                break;
            }
            $index++;
        }
        return $index;
    }

    private static function generatedLayerPreludeEnd(string $css, int $start, int $end): ?int
    {
        for ($index = $start; $index < $end;) {
            $byte = $css[$index];
            if ($byte === '/' && ($css[$index + 1] ?? '') === '*') {
                $index = self::generatedCssSkipComment($css, $index, $end);
            } elseif ($byte === '"' || $byte === "'") {
                $index = self::generatedCssSkipQuoted($css, $index, $end, $byte);
            } elseif ($byte === '\\') {
                $index = min($end, $index + 2);
            } elseif ($byte === '(' || $byte === '[') {
                $index = self::generatedCssSkipDelimited($css, $index, $end);
            } elseif ($byte === ';' || $byte === '{') {
                return $index;
            } else {
                $index++;
            }
        }
        return null;
    }

    /** @return list<list<string>> */
    private static function generatedLayerPaths(string $prelude): array
    {
        $prelude = preg_replace('~/\*.*?\*/~su', ' ', $prelude) ?? $prelude;
        $paths = [];
        foreach (
            \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel(
                $prelude,
                [','],
            ) as $rawPath
        ) {
            $segments = [];
            $segmentStart = 0;
            $length = strlen($rawPath);
            for ($index = 0; $index <= $length; $index++) {
                if ($index < $length && $rawPath[$index] === '\\') {
                    $index = self::generatedCssIdentifierEnd($rawPath, $index, $length) - 1;
                    continue;
                }
                if ($index < $length && $rawPath[$index] !== '.') {
                    continue;
                }
                $segment = CssChecks::decodeIdentifier(trim(substr(
                    $rawPath,
                    $segmentStart,
                    $index - $segmentStart,
                )));
                if ($segment === '' || preg_match('/[\s,;{}()]/u', $segment) === 1) {
                    $segments = [];
                    break;
                }
                $segments[] = $segment;
                $segmentStart = $index + 1;
            }
            if ($segments !== []) {
                $paths[] = $segments;
            }
        }
        return $paths;
    }

    /** @param list<string> $path @param array<string,list<string>> $children */
    private static function generatedRecordLayerPath(
        array $path,
        ?string $parent,
        array &$children,
    ): string {
        $id = $parent;
        foreach ($path as $segment) {
            $parentKey = $id ?? '';
            $child = $id === null ? $segment : $id . "\0" . $segment;
            $children[$parentKey] ??= [];
            if (!in_array($child, $children[$parentKey], true)) {
                $children[$parentKey][] = $child;
            }
            $id = $child;
        }
        return $id ?? '#invalid-layer';
    }

    /** @param array<string,list<string>> $children @param array<string,int> $order */
    private static function generatedFlattenLayer(string $layer, array $children, array &$order): void
    {
        foreach ($children[$layer] ?? [] as $child) {
            self::generatedFlattenLayer($child, $children, $order);
        }
        $order[$layer] = count($order);
    }

    private static function generatedCssSkipComment(string $css, int $start, int $end): int
    {
        $close = strpos($css, '*/', $start + 2);
        return $close === false || $close >= $end ? $end : $close + 2;
    }

    private static function generatedCssSkipQuoted(
        string $css,
        int $start,
        int $end,
        string $delimiter,
    ): int {
        for ($index = $start + 1; $index < $end;) {
            if ($css[$index] === '\\') {
                $index = min(
                    $end,
                    $index + (($css[$index + 1] ?? '') === "\r"
                        && ($css[$index + 2] ?? '') === "\n" ? 3 : 2),
                );
            } elseif ($css[$index] === $delimiter) {
                return $index + 1;
            } elseif (str_contains("\r\n\f", $css[$index])) {
                return $index;
            } else {
                $index++;
            }
        }
        return $end;
    }

    private static function generatedCssSkipDelimited(string $css, int $start, int $end): int
    {
        $stack = [match ($css[$start]) {
            '(' => ')',
            '[' => ']',
            default => '}',
        }];
        for ($index = $start + 1; $index < $end;) {
            $byte = $css[$index];
            if ($byte === '/' && ($css[$index + 1] ?? '') === '*') {
                $index = self::generatedCssSkipComment($css, $index, $end);
            } elseif ($byte === '"' || $byte === "'") {
                $index = self::generatedCssSkipQuoted($css, $index, $end, $byte);
            } elseif ($byte === '\\') {
                $index = min($end, $index + 2);
            } elseif ($byte === '(' || $byte === '[' || $byte === '{') {
                $stack[] = match ($byte) {
                    '(' => ')',
                    '[' => ']',
                    default => '}',
                };
                $index++;
            } elseif ($byte === $stack[count($stack) - 1]) {
                array_pop($stack);
                $index++;
                if ($stack === []) {
                    return $index;
                }
            } else {
                $index++;
            }
        }
        return $end;
    }

    private static function generatedCssMatchingBrace(string $css, int $open, int $end): ?int
    {
        $after = self::generatedCssSkipDelimited($css, $open, $end);
        return $after <= $open + 1 || $after > $end || ($css[$after - 1] ?? '') !== '}'
            ? null
            : $after - 1;
    }

    /**
     * @param array{intervals:list<array{start:int,end:int,name:string}>} $model
     */
    private static function generatedLayerForOffset(array $model, int $offset): ?string
    {
        $match = null;
        $matchSize = PHP_INT_MAX;
        foreach ($model['intervals'] as $interval) {
            $size = $interval['end'] - $interval['start'];
            if ($offset >= $interval['start'] && $offset < $interval['end'] && $size < $matchSize) {
                $match = $interval['name'];
                $matchSize = $size;
            }
        }
        return $match;
    }

    /** @param array{intervals:list<array{start:int,end:int,name:string}>} $model */
    public static function cascadeLayerForOffset(array $model, int $offset): ?string
    {
        return self::generatedLayerForOffset($model, $offset);
    }

    /** @return array<string,int> */
    private static function generatedLayerOrder(string $css): array
    {
        return self::generatedLayerModel($css)['order'];
    }

    /** @param array{layer?:?string,important:bool,inline?:bool} $declaration @param array<string,int> $layerOrder */
    private static function generatedLayerCascadeRank(array $declaration, array $layerOrder): int
    {
        $layer = $declaration['layer'] ?? null;
        $count = count($layerOrder);
        if (($declaration['inline'] ?? false) === true) {
            return $count + 2;
        }
        if ($layer === null) {
            return $declaration['important'] ? 0 : $count + 1;
        }
        $index = $layerOrder[$layer] ?? $count;
        return $declaration['important']
            ? $count - $index + 1
            : $index + 1;
    }

    /** @param array{layer?:?string,important:bool,inline?:bool} $declaration @param array<string,int> $layerOrder */
    public static function cascadeLayerRank(array $declaration, array $layerOrder): int
    {
        return self::generatedLayerCascadeRank($declaration, $layerOrder);
    }

    /**
     * @param list<array{start:int,end:int,rendered:string,selector:string,scope:string,pseudo:string,important:bool}> $declarations
     * @return list<array{rendered:string,participants:list<array<string,mixed>>,markup?:string,fail_closed?:bool}>
     */
    private static function generatedContentCompositions(
        array $declarations,
        string $markup = '',
        string $css = '',
    ): array
    {
        $layerOrder = self::generatedLayerOrder($css);
        $fragment = $markup === ''
            ? null
            : \Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment::parse($markup);
        if ($fragment !== null) {
            foreach ($declarations as $index => $declaration) {
                $declarations[$index]['condition'] = self::generatedSubjectCondition(
                    $declaration,
                    $fragment,
                );
            }
        }
        // Later content wins for the exact same selector in the exact same
        // scope; an important declaration wins over every normal declaration.
        $exact = [];
        foreach ($declarations as $declaration) {
            $key = ($declaration['layer'] ?? '<unlayered>') . "\0"
                . $declaration['condition'] . "\0" . $declaration['selector'] . "\0"
                . $declaration['pseudo'];
            $winner = $exact[$key] ?? null;
            if ($winner === null
                || (!$winner['important'] && $declaration['important'])
                || ($winner['important'] === $declaration['important'])
            ) {
                $exact[$key] = $declaration;
            }
        }
        $byPseudo = ['marker' => [], 'before' => [], 'element' => [], 'after' => []];
        foreach ($exact as $declaration) {
            $byPseudo[$declaration['pseudo']][] = $declaration;
        }
        if ($fragment !== null) {
            $hiddenElements = self::generatedCssHiddenElements($fragment, $css);
            // A conditional winner cannot erase the unconditional cascade: at
            // another viewport or feature state that conditional rule is
            // inactive. Resolve the base cascade and each potentially-active
            // conditional scope, then retain every distinct possible winner.
            $conditionalScopes = [];
            foreach ($exact as $declaration) {
                if ($declaration['condition'] !== '') {
                    $conditionalScopes[$declaration['condition']] = true;
                }
            }
            $scopeKeys = array_keys($conditionalScopes);
            $scenarios = self::generatedConditionScenarios(
                $scopeKeys,
                self::MAX_GENERATED_CONDITION_SCENARIOS,
            );
            $overflowDeclarations = [];
            if ($scenarios === null) {
                $pseudoScenarios = self::generatedPseudoConditionScenarios($exact, $scopeKeys);
                if ($pseudoScenarios === null) {
                    $overflowDeclarations = array_values(array_filter(
                        $exact,
                        static fn (array $declaration): bool =>
                            $declaration['pseudo'] !== 'element'
                                && $declaration['condition'] !== '',
                    ));
                    $scenarios = [[]];
                } else {
                    $scenarios = [
                        [],
                        self::generatedConditionScenarioClosure($scopeKeys, $scopeKeys),
                        ...$pseudoScenarios,
                    ];
                }
            }
            $possibleByScenario = [];
            foreach ($scenarios as $scenario) {
                $winners = [];
                foreach ($exact as $declaration) {
                    if ($declaration['pseudo'] === 'element'
                        || ($declaration['condition'] !== ''
                            && !in_array($declaration['condition'], $scenario, true))
                    ) {
                        continue;
                    }
                    $specificity = GroundedContactMarkup::cssSelectorSpecificity($declaration['selector']);
                    foreach (GroundedContactMarkup::matchedCssElements(
                        $fragment,
                        $declaration['selector'],
                    ) as $element) {
                        if (!self::generatedElementPaintsPseudoContent(
                            $element,
                            $declaration['pseudo'],
                            $fragment,
                            $css,
                        )) {
                            continue;
                        }
                        $key = spl_object_id($element) . "\0" . $declaration['pseudo'];
                        $winner = $winners[$key]['declaration'] ?? null;
                        $layerRank = self::generatedLayerCascadeRank($declaration, $layerOrder);
                        if ($winner === null
                            || (!$winner['important'] && $declaration['important'])
                            || ($winner['important'] === $declaration['important']
                                && ($layerRank > $winners[$key]['layerRank']
                                    || ($layerRank === $winners[$key]['layerRank']
                                        && ($specificity > $winners[$key]['specificity']
                                            || ($specificity === $winners[$key]['specificity']
                                                && $declaration['start'] > $winner['start'])))))
                        ) {
                            $winners[$key] = compact(
                                'element',
                                'declaration',
                                'specificity',
                                'layerRank',
                            );
                        }
                    }
                }
                $possible = [];
                foreach ($winners as $winner) {
                    $element = $winner['element'];
                    $pseudo = $winner['declaration']['pseudo'];
                    $key = $winner['declaration']['start'] . ':' . $winner['declaration']['end'];
                    $possible[spl_object_id($element)]['element'] = $element;
                    $possible[spl_object_id($element)][$pseudo][$key] = $winner['declaration'];
                }
                $possibleByScenario[] = $possible;
            }
            $compositions = [];
            foreach ($possibleByScenario as $possible) {
                foreach ($possible as $state) {
                    $element = $state['element'];
                    $markerCandidates = array_values($state['marker'] ?? [null]);
                    $beforeCandidates = array_values($state['before'] ?? [null]);
                    $afterCandidates = array_values($state['after'] ?? [null]);
                    foreach ($markerCandidates as $marker) {
                        foreach ($beforeCandidates as $before) {
                            foreach ($afterCandidates as $after) {
                                if ($marker === null && $before === null && $after === null) {
                                    continue;
                                }
                                $rendered = ($marker['rendered'] ?? '')
                                    . ($before['rendered'] ?? '')
                                    . self::generatedElementText($element, $hiddenElements)
                                    . ($after['rendered'] ?? '')
                                    . self::generatedAssociatedControlText(
                                        $element,
                                        $fragment,
                                        $hiddenElements,
                                    );
                                $injected = $markup;
                                $insertions = [];
                                if ($after !== null) {
                                    $insertions[] = [
                                        'offset' => $element->innerEndOffset(),
                                        'rank' => 0,
                                        'text' => $after['rendered'],
                                    ];
                                }
                                if ($before !== null) {
                                    $insertions[] = [
                                        'offset' => $element->innerStartOffset(),
                                        'rank' => 1,
                                        'text' => $before['rendered'],
                                    ];
                                }
                                if ($marker !== null) {
                                    $insertions[] = [
                                        'offset' => $element->innerStartOffset(),
                                        'rank' => 2,
                                        'text' => $marker['rendered'],
                                    ];
                                }
                                usort(
                                    $insertions,
                                    static fn (array $left, array $right): int =>
                                        [$right['offset'], $left['rank']]
                                            <=> [$left['offset'], $right['rank']],
                                );
                                foreach ($insertions as $insertion) {
                                    $injected = substr($injected, 0, $insertion['offset'])
                                        . htmlspecialchars(
                                            $insertion['text'],
                                            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                                            'UTF-8',
                                        )
                                        . substr($injected, $insertion['offset']);
                                }
                                $participants = array_values(array_filter(
                                    [$marker, $before, $after],
                                    is_array(...),
                                ));
                                $compositions[] = compact('rendered', 'participants') + [
                                    'markup' => $injected,
                                    'grounding_fallback' => self::generatedMarkupNeedsGroundingFallback(
                                        $element,
                                        $markup,
                                    ),
                                ];
                            }
                        }
                    }
                }
            }
            foreach ($overflowDeclarations as $declaration) {
                $compositions[] = [
                    'rendered' => $declaration['rendered'],
                    'participants' => [$declaration],
                    'fail_closed' => true,
                ];
            }
            return $compositions;
        }
        $compositions = [];
        $partials = [[]];
        foreach (['marker', 'before', 'element', 'after'] as $pseudo) {
            $next = $partials;
            foreach ($partials as $partial) {
                foreach ($byPseudo[$pseudo] as $declaration) {
                    $compatible = true;
                    foreach ($partial as $participant) {
                        if (!self::generatedSelectorSubjectsCompatible(
                            $participant['selector'],
                            $declaration['selector'],
                        )) {
                            $compatible = false;
                            break;
                        }
                    }
                    if ($compatible) {
                        $next[] = [...$partial, $declaration];
                        if (count($next) > self::MAX_GENERATED_CONDITION_SCENARIOS) {
                            return array_map(
                                static fn (array $candidate): array => [
                                    'rendered' => $candidate['rendered'],
                                    'participants' => [$candidate],
                                    'fail_closed' => true,
                                ],
                                $declarations,
                            );
                        }
                    }
                }
            }
            $partials = $next;
        }
        foreach ($partials as $participants) {
            if (count($participants) < 2) {
                continue;
            }
            $compositions[] = [
                'rendered' => implode('', array_column($participants, 'rendered')),
                'participants' => $participants,
            ];
        }
        return $compositions;
    }

    private static function generatedElementPaintsPseudoContent(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlNode $element,
        string $pseudo,
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment $fragment,
        string $css,
    ): bool {
        $tag = strtolower((string) $element->tagName());
        for ($ancestor = $element; $ancestor !== null; $ancestor = $ancestor->parent()) {
            $ancestorTag = strtolower((string) $ancestor->tagName());
            if ($ancestorTag === 'foreignobject') {
                break;
            }
            if ($ancestorTag === 'svg') {
                return false;
            }
        }
        if ($pseudo === 'marker') {
            return self::generatedElementDisplaysListItem($element, $fragment, $css);
        }
        if ($tag === 'input') {
            return in_array(
                strtolower((string) ($element->attribute('type') ?? 'text')),
                ['checkbox', 'radio', 'range'],
                true,
            );
        }
        if ($tag === 'select') {
            return in_array('base-select', self::generatedElementCssValues(
                $element,
                $fragment,
                $css,
                ['appearance', '-webkit-appearance'],
            ), true);
        }
        if (in_array(
            $tag,
            ['audio', 'canvas', 'iframe', 'meter', 'object', 'progress', 'textarea', 'video'],
            true,
        )) {
            return false;
        }
        return !\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode::isVoidTag($tag);
    }

    private static function generatedElementDisplaysListItem(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlNode $element,
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment $fragment,
        string $css,
        int $depth = 0,
    ): bool {
        if ($depth >= 16) {
            return false;
        }
        $tag = strtolower((string) $element->tagName());
        foreach (self::generatedElementCssValues($element, $fragment, $css, ['display']) as $display) {
            if (in_array($display, ['<initial>', 'revert', 'revert-layer'], true)) {
                if (in_array($tag, ['li', 'summary'], true)) {
                    return true;
                }
                continue;
            }
            if ($display === 'inherit') {
                $parent = $element->parent();
                if ($parent?->isElement() === true
                    && self::generatedElementDisplaysListItem($parent, $fragment, $css, $depth + 1)
                ) {
                    return true;
                }
                continue;
            }
            if (in_array(
                'list-item',
                preg_split('/[\x09\x0A\x0C\x0D\x20]+/u', trim($display)) ?: [],
                true,
            )) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $properties @return list<string> */
    private static function generatedElementCssValues(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlNode $element,
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment $fragment,
        string $css,
        array $properties,
    ): array {
        $layerModel = self::generatedLayerModel($css);
        $layerOrder = $layerModel['order'];
        $registrationConditions = self::registeredCustomPropertyConditions($css);
        $canonicalProperty = $properties[0];
        $ancestry = [];
        for ($node = $element; $node !== null; $node = $node->parent()) {
            if ($node->isElement() || $node->isDocument()) {
                $ancestry[spl_object_id($node)] = $node;
            }
        }
        $candidates = [];
        foreach (CssChecks::scanDeclarations($css) as $declaration) {
            $priority = CssChecks::splitDeclarationPriority($declaration['value']);
            $isAll = strtolower($declaration['property']) === 'all';
            $authoredProperty = str_starts_with($declaration['property'], '--')
                ? $declaration['property']
                : strtolower($declaration['property']);
            if ($isAll) {
                $allValue = strtolower(CssChecks::decodeIdentifier($priority['value']));
                if (!in_array($allValue, ['initial', 'inherit', 'revert', 'revert-layer', 'unset'], true)) {
                    continue;
                }
                $authoredProperty = $canonicalProperty;
            }
            if (!in_array($authoredProperty, $properties, true)
                && !str_starts_with($authoredProperty, '--')
            ) {
                continue;
            }
            foreach (self::generatedContentSubjects($declaration) as $subject) {
                if ($subject['pseudo'] !== 'element') {
                    continue;
                }
                $matches = str_starts_with($authoredProperty, '--')
                    && preg_match('/^(?::root|html|body)$/iu', trim($subject['selector'])) === 1
                    ? [$fragment->root()]
                    : GroundedContactMarkup::matchedCssElements($fragment, $subject['selector']);
                foreach ($matches as $match) {
                    $nodeId = spl_object_id($match);
                    if (!isset($ancestry[$nodeId])) {
                        continue;
                    }
                    $important = $priority['important'];
                    $layer = self::generatedLayerForOffset($layerModel, $declaration['start']);
                    $inline = false;
                    $candidates[] = [
                        'node' => $match,
                        'property' => in_array($authoredProperty, $properties, true)
                            ? $canonicalProperty
                            : $authoredProperty,
                        'value' => $priority['value'],
                        'important' => $important,
                        'layer' => $layer,
                        'layerRank' => self::generatedLayerCascadeRank(
                            compact('layer', 'important', 'inline'),
                            $layerOrder,
                        ),
                        'specificity' => GroundedContactMarkup::cssSelectorSpecificity($subject['selector']),
                        'order' => $declaration['start'],
                        'condition' => self::generatedSubjectCondition($subject, $fragment),
                    ];
                }
            }
        }
        foreach ($ancestry as $node) {
            if (!$node->isElement()) {
                continue;
            }
            foreach (CssChecks::scanDeclarations((string) $node->attribute('style'), true) as $declaration) {
                $priority = CssChecks::splitDeclarationPriority($declaration['value']);
                $isAll = strtolower($declaration['property']) === 'all';
                $authoredProperty = str_starts_with($declaration['property'], '--')
                    ? $declaration['property']
                    : strtolower($declaration['property']);
                if ($isAll) {
                    $allValue = strtolower(CssChecks::decodeIdentifier($priority['value']));
                    if (!in_array(
                        $allValue,
                        ['initial', 'inherit', 'revert', 'revert-layer', 'unset'],
                        true,
                    )) {
                        continue;
                    }
                    $authoredProperty = $canonicalProperty;
                }
                if (!in_array($authoredProperty, $properties, true)
                    && !str_starts_with($authoredProperty, '--')
                ) {
                    continue;
                }
                $important = $priority['important'];
                $layer = null;
                $inline = true;
                $candidates[] = [
                    'node' => $node,
                    'property' => in_array($authoredProperty, $properties, true)
                        ? $canonicalProperty
                        : $authoredProperty,
                    'value' => $priority['value'],
                    'important' => $important,
                    'layer' => $layer,
                    'layerRank' => self::generatedLayerCascadeRank(
                        compact('layer', 'important', 'inline'),
                        $layerOrder,
                    ),
                    'specificity' => 1000,
                    'order' => PHP_INT_MAX,
                    'condition' => '',
                ];
            }
        }
        $candidates = self::generatedRelevantPropertyCandidates($candidates, $canonicalProperty);
        $conditions = [];
        foreach ($candidates as $candidate) {
            if ($candidate['condition'] !== '') {
                $conditions[$candidate['condition']] = true;
            }
        }
        foreach ($registrationConditions as $condition) {
            $conditions[$condition] = true;
        }
        $scopeKeys = array_keys($conditions);
        $scenarios = self::generatedConditionScenarios(
            $scopeKeys,
            self::MAX_GENERATED_CONDITION_SCENARIOS,
        );
        if ($scenarios === null) {
            return [$canonicalProperty === 'display' ? 'list-item' : 'base-select'];
        }
        $values = [];
        foreach ($scenarios as $scenario) {
            $registrations = self::registeredCustomProperties($css, $scenario);
            $winners = [];
            $activeCandidates = [];
            foreach ($candidates as $candidate) {
                if ($candidate['condition'] !== ''
                    && !in_array($candidate['condition'], $scenario, true)
                ) {
                    continue;
                }
                $nodeId = spl_object_id($candidate['node']);
                $activeCandidates[$nodeId][$candidate['property']][] = $candidate;
                $winner = $winners[$nodeId][$candidate['property']] ?? null;
                if (self::generatedCascadeCandidateWins($candidate, $winner)) {
                    $winners[$nodeId][$candidate['property']] = $candidate;
                }
            }
            foreach ($winners as $nodeId => $propertiesByNode) {
                foreach ($propertiesByNode as $property => $winner) {
                    $winners[$nodeId][$property] = self::generatedRevertLayerWinner(
                        $winner,
                        $activeCandidates[$nodeId][$property] ?? [],
                    );
                }
            }
            $elementId = spl_object_id($element);
            $winner = $winners[$elementId][$canonicalProperty] ?? null;
            $values[] = self::generatedResolvedCssValue(
                $winner['value'] ?? null,
                $canonicalProperty,
                $element,
                $winners,
                $registrations,
            );
        }
        return array_values(array_unique($values));
    }

    /** @param list<string> $activeConditions @return array<string,array{inherits:bool,initial:?string,syntax:string}> */
    public static function registeredCustomProperties(string $css, array $activeConditions = []): array
    {
        $registrations = [];
        foreach (self::generatedRegisteredCustomPropertyRules($css) as $rule) {
            if ($rule['condition'] !== ''
                && !in_array($rule['condition'], $activeConditions, true)
            ) {
                continue;
            }
            $registrations[$rule['name']] = [
                'inherits' => $rule['inherits'],
                'initial' => $rule['initial'],
                'syntax' => $rule['syntax'],
            ];
        }
        return $registrations;
    }

    /** @return list<string> */
    public static function registeredCustomPropertyConditions(string $css): array
    {
        $conditions = [];
        foreach (self::generatedRegisteredCustomPropertyRules($css) as $rule) {
            if ($rule['condition'] !== '') {
                $conditions[$rule['condition']] = true;
            }
        }
        return array_keys($conditions);
    }

    /** @return list<array{name:string,inherits:bool,initial:?string,syntax:string,condition:string}> */
    private static function generatedRegisteredCustomPropertyRules(string $css): array
    {
        $groups = [];
        $group = -1;
        $previousEnd = null;
        $previousName = null;
        $previousCondition = null;
        foreach (CssChecks::scanDeclarations($css) as $declaration) {
            if ($declaration['kind'] !== 'at-rule'
                || preg_match('/^\s*@property\s+(--[^\s{]+)\s*$/iu', $declaration['context'], $match) !== 1
                || trim((string) ($declaration['ancestors'][array_key_last($declaration['ancestors'])] ?? ''))
                    !== trim($declaration['context'])
                || self::generatedPropertyRuleIsInactive($declaration['ancestors'])
            ) {
                continue;
            }
            $name = CssChecks::decodeIdentifier((string) $match[1]);
            $condition = self::generatedPropertyRuleCondition($declaration['ancestors']);
            if ($previousEnd === null
                || $previousName !== $name
                || $previousCondition !== $condition
                || str_contains(substr($css, $previousEnd, $declaration['start'] - $previousEnd), '}')
            ) {
                $group++;
                $groups[$group] = [
                    'name' => $name,
                    'condition' => $condition,
                    'descriptors' => [],
                ];
            }
            $property = strtolower($declaration['property']);
            if (in_array($property, ['inherits', 'initial-value', 'syntax'], true)) {
                $groups[$group]['descriptors'][$property] = trim($declaration['value']);
            }
            $previousEnd = $declaration['end'];
            $previousName = $name;
            $previousCondition = $condition;
        }
        $rules = [];
        foreach ($groups as $propertyRule) {
            $name = $propertyRule['name'];
            $values = $propertyRule['descriptors'];
            if (!isset($values['syntax'], $values['inherits'])
                || !in_array(strtolower($values['inherits']), ['false', 'true'], true)
                || preg_match('/^(["\'])(.*)\1$/suD', $values['syntax'], $syntaxMatch) !== 1
            ) {
                continue;
            }
            $syntax = CssChecks::decodeIdentifier((string) $syntaxMatch[2]);
            $initial = $values['initial-value'] ?? null;
            if (!self::generatedSyntaxDefinitionIsValid($syntax)
                || ($initial === null && $syntax !== '*')
                || ($initial !== null && !self::generatedRegisteredInitialIsValid($syntax, $initial))
            ) {
                continue;
            }
            $rules[] = [
                'name' => $name,
                'inherits' => strtolower($values['inherits']) === 'true',
                'initial' => $initial,
                'syntax' => $syntax,
                'condition' => $propertyRule['condition'],
            ];
        }
        return $rules;
    }

    /** @param list<string> $ancestors */
    private static function generatedPropertyRuleCondition(array $ancestors): string
    {
        return implode("\0", array_values(array_filter(
            array_slice($ancestors, 0, -1),
            static fn (string $ancestor): bool => str_starts_with(ltrim($ancestor), '@')
                && preg_match('/^\s*@layer\b/iu', $ancestor) !== 1,
        )));
    }

    private static function generatedRegisteredInitialIsValid(string $syntax, string $initial): bool
    {
        $decoded = self::generatedDecodeCssEscapes($initial);
        if (preg_match('/(?<![-\w])(?:attr|env|var)\s*\(/iu', $decoded) === 1
            || preg_match(
                '/(?:\d|\.)(?:em|rem|ex|ch|cap|ic|lh|rlh|vw|vh|vi|vb|vmin|vmax|%)\b/iu',
                $decoded,
            ) === 1
        ) {
            return false;
        }
        return self::generatedValueMatchesSyntax($initial, $syntax, true);
    }

    /** @param list<string> $ancestors */
    private static function generatedPropertyRuleIsInactive(array $ancestors): bool
    {
        foreach (array_slice($ancestors, 0, -1) as $ancestor) {
            $decoded = self::generatedDecodeCssEscapes(
                preg_replace('~/\*.*?\*/~su', ' ', $ancestor) ?? $ancestor,
            );
            if (preg_match('/^\s*@media\s+(.+)$/isuD', trim($decoded), $media) === 1) {
                $queries = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel(
                    (string) $media[1],
                    [','],
                );
                if ($queries !== [] && array_filter(
                    $queries,
                    static fn (string $query): bool => strtolower((string) preg_replace(
                        '/\s+/u',
                        ' ',
                        trim($query),
                    )) !== 'not all',
                ) === []) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function generatedValueMatchesSyntax(
        string $value,
        string $syntax,
        bool $initialValue = false,
    ): bool {
        if (!self::generatedSyntaxDefinitionIsValid($syntax)) {
            return false;
        }
        $authoredValue = preg_replace('~/\*.*?\*/~su', ' ', $value) ?? $value;
        $value = trim(self::generatedDecodeCssEscapes($authoredValue));
        $alternatives = preg_split('/\s*\|\s*/u', trim($syntax)) ?: [];
        foreach ($alternatives as $alternative) {
            $multiplier = str_ends_with($alternative, '+') || str_ends_with($alternative, '#')
                ? substr($alternative, -1)
                : null;
            if ($multiplier !== null) {
                $componentSyntax = trim(substr($alternative, 0, -1));
                $components = $multiplier === '#'
                    ? \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel(
                        $value,
                        [','],
                    )
                    : self::generatedSplitTopLevelWhitespace($value);
                $componentsAreValid = is_array($components) && $components !== [];
                foreach ($components ?: [] as $component) {
                    if ($component === '' || !self::generatedValueMatchesSyntax(
                        $component,
                        $componentSyntax,
                        $initialValue,
                    )) {
                        $componentsAreValid = false;
                        break;
                    }
                }
                if ($componentsAreValid) {
                    return true;
                }
                continue;
            }
            if ($alternative === '*') {
                return $value !== '';
            }
            if ($alternative === '<custom-ident>' && self::generatedCustomIdentIsValid($value)) {
                return true;
            }
            if ($alternative === '<number>'
                && preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/iuD', $value) === 1
            ) {
                return true;
            }
            if ($alternative === '<integer>' && preg_match('/^[+-]?\d+$/uD', $value) === 1) {
                return true;
            }
            if ($alternative === '<percentage>'
                && preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?%$/iuD', $value) === 1
            ) {
                return true;
            }
            if ($alternative === '<angle>'
                && preg_match('/^(?:[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:deg|grad|rad|turn)|[+-]?0(?:\.0*)?)$/iuD', $value) === 1
            ) {
                return true;
            }
            if ($alternative === '<time>'
                && preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:ms|s)$/iuD', $value) === 1
            ) {
                return true;
            }
            if ($alternative === '<resolution>'
                && preg_match('/^[+]?(?:\d+(?:\.\d*)?|\.\d+)(?:dpi|dpcm|dppx|x)$/iuD', $value) === 1
            ) {
                return true;
            }
            if ($alternative === '<color>' && self::generatedColorIsValid($value)) {
                return true;
            }
            if (in_array($alternative, ['<length>', '<length-percentage>'], true)) {
                $units = $initialValue
                    ? 'px|cm|mm|q|in|pt|pc'
                    : 'px|cm|mm|q|in|pt|pc|em|rem|ex|ch|cap|ic|lh|rlh|vw|vh|vi|vb|vmin|vmax';
                if (preg_match(
                    '/^(?:[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?(?:'
                        . $units . ')|[+-]?0(?:\.0*)?)$/iuD',
                    $value,
                ) === 1 || ($alternative === '<length-percentage>'
                    && preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)%$/uD', $value) === 1)
                ) {
                    return true;
                }
                if (!str_contains($authoredValue, '\\')
                    && self::generatedMathFunctionMatchesLength(
                        $value,
                        $alternative === '<length-percentage>',
                        $initialValue,
                    )
                ) {
                    return true;
                }
            }
            if ($alternative !== ''
                && $alternative[0] !== '<'
                && strtolower(CssChecks::decodeIdentifier($alternative)) === strtolower($value)
            ) {
                return true;
            }
        }
        return false;
    }

    private static function generatedMathFunctionMatchesLength(
        string $value,
        bool $allowPercentage,
        bool $initialValue,
    ): bool {
        if (preg_match('/^(calc|min|max|clamp)\((.*)\)$/isuD', $value, $function) !== 1
            || preg_match('/(?<![-\w])(?:attr|env|var)\s*\(/iu', $value) === 1
        ) {
            return false;
        }
        $arguments = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel(
            (string) $function[2],
            [','],
        );
        $name = strtolower((string) $function[1]);
        if (str_ends_with(rtrim((string) $function[2]), ',')
            || ($name === 'calc' && count($arguments) !== 1)
            || ($name === 'clamp' && count($arguments) !== 3)
            || (in_array($name, ['min', 'max'], true) && $arguments === [])
        ) {
            return false;
        }
        $type = null;
        foreach ($arguments as $argument) {
            if (trim($argument) === ''
                || !self::generatedMathBinaryOperatorsAreSpaced($argument)
            ) {
                return false;
            }
            $candidate = self::generatedMathExpressionType(
                $argument,
                $allowPercentage,
                $initialValue,
            );
            if ($candidate === null || ($type !== null && $candidate !== $type)) {
                return false;
            }
            $type = $candidate;
        }
        return $type === 'length' || ($allowPercentage && $type === 'percentage');
    }

    /** CSS requires spaced binary signs and unspaced signed numeric tokens. */
    private static function generatedMathBinaryOperatorsAreSpaced(string $expression): bool
    {
        $length = strlen($expression);
        for ($index = 0; $index < $length; $index++) {
            if ($expression[$index] !== '+' && $expression[$index] !== '-') {
                continue;
            }
            if (preg_match(
                '/^(?:\+(?:pi|e|infinity|nan)|-(?:pi|e|nan))\b/iu',
                substr($expression, $index),
            ) === 1) {
                return false;
            }
            if (isset($expression[$index + 1])
                && in_array($expression[$index + 1], ['+', '-', '('], true)
            ) {
                return false;
            }
            if ($index > 1
                && ($expression[$index - 1] === 'e' || $expression[$index - 1] === 'E')
                && preg_match('/[0-9.]/', $expression[$index - 2]) === 1
                && isset($expression[$index + 1])
                && ctype_digit($expression[$index + 1])
            ) {
                continue;
            }
            $left = $index - 1;
            while ($left >= 0 && ctype_space($expression[$left])) {
                $left--;
            }
            if ($left < 0
                || preg_match('/[0-9a-z.%)]/i', $expression[$left]) !== 1
            ) {
                if (!isset($expression[$index + 1])
                    || ctype_space($expression[$index + 1])
                ) {
                    return false;
                }
                continue;
            }
            if ($index === 0
                || !ctype_space($expression[$index - 1])
                || !isset($expression[$index + 1])
                || !ctype_space($expression[$index + 1])
            ) {
                return false;
            }
        }
        return true;
    }

    private static function generatedMathExpressionType(
        string $expression,
        bool $allowPercentage,
        bool $initialValue,
    ): ?string {
        $tokens = self::generatedMathTokens($expression, $allowPercentage, $initialValue);
        if ($tokens === null) {
            return null;
        }
        $index = 0;
        $type = self::generatedMathParseSum($tokens, $index);
        return $index === count($tokens) ? $type : null;
    }

    /** @return ?list<string> */
    private static function generatedMathTokens(
        string $expression,
        bool $allowPercentage,
        bool $initialValue,
    ): ?array {
        $tokens = [];
        $units = $initialValue
            ? ['px', 'cm', 'mm', 'q', 'in', 'pt', 'pc']
            : ['px', 'cm', 'mm', 'q', 'in', 'pt', 'pc', 'em', 'rem', 'ex', 'ch',
                'cap', 'ic', 'lh', 'rlh', 'vw', 'vh', 'vi', 'vb', 'vmin', 'vmax'];
        $nesting = 0;
        for ($offset = 0, $length = strlen($expression); $offset < $length;) {
            if (preg_match('/^\s+/u', substr($expression, $offset), $space) === 1) {
                $offset += strlen((string) $space[0]);
                continue;
            }
            $byte = $expression[$offset];
            if (str_contains('+-*/()', $byte)) {
                if ($byte === '(' && ++$nesting > self::MAX_GENERATED_MATH_NESTING) {
                    return null;
                }
                if ($byte === ')' && --$nesting < 0) {
                    return null;
                }
                $tokens[] = $byte;
                $offset++;
                continue;
            }
            if (preg_match(
                '/^(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?([a-z%]*)/iu',
                substr($expression, $offset),
                $number,
            ) === 1) {
                $unit = strtolower((string) $number[1]);
                if ($unit === '') {
                    $tokens[] = 'value:number';
                } elseif ($unit === '%' && $allowPercentage) {
                    $tokens[] = 'value:percentage';
                } elseif (in_array($unit, $units, true)) {
                    $tokens[] = 'value:length';
                } else {
                    return null;
                }
                $offset += strlen((string) $number[0]);
                continue;
            }
            if (preg_match('/^(?:pi|e|infinity|-infinity|nan)\b/iu', substr($expression, $offset), $constant) === 1) {
                $tokens[] = 'value:number';
                $offset += strlen((string) $constant[0]);
                continue;
            }
            return null;
        }
        return $tokens === [] || $nesting !== 0 ? null : $tokens;
    }

    /** @param list<string> $tokens */
    private static function generatedMathParseSum(array $tokens, int &$index): ?string
    {
        $left = self::generatedMathParseProduct($tokens, $index);
        while ($left !== null && in_array($tokens[$index] ?? null, ['+', '-'], true)) {
            $index++;
            $right = self::generatedMathParseProduct($tokens, $index);
            if ($right === null || $left !== $right) {
                return null;
            }
        }
        return $left;
    }

    /** @param list<string> $tokens */
    private static function generatedMathParseProduct(array $tokens, int &$index): ?string
    {
        $left = self::generatedMathParseFactor($tokens, $index);
        while ($left !== null && in_array($tokens[$index] ?? null, ['*', '/'], true)) {
            $operator = $tokens[$index++];
            $right = self::generatedMathParseFactor($tokens, $index);
            if ($right === null) {
                return null;
            }
            if ($operator === '*') {
                if ($left === 'number') {
                    $left = $right;
                } elseif ($right !== 'number') {
                    return null;
                }
            } elseif ($right !== 'number') {
                return null;
            }
        }
        return $left;
    }

    /** @param list<string> $tokens */
    private static function generatedMathParseFactor(array $tokens, int &$index): ?string
    {
        if (in_array($tokens[$index] ?? null, ['+', '-'], true)) {
            $index++;
            return self::generatedMathParseFactor($tokens, $index);
        }
        $token = $tokens[$index] ?? null;
        if (is_string($token) && str_starts_with($token, 'value:')) {
            $index++;
            return substr($token, strlen('value:'));
        }
        if ($token !== '(') {
            return null;
        }
        $index++;
        $type = self::generatedMathParseSum($tokens, $index);
        if (($tokens[$index] ?? null) !== ')') {
            return null;
        }
        $index++;
        return $type;
    }

    /** @return list<string> */
    private static function generatedSplitTopLevelWhitespace(string $value): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $length = strlen($value);
        for ($index = 0; $index < $length; $index++) {
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
            } elseif ($byte === '(' || $byte === '[') {
                $depth++;
            } elseif ($byte === ')' || $byte === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && preg_match('/\s/u', $byte) === 1) {
                $part = trim(substr($value, $start, $index - $start));
                if ($part !== '') {
                    $parts[] = $part;
                }
                while ($index + 1 < $length && preg_match('/\s/u', $value[$index + 1]) === 1) {
                    $index++;
                }
                $start = $index + 1;
            }
        }
        $part = trim(substr($value, $start));
        if ($part !== '') {
            $parts[] = $part;
        }
        return $parts;
    }

    private static function generatedColorIsValid(string $value, int $depth = 0): bool
    {
        if ($depth >= 8) {
            return false;
        }
        $value = strtolower(trim($value));
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/iuD', $value) === 1) {
            return true;
        }
        if (\Automattic\SiteBuild\Units\CardStyleContract::isCssColorName($value)) {
            return true;
        }
        if (preg_match('/^color-mix\((.*)\)$/isuD', $value, $mix) === 1) {
            $parts = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel(
                (string) $mix[1],
                [','],
            );
            if (count($parts) !== 3
                || preg_match(
                    '/^\s*in\s+(?:(?:hsl|hwb|lch|oklch)'
                        . '(?:\s+(?:shorter|longer|increasing|decreasing)\s+hue)?'
                        . '|srgb(?:-linear)?|display-p3|a98-rgb|prophoto-rgb|rec2020|'
                        . 'lab|oklab|xyz(?:-d50|-d65)?)\s*$/iuD',
                    $parts[0],
                ) !== 1
            ) {
                return false;
            }
            $left = self::generatedColorMixOperand($parts[1], $depth + 1);
            $right = self::generatedColorMixOperand($parts[2], $depth + 1);
            return $left !== null
                && $right !== null
                && (($left['percentage'] ?? 50.0) + ($right['percentage'] ?? 50.0)) > 0.0;
        }
        if (preg_match('/^(rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|color)\((.*)\)$/isuD', $value, $function) !== 1) {
            return false;
        }
        $arguments = trim((string) $function[2]);
        if ($arguments === '' || str_ends_with($arguments, ',')) {
            return false;
        }
        $name = strtolower((string) $function[1]);
        if ($name === 'color') {
            if (preg_match(
                '/^(srgb(?:-linear)?|display-p3|a98-rgb|prophoto-rgb|rec2020|'
                    . 'xyz(?:-d50|-d65)?)\s+(.+)$/isuD',
                $arguments,
                $space,
            ) !== 1) {
                return false;
            }
            $arguments = (string) $space[2];
        }
        $commaParts = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel(
            $arguments,
            [','],
        );
        $components = [];
        if (count($commaParts) > 1) {
            $components = array_slice($commaParts, 0, 3);
            if (!in_array($name, ['rgb', 'rgba', 'hsl', 'hsla'], true)
                || !in_array(count($commaParts), [3, 4], true)
                || array_filter($commaParts, static fn (string $part): bool => trim($part) === '') !== []
                || str_contains($arguments, '/')
                || !self::generatedLegacyColorComponentsAreValid($name, $components)
                || (count($commaParts) === 4
                    && !self::generatedColorAlphaIsValid($commaParts[3], false))
            ) {
                return false;
            }
        } else {
            $spaceParts = self::generatedSplitTopLevelWhitespace($arguments);
            $components = array_slice($spaceParts, 0, 3);
            if (count($spaceParts) !== 3
                && !(count($spaceParts) === 5 && $spaceParts[3] === '/')
            ) {
                return false;
            }
            if (count($spaceParts) === 5
                && !self::generatedColorAlphaIsValid($spaceParts[4])
            ) {
                return false;
            }
        }
        if (!self::generatedColorComponentsAreValid($name, $components)) {
            return false;
        }
        if (preg_match('/[a-z_]/iu', preg_replace('/\b(?:deg|grad|rad|turn|none)\b/iu', '', $arguments) ?? '') === 1) {
            return false;
        }
        $numbers = preg_match_all('/(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?%?/iu', $arguments);
        return is_int($numbers) && $numbers >= 3
            && preg_match('/[^0-9e+.,%\/\s-]/iu', preg_replace(
                '/\b(?:deg|grad|rad|turn|none)\b/iu',
                '',
                $arguments,
            ) ?? '') !== 1;
    }

    /** @return ?array{percentage:?float} */
    private static function generatedColorMixOperand(string $operand, int $depth): ?array
    {
        $operand = trim($operand);
        $weight = null;
        if (preg_match(
            '/^(.*\S)\s+([+-]?(?:\d+(?:\.\d*)?|\.\d+))%$/uD',
            $operand,
            $percentage,
        ) === 1) {
            $operand = (string) $percentage[1];
            $weight = (float) $percentage[2];
            if ($weight < 0.0 || $weight > 100.0) {
                return null;
            }
        }
        return self::generatedColorIsValid($operand, $depth)
            ? ['percentage' => $weight]
            : null;
    }

    private static function generatedColorAlphaIsValid(string $alpha, bool $allowNone = true): bool
    {
        if (!$allowNone && strtolower(trim($alpha)) === 'none') {
            return false;
        }
        return preg_match(
            '/^(?:none|[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?%?)$/iuD',
            trim($alpha),
        ) === 1;
    }

    /** @param list<string> $components */
    private static function generatedColorComponentsAreValid(string $name, array $components): bool
    {
        $name = match ($name) {
            'rgba' => 'rgb',
            'hsla' => 'hsl',
            default => $name,
        };
        foreach ($components as $index => $component) {
            $allowAngle = in_array($name, ['hsl', 'hwb'], true) && $index === 0
                || in_array($name, ['lch', 'oklch'], true) && $index === 2;
            $allowPercentage = !$allowAngle;
            $suffixes = [];
            if ($allowPercentage) {
                $suffixes[] = '%';
            }
            if ($allowAngle) {
                array_push($suffixes, 'deg', 'grad', 'rad', 'turn');
            }
            $suffix = $suffixes === [] ? '' : '(?:' . implode('|', $suffixes) . ')?';
            if (preg_match(
                '/^(?:none|[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?' . $suffix . ')$/iuD',
                trim($component),
            ) !== 1) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $components */
    private static function generatedLegacyColorComponentsAreValid(string $name, array $components): bool
    {
        if (array_filter(
            $components,
            static fn (string $component): bool => strtolower(trim($component)) === 'none',
        ) !== []) {
            return false;
        }
        $name = match ($name) {
            'rgba' => 'rgb',
            'hsla' => 'hsl',
            default => $name,
        };
        if ($name === 'hsl') {
            return !str_ends_with(trim($components[0] ?? ''), '%')
                && str_ends_with(trim($components[1] ?? ''), '%')
                && str_ends_with(trim($components[2] ?? ''), '%');
        }
        $percentageChannels = count(array_filter(
            $components,
            static fn (string $component): bool => str_ends_with(trim($component), '%'),
        ));
        return $percentageChannels === 0 || $percentageChannels === count($components);
    }

    private static function generatedSyntaxDefinitionIsValid(string $syntax): bool
    {
        $syntax = trim($syntax);
        if ($syntax === '*') {
            return true;
        }
        $alternatives = explode('|', $syntax);
        foreach ($alternatives as $alternative) {
            $alternative = trim($alternative);
            if ($alternative === '' || $alternative === '*') {
                return false;
            }
            if (str_ends_with($alternative, '+') || str_ends_with($alternative, '#')) {
                if (preg_match('/\s[+#]$/u', $alternative) === 1) {
                    return false;
                }
                $alternative = trim(substr($alternative, 0, -1));
            }
            if (in_array($alternative, [
                '<custom-ident>', '<number>', '<integer>', '<percentage>', '<angle>',
                '<time>', '<resolution>', '<color>', '<length>', '<length-percentage>',
            ], true)) {
                continue;
            }
            if (!self::generatedCustomIdentIsValid($alternative)) {
                return false;
            }
        }
        return true;
    }

    private static function generatedCustomIdentIsValid(string $value): bool
    {
        $value = trim(self::generatedDecodeCssEscapes($value));
        return preg_match(
            '/^-?(?:[_a-z\x{80}-\x{10ffff}])(?:[-_a-z0-9\x{80}-\x{10ffff}])*$/iuD',
            $value,
        ) === 1
            && !in_array(strtolower($value), [
                'default', 'inherit', 'initial', 'revert', 'revert-layer', 'unset',
            ], true);
    }

    /**
     * Conditional custom properties that cannot participate in resolving the
     * inspected property must not consume the bounded condition-state search.
     * Follow every var() edge first, then retain only that dependency closure.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    private static function generatedRelevantPropertyCandidates(
        array $candidates,
        string $canonicalProperty,
    ): array {
        $needed = [];
        foreach ($candidates as $candidate) {
            if ($candidate['property'] === $canonicalProperty) {
                foreach (self::generatedReferencedCustomProperties((string) $candidate['value']) as $name) {
                    $needed[$name] = true;
                }
            }
        }
        do {
            $changed = false;
            foreach ($candidates as $candidate) {
                if (!isset($needed[$candidate['property']])) {
                    continue;
                }
                foreach (self::generatedReferencedCustomProperties((string) $candidate['value']) as $name) {
                    if (!isset($needed[$name])) {
                        $needed[$name] = true;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['property'] === $canonicalProperty
                || isset($needed[$candidate['property']]),
        ));
    }

    /** @return list<string> */
    private static function generatedReferencedCustomProperties(string $value): array
    {
        $value = preg_replace('~/\*.*?\*/~su', ' ', $value) ?? $value;
        $value = self::generatedDecodeCssEscapes($value);
        preg_match_all('/(?<![-\w])var\s*\(\s*(--[-_a-z0-9\x{80}-\x{10ffff}]+)/iu', $value, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }

    /** @param array{important:bool,layerRank:int,specificity:int,order:int} $candidate */
    private static function generatedCascadeCandidateWins(array $candidate, ?array $winner): bool
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

    /** @param list<array<string,mixed>> $candidates */
    public static function cascadeRevertLayerWinner(?array $winner, array $candidates): ?array
    {
        return self::generatedRevertLayerWinner($winner, $candidates);
    }

    /** @param list<array<string,mixed>> $candidates */
    private static function generatedRevertLayerWinner(?array $winner, array $candidates): ?array
    {
        $excludedLayers = [];
        while ($winner !== null) {
            $value = strtolower(trim(self::generatedDecodeCssEscapes(
                preg_replace('~/\*.*?\*/~su', ' ', (string) $winner['value']) ?? (string) $winner['value'],
            )));
            if ($value !== 'revert-layer') {
                return $winner;
            }
            $layer = $winner['layer'] ?? null;
            if ($layer === null) {
                return null;
            }
            $excludedLayers[$layer] = true;
            $winner = null;
            foreach ($candidates as $candidate) {
                if (isset($excludedLayers[$candidate['layer'] ?? ''])) {
                    continue;
                }
                if (self::generatedCascadeCandidateWins($candidate, $winner)) {
                    $winner = $candidate;
                }
            }
        }
        return null;
    }

    /**
     * @param array<int,array<string,array{value:string}>> $winners
     * @param array<string,array{inherits:bool,initial:string}> $registrations
     */
    private static function generatedResolvedCssValue(
        ?string $value,
        string $property,
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlNode $element,
        array $winners,
        array $registrations,
    ): string {
        if ($value === null) {
            if ($property !== 'visibility' || $element->parent() === null) {
                return '<initial>';
            }
            $parent = $element->parent();
            return self::generatedResolvedCssValue(
                $winners[spl_object_id($parent)][$property]['value'] ?? null,
                $property,
                $parent,
                $winners,
                $registrations,
            );
        }
        $resolved = self::generatedTryResolveCssValue(
            $value,
            $element,
            $winners,
            $registrations,
            [],
            false,
            0,
        );
        $computed = $resolved['value'] ?? strtolower(trim(self::generatedDecodeCssEscapes(
            preg_replace('~/\*.*?\*/~su', ' ', $value) ?? $value,
        )));
        if ($computed === 'inherit'
            || ($property === 'visibility' && in_array($computed, ['revert', 'unset'], true))
        ) {
            $parent = $element->parent();
            if ($parent === null) {
                return '<initial>';
            }
            return self::generatedResolvedCssValue(
                $winners[spl_object_id($parent)][$property]['value'] ?? null,
                $property,
                $parent,
                $winners,
                $registrations,
            );
        }
        if (in_array($computed, ['initial', 'revert', 'revert-layer', 'unset'], true)) {
            return '<initial>';
        }
        return $computed;
    }

    /**
     * @param array<int,array<string,array{value:string,node?:object}>> $winners
     * @param array<string,array{inherits:bool,initial:string}> $registrations
     * @param array<string,true> $resolving
     * @return array{value:?string,cycle:bool}
     */
    private static function generatedTryResolveCssValue(
        string $value,
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlNode $element,
        array $winners,
        array $registrations,
        array $resolving,
        bool $customPropertyValue,
        int $depth,
    ): array {
        $value = preg_replace('~/\*.*?\*/~su', ' ', $value) ?? $value;
        $value = self::generatedDecodeCssEscapes($value);
        if ($depth >= 32) {
            return ['value' => null, 'cycle' => true];
        }
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
        $close = self::generatedCssClosingParenthesis($value, $open);
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
            if (preg_match('/^--[-_a-z0-9\x{80}-\x{10ffff}]+$/iuD', $name) === 1) {
                for ($node = $element; $node !== null; $node = $node->parent()) {
                    $winner = $winners[spl_object_id($node)][$name] ?? null;
                    if (!is_array($winner)) {
                        if (isset($registrations[$name]) && !$registrations[$name]['inherits']) {
                            if ($registrations[$name]['initial'] !== null) {
                                $replacement = self::generatedTryResolveCssValue(
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
                        continue;
                    }
                    $wide = strtolower(trim(self::generatedDecodeCssEscapes((string) $winner['value'])));
                    if ($wide === 'inherit'
                        || (in_array($wide, ['revert', 'revert-layer', 'unset'], true)
                            && ($registrations[$name]['inherits'] ?? true))
                    ) {
                        continue;
                    }
                    if ($wide === 'initial'
                        || in_array($wide, ['revert', 'revert-layer', 'unset'], true)
                    ) {
                        if (isset($registrations[$name])
                            && $registrations[$name]['initial'] !== null
                        ) {
                            $replacement = self::generatedTryResolveCssValue(
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
                    $owner = ($winner['node'] ?? null) instanceof
                        \Automattic\SiteBuild\BlockSerializer\Html\HtmlNode
                        ? $winner['node']
                        : $node;
                    $replacement = self::generatedTryResolveCssValue(
                        (string) $winner['value'],
                        $owner,
                        $winners,
                        $registrations,
                        $nextResolving,
                        true,
                        $depth + 1,
                    );
                    if ($replacement['value'] !== null
                        && isset($registrations[$name])
                        && (!self::generatedValueMatchesSyntax(
                            $replacement['value'],
                            $registrations[$name]['syntax'],
                        ) || (str_contains((string) $winner['value'], '\\')
                            && preg_match(
                                '/(?:calc|min|max|clamp)\s*\(/iu',
                                (string) $winner['value'],
                            ) === 1
                            && !self::generatedValueMatchesSyntax(
                                (string) $winner['value'],
                                $registrations[$name]['syntax'],
                            )))
                    ) {
                        $replacement = $registrations[$name]['initial'] === null
                            ? ['value' => null, 'cycle' => false]
                            : self::generatedTryResolveCssValue(
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
                if ($replacement['value'] === null
                    && !$replacement['cycle']
                    && isset($registrations[$name])
                    && $registrations[$name]['initial'] !== null
                ) {
                    $replacement = self::generatedTryResolveCssValue(
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
            if ($replacement['cycle'] && $customPropertyValue) {
                return $replacement;
            }
        } elseif ($function === 'env') {
            $environment = trim((string) ($arguments[0] ?? ''));
            $known = self::knownEnvironmentValue($environment);
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
                && self::attributeValueMatchesType((string) $element->attribute($attribute), $type)
            ) {
                $replacement = [
                    'value' => trim((string) $element->attribute($attribute)),
                    'cycle' => false,
                ];
            }
        }
        if ($replacement['value'] === null && count($arguments) > 1) {
            $replacement = self::generatedTryResolveCssValue(
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
        return self::generatedTryResolveCssValue(
            substr($value, 0, $start) . $replacement['value'] . substr($value, $close + 1),
            $element,
            $winners,
            $registrations,
            $resolving,
            $customPropertyValue,
            $depth + 1,
        );
    }

    public static function knownEnvironmentValue(string $name): ?string
    {
        $name = CssChecks::decodeIdentifier(trim($name));
        if (preg_match('/^safe-area-(?:max-)?inset-(?:top|right|bottom|left)$/uD', $name) === 1) {
            return '0px';
        }
        return $name === 'preferred-text-scale' ? '1' : null;
    }

    public static function attributeValueMatchesType(string $value, ?string $type): bool
    {
        if ($type === null || $type === '' || $type === 'string' || $type === 'raw-string') {
            return true;
        }
        return self::generatedValueMatchesSyntax(
            $value,
            CssChecks::decodeIdentifier($type),
        );
    }

    public static function customPropertyValueMatchesSyntax(string $value, string $syntax): bool
    {
        return self::generatedValueMatchesSyntax($value, $syntax);
    }

    private static function generatedCssClosingParenthesis(string $value, int $open): ?int
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

    /** @param array<string,array<string,true>> $allowed */
    private static function markupWarningCountAgainstAllowed(string $markup, array $allowed): int
    {
        $siteSpec = ['contact' => []];
        foreach ($allowed as $type => $values) {
            foreach (array_keys($values) as $value) {
                $siteSpec['contact'][$type][] = $value;
            }
        }
        $warnings = [];
        GroundedContactMarkup::scrub($markup, $siteSpec, '<css-markup-composition>', $warnings);
        return count($warnings);
    }

    /**
     * Resolve visibility through the same layer, inline, variable, and
     * conditional cascade used for pseudo painting. A node is classified as
     * hidden only when every represented browser state hides it.
     *
     * @return array{
     *     display:array<int,true>,
     *     visibility:array<int,true>,
     *     displayOverride:array<int,true>
     * }
     */
    private static function generatedCssHiddenElements(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment $fragment,
        string $css,
    ): array {
        $hidden = ['display' => [], 'visibility' => [], 'displayOverride' => []];
        foreach (self::generatedDescendantElements($fragment->root()) as $element) {
            $id = spl_object_id($element);
            $display = self::generatedElementCssValues($element, $fragment, $css, ['display']);
            if ($display !== [] && array_filter(
                $display,
                static fn (string $value): bool => $value !== 'none',
            ) === []) {
                $hidden['display'][$id] = true;
            }
            if (array_filter(
                $display,
                static fn (string $value): bool => !in_array(
                    $value,
                    ['<initial>', 'none', 'revert'],
                    true,
                ),
            ) !== []) {
                $hidden['displayOverride'][$id] = true;
            }
            $visibility = self::generatedElementCssValues(
                $element,
                $fragment,
                $css,
                ['visibility'],
            );
            if ($visibility !== [] && array_filter(
                $visibility,
                static fn (string $value): bool => !in_array($value, ['collapse', 'hidden'], true),
            ) === []) {
                $hidden['visibility'][$id] = true;
            }
        }
        return $hidden;
    }

    /**
     * Browser-visible text contributed by an element, including the value
     * rendered inside descendant text-like form controls. textContent alone
     * omits those values, even though a generated label pseudo and the control
     * value are published together visually and through accessibility APIs.
     *
     * @param array{display?:array<int,true>,visibility?:array<int,true>,displayOverride?:array<int,true>}
     *     $hiddenElements
     */
    private static function generatedElementText(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlNode $element,
        array $hiddenElements = [],
    ): string {
        if ($element->isText()) {
            return $element->textContent();
        }
        if (!$element->isElement() && !$element->isDocument()) {
            return '';
        }
        $tag = $element->tagName();
        if (self::generatedElementIsBrowserInert($element, $hiddenElements)) {
            return '';
        }
        if ($tag === 'input') {
            $type = strtolower(trim((string) ($element->attribute('type') ?? 'text')));
            $type = $type === '' ? 'text' : $type;
            if ($type === 'image') {
                return (string) ($element->attribute('alt') ?? '');
            }
            if (!in_array(
                $type,
                ['button', 'email', 'number', 'reset', 'search', 'submit', 'tel', 'text', 'url'],
                true,
            ) || !$element->hasAttribute('value')) {
                return '';
            }
            $value = (string) $element->attribute('value');
            if (in_array($type, ['email', 'search', 'tel', 'text', 'url'], true)) {
                $value = str_replace(["\r", "\n"], '', $value);
            }
            if (in_array($type, ['email', 'url'], true)) {
                $value = trim($value, "\x09\x0A\x0C\x0D\x20");
            }
            if ($type === 'number'
                && preg_match('/^-?(?:[0-9]+|[0-9]*\.[0-9]+)(?:e[+-]?[0-9]+)?$/iD', $value) !== 1
            ) {
                return '';
            }
            return $value;
        }
        if ($tag === 'select') {
            $options = [];
            foreach ($element->children() as $child) {
                if ($child->tagName() === 'option') {
                    $options[] = $child;
                } elseif ($child->tagName() === 'optgroup') {
                    foreach ($child->children() as $option) {
                        if ($option->tagName() === 'option') {
                            $options[] = $option;
                        }
                    }
                }
            }
            if ($options === []) {
                return '';
            }
            $options = array_values(array_filter(
                $options,
                static fn (\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode $option): bool =>
                    !self::generatedElementIsBrowserInert($option, $hiddenElements),
            ));
            if ($options === []) {
                return '';
            }
            $visible = $element->hasAttribute('multiple')
                ? $options
                : array_values(array_filter(
                    $options,
                    static fn (\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode $option): bool =>
                        $option->hasAttribute('selected'),
                ));
            if ($visible === []) {
                $visible = [$options[0]];
            }
            return implode(' ', array_map(
                static fn (\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode $option): string =>
                    (string) ($option->attribute('label') ?? $option->textContent()),
                $visible,
            ));
        }
        $text = '';
        foreach ($element->children() as $child) {
            $text .= self::generatedElementText($child, $hiddenElements);
        }
        return $text;
    }

    /** @param array{display?:array<int,true>,visibility?:array<int,true>,displayOverride?:array<int,true>} $hiddenElements */
    private static function generatedAssociatedControlText(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlNode $element,
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment $fragment,
        array $hiddenElements,
    ): string {
        $labelFor = $element->tagName() === 'label'
            ? trim((string) ($element->attribute('for') ?? ''))
            : '';
        $labelId = trim((string) ($element->attribute('id') ?? ''));
        if ($labelFor === '' && $labelId === '') {
            return '';
        }
        $text = '';
        foreach (self::generatedDescendantElements($fragment->root()) as $candidate) {
            if (!in_array($candidate->tagName(), ['input', 'output', 'select', 'textarea'], true)) {
                continue;
            }
            $associated = $labelFor !== '' && $candidate->attribute('id') === $labelFor;
            if (!$associated && $labelId !== '') {
                foreach (['aria-labelledby', 'aria-describedby'] as $attribute) {
                    $tokens = preg_split(
                        '/\s+/u',
                        trim((string) ($candidate->attribute($attribute) ?? '')),
                    ) ?: [];
                    if (in_array($labelId, $tokens, true)) {
                        $associated = true;
                        break;
                    }
                }
            }
            if ($associated) {
                $text .= self::generatedElementText($candidate, $hiddenElements);
            }
        }
        return $text;
    }

    /**
     * @return list<\Automattic\SiteBuild\BlockSerializer\Html\HtmlNode>
     */
    private static function generatedDescendantElements(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlNode $root,
    ): array {
        $elements = [];
        foreach ($root->children() as $child) {
            if ($child->isElement()) {
                $elements[] = $child;
            }
            array_push($elements, ...self::generatedDescendantElements($child));
        }
        return $elements;
    }

    /** @param array{display?:array<int,true>,visibility?:array<int,true>,displayOverride?:array<int,true>} $hiddenElements */
    private static function generatedElementIsBrowserInert(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlNode $element,
        array $hiddenElements,
    ): bool {
        if (isset($hiddenElements['visibility'][spl_object_id($element)])) {
            return true;
        }
        $node = $element;
        $branch = $element;
        while ($node !== null) {
            $tag = $node->tagName();
            $nodeId = spl_object_id($node);
            if (isset($hiddenElements['display'][$nodeId])
                || ($node->hasAttribute('hidden')
                    && !isset($hiddenElements['displayOverride'][$nodeId]))
                || in_array($tag, ['script', 'style', 'template', 'noscript'], true)
            ) {
                return true;
            }
            if ($tag === 'details' && !$node->hasAttribute('open')) {
                $summary = null;
                foreach ($node->elementChildren() as $child) {
                    if ($child->tagName() === 'summary') {
                        $summary = $child;
                        break;
                    }
                }
                if ($summary === null || $branch !== $summary) {
                    return true;
                }
            }
            $branch = $node;
            $node = $node->parent();
        }
        return false;
    }

    private static function generatedMarkupNeedsGroundingFallback(
        \Automattic\SiteBuild\BlockSerializer\Html\HtmlNode $element,
        string $markup,
    ): bool {
        if ($element->tagName() === 'label' || $element->hasAttribute('for')) {
            return trim((string) ($element->attribute('for') ?? '')) !== '';
        }
        $id = trim((string) ($element->attribute('id') ?? ''));
        return $id !== '' && preg_match(
            '/\baria-(?:labelledby|describedby)\s*=\s*(["\'])[^"\']*\b'
                . preg_quote($id, '/') . '\b[^"\']*\1/iu',
            $markup,
        ) === 1;
    }

    private static function generatedConditionalScopesCanOverlap(string $left, string $right): bool
    {
        if (self::generatedSelectorStatesConflict($left, $right)) {
            return false;
        }
        if (self::generatedMediaScopeHasAlternatives($left)
            || self::generatedMediaScopeHasAlternatives($right)
        ) {
            return true;
        }
        $leftOrientation = self::generatedMediaOrientation($left);
        $rightOrientation = self::generatedMediaOrientation($right);
        if ($leftOrientation !== null && $rightOrientation !== null
            && $leftOrientation !== $rightOrientation
        ) {
            return false;
        }
        $leftMediaType = self::generatedMediaType($left);
        $rightMediaType = self::generatedMediaType($right);
        if ($leftMediaType !== null && $rightMediaType !== null
            && $leftMediaType !== $rightMediaType
        ) {
            return false;
        }
        $leftFeatures = self::generatedDiscreteMediaFeatures($left);
        $rightFeatures = self::generatedDiscreteMediaFeatures($right);
        foreach (array_intersect(array_keys($leftFeatures), array_keys($rightFeatures)) as $feature) {
            if ($leftFeatures[$feature] !== $rightFeatures[$feature]) {
                return false;
            }
        }
        [$leftSupports, $leftNotSupports] = self::generatedSupportsPredicates($left);
        [$rightSupports, $rightNotSupports] = self::generatedSupportsPredicates($right);
        if (array_intersect($leftSupports, $rightNotSupports) !== []
            || array_intersect($rightSupports, $leftNotSupports) !== []
        ) {
            return false;
        }
        $leftBounds = self::generatedViewportBounds($left);
        $rightBounds = self::generatedViewportBounds($right);
        if ($leftBounds !== null && $rightBounds !== null) {
            foreach (['width', 'height'] as $dimension) {
                if (max($leftBounds['min-' . $dimension], $rightBounds['min-' . $dimension])
                    > min($leftBounds['max-' . $dimension], $rightBounds['max-' . $dimension])
                ) {
                    return false;
                }
            }
        }
        // An unrecognized condition may overlap; fail closed rather than
        // letting it hide a browser-reachable contact composition.
        return true;
    }

    /**
     * Enumerate every feasible active/inactive combination while bounded by
     * distinct reachable states. Implication collapses ordered media ranges,
     * so many breakpoints stay linear rather than becoming exponential.
     *
     * @param list<string> $scopes
     * @return ?list<list<string>>
     */
    public static function feasibleConditionScenarios(array $scopes, int $maximum = 16384): ?array
    {
        return self::generatedConditionScenarios($scopes, $maximum);
    }

    private static function generatedConditionScenarios(array $scopes, int $maximum = 16384): ?array
    {
        $scopeCount = count($scopes);
        if ($scopeCount > self::MAX_GENERATED_CONDITION_SCOPES) {
            return null;
        }
        $unconstrainedStates = $scopeCount >= 31 ? PHP_INT_MAX : 1 << $scopeCount;
        if ($unconstrainedStates >= $maximum) {
            $independentScopeCount = count(array_filter(
                $scopes,
                static fn (string $scope): bool => !str_starts_with(ltrim($scope), '@media'),
            ));
            $independentStates = $independentScopeCount >= 31
                ? PHP_INT_MAX
                : 1 << $independentScopeCount;
            if ($independentStates >= $maximum) {
                return null;
            }
            $hasImplication = false;
            foreach ($scopes as $left) {
                if (!str_starts_with(ltrim($left), '@media')) {
                    continue;
                }
                foreach ($scopes as $right) {
                    if ($left !== $right
                        && str_starts_with(ltrim($right), '@media')
                        && self::generatedConditionImplies($left, $right)
                    ) {
                        $hasImplication = true;
                        break 2;
                    }
                }
            }
            if (!$hasImplication) {
                return null;
            }
        }
        $scenarios = [[]];
        $seen = ['' => true];
        foreach ($scopes as $scope) {
            $existing = $scenarios;
            foreach ($existing as $scenario) {
                $candidate = self::generatedConditionScenarioClosure(
                    [...$scenario, $scope],
                    $scopes,
                );
                sort($candidate);
                $key = implode("\0\0", $candidate);
                if (!isset($seen[$key])
                    && self::generatedConditionalScopesCanAllOverlap($candidate)
                ) {
                    $seen[$key] = true;
                    $scenarios[] = $candidate;
                    if (count($scenarios) > $maximum) {
                        return null;
                    }
                }
            }
        }
        return $scenarios;
    }

    /** @param list<string> $scopes @return list<list<string>> */
    private static function generatedFallbackConditionScenarios(array $scopes): array
    {
        $scenarios = [[], self::generatedConditionScenarioClosure($scopes, $scopes)];
        foreach ($scopes as $scope) {
            $scenarios[] = self::generatedConditionScenarioClosure([$scope], $scopes);
        }
        for ($left = 0; $left < count($scopes); $left++) {
            for ($right = $left + 1; $right < count($scopes); $right++) {
                $candidate = self::generatedConditionScenarioClosure(
                    [$scopes[$left], $scopes[$right]],
                    $scopes,
                );
                if (self::generatedConditionalScopesCanAllOverlap($candidate)) {
                    $scenarios[] = $candidate;
                }
                if (count($scenarios) >= 4096) {
                    break 2;
                }
            }
        }
        for ($first = 0; $first < count($scopes); $first++) {
            for ($second = $first + 1; $second < count($scopes); $second++) {
                for ($third = $second + 1; $third < count($scopes); $third++) {
                    $candidate = self::generatedConditionScenarioClosure(
                        [$scopes[$first], $scopes[$second], $scopes[$third]],
                        $scopes,
                    );
                    if (self::generatedConditionalScopesCanAllOverlap($candidate)) {
                        $scenarios[] = $candidate;
                    }
                    if (count($scenarios) >= 4096) {
                        break 3;
                    }
                }
            }
        }
        $unique = [];
        foreach ($scenarios as $scenario) {
            sort($scenario);
            $unique[implode("\0\0", $scenario)] = $scenario;
        }
        return array_values($unique);
    }

    /**
     * Overflow contact composition only needs one active winner source per
     * paint channel. Enumerate those cross-channel conditions directly so a
     * large set of unrelated safe resets cannot crowd out the required tuple.
     *
     * Equivalent consecutive declarations retain only the latest condition in
     * that source-order run. A different declaration splits the run, preserving
     * every boundary where relative cascade order can change a winner.
     * If the reduced cross-product is still too large, return null so the
     * caller can remove the conditional content instead of sampling unsafely.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param list<string> $scopes
     * @return ?list<list<string>>
     */
    private static function generatedPseudoConditionScenarios(array $declarations, array $scopes): ?array
    {
        if (count($scopes) > self::MAX_GENERATED_FALLBACK_CONDITION_SCOPES) {
            return null;
        }
        $byPseudo = ['marker' => [], 'before' => [], 'after' => []];
        $order = 0;
        foreach ($declarations as $declaration) {
            $condition = (string) ($declaration['condition'] ?? '');
            $pseudo = (string) ($declaration['pseudo'] ?? '');
            if ($condition !== '' && isset($byPseudo[$pseudo])) {
                $rendered = (string) ($declaration['rendered'] ?? '');
                $priority = self::generatedContactFragmentPriority($rendered);
                $existing = $byPseudo[$pseudo][$condition] ?? null;
                $byPseudo[$pseudo][$condition] = [
                    'priority' => min($priority, $existing['priority'] ?? $priority),
                    'order' => $existing['order'] ?? $order++,
                    'signatures' => [
                        ...($existing['signatures'] ?? []),
                        implode("\0", [
                            $rendered,
                            (string) ($declaration['selector'] ?? ''),
                            (string) ($declaration['layer'] ?? ''),
                            ($declaration['important'] ?? false) ? 'important' : 'normal',
                        ]),
                    ],
                ];
            }
        }
        foreach ($byPseudo as &$conditions) {
            uasort(
                $conditions,
                static fn (array $left, array $right): int =>
                    [$left['priority'], $left['order']] <=> [$right['priority'], $right['order']],
            );
            if (array_filter(
                $conditions,
                static fn (array $condition): bool => $condition['priority'] === 0,
            ) !== []) {
                $conditions = array_filter(
                    $conditions,
                    static fn (array $condition): bool => $condition['priority'] === 0,
                );
            }
            $representatives = [];
            $previousFingerprint = null;
            $previousCondition = null;
            foreach ($conditions as $condition => $metadata) {
                sort($metadata['signatures']);
                $fingerprint = hash('sha256', implode("\0\0", $metadata['signatures']));
                if ($fingerprint === $previousFingerprint && $previousCondition !== null) {
                    unset($representatives[$previousCondition]);
                }
                $representatives[$condition] = $metadata;
                $previousFingerprint = $fingerprint;
                $previousCondition = $condition;
            }
            $conditions = $representatives;
        }
        unset($conditions);
        $scenarioCount = 1;
        foreach ($byPseudo as $conditions) {
            $choices = count($conditions) + 1;
            if ($scenarioCount > intdiv(self::MAX_GENERATED_CONDITION_SCENARIOS, $choices)) {
                return null;
            }
            $scenarioCount *= $choices;
        }
        $scopeWork = max(1, count($scopes));
        if ($scenarioCount > intdiv(
            self::MAX_GENERATED_CONDITION_SCENARIO_SCOPE_WORK,
            $scopeWork,
        )) {
            return null;
        }
        $scenarios = [];
        $seen = [];
        foreach ([null, ...array_keys($byPseudo['marker'])] as $marker) {
            foreach ([null, ...array_keys($byPseudo['before'])] as $before) {
                foreach ([null, ...array_keys($byPseudo['after'])] as $after) {
                    $active = array_values(array_filter(
                        [$marker, $before, $after],
                        is_string(...),
                    ));
                    if ($active === []) {
                        continue;
                    }
                    $candidate = self::generatedConditionScenarioClosure(
                        $active,
                        $scopes,
                    );
                    sort($candidate);
                    $key = implode("\0\0", $candidate);
                    if (!isset($seen[$key])
                        && self::generatedConditionalScopesCanAllOverlap($candidate)
                    ) {
                        $seen[$key] = true;
                        $scenarios[] = $candidate;
                        if (count($scenarios) >= self::MAX_GENERATED_CONDITION_SCENARIOS) {
                            return null;
                        }
                    }
                }
            }
        }
        return $scenarios;
    }

    private static function generatedContactFragmentPriority(string $rendered): int
    {
        if ((preg_match('/\p{N}/u', $rendered) === 1
                && preg_match('/^\s*[+\p{N}(). \/\-‐‑‒–−]+\s*$/uD', $rendered) === 1)
            || str_contains($rendered, '@')
            || preg_match('/(?:^|[\p{L}\p{N}])\.[\p{L}\p{N}]/u', $rendered) === 1
            || preg_match('~(?:https?|ftp):|[\\/]{2}~iu', $rendered) === 1
        ) {
            return 0;
        }
        return preg_match('/[\p{N}.:\/+]/u', $rendered) === 1 ? 1 : 2;
    }

    /** @param list<string> $scenario @param list<string> $scopes @return list<string> */
    private static function generatedConditionScenarioClosure(array $scenario, array $scopes): array
    {
        $active = array_fill_keys($scenario, true);
        do {
            $changed = false;
            foreach (array_keys($active) as $condition) {
                foreach ($scopes as $scope) {
                    if (isset($active[$scope])) {
                        continue;
                    }
                    if (self::generatedConditionImplies($condition, $scope)) {
                        $active[$scope] = true;
                        $changed = true;
                    }
                }
            }
        } while ($changed);
        return array_keys($active);
    }

    private static function generatedConditionImplies(string $active, string $required): bool
    {
        $activeTokens = explode("\0", $active);
        $activeBounds = self::generatedViewportBounds($active);
        foreach (explode("\0", $required) as $requiredToken) {
            if (in_array($requiredToken, $activeTokens, true)) {
                continue;
            }
            if (!str_starts_with(ltrim($requiredToken), '@media') || $activeBounds === null) {
                return false;
            }
            $requiredBounds = self::generatedViewportBounds($requiredToken);
            if ($requiredBounds === null) {
                return false;
            }
            $requiredOrientation = self::generatedMediaOrientation($requiredToken);
            if ($requiredOrientation !== null
                && self::generatedMediaOrientation($active) !== $requiredOrientation
            ) {
                return false;
            }
            foreach (['width', 'height'] as $dimension) {
                if ($activeBounds['min-' . $dimension] < $requiredBounds['min-' . $dimension]
                    || $activeBounds['max-' . $dimension] > $requiredBounds['max-' . $dimension]
                ) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @param list<string> $scopes */
    private static function generatedConditionalScopesCanAllOverlap(array $scopes): bool
    {
        for ($left = 0; $left < count($scopes); $left++) {
            for ($right = $left + 1; $right < count($scopes); $right++) {
                if (!self::generatedConditionalScopesCanOverlap($scopes[$left], $scopes[$right])) {
                    return false;
                }
            }
        }
        $bounds = [
            'min-width' => 0.0,
            'max-width' => INF,
            'min-height' => 0.0,
            'max-height' => INF,
        ];
        $orientation = null;
        foreach ($scopes as $scope) {
            if (self::generatedMediaScopeHasAlternatives($scope)) {
                continue;
            }
            foreach (explode("\0", $scope) as $ancestor) {
                if (preg_match('/^\s*@media\b(.*)$/isu', $ancestor, $media) !== 1) {
                    continue;
                }
                $query = self::generatedDecodeCssEscapes((string) $media[1]);
                if (preg_match('/\borientation\s*:\s*(portrait|landscape)\b/iu', $query, $match) === 1) {
                    $candidate = strtolower((string) $match[1]);
                    if ($orientation !== null && $orientation !== $candidate) {
                        return false;
                    }
                    $orientation = $candidate;
                }
                if (preg_match_all(
                    '/\b(min|max)-(width|height)\s*:\s*([0-9]+(?:\.[0-9]+)?)(px|em|rem)\b/iu',
                    $query,
                    $matches,
                    PREG_SET_ORDER,
                ) === false) {
                    continue;
                }
                foreach ($matches as $match) {
                    $kind = strtolower((string) $match[1]) . '-' . strtolower((string) $match[2]);
                    $value = (float) $match[3]
                        * (strtolower((string) $match[4]) === 'px' ? 1.0 : 16.0);
                    $bounds[$kind] = str_starts_with($kind, 'min-')
                        ? max($bounds[$kind], $value)
                        : min($bounds[$kind], $value);
                }
            }
        }
        foreach ($scopes as $scope) {
            $viewport = self::generatedViewportBounds($scope);
            if ($viewport === null) {
                continue;
            }
            foreach (['width', 'height'] as $dimension) {
                $bounds['min-' . $dimension] = max(
                    $bounds['min-' . $dimension],
                    $viewport['min-' . $dimension],
                );
                $bounds['max-' . $dimension] = min(
                    $bounds['max-' . $dimension],
                    $viewport['max-' . $dimension],
                );
            }
        }
        if ($bounds['min-width'] > $bounds['max-width']
            || $bounds['min-height'] > $bounds['max-height']
        ) {
            return false;
        }
        if ($orientation === 'portrait' && $bounds['max-height'] < $bounds['min-width']) {
            return false;
        }
        if ($orientation === 'landscape' && $bounds['max-width'] <= $bounds['min-height']) {
            return false;
        }
        return true;
    }

    private static function generatedMediaScopeHasAlternatives(string $scope): bool
    {
        foreach (explode("\0", $scope) as $ancestor) {
            if (preg_match('/^\s*@media\b(.*)$/isu', $ancestor, $media) !== 1) {
                continue;
            }
            $query = self::generatedDecodeCssEscapes((string) $media[1]);
            if (preg_match('/\b(?:not|or)\b/iu', $query) === 1
                || count(\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel(
                    $query,
                    [','],
                )) > 1
            ) {
                return true;
            }
        }
        return false;
    }

    private static function generatedDecodeCssEscapes(string $value): string
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
            $value,
        ) ?? $value;
    }

    private static function generatedSelectorStatesConflict(string $left, string $right): bool
    {
        $leftStates = self::generatedSelectorStatesByOwner($left);
        $rightStates = self::generatedSelectorStatesByOwner($right);
        $exclusive = [
            [':disabled', ':enabled'],
            [':valid', ':invalid'],
            [':required', ':optional'],
            [':read-only', ':read-write'],
            [':in-range', ':out-of-range'],
        ];
        foreach (array_intersect(array_keys($leftStates), array_keys($rightStates)) as $owner) {
            foreach ($leftStates[$owner] as $state) {
                $opposite = str_starts_with($state, '!') ? substr($state, 1) : '!' . $state;
                if (in_array($opposite, $rightStates[$owner], true)) {
                    return true;
                }
            }
            foreach ($exclusive as [$first, $second]) {
                if ((in_array($first, $leftStates[$owner], true)
                        && in_array($second, $rightStates[$owner], true))
                    || (in_array($second, $leftStates[$owner], true)
                        && in_array($first, $rightStates[$owner], true))
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<string,list<string>> */
    private static function generatedSelectorStatesByOwner(string $scope): array
    {
        $states = [];
        foreach (explode("\0", $scope) as $condition) {
            if (!str_starts_with($condition, '@selector-state ')) {
                continue;
            }
            foreach (explode(',', substr($condition, strlen('@selector-state '))) as $state) {
                [$owner, $predicate] = array_pad(explode('|', $state, 2), 2, '');
                if ($owner !== '' && $predicate !== '') {
                    $states[$owner][] = $predicate;
                }
            }
        }
        foreach ($states as $owner => $predicates) {
            $states[$owner] = array_values(array_unique($predicates));
        }
        return $states;
    }

    /**
     * @return array{min-width:float,max-width:float,min-height:float,max-height:float}|null
     */
    private static function generatedViewportBounds(string $scope): ?array
    {
        $bounds = [
            'min-width' => -INF,
            'max-width' => INF,
            'min-height' => -INF,
            'max-height' => INF,
        ];
        $sawViewportFeature = false;
        $number = '[+-]?(?:(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:e[+-]?[0-9]+)?)';
        $unit = 'px|em|rem';
        foreach (explode("\0", $scope) as $ancestor) {
            if (preg_match('/^\s*@media\b(.*)$/isu', $ancestor, $media) !== 1) {
                continue;
            }
            $query = self::generatedDecodeCssEscapes((string) $media[1]);
            if (str_contains($query, ',') || preg_match('/\b(?:not|or)\b/iu', $query) === 1) {
                return null;
            }
            if (preg_match_all(
                '/\b(min|max)-(width|height)\s*:\s*(' . $number . ')(' . $unit . ')\b/iu',
                $query,
                $legacy,
                PREG_SET_ORDER,
            ) === false) {
                return null;
            }
            foreach ($legacy as $match) {
                $sawViewportFeature = true;
                $kind = strtolower((string) $match[1]);
                $dimension = strtolower((string) $match[2]);
                $value = self::generatedViewportPixels((float) $match[3], (string) $match[4]);
                $key = $kind . '-' . $dimension;
                $bounds[$key] = $kind === 'min'
                    ? max($bounds[$key], $value)
                    : min($bounds[$key], $value);
            }
            if (preg_match_all(
                '/\b(width|height)\s*(<=|>=|=|<|>)\s*(' . $number . ')(' . $unit . ')\b/iu',
                $query,
                $featureFirst,
                PREG_SET_ORDER,
            ) === false || preg_match_all(
                '/(?<![\w.])(' . $number . ')(' . $unit . ')\s*(<=|>=|=|<|>)\s*(width|height)\b/iu',
                $query,
                $valueFirst,
                PREG_SET_ORDER,
            ) === false) {
                return null;
            }
            foreach ($featureFirst as $match) {
                $sawViewportFeature = true;
                self::generatedApplyViewportComparison(
                    $bounds,
                    strtolower((string) $match[1]),
                    (string) $match[2],
                    self::generatedViewportPixels((float) $match[3], (string) $match[4]),
                );
            }
            foreach ($valueFirst as $match) {
                $sawViewportFeature = true;
                $operator = match ((string) $match[3]) {
                    '<' => '>', '<=' => '>=', '>' => '<', '>=' => '<=',
                    '=' => '=',
                };
                self::generatedApplyViewportComparison(
                    $bounds,
                    strtolower((string) $match[4]),
                    $operator,
                    self::generatedViewportPixels((float) $match[1], (string) $match[2]),
                );
            }
            $query = preg_replace(
                '/\(\s*orientation\s*:\s*(?:portrait|landscape)\s*\)/iu',
                ' ',
                $query,
            ) ?? $query;
            $query = preg_replace(
                '/\([^()]*\b(?:width|height)\b[^()]*\)/iu',
                ' ',
                $query,
            ) ?? $query;
            $residual = preg_replace(
                '/\(\s*(?:min|max)-(?:width|height)\s*:\s*' . $number . '(?:' . $unit . ')\s*\)|'
                    . '\(\s*(?:(?:width|height)\s*(?:<=|>=|=|<|>)\s*' . $number . '(?:' . $unit . ')|'
                    . $number . '(?:' . $unit . ')\s*(?:<=|>=|=|<|>)\s*(?:width|height)'
                    . '(?:\s*(?:<=|>=|=|<|>)\s*' . $number . '(?:' . $unit . '))?)\s*\)|'
                    . '\(\s*orientation\s*:\s*(?:portrait|landscape)\s*\)|'
                    . '\b(?:only\s+)?(?:all|screen)\b|\band\b|[\s()]+/iu',
                '',
                $query,
            );
            if (!is_string($residual) || $residual !== '') {
                return null;
            }
        }
        return $sawViewportFeature ? $bounds : null;
    }

    private static function generatedViewportPixels(float $value, string $unit): float
    {
        return $value * (strtolower($unit) === 'px' ? 1.0 : 16.0);
    }

    /** @param array<string,float> $bounds */
    private static function generatedApplyViewportComparison(
        array &$bounds,
        string $dimension,
        string $operator,
        float $value,
    ): void {
        if ($operator === '=') {
            $bounds['min-' . $dimension] = max($bounds['min-' . $dimension], $value);
            $bounds['max-' . $dimension] = min($bounds['max-' . $dimension], $value);
            return;
        }
        if ($operator === '>=' || $operator === '>') {
            $bounds['min-' . $dimension] = max(
                $bounds['min-' . $dimension],
                $operator === '>' ? $value + 1.0e-9 : $value,
            );
            return;
        }
        $bounds['max-' . $dimension] = min(
            $bounds['max-' . $dimension],
            $operator === '<' ? $value - 1.0e-9 : $value,
        );
    }

    private static function generatedMediaOrientation(string $scope): ?string
    {
        $orientation = null;
        foreach (explode("\0", $scope) as $ancestor) {
            if (preg_match('/^\s*@media\b/iu', $ancestor) !== 1) {
                continue;
            }
            if (preg_match_all(
                '/\borientation\s*:\s*(landscape|portrait)\b/iu',
                $ancestor,
                $matches,
            ) === false) {
                return null;
            }
            foreach ($matches[1] as $value) {
                $value = strtolower((string) $value);
                if ($orientation !== null && $orientation !== $value) {
                    return null;
                }
                $orientation = $value;
            }
        }
        return $orientation;
    }

    private static function generatedMediaType(string $scope): ?string
    {
        $type = null;
        foreach (explode("\0", $scope) as $ancestor) {
            if (preg_match('/^\s*@media\s+(?:only\s+)?(screen|print|speech)\b/iu', $ancestor, $match) !== 1) {
                continue;
            }
            $candidate = strtolower((string) $match[1]);
            if ($type !== null && $type !== $candidate) {
                return null;
            }
            $type = $candidate;
        }
        return $type;
    }

    /** @return array<string,string> */
    private static function generatedDiscreteMediaFeatures(string $scope): array
    {
        $features = [];
        foreach (explode("\0", $scope) as $ancestor) {
            if (preg_match('/^\s*@media\b/iu', $ancestor) !== 1) {
                continue;
            }
            if (preg_match_all(
                '/\b([a-z][\w-]*)\s*:\s*([a-z][\w-]*)\b/iu',
                $ancestor,
                $matches,
            ) === false) {
                continue;
            }
            foreach ($matches[1] as $index => $name) {
                $name = strtolower((string) $name);
                $value = strtolower((string) $matches[2][$index]);
                if (isset($features[$name]) && $features[$name] !== $value) {
                    unset($features[$name]);
                    continue;
                }
                $features[$name] = $value;
            }
        }
        return $features;
    }

    /** @return array{0:list<string>,1:list<string>} */
    private static function generatedSupportsPredicates(string $scope): array
    {
        $positive = [];
        $negative = [];
        foreach (explode("\0", $scope) as $ancestor) {
            if (preg_match('/^\s*@supports\b(.*)$/isu', $ancestor, $supports) !== 1) {
                continue;
            }
            $predicate = trim((string) $supports[1]);
            $negated = preg_match('/^not\b/iu', $predicate) === 1;
            if ($negated) {
                $predicate = preg_replace('/^not\b/iu', '', $predicate, 1) ?? $predicate;
            }
            $predicate = preg_replace('/\s+/u', '', trim($predicate, " \t\n\r\f()")) ?? $predicate;
            if ($predicate === '') {
                continue;
            }
            if ($negated) {
                $negative[] = strtolower($predicate);
            } else {
                $positive[] = strtolower($predicate);
            }
        }
        return [array_values(array_unique($positive)), array_values(array_unique($negative))];
    }

    private static function generatedSelectorSubjectsCompatible(string $left, string $right): bool
    {
        $left = self::generatedSelectorSubjectProfile($left);
        $right = self::generatedSelectorSubjectProfile($right);
        if ($left['tag'] !== null && $right['tag'] !== null && $left['tag'] !== $right['tag']) {
            return false;
        }
        if ($left['id'] !== null && $right['id'] !== null) {
            return $left['id'] === $right['id'];
        }
        if (array_intersect($left['classes'], $right['classes']) !== []) {
            return true;
        }
        return $left['tag'] !== null && $right['tag'] !== null && $left['tag'] === $right['tag'];
    }

    /** @param array{context:string,ancestors:list<string>} $declaration */
    private static function generatedNumericFragmentIsNamedByMarkup(
        string $rendered,
        array $declaration,
        string $markup,
    ): bool {
        $standalone = self::generatedContactCandidates('content', $rendered);
        if (count($standalone) !== 1 || $standalone[0]['type'] !== 'generated_text') {
            return false;
        }
        $fragment = \Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment::parse($markup);
        $matched = false;
        foreach (self::generatedContentSubjects($declaration) as $subject) {
            try {
                $elements = $fragment->querySelectorAll($subject['selector']);
            } catch (\Throwable) {
                return false;
            }
            foreach ($elements as $element) {
                $matched = true;
                $combined = $subject['pseudo'] === 'after'
                    ? $element->textContent() . ' ' . $rendered
                    : $rendered . ' ' . $element->textContent();
                if (self::generatedContactCandidates('content', $combined) !== []) {
                    return false;
                }
            }
        }
        return $matched;
    }

    /** @return array{tag:?string,id:?string,classes:list<string>} */
    private static function generatedSelectorSubjectProfile(string $selector): array
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
            } elseif ($depth === 0 && ($byte === '>' || $byte === '+' || $byte === '~' || ctype_space($byte))) {
                $start = $index + 1;
            }
        }
        $compound = ltrim(substr($selector, $start));
        $tag = null;
        if (preg_match('/^(?:[a-z_][\w-]*\|)?([a-z_][\w-]*)/iu', $compound, $tagMatch) === 1) {
            $tag = mb_strtolower((string) $tagMatch[1]);
        }
        $id = null;
        if (preg_match('/(?<!\\\\)#([a-z_][\w-]*)/iu', $compound, $idMatch) === 1) {
            $id = (string) $idMatch[1];
        }
        preg_match_all('/(?<!\\\\)\.([a-z_][\w-]*)/iu', $compound, $classMatches);
        $classes = array_values(array_unique(array_map(
            mb_strtolower(...),
            $classMatches[1] ?? [],
        )));
        return ['tag' => $tag, 'id' => $id, 'classes' => $classes];
    }

    /**
     * Remove declarations whose embedded SVG paints or links to an ungrounded
     * contact fact.
     *
     * @param array<string,array<string,true>> $allowed
     * @return array{css:string,removals:list<array{kind:string,authored_value:string,delivered_value:string,disposition:string}>}
     */
    public static function scrubSvgDataContacts(string $css, array $allowed): array
    {
        [$repaired, $dropped] = CssChecks::dropDeclarations(
            $css,
            static function (array $declaration) use ($allowed): bool {
                if ($declaration['kind'] !== 'style') {
                    return false;
                }
                foreach (self::resourceUrls($declaration['value']) as $url) {
                    if (ContactFacts::ungroundedAgainstSet(
                        GroundedContactMarkup::svgDataCandidates($url),
                        $allowed,
                    ) !== []) {
                        return true;
                    }
                }
                return false;
            },
        );
        return [
            'css' => $repaired,
            'removals' => array_map(
                static fn (array $declaration): array => [
                    'kind' => 'ungrounded_svg_data_contact',
                    'authored_value' => $declaration['raw'],
                    'delivered_value' => 'removed',
                    'disposition' => 'removed_ungrounded_contact',
                ],
                $dropped,
            ),
        ];
    }

    /**
     * Remove author CSS that overrides the browser's hidden-state display rule.
     * Generated copy inside a hidden element must not become visible later.
     *
     * @return array{
     *     css:string,
     *     removals:list<array{kind:string,authored_value:string,delivered_value:string,disposition:string}>
     * }
     */
    public static function scrubHiddenReveals(string $css): array
    {
        [$repaired, $dropped] = CssChecks::dropDeclarations(
            $css,
            static function (array $declaration): bool {
                if ($declaration['kind'] !== 'style') {
                    return false;
                }
                $selector = self::decodeCssEscapes($declaration['context']);
                if ($selector === null
                    || preg_match('/\[\s*hidden(?:\s*(?:[~|^$*]?=)[^\]]*)?\]/iu', $selector) !== 1
                ) {
                    return false;
                }
                if (strtolower($declaration['property']) === 'all') {
                    return true;
                }
                if (strtolower($declaration['property']) !== 'display') {
                    return false;
                }
                $value = trim(preg_replace('/\s*!important\s*$/iu', '', $declaration['value'])
                    ?? $declaration['value']);
                return strtolower($value) !== 'none';
            },
        );
        return [
            'css' => $repaired,
            'removals' => array_map(
                static fn (array $declaration): array => [
                    'kind' => 'hidden_state_reveal',
                    'authored_value' => $declaration['raw'],
                    'delivered_value' => 'removed',
                    'disposition' => 'removed_hidden_state_reveal',
                ],
                $dropped,
            ),
        ];
    }

    /**
     * Apply every generated-CSS delivery guard and combine their receipts.
     *
     * @param array<string,array<string,true>> $allowed
     * @return array{css:string,removals:list<array{kind:string,authored_value:string,delivered_value:string,disposition:string}>}
     */
    public static function scrubGenerated(
        string $css,
        array $allowed,
        bool $bareDeclarationList = false,
        string $markup = '',
    ): array
    {
        if ($bareDeclarationList) {
            $prefix = ':root{';
            $wrapped = self::scrubGenerated($prefix . $css . '}', $allowed, false, $markup);
            if (!str_starts_with($wrapped['css'], $prefix) || !str_ends_with($wrapped['css'], '}')) {
                return ['css' => '', 'removals' => $wrapped['removals']];
            }
            return [
                'css' => substr($wrapped['css'], strlen($prefix), -1),
                'removals' => $wrapped['removals'],
            ];
        }
        $result = self::scrub($css);
        $indirect = self::scrubResourceIndirection($result['css']);
        $embedded = self::scrubSvgDataContacts($indirect['css'], $allowed);
        $contact = self::scrubContactContent($embedded['css'], $allowed, $markup);
        $hidden = self::scrubHiddenReveals($contact['css']);
        return [
            'css' => $hidden['css'],
            'removals' => array_merge(
                $result['removals'],
                $indirect['removals'],
                $embedded['removals'],
                $contact['removals'],
                $hidden['removals'],
            ),
        ];
    }

    private static function hasUnresolvedResourceValue(string $property, string $value): bool
    {
        if (!self::containsIndirection($value)) {
            return false;
        }
        $decoded = self::decodeCssEscapes($value);
        if ($decoded !== null && CssChecks::resourceLoadingProblem($decoded) !== null) {
            return true;
        }
        // These properties cannot consume an ordinary color/length token;
        // an unresolved whole-value substitution may therefore hide a URL.
        return in_array($property, [
            '-webkit-backdrop-filter', '-webkit-box-reflect', '-webkit-filter', '-webkit-mask',
            '-webkit-mask-box-image', '-webkit-mask-box-image-source', '-webkit-mask-image',
            'backdrop-filter', 'background-image', 'border-image', 'border-image-source',
            'clip-path', 'cursor', 'filter', 'list-style-image', 'marker', 'marker-end',
            'marker-mid', 'marker-start', 'mask', 'mask-border', 'mask-border-source', 'mask-image',
            'motion-path', 'offset-path', 'shape-outside', 'src',
        ], true);
    }

    private static function containsIndirection(string $value): bool
    {
        $value = self::decodeCssEscapes($value);
        return $value !== null && preg_match(
            '/(?<![-\w])(?:attr|env|var)(?:\s|\/\*.*?\*\/)*\(/isu',
            $value,
        ) === 1;
    }

    private static function hasUnresolvedGeneratedTextValue(string $property, string $value): bool
    {
        if (self::containsIndirection($value)) {
            return true;
        }
        $decoded = self::decodeCssEscapes($value);
        if ($decoded === null) {
            return false;
        }
        if ($property === 'content' && preg_match(
            '/(?<![-\w])counters?(?:\s|\/\*.*?\*\/)*\(/isu',
            $decoded,
        ) === 1) {
            return true;
        }
        if (!in_array($property, ['list-style', 'list-style-type'], true)) {
            return false;
        }
        foreach (self::topLevelIdentifiers($decoded) as $identifier) {
            if (!in_array($identifier, self::BUILT_IN_LIST_STYLE_KEYWORDS, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function topLevelIdentifiers(string $value): array
    {
        $identifiers = [];
        $length = strlen($value);
        $depth = 0;
        for ($offset = 0; $offset < $length;) {
            if (self::startsComment($value, $offset)) {
                $offset = self::commentEnd($value, $offset);
                continue;
            }
            $byte = $value[$offset];
            if ($byte === '"' || $byte === "'") {
                $offset = self::stringEnd($value, $offset);
                continue;
            }
            if ($byte === '(' || $byte === '[') {
                $depth++;
                $offset++;
                continue;
            }
            if ($byte === ')' || $byte === ']') {
                $depth = max(0, $depth - 1);
                $offset++;
                continue;
            }
            if ($depth !== 0) {
                $offset++;
                continue;
            }
            $identifier = self::identifierAt($value, $offset);
            if ($identifier === null) {
                $offset++;
                continue;
            }
            $after = self::skipWhitespaceAndComments($value, $identifier['end']);
            if ($after >= $length || $value[$after] !== '(') {
                $identifiers[] = strtolower($identifier['value']);
            }
            $offset = $identifier['end'];
        }
        return $identifiers;
    }

    private static function contentText(string $css, int $start, int $end): string
    {
        $offset = self::skipWhitespaceAndComments($css, $start);
        $identifier = self::identifierAt($css, $offset);
        if ($identifier === null) {
            return '';
        }
        $offset = self::skipWhitespaceAndComments($css, $identifier['end']);
        if ($offset >= $end || $css[$offset] !== ':') {
            return '';
        }
        $offset++;
        $functions = [];
        $text = '';
        while ($offset < $end) {
            if (self::startsComment($css, $offset)) {
                $offset = self::commentEnd($css, $offset);
                continue;
            }
            $byte = $css[$offset];
            if ($byte === '"' || $byte === "'") {
                $stringEnd = self::completeStringEnd($css, $offset);
                if ($stringEnd === null || $stringEnd > $end) {
                    $offset = self::stringEnd($css, $offset);
                    continue;
                }
                $decoded = self::decodeCssEscapes(
                    substr($css, $offset + 1, $stringEnd - $offset - 2),
                );
                if ($decoded !== null && !in_array('url', $functions, true)) {
                    $text .= $decoded;
                }
                $offset = $stringEnd;
                continue;
            }
            $function = self::identifierAt($css, $offset);
            if ($function !== null) {
                $afterFunction = self::skipWhitespaceAndComments($css, $function['end']);
                if ($afterFunction < $end && $css[$afterFunction] === '(') {
                    $functions[] = strtolower($function['value']);
                    $offset = $afterFunction + 1;
                    continue;
                }
            }
            if ($byte === '(' || $byte === '[') {
                $functions[] = '';
            } elseif (($byte === ')' || $byte === ']') && $functions !== []) {
                array_pop($functions);
            }
            $offset++;
        }
        return $text;
    }

    private static function declarationName(string $css, int $start, int $end): string
    {
        $offset = self::skipWhitespaceAndComments($css, $start);
        $identifier = self::identifierAt($css, $offset);
        if ($identifier === null || $identifier['end'] > $end) {
            return '';
        }
        $colon = self::skipWhitespaceAndComments($css, $identifier['end']);
        return $colon < $end && $css[$colon] === ':' ? strtolower($identifier['value']) : '';
    }

    private static function importIdentifierEndAt(string $css, int $offset): ?int
    {
        $identifier = self::identifierAt($css, $offset + 1);
        return $identifier !== null && strcasecmp($identifier['value'], 'import') === 0
            ? $identifier['end']
            : null;
    }

    private static function atRuleStatementEnd(string $css, int $offset): ?int
    {
        $length = strlen($css);
        $parentheses = 0;
        $brackets = 0;
        $braceInsideNesting = false;

        while ($offset < $length) {
            if (self::startsComment($css, $offset)) {
                $commentEnd = strpos($css, '*/', $offset + 2);
                if ($commentEnd === false) {
                    return $length;
                }
                $offset = $commentEnd + 2;
                continue;
            }

            $byte = $css[$offset];
            if ($byte === '"' || $byte === "'") {
                $stringEnd = self::completeStringEnd($css, $offset);
                if ($stringEnd === null) {
                    return self::hasRuleBrace($css, $offset + 1) ? null : $length;
                }
                $offset = $stringEnd;
                continue;
            }
            if ($byte === '\\') {
                $escapeEnd = CssSyntaxScanner::escapeEnd($css, $offset);
                if ($escapeEnd === null) {
                    return null;
                }
                $offset = $escapeEnd;
                continue;
            }
            if ($byte === '(') {
                $parentheses++;
            } elseif ($byte === ')') {
                if ($parentheses > 0) {
                    $parentheses--;
                }
            } elseif ($byte === '[') {
                $brackets++;
            } elseif ($byte === ']') {
                if ($brackets > 0) {
                    $brackets--;
                }
            } elseif ($parentheses === 0 && $brackets === 0 && $byte === ';') {
                return $offset + 1;
            } elseif ($byte === '{' || $byte === '}') {
                if ($parentheses === 0 && $brackets === 0) {
                    return null;
                }
                $braceInsideNesting = true;
            }

            $offset++;
        }

        return ($parentheses !== 0 || $brackets !== 0) && $braceInsideNesting
            ? null
            : $length;
    }

    /**
     * @return array{end:int,value:string}|null
     */
    private static function urlAt(string $css, int $offset): ?array
    {
        $length = strlen($css);
        if (
            $offset > 0
            && (self::isIdentifierByte($css[$offset - 1]) || ord($css[$offset - 1]) >= 0x80)
        ) {
            return null;
        }

        $identifier = self::identifierAt($css, $offset);
        if ($identifier === null || strcasecmp($identifier['value'], 'url') !== 0) {
            return null;
        }

        // A function token requires `(` immediately after the identifier;
        // comments may disappear lexically, but CSS whitespace may not.
        $cursor = self::skipComments($css, $identifier['end']);
        if ($cursor >= $length || $css[$cursor] !== '(') {
            return null;
        }
        $cursor = self::skipWhitespaceAndComments($css, $cursor + 1);
        if ($cursor >= $length) {
            return null;
        }

        if ($css[$cursor] === '"' || $css[$cursor] === "'") {
            $quote = $css[$cursor];
            $valueStart = $cursor + 1;
            $cursor++;

            while ($cursor < $length) {
                if ($css[$cursor] === '\\') {
                    $escapeEnd = self::decodedEscapeEnd($css, $cursor);
                    if ($escapeEnd === null) {
                        return null;
                    }
                    $cursor = $escapeEnd;
                    continue;
                }
                if ($css[$cursor] === $quote) {
                    $value = substr($css, $valueStart, $cursor - $valueStart);
                    $cursor = self::skipWhitespaceAndComments($css, $cursor + 1);
                    if ($cursor < $length && $css[$cursor] === ')') {
                        return ['end' => $cursor + 1, 'value' => trim($value)];
                    }
                    return null;
                }
                if ($css[$cursor] === "\n" || $css[$cursor] === "\r" || $css[$cursor] === "\f") {
                    return null;
                }
                $cursor++;
            }

            return null;
        }

        $valueStart = $cursor;
        while ($cursor < $length) {
            $byte = $css[$cursor];
            if ($byte === '\\') {
                if (in_array($css[$cursor + 1] ?? '', ["\n", "\r", "\f"], true)) {
                    return null;
                }
                $escapeEnd = self::decodedEscapeEnd($css, $cursor);
                if ($escapeEnd === null) {
                    return null;
                }
                $cursor = $escapeEnd;
                continue;
            }
            if ($byte === ')') {
                return [
                    'end'   => $cursor + 1,
                    'value' => trim(substr($css, $valueStart, $cursor - $valueStart)),
                ];
            }
            if ($byte === '{' || $byte === '}') {
                return null;
            }
            if ($byte === '"' || $byte === "'" || $byte === '(') {
                return null;
            }
            if (str_contains("\t\n\f\r ", $byte)) {
                $valueEnd = $cursor;
                $cursor = self::skipWhitespaceAndComments($css, $cursor);
                if ($cursor < $length && $css[$cursor] === ')') {
                    return [
                        'end' => $cursor + 1,
                        'value' => trim(substr($css, $valueStart, $valueEnd - $valueStart)),
                    ];
                }
                return null;
            }
            $cursor++;
        }

        return null;
    }

    /**
     * @return array{end:int,value:string}|null
     */
    private static function identifierAt(string $css, int $offset): ?array
    {
        $length = strlen($css);
        $start = $offset;

        while ($offset < $length) {
            if ($css[$offset] === '\\') {
                $escaped = $css[$offset + 1] ?? null;
                if (
                    $escaped === null
                    || $escaped === "\n"
                    || $escaped === "\r"
                    || $escaped === "\f"
                ) {
                    return null;
                }

                $end = CssSyntaxScanner::escapeEnd($css, $offset);
                if ($end === null) {
                    return null;
                }
                $offset = $end;
                continue;
            }

            if (!self::isIdentifierByte($css[$offset]) && ord($css[$offset]) < 0x80) {
                break;
            }
            $offset++;
        }

        if ($offset === $start) {
            return null;
        }

        $decoded = self::decodeCssEscapes(substr($css, $start, $offset - $start));
        return $decoded === null ? null : ['end' => $offset, 'value' => $decoded];
    }

    private static function startsHashOrAtKeyword(string $css, int $offset): bool
    {
        return $offset > 0 && ($css[$offset - 1] === '#' || $css[$offset - 1] === '@');
    }

    private static function isExternalUrl(string $url): bool
    {
        $decoded = self::decodeCssEscapes($url);
        if ($decoded === null) {
            return false;
        }
        $decoded = str_replace(["\t", "\n", "\r"], '', $decoded);
        $decoded = ltrim($decoded, "\x00..\x20");
        return preg_match('/^https?:/i', $decoded) === 1
            || (
                isset($decoded[1])
                && ($decoded[0] === '/' || $decoded[0] === '\\')
                && ($decoded[1] === '/' || $decoded[1] === '\\')
            );
    }

    private static function stringCanBeUrlIn(string $function): bool
    {
        return in_array(
            strtolower($function),
            ['image-set', '-webkit-image-set', 'image', 'cross-fade', 'if', 'src'],
            true,
        );
    }

    /** Decode CSS escapes with the same scanner used by the external-resource scrubber. */
    public static function decodeCssEscapes(string $value): ?string
    {
        $decoded = '';
        $length = strlen($value);

        for ($offset = 0; $offset < $length;) {
            if ($value[$offset] !== '\\') {
                $decoded .= $value[$offset] === "\0" ? "\u{FFFD}" : $value[$offset];
                $offset++;
                continue;
            }

            $end = self::decodedEscapeEnd($value, $offset);
            if ($end === null) {
                return null;
            }

            $escapedOffset = $offset + 1;
            $escaped = $value[$escapedOffset];
            if (!ctype_xdigit($escaped)) {
                if ($escaped !== "\n" && $escaped !== "\r" && $escaped !== "\f") {
                    $decoded .= $escaped;
                }
                $offset = $end;
                continue;
            }

            $hexEnd = $escapedOffset;
            while (
                $hexEnd < $length
                && $hexEnd < $escapedOffset + 6
                && ctype_xdigit($value[$hexEnd])
            ) {
                $hexEnd++;
            }
            $codePoint = hexdec(substr($value, $escapedOffset, $hexEnd - $escapedOffset));
            $decoded .= $codePoint === 0
                || $codePoint > 0x10ffff
                || ($codePoint >= 0xd800 && $codePoint <= 0xdfff)
                    ? "\u{FFFD}"
                    : mb_chr($codePoint, 'UTF-8');
            $offset = $end;
        }

        return $decoded;
    }

    private static function decodedEscapeEnd(string $value, int $offset): ?int
    {
        $length = strlen($value);
        if ($offset >= $length || $value[$offset] !== '\\' || $offset + 1 >= $length) {
            return null;
        }
        $next = $offset + 1;
        if (!ctype_xdigit($value[$next])) {
            if ($value[$next] === "\r" && $next + 1 < $length && $value[$next + 1] === "\n") {
                return $next + 2;
            }
            return $next + 1;
        }
        $end = $next;
        while ($end < $length && $end < $next + 6 && ctype_xdigit($value[$end])) {
            $end++;
        }
        if ($end < $length && str_contains(" \t\n\r\f", $value[$end])) {
            if ($value[$end] === "\r" && $end + 1 < $length && $value[$end + 1] === "\n") {
                return $end + 2;
            }
            return $end + 1;
        }
        return $end;
    }

    /** Best-effort selector path for an already-isolated declaration. */
    private static function selectorBeforeDeclaration(string $css, int $declarationStart): string
    {
        $prefix = substr($css, 0, $declarationStart);
        $open = strrpos($prefix, '{');
        if ($open === false) {
            return '<declaration-list>';
        }
        $before = substr($prefix, 0, $open);
        $previousOpen = strrpos($before, '{');
        $previousClose = strrpos($before, '}');
        $boundary = max(
            $previousOpen === false ? -1 : $previousOpen,
            $previousClose === false ? -1 : $previousClose,
        );
        $selector = trim(substr($before, $boundary + 1));
        return $selector === '' ? '<declaration>' : $selector;
    }

    /**
     * @return array{start:int,end:int}|null
     */
    private static function declarationRange(string $css, int $urlOffset): ?array
    {
        $length = strlen($css);
        $segmentStart = 0;
        $braceDepth = 0;
        $parentheses = 0;

        for ($offset = 0; $offset < $urlOffset;) {
            if (self::startsComment($css, $offset)) {
                $offset = self::commentEnd($css, $offset);
                continue;
            }

            $byte = $css[$offset];
            if ($byte === '"' || $byte === "'") {
                $offset = self::stringEnd($css, $offset);
                continue;
            }
            if ($byte === '{') {
                $parentheses = 0;
                $braceDepth++;
                $segmentStart = $offset + 1;
            } elseif ($byte === '}') {
                $parentheses = 0;
                $braceDepth = max(0, $braceDepth - 1);
                $segmentStart = $offset + 1;
            } elseif ($byte === '(') {
                $parentheses++;
            } elseif ($byte === ')' && $parentheses > 0) {
                $parentheses--;
            } elseif ($parentheses === 0 && $byte === ';') {
                $segmentStart = $offset + 1;
            }
            $offset++;
        }

        if ($braceDepth === 0) {
            return null;
        }

        $start = self::skipWhitespaceAndComments($css, $segmentStart);
        $colon = null;
        $parentheses = 0;

        for ($offset = $start; $offset < $length;) {
            if (self::startsComment($css, $offset)) {
                $offset = self::commentEnd($css, $offset);
                continue;
            }

            $byte = $css[$offset];
            if ($byte === '"' || $byte === "'") {
                $offset = self::stringEnd($css, $offset);
                continue;
            }
            if ($byte === '(') {
                $parentheses++;
            } elseif ($byte === ')') {
                if ($parentheses === 0) {
                    return null;
                }
                $parentheses--;
            } elseif ($parentheses === 0 && $byte === ':' && $colon === null) {
                $colon = $offset;
            } elseif ($byte === '{') {
                return null;
            } elseif ($parentheses === 0 && $byte === ';') {
                if ($colon === null || $colon >= $urlOffset || $urlOffset >= $offset) {
                    return null;
                }
                return ['start' => $start, 'end' => $offset + 1];
            } elseif ($byte === '}') {
                if ($parentheses !== 0) {
                    return null;
                }
                if ($colon === null || $colon >= $urlOffset || $urlOffset >= $offset) {
                    return null;
                }
                return ['start' => $start, 'end' => $offset];
            }

            $offset++;
        }

        if ($colon === null || $colon >= $urlOffset || $urlOffset >= $length) {
            return null;
        }

        return ['start' => $start, 'end' => $length];
    }

    private static function startsComment(string $css, int $offset): bool
    {
        return isset($css[$offset + 1]) && $css[$offset] === '/' && $css[$offset + 1] === '*';
    }

    private static function hasRuleBrace(string $css, int $offset): bool
    {
        return strpos($css, '{', $offset) !== false || strpos($css, '}', $offset) !== false;
    }

    private static function commentEnd(string $css, int $offset): int
    {
        $end = strpos($css, '*/', $offset + 2);
        return $end === false ? strlen($css) : $end + 2;
    }

    private static function stringEnd(string $css, int $offset): int
    {
        $length = strlen($css);
        $quote = $css[$offset];
        $offset++;

        while ($offset < $length) {
            if ($css[$offset] === '\\') {
                $escapeEnd = CssSyntaxScanner::escapeEnd($css, $offset);
                if ($escapeEnd === null) {
                    return $length;
                }
                $offset = $escapeEnd;
                continue;
            }
            if ($css[$offset] === $quote) {
                return $offset + 1;
            }
            if ($css[$offset] === "\n" || $css[$offset] === "\r" || $css[$offset] === "\f") {
                return $offset;
            }
            $offset++;
        }

        return $length;
    }

    private static function completeStringEnd(string $css, int $offset): ?int
    {
        $length = strlen($css);
        $quote = $css[$offset];
        $offset++;

        while ($offset < $length) {
            if ($css[$offset] === '\\') {
                $escapeEnd = CssSyntaxScanner::escapeEnd($css, $offset);
                if ($escapeEnd === null) {
                    return null;
                }
                $offset = $escapeEnd;
                continue;
            }
            if ($css[$offset] === $quote) {
                return $offset + 1;
            }
            if ($css[$offset] === "\n" || $css[$offset] === "\r" || $css[$offset] === "\f") {
                return null;
            }
            $offset++;
        }

        return null;
    }

    private static function skipWhitespaceAndComments(string $css, int $offset): int
    {
        $length = strlen($css);

        while ($offset < $length) {
            while ($offset < $length && str_contains(" \t\r\n\f", $css[$offset])) {
                $offset++;
            }
            if (!self::startsComment($css, $offset)) {
                break;
            }
            $offset = self::commentEnd($css, $offset);
        }

        return $offset;
    }

    private static function skipComments(string $css, int $offset): int
    {
        while (self::startsComment($css, $offset)) {
            $offset = self::commentEnd($css, $offset);
        }

        return $offset;
    }

    private static function isIdentifierByte(string $byte): bool
    {
        return ctype_alnum($byte) || $byte === '_' || $byte === '-';
    }
}
