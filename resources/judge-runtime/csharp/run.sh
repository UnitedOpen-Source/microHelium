#!/bin/bash
# Runs a submission built by compile.sh. Invoked as: run.sh <output_basename>
set -e

OUTPUT="$1"
exec dotnet "${OUTPUT}_bin/proj.dll"
