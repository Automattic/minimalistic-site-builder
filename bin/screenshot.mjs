#!/usr/bin/env node
// Capture a full-page PNG screenshot of a URL using the system Chrome over the
// DevTools Protocol — no Puppeteer/Playwright dependency (Node 22's global
// WebSocket + fetch are enough). Chrome's plain `--screenshot` flag only grabs
// the viewport, so we drive Page.captureScreenshot with captureBeyondViewport.
//
//   node bin/screenshot.mjs <url> <out.png> [chromeBinary]
//
// Width defaults to 1280 (override with SHOT_WIDTH). Exits non-zero on failure.

import { spawn } from 'node:child_process';
import { mkdtemp, readFile, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';

const [, , url, out, chromeBin = process.env.CHROME_BIN || 'google-chrome'] = process.argv;
if (!url || !out) {
  console.error('Usage: node bin/screenshot.mjs <url> <out.png> [chromeBinary]');
  process.exit(2);
}
const width = parseInt(process.env.SHOT_WIDTH || '1280', 10);

const profile = await mkdtemp(join(tmpdir(), 'shot-'));
const chrome = spawn(chromeBin, [
  '--headless=new', '--no-sandbox', '--disable-gpu', '--hide-scrollbars',
  `--user-data-dir=${profile}`, '--remote-debugging-port=0',
  `--window-size=${width},900`, 'about:blank',
], { stdio: ['ignore', 'ignore', 'ignore'] });

let ws, id = 0;
const replies = new Map();
const waiters = new Map(); // event method -> resolver
const cleanup = async () => {
  try { ws?.close(); } catch {}
  chrome.kill('SIGKILL');
  // Best-effort temp-profile removal. Chrome may still be flushing files to the
  // profile as we delete it (a SIGKILL'd process isn't reaped synchronously),
  // which races rmdir into ENOTEMPTY. Retry a few times, and never let a cleanup
  // failure mask an otherwise-successful capture — it's just a /tmp dir.
  try {
    await rm(profile, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 });
  } catch {}
};
const fail = async (msg) => { console.error(msg); await cleanup(); process.exit(1); };

chrome.on('error', (e) => fail(`Could not launch Chrome (${chromeBin}): ${e.message}`));

try {
  // Chrome writes the chosen debugging port here once it's listening.
  const portFile = join(profile, 'DevToolsActivePort');
  let port;
  for (let i = 0; i < 100 && !port; i++) {
    try { port = parseInt((await readFile(portFile, 'utf8')).split('\n')[0], 10); }
    catch { await sleep(100); }
  }
  if (!port) throw new Error('Chrome did not expose a DevTools port in time');

  const ver = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json();
  ws = new WebSocket(ver.webSocketDebuggerUrl);
  await new Promise((res, rej) => { ws.onopen = res; ws.onerror = () => rej(new Error('DevTools socket error')); });
  ws.onmessage = (e) => {
    const m = JSON.parse(e.data);
    if (m.id && replies.has(m.id)) { replies.get(m.id)(m); replies.delete(m.id); }
    if (m.method && waiters.has(m.method)) { waiters.get(m.method)(); waiters.delete(m.method); }
  };
  const send = (method, params = {}, sessionId) => new Promise((res) => {
    const mid = ++id; replies.set(mid, res);
    ws.send(JSON.stringify({ id: mid, method, params, sessionId }));
  });
  const waitFor = (method, ms) => Promise.race([
    new Promise((res) => waiters.set(method, res)),
    sleep(ms),
  ]);

  const { result: { targetId } } = await send('Target.createTarget', { url: 'about:blank' });
  const { result: { sessionId } } = await send('Target.attachToTarget', { targetId, flatten: true });
  await send('Page.enable', {}, sessionId);
  await send('Emulation.setDeviceMetricsOverride',
    { width, height: 900, deviceScaleFactor: 1, mobile: false }, sessionId);

  await send('Page.navigate', { url }, sessionId);
  await waitFor('Page.loadEventFired', 30000);
  await sleep(1500); // let lazy images / webfonts settle past the load event

  const { result: { cssContentSize } } = await send('Page.getLayoutMetrics', {}, sessionId);
  const height = Math.max(1, Math.ceil(cssContentSize.height));
  const shot = await send('Page.captureScreenshot', {
    format: 'png', captureBeyondViewport: true,
    clip: { x: 0, y: 0, width, height, scale: 1 },
  }, sessionId);
  if (!shot.result?.data) throw new Error('captureScreenshot returned no data');
  await writeFile(out, Buffer.from(shot.result.data, 'base64'));
  console.log(`${width}x${height}`);
  await cleanup();
  process.exit(0);
} catch (e) {
  await fail(e.message);
}
