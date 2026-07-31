<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\Json\JsonDecoder;

/**
 * Lossless, bounded repairs for malformed generated Gutenberg delimiters.
 *
 * Every accepted rewrite has one mechanical reading: attribute JSON is
 * repaired only when its root object alone is missing a closer or when all
 * valid premature-closer deletions decode identically; a typo-shaped closing
 * token is removed only when the real closer for that same block immediately
 * precedes it. Anything ambiguous remains untouched for the strict document
 * recovery gate to reject.
 */
final class BlockCommentRepair
{
    /**
     * One block-comment delimiter: closer flag, name, attrs JSON, void flag.
     * The attrs scan must not cross a `-->` boundary. The payload need not
     * end in `}`: a flat/array/scalar-ended payload missing only its root
     * closer must still be captured so it can be repaired.
     */
    private const TOKEN_RE =
        '/<!--\s*(\/)?wp:([a-z][a-z0-9_\/-]*)\s*'
        . '(\{(?:(?!-->).)*?)?\s*(\/)?-->/s';

    /** Hard cap on candidate payloads evaluated per premature-closer search. */
    private const MAX_CANDIDATE_ATTEMPTS = 1024;

    /**
     * @return array{markup:string,notes:list<string>}
     */
    public static function repair(string $markup): array
    {
        $notes = [];
        $view = HtmlBlockContext::delimiterView($markup);
        $markup = self::repairMalformedAttributes($markup, $view, $notes);
        $markup = self::removeRedundantTypoClosers($markup, $notes);
        return ['markup' => $markup, 'notes' => $notes];
    }

    /**
     * Repair only attribute JSON with one unambiguous decoded object.
     *
     * @param list<string> $notes
     */
    private static function repairMalformedAttributes(
        string $markup,
        string $delimiterView,
        array &$notes
    ): string {
        $found = preg_match_all(
            self::TOKEN_RE,
            $delimiterView,
            $tokens,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        );
        if ($found === false || $found === 0) {
            return $markup;
        }

        // Descending edits preserve every parser offset and every byte outside
        // the JSON payload being repaired.
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (!isset($token[3]) || $token[3][1] === -1 || $token[3][0] === '') {
                continue;
            }
            $json = $token[3][0];
            if (json_decode($json) instanceof \stdClass) {
                continue;
            }

            $completed = self::withMissingRootCloser($json);
            if ($completed !== null) {
                $markup = substr_replace(
                    $markup,
                    $completed,
                    $token[3][1],
                    strlen($json),
                );
                $notes[] = "wp:{$token[2][0]} attributes omitted their final root closer"
                    . ' — restored it so the declared attributes parse instead of being erased';
                continue;
            }

            $repaired = self::withoutPrematureClosers($json);
            if ($repaired === null) {
                continue;
            }
            $markup = substr_replace(
                $markup,
                $repaired,
                $token[3][1],
                strlen($json),
            );
            $notes[] = "wp:{$token[2][0]} attributes closed their JSON object early"
                . ' — removed the stray closer(s) so the declared attributes parse instead of being erased';
        }
        return $markup;
    }

    /**
     * Remove the exact golden-beacon typo shape only when it is redundant:
     * a real closer for the same block immediately precedes it. The real
     * closer must be visible in the block-delimiter lexical view, which keeps
     * examples inside code/pre/template and other opaque contexts untouched.
     *
     * @param list<string> $notes
     */
    private static function removeRedundantTypoClosers(string $markup, array &$notes): string
    {
        $pattern = '/(?<valid><!--\s*\/wp:(?<validName>[a-z][a-z0-9_\/-]*)\s*-->)'
            . '(?<gap>[\x09\x0A\x0C\x0D\x20]*)'
            . '<\/!--\s*wp:(?<typoName>[a-z][a-z0-9_\/-]*)\s*-->/';
        $found = preg_match_all(
            $pattern,
            $markup,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        );
        if ($found === false || $found === 0) {
            return $markup;
        }

        $view = HtmlBlockContext::delimiterView($markup);
        for ($i = count($matches) - 1; $i >= 0; $i--) {
            $match = $matches[$i];
            if ($match['validName'][0] !== $match['typoName'][0]) {
                continue;
            }
            $validOffset = $match['valid'][1];
            if (substr($view, $validOffset, strlen($match['valid'][0])) !== $match['valid'][0]) {
                continue;
            }

            $replacement = $match['valid'][0] . $match['gap'][0];
            $markup = substr_replace(
                $markup,
                $replacement,
                $match[0][1],
                strlen($match[0][0]),
            );
            $notes[] = "removed a redundant malformed wp:{$match['typoName'][0]} closer"
                . ' that immediately followed its valid closer';
        }
        return $markup;
    }

    /**
     * Complete only the narrow form where every nested object/array is
     * balanced and the sole missing token is the root object's final `}`.
     */
    private static function withMissingRootCloser(string $json): ?string
    {
        $stack = [];
        $inString = false;
        $escaped = false;
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === '{') {
                $stack[] = ['closer' => '}', 'offset' => $i];
            } elseif ($char === '[') {
                $stack[] = ['closer' => ']', 'offset' => $i];
            } elseif ($char === '}' || $char === ']') {
                $frame = array_pop($stack);
                if ($frame === null || $frame['closer'] !== $char) {
                    return null;
                }
            }
        }

        if ($inString || count($stack) !== 1
            || $stack[0]['offset'] !== 0 || $stack[0]['closer'] !== '}') {
            return null;
        }
        $candidate = $json . '}';
        return json_decode($candidate) instanceof \stdClass
            && !self::hasSameDepthDuplicateKeys($candidate)
            ? $candidate
            : null;
    }

    /**
     * Remove at most two premature closers only when every successful
     * candidate produces the same decoded object.
     */
    private static function withoutPrematureClosers(string $json): ?string
    {
        // The attempt budget is enforced per candidate, inside the loops: a
        // second-round sweep is quadratic (payloads x closers, each candidate
        // a full payload copy), so checking only between rounds would
        // materialize the whole round in memory before any limit applied.
        $attempts = 0;
        $payloads = [$json];
        for ($deletions = 0; $deletions < 2; $deletions++) {
            $results = [];
            $stillInvalid = [];
            foreach ($payloads as $payload) {
                foreach (self::closerOffsets($payload) as $offset) {
                    if (++$attempts > self::MAX_CANDIDATE_ATTEMPTS) {
                        return null;
                    }
                    $candidate = substr_replace($payload, '', $offset, 1);
                    $decoded = json_decode($candidate);
                    if (!$decoded instanceof \stdClass) {
                        $stillInvalid[$candidate] = true;
                        continue;
                    }
                    if (!self::hasSameDepthDuplicateKeys($candidate)) {
                        $results[json_encode($decoded)] = $candidate;
                        if (count($results) > 1) {
                            return null;
                        }
                    }
                }
            }
            if ($results !== []) {
                return reset($results);
            }
            $payloads = array_keys($stillInvalid);
        }
        return null;
    }

    /** @return list<int> byte offsets of every `}` / `]` outside strings */
    private static function closerOffsets(string $json): array
    {
        $offsets = [];
        $inString = false;
        $escaped = false;
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '}' || $char === ']') {
                $offsets[] = $i;
            }
        }
        return $offsets;
    }

    /** Whether a valid payload declares the same decoded key twice. */
    private static function hasSameDepthDuplicateKeys(string $json): bool
    {
        $decoder = new JsonDecoder($json, mergeDuplicateObjectKeys: true);
        try {
            $decoder->decode();
        } catch (\InvalidArgumentException) {
            return false;
        }
        return $decoder->mergedDuplicateKeyPaths() !== [];
    }
}
