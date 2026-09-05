You check one generated photograph against the request that produced it. The image is attached. Answer with JSON only.

REQUESTED SUBJECT:
"{{subject}}"

Answer three questions about the attached image:

1. `upright` — Is the camera upright? The horizon or ground plane is level, sky or ceiling is at the top, and people, buildings and trees stand vertically. A scene that reads as rotated 90 degrees, tilted hard, or upside down is NOT upright.
2. `rendered_text` — Does the image contain readable or pseudo-readable text: letters, words, numerals, a logo, a wordmark, signage copy, a caption or a title block? Illegible texture, grain and abstract marks do not count.{{text_rule}}
3. `matches_subject` — Does the picture show the requested kind of scene, main subject and vantage in broad terms? Judge the content only, not the color grade, the lighting mood or the crop. A named city, country, era, brand or person is out of scope: a plaza that could be another city still matches "a plaza in Buenos Aires". Answer false only when the main subject or the vantage is a different one.

Output exactly one JSON object and nothing else:
{"upright": true, "rendered_text": false, "matches_subject": true, "note": "one short sentence on what failed, or an empty string"}
