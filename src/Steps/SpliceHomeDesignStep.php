<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Re-composes the homepage from the authored fold and below-fold body.
 *
 * Generated-content defects degrade at this boundary: a usable raw body or
 * fold is delivered with an actionable warning. I/O failures remain fatal.
 */
final class SpliceHomeDesignStep implements Step
{
    private const PREVIEW_PATH = 'design/preview.html';
    private const BODY_PATH = 'design/home-body.html';
    private const HOME_PATH = 'design/home.html';

    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private const RAW_TEXT_ELEMENTS = ['script', 'style', 'title', 'textarea'];

    public function id(): string
    {
        return 'splice-home-design';
    }

    public function label(): string
    {
        return 'Splice homepage design';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [self::PREVIEW_PATH, self::BODY_PATH],
            writes: [self::HOME_PATH, 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $preview = $project->exists(self::PREVIEW_PATH)
            ? $project->readText(self::PREVIEW_PATH)
            : '';
        $body = $project->exists(self::BODY_PATH)
            ? $project->readText(self::BODY_PATH)
            : '';

        $previewParts = self::previewParts($preview);
        $bodyParts = self::bodyParts($body);
        if ($previewParts !== null && $bodyParts !== null) {
            $project->writeText(
                self::HOME_PATH,
                self::compose($preview, $body, $previewParts, $bodyParts),
            );
            return;
        }

        if ($previewParts === null && trim($body) !== '') {
            $delivered = $body;
            $reason = 'fold missing header, section#hero, or a closed document envelope';
            $disposition = 'retained raw home body';
        } elseif ($previewParts !== null) {
            $delivered = $preview;
            $reason = 'home-body missing, empty, or malformed';
            $disposition = 'retained header and hero only';
        } elseif (trim($body) !== '') {
            $delivered = $body;
            $reason = 'fold and home-body malformed';
            $disposition = 'retained raw home body';
        } else {
            $delivered = '<!doctype html><html><body><main></main></body></html>';
            $reason = 'fold malformed and home-body missing, empty, or malformed';
            $disposition = 'degraded to deterministic empty front-page shell';
        }

        $project->writeText(self::HOME_PATH, $delivered);
        $project->addWarnings($this->id(), [
            'malformed_design: file design/home.html block_path document; authored_value '
                . self::warningValue($reason . '; preview=' . $preview . '; home-body=' . $body)
                . '; delivered_value ' . self::warningValue($disposition)
                . '; disposition degraded',
        ]);
    }

    /**
     * @param array{main:array<string,int|string|null>,hero:array<string,int|string|null>,body:array<string,int|string|null>} $preview
     * @param array{main:array<string,int|string|null>,footer:array<string,int|string|null>,style:?array<string,int|string|null>} $body
     */
    private static function compose(string $previewHtml, string $bodyHtml, array $preview, array $body): string
    {
        $previewMain = $preview['main'];
        $hero = $preview['hero'];
        $previewBody = $preview['body'];
        $bodyMain = $body['main'];
        $footer = $body['footer'];

        $mainOpen = substr(
            $previewHtml,
            $previewMain['start'],
            $previewMain['open_end'] - $previewMain['start'],
        );
        $mainClose = substr(
            $previewHtml,
            $previewMain['close_start'],
            $previewMain['end'] - $previewMain['close_start'],
        );
        $heroSource = substr($previewHtml, $hero['start'], $hero['end'] - $hero['start']);
        $belowFold = substr(
            $bodyHtml,
            $bodyMain['open_end'],
            $bodyMain['close_start'] - $bodyMain['open_end'],
        );
        $footerSource = substr($bodyHtml, $footer['start'], $footer['end'] - $footer['start']);

        $composed = substr($previewHtml, 0, $previewMain['start'])
            . $mainOpen
            . $heroSource
            . $belowFold
            . $mainClose
            . substr(
                $previewHtml,
                $previewMain['end'],
                $previewBody['close_start'] - $previewMain['end'],
            )
            . $footerSource
            . substr($previewHtml, $previewBody['close_start']);

        $style = $body['style'] ?? null;
        if (!is_array($style)) {
            return $composed;
        }
        $styleSource = substr($bodyHtml, (int) $style['start'], (int) $style['end'] - (int) $style['start']);
        $headClose = stripos($composed, '</head>');
        if ($headClose === false || $styleSource === '') {
            return $composed;
        }
        return substr($composed, 0, $headClose) . $styleSource . "\n" . substr($composed, $headClose);
    }

    /**
     * @return array{main:array<string,int|string|null>,hero:array<string,int|string|null>,body:array<string,int|string|null>}|null
     */
    private static function previewParts(string $html): ?array
    {
        $elements = self::elements($html);
        if ($elements === null) {
            return null;
        }

        $documents = self::matching($elements, 'html');
        $heads = self::matching($elements, 'head');
        $bodies = self::matching($elements, 'body');
        $headers = self::matching($elements, 'header');
        $mains = self::matching($elements, 'main');
        $footers = self::matching($elements, 'footer');
        if (
            count($documents) !== 1
            || count($heads) !== 1
            || count($bodies) !== 1
            || count($headers) !== 1
            || count($mains) !== 1
            || $footers !== []
        ) {
            return null;
        }

        $document = $documents[0];
        $head = $heads[0];
        $body = $bodies[0];
        $header = $headers[0];
        $main = $mains[0];
        $roots = array_values(array_filter(
            $elements,
            static fn (array $element): bool => $element['parent'] === null,
        ));
        $documentChildren = array_values(array_filter(
            $elements,
            static fn (array $element): bool => $element['parent'] === $document['index'],
        ));
        $prefix = substr($html, 0, $document['start']);
        if (
            array_column($roots, 'name') !== ['html']
            || $head['parent'] !== $document['index']
            || $body['parent'] !== $document['index']
            || array_column($documentChildren, 'name') !== ['head', 'body']
            || preg_match(
                '/\A[\x09\x0A\x0C\x0D\x20]*<!doctype[\x09\x0A\x0C\x0D\x20]+html'
                    . '[\x09\x0A\x0C\x0D\x20]*>[\x09\x0A\x0C\x0D\x20]*\z/iD',
                $prefix,
            ) !== 1
            || !self::isWhitespace(substr(
                $html,
                $document['open_end'],
                $head['start'] - $document['open_end'],
            ))
            || !self::isWhitespace(substr(
                $html,
                $head['end'],
                $body['start'] - $head['end'],
            ))
            || !self::isWhitespace(substr(
                $html,
                $body['end'],
                $document['close_start'] - $body['end'],
            ))
            || !self::isWhitespace(substr($html, $document['end']))
        ) {
            return null;
        }

        $bodyChildren = array_values(array_filter(
            $elements,
            static fn (array $element): bool => $element['parent'] === $body['index'],
        ));
        if (
            $header['parent'] !== $body['index']
            || $main['parent'] !== $body['index']
            || $header['start'] >= $main['start']
            || array_column($bodyChildren, 'name') !== ['header', 'main']
            || !self::isWhitespace(substr(
                $html,
                $body['open_end'],
                $header['start'] - $body['open_end'],
            ))
            || !self::isWhitespace(substr(
                $html,
                $header['end'],
                $main['start'] - $header['end'],
            ))
            || !self::isWhitespace(substr(
                $html,
                $main['end'],
                $body['close_start'] - $main['end'],
            ))
        ) {
            return null;
        }

        $heroes = array_values(array_filter(
            self::matching($elements, 'section'),
            static fn (array $element): bool => $element['parent'] === $main['index']
                && self::attribute($html, $element, 'id') === 'hero',
        ));
        if (count($heroes) !== 1) {
            return null;
        }
        $hero = $heroes[0];
        $mainChildren = array_values(array_filter(
            $elements,
            static fn (array $element): bool => $element['parent'] === $main['index'],
        ));
        if (
            count($mainChildren) !== 1
            || $mainChildren[0]['index'] !== $hero['index']
            || !self::isWhitespace(substr(
                $html,
                $main['open_end'],
                $hero['start'] - $main['open_end'],
            ))
            || !self::isWhitespace(substr(
                $html,
                $hero['end'],
                $main['close_start'] - $hero['end'],
            ))
        ) {
            return null;
        }

        return ['main' => $main, 'hero' => $hero, 'body' => $body];
    }

    /**
     * @return array{main:array<string,int|string|null>,footer:array<string,int|string|null>,style:?array<string,int|string|null>}|null
     */
    private static function bodyParts(string $html): ?array
    {
        if (trim($html) === '') {
            return null;
        }
        $elements = self::elements($html);
        if ($elements === null) {
            return null;
        }

        $mains = self::matching($elements, 'main');
        $footers = self::matching($elements, 'footer');
        $topLevelFooters = array_values(array_filter(
            $footers,
            static fn (array $element): bool => $element['parent'] === null,
        ));
        if (
            count($mains) !== 1
            || count($topLevelFooters) !== 1
            || self::matching($elements, 'header') !== []
        ) {
            return null;
        }
        foreach ($footers as $candidate) {
            $ancestorIndex = $candidate['parent'];
            while ($ancestorIndex !== null) {
                $ancestor = $elements[$ancestorIndex];
                if (in_array($ancestor['name'], ['footer', 'address'], true)) {
                    return null;
                }
                $ancestorIndex = $ancestor['parent'];
            }
        }
        $main = $mains[0];
        $footer = $topLevelFooters[0];
        $mainAttributes = self::attributes($html, $main);
        $roots = array_values(array_filter(
            $elements,
            static fn (array $element): bool => $element['parent'] === null,
        ));
        $rootNames = array_column($roots, 'name');
        $style = $rootNames === ['style', 'main', 'footer'] ? $roots[0] : null;
        if (
            $mainAttributes === null
            || $mainAttributes !== []
            || $main['parent'] !== null
            || $footer['parent'] !== null
            || $main['start'] >= $footer['start']
            || ($rootNames !== ['main', 'footer'] && $rootNames !== ['style', 'main', 'footer'])
            || !self::isWhitespace(substr($html, 0, $roots[0]['start']))
            || ($style !== null && !self::isPageCssStyle($html, $style, $main['start']))
            || !self::isWhitespace(substr(
                $html,
                $main['end'],
                $footer['start'] - $main['end'],
            ))
            || !self::isWhitespace(substr($html, $footer['end']))
        ) {
            return null;
        }
        foreach (self::matching($elements, 'section') as $section) {
            if (self::attribute($html, $section, 'id') === 'hero') {
                return null;
            }
        }
        return ['main' => $main, 'footer' => $footer, 'style' => $style];
    }

    /** @param array<string,int|string|null> $style */
    private static function isPageCssStyle(string $html, array $style, int $nextStart): bool
    {
        $openEnd = (int) $style['open_end'];
        $closeStart = (int) $style['close_start'];
        $end = (int) $style['end'];
        $opening = substr($html, (int) $style['start'], $openEnd - (int) $style['start']);
        return preg_match(
            '/(?:^|\s)data-page-css(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?(?=\s|\/?>)/i',
            $opening,
        ) === 1
            && self::isWhitespace(substr($html, $end, $nextStart - $end));
    }

    /**
     * @param list<array<string,int|string|null>> $elements
     * @return list<array<string,int|string|null>>
     */
    private static function matching(array $elements, string $name): array
    {
        return array_values(array_filter(
            $elements,
            static fn (array $element): bool => $element['name'] === $name,
        ));
    }

    /**
     * Tokenize source tags and retain exact byte spans. No DOM serialization.
     *
     * @return list<array{index:int,name:string,start:int,open_end:int,close_start:int,end:int,parent:?int}>|null
     */
    private static function elements(string $html): ?array
    {
        $elements = [];
        $stack = [];
        $length = strlen($html);
        $offset = 0;
        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                break;
            }
            if (substr($html, $start, 4) === '<!--') {
                $end = strpos($html, '-->', $start + 4);
                if ($end === false) {
                    return null;
                }
                $offset = $end + 3;
                continue;
            }
            if (substr($html, $start, 2) === '<!' || substr($html, $start, 2) === '<?') {
                $end = self::markupEnd($html, $start + 2);
                if ($end === null) {
                    return null;
                }
                $offset = $end;
                continue;
            }

            $tag = self::tagAt($html, $start);
            if ($tag === null) {
                if (preg_match('/\G<\/?[A-Za-z]/', $html, $unused, 0, $start) === 1) {
                    return null;
                }
                $offset = $start + 1;
                continue;
            }

            $name = $tag['name'];
            if ($tag['closing']) {
                if (in_array($name, self::VOID_ELEMENTS, true)) {
                    $offset = $tag['end'];
                    continue;
                }
                $last = array_key_last($stack);
                if ($last === null || $elements[$stack[$last]]['name'] !== $name) {
                    return null;
                }
                $index = array_pop($stack);
                $elements[$index]['close_start'] = $tag['start'];
                $elements[$index]['end'] = $tag['end'];
                $offset = $tag['end'];
                continue;
            }

            $index = count($elements);
            $elements[] = [
                'index' => $index,
                'name' => $name,
                'start' => $tag['start'],
                'open_end' => $tag['end'],
                'close_start' => $tag['end'],
                'end' => $tag['end'],
                'parent' => $stack === [] ? null : $stack[array_key_last($stack)],
            ];
            if ($tag['self_closing'] || in_array($name, self::VOID_ELEMENTS, true)) {
                $offset = $tag['end'];
                continue;
            }
            if (in_array($name, self::RAW_TEXT_ELEMENTS, true)) {
                $close = self::rawTextClose($html, $name, $tag['end']);
                if ($close === null) {
                    return null;
                }
                $elements[$index]['close_start'] = $close['start'];
                $elements[$index]['end'] = $close['end'];
                $offset = $close['end'];
                continue;
            }
            $stack[] = $index;
            $offset = $tag['end'];
        }

        return $stack === [] ? $elements : null;
    }

    /** @return array{name:string,start:int,end:int,closing:bool,self_closing:bool}|null */
    private static function tagAt(string $html, int $start): ?array
    {
        if (
            preg_match(
                '/\G<(\/?)(([A-Za-z][A-Za-z0-9:-]*))(?=[\x09\x0A\x0C\x0D\x20\/>])/',
                $html,
                $match,
                0,
                $start,
            ) !== 1
        ) {
            return null;
        }
        $end = self::markupEnd($html, $start + strlen($match[0]));
        if ($end === null) {
            return null;
        }
        $beforeClose = rtrim(substr($html, $start, $end - $start - 1));
        return [
            'name' => strtolower($match[2]),
            'start' => $start,
            'end' => $end,
            'closing' => $match[1] === '/',
            'self_closing' => str_ends_with($beforeClose, '/'),
        ];
    }

    private static function markupEnd(string $html, int $offset): ?int
    {
        $quote = null;
        $length = strlen($html);
        for ($i = $offset; $i < $length; $i++) {
            $char = $html[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '>') {
                return $i + 1;
            }
        }
        return null;
    }

    /** @return array{name:string,start:int,end:int,closing:bool,self_closing:bool}|null */
    private static function rawTextClose(string $html, string $name, int $offset): ?array
    {
        while (($start = stripos($html, '</' . $name, $offset)) !== false) {
            $tag = self::tagAt($html, $start);
            if ($tag !== null && $tag['closing'] && $tag['name'] === $name) {
                return $tag;
            }
            $offset = $start + 2;
        }
        return null;
    }

    /** @param array<string,int|string|null> $element */
    private static function attribute(string $html, array $element, string $wanted): ?string
    {
        $attributes = self::attributes($html, $element);
        $values = $attributes[strtolower($wanted)] ?? [];
        if ($attributes === null || count($values) !== 1 || $values[0] === null) {
            return null;
        }
        return html_entity_decode($values[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Parse only live opening-tag attributes. Quoted attribute contents never
     * become synthetic attributes.
     *
     * @param array<string,int|string|null> $element
     * @return array<string,list<?string>>|null
     */
    private static function attributes(string $html, array $element): ?array
    {
        $opening = substr($html, $element['start'], $element['open_end'] - $element['start']);
        if (preg_match('/\A<[A-Za-z][A-Za-z0-9:-]*/', $opening, $tag) !== 1) {
            return null;
        }

        $attributes = [];
        $offset = strlen($tag[0]);
        $length = strlen($opening);
        while ($offset < $length) {
            while (
                $offset < $length
                && str_contains("\x09\x0A\x0C\x0D\x20", $opening[$offset])
            ) {
                $offset++;
            }
            if ($offset === $length - 1 && $opening[$offset] === '>') {
                return $attributes;
            }
            if ($offset === $length - 2 && substr($opening, $offset) === '/>') {
                return $attributes;
            }
            if (
                preg_match(
                    '/\G([^\x09\x0A\x0C\x0D\x20"\'=<>`\/]+)'
                        . '(?:[\x09\x0A\x0C\x0D\x20]*=[\x09\x0A\x0C\x0D\x20]*'
                        . '(?:"([^"]*)"|\'([^\']*)\'|([^\x09\x0A\x0C\x0D\x20"\'=<>`]+)))?/',
                    $opening,
                    $match,
                    PREG_UNMATCHED_AS_NULL,
                    $offset,
                ) !== 1
            ) {
                return null;
            }
            $name = strtolower($match[1]);
            $value = $match[2] ?? $match[3] ?? $match[4] ?? null;
            $attributes[$name][] = $value;
            $offset += strlen($match[0]);
        }
        return null;
    }

    private static function isWhitespace(string $value): bool
    {
        return preg_match('/\A[\x09\x0A\x0C\x0D\x20]*\z/D', $value) === 1;
    }

    private static function warningValue(string $value): string
    {
        if (!preg_match('//u', $value)) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        if (strlen($value) > 320) {
            $value = mb_strcut($value, 0, 317, 'UTF-8') . '...';
        }
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR,
        );
    }
}
