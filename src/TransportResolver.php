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
 * Rungs are a list, not a conditional chain, so adding a rung is an append.
 * Adding a harness also requires coordinated kind, binary, build, and billing-map edits.
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

    /** @var array<string,array{0:string,1:string}> env var => [required value, kind] */
    private const FINGERPRINTS = [
        'CLAUDECODE' => ['1', TransportChoice::KIND_CLAUDE_CLI],
    ];

    /** Codex markers are sandbox-gated: present or absent, value not meaningful. */
    private const CODEX_MARKERS = ['CODEX_SANDBOX_NETWORK_DISABLED', 'CODEX_THREAD_ID', 'CODEX_SANDBOX'];

    /** Unsupported harness fingerprints that must refuse rather than cross a billing boundary. */
    private const UNSUPPORTED_FINGERPRINTS = [
        'OPENCODE'        => ['1', 'OpenCode'],
        'PI_CODING_AGENT' => ['true', 'pi.dev'],
    ];

    /** @var array<string,list<string>> Provider name => accepted API-key env vars. */
    private const PROVIDER_KEYS = [
        'anthropic'  => ['ANTHROPIC_API_KEY'],
        'openai'     => ['OPENAI_API_KEY'],
        'xai'        => ['XAI_API_KEY'],
        'openrouter' => ['OPENROUTER_API_KEY', 'OPEN_ROUTER_API_KEY'],
    ];

    private const COMPILED_DEFAULT_PROVIDER = 'anthropic';
    private const ERROR_VALUE_MAX_BYTES = 80;

    /** Depth cap for the ancestry walk — a cycle or a deep tree must not hang resolution. */
    private const ANCESTRY_MAX_DEPTH = 12;
    private const ANCESTRY_TRUNCATED = 'ancestry walk truncated at depth ' . self::ANCESTRY_MAX_DEPTH;

    /**
     * @param array<string,string|null>   $env      environment as data
     * @param callable(string):?string    $onPath   binary name => absolute path or null
     * @param callable():list<string>     $ancestry ancestor process names, nearest first
     * @param ?string                     $defaultProvider configured default supplied as data by the caller
     */
    public static function decide(
        array $env,
        callable $onPath,
        callable $ancestry,
        ?string $defaultProvider = null,
    ): TransportChoice
    {
        $ancestryWasTruncated = false;
        $trackedAncestry = static function () use ($ancestry, &$ancestryWasTruncated): array {
            $names = [];
            foreach ($ancestry() as $name) {
                if ($name === self::ANCESTRY_TRUNCATED) {
                    $ancestryWasTruncated = true;
                    continue;
                }
                $names[] = $name;
            }
            return $names;
        };
        $rungs = [
            self::rungOverride(...),
            self::rungApiKey(...),
            self::rungFingerprint(...),
            self::rungAncestry(...),
            self::rungSolePath(...),
        ];
        if ($defaultProvider === null || trim($defaultProvider) === '') {
            $defaultProvider = self::COMPILED_DEFAULT_PROVIDER;
        }
        foreach ($rungs as $rung) {
            $choice = $rung($env, $onPath, $trackedAncestry, $defaultProvider);
            if ($choice !== null) {
                if ($ancestryWasTruncated) {
                    return new TransportChoice(
                        $choice->kind,
                        $choice->reason . ' (' . self::ANCESTRY_TRUNCATED . ')',
                        $choice->binary,
                        $choice->provider,
                    );
                }
                return $choice;
            }
        }
        throw new TransportUnavailable(
            'No LLM transport could be resolved. Set ANTHROPIC_API_KEY (or your provider\'s key) '
            . 'for the metered API, or SITE_BUILD_LLM=claude-cli|codex-cli|grok-cli to spend a '
            . 'coding-agent subscription.'
        );
    }

    /**
     * Construct the chosen transport. Harness choices touch the filesystem;
     * embedding hosts inject their API factory without loading CLI bootstrap glue.
     * The factory owns credential validation for its transport.
     *
     * @param null|callable(string):Llm $apiFactory
     */
    public static function build(
        TransportChoice $choice,
        ?callable $apiFactory = null,
        ?string $harnessModel = null,
    ): Llm {
        if ($choice->kind === TransportChoice::KIND_API) {
            if ($apiFactory === null) {
                throw new TransportUnavailable(
                    'Transport api cannot be built because the host must supply an API factory '
                    . 'as the second argument to TransportResolver::build().'
                );
            }
            if ($choice->provider === null || trim($choice->provider) === '') {
                throw new TransportUnavailable(
                    'Transport api has no resolved provider. Obtain the choice from TransportResolver::decide(), '
                    . 'or construct it with a canonical provider: anthropic, openai, xai, or openrouter.'
                );
            }
            $provider = self::normalizeProvider($choice->provider, 'resolved API provider');

            return $apiFactory($provider);
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
            TransportChoice::KIND_CLAUDE_CLI => $harnessModel === null || trim($harnessModel) === ''
                ? throw new TransportUnavailable(
                    'Transport claude-cli cannot be built because its harness model is missing. '
                    . 'Pass a non-blank model as the third argument to TransportResolver::build().'
                )
                : new ClaudeCliLlm($harnessModel, $binary),
            TransportChoice::KIND_CODEX_CLI => $harnessModel === null || trim($harnessModel) === ''
                ? throw new TransportUnavailable(
                    'Transport codex-cli cannot be built because its harness model is missing. '
                    . 'Pass a non-blank model as the third argument to TransportResolver::build().'
                )
                : new CodexCliLlm($harnessModel, $binary),
            TransportChoice::KIND_GROK_CLI => $harnessModel === null || trim($harnessModel) === ''
                ? throw new TransportUnavailable(
                    'Transport grok-cli cannot be built because its harness model is missing. '
                    . 'Pass a non-blank model as the third argument to TransportResolver::build().'
                )
                : new GrokCliLlm($harnessModel, $binary),
            default => throw new TransportUnavailable(
                "Transport {$choice->kind} is resolved but not implemented. "
                . 'Set SITE_BUILD_LLM=api with the configured provider key.'
            ),
        };
    }

    /** Canonical environment variable carrying one provider's API credential. */
    public static function credentialVariableFor(string $provider): ?string
    {
        $provider = self::normalizeProvider($provider, 'API provider');
        return self::PROVIDER_KEYS[$provider][0] ?? null;
    }

    /**
     * Harness transports shell out, so a host with proc_open disabled cannot use
     * them. Checked when build() constructs the transport so this reads as a named
     * configuration error before the first completion.
     */
    public static function assertSubprocessesAvailable(): void
    {
        if (!function_exists('proc_open')) {
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
        $provider = '';
        $reason = $choice->reason;
        if ($choice->kind === TransportChoice::KIND_API) {
            $canonicalProvider = $choice->provider === null || trim($choice->provider) === ''
                ? 'unresolved'
                : self::normalizeProvider($choice->provider, 'resolved API provider');
            $provider = "; provider: {$canonicalProvider}";
            $reason = preg_replace('/\s*\(provider:\s*[^()]+\)/i', '', $reason) ?? $reason;
            $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? $reason);
            if ($reason === '') {
                $reason = 'host-supplied API choice';
            }
        }
        $where = $choice->binary !== null ? " via {$choice->binary}" : '';
        return "Transport: {$choice->kind}{$where} ({$billing}{$provider}) — resolved by {$reason}";
    }

    /** Absolute path to an executable on PATH, or null. */
    public static function binaryPath(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if (!self::isAbsolutePath($dir)) {
                continue;
            }
            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (self::isAbsolutePath($candidate) && is_file($candidate) && is_executable($candidate)) {
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
     * A terminal truncation notice is appended after process names when the
     * depth cap stops the walk, so decide() can keep its audit reason truthful.
     *
     * @return list<string>
     */
    public static function ancestry(): array
    {
        if (!function_exists('shell_exec')) {
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
            $rawPpid = trim($parts[0]);
            $name = basename(trim($parts[1]));
            if ($rawPpid === '' || !ctype_digit($rawPpid) || $name === '') {
                break;
            }
            $next = (int) $rawPpid;
            $names[] = $name;
            if ($next === $pid) {
                break;
            }
            $pid = $next;
        }
        if ($depth >= self::ANCESTRY_MAX_DEPTH && $pid > 1) {
            $names[] = self::ANCESTRY_TRUNCATED;
        }
        return $names;
    }

    /** Rung 1 — explicit intent. Validates; never falls through to another billing path. */
    private static function rungOverride(
        array $env,
        callable $onPath,
        callable $ancestry,
        string $defaultProvider,
    ): ?TransportChoice
    {
        $override = trim($env['SITE_BUILD_LLM'] ?? '');
        if ($override === '') {
            return null;
        }
        if (!in_array($override, TransportChoice::KINDS, true)) {
            $display = self::safeForMessage($override);
            throw new TransportUnavailable(
                "Unknown SITE_BUILD_LLM '{$display}'. Valid values: " . implode(', ', TransportChoice::KINDS)
            );
        }
        if ($override === TransportChoice::KIND_API) {
            $provider = self::apiProvider($env, $defaultProvider);
            return new TransportChoice(
                $override,
                "SITE_BUILD_LLM=api (provider: {$provider})",
                null,
                $provider,
            );
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
    private static function rungApiKey(
        array $env,
        callable $onPath,
        callable $ancestry,
        string $defaultProvider,
    ): ?TransportChoice
    {
        $configured = strtolower(trim((string) ($env['LLM_PROVIDER'] ?? '')));
        $explicit = $configured !== '';
        $provider = self::apiProvider($env, $defaultProvider);
        foreach (self::PROVIDER_KEYS[$provider] as $var) {
            if (trim((string) ($env[$var] ?? '')) !== '') {
                return new TransportChoice(
                    TransportChoice::KIND_API,
                    "{$var} present (provider: {$provider})",
                    null,
                    $provider,
                );
            }
        }
        if ($explicit) {
            throw new TransportUnavailable(
                "LLM_PROVIDER={$provider} requires " . implode(' or ', self::PROVIDER_KEYS[$provider]) . '. '
                . 'Set its provider key, or set SITE_BUILD_LLM to an available harness transport.'
            );
        }
        $otherKeys = self::presentProviderKeys($env, $provider);
        if ($otherKeys !== []) {
            throw new TransportUnavailable(
                "Default provider {$provider} has no " . implode(' or ', self::PROVIDER_KEYS[$provider])
                . ', but other provider keys are present: ' . implode(', ', $otherKeys) . '. '
                . 'Set SITE_BUILD_LLM=api|claude-cli|codex-cli|grok-cli explicitly. '
                . 'Set LLM_PROVIDER only to use the present API key(s) and choose their provider; '
                . 'otherwise unset the listed key(s).'
            );
        }
        return null;
    }

    /** Rung 3 — exact-value fingerprints; live ancestry wins inherited supported signals. */
    private static function rungFingerprint(
        array $env,
        callable $onPath,
        callable $ancestry,
        string $defaultProvider,
    ): ?TransportChoice
    {
        $unsupported = [];
        foreach (self::UNSUPPORTED_FINGERPRINTS as $var => [$want, $name]) {
            if (($env[$var] ?? null) === $want) {
                $unsupported[] = "{$name} ({$var}={$want})";
            }
        }
        if ($unsupported !== []) {
            throw new TransportUnavailable(
                'Unsupported harness fingerprint: ' . implode(', ', $unsupported) . '. '
                . 'Set SITE_BUILD_LLM=api|claude-cli|codex-cli|grok-cli explicitly; '
                . 'OpenCode and pi.dev transports are not implemented.'
            );
        }

        $matches = [];
        foreach (self::FINGERPRINTS as $var => [$want, $kind]) {
            if (($env[$var] ?? null) === $want) {
                $matches[$kind][] = "{$var}={$want}";
            }
        }
        foreach (self::CODEX_MARKERS as $var) {
            if (trim((string) ($env[$var] ?? '')) !== '') {
                $matches[TransportChoice::KIND_CODEX_CLI][] = "{$var} present";
            }
        }
        if ($matches !== []) {
            $ancestryMatches = [];
            foreach ($ancestry() as $name) {
                $kind = self::HARNESSES[strtolower(trim($name))] ?? null;
                if ($kind !== null && !isset($ancestryMatches[$kind])) {
                    $ancestryMatches[$kind] = $name;
                }
            }
            if (count($ancestryMatches) > 1) {
                $names = array_map(
                    static fn (string $kind): string => self::BINARY_FOR[$kind],
                    array_keys($ancestryMatches),
                );
                throw self::ambiguousTransport($names, 'process ancestry identifies multiple harnesses');
            }
            if ($ancestryMatches !== []) {
                $kind = array_key_first($ancestryMatches);
                $name = $ancestryMatches[$kind];
                $ignored = [];
                foreach ($matches as $fingerprintKind => $signals) {
                    if ($fingerprintKind === $kind) {
                        continue;
                    }
                    foreach ($signals as $signal) {
                        $ignored[] = "inherited {$signal} ignored";
                    }
                }
                $reason = "process ancestry found {$name}"
                    . ($ignored === [] ? '' : ' (' . implode('; ', $ignored) . ')');
                return self::harnessChoice($kind, $reason, $onPath);
            }
        }
        if (count($matches) > 1) {
            $names = array_map(
                static fn (string $kind): string => self::BINARY_FOR[$kind],
                array_keys($matches),
            );
            throw self::ambiguousTransport(
                $names,
                'environment fingerprints identify multiple harnesses',
            );
        }
        if ($matches === []) {
            return null;
        }
        $kind = array_key_first($matches);
        return self::harnessChoice($kind, 'env fingerprint ' . implode(', ', $matches[$kind]), $onPath);
    }

    /**
     * Rung 4 — process ancestry. Load-bearing rather than exotic: codex's env
     * markers are sandbox-gated and vanish under danger-full-access, so an
     * ancestor named `codex` is its only reliable signal.
     */
    private static function rungAncestry(
        array $env,
        callable $onPath,
        callable $ancestry,
        string $defaultProvider,
    ): ?TransportChoice
    {
        $matches = [];
        foreach ($ancestry() as $name) {
            $kind = self::HARNESSES[strtolower(trim($name))] ?? null;
            if ($kind !== null && !isset($matches[$kind])) {
                $matches[$kind] = $name;
            }
        }
        if (count($matches) > 1) {
            $names = array_map(
                static fn (string $kind): string => self::BINARY_FOR[$kind],
                array_keys($matches),
            );
            throw self::ambiguousTransport($names, 'process ancestry identifies multiple harnesses');
        }
        if ($matches === []) {
            return null;
        }
        $kind = array_key_first($matches);
        $name = $matches[$kind];
        return self::harnessChoice($kind, "process ancestry found '{$name}'", $onPath);
    }

    /** Rung 5 — exactly one harness on PATH. Two or more is ambiguous: refuse. */
    private static function rungSolePath(
        array $env,
        callable $onPath,
        callable $ancestry,
        string $defaultProvider,
    ): ?TransportChoice
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
            throw self::ambiguousTransport(array_keys($found), 'all are on PATH and nothing else identifies the harness');
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

    /** Resolve and canonicalize the API provider without reading ambient state. */
    private static function apiProvider(array $env, string $defaultProvider): string
    {
        $configured = strtolower(trim((string) ($env['LLM_PROVIDER'] ?? '')));
        return self::normalizeProvider(
            $configured !== '' ? $configured : $defaultProvider,
            $configured !== '' ? 'LLM_PROVIDER' : 'default provider',
        );
    }

    /** @return list<string> */
    private static function presentProviderKeys(array $env, string $exceptProvider): array
    {
        $present = [];
        foreach (self::PROVIDER_KEYS as $provider => $keys) {
            if ($provider === $exceptProvider) {
                continue;
            }
            foreach ($keys as $key) {
                if (trim((string) ($env[$key] ?? '')) !== '') {
                    $present[$key] = true;
                }
            }
        }
        return array_keys($present);
    }

    private static function normalizeProvider(string $provider, string $source = 'LLM_PROVIDER'): string
    {
        $provider = strtolower(trim($provider));
        if ($provider === 'grok') {
            $provider = 'xai';
        }
        if (!array_key_exists($provider, self::PROVIDER_KEYS)) {
            throw new TransportUnavailable(
                "Unknown {$source} '" . self::safeForMessage($provider) . "'. Valid providers: "
                . implode(', ', array_keys(self::PROVIDER_KEYS)) . '; grok is an alias for xai'
            );
        }
        return $provider;
    }

    /** @param list<string> $names */
    private static function ambiguousTransport(array $names, string $detail): TransportUnavailable
    {
        return new TransportUnavailable(
            'Ambiguous transport: ' . implode(', ', $names) . " {$detail}. Set SITE_BUILD_LLM to choose one."
        );
    }

    private static function safeForMessage(string $value): string
    {
        $value = preg_replace('/[\\x00-\\x1F\\x7F]/', '', $value) ?? '';
        if (strlen($value) > self::ERROR_VALUE_MAX_BYTES) {
            return substr($value, 0, self::ERROR_VALUE_MAX_BYTES) . '...';
        }
        return $value;
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if (DIRECTORY_SEPARATOR === '/') {
            return $path[0] === '/';
        }
        return $path[0] === '/' || $path[0] === '\\' || (strlen($path) >= 3
            && ctype_alpha($path[0])
            && $path[1] === ':'
            && ($path[2] === '/' || $path[2] === '\\'));
    }
}
