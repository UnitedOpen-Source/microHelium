#!/usr/bin/env bash
#
# Issue #252 -- responde, contra um deploy de verdade, a pergunta que nenhum
# teste desta suite alcanca: as linhas do event feed chegam ENQUANTO a prova
# acontece, ou todas juntas no fim?
#
# A secao 3 do runbook (docs/runbooks/252-event-feed-streaming.md) descrevia
# isso em prosa, com um `perl` de uma linha para alguem colar e interpretar.
# Interpretar e onde se erra: "a primeira linha chegou rapido" nao quer dizer
# nada sem saber quanto durou o feed inteiro. Este script mede e decide.
#
# O criterio, e por que ele e este:
#
#   Um feed bufferizado entrega tudo no fechamento da resposta -- primeira e
#   ultima linha no MESMO instante. Um feed que faz streaming entrega a
#   primeira assim que ela existe. Entao o que separa as duas hipoteses nao e
#   o tempo absoluto da primeira linha, e sim a FRACAO do total em que ela
#   chegou. Medido em laboratorio com a configuracao de nginx deste
#   repositorio: 0,04% com streaming, 100% sem. O limite de 50% abaixo fica
#   longe dos dois casos de proposito.
#
# Uso:
#   scripts/clics/verifica-streaming-deploy.sh https://SEU-DEPLOY <ID-DO-CONTEST>
#
# Variaveis:
#   TOKEN         Bearer token, se o feed exigir autenticacao
#   MAX_SEGUNDOS  quanto tempo observar antes de desistir (default 60)
#   MIN_LINHAS    minimo de linhas para a medicao valer (default 20)
#
# Sai com 0 se o streaming esta provado, 1 se esta provado que NAO, e 2 se a
# medicao nao pode ser feita (feed curto demais, sem resposta, etc).

set -uo pipefail

BASE="${1:-}"
CONTEST="${2:-}"
MAX_SEGUNDOS="${MAX_SEGUNDOS:-60}"
MIN_LINHAS="${MIN_LINHAS:-20}"

if [ -z "$BASE" ] || [ -z "$CONTEST" ]; then
    sed -n '2,32p' "$0" | sed 's/^# \{0,1\}//'
    exit 2
fi

URL="${BASE%/}/api/clics/contests/${CONTEST}/event-feed"
AUTH=()
[ -n "${TOKEN:-}" ] && AUTH=(-H "Authorization: Bearer ${TOKEN}")
# ${AUTH[@]+...}: sob `set -u`, o bash 3.2 do macOS trata a expansao de um
# array VAZIO como variavel nao definida e aborta. Medido: sem isto o script
# morria em "AUTH[@]: unbound variable" antes de medir coisa nenhuma.

echo "==> Feed: ${URL}"
echo "==> Observando por ate ${MAX_SEGUNDOS}s (precisa de >= ${MIN_LINHAS} linhas para decidir)"
echo

# ---------------------------------------------------------------------------
# 1. Os cabecalhos, que ja denunciam dois modos de falha sem esperar nada
# ---------------------------------------------------------------------------
CAB="$(curl -sS -N -D - -o /dev/null --max-time 10 ${AUTH[@]+"${AUTH[@]}"} "$URL" 2>/dev/null | tr -d '\r')"

if [ -z "$CAB" ]; then
    echo "INCONCLUSIVO: o feed nao respondeu em 10s. Confira a URL, o contest e o TOKEN." >&2
    exit 2
fi

echo "--- cabecalhos ---"
printf '%s\n' "$CAB" | grep -iE '^(HTTP/|content-type|content-encoding|content-length|transfer-encoding|x-accel-buffering):' || true
echo

PROBLEMAS=0
if printf '%s' "$CAB" | grep -qi '^content-encoding:.*gzip'; then
    echo "PROBLEMA: Content-Encoding: gzip -- alguem no caminho esta comprimindo o feed."
    echo "          Comprimir exige acumular. Tire application/x-ndjson do gzip_types"
    echo "          do proxy (e confira zlib.output_compression no PHP)."
    PROBLEMAS=1
fi
if printf '%s' "$CAB" | grep -qi '^content-length:'; then
    echo "PROBLEMA: Content-Length presente -- a resposta foi bufferizada inteira para"
    echo "          que alguem pudesse medir o tamanho dela. Um stream vai por"
    echo "          Transfer-Encoding: chunked."
    PROBLEMAS=1
fi
[ "$PROBLEMAS" = 1 ] && echo

# ---------------------------------------------------------------------------
# 2. A medicao: quando cada linha chega
# ---------------------------------------------------------------------------
echo "--- chegada das linhas ---"
MEDIDA="$(
    curl -sS -N --max-time "$MAX_SEGUNDOS" ${AUTH[@]+"${AUTH[@]}"} "$URL" 2>/dev/null \
    | perl -MTime::HiRes=time -ne '
        BEGIN { $t0 = time; $n = 0; }
        $n++;
        $t = time - $t0;
        $primeira = $t if $n == 1;
        printf("  linha %-6d t=%.3fs\n", $n, $t) if ($n <= 3 || $n % 500 == 0);
        END {
            $t = time - $t0;
            printf("  ULTIMA linha %d       t=%.3fs\n", $n, $t);
            printf("RESULTADO %d %.6f %.6f\n", $n, $primeira // 0, $t);
        }'
)"

printf '%s\n' "$MEDIDA" | grep -v '^RESULTADO '
RES="$(printf '%s\n' "$MEDIDA" | grep '^RESULTADO ' | tail -1)"
echo

read -r _ LINHAS PRIMEIRA TOTAL <<<"${RES:-RESULTADO 0 0 0}"

if [ "${LINHAS:-0}" -lt "$MIN_LINHAS" ]; then
    echo "INCONCLUSIVO: so ${LINHAS} linhas em ${TOTAL}s -- abaixo do minimo de ${MIN_LINHAS}." >&2
    echo "Um feed curto termina rapido demais para a diferenca aparecer. Use um contest" >&2
    echo "com alguns milhares de eventos, ou aumente MAX_SEGUNDOS para observar a prova" >&2
    echo "em andamento." >&2
    exit 2
fi

FRACAO="$(awk -v p="$PRIMEIRA" -v t="$TOTAL" 'BEGIN { printf "%.2f", (t > 0 ? 100 * p / t : 100) }')"

echo "==> ${LINHAS} linhas em ${TOTAL}s; a primeira chegou a ${FRACAO}% do total."
echo

if awk -v f="$FRACAO" 'BEGIN { exit !(f < 50) }'; then
    if [ "$PROBLEMAS" = 1 ]; then
        echo "SIM, faz streaming -- mas com os problemas de cabecalho acima. Corrija-os:"
        echo "eles costumam piorar com um feed maior ou outro cliente."
        exit 0
    fi
    echo "SIM: o feed faz streaming atraves deste deploy."
    echo "A primeira linha chegou muito antes da ultima, entao nao ha buffer segurando"
    echo "a resposta entre a aplicacao e este cliente."
    exit 0
fi

echo "NAO: este deploy NAO entrega o feed em streaming."
echo "A primeira linha chegou praticamente junto com a ultima (${FRACAO}% do total), que e"
echo "a assinatura de uma resposta acumulada e solta no fechamento. Um resolver trava"
echo "esperando a primeira linha."
echo
echo "Onde procurar, na ordem em que costuma estar:"
echo "  1. um CDN ou balanceador NA FRENTE do nginx (nao esta neste repositorio);"
echo "  2. gzip aplicado a application/x-ndjson em alguma camada;"
echo "  3. zlib.output_compression ligado no PHP (docker/php/php.ini);"
echo "  4. proxy_buffering/fastcgi_buffering ligado explicitamente, que sobrepoe"
echo "     o X-Accel-Buffering: no que a aplicacao manda."
echo
echo "As camadas 2 a 4 deste repositorio ja tem guarda na suite"
echo "(EventFeedStreamingContractTest e EventFeedDeploymentContractTest), entao se"
echo "a suite esta verde a causa esta muito provavelmente na camada 1."
exit 1
