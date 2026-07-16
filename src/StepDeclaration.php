<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Self-description of one Step: identity, project files read/written, and
 * whether the step fans out concurrent work. Pure data for validation and
 * host-side graph export.
 */
final class StepDeclaration
{
    /**
     * @param list<string> $reads  Project-relative paths; may end with /*
     * @param list<string> $writes Project-relative paths; may end with /*
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $reads,
        public readonly array $writes,
        public readonly bool $concurrent = false,
    ) {
        if ($this->id === '') {
            throw new \InvalidArgumentException('StepDeclaration id must be non-empty');
        }
        foreach (['reads' => $this->reads, 'writes' => $this->writes] as $kind => $paths) {
            foreach ($paths as $path) {
                if (!is_string($path) || $path === '') {
                    throw new \InvalidArgumentException("StepDeclaration {$kind} path must be a non-empty string");
                }
                if (str_starts_with($path, '/') || str_contains($path, '..')) {
                    throw new \InvalidArgumentException(
                        "StepDeclaration {$kind} path must be project-relative without '..': {$path}"
                    );
                }
            }
        }
    }
}
