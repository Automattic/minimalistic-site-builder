## Images

Use content images only when the assigned composition permits them.
Use native `src` and `alt` attributes. Omit custom data attributes.
Start `src` with `theme:./assets/` and append the descriptive filename with the `.jpg` extension.
Use a unique filename with lowercase letters, numbers, and hyphens.
Set `alt` to this exact format:

`AI_IMAGE: subject | page-context | style | aspect-ratio`

- subject: Describe the subject and viewpoint in one to three sentences. Keep each subject distinct within a group.
- page-context: State the image slot and its empty area in English. This field supplies machine instructions, not visible copy.
- style: Use the committed Image kind keyword. Use `photorealistic` for portraits.
- aspect-ratio: Select one value from the list below.

| Value | Ratio | Slot |
|---|---|---|
| square | 1:1 | Square slot |
| landscape | 16:9 | Wide feature or hero |
| ultrawide | 21:9 | Full-width cover only |
| portrait | 9:16 | Tall editorial panel |
| card-landscape | 4:3 | Contained card or column |
| card-portrait | 3:4 | Portrait card or column |

Permitted styles: `photorealistic`, `digital-art`, `illustration`, `minimalist`, `ui-screenshot`, `flat-design`, `3d-render`, `abstract`, `watercolor`.
For `ui-screenshot`, describe the application screen only. Omit browser chrome, devices, and desks.

Match the committed Image crop: landscape uses card-landscape for cards; portrait uses card-portrait; square uses square.
Panoramic uses landscape for contained cards and ultrawide for full-width bands. Mixed uses the slot rules above.
Use landscape or ultrawide for full-width cover backgrounds under every Image crop.
Use one aspect ratio for all images in a row or grid.

Describe content and viewpoint only. The image pipeline supplies the image grade, treatment, and render instructions.
For copy over an image, describe the required empty, low-detail area and keep the subject outside it.
Match that area to the recipe's text anchor. Keep the scene upright and use its natural horizontal or vertical axis.
Describe the slot as a photograph or backdrop. Omit references to headlines, subtitles, menus, or other text in the image request.
Use scenes without text. Describe unavoidable signs and labels as blank, distant, cropped, or out of focus.
Do not request words, letters, numerals, logos, or fake text. Put readable text in HTML blocks.

For a cover, put the same asset path in the block `url` and the inner `img` `src`.
Put the `AI_IMAGE:` specification only in `alt`.
Omit image captions outside galleries. Add a gallery caption only when the content requires one.
Omit decorative images, transparent images, PNG files, icons, ornaments, and decorative glyphs.
Use separators, borders, space, and typography for decoration.
