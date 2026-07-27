<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Registry;

use Automattic\SiteBuild\BlockSerializer\UnsupportedMarkupException;
use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategy;

/** Loads and validates the frozen post-registration Gutenberg snapshot. */
final class BlockRegistry
{
    /** @var array<string,array<string,mixed>> */
    private array $registered;

    /** @var array<string,SaveStrategy> */
    private array $supported;

    /** @var list<string> */
    private array $observed;

    /**
     * @param array<string,array<string,mixed>>|null $registered
     * @param array<string,SaveStrategy>|null $supported
     * @param list<string>|null $observed
     */
    public function __construct(?array $registered = null, ?array $supported = null, ?array $observed = null)
    {
        if ($registered === null) {
            $snapshot = require __DIR__ . '/generated-registry.php';
            if (!is_array($snapshot) || !isset($snapshot['registered']) || !is_array($snapshot['registered'])) {
                throw new \RuntimeException('Invalid generated block registry snapshot');
            }
            $registered = $snapshot['registered'];
            $observed ??= array_values($snapshot['observed'] ?? []);
        }
        $supported ??= require __DIR__ . '/supported-blocks.php';
        $observed ??= [];

        $this->registered = $registered;
        $this->supported = $supported;
        $this->observed = array_values(array_unique($observed));
        sort($this->observed, SORT_STRING);
        $this->assertConsistent();
    }

    /** @return list<string> */
    public function registeredNames(): array
    {
        $names = array_keys($this->registered);
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return list<string> */
    public function supportedNames(): array
    {
        $names = array_keys($this->supported);
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return list<string> */
    public function observedNames(): array
    {
        return $this->observed;
    }

    public function isRegistered(string $name): bool
    {
        return isset($this->registered[$name]);
    }

    public function isSupported(string $name): bool
    {
        return isset($this->supported[$name]);
    }

    public function strategy(string $name): SaveStrategy
    {
        if (!$this->isRegistered($name)) {
            return SaveStrategy::MISSING_BLOCK;
        }
        if (!$this->isSupported($name)) {
            throw new UnsupportedMarkupException("Registered block '{$name}' is outside the supported PHP domain");
        }
        return $this->supported[$name];
    }

    /** @return array<string,mixed> */
    public function block(string $name): array
    {
        if (!$this->isRegistered($name)) {
            throw new \RuntimeException("Block '{$name}' is not registered in the frozen snapshot");
        }
        return $this->registered[$name];
    }

    /** @return array<string,array<string,mixed>> */
    public function attributes(string $name): array
    {
        $attrs = $this->block($name)['attributes'] ?? [];
        if (!is_array($attrs)) {
            throw new \RuntimeException("Block '{$name}' has an invalid attribute snapshot");
        }
        return $attrs;
    }

    private function assertConsistent(): void
    {
        foreach ($this->supported as $name => $strategy) {
            if (!$strategy instanceof SaveStrategy) {
                throw new \RuntimeException("Supported block '{$name}' has no explicit save strategy");
            }
            if (!isset($this->registered[$name])) {
                throw new \RuntimeException("Supported block '{$name}' is absent from the registered snapshot");
            }
        }
        foreach ($this->observed as $name) {
            // Unregistered names deliberately exercise MISSING_BLOCK and are
            // not members of the registered observed subset.
            if (isset($this->registered[$name]) && !isset($this->supported[$name])) {
                throw new \RuntimeException("Observed registered block '{$name}' is not supported");
            }
        }
    }
}
