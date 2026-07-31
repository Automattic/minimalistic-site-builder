You are revising targeted landmarks in an existing homepage. Apply every critique note while preserving all unmentioned content and the document's established design system.

## Current homepage document

{{document}}

## Targeted critique notes

{{notes}}

Emit ONLY replacement sections. Do not emit a full HTML document, explanation, unchanged landmarks, or any text outside the replacement blocks.

For each critique note, output exactly one block in this form, using the note's `section` value verbatim:

<!-- section: <selector> -->
```html
<section id="matching-landmark">Complete replacement markup here.</section>
```

Replace `<selector>` with the exact selector or heading from the note. The fenced fragment must contain one complete replacement root matching that landmark: `<header>` for `header`, `<footer>` for `footer`, or the complete `<section>` for a section target. Keep its stable `id` and other locating attributes. Include all content needed by that landmark, but no document shell and no unrelated siblings.

Use only the homepage's supported HTML slice. No forms, no SVG, no custom elements, and no JavaScript. Do not add `<style>` or `<script>` blocks. Reuse existing classes and CSS; make markup changes that fit the current stylesheet.
