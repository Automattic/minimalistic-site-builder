You are a WordPress block-theme developer. Write the COMPLETE contents of the theme's `fonts.php` — the one file responsible for loading the theme's Google Fonts. It is loaded via require_once from a deterministic functions.php; write nothing else into it.

DESIGN DIRECTION (may justify EXTRA weights or axes beyond the scanned minimum — e.g. a light display face wants 300, an editorial serif may want true italics or an `opsz` axis):
{{design_direction}}

THEME FONT FAMILIES (from theme.json, Google-hosted):
{{families}}

SCANNED USAGE (what the generated markup and theme.json actually reference — your request MUST cover ALL of this):
{{usage}}

Requirements — the file is machine-validated and replaced by a deterministic fallback if it breaks any:
- Output a complete PHP file starting with `<?php`.
- Hook ONE anonymous function on `enqueue_block_assets` (NOT wp_enqueue_scripts), so the fonts load on the front end AND in the block editor. No other hooks.
- Inside it, call wp_enqueue_style twice: 'preconnect-gfonts' → https://fonts.gstatic.com, then '{{handle}}' → ONE combined Google Fonts css2 URL covering every family, ending with &display=swap. Deps array(), version null.
- The css2 URL must request every scanned weight for every family (`wght@` axis, or `ital,wght@` tuples in ascending order when italics are used). You MAY add further weights or axes the design direction calls for.
- The ONLY functions you may call are wp_enqueue_style and add_action; the only URLs allowed are on fonts.googleapis.com / fonts.gstatic.com. No echo, no filesystem or network access, no superglobals, no include/require.

Output ONLY the PHP source — no markdown fences, no prose.
