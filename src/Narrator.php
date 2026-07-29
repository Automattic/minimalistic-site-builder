<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Where the pipeline's progress narration goes.
 *
 * The library narrates every recovery, repair and salvage as it runs — one line
 * per event, on the error channel, so it interleaves with a build without
 * polluting stdout. Under the CLI SAPI that channel is PHP's own `STDERR`
 * constant, and for years writing to it directly was enough.
 *
 * It is not enough for an embedded host. `STDERR` is a *constant bound to a
 * stream resource*, and the two halves of that fail independently:
 *
 *   - Under a non-CLI SAPI the constant is never defined at all, so any
 *     `fwrite(STDERR, …)` fatals on an undefined constant.
 *   - Under a long-lived CLI worker the constant is defined, but the process
 *     may close or replace its standard streams after startup. The constant
 *     still exists and still holds the original resource — now closed — and
 *     `fwrite()` fails with "supplied resource is not a valid stream resource".
 *
 * The second case is the nastier one, because the obvious guard does not catch
 * it: `defined('STDERR')` is true, so a definedness check short-circuits and
 * hands back the dead handle. Liveness, not definedness, is the question worth
 * asking, and it has to be asked at write time rather than once at startup —
 * a handle that was alive when the run began can die halfway through it.
 *
 * So narration goes through here instead. Every write resolves a target in
 * order of preference (injected stream, live `STDERR`, a freshly opened
 * `php://stderr`, then a memory sink that discards) and re-resolves whenever
 * the cached one has stopped being a valid resource. A host that wants the
 * narration somewhere specific — a log file, a job transcript — calls
 * setStream() once and every subsequent line follows it.
 *
 * Narration is strictly best-effort: it is commentary on a build, never part
 * of one. Nothing here throws, and a failure to narrate is never a failure to
 * build. Defects that changed delivered output belong in `Project::addWarnings()`
 * — the narration channel alone has never been sufficient for those.
 */
final class Narrator
{
    /** Host-provided target, set via setStream(); null = resolve a default. */
    private static mixed $injected = null;

    /** Last resolved target, reused while it stays a valid resource. */
    private static mixed $resolved = null;

    /** Suppresses all output (handy for tests). */
    private static bool $disabled = false;

    /**
     * Point narration at a stream of the host's choosing.
     *
     * Pass an open, writable stream resource. Passing null clears the override
     * and returns to the default resolution order. The stream is not taken
     * ownership of — the caller opened it and the caller closes it, and if it
     * closes it early, write() notices and falls back rather than fataling.
     *
     * @param resource|null $stream
     */
    public static function setStream(mixed $stream): void
    {
        self::$injected = self::usable($stream) ? $stream : null;
        self::$resolved = null;
    }

    /** Turn narration on/off. Off discards every line. */
    public static function setEnabled(bool $enabled): void
    {
        self::$disabled = !$enabled;
    }

    /** Whether narration is currently enabled. */
    public static function enabled(): bool
    {
        return !self::$disabled;
    }

    /**
     * Drop the injected stream and any cached target, and re-enable output.
     *
     * Restores the state a fresh process starts in. Tests use this between
     * cases; a host that reuses one process across several builds should call
     * it between them so a stream belonging to a finished build is never
     * written to by the next one.
     */
    public static function reset(): void
    {
        self::$injected = null;
        self::$resolved = null;
        self::$disabled = false;
    }

    /**
     * Narrate one line. Never throws.
     *
     * The message is written verbatim — callers own their own formatting,
     * including the trailing newline, exactly as they did when they wrote to
     * STDERR directly.
     */
    public static function write(string $message): void
    {
        if (self::$disabled || $message === '') {
            return;
        }
        try {
            $stream = self::stream();
            if ($stream !== null) {
                @fwrite($stream, $message);
            }
        } catch (\Throwable $e) {
            // Best-effort: a narration failure must never break a build.
        }
    }

    /**
     * The stream narration is currently going to, or null when there is
     * nowhere usable to write.
     *
     * Resolution order: the injected stream, then `STDERR` if it is defined and
     * still live, then a freshly opened `php://stderr`, then `php://memory` as
     * a sink so callers have something valid to write into even where no error
     * channel exists. The result is cached, but only while it stays a valid
     * resource — the moment it does not, the next call resolves again.
     *
     * @return resource|null
     */
    public static function stream(): mixed
    {
        if (self::usable(self::$injected)) {
            return self::$injected;
        }
        if (self::usable(self::$resolved)) {
            return self::$resolved;
        }

        self::$resolved = self::open();
        return self::$resolved;
    }

    /**
     * Open a fresh narration target, best available first.
     *
     * @return resource|null
     */
    private static function open(): mixed
    {
        if (defined('STDERR') && self::usable(constant('STDERR'))) {
            return constant('STDERR');
        }
        foreach (['php://stderr', 'php://memory'] as $target) {
            $stream = @fopen($target, 'w');
            if (self::usable($stream)) {
                return $stream;
            }
        }
        return null;
    }

    /**
     * Whether a value is a stream that can still be written to.
     *
     * `is_resource()` is what distinguishes an open handle from a closed one:
     * closing a stream turns its resource into a "resource (closed)", which
     * fails this check while still being defined and non-null. That is the
     * whole reason this class exists.
     */
    private static function usable(mixed $stream): bool
    {
        return is_resource($stream);
    }
}
