**typographic-billboard** — ONE viewport-filling brand line dominates the band:
a single wp:heading carrying `"fitText":true` and a real `"align":"full"` or
`"align":"wide"` attribute, its saved HTML mirroring the support as
`class="wp-block-heading alignfull has-fit-text"`, its text the spec's exact
identity on one short line. Fit text sizes the line to its container at
runtime: never set a fontSize preset, raw font-size, or clamp() on that
heading, and never use wp:site-title for this gesture — it does not support
fit text. Put all useful navigation/contact/copyright in ONE compact
horizontal baseline or wrapped flex rail beneath it. No link columns and no
image.
