'use strict';

/**
 * Locate a Chrome/Chromium executable for playwright-core.
 *
 * playwright-core ships no browser of its own, so the driver runs against the
 * system Chrome — the same approach (and the same candidate list and error
 * wording) as bin/screenshot/screenshot.js, exported here so harness.mjs can
 * share it.
 */

function findChrome(explicit) {
    const fs = require('fs');
    const path = require('path');
    const findOnPath = (bin) => {
        for (const dir of (process.env.PATH || '').split(path.delimiter)) {
            if (!dir) continue;
            const candidate = path.join(dir, bin);
            try {
                fs.accessSync(candidate, fs.constants.X_OK);
                return candidate;
            } catch { /* ignore */ }
        }
        return null;
    };

    if (explicit) {
        if (explicit.includes('/') || explicit.includes('\\')) {
            if (fs.existsSync(explicit)) return explicit;
            throw new Error(`Chrome/Chromium executable does not exist: ${explicit}`);
        }
        const resolved = findOnPath(explicit);
        if (resolved) return resolved;
        throw new Error(`Could not find Chrome/Chromium executable named ${explicit}. Pass --chrome=<path>.`);
    }

    const candidates = [
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/snap/bin/chromium',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ];
    for (const c of candidates) {
        try { if (fs.existsSync(c)) return c; } catch { /* ignore */ }
    }
    throw new Error('Could not find Chrome/Chromium. Pass --chrome=<path> or set CHROME/CHROME_BIN.');
}

module.exports = { findChrome };
