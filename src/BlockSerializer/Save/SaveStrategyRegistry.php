<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Save;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlSerializer;
use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Save\Renderers\CoreBlockRenderer;

/** Resolves every admitted block through its reviewed explicit save strategy. */
final class SaveStrategyRegistry
{
    public function __construct(
        private BlockRegistry $registry,
        private ?CoreBlockRenderer $renderer = null,
        private ?HtmlSerializer $html = null,
    ) {
        $this->renderer ??= new CoreBlockRenderer($registry);
        $this->html ??= new HtmlSerializer();
    }

    /**
     * @param array<string,mixed> $attrs
     * @param string $originalContent Original inner content for MISSING_BLOCK.
     */
    public function save(string $name, array $attrs, string $innerBlocks, string $originalContent = ''): string
    {
        $strategy = $this->registry->strategy($name);
        return match ($strategy) {
            SaveStrategy::DYNAMIC_NULL => '',
            SaveStrategy::INNER_BLOCKS => $innerBlocks,
            SaveStrategy::RAW_CONTENT => $this->rawContent($name, $attrs),
            SaveStrategy::CONDITIONAL => $this->conditional($name, $attrs, $innerBlocks),
            SaveStrategy::STATIC_RENDERER => $this->serializeStatic($name, $attrs, $innerBlocks),
            SaveStrategy::MISSING_BLOCK => $originalContent,
        };
    }

    /** @param array<string,mixed> $attrs */
    private function rawContent(string $name, array $attrs): string
    {
        if ($name !== 'core/html') {
            throw new \RuntimeException("No raw-content adapter for '{$name}'");
        }
        return (string) ($attrs['content'] ?? '');
    }

    /** @param array<string,mixed> $attrs */
    private function conditional(string $name, array $attrs, string $inner): string
    {
        if ($name !== 'core/navigation') {
            throw new \RuntimeException("No conditional save adapter for '{$name}'");
        }
        return !empty($attrs['ref']) ? '' : $inner;
    }

    /** @param array<string,mixed> $attrs */
    private function serializeStatic(string $name, array $attrs, string $inner): string
    {
        $tree = $this->renderer->render($name, $attrs, $inner);
        return $this->html->serialize($tree);
    }
}
