# Convert can't eat the HTML we just honored

HTML-first (`feat/html-first-generation`, draft PR #251) already
writes the utilities the direction needs: `u-tooth`, `u-ticker`,
a real `<a class="wp-element-button">`. `blocks-engine` convert
turns buttons into synthetic paragraphs and hashes column
geometry (`be-inline-geometry-…`). Items 01–07 get implemented in
HTML and die at transform.

HTML-first is now the default graph. `DirectionUtilities` restores
direction-owned class tokens (`device--*`, `card-style--*`, motion
kit classes, `u-*`, `has-accent-font-family`) after
`compileFragment`, and records a warning when a `wp-element-button`
is flattened into a paragraph.

## Change

- Preserve utility class names through the transformer.
- Preserve `core/buttons` as buttons.
- If a construct cannot convert, keep that part's pre-convert
  HTML and warn. Do not silently flatten it into `wp:group`
  soup. Matches the package ladder: smallest unit, warn,
  continue.

## Out of scope

Redesigning the transformer. Staying on HTML forever and
dropping the block theme contract.
