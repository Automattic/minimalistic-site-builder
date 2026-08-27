<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

/**
 * JSON client for the msb-companion routes on a sandbox WordPress.
 *
 * Every request goes through the `?rest_route=` form: the sandbox flips to
 * pretty permalinks lazily, and rest_route works on both permalink modes from
 * the first request. Errors follow the WP REST envelope ({code, message,
 * data:{status, hint?}}) and surface as TreeGraphException so steps report
 * the companion's own diagnosis, not a curl code.
 */
final class SandboxClient
{
    private const NAMESPACE_PATH = '/msb-companion/v1';

    public function __construct(private readonly string $baseUrl) {}

    /** The harness page URL for the Node driver. */
    public function harnessUrl(): string
    {
        return $this->url('/harness');
    }

    /** @return array{fingerprint: string} */
    public function fingerprint(): array
    {
        return $this->request('GET', '/fingerprint');
    }

    /** @return array<string,mixed> the manifest */
    public function manifest(bool $refresh = false): array
    {
        return $this->request('GET', '/manifest', null, $refresh ? ['refresh' => '1'] : []);
    }

    /** @return list<array{name: string, title: string, categories: array, content: string, parsed: array}> */
    public function patterns(): array
    {
        return $this->request('GET', '/patterns');
    }

    /**
     * Registry-shape validation of one TreeIR document.
     *
     * @param array<string,mixed> $tree
     * @return array{valid: bool, epoch_ok: bool, server_fingerprint: string, diagnostics: array}
     */
    public function validate(array $tree): array
    {
        return $this->request('POST', '/validate', $tree);
    }

    /**
     * @param array<string,mixed> $tokens DesignTokens document
     * @return array<string,mixed>
     */
    public function tokensApply(array $tokens, bool $dryRun): array
    {
        return $this->request('POST', '/theme/tokens', $tokens + ($dryRun ? ['dry_run' => true] : []));
    }

    /** @return array{id: int, url: string, color: string, slug: string, reused: bool} */
    public function placeholder(string $color, ?int $width = null, ?int $height = null): array
    {
        $body = ['color' => $color];
        if ($width !== null) {
            $body['width'] = $width;
        }
        if ($height !== null) {
            $body['height'] = $height;
        }
        return $this->request('POST', '/placeholder', $body);
    }

    /**
     * @param array{title: string, slug: string, content: string, template?: string} $body
     * @return array{id: int, link: string, updated: bool}
     */
    public function publishPage(array $body): array
    {
        return $this->request('POST', '/publish/page', $body);
    }

    /** @param array<string,mixed> $body title/description/show_on_front/page_on_front */
    public function publishSettings(array $body): array
    {
        return $this->request('POST', '/publish/settings', $body);
    }

    /** @return array{written: bool, id?: int, reason?: string} */
    public function publishTemplatePart(string $slug, string $content): array
    {
        return $this->request('POST', '/publish/template-part', ['slug' => $slug, 'content' => $content]);
    }

    /** @return array{id: int} */
    public function publishNavigation(string $content): array
    {
        return $this->request('POST', '/publish/navigation', ['content' => $content]);
    }

    /** @return array{deleted: int} */
    public function deleteSamplePage(): array
    {
        return $this->request('POST', '/publish/delete-sample-page', []);
    }

    /** @return array{id: int, url: string} */
    public function uploadMedia(string $filename, string $mime, string $bytes, string $alt = ''): array
    {
        return $this->request('POST', '/publish/media', [
            'filename' => $filename,
            'mime'     => $mime,
            'data'     => base64_encode($bytes),
            'alt'      => $alt,
        ]);
    }

    /** @return array{id: int, link: string} */
    public function updatePageContent(int $id, string $content): array
    {
        return $this->request('POST', '/publish/update-page', ['id' => $id, 'content' => $content]);
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,string>     $query
     * @return array<mixed>
     */
    private function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $ch = curl_init($this->url($path, $query));
        if ($ch === false) {
            throw new TreeGraphException('sandbox_unreachable', 'Could not initialize curl.');
        }
        $headers = [
            'Accept: application/json',
            // Playground's --login intercepts the first cookie-less request
            // with a 302 auto-login round-trip; this cookie tells it that
            // dance already happened, so API calls answer directly.
            'Cookie: playground_auto_login_already_happened=1',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode(
                $body,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new TreeGraphException(
                'sandbox_unreachable',
                "The sandbox did not answer {$method} {$path}: {$error}",
                'Is the Playground sandbox still running? sandbox.json records its pid and log.',
            );
        }

        // A PHP notice ahead of the JSON body must not turn a readable answer
        // into a parse failure: salvage from the first brace/bracket.
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $first = min(
                ($p = strpos((string) $raw, '{')) === false ? PHP_INT_MAX : $p,
                ($p = strpos((string) $raw, '[')) === false ? PHP_INT_MAX : $p,
            );
            if ($first !== PHP_INT_MAX) {
                $decoded = json_decode(substr((string) $raw, $first), true);
            }
        }
        if (!is_array($decoded)) {
            throw new TreeGraphException(
                'sandbox_bad_response',
                "{$method} {$path} returned HTTP {$status} with a non-JSON body: " . substr((string) $raw, 0, 300),
            );
        }

        if ($status >= 400) {
            $code = (string) ($decoded['code'] ?? 'sandbox_error');
            $message = (string) ($decoded['message'] ?? "HTTP {$status}");
            $hint = (string) ($decoded['data']['hint'] ?? '');
            throw new TreeGraphException($code, "{$method} {$path}: {$message}", $hint, ['status' => $status]);
        }

        return $decoded;
    }

    /** @param array<string,string> $query */
    private function url(string $path, array $query = []): string
    {
        $url = rtrim($this->baseUrl, '/') . '/?rest_route=' . rawurlencode(self::NAMESPACE_PATH . $path);
        foreach ($query as $key => $value) {
            $url .= '&' . rawurlencode($key) . '=' . rawurlencode($value);
        }
        return $url;
    }
}
