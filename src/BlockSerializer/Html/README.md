# HTML compatibility layer provenance

This directory is project-owned PHP code. It does not copy or load DOM,
Lexbor, Composer packages, or WordPress runtime classes.

Its behavior was implemented against the development oracle locked by
`package-lock.json` (SHA-256
`eb1b8c40981564cf9f1f01e0d4a5d910f20b83c8c73ee338d2b592df8d64150c`):

- `@wordpress/blocks` 15.15.0 attribute matchers;
- `hpq` as used by that package;
- `@wordpress/rich-text` 7.49.0 `RichTextData.fromHTMLElement()`;
- JSDOM 29.0.1 fragment parsing/serialization, used only as the development
  differential oracle.

The runtime implementation is intentionally bounded. `Selector` accepts the
closed grammar present in the registered schema snapshot: selector groups,
descendant and child combinators, tag/universal/class/id selectors, attribute
presence and value operators, and `:not()` with a simple compound selector.
Sibling combinators, arbitrary pseudo-classes, CSS namespaces, and escapes fail
closed with `RuntimeException`.

`HtmlFragment` retains source byte spans and also exposes a canonical HTML
serialization. It implements the common HTML recovery needed by generated
block markup (void elements, mismatched/unclosed tags, and optional paragraph,
list, option, and table-cell end tags). It is not advertised as a general
browser parser. Any newly observed malformed shape or registered selector must
first land as a reviewed fixture before this closed implementation expands.

`RichText` returns the selected element's canonical `innerHTML`, matching the
serializer-facing `originalHTML` retained by the pinned RichText package.
Whitespace and inline structure remain authored; entities, literal NBSP,
attribute quoting, tag case, and `<br>` use canonical fragment spellings.
