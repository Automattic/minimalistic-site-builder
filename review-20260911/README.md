# PR review evidence — 2026-09-11

Sites generated with the Claude Code subscription transport
(`SITE_BUILD_LLM=claude-cli`), `--blocks-first --multi-page --with-images`.
Screenshots captured with `php bin/screenshot.php <slug> --runner=playground`
at `SHOT_WIDTH=1366` unless the file name says otherwise.

Two briefs were used.

- Brief A (PRs 652, 653, 654, 655, 656, 659, 660 and the trunk baseline):
  "Barro Nuevo, a small-batch ceramics studio in Oaxaca. Wood-fired stoneware
  tableware, monthly open-studio days, and a wholesale line for restaurants.
  Pages: Home, Studio, Collections, Visit."
- Brief B (PR 643): brief A plus "Use rounded dark bands that float on a light page."
- Brief C (PR 645): brief A plus "Use a deep forest green and warm cream palette,
  a display serif for headings, a framed canvas, and black-and-white photography."

| file | site | branch head |
| --- | --- | --- |
| trunk-home-top.jpg | ev-trunk-a | trunk db15f223 |
| 643-home-top.jpg, 643-rounded-band.jpg, 643-rounded-band-1800.jpg | ev-643 | a3456d32 |
| 645-home-top.jpg | ev-645 | e5e2cd66 |
| 652-home-top.jpg | ev-652 | ed0cc7a9 |
| 653-home-top.jpg | ev-653 | 7f49f080 |
| 654-home-top.jpg | ev-654 | e577c01d |
| 655-home-top.jpg | ev-655 | 857b6284 |
| 656-home-top.jpg, 656-hero-eyebrow.jpg | ev-656 | e40c3ffe |
| 659-home-top.jpg | ev-659 | ea9c9469 |
| 660-home-top.jpg | ev-660 | a25cdcb3 |

`643-rounded-band-1800.jpg` was captured at `SHOT_WIDTH=1800` to measure the
band inset against the theme `wideSize` of 1320px.
