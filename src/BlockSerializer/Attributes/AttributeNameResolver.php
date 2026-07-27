<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Attributes;

/**
 * Maps an unregistered comment-attribute key onto the registered attribute it
 * was probably meant to be.
 *
 * Generated markup varies the shape of a real name — `vertical_alignment`,
 * `VerticalAlignment`, `vertical-alignment` — while meaning the registered
 * attribute exactly. That intent is recoverable, so renaming beats dropping.
 *
 * Matching is deliberately limited to shape. An earlier revision also matched
 * on edit distance to catch genuine misspellings; measured against every real
 * build in this repo it resolved nothing the shape pass did not already get,
 * while producing renames that silently changed pages: `author` onto `anchor`
 * (emitting an `id` with a space in it), `alias` onto `align` (changing a
 * section's width), `link` onto `lock` (turning an authored link into a
 * block-locking directive). On core/group alone, 273 dictionary words fall
 * within edit distance 2 of `lock`. Recall that never fires is not worth a
 * corruption mode that does, so the fuzzy pass is gone.
 *
 * A rename is still a guess, so it must be unambiguous: a key whose shape
 * matches two registered names is refused rather than resolved arbitrarily,
 * the authored value must fit the target's declared type, and several classes
 * of target are excluded outright (see NEVER_RENAME_ONTO and the source-backed
 * check). A key that clears none of that is left for the caller to drop.
 *
 * Pure and side-effect free.
 */
final class AttributeNameResolver
{

    /**
     * Attributes a rename may never target, whatever the key looks like.
     *
     * SupportDomainGuard validates `style` and `layout` against the raw comment
     * — before any rename exists — and fails closed on an unreviewed path under
     * them. A key renamed onto either would arrive after that check and ship
     * unvalidated, so `{"Style":{"background":{"bogusKey":"x"}}}` would smuggle
     * past the one family the design says must never pass silently. Nobody
     * meaningfully misspells `style`; dropping the stray is the correct outcome.
     */
    private const NEVER_RENAME_ONTO = [
        'style'  => true,
        'layout' => true,
    ];

    /**
     * The registered attribute $key was probably meant to name, or null when
     * there is no unambiguous answer.
     *
     * @param mixed                             $value   the authored value, natively typed
     * @param array<string,array<string,mixed>> $schemas the block's registered attribute schemas
     * @param array<string,mixed>               $taken   keys the comment already carries; never overwritten
     */
    public static function resolve(string $key, mixed $value, array $schemas, array $taken = []): ?string
    {
        $normalized = self::normalize($key);
        if ($normalized === '') {
            return null;
        }

        $candidates = [];
        foreach ($schemas as $name => $schema) {
            // A key the comment already carries is an explicit authorial
            // choice. Renaming onto it would silently overwrite that value
            // with a guess, which is worse than dropping the stray key.
            if (array_key_exists($name, $taken) || !is_array($schema)) {
                continue;
            }
            if (isset(self::NEVER_RENAME_ONTO[$name])) {
                continue;
            }
            // A source-backed attribute is read from the saved HTML, not from
            // the delimiter, so a value moved here is discarded on the next
            // sourcing pass. Reporting that as a successful rename would be a
            // lie — and one this pipeline keeps out of warnings.json, since a
            // rename is supposed to mean the value survived. Drop instead.
            if (isset($schema['source'])) {
                continue;
            }
            $candidates[$name] = self::normalize((string) $name);
        }

        // Two registered names that differ only in shape cannot be told apart
        // from the authored key alone, so only a lone match resolves.
        $matches = array_keys($candidates, $normalized, true);
        if (count($matches) !== 1) {
            return null;
        }
        return self::typeMatches($value, $schemas[$matches[0]]) ? $matches[0] : null;
    }

    /**
     * Case- and separator-insensitive form, so `vertical_alignment`,
     * `Vertical-Alignment` and `verticalAlignment` compare equal.
     */
    private static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $name));
    }

    /**
     * Whether the authored value can be stored under the target schema. A
     * schema with no declared type accepts anything. JSON objects arrive as
     * either stdClass or an associative array depending on the decode path, so
     * both are treated as `object`.
     *
     * @param array<string,mixed> $schema
     */
    private static function typeMatches(mixed $value, array $schema): bool
    {
        $types = $schema['type'] ?? null;
        if ($types === null) {
            return true;
        }
        foreach ((array) $types as $type) {
            $ok = match ((string) $type) {
                'string'  => is_string($value),
                'number'  => is_int($value) || is_float($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                'null'    => $value === null,
                'array'   => is_array($value) && array_is_list($value),
                'object'  => $value instanceof \stdClass || (is_array($value) && !array_is_list($value)),
                default   => true,
            };
            if ($ok) {
                return true;
            }
        }
        return false;
    }
}
