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

# Issue #303 -- fixa o SDK, em vez de deixar o `dotnet` escolher o maior
# instalado.
#
# Hoje so ha um SDK na imagem e isto e inofensivo. Ele existe porque, no
# momento em que um segundo SDK entrar (a #305 pede o .NET 10 ao lado do 8),
# a ausencia disto QUEBRA o caminho que funciona. Medido, com os SDKs 8.0.131
# e 10.0.303 lado a lado:
#
#   sem global.json, projeto net8.0 -> o SDK 10 assume e tenta baixar
#     `Microsoft.NETCore.App.Ref (= 8.0.30)` do NuGet; o sandbox nao tem
#     rede, e a compilacao morre com NU1100
#   com global.json fixando 8.0.x   -> compila e roda (runtime 8.0.31)
#
# `rollForward: latestFeature` aceita qualquer 8.0.x, entao fixar a versao
# do pacote Alpine nao e preciso -- o que importa e nao saltar de major.
#
# O arquivo vai no diretorio de onde `dotnet build` e INVOCADO, e nao dentro
# de proj/: a resolucao do global.json sobe a partir do diretorio corrente.
# Foi assim que o primeiro teste desta mudanca deu falso negativo.
printf '{"sdk":{"version":"%s","rollForward":"latestFeature"}}\n' "${SDK_VERSION:-8.0.0}" > global.json

dotnet build proj -c Release -o "${OUTPUT}_bin"
