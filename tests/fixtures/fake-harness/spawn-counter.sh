#!/bin/sh
# Structural conformance must reject before this executable can write its counter.
printf 'spawned\n' >> "${0}.count"
printf '%s' '{"is_error":false,"stop_reason":"end_turn","result":"{}","usage":{}}'
