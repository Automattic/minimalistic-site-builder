#!/bin/sh
# Write to both streams and exit non-zero.
echo "partial output"
echo "diagnostic detail" >&2
exit 7
