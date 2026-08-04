<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageClient;
use Automattic\SiteBuild\ImagePromptComposer;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\Steps\GenerateImagesStep;
use Automattic\SiteBuild\GeminiImage;

/**
 * Image-prompt debugger.
 *
 * A standalone page for iterating on AI_IMAGE prompts WITHOUT building a whole
 * theme. It drives the real GenerateImagesStep against a throwaway temp project,
 * so what you see here is exactly what the pipeline would produce: the same
 * prompt composition (ImagePromptComposer), the same site-context grounding, the
 * same Gemini call, the same batching/retry.
 *
 * Run it:
 *   php -S localhost:8080 bin/image-debug.php
 * then open http://localhost:8080/ . Needs GOOGLE_VERTEX_API_TOKEN in .env (the
 * same token the build uses for images).
 *
 * The page POSTs ?action=generate with {site, images:[...]} and renders each
 * returned image. "Generate" on a card sends just that one spec; "Generate all"
 * sends every card in one request (a real concurrent batch through the step).
 */

require_once __DIR__ . '/../src/bootstrap.php';

// The pipeline steps log progress with fwrite(STDERR, …). STDERR is only a
// predefined constant under the CLI SAPI — under the built-in web server it is
// undefined, so define it here (mapped to the server's stderr) before any step runs.
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}

// ---------------------------------------------------------------------------
// The pre-filled site context + 10 example prompts. Authored for the brief:
// "minimalist theme for my photo-journalism portfolio. Argentinean
// photo-journalist based in Buenos Aires, covered the country's most important
// social and political events of the last 20 years."
// ---------------------------------------------------------------------------

/** @return array{name:string,topic:string,description:string} */
function debug_site_context(): array
{
    return [
        'name'        => 'Argentine Photojournalism Portfolio',
        'topic'       => "documentary photojournalism of Argentina's social and political events",
        'description' => 'The portfolio of a Buenos Aires–based Argentinean photojournalist who has '
            . "covered the country's most important social and political events over the last 20 years. "
            . 'Minimalist, restrained, black-and-white-leaning editorial aesthetic.',
    ];
}

/** @return array<int,array{filename:string,subject:string,pageContext:string,style:string,aspectRatio:string}> */
function debug_examples(): array
{
    return [
        [
            'filename'    => 'hero-protest-dusk.jpg',
            'subject'     => 'A lone figure seen from behind facing a vast crowd at a dusk demonstration on a wide '
                . 'Buenos Aires avenue, banners blurred in the distance, smoke and low golden light, documentary '
                . 'reportage feel, subject placed to the right with open low-detail sky to the left',
            'pageContext' => 'full-bleed hero section with the photographer name and tagline overlaid on the left',
            'style'       => 'photorealistic',
            'aspectRatio' => 'landscape',
        ],
        [
            'filename'    => 'about-portrait.jpg',
            'subject'     => 'An environmental portrait of a weathered photojournalist in a plain studio, holding a '
                . 'worn 35mm rangefinder camera, soft window light from the side, neutral grey backdrop, calm and direct gaze',
            'pageContext' => 'portrait image beside the about / biography text',
            'style'       => 'photorealistic',
            'aspectRatio' => 'portrait',
        ],
        [
            'filename'    => 'essay-economic-crisis.jpg',
            'subject'     => 'A long quiet queue of people waiting outside a shuttered bank at dawn, empty street, '
                . 'muted tones, a single shaft of light, restrained black-and-white documentary frame',
            'pageContext' => 'portfolio essay card in a 3-column grid of featured stories',
            'style'       => 'photorealistic',
            'aspectRatio' => 'square',
        ],
        [
            'filename'    => 'essay-plaza-de-mayo.jpg',
            'subject'     => 'A dense crowd filling Plaza de Mayo with flags raised toward the Casa Rosada at golden '
                . 'hour, seen from a high vantage, dust and light in the air, photojournalistic wide shot',
            'pageContext' => 'portfolio essay card in a 3-column grid of featured stories',
            'style'       => 'photorealistic',
            'aspectRatio' => 'square',
        ],
        [
            'filename'    => 'essay-womens-march.jpg',
            'subject'     => "A close, candid frame of marchers wearing green scarves at a women's rights march, "
                . 'raised hands and determined faces, shallow depth of field, soft overcast light, intimate reportage',
            'pageContext' => 'portfolio essay card in a 3-column grid of featured stories',
            'style'       => 'photorealistic',
            'aspectRatio' => 'square',
        ],
        [
            'filename'    => 'essay-human-rights-memory.jpg',
            'subject'     => 'Elderly women with white headscarves walking slowly across an empty plaza at dawn, long '
                . 'shadows, quiet and solemn, restrained documentary black-and-white tone',
            'pageContext' => 'portfolio essay card in a 3-column grid of featured stories',
            'style'       => 'photorealistic',
            'aspectRatio' => 'square',
        ],
        [
            'filename'    => 'feature-newsroom.jpg',
            'subject'     => 'A contact sheet and scattered black-and-white prints on a worn wooden light table, a '
                . 'loupe resting on one frame, warm desk lamp, top-down view, analog editorial mood',
            'pageContext' => 'wide feature image introducing the "process / archive" section',
            'style'       => 'photorealistic',
            'aspectRatio' => 'landscape',
        ],
        [
            'filename'    => 'cta-band-cityscape.jpg',
            'subject'     => 'A minimal, hazy skyline of Buenos Aires at blue hour with large areas of calm empty sky, '
                . 'soft gradient, almost monochrome, very low detail so text reads cleanly on top',
            'pageContext' => 'background of a call-to-action band inviting visitors to commission or get in touch',
            'style'       => 'minimalist',
            'aspectRatio' => 'landscape',
        ],
        [
            'filename'    => 'contact-darkroom.jpg',
            'subject'     => 'A quiet darkroom detail: a print hanging from a clip to dry under dim red safelight, '
                . 'water tray reflections, intimate and still, shallow focus',
            'pageContext' => 'small accent image next to the contact form',
            'style'       => 'photorealistic',
            'aspectRatio' => 'portrait',
        ],
        [
            'filename'    => 'logo-mark.jpg',
            'subject'     => 'An ultra-minimal monogram mark formed from a simple aperture-blade motif, thin lines, '
                . 'high contrast, generous negative space, centered on a plain off-white field',
            'pageContext' => 'site logo mark used in the header and footer',
            'style'       => 'minimalist',
            'aspectRatio' => 'square',
        ],
    ];
}

// ---------------------------------------------------------------------------
// API: POST ?action=generate  { site:{name,topic,description}, images:[ {filename,
// subject, pageContext, style, aspectRatio} ] }  ->  per-image results.
// ---------------------------------------------------------------------------

if (($_GET['action'] ?? '') === 'generate') {
    header('Content-Type: application/json');
    echo json_encode(handle_generate(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Read the POST body and run the generation. @return array<string,mixed> */
function handle_generate(): array
{
    $req = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($req)) {
        return ['ok' => false, 'error' => 'Invalid JSON request body.'];
    }
    return generate_images_for($req, null);
}

/**
 * Core: turn a decoded {site, images:[...]} request into per-image results by
 * running the real GenerateImagesStep against a throwaway temp project.
 *
 * @param array<string,mixed> $req
 * @param ImageClient|null    $client image transport; defaults to the real WPCOM
 *        proxy. Injectable so the flow can be exercised with a fake in tests.
 * @return array<string,mixed>
 */
function generate_images_for(array $req, ?ImageClient $client = null): array
{
    $site = is_array($req['site'] ?? null) ? $req['site'] : [];
    $siteSpec = [
        'name'        => trim((string) ($site['name'] ?? '')),
        'topic'       => trim((string) ($site['topic'] ?? '')),
        'description' => trim((string) ($site['description'] ?? '')),
    ];
    $siteContext = GenerateImagesStep::siteContext($siteSpec);

    $images = is_array($req['images'] ?? null) ? $req['images'] : [];
    if ($images === []) {
        return ['ok' => false, 'error' => 'No images in request.'];
    }

    // Build the images.json specs the step expects. Sanitise the filename to the
    // same charset CollectImagesStep enforces so the on-disk asset is findable.
    $specs = [];
    foreach (array_values($images) as $i => $img) {
        $filename = sanitize_filename((string) ($img['filename'] ?? ''), $i);
        $specs[] = [
            'filename'    => $filename,
            'src'         => "theme:./assets/{$filename}",
            'subject'     => trim((string) ($img['subject'] ?? '')),
            'pageContext' => trim((string) ($img['pageContext'] ?? '')),
            'style'       => trim((string) ($img['style'] ?? '')),
            'aspectRatio' => trim((string) ($img['aspectRatio'] ?? 'landscape')),
            'status'      => 'pending',
        ];
    }

    $store = new ProjectStore(sys_get_temp_dir() . '/builder-image-debug');
    $project = $store->create('run-' . bin2hex(random_bytes(4)));
    $started = microtime(true);
    try {
        $project->writeJson('siteSpec.json', $siteSpec);
        $project->writeJson('images.json', $specs);

        // The real pipeline step does the work: compose → Gemini → batch/retry.
        (new GenerateImagesStep($client ?? make_image_client()))->run($project);

        $done = $project->readJson('images.json');
        $results = [];
        foreach ($done as $spec) {
            $filename = (string) $spec['filename'];
            $dataUri = null;
            if (($spec['status'] ?? '') === 'completed' && $project->exists('theme/assets/' . $filename)) {
                $bytes = $project->readText('theme/assets/' . $filename);
                $dataUri = 'data:' . GeminiImage::mimeForFilename($filename) . ';base64,'
                    . base64_encode($bytes);
            }
            $results[] = [
                'filename'    => $filename,
                'status'      => (string) ($spec['status'] ?? 'unknown'),
                'error'       => $spec['error'] ?? null,
                // Exactly what the step composed and sent for this image.
                'prompt'      => ImagePromptComposer::compose(
                    (string) ($spec['subject'] ?? ''),
                    (string) ($spec['pageContext'] ?? ''),
                    (string) ($spec['style'] ?? ''),
                    $siteContext,
                    '',
                    GeminiImage::mimeForFilename($filename) === 'image/png',
                ),
                'aspectRatio' => GeminiImage::aspectRatio((string) ($spec['aspectRatio'] ?? 'landscape')),
                'dataUri'     => $dataUri,
            ];
        }

        return [
            'ok'          => true,
            'durationMs'  => (int) round((microtime(true) - $started) * 1000),
            'siteContext' => $siteContext,
            'results'     => $results,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    } finally {
        // Throwaway project — never keep it around.
        rrmdir($project->root);
    }
}

function sanitize_filename(string $name, int $index): string
{
    $name = strtolower(trim($name));
    // A .png extension is meaningful (transparent-background asset) — keep it.
    $ext = str_ends_with($name, '.png') ? '.png' : '.jpg';
    $name = preg_replace('/\.(jpe?g|png)$/', '', $name) ?? $name;
    $name = preg_replace('/[^a-z0-9-]+/', '-', $name) ?? '';
    $name = trim($name, '-');
    if ($name === '') {
        $name = 'image-' . ($index + 1);
    }
    return $name . $ext;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

// ---------------------------------------------------------------------------
// Page.
// ---------------------------------------------------------------------------

$site = debug_site_context();
$examples = debug_examples();
$styles = ['photorealistic', 'digital-art', 'illustration', 'minimalist', 'flat-design', '3d-render', 'abstract', 'watercolor'];
$ratios = ['square', 'landscape', 'ultrawide', 'portrait', 'card-landscape', 'card-portrait'];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Image Prompt Debugger</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body {
    margin: 0; font: 14px/1.5 ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
    background: #f5f6f8; color: #1d2330;
  }
  header { position: sticky; top: 0; z-index: 5; background: #ffffff; border-bottom: 1px solid #e1e4ea; padding: 14px 20px; }
  header .row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
  h1 { font-size: 16px; margin: 0; font-weight: 650; }
  .muted { color: #6b7385; }
  main { padding: 20px; max-width: 1400px; margin: 0 auto; }
  fieldset { border: 1px solid #e1e4ea; border-radius: 10px; padding: 12px 14px; margin: 0 0 18px; background: #ffffff; }
  legend { padding: 0 6px; color: #6b7385; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; }
  label { display: block; font-size: 12px; color: #6b7385; margin: 8px 0 3px; }
  input, textarea, select {
    width: 100%; background: #ffffff; color: #1d2330; border: 1px solid #ccd2dd;
    border-radius: 7px; padding: 7px 9px; font: inherit; resize: vertical;
  }
  textarea { min-height: 78px; }
  .site-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; }
  .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 16px; }
  .card { border: 1px solid #e1e4ea; border-radius: 12px; background: #ffffff; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 1px 2px rgba(20,30,50,.04); }
  .card .body { padding: 12px 14px; }
  .card .preview {
    background: #eef0f4 repeating-linear-gradient(45deg, #eef0f4 0 10px, #e6e9ef 10px 20px);
    aspect-ratio: 16 / 9; display: flex; align-items: center; justify-content: center; position: relative;
    border-bottom: 1px solid #e1e4ea;
  }
  .card .preview.square { aspect-ratio: 1 / 1; }
  .card .preview.ultrawide { aspect-ratio: 21 / 9; }
  .card .preview.portrait { aspect-ratio: 9 / 16; }
  .card .preview.card-landscape { aspect-ratio: 4 / 3; }
  .card .preview.card-portrait { aspect-ratio: 3 / 4; }
  .card .preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .card .ph { color: #9aa1b1; font-size: 13px; }
  .meta { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
  .pill { font-size: 11px; padding: 2px 8px; border-radius: 999px; border: 1px solid #d3d8e2; color: #6b7385; }
  .two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  button {
    background: #2f6ae1; color: #fff; border: 0; border-radius: 8px; padding: 8px 14px;
    font: inherit; font-weight: 600; cursor: pointer;
  }
  button.ghost { background: #eef1f6; color: #38415a; border: 1px solid #d3d8e2; }
  button:disabled { opacity: .5; cursor: default; }
  .status { font-size: 12px; margin-top: 8px; min-height: 16px; }
  .status.err { color: #d23c3c; white-space: pre-wrap; }
  .status.ok { color: #1a9e54; }
  .status.run { color: #b07d12; }
  details { margin-top: 8px; }
  summary { cursor: pointer; color: #6b7385; font-size: 12px; }
  pre.prompt { white-space: pre-wrap; background: #f5f6f8; border: 1px solid #e1e4ea; border-radius: 7px; padding: 8px; font-size: 12px; color: #38415a; margin: 6px 0 0; }
  .spacer { flex: 1; }
</style>
</head>
<body>
<header>
  <div class="row">
    <h1>🖼️ Image Prompt Debugger</h1>
    <span class="muted">Runs the real <code>GenerateImagesStep</code> · no theme build</span>
    <span class="spacer"></span>
    <span id="globalStatus" class="muted"></span>
    <button id="generateAll">Generate all</button>
  </div>
</header>
<main>
  <fieldset>
    <legend>Site context (grounds every image)</legend>
    <div class="site-grid">
      <div>
        <label>Name</label>
        <input id="siteName" value="<?= htmlspecialchars($site['name']) ?>">
      </div>
      <div>
        <label>Topic</label>
        <input id="siteTopic" value="<?= htmlspecialchars($site['topic']) ?>">
      </div>
      <div style="grid-column: 1 / -1;">
        <label>Description</label>
        <textarea id="siteDescription" style="min-height: 56px;"><?= htmlspecialchars($site['description']) ?></textarea>
      </div>
    </div>
  </fieldset>

  <div class="cards" id="cards"></div>
</main>

<script>
const EXAMPLES = <?= json_encode($examples, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const STYLES = <?= json_encode($styles) ?>;
const RATIOS = <?= json_encode($ratios) ?>;

const cardsEl = document.getElementById('cards');

function siteContext() {
  return {
    name: document.getElementById('siteName').value,
    topic: document.getElementById('siteTopic').value,
    description: document.getElementById('siteDescription').value,
  };
}

function el(html) { const t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstChild; }

function makeCard(ex, i) {
  const styleOpts = STYLES.map(s => `<option ${s === ex.style ? 'selected' : ''}>${s}</option>`).join('');
  const ratioOpts = RATIOS.map(r => `<option ${r === ex.aspectRatio ? 'selected' : ''}>${r}</option>`).join('');
  const card = el(`
    <div class="card" data-index="${i}">
      <div class="preview ${ex.aspectRatio}"><span class="ph">no image yet</span></div>
      <div class="body">
        <div class="meta">
          <span class="pill">#${i + 1}</span>
          <input class="f-filename" style="flex:1; min-width:120px;" value="${ex.filename}">
        </div>
        <label>Subject — what the image shows &amp; POV</label>
        <textarea class="f-subject">${ex.subject}</textarea>
        <label>Page context — where/how it's used (not drawn)</label>
        <input class="f-pageContext" value="${ex.pageContext}">
        <div class="two">
          <div><label>Style</label><select class="f-style">${styleOpts}</select></div>
          <div><label>Aspect ratio</label><select class="f-aspectRatio">${ratioOpts}</select></div>
        </div>
        <div class="meta" style="margin-top:12px;">
          <button class="gen">Generate</button>
          <span class="spacer"></span>
          <span class="status"></span>
        </div>
        <details><summary>Composed prompt sent to the endpoint</summary><pre class="prompt">—</pre></details>
      </div>
    </div>
  `);
  card.querySelector('.gen').addEventListener('click', () => generateOne(card));
  card.querySelector('.f-aspectRatio').addEventListener('change', e => {
    card.querySelector('.preview').className = 'preview ' + e.target.value;
  });
  return card;
}

function readCard(card) {
  return {
    filename: card.querySelector('.f-filename').value,
    subject: card.querySelector('.f-subject').value,
    pageContext: card.querySelector('.f-pageContext').value,
    style: card.querySelector('.f-style').value,
    aspectRatio: card.querySelector('.f-aspectRatio').value,
  };
}

function setStatus(card, kind, text) {
  const s = card.querySelector('.status');
  s.className = 'status ' + kind;
  s.textContent = text;
}

function applyResult(card, res) {
  card.querySelector('.prompt').textContent = res.prompt || '—';
  if (res.status === 'completed' && res.dataUri) {
    const preview = card.querySelector('.preview');
    preview.innerHTML = '';
    const img = document.createElement('img');
    img.src = res.dataUri;
    preview.appendChild(img);
    setStatus(card, 'ok', '✓ ' + res.aspectRatio);
  } else {
    setStatus(card, 'err', '✗ ' + (res.error || res.status || 'failed'));
  }
}

async function postGenerate(cards) {
  const images = cards.map(readCard);
  const r = await fetch('?action=generate', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ site: siteContext(), images }),
  });
  return r.json();
}

async function generateOne(card) {
  const btn = card.querySelector('.gen');
  btn.disabled = true;
  setStatus(card, 'run', '… generating');
  try {
    const data = await postGenerate([card]);
    if (!data.ok) { setStatus(card, 'err', data.error || 'request failed'); return; }
    applyResult(card, data.results[0]);
  } catch (e) {
    setStatus(card, 'err', String(e));
  } finally {
    btn.disabled = false;
  }
}

async function generateAll() {
  const cards = [...cardsEl.querySelectorAll('.card')];
  const btn = document.getElementById('generateAll');
  const gstat = document.getElementById('globalStatus');
  btn.disabled = true;
  gstat.textContent = 'generating ' + cards.length + ' image(s)…';
  cards.forEach(c => setStatus(c, 'run', '… generating'));
  try {
    const data = await postGenerate(cards);
    if (!data.ok) { gstat.textContent = 'Error: ' + (data.error || 'request failed'); cards.forEach(c => setStatus(c, 'err', data.error || 'failed')); return; }
    const byName = Object.fromEntries(data.results.map(r => [r.filename, r]));
    cards.forEach(card => {
      const res = byName[readCard(card).filename];
      if (res) applyResult(card, res);
    });
    const ok = data.results.filter(r => r.status === 'completed').length;
    gstat.textContent = `done · ${ok}/${data.results.length} ok · ${data.durationMs} ms`;
  } catch (e) {
    gstat.textContent = 'Error: ' + e;
  } finally {
    btn.disabled = false;
  }
}

EXAMPLES.forEach((ex, i) => cardsEl.appendChild(makeCard(ex, i)));
document.getElementById('generateAll').addEventListener('click', generateAll);
</script>
</body>
</html>
