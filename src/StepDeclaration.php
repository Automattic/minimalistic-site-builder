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
                self::assertValidProjectPath($path, "StepDeclaration {$kind} path");
            }
        }
    }

    /**
     * Assert that a path uses the declaration grammar: canonical POSIX-style
     * project-relative segments, optionally ending in one directory glob.
     */
    public static function assertValidProjectPath(mixed $path, string $subject): void
    {
        if (!is_string($path) || $path === '') {
            throw new \InvalidArgumentException("{$subject} must be a non-empty string");
        }

        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            $isTerminalGlob = $segment === '*' && $index === $last && $index > 0;
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || str_contains($segment, "\0")
                || str_contains($segment, '\\')
                || (str_contains($segment, '*') && !$isTerminalGlob)
            ) {
                throw new \InvalidArgumentException(
                    "{$subject} must be a canonical project-relative path "
                    . "with only an optional terminal '/*' glob: {$path}"
                );
            }
        }
    }
}
