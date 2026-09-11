<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

/**
 * Provider-free extraction of Big Sky's collect / map / retry-item behavior
 * (Replace_Content::generate_replacement_content and update_block_content).
 * The caller owns batching and retry transport. Bindings never enter the
 * editable model inventory, and valid siblings survive partial responses.
 */
final class ContentPersonalizer
{
    public static function prepare(ApprovedPattern $pattern, array $facts): array
    {
        $values = [];
        $pending = [];
        $warnings = [];
        foreach ($pattern->inventory() as $slot) {
            if (isset($slot['binding'])) {
                // Flat, explicitly supplied facts replace implicit context paths.
                $value = $facts[$slot['binding']] ?? null;
                $error = ApprovedPattern::valueError($slot, $value);
                if ($error !== null) {
                    $value = $slot['fallback'];
                    $warnings[] = self::warning($slot, $facts[$slot['binding']] ?? null, $value, "binding {$slot['binding']}: {$error}; approved fallback");
                }
                $values[$slot['id']] = $value;
            } else {
                $pending[] = $slot;
            }
        }
        return ['values' => $values, 'pending' => $pending, 'warnings' => $warnings];
    }

    /** Missing, duplicate and invalid items remain pending for targeted retry. */
    public static function consume(array $pending, array $response): array
    {
        $received = [];
        $warnings = [];
        $expected = array_column($pending, null, 'id');
        foreach (array_diff_key($response, ['content' => true]) as $key => $value) {
            $warnings[] = ['block_path' => [], 'slot' => null, 'authored' => [$key => $value],
                'delivered' => 'removed', 'disposition' => 'undeclared response field removed'];
        }
        $content = $response['content'] ?? [];
        if (!is_array($content)) {
            $content = [];
        }
        foreach ($content as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || !isset($expected[$item['id']])) {
                $warnings[] = ['block_path' => [], 'slot' => is_array($item) ? ($item['id'] ?? null) : null,
                    'authored' => $item, 'delivered' => 'removed', 'disposition' => 'undeclared response item removed'];
                continue;
            }
            foreach (array_diff_key($item, ['id' => true, 'e' => true]) as $key => $value) {
                $warnings[] = ['block_path' => $expected[$item['id']]['block_path'], 'slot' => $item['id'],
                    'authored' => [$key => $value], 'delivered' => 'removed',
                    'disposition' => 'undeclared response item field removed'];
            }
            $received[$item['id']][] = $item['e'] ?? null;
        }
        $values = [];
        $retry = [];
        foreach ($pending as $slot) {
            $items = $received[$slot['id']] ?? [];
            $error = count($items) === 1 ? ApprovedPattern::valueError($slot, $items[0]) : 'missing or duplicate item';
            if ($error !== null) {
                $slot['error'] = $error;
                $slot['authored'] = count($items) === 1 ? $items[0] : $items;
                $retry[] = $slot;
            } else {
                $values[$slot['id']] = $items[0];
            }
        }
        return ['values' => $values, 'pending' => $retry, 'warnings' => $warnings];
    }

    public static function finish(array $pending): array
    {
        $values = [];
        $warnings = [];
        foreach ($pending as $slot) {
            $values[$slot['id']] = $slot['fallback'];
            $warnings[] = self::warning($slot, $slot['authored'] ?? null, $slot['fallback'], ($slot['error'] ?? 'missing response') . '; approved fallback after retry');
        }
        return ['values' => $values, 'warnings' => $warnings];
    }

    public static function request(array $pending, array $facts): array
    {
        $items = array_map(static fn ($slot) => [
            'id' => $slot['id'], 'field' => $slot['field'], 'example' => $slot['example'],
            'instruction' => $slot['instruction'] ?? '', 'max_words' => $slot['max_words'] ?? null,
            'previous_error' => $slot['error'] ?? null,
        ], $pending);
        return [
            'prompt' => "Personalize only the requested plain-text content slots using the supplied facts. "
                . "Examples illustrate length and purpose; they are not facts. Do not invent factual claims. "
                . "Return each requested ID exactly once as {\"content\":[{\"id\":\"slot-id\",\"e\":\"value\"}]}. "
                . "Do not return markup, layout, styling, or additional slots.\n"
                . json_encode(['facts' => $facts, 'items' => $items], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'json_schema' => ['name' => 'pattern_content', 'schema' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['content'],
                'properties' => ['content' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'e'],
                    'properties' => ['id' => ['type' => 'string'], 'e' => ['type' => 'string']],
                ]]],
            ]],
        ];
    }

    private static function warning(array $slot, mixed $authored, string $delivered, string $disposition): array
    {
        return ['block_path' => $slot['block_path'], 'slot' => $slot['id'],
            'authored' => $authored, 'delivered' => $delivered, 'disposition' => $disposition];
    }
}
