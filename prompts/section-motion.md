Motion is optional. The committed profile is `{{motion_profile}}`.
{{motion_palette}}

Use the listed classes through block `className`; their CSS and responsive/reduced-motion behavior ship with the theme. Never author animation CSS, `@keyframes`, `is-visible`, `motion-*` runtime states, or invented motion classes. Zero effects is valid.

- At most two entrances per section, on the content that should enter rather than its padded outer shell. Use `stagger-children` on a repeated-item container instead of animating each row.
- At most one ambient effect (`ken-burns`, `gradient-shift`, `ambient-drift`) across the page, at its focal moment. Supporting sections may stay still.
- Use at most one entrance/ambient class per block. A hover class may coexist, except `ambient-drift` + `hover-lift` and `ken-burns` + `hover-reveal`, which compete for the same transform; place hover on a nested wrapper instead.
- If SITE SPEC contains an explicit `animation_request` for an element in this section, give that one element `className:"custom-motion"`. The build implements the requested effect. Do not apply that marker elsewhere.
