<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Chooses the transport once, at the entry point, from an ordered ladder.
 *
 * decide() is pure: it takes the environment as data and never touches the
 * filesystem, so every rung is testable with no binary installed and no key
 * present. That matters because this is the code that must never guess a
 * billing path — a wrong answer here silently spends the wrong budget.
 *
 * Rungs are a list, not a conditional chain, so adding a harness is an append.
 */
final class TransportResolver
{
    /** Binary name => transport kind. Order fixes the rung-5 ambiguity message. */
    public const HARNESSES = [
        'claude' => TransportChoice::KIND_CLAUDE_CLI,
        'codex'  => TransportChoice::KIND_CODEX_CLI,
        'grok'   => TransportChoice::KIND_GROK_CLI,
    ];

    /** Transport kind => the binary it shells out to. */
    private const BINARY_FOR = [
        TransportChoice::KIND_CLAUDE_CLI => 'claude',
        TransportChoice::KIND_CODEX_CLI  => 'codex',
        TransportChoice::KIND_GROK_CLI   => 'grok',
    ];

    /**
     * Exact-value env fingerprints. The tools disagree on the value — three use
     * '1' and pi uses the string 'true' — so these compare exactly rather than
     * testing truthiness.
     *
     * @var array<string,array{0:string,1:string}> env var => [required value, kind]
     */
    private const FINGERPRINTS = [
        'CLAUDECODE' => ['1', TransportChoice::KIND_CLAUDE_CLI],
    ];

    /** Codex markers are sandbox-gated: present or absent, value not meaningful. */
    private const CODEX_MARKERS = ['CODEX_SANDBOX_NETWORK_DISABLED', 'CODEX_THREAD_ID', 'CODEX_SANDBOX'];

    /** Provider name => the env var holding its API key. */
    private const PROVIDER_KEYS = [
        'anthropic'  => 'ANTHROPIC_API_KEY',
        'xai'        => 'XAI_API_KEY',
        'grok'       => 'XAI_API_KEY',
        'openai'     => 'OPENAI_API_KEY',
        'openrouter' => 'OPENROUTER_API_KEY',
    ];

    /** Depth cap for the ancestry walk — a cycle or a deep tree must not hang resolution. */
    private const ANCESTRY_MAX_DEPTH = 12;

    /**
     * @param array<string,string>        $env      environment as data
     * @param callable(string):?string    $onPath   binary name => absolute path or null
     * @param callable():list<string>     $ancestry ancestor process names, nearest first
     */
    public static function decide(array $env, callable $onPath, callable $ancestry): TransportChoice
    {
        foreach (['override', 'apiKey', 'fingerprint', 'ancestry', 'solePath'] as $rung) {
            $choice = self::{'rung' . ucfirst($rung)}($env, $onPath, $ancestry);
            if ($choice !== null) {
                return $choice;
            }
        }
        throw new TransportUnavailable(
            'No LLM transport could be resolved. Set ANTHROPIC_API_KEY (or your provider\'s key) '
            . 'for the metered API, or SITE_BUILD_LLM=claude-cli|codex-cli|grok-cli to spend a '
            . 'coding-agent subscription.'
        );
    }

    /** Construct the chosen transport. The only half that touches the filesystem. */
    public static function build(TransportChoice $choice, string $model): Llm
    {
        if ($choice->kind === TransportChoice::KIND_API) {
            return make_llm();
        }
        self::assertSubprocessesAvailable();
        $binary = $choice->binary;
        if ($binary === null || !is_file($binary) || !is_executable($binary)) {
            throw new TransportUnavailable(
                "Transport {$choice->kind} resolved to '" . ($binary ?? 'nothing') . "', which is not an executable file. "
                . 'Install it, or set SITE_BUILD_LLM=api with the configured provider key.'
            );
        }
        return match ($choice->kind) {
            TransportChoice::KIND_CLAUDE_CLI => throw new TransportUnavailable(
                'Transport claude-cli is resolved but not yet implemented. '
                . 'Use SITE_BUILD_LLM=api with the configured provider key until this transport is implemented.'
            ),
            TransportChoice::KIND_CODEX_CLI => throw new TransportUnavailable(
                'Transport codex-cli is resolved but not yet implemented. '
                . 'Use SITE_BUILD_LLM=api with the configured provider key until this transport is implemented.'
            ),
            TransportChoice::KIND_GROK_CLI => throw new TransportUnavailable(
                'Transport grok-cli is resolved but not yet implemented. '
                . 'Use SITE_BUILD_LLM=api with the configured provider key until this transport is implemented.'
            ),
            default => throw new TransportUnavailable(
                "Transport {$choice->kind} is resolved but not implemented. "
                . 'Set SITE_BUILD_LLM=api with the configured provider key.'
            ),
        };
    }

    /**
     * Harness transports shell out, so a host with proc_open disabled cannot use
     * them. Checked at resolve time so this reads as a named configuration error
     * rather than a fatal on the first completion, after the build has started.
     */
    public static function assertSubprocessesAvailable(): void
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            throw new TransportUnavailable(
                'Harness transports need proc_open, which this PHP disables via disable_functions. '
                . 'Set SITE_BUILD_LLM=api with LLM_PROVIDER and its provider key instead.'
            );
        }
    }

    /** The line echoed before any spend. Names the rung, not just the transport. */
    public static function describe(TransportChoice $choice): string
    {
        $billing = $choice->isSubscription() ? 'subscription' : 'metered';
        $where = $choice->binary !== null ? " via {$choice->binary}" : '';
        return "Transport: {$choice->kind}{$where} ({$billing}) — resolved by {$choice->reason}";
    }

    /** Absolute path to an executable on PATH, or null. */
    public static function binaryPath(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '/') . '/' . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Ancestor process names, nearest first. Best effort by design: an
     * unreadable `ps` returns an empty list rather than failing resolution,
     * because ancestry is one rung of a ladder, not the whole answer.
     *
     * @return list<string>
     */
    public static function ancestry(): array
    {
        if (!function_exists('proc_open') || !function_exists('shell_exec')) {
            return [];
        }
        $names = [];
        $pid = function_exists('posix_getppid') ? posix_getppid() : getmypid();
        for ($depth = 0; $depth < self::ANCESTRY_MAX_DEPTH && $pid > 1; $depth++) {
            $out = @shell_exec('ps -o ppid=,comm= -p ' . (int) $pid . ' 2>/dev/null');
            if (!is_string($out) || trim($out) === '') {
                break;
            }
            $parts = preg_split('/\s+/', trim($out), 2);
            if ($parts === false || count($parts) < 2) {
                break;
            }
            $names[] = basename(trim($parts[1]));
            $next = (int) $parts[0];
            if ($next === $pid) {
                break;
            }
            $pid = $next;
        }
        return $names;
    }

    /** Rung 1 — explicit intent. Validates; never falls through to another billing path. */
    private static function rungOverride(array $env, callable $onPath, callable $ancestry): ?TransportChoice
    {
        $override = trim($env['SITE_BUILD_LLM'] ?? '');
        if ($override === '') {
            return null;
        }
        if (!in_array($override, TransportChoice::KINDS, true)) {
            throw new TransportUnavailable(
                "Unknown SITE_BUILD_LLM '{$override}'. Valid values: " . implode(', ', TransportChoice::KINDS)
            );
        }
        if ($override === TransportChoice::KIND_API) {
            return new TransportChoice($override, 'SITE_BUILD_LLM=api');
        }
        $binary = self::BINARY_FOR[$override];
        $path = $onPath($binary);
        if ($path === null) {
            throw new TransportUnavailable(
                "SITE_BUILD_LLM={$override} but '{$binary}' is not on PATH. Install it or choose another transport."
            );
        }
        return new TransportChoice($override, "SITE_BUILD_LLM={$override}", $path);
    }

    /**
     * Rung 2 — the configured provider's key. Metered beats subscription by
     * design (the ticket settles this): performance is the priority, and
     * subscription billing requires scrubbing keys, which is an explicit act.
     */
    private static function rungApiKey(array $env, callable $onPath, callable $ancestry): ?TransportChoice
    {
        $provider = strtolower(trim($env['LLM_PROVIDER'] ?? '')) ?: 'anthropic';
        $var = self::PROVIDER_KEYS[$provider] ?? null;
        if ($var === null || trim($env[$var] ?? '') === '') {
            return null;
        }
        return new TransportChoice(TransportChoice::KIND_API, "{$var} present (provider: {$provider})");
    }

    /** Rung 3 — env fingerprint, exact-value. */
    private static function rungFingerprint(array $env, callable $onPath, callable $ancestry): ?TransportChoice
    {
        foreach (self::FINGERPRINTS as $var => [$want, $kind]) {
            if (($env[$var] ?? null) === $want) {
                return self::harnessChoice($kind, "env fingerprint {$var}={$want}", $onPath);
            }
        }
        foreach (self::CODEX_MARKERS as $var) {
            if (array_key_exists($var, $env) && trim($env[$var]) !== '') {
                return self::harnessChoice(TransportChoice::KIND_CODEX_CLI, "env fingerprint {$var} present", $onPath);
            }
        }
        return null;
    }

    /**
     * Rung 4 — process ancestry. Load-bearing rather than exotic: codex's env
     * markers are sandbox-gated and vanish under danger-full-access, so an
     * ancestor named `codex` is its only reliable signal.
     */
    private static function rungAncestry(array $env, callable $onPath, callable $ancestry): ?TransportChoice
    {
        foreach ($ancestry() as $name) {
            $kind = self::HARNESSES[strtolower(trim($name))] ?? null;
            if ($kind !== null) {
                return self::harnessChoice($kind, "process ancestry found '{$name}'", $onPath);
            }
        }
        return null;
    }

    /** Rung 5 — exactly one harness on PATH. Two or more is ambiguous: refuse. */
    private static function rungSolePath(array $env, callable $onPath, callable $ancestry): ?TransportChoice
    {
        $found = [];
        foreach (self::HARNESSES as $binary => $kind) {
            if ($onPath($binary) !== null) {
                $found[$binary] = $kind;
            }
        }
        if ($found === []) {
            return null;
        }
        if (count($found) > 1) {
            throw new TransportUnavailable(
                'Ambiguous transport: ' . implode(', ', array_keys($found))
                . ' are all on PATH and nothing else identifies the harness. '
                . 'Set SITE_BUILD_LLM to choose one.'
            );
        }
        $binary = array_key_first($found);
        return self::harnessChoice($found[$binary], "'{$binary}' is the only harness on PATH", $onPath);
    }

    /** Build a harness choice, failing loudly when its binary is absent. */
    private static function harnessChoice(string $kind, string $reason, callable $onPath): TransportChoice
    {
        $binary = self::BINARY_FOR[$kind];
        $path = $onPath($binary);
        if ($path === null) {
            throw new TransportUnavailable(
                "Resolved {$kind} ({$reason}) but '{$binary}' is not on PATH. "
                . 'Install it, or set SITE_BUILD_LLM / a provider API key.'
            );
        }
        return new TransportChoice($kind, $reason, $path);
    }
}
