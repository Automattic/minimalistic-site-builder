# image_grade on every image, rewrite fights

`image_grade` is the one field that mostly works.
`ImagePromptComposer` appends it to every Gemini prompt. Residual
leaks are competing grade language in the subject ("studio white,
no grain" on a Portra site), wrong locale under a location
caption, and painted lettering. BIGR-802 is this class.

## Change

- Keep the composer append.
- Before the API call, strip or rewrite subject tokens that fight
  the committed grade (studio-white, flash-hard, no-grain on a
  grain grade, saturated neon on a charcoal grade).
- Locale and ghost-signage stay on BIGR-802. Same composer, same
  "direction said X, image said Y" test.

## Out of scope

A vision model judging the finished JPEG. Raising sample size.
New aspect ratios.
