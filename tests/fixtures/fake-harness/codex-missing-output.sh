#!/bin/sh
# Emit valid usage JSONL but deliberately leave the requested -o file absent.
printf '%s\n' '{"type":"turn.completed","usage":{"input_tokens":17357,"cached_input_tokens":11008,"cache_write_input_tokens":0,"output_tokens":5,"reasoning_output_tokens":0}}'
