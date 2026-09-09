# Super Coaching: Studio examples for PR #626

Full-page screenshots of six existing user-generated Studio sites, captured for https://github.com/Automattic/minimalistic-site-builder/pull/626. These are observed outputs, not a claim that every requested style or accessibility criterion is perfectly satisfied.

| Studio title | Studio site folder | Capture URL | Screenshot prefix |
| --- | --- | --- | --- |
| Super Coaching: Art Deco | vivid-willow2 | http://localhost:8884/ | art-deco |
| Super Coaching: Bauhaus | rustic-pebble | http://localhost:8881/ | bauhaus |
| Super Coaching: Organic | clever-harvest | http://localhost:8883/ | organic |
| Super Coaching: Retro-Futurist | crisp-valley2 | http://localhost:8882/ | retro-futurist |
| Super Coaching: Synthwave | dapper-meadow | http://localhost:8886/ | synthwave |
| Super Coaching: No art direction | rustic-otter | http://localhost:8885/ | no-art-direction |

Desktop viewport: 1440 × 900. Mobile viewport: 390 × 900. Both use full-page capture with device scale factor 1 and motion enabled. The helper scrolls the page, waits for lazy-loaded images, and settles entrance animations; no site content or generated styling was changed for the screenshots.

Reproduce from the code checkout at `5eb9a2fb` with the corresponding Studio site running:

```sh
node bin/screenshot/screenshot.js http://localhost:8884/ /tmp/art-deco-desktop.png --width=1440 --motion
node bin/screenshot/screenshot.js http://localhost:8884/ /tmp/art-deco-mobile.png --width=390 --motion
```

Capture dates: September 8–9, 2026 UTC. The code reference identifies the capture helper and PR implementation, not an assertion that the user-generated sites were all built at that exact commit.

## CLI builds on the PR branch

Full-page home screenshots of seven sites that the default CLI build created on the PR branch at `9f7e1428` on 2026-09-09 (about 14:36 UTC). The build pipeline captured each screenshot in the `theme-screenshot` step at a width of 1366 px. The files are in `cli/`.

| Project | Site name | Prompt summary | File |
| --- | --- | --- | --- |
| portfolio24 | Cobertura | Minimalist photo-journalism portfolio, Buenos Aires | cli/portfolio24-home.png |
| tbilisi24 | Tbilisi Tavern | Classic Georgian restaurant in Tbilisi Old Town | cli/tbilisi24-home.png |
| naturaleza24 | Naturaleza Sabia | Vegetarian Argentinean restaurant in San Telmo, for tourists | cli/naturaleza24-home.png |
| lumen24 | Lumen Objects | Warm, editorial site for a Copenhagen recycled-glass lamp studio | cli/lumen24-home.png |
| atlas24 | Atlas Field | Clean, confident site for a construction crew mobile app | cli/atlas24-home.png |
| pulso24 | Neon Nexus | Vaporwave electronic music festival in Stockholm | cli/pulso24-home.png |
| hearth24 | Hearth & Crumb | Warm, honest neighborhood bakery in Portland, Maine | cli/hearth24-home.png |

These are observed outputs. They are not a claim that every requested style is fully satisfied.
