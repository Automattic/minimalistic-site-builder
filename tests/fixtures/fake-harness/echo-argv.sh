#!/bin/sh
# Print argv cardinality plus first element so shell metacharacters stay observable.
printf '%s\n' "$#"
printf '%s' "$1"
