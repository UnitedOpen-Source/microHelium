#!/bin/bash
# Issue #327 -- sobe a pilha do V8 sem trocar um erro legivel por um mudo.
#
# Uma recursao de 10^4 niveis recebia `RE` com `RangeError: Maximum call
# stack size exceeded` em Node e em TypeScript e passava em vinte outras
# linguagens. O `--stack-size` do V8 resolve, e a issue avisa da armadilha:
# `--stack-size` ACIMA da pilha real do sistema operacional faz o V8
# estourar com SIGSEGV em vez de `RangeError`.
#
# A armadilha foi MEDIDA nesta imagem, com `ulimit -s` em 8192 KB e uma
# recursao sem captura:
#
#     --stack-size=984 (padrao)   rc=1    RangeError
#     --stack-size=2000           rc=1    RangeError
#     --stack-size=4000           rc=1    RangeError
#     --stack-size=6000           rc=1    RangeError
#     --stack-size=7000           rc=1    RangeError
#     --stack-size=7800           rc=1    RangeError
#     --stack-size=8192           rc=139  SIGSEGV, sem mensagem
#     --stack-size=12000          rc=139  SIGSEGV, sem mensagem
#     --stack-size=20000          rc=139  SIGSEGV, sem mensagem
#
# O penhasco e exatamente `ulimit -s`. Por isso este script DERIVA o valor
# da pilha real em vez de fixar um numero: um host cujo RLIMIT_STACK seja
# menor que o desta imagem faria um `--stack-size` fixo cair do lado do
# SIGSEGV, e o juiz nao aplica `ulimit -s` nenhum (ver rlimitPrologue) --
# o valor e o que o container herdar.
#
# Tres quartos da pilha deixa uma margem de 2048 KB nesta imagem, bem dentro
# da faixa medida como segura. Profundidade que isso compra, medida:
#
#     --stack-size=984    10.426 quadros
#     --stack-size=2000   21.272 quadros
#     --stack-size=4000   42.621 quadros
#     --stack-size=6000   63.917 quadros
#
# Invocado como: run.sh <memory_mb> <script> [args...]
set -e

MEMORY_MB="$1"
shift

STACK_KB=$(ulimit -s)

# `unlimited` nao da um numero de onde derivar, e a pilha da thread
# principal do Linux cresce ate onde couber; 8192 e o padrao dos containers
# desta imagem e o valor sob o qual a tabela acima foi medida.
if [ "$STACK_KB" = "unlimited" ] || [ -z "$STACK_KB" ]; then
    STACK_KB=8192
fi

V8_STACK_KB=$((STACK_KB * 3 / 4))

# Abaixo do padrao do V8 nao ha o que ganhar, e forcar um valor menor que o
# dele so reduziria a profundidade: nesse caso nao se passa a flag.
if [ "$V8_STACK_KB" -lt 984 ]; then
    exec node --max-old-space-size="$MEMORY_MB" "$@"
fi

exec node --stack-size="$V8_STACK_KB" --max-old-space-size="$MEMORY_MB" "$@"
