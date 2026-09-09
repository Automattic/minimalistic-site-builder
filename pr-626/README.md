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
