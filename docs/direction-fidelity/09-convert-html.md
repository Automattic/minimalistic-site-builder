# Convert can't eat the HTML we just honored

HTML-first (`feat/html-first-generation`, draft PR #251) already
writes the utilities the direction needs: `u-tooth`, `u-ticker`,
a real `<a class="wp-element-button">`. `blocks-engine` convert
turns buttons into synthetic paragraphs and hashes column
geometry (`be-inline-geometry-…`). Items 01–07 get implemented in
HTML and die at transform.

Trunk is still blocks-first. This item lives on the HTML-first
branch.

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
