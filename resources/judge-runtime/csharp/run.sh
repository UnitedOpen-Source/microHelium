#!/bin/bash
# Runs a submission built by compile.sh.
# Invoked as: run.sh <output_basename> [memory_limit_mb]
set -e

OUTPUT="$1"
MEMORY_MB="$2"

# Issue #102 -- .NET is one of the runtimes `ulimit -v` cannot cap: it
# reserves a large virtual range at startup and refuses to boot under an
# address-space limit. DOTNET_GCHeapHardLimit does cap it, for real --
# measured in the judge image, a loop allocating 16 MB arrays reached 640 MB
# unbounded and died with "Out of memory." under a 256 MB limit.
#
# The variable takes HEX BYTES with no 0x prefix, which is why this
# conversion exists rather than passing the megabytes straight through.
if [ -n "$MEMORY_MB" ] && [ "$MEMORY_MB" -gt 0 ] 2>/dev/null; then
    export DOTNET_GCHeapHardLimit
    DOTNET_GCHeapHardLimit=$(printf '%x' $((MEMORY_MB * 1024 * 1024)))
fi

exec dotnet "${OUTPUT}_bin/proj.dll"
