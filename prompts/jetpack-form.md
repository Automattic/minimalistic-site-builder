## Forms

This build runs on a host that owns a real form backend, so a form the brief
genuinely asks for is no longer dead UI. You do NOT write the form markup
yourself: you reserve its place with a placeholder block, and a later host step
replaces that block with a working form.

Emit a form placeholder ONLY where the site spec, the page's purpose, or this
section's purpose genuinely calls for one — contact, booking, RSVP, enquiry, or
newsletter signup. Never decorate an ordinary content section with a form, and
never emit more than one placeholder in a section.

The placeholder is a single paragraph block carrying the `jetpack-form-placeholder`
class, whose only text is the form spec:

```html
<!-- wp:paragraph {"className":"jetpack-form-placeholder"} -->
<p class="jetpack-form-placeholder">JP_FORM: contact | Name:name:required, Email:email:required, Message:textarea:required | Send message</p>
<!-- /wp:paragraph -->
```

### Spec Format

```
JP_FORM: purpose | fields | submit-label
```

- `JP_FORM:` — Required prefix marker (exactly as written)
- `|` — Pipe character used as the separator between the three values
- `purpose` — One of: `contact`, `booking`, `rsvp`, `enquiry`, `newsletter-signup`
- `fields` — Comma-separated field list, in the order they should appear
- `submit-label` — The button text, written in the site's language

**Field format:** `label:type` or `label:type:required`

- `label` — The visitor-facing field label, written in the site's language and
  capitalised the way it will be READ on the page: `Name`, `Email`,
  `Piece description` — not `name` or `piece description`. The host prints it
  verbatim above the input. Keep it short and plain.
  A label may not contain `,` `:` or `|`.
- `type` — One of: `name`, `text`, `email`, `tel`, `url`, `date`, `textarea`,
  `checkbox`, `select`, `radio`. Use `name` for the field asking who the
  visitor is, not `text`: a host can prefill a name it already knows, and it
  cannot tell one from a label written in the site's language.
- `required` — Add this third part only for fields the visitor must fill.

`select` and `radio` need their choices, written as a parenthesised list right
after the type: `party size:select(1, 2, 3, 4 or more):required`. Choices may
not contain `,` `:` or `|`. Every other type takes no choices.

### Rules

- Ask for the fewest fields that serve the purpose. A contact form is usually
  name, email, message; a newsletter signup is usually just email. Never ask
  for anything the section's purpose does not need.
- Write `label`, the choices, and `submit-label` in the site's language; write
  `purpose` and `type` in English — they are machine values, never shown.
- The placeholder paragraph's text is the ENTIRE spec: no heading, no extra
  copy, no markup inside it. Any surrounding heading or lead-in paragraph is a
  normal sibling block, outside the placeholder.
- The placeholder block carries `className` and NOTHING else — no font size, no
  colour, no spacing. It is never styled, because it is not copy: the host
  replaces the whole block before a visitor sees it.
- Keep writing the section's other blocks as usual. The placeholder replaces
  only the form itself.
- Never emit `<form>`, `<input>`, `<textarea>`, `<select>`, or any
  `wp:jetpack/*` block of your own. The placeholder is the only form output.

### Example Section Fragment

```html
<!-- wp:heading -->
<h2 class="wp-block-heading">Book a table</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"jetpack-form-placeholder"} -->
<p class="jetpack-form-placeholder">JP_FORM: booking | Name:name:required, Email:email:required, Date:date:required, Party size:select(1, 2, 3, 4 or more):required, Notes:textarea | Request booking</p>
<!-- /wp:paragraph -->
```
