#!/bin/sh
# Issue #296 -- a compilacao de G-Portugol, em duas etapas: `gpt -t` traduz
# para C, o `gcc` compila o C. Invocado de dentro do diretorio do run, como
# `compile.sh <fonte> <base do executavel>` (AutoJudgeService::compile()).
#
# ## Por que NAO se usa `gpt -o`
#
# O `gpt` sabe gerar executavel sozinho (`-o`), e seria uma linha em vez
# deste arquivo. Nao pode ser usado, e o motivo esta medido:
#
#   o backend proprio do `gpt` emite assembly NASM de 32 bits e o monta
#   sem consultar a arquitetura da maquina. Medido em aarch64, COM o nasm
#   instalado: ele SAI COM 0, escreve um `ELF 32-bit LSB executable, Intel
#   i386`, e esse arquivo estoura com SIGSEGV na primeira instrucao. E
#   medido na imagem do juiz, que nao instala nasm: morre com
#   `nasm: not found`. As duas formas dao veredito errado, e a primeira e
#   a pior -- compilacao que "passa" e binario que morre transforma toda
#   submissao correta em RE.
#
# Compilacao que "passa" e binario que morre e a receita do veredito errado:
# toda submissao correta viraria RE. A tradução para C nao tem esse problema
# -- o C gerado nao menciona arquitetura nenhuma, e quem escolhe o alvo
# passa a ser o `gcc` da imagem.
#
# ## Por que NAO se usa `gpt -i`
#
# O interpretador embutido tem o defeito da familia #269/#301: MEDIDO, um
# arquivo com erro de sintaxe imprime o diagnostico e SAI COM 0. Como o CE
# vem do codigo de saida da compilacao, programa que nem analisa passaria
# para a execucao e viraria WA. O `-t`, esse, sai com 1 -- e e por isso que
# esta linguagem nao precisou do contorno que o Portugol Studio precisou.
set -eu

FONTE="$1"
SAIDA="$2"

# A biblioteca padrao da linguagem (`raiz_quadrada`, `potencia`,
# `absoluto`, `arredonda`) NAO e embutida no compilador: ela e um arquivo
# `.gpt` que o `GPT_INCLUDE` tem de apontar. Medido -- sem isto,
# `raiz_quadrada(x)` vira "Função não foi declarada" e a submissao recebe
# CE por uma funcao que o manual documenta como interna.
#
# E o caminho de um ARQUIVO, e nao de um diretorio: o `GPT_INCLUDE` e "uma
# lista de caminhos de arquivo separados por `:`" (gpt(1)). Apontar para
# `/opt/gportugol/lib/gpt` falha silenciosamente -- tambem medido.
GPT_INCLUDE="${GPT_INCLUDE:-/opt/gportugol/lib/gpt/base.gpt}"
export GPT_INCLUDE

C_GERADO="${SAIDA}.gpt.c"

# Etapa 1. O diagnostico do `gpt` ja sai em stderr, no formato
# `arquivo:linha - mensagem`, e citando o arquivo que a equipe submeteu.
# Nada a reescrever: e repassado como esta.
set +e
gpt -t "$C_GERADO" "$FONTE"
ESTADO=$?
set -e

if [ "$ESTADO" -ne 0 ]; then
    exit "$ESTADO"
fi

# Etapa 2. `-w` de proposito, e nao por preguica.
#
# O C gerado dispara tres avisos de `-Wunused-result` em TODA submissao
# (`scanf` dentro de `leia_inteiro`, `leia_caractere` e `leia_real`), porque
# o gerador e quem os escreve -- nao a equipe. Sem `-w`, todo envio, inclusive
# o perfeito, vem com nove linhas de aviso sobre um arquivo que a equipe
# nunca viu. E o mesmo ruido que a #331 tirou do C#.
#
# Se o `gcc` FALHAR, porem, o defeito e do tradutor e nao do programa: a
# mensagem citaria `solucao.gpt.c`, um arquivo que nao existe no computador
# de ninguem. Entao ela e substituida por uma que diz o que houve.
set +e
ERRO_GCC=$(gcc -O2 -w -o "$SAIDA" "$C_GERADO" 2>&1)
ESTADO=$?
set -e

if [ "$ESTADO" -ne 0 ]; then
    printf 'O tradutor do G-Portugol gerou C que nao compila. Isto e um defeito do compilador, e nao do seu programa -- avise a organizacao.\n' >&2
    printf '%s\n' "$ERRO_GCC" >&2
    exit "$ESTADO"
fi

exit 0
