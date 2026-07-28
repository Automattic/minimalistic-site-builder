# TOON block attributes prototype

**Branch:** `feat/toon-block-attrs-prototype`  
**Status:** experimental — pure PHP, no Node runtime

## Goal

Let models emit Gutenberg-shaped markup whose **block openers carry TOON attributes** instead of deep JSON, then deterministically convert to standard WordPress comment JSON before the rest of the pipeline (recovery, salvage, fixer, …).

This targets the multi-page failure mode where a single missing `}` inside `<!-- wp:… {…} -->` invalidates the whole section.

## Why pure PHP

The site-build core is intentionally dependency-free (`autoload.php`, no Composer packages at runtime). TOON encode/decode and block expansion live in:

| Class | Role |
|-------|------|
| `Automattic\SiteBuild\Toon` | JSON-model ↔ TOON text (serializer + parser) |
| `Automattic\SiteBuild\ToonBlockAttrs` | Expand TOON attrs in `<!-- wp:… -->` openers to JSON |
| `GeneratedMarkup::normalize` | Calls expand **before** block document recovery |

No `npx`, no `@toon-format/cli`, no JavaScript.

## Intermediate form

```html
<!-- wp:paragraph
align: center
textColor: base
style:
  spacing:
    margin:
      top: "var:preset|spacing|md"
  elements:
    link:
      color:
        text: "var:preset|color|base"
      ":hover":
        color:
          text: "var:preset|color|accent"
-->
<p>…</p>
<!-- /wp:paragraph -->
```

Optional explicit marker:

```html
<!-- wp:spacer toon
height: 24px
/-->
```

After expansion:

```html
<!-- wp:paragraph {"align":"center","textColor":"base","style":{…}} -->
```

Openers that already use valid JSON are left unchanged.

## CLI

```bash
# JSON → TOON
php bin/toon.php encode <<< '{"align":"center","textColor":"base"}'

# TOON → JSON
php bin/toon.php decode path/to.toon

# Expand hybrid section HTML
php bin/toon.php expand path/to/section.html
```

## Codec coverage (prototype)

Supported well:

- Nested objects (block attrs)
- Primitives (string / number / bool / null)
- Quoted keys (`:hover`) and quoting rules for colon-heavy preset strings
- Inline primitive arrays `key[N]: a,b`
- Empty arrays `key: []`
- Uniform object arrays in tabular form `key[N]{f1,f2}:`

Not a full TOON v4.1 product implementation (keyed tabular, nested field groups, tab/pipe delimiters, …). Extend `Toon.php` as needed; keep tests green.

## How it enters a build

`GeneratedMarkup::normalize()` (every section/header/footer unit) expands TOON openers automatically. No new pipeline step or flag is required for the prototype.

To *encourage* models to emit TOON attrs, add a prompt fragment in a follow-up (not on by default on trunk until evaluated).

## Tests

```bash
php tests/run.php
# or focused:
php tests/run.php tests/unit/toon_test.php
```

## Reference

- Spec: https://github.com/toon-format/spec  
- Online converter (design reference only): https://www.mywebutils.com/json-to-toon  
- Official TS package (not used at runtime): https://github.com/toon-format/toon  
