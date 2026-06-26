You are the design lead. Produce a `design.md` document — the single source of design truth a developer will follow to build a WordPress block theme. This is where ALL design decisions are made (palette, typography, spacing, shapes, components, imagery); the site spec deliberately carries none of them.

You have two inputs.

USER PROMPT:
"{{user_prompt}}"

SITE SPEC (JSON — factual info about the site, no design):
{{site_spec}}

Write the document in the **DESIGN.md standard** (https://github.com/google-labs-code/design.md): YAML front matter holding machine-readable design tokens, followed by a Markdown body of design rationale. Follow this structure EXACTLY.

## 1. YAML front matter

Delimited by `---` fences at the very top of the file. Use these keys:

```
---
name: <Site name>
description: <one short line on the design concept>
colors:
  base: "#RRGGBB"       # page background
  contrast: "#RRGGBB"   # body text color (must read clearly on base)
  primary: "#RRGGBB"    # main brand color
  secondary: "#RRGGBB"  # supporting brand color
  accent: "#RRGGBB"     # reserved for CTAs / interaction only
typography:
  heading:
    fontFamily: <a real, commonly available web/Google font family>
    fontWeight: <e.g. 600 or 700>
  body:
    fontFamily: <a real, commonly available web/Google font family>
    fontSize: 1rem
    lineHeight: 1.6
rounded:
  sm: <e.g. 4px>
  md: <e.g. 8px>
  lg: <e.g. 16px>
spacing:
  sm: <e.g. 8px>
  md: <e.g. 16px>
  lg: <e.g. 32px>
---
```

Requirements for the front matter:
- Include the five color tokens with EXACTLY these names — `base`, `contrast`, `primary`, `secondary`, `accent` — as valid `#RRGGBB` hex. Body text on the background must have strong contrast.
- Include the two typography tokens with EXACTLY these names — `heading` and `body` — each with a real `fontFamily`. Pick fonts that genuinely fit the subject; avoid generic defaults.
- Choose colors and fonts that fit the site's topic, area, audience, and `visual_vibe` from the spec — be specific and opinionated, not generic.

## 2. Markdown body

After the closing `---`, write these `##` sections, in this exact order (canonical DESIGN.md ordering). Do not duplicate a heading.

## Overview
The brand philosophy and visual direction — who it's for and the feeling to achieve.

## Colors
Explain each token's role (base, contrast, primary, secondary, accent) and how it's used (backgrounds, text, sections, interaction). Note contrast/readability. Reference tokens with `{colors.primary}` syntax where natural.

## Typography
The heading + body font system: how the pairing works, the size/weight hierarchy, and where each is used.

## Layout
Spacing rhythm, content width, density, and the overall page structure — including the ordered landing-page sections from the spec and what each contains.

## Shapes
Border-radius usage (referencing `{rounded.sm}` etc.) and the general softness/sharpness of the UI.

## Components
Concrete styling for buttons (accent-driven), links, cards, navigation, and forms.

## Imagery
Direction for photos, illustration, and iconography that fits the brand.

## Do's and Don'ts
Two short bullet lists of concrete, specific guidance (avoid generic AI aesthetics).

Be specific and consistent with the inputs. Use the real hex values and font names from your front matter. Output ONLY the Markdown document (front matter + body), nothing else.
