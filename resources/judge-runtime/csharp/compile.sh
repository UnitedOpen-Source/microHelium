#!/bin/bash
# C# submissions are a single .cs file, but `dotnet build` requires a
# project. This wraps the submitted source in a throwaway console project
# and builds it. Invoked as: compile.sh <source_file> <output_basename>
# from within the run's working directory (AutoJudgeService::compile()).
set -e
set -o pipefail

SOURCE="$1"
OUTPUT="$2"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_NAME="$(basename "$SOURCE")"

# Issue #331 -- o banner de PRIMEIRA EXECUCAO do SDK, que enterrava o erro
# da equipe sob quinze linhas de propaganda ("Welcome to .NET 8.0!", "Write
# your first app", "Explore documentation", ...). Ele aparecia em TODA
# submissao, e nao numa: o `HOME` do envio e o diretorio do run, sempre
# novo, entao para o `dotnet` toda submissao e a primeira.
#
# Estas variaveis tem de existir ANTES do primeiro `dotnet`, porque e ele
# quem decide imprimir a saudacao.
export DOTNET_NOLOGO=1
export DOTNET_CLI_TELEMETRY_OPTOUT=1
export DOTNET_SKIP_FIRST_TIME_EXPERIENCE=1
export DOTNET_GENERATE_ASPNET_CERTIFICATE=0
export DOTNET_SKIP_WORKLOAD_INTEGRITY_CHECK=1

mkdir -p proj
cp "$SCRIPT_DIR/proj.csproj.template" proj/proj.csproj
# Keeps `dotnet build`'s restore offline -- see the comment in nuget.config.
cp "$SCRIPT_DIR/nuget.config" proj/nuget.config

# Issue #331 -- com o NOME DA EQUIPE, e nao `Program.cs`. O Roslyn reporta o
# nome do arquivo que compilou, e a equipe submeteu `solution.cs`: ler
# `proj/Program.cs(4,14)` a mandava procurar um arquivo que nao existe no
# computador dela. O `.csproj` compila `**/*.cs` por padrao, entao o nome
# nao precisa ser `Program.cs` -- o que precisa continuar valendo e o
# `AssemblyName`, que vem do nome do PROJETO (`proj`) e e o que o run.sh
# invoca.
cp "$SOURCE" "proj/$SOURCE_NAME"

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

# Issue #331 -- o resto do ruido, e o caminho em volta do nome do arquivo.
#
# `--nologo` tira a linha de versao do MSBuild, `-v quiet` tira o "Determining
# projects to restore" e o "Restored ...", e `NoSummary` tira o bloco
# "Build FAILED / N Error(s) / Time Elapsed" que REPETE cada erro ja
# impresso. Erros e avisos continuam saindo: o `quiet` do MSBuild nunca cala
# diagnostico, so progresso -- por isso um NU1100 de restore offline
# continua chegando a equipe.
#
# O `sed` faz duas trocas, e as duas sao textuais e ancoradas no caminho
# desta invocacao (nao num formato de mensagem do Roslyn): apaga o sufixo
# ` [<dir>/proj/proj.csproj]` repetido em cada linha, e encurta
# `<dir>/proj/solution.cs` para `solution.cs`. O resultado e a mesma forma
# que as outras 17 familias de fonte ja entregam.
PROJ_DIR="$PWD/proj"

set +e
dotnet build proj -c Release -o "${OUTPUT}_bin" \
    --nologo -v quiet -consoleloggerparameters:NoSummary 2>&1 \
    | sed -e "s| \[${PROJ_DIR}/proj\.csproj\]||g" -e "s|${PROJ_DIR}/||g"
STATUS=${PIPESTATUS[0]}
set -e

exit "$STATUS"
