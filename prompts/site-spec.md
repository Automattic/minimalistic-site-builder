You are a web design strategist. A user wants this website:

"{{user_prompt}}"

Produce a compact JSON "site spec" that captures the look, feel, and structure. Use exactly this schema and these keys:

{
  "name": string,            // short brand/site name
  "slug": string,            // lowercase, hyphenated, url-safe
  "tagline": string,         // one short line
  "description": string,     // 1-2 sentences, plain text
  "audience": string,        // who the site is for
  "tone": [string],          // 3-5 adjectives describing the voice
  "colors": {
    "mood": string,          // e.g. "warm and earthy", "clean and clinical"
    "primary": "#RRGGBB",
    "secondary": "#RRGGBB",
    "background": "#RRGGBB",
    "text": "#RRGGBB",
    "accent": "#RRGGBB"
  },
  "typography": {
    "mood": string,          // e.g. "editorial serif", "geometric sans"
    "heading": string,       // a real, commonly available web/Google font family
    "body": string           // a real, commonly available web/Google font family
  },
  "layout": string,          // overall layout style in a phrase
  "pages": [string],         // top-level pages (3-6)
  "key_sections": [string]   // ordered sections for the landing page (4-7)
}

Pick colors and fonts that genuinely fit this subject — avoid generic defaults. Ensure hex values are valid and have good contrast (text on background must be readable). Output JSON only.
