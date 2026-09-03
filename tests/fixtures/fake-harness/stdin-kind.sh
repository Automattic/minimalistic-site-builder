#!/bin/sh
# /dev/null is a character device; a closed proc_open pipe is not.
if [ -c /dev/fd/0 ]; then
    printf '%s' 'character-device'
else
    printf '%s' 'not-character-device'
fi
