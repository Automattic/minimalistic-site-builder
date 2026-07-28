<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Pure-PHP Token-Oriented Object Notation (TOON) codec.
 *
 * Implements the JSON data model ↔ TOON text mapping used by this package's
 * block-attribute prototype. No Node runtime: encode/decode run entirely in
 * process. Focused on shapes common in Gutenberg block attrs (nested objects,
 * primitives, small arrays). Tabular array form is supported for uniform
 * object arrays; exotic host types are out of scope.
 *
 * Spec reference: https://github.com/toon-format/spec (v4.1 subset).
 */
final class Toon
{
    private const INDENT = 2;

    /**
     * Encode a JSON-model value (array/object/primitive) as TOON text.
     * Associative arrays are objects; list arrays (0..n-1 keys) are arrays.
     */
    public static function encode(mixed $value): string
    {
        $normalized = self::normalize($value);
        if ($normalized === []) {
            // Empty root object → empty document (spec §8).
            if (is_array($value) && !self::isList($value)) {
                return '';
            }
        }
        $lines = self::encodeValue($normalized, 0, true);
        return rtrim(implode("\n", $lines), "\n");
    }

    /**
     * Decode TOON text into PHP arrays / primitives (JSON model).
     * Objects become associative arrays; arrays become list arrays.
     *
     * @return mixed
     */
    public static function decode(string $text): mixed
    {
        $text = self::stripBom($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $cleaned = [];
        foreach ($lines as $line) {
            $line = rtrim($line, " \t");
            // Full-line comments (# …) — decode-side only.
            $trimLead = ltrim($line, ' ');
            if ($trimLead !== '' && $trimLead[0] === '#') {
                continue;
            }
            $cleaned[] = $line;
        }

        // Drop trailing blank lines for root discovery.
        while ($cleaned !== [] && trim(end($cleaned)) === '') {
            array_pop($cleaned);
        }
        if ($cleaned === []) {
            return [];
        }

        $nonBlank = [];
        foreach ($cleaned as $i => $line) {
            if (trim($line) !== '') {
                $nonBlank[] = [$i, $line];
            }
        }
        if ($nonBlank === []) {
            return [];
        }

        [, $first] = $nonBlank[0];
        $firstContent = self::content($first);

        // Root empty array.
        if ($firstContent === '[]' && count($nonBlank) === 1) {
            return [];
        }

        // Root primitive (single non-blank line, not key:value / header).
        if (count($nonBlank) === 1
            && !self::looksLikeKeyValue($firstContent)
            && !self::looksLikeArrayHeader($firstContent)
        ) {
            return self::decodePrimitiveToken($firstContent);
        }

        // Root array header: [N]: … or [N]{fields}:
        if (self::looksLikeArrayHeader($firstContent) && !self::keyBeforeBracket($firstContent)) {
            $parser = new ToonParser($cleaned, self::INDENT);
            return $parser->parseRootArray(0);
        }

        // Root object (default).
        $parser = new ToonParser($cleaned, self::INDENT);
        return $parser->parseObject(0, 0, count($cleaned));
    }

    /** Round-trip helper for tests. */
    public static function roundTrip(mixed $value): mixed
    {
        return self::decode(self::encode($value));
    }

    // ------------------------------------------------------------------ encode

    /** @return list<string> */
    private static function encodeValue(mixed $value, int $depth, bool $isRoot): array
    {
        if (is_array($value)) {
            if ($value === []) {
                // Empty array vs empty object: at root, empty object is empty doc;
                // empty array is `[]`. Nested empties handled by callers.
                return $isRoot ? ['[]'] : ['[]'];
            }
            if (self::isList($value)) {
                return self::encodeArray($value, $depth, $isRoot, null);
            }
            return self::encodeObject($value, $depth, $isRoot);
        }
        return [self::encodePrimitive($value)];
    }

    /**
     * @param array<string,mixed> $object
     * @return list<string>
     */
    private static function encodeObject(array $object, int $depth, bool $isRoot): array
    {
        $lines = [];
        $pad = str_repeat(' ', $depth * self::INDENT);
        foreach ($object as $key => $val) {
            $keyStr = self::encodeKey((string) $key);
            if (is_array($val) && $val !== [] && !self::isList($val)) {
                $lines[] = $pad . $keyStr . ':';
                foreach (self::encodeObject($val, $depth + 1, false) as $child) {
                    $lines[] = $child;
                }
            } elseif (is_array($val) && self::isList($val)) {
                $arrLines = self::encodeArray($val, $depth, false, $keyStr);
                foreach ($arrLines as $line) {
                    $lines[] = $line;
                }
            } elseif (is_array($val) && $val === []) {
                // Empty array field.
                $lines[] = $pad . $keyStr . ': []';
            } else {
                $lines[] = $pad . $keyStr . ': ' . self::encodePrimitive($val);
            }
        }
        return $lines;
    }

    /**
     * @param list<mixed> $items
     * @return list<string>
     */
    private static function encodeArray(array $items, int $depth, bool $isRoot, ?string $key): array
    {
        $n = count($items);
        $pad = str_repeat(' ', $depth * self::INDENT);
        $headerPrefix = $key !== null ? $key : '';

        if ($n === 0) {
            if ($key !== null) {
                return [$pad . $key . ': []'];
            }
            return [$pad . '[]'];
        }

        // All primitives → inline form.
        $allPrimitive = true;
        foreach ($items as $item) {
            if (is_array($item)) {
                $allPrimitive = false;
                break;
            }
        }
        if ($allPrimitive) {
            $cells = [];
            foreach ($items as $item) {
                $cells[] = self::encodePrimitive($item, true);
            }
            $header = $headerPrefix . '[' . $n . ']: ' . implode(',', $cells);
            return [$pad . $header];
        }

        // Uniform objects → tabular when eligible.
        if (self::isUniformObjectArray($items)) {
            /** @var array<string,mixed> $first */
            $first = $items[0];
            $fields = array_keys($first);
            $fieldList = implode(',', array_map(
                static fn (string|int $k): string => self::encodeKey((string) $k),
                $fields,
            ));
            $header = $headerPrefix . '[' . $n . ']{' . $fieldList . '}:';
            $lines = [$pad . $header];
            $rowPad = str_repeat(' ', ($depth + 1) * self::INDENT);
            foreach ($items as $item) {
                $cells = [];
                foreach ($fields as $f) {
                    $cells[] = self::encodePrimitive($item[$f] ?? null, true);
                }
                $lines[] = $rowPad . implode(',', $cells);
            }
            return $lines;
        }

        // List form.
        $header = $headerPrefix . '[' . $n . ']:';
        $lines = [$pad . $header];
        $itemPad = str_repeat(' ', ($depth + 1) * self::INDENT);
        foreach ($items as $item) {
            if (!is_array($item)) {
                $lines[] = $itemPad . '- ' . self::encodePrimitive($item);
                continue;
            }
            if ($item === []) {
                $lines[] = $itemPad . '-';
                continue;
            }
            if (self::isList($item)) {
                // Nested primitive array as list item.
                $inner = self::encodeArray($item, $depth + 1, false, null);
                // First line is header at depth+1; re-emit with "- " prefix on header.
                if ($inner === []) {
                    $lines[] = $itemPad . '- []';
                    continue;
                }
                $first = $inner[0];
                $lines[] = $itemPad . '- ' . ltrim($first);
                for ($i = 1, $c = count($inner); $i < $c; $i++) {
                    $lines[] = $inner[$i];
                }
                continue;
            }
            // Object list item: first field on hyphen line.
            $keys = array_keys($item);
            $firstKey = (string) $keys[0];
            $firstVal = $item[$firstKey];
            $rest = $item;
            unset($rest[$firstKey]);
            if (!is_array($firstVal)) {
                $lines[] = $itemPad . '- ' . self::encodeKey($firstKey) . ': ' . self::encodePrimitive($firstVal);
            } elseif ($firstVal === []) {
                $lines[] = $itemPad . '- ' . self::encodeKey($firstKey) . ':';
            } elseif (!self::isList($firstVal)) {
                $lines[] = $itemPad . '- ' . self::encodeKey($firstKey) . ':';
                foreach (self::encodeObject($firstVal, $depth + 2, false) as $child) {
                    $lines[] = $child;
                }
            } else {
                $arrLines = self::encodeArray($firstVal, $depth + 1, false, self::encodeKey($firstKey));
                $lines[] = $itemPad . '- ' . ltrim($arrLines[0]);
                for ($i = 1, $c = count($arrLines); $i < $c; $i++) {
                    $lines[] = $arrLines[$i];
                }
            }
            if ($rest !== []) {
                foreach (self::encodeObject($rest, $depth + 2, false) as $child) {
                    $lines[] = $child;
                }
            }
        }
        return $lines;
    }

    private static function encodePrimitive(mixed $value, bool $inDelimited = false): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            if (is_float($value) && (is_nan($value) || is_infinite($value))) {
                return 'null';
            }
            if (is_float($value) && floor($value) === $value && abs($value) < 1e21) {
                return (string) (int) $value;
            }
            // Canonical-ish decimal.
            $s = is_int($value) ? (string) $value : rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
            return $s === '-0' ? '0' : $s;
        }
        $s = (string) $value;
        if (self::needsQuotes($s, $inDelimited)) {
            return '"' . self::escapeString($s) . '"';
        }
        return $s;
    }

    private static function encodeKey(string $key): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key) === 1) {
            return $key;
        }
        return '"' . self::escapeString($key) . '"';
    }

    private static function needsQuotes(string $s, bool $inDelimited): bool
    {
        if ($s === '') {
            return true;
        }
        if (preg_match('/^\s|\s$/', $s) === 1) {
            return true;
        }
        if ($s === 'true' || $s === 'false' || $s === 'null') {
            return true;
        }
        if (preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?(?:e[+-]?[0-9]+)?$/i', $s) === 1) {
            return true;
        }
        if (str_contains($s, ':') || str_contains($s, '"') || str_contains($s, '\\')) {
            return true;
        }
        if (preg_match('/[\[\]{}]/', $s) === 1) {
            return true;
        }
        if (preg_match('/[\x00-\x1F]/', $s) === 1) {
            return true;
        }
        if ($s[0] === '-' || $s[0] === '#') {
            return true;
        }
        if ($inDelimited && str_contains($s, ',')) {
            return true;
        }
        return false;
    }

    private static function escapeString(string $s): string
    {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            $o = ord($c);
            if ($c === '\\') {
                $out .= '\\\\';
            } elseif ($c === '"') {
                $out .= '\\"';
            } elseif ($c === "\n") {
                $out .= '\\n';
            } elseif ($c === "\r") {
                $out .= '\\r';
            } elseif ($c === "\t") {
                $out .= '\\t';
            } elseif ($o < 0x20) {
                $out .= sprintf('\\u%04x', $o);
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        if ($value === []) {
            // Ambiguous; callers treat empty specially.
            return true;
        }
        $i = 0;
        foreach ($value as $k => $_) {
            if ($k !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }

    /** @param list<mixed> $items */
    private static function isUniformObjectArray(array $items): bool
    {
        if ($items === []) {
            return false;
        }
        $fields = null;
        foreach ($items as $item) {
            if (!is_array($item) || $item === [] || self::isList($item)) {
                return false;
            }
            foreach ($item as $v) {
                if (is_array($v)) {
                    return false; // nested columns need nested field groups; skip for prototype
                }
            }
            $keys = array_keys($item);
            sort($keys);
            if ($fields === null) {
                $fields = $keys;
            } elseif ($fields !== $keys) {
                return false;
            }
        }
        return true;
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return (string) $value;
        }
        if (self::isList($value)) {
            $out = [];
            foreach ($value as $v) {
                $out[] = self::normalize($v);
            }
            return $out;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = self::normalize($v);
        }
        return $out;
    }

    private static function stripBom(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            return substr($text, 3);
        }
        if (str_starts_with($text, "\u{FEFF}")) {
            return substr($text, strlen("\u{FEFF}"));
        }
        return $text;
    }

    private static function content(string $line): string
    {
        return ltrim($line, ' ');
    }

    private static function looksLikeKeyValue(string $content): bool
    {
        return self::firstUnquotedColon($content) !== null;
    }

    private static function looksLikeArrayHeader(string $content): bool
    {
        return preg_match('/\[(?:0|[1-9][0-9]*)\]/', $content) === 1
            && str_contains($content, ':');
    }

    private static function keyBeforeBracket(string $content): bool
    {
        $br = strpos($content, '[');
        if ($br === false || $br === 0) {
            return false;
        }
        return true;
    }

    private static function firstUnquotedColon(string $s): ?int
    {
        $inQuote = false;
        $escape = false;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($c === '\\' && $inQuote) {
                $escape = true;
                continue;
            }
            if ($c === '"') {
                $inQuote = !$inQuote;
                continue;
            }
            if ($c === ':' && !$inQuote) {
                return $i;
            }
        }
        return null;
    }

    public static function decodePrimitiveToken(string $token): mixed
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }
        if ($token[0] === '"') {
            return self::unescapeQuoted($token);
        }
        if ($token === 'true') {
            return true;
        }
        if ($token === 'false') {
            return false;
        }
        if ($token === 'null') {
            return null;
        }
        if ($token === '[]') {
            return [];
        }
        if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:e[+-]?[0-9]+)?$/i', $token) === 1) {
            if (preg_match('/^-?0[0-9]/', $token) === 1) {
                return $token; // leading zeros → string
            }
            if (str_contains($token, '.') || stripos($token, 'e') !== false) {
                return (float) $token;
            }
            // Keep large ints as int when possible.
            if (strlen(ltrim($token, '-')) < 18) {
                return (int) $token;
            }
            return $token;
        }
        return $token;
    }

    public static function unescapeQuoted(string $token): string
    {
        $token = trim($token);
        if ($token === '' || $token[0] !== '"') {
            throw new \RuntimeException('expected quoted string, got: ' . $token);
        }
        $len = strlen($token);
        if ($len < 2 || $token[$len - 1] !== '"') {
            throw new \RuntimeException('unterminated quoted string: ' . $token);
        }
        $inner = substr($token, 1, -1);
        $out = '';
        $n = strlen($inner);
        for ($i = 0; $i < $n; $i++) {
            $c = $inner[$i];
            if ($c !== '\\') {
                $out .= $c;
                continue;
            }
            if ($i + 1 >= $n) {
                throw new \RuntimeException('dangling escape in string');
            }
            $nxt = $inner[++$i];
            $out .= match ($nxt) {
                '\\' => '\\',
                '"' => '"',
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'u' => self::decodeUnicodeEscape($inner, $i),
                default => throw new \RuntimeException('invalid escape \\' . $nxt),
            };
            if ($nxt === 'u') {
                $i += 4; // decodeUnicodeEscape advances conceptually; we consumed uXXXX
            }
        }
        return $out;
    }

    private static function decodeUnicodeEscape(string $inner, int $uIndex): string
    {
        // $uIndex points at 'u'
        $hex = substr($inner, $uIndex + 1, 4);
        if (strlen($hex) < 4 || preg_match('/^[0-9a-fA-F]{4}$/', $hex) !== 1) {
            throw new \RuntimeException('invalid unicode escape');
        }
        $cp = hexdec($hex);
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            throw new \RuntimeException('lone surrogate in unicode escape');
        }
        return mb_chr($cp, 'UTF-8');
    }

    /** Split a delimiter-separated line respecting quotes. @return list<string> */
    public static function splitDelimited(string $line, string $delim = ','): array
    {
        $parts = [];
        $buf = '';
        $inQuote = false;
        $escape = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($escape) {
                $buf .= $c;
                $escape = false;
                continue;
            }
            if ($c === '\\' && $inQuote) {
                $buf .= $c;
                $escape = true;
                continue;
            }
            if ($c === '"') {
                $inQuote = !$inQuote;
                $buf .= $c;
                continue;
            }
            if ($c === $delim && !$inQuote) {
                $parts[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $parts[] = trim($buf);
        return $parts;
    }
}

/**
 * Line-oriented TOON parser (package-private helper for Toon::decode).
 *
 * @internal
 */
final class ToonParser
{
    /** @param list<string> $lines */
    public function __construct(
        private array $lines,
        private int $indentSize,
    ) {}

    /**
     * Parse object fields starting at $start line, at $depth, until line >= $end
     * or indentation drops to depth or below (for nested) / end of lines.
     *
     * @return array<string,mixed>
     */
    public function parseObject(int $start, int $depth, int $end): array
    {
        $obj = [];
        $i = $start;
        while ($i < $end) {
            $line = $this->lines[$i];
            if (trim($line) === '') {
                $i++;
                continue;
            }
            $lineDepth = $this->depthOf($line);
            if ($lineDepth < $depth) {
                break;
            }
            if ($lineDepth > $depth) {
                throw new \RuntimeException(
                    "TOON: unexpected indent at line " . ($i + 1) . ": " . trim($line)
                );
            }
            $content = ltrim($line, ' ');

            // List items don't belong in plain object body.
            if (str_starts_with($content, '- ') || $content === '-') {
                break;
            }

            // Array header with key: key[N]: or key[N]{f}:
            if ($this->isKeyedArrayHeader($content)) {
                [$key, $value, $next] = $this->parseKeyedArrayHeader($i, $depth, $end);
                $obj[$key] = $value;
                $i = $next;
                continue;
            }

            $colon = $this->firstUnquotedColon($content);
            if ($colon === null) {
                // Tabular row or scalar — end object.
                break;
            }
            $keyTok = trim(substr($content, 0, $colon));
            $rest = trim(substr($content, $colon + 1));
            $key = $this->decodeKey($keyTok);

            if ($rest === '') {
                // Nested object or empty object — look ahead.
                $childStart = $i + 1;
                $childEnd = $this->findScopeEnd($childStart, $depth, $end);
                if ($childStart >= $childEnd) {
                    $obj[$key] = [];
                    $i = $childStart;
                    continue;
                }
                // If first child looks like array rows without header, invalid;
                // normal nested object.
                $obj[$key] = $this->parseObject($childStart, $depth + 1, $childEnd);
                $i = $childEnd;
                continue;
            }

            if ($rest === '[]') {
                $obj[$key] = [];
                $i++;
                continue;
            }

            $obj[$key] = Toon::decodePrimitiveToken($rest);
            $i++;
        }
        return $obj;
    }

    /** @return mixed */
    public function parseRootArray(int $start): mixed
    {
        $line = $this->lines[$start];
        $content = ltrim($line, ' ');
        return $this->parseArrayFromHeader($content, $start, 0, count($this->lines), null)[0];
    }

    /**
     * @return array{0:mixed,1:int} [value, nextLine]
     */
    private function parseKeyedArrayHeader(int $lineIndex, int $depth, int $end): array
    {
        $content = ltrim($this->lines[$lineIndex], ' ');
        $br = strpos($content, '[');
        if ($br === false) {
            throw new \RuntimeException('TOON: expected array header');
        }
        $keyTok = substr($content, 0, $br);
        $key = $this->decodeKey(trim($keyTok));
        $headerRest = substr($content, $br);
        [$value, $next] = $this->parseArrayFromHeader($headerRest, $lineIndex, $depth, $end, $key);
        return [$key, $value, $next];
    }

    /**
     * @return array{0:mixed,1:int}
     */
    private function parseArrayFromHeader(
        string $headerContent,
        int $lineIndex,
        int $depth,
        int $end,
        ?string $keyForError,
    ): array {
        // headerContent like [2]: a,b  or [2]{id,name}:  or [2]:
        if (!preg_match(
            '/^\[(0|[1-9][0-9]*)\](\{([^}]*)\})?:\s*(.*)$/',
            $headerContent,
            $m
        )) {
            throw new \RuntimeException('TOON: malformed array header: ' . $headerContent);
        }
        $n = (int) $m[1];
        $fieldsRaw = $m[3] ?? null;
        $inline = trim($m[4] ?? '');

        if ($fieldsRaw !== null && $fieldsRaw !== '') {
            // Tabular form.
            $fields = Toon::splitDelimited($fieldsRaw, ',');
            $fields = array_map(fn (string $f): string => $this->decodeKey(trim($f)), $fields);
            $rows = [];
            $i = $lineIndex + 1;
            while ($i < $end && count($rows) < $n) {
                $line = $this->lines[$i];
                if (trim($line) === '') {
                    $i++;
                    continue;
                }
                $lineDepth = $this->depthOf($line);
                if ($lineDepth <= $depth) {
                    break;
                }
                if ($lineDepth !== $depth + 1) {
                    throw new \RuntimeException('TOON: bad tabular row indent at line ' . ($i + 1));
                }
                $cells = Toon::splitDelimited(ltrim($line, ' '), ',');
                if (count($cells) !== count($fields)) {
                    throw new \RuntimeException(
                        'TOON: tabular row width mismatch at line ' . ($i + 1)
                    );
                }
                $row = [];
                foreach ($fields as $fi => $fname) {
                    $row[$fname] = Toon::decodePrimitiveToken($cells[$fi]);
                }
                $rows[] = $row;
                $i++;
            }
            if (count($rows) !== $n) {
                throw new \RuntimeException(
                    'TOON: expected ' . $n . ' tabular rows, got ' . count($rows)
                    . ($keyForError !== null ? " for key {$keyForError}" : '')
                );
            }
            return [$rows, $i];
        }

        if ($inline !== '') {
            // Inline primitive array.
            $cells = Toon::splitDelimited($inline, ',');
            if (count($cells) !== $n) {
                throw new \RuntimeException(
                    'TOON: inline array length mismatch (header ' . $n . ', cells ' . count($cells) . ')'
                );
            }
            $vals = [];
            foreach ($cells as $c) {
                $vals[] = Toon::decodePrimitiveToken($c);
            }
            return [$vals, $lineIndex + 1];
        }

        // List form: N items at depth+1 starting with "- "
        $items = [];
        $i = $lineIndex + 1;
        while ($i < $end && count($items) < $n) {
            $line = $this->lines[$i];
            if (trim($line) === '') {
                $i++;
                continue;
            }
            $lineDepth = $this->depthOf($line);
            if ($lineDepth <= $depth) {
                break;
            }
            if ($lineDepth !== $depth + 1) {
                throw new \RuntimeException('TOON: bad list item indent at line ' . ($i + 1));
            }
            $content = ltrim($line, ' ');
            if ($content === '-') {
                $items[] = [];
                $i++;
                continue;
            }
            if (!str_starts_with($content, '- ')) {
                break;
            }
            $rest = substr($content, 2);
            // Nested array header on hyphen line.
            if ($this->isArrayHeaderBody($rest) && !$this->looksLikeKeyValue($rest)) {
                // keyless [M]: …
                [$inner, $next] = $this->parseArrayFromHeader($rest, $i, $depth + 1, $end, null);
                $items[] = $inner;
                $i = $next;
                continue;
            }
            if ($this->isKeyedArrayHeader($rest)) {
                [$k, $val, $next] = $this->parseKeyedArrayHeaderFromContent($rest, $i, $depth + 1, $end);
                $obj = [$k => $val];
                // Further fields of list-item object at depth+2.
                $fieldEnd = $this->findScopeEnd($next, $depth + 1, $end);
                $more = $this->parseObject($next, $depth + 2, $fieldEnd);
                $items[] = $obj + $more;
                $i = $fieldEnd;
                continue;
            }
            if ($this->looksLikeKeyValue($rest)) {
                $colon = $this->firstUnquotedColon($rest);
                $keyTok = trim(substr($rest, 0, (int) $colon));
                $valRest = trim(substr($rest, (int) $colon + 1));
                $k = $this->decodeKey($keyTok);
                if ($valRest === '') {
                    $childStart = $i + 1;
                    $childEnd = $this->findScopeEnd($childStart, $depth + 1, $end);
                    $nested = $this->parseObject($childStart, $depth + 2, $childEnd);
                    $obj = [$k => $nested];
                    $more = $this->parseObject($childEnd, $depth + 2, $this->findScopeEnd($childEnd, $depth + 1, $end));
                    // Simpler: parse remaining sibling fields until next list item or depth drop.
                    $i = $childEnd;
                    $fieldEnd = $this->findListItemFieldEnd($i, $depth + 1, $end);
                    $more = $this->parseObject($i, $depth + 2, $fieldEnd);
                    $items[] = $obj + $more;
                    $i = $fieldEnd;
                    continue;
                }
                $obj = [$k => Toon::decodePrimitiveToken($valRest)];
                $i++;
                $fieldEnd = $this->findListItemFieldEnd($i, $depth + 1, $end);
                $more = $this->parseObject($i, $depth + 2, $fieldEnd);
                $items[] = $obj + $more;
                $i = $fieldEnd;
                continue;
            }
            $items[] = Toon::decodePrimitiveToken($rest);
            $i++;
        }
        if (count($items) !== $n) {
            throw new \RuntimeException(
                'TOON: expected ' . $n . ' list items, got ' . count($items)
            );
        }
        return [$items, $i];
    }

    /**
     * @return array{0:string,1:mixed,2:int}
     */
    private function parseKeyedArrayHeaderFromContent(
        string $content,
        int $lineIndex,
        int $depth,
        int $end,
    ): array {
        $br = strpos($content, '[');
        $keyTok = substr($content, 0, (int) $br);
        $key = $this->decodeKey(trim($keyTok));
        $headerRest = substr($content, (int) $br);
        [$value, $next] = $this->parseArrayFromHeader($headerRest, $lineIndex, $depth, $end, $key);
        return [$key, $value, $next];
    }

    private function findScopeEnd(int $start, int $parentDepth, int $end): int
    {
        $i = $start;
        while ($i < $end) {
            $line = $this->lines[$i];
            if (trim($line) === '') {
                $i++;
                continue;
            }
            if ($this->depthOf($line) <= $parentDepth) {
                return $i;
            }
            $i++;
        }
        return $end;
    }

    /** End of extra fields for a list-item object (depth parentDepth+1 lines that are not new list items). */
    private function findListItemFieldEnd(int $start, int $listItemDepth, int $end): int
    {
        // Fields of list-item object are at listItemDepth+1; stop at next list item at listItemDepth or shallower.
        $i = $start;
        while ($i < $end) {
            $line = $this->lines[$i];
            if (trim($line) === '') {
                $i++;
                continue;
            }
            $d = $this->depthOf($line);
            if ($d < $listItemDepth + 1) {
                return $i;
            }
            if ($d === $listItemDepth) {
                $c = ltrim($line, ' ');
                if (str_starts_with($c, '- ') || $c === '-') {
                    return $i;
                }
                return $i;
            }
            $i++;
        }
        return $end;
    }

    private function depthOf(string $line): int
    {
        $spaces = 0;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            if ($line[$i] === ' ') {
                $spaces++;
            } elseif ($line[$i] === "\t") {
                throw new \RuntimeException('TOON: tabs not allowed for indentation');
            } else {
                break;
            }
        }
        if ($spaces % $this->indentSize !== 0) {
            throw new \RuntimeException('TOON: indent is not a multiple of ' . $this->indentSize);
        }
        return intdiv($spaces, $this->indentSize);
    }

    private function isKeyedArrayHeader(string $content): bool
    {
        if (!preg_match('/^[^\[]+\[(?:0|[1-9][0-9]*)\]/', $content)) {
            return false;
        }
        return str_contains($content, ':');
    }

    private function isArrayHeaderBody(string $content): bool
    {
        return preg_match('/^\[(?:0|[1-9][0-9]*)\]/', $content) === 1;
    }

    private function looksLikeKeyValue(string $content): bool
    {
        return $this->firstUnquotedColon($content) !== null
            && !$this->isArrayHeaderBody($content);
    }

    private function firstUnquotedColon(string $s): ?int
    {
        $inQuote = false;
        $escape = false;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($c === '\\' && $inQuote) {
                $escape = true;
                continue;
            }
            if ($c === '"') {
                $inQuote = !$inQuote;
                continue;
            }
            if ($c === ':' && !$inQuote) {
                return $i;
            }
        }
        return null;
    }

    private function decodeKey(string $token): string
    {
        $token = trim($token);
        if ($token !== '' && $token[0] === '"') {
            return Toon::unescapeQuoted($token);
        }
        return $token;
    }
}
