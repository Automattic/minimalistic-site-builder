# Site Creation Steps

The build sequence, in order. Each step is either **Deterministic** (plain PHP,
no LLM) or an **LLM step** (one isolated call, dynamic prompt). Every step reads
from files written by earlier steps and writes its own output to disk, so any
step can be re-run independently.

---

## 1. Deterministic — Scaffold a new theme
- **Input:** none
- **Output:** a theme folder containing `style.css` and `readme.txt`, with
  `{{placeholders}}` (e.g. `{{THEME_NAME}}`, `{{THEME_SLUG}}`, `{{DESCRIPTION}}`,
  `{{AUTHOR}}`) that later steps replace.
- **Notes:** pure boilerplate copy; no project identity known yet.

## 2. LLM — Site spec
- **Input:** user site creation prompt
- **Output:** `siteSpec.json` capturing all look-and-feel characteristics of the
  site (name, slug, tagline, audience, tone, color mood, typography mood,
  layout style, key pages/sections).
- **Notes:** first and only step that reads the raw user prompt. Everything
  downstream is derived from this file.

## 3. Deterministic — Apply project identity to the theme
- **Input:** `siteSpec.json` (name + slug), scaffolded theme from step 1
- **Output:** `style.css` / `readme.txt` with the `{{placeholders}}` replaced by
  the real project name, slug, and description.
- **Notes:** turns the boilerplate theme into a named project theme.

## 4. LLM — Design direction
- **Input:** `siteSpec.json`
- **Output:** `designDirection.json` — a concrete creative brief (palette,
  type pairing, spacing/rhythm, imagery, mood references) meant to **inspire and
  constrain the next coding step**.
- **Notes:** translates the abstract spec into actionable design decisions.

## 5. LLM — Design document
- **Input:** `siteSpec.json` + `designDirection.json`
- **Output:** `design.md` — a human-readable design document consolidating spec
  and direction into clear guidance for building the theme.
- **Notes:** single narrative source the remaining build steps reference.

## 6. LLM — theme.json
- **Input:** `design.md` (and `designDirection.json` for exact token values)
- **Output:** `theme.json` — WordPress global styles/settings: color palette,
  typography, spacing, and layout, derived from the design document.
- **Notes:** machine-consumable design tokens for the block theme.

## 7. LLM — Landing page template
- **Input:** `theme.json` + the rest of the theme files (`style.css`, `design.md`)
- **Output:** the landing page template (block markup, e.g.
  `templates/front-page.html` + any needed `parts/` and `patterns/`).
- **Notes:** first real page; uses the tokens from `theme.json` so it stays
  visually consistent with the design direction.

---

## Sequence at a glance

| # | Type | Step | Reads | Writes |
|---|------|------|-------|--------|
| 1 | Deterministic | Scaffold theme | — | `style.css`, `readme.txt` (placeholders) |
| 2 | LLM | Site spec | user prompt | `siteSpec.json` |
| 3 | Deterministic | Apply identity | `siteSpec.json` + theme | filled `style.css`, `readme.txt` |
| 4 | LLM | Design direction | `siteSpec.json` | `designDirection.json` |
| 5 | LLM | Design doc | `siteSpec.json` + `designDirection.json` | `design.md` |
| 6 | LLM | theme.json | `design.md` (+ `designDirection.json`) | `theme.json` |
| 7 | LLM | Landing page | `theme.json` + theme files | `templates/front-page.html` (+ parts/patterns) |

## Next steps (later, same one-shot pattern)
- Additional page templates (about, contact, etc.)
- Navigation / header / footer template parts
- Reusable block patterns
