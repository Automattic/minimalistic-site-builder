<?php
declare(strict_types=1);

use Automattic\SiteBuild\ArchetypeCatalog;
use Automattic\SiteBuild\ArchetypeGallery;
use Automattic\SiteBuild\ArchetypeMockups;
use Automattic\SiteBuild\ArchetypeProposals;
use Automattic\SiteBuild\PromptRenderer;

/**
 * Router for the gallery's dev server (bin/archetypes.php serve).
 *
 * Static files come off disk; two endpoints do the rest:
 *
 *   POST /api/propose  {family, prompt, auto}  → draws one archetype, stores it
 *   POST /api/select   {prompt, ids}           → records the picks for the CLI
 *
 * It binds to 127.0.0.1 and it writes only inside docs/archetypes, but it is
 * still a developer tool with a model call behind a POST: do not expose it.
 */

require_once __DIR__ . '/../../src/bootstrap.php';

$docs = repo_path('docs/archetypes');
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if ($path === '/api/propose' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    respond(propose($docs));
}
if ($path === '/api/select' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    respond(select($docs));
}

// The gallery is rebuilt on every page load: a proposal added a moment ago,
// or a catalog entry added in an editor, shows up on refresh without a
// restart.
if ($path === '/' || $path === '/index.html') {
    file_put_contents($docs . '/index.html', gallery_html($docs));
    header('Content-Type: text/html; charset=utf-8');
    echo file_get_contents($docs . '/index.html');
    exit;
}

return false; // let the built-in server serve shots/ and everything else

/** @return array{status:int,body:array<string,mixed>} */
function propose(string $docs): array
{
    $input = json_body();
    $family = (string) ($input['family'] ?? 'section');
    $auto = (bool) ($input['auto'] ?? false);
    $request = trim((string) ($input['prompt'] ?? ''));
    if (!$auto && $request === '') {
        return ['status' => 400, 'body' => ['error' => 'describe the archetype, or press Add variety']];
    }
    try {
        $store = new ArchetypeProposals($docs . '/proposals');
        $mockups = new ArchetypeMockups(make_llm(), new PromptRenderer(repo_path('prompts')));
        $record = $mockups->draw($family, ArchetypeCatalog::entries(), $store->all(), $auto ? '' : $request);
        $store->save($record);
        return ['status' => 200, 'body' => ['id' => $record['id'], 'family' => $record['family']]];
    } catch (Throwable $e) {
        return ['status' => 500, 'body' => ['error' => $e->getMessage()]];
    }
}

/**
 * Keep the picks where the next session can find them: the page already put
 * the prompt on the clipboard, and a clipboard does not survive a closed tab.
 *
 * @return array{status:int,body:array<string,mixed>}
 */
function select(string $docs): array
{
    $input = json_body();
    $prompt = trim((string) ($input['prompt'] ?? ''));
    if ($prompt === '') {
        return ['status' => 400, 'body' => ['error' => 'nothing selected']];
    }
    $file = $docs . '/selection.md';
    $ids = array_values(array_filter(array_map('strval', (array) ($input['ids'] ?? []))));
    file_put_contents($file, "<!-- picked " . date('c') . " -->\n" . $prompt . "\n");
    return ['status' => 200, 'body' => ['saved' => basename($file), 'count' => count($ids)]];
}

function gallery_html(string $docs): string
{
    $shots = is_file($docs . '/shots/index.json')
        ? (array) json_decode((string) file_get_contents($docs . '/shots/index.json'), true)
        : [];
    return ArchetypeGallery::render(
        ArchetypeCatalog::entries(),
        $shots,
        (new ArchetypeProposals($docs . '/proposals'))->all(),
        live: true,
    );
}

/** @return array<string,mixed> */
function json_body(): array
{
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}

/** @param array{status:int,body:array<string,mixed>} $response */
function respond(array $response): never
{
    http_response_code($response['status']);
    header('Content-Type: application/json');
    echo json_encode($response['body'], JSON_UNESCAPED_SLASHES);
    exit;
}
