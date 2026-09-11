<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

/**
 * MSB-owned delivery contract for content targeting an existing theme.
 * Destination hosts adapt this declarative bundle to their own importer.
 */
final class ContentBundle
{
    public const VERSION = 1;

    public static function build(array $input, array $output): array
    {
        $sourceById = array_column($input['layouts'], null, 'id');
        if (!isset($output['layouts']) || !is_array($output['layouts']) || !array_is_list($output['layouts'])) {
            throw new \InvalidArgumentException('Pattern output requires a list of layouts');
        }
        $pages = [];
        $sharedParts = [];
        $seen = [];
        foreach ($output['layouts'] as $layout) {
            if (!is_array($layout) || !is_string($layout['id'] ?? null)
                || !isset($sourceById[$layout['id']]) || isset($seen[$layout['id']])) {
                throw new \InvalidArgumentException('Pattern output contains an unknown or duplicate layout');
            }
            $source = $sourceById[$layout['id']];
            if (($layout['role'] ?? null) !== $source['role']
                || !is_string($layout['markup'] ?? null)
                || ($layout['source_hash'] ?? null) !== hash('sha256', $source['markup'])) {
                throw new \InvalidArgumentException("Pattern output layout {$layout['id']} does not match its approved source");
            }
            $seen[$layout['id']] = true;
            $row = [
                'id' => $layout['id'],
                'title' => $layout['role'] === 'page'
                    ? $source['page']['title']
                    : $source['shared_part']['title'],
                'slug' => $layout['role'] === 'page'
                    ? $source['page']['slug']
                    : $source['shared_part']['slug'],
                'content' => $layout['markup'],
                'source_hash' => $layout['source_hash'],
                'content_hash' => hash('sha256', $layout['markup']),
            ];
            if ($layout['role'] === 'page') {
                $pages[] = $row;
            } else {
                $sharedParts[] = $row + ['area' => $source['shared_part']['area']];
            }
        }
        if (count($seen) !== count($sourceById)) {
            throw new \InvalidArgumentException('Pattern output is missing an approved layout');
        }
        return [
            'version' => self::VERSION,
            'kind' => 'wordpress-content',
            'input_hash' => $output['input_hash'],
            'requirements' => ['theme' => $input['delivery']['theme']],
            'site' => ['title' => $input['delivery']['site_title']],
            'brand' => $input['brand'],
            'pages' => $pages,
            'shared_parts' => $sharedParts,
            'media' => array_values($input['media']),
        ];
    }
}
