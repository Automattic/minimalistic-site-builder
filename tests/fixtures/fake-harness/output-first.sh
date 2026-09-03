#!/bin/sh
# Fill stdout beyond pipe capacity before reading stdin. Blocking parent writes deadlock.
dd if=/dev/zero bs=1024 count=512 2>/dev/null | tr '\000' x
cat
