#!/bin/bash
# C# submissions are a single .cs file, but `dotnet build` requires a
# project. This wraps the submitted source in a throwaway console project
# and builds it. Invoked as: compile.sh <source_file> <output_basename>
# from within the run's working directory (AutoJudgeService::compile()).
set -e

SOURCE="$1"
OUTPUT="$2"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

mkdir -p proj
cp "$SCRIPT_DIR/proj.csproj.template" proj/proj.csproj
# Keeps `dotnet build`'s restore offline -- see the comment in nuget.config.
cp "$SCRIPT_DIR/nuget.config" proj/nuget.config
cp "$SOURCE" proj/Program.cs

dotnet build proj -c Release -o "${OUTPUT}_bin"
