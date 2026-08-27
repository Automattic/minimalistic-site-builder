---
task_type: repair
required: [artifact, diagnostics, original_payload_note]
---
An artifact failed its gate. You get EXACTLY ONE attempt to replace it; a second
failure kills the artifact and its slot falls back to a baseline. Do not redesign —
fix precisely what the diagnostics name and change nothing else.

The failed artifact:
{{artifact}}

The gate's diagnostics, verbatim:
{{diagnostics}}

{{original_payload_note}}

Output ONLY the corrected artifact in the same format — a TreeIR JSON document
({"version": 1, "epoch": "...", "blocks": [...]}) for a tree, or the file map
({"files": {"<name>": "<content>"}}) for a block or schema package. No commentary.
