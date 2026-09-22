#!/usr/bin/env bash
#
# Issue #252 -- mede o `X-Accel-Buffering: no` e o `flush()` ATRAVESSANDO o
# nginx, sem precisar de deploy.
#
# A issue dizia que essa parte so um deploy real responderia. Metade dela
# nao: a configuracao do nginx de producao esta versionada AQUI
# (docker/nginx/*.conf, montadas pelo docker-compose.yml), e a do PHP
# tambem (docker/php/php.ini e www.conf, instalados pelo Dockerfile). Subir
# nginx + php-fpm com ESSES MESMOS ARQUIVOS e medir com `curl -N` de fora
# responde pelo caminho inteiro. O que fica de fora e o host -- um CDN ou
# balanceador na frente --, nao a configuracao; para isso existe
# scripts/clics/verifica-streaming-deploy.sh.
#
# O feed real precisa de banco, contest e eventos. Este script nao o sobe:
# ele sobe um emissor minimo que repete o laco de
# ContestApiController::emit() -- mesmo Content-Type, mesmos cabecalhos,
# mesmo ob_flush()/flush() por linha. O que esta sob medicao e o CAMINHO
# (nginx + php-fpm + as confs), nao a montagem do JSON, que a suite ja cobre.
#
# Cada variante abaixo desliga UMA coisa. Uma guarda que nao fica vermelha
# quando o mecanismo some nao esta medindo nada -- e foi exatamente assim
# que este repositorio descobriu, oito vezes, que tinha teste verde contra
# mecanismo quebrado.
#
# Uso:  scripts/clics/verifica-streaming-nginx.sh
# Requer: docker (com o daemon rodando), curl, perl.
# Sai 0 se o resultado for o esperado em todas as variantes.

set -uo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PORTA="${PORTA:-18080}"
LAB="$(mktemp -d)"
PROJETO="mh252-$$"

limpar() {
    docker compose -p "$PROJETO" -f "$LAB/compose.yml" down -v >/dev/null 2>&1 || true
    rm -rf "$LAB"
}
trap limpar EXIT

if ! docker info >/dev/null 2>&1; then
    echo "Docker nao esta disponivel. Este script precisa do daemon rodando." >&2
    exit 2
fi

echo "==> Usando as configuracoes versionadas do repositorio:"
for f in docker/nginx/nginx.conf docker/nginx/default.conf docker/php/php.ini docker/php/www.conf; do
    [ -f "$RAIZ/$f" ] || { echo "FALTANDO: $f" >&2; exit 2; }
    echo "      $f"
done
echo

mkdir -p "$LAB/conf" "$LAB/public"
cp "$RAIZ/docker/nginx/nginx.conf" "$LAB/conf/nginx.conf"
cp "$RAIZ/docker/nginx/default.conf" "$LAB/conf/default.conf"
cp "$RAIZ/docker/php/php.ini" "$LAB/conf/php.ini"

# O www.conf do repositorio, com duas adaptacoes de laboratorio e nada mais:
# o usuario da imagem oficial (www-data, nao www) e os logs em /tmp, que
# existe no container. O request_terminate_timeout fica como esta no
# repositorio.
python3 - "$RAIZ/docker/php/www.conf" "$LAB/conf/www.conf" <<'PY'
import sys
origem, destino = sys.argv[1], sys.argv[2]
s = open(origem, encoding='utf-8').read()
s = s.replace("= www\n", "= www-data\n").replace("/var/log/php/", "/tmp/")
open(destino, 'w', encoding='utf-8').write(s)
PY

cat > "$LAB/public/index.php" <<'PHP'
<?php
// Replica minima do laco de App\Http\Controllers\Clics\ContestApiController::emit().
// Cada parametro desliga UMA coisa, para a medicao poder atribuir a falha.
$accel   = ($_GET['accel']   ?? '1') !== '0';   // o X-Accel-Buffering: no
$obflush = ($_GET['obflush'] ?? '1') !== '0';   // o ob_flush() por linha
$doFlush = ($_GET['flush']   ?? '1') !== '0';   // o flush() de SAPI
$n       = (int) ($_GET['n'] ?? 20);
$sleepUs = (int) ($_GET['sleep_us'] ?? 250000);

header('Content-Type: application/x-ndjson');
header('Cache-Control: no-cache');
header('X-Ob-Level: '.ob_get_level());
if ($accel) {
    header('X-Accel-Buffering: no');
}

for ($i = 1; $i <= $n; $i++) {
    echo json_encode([
        'type' => 'submissions',
        'id' => (string) $i,
        'data' => ['id' => (string) $i, 'team_id' => '42', 'problem_id' => 'A', 'language_id' => 'cpp'],
        'token' => (string) $i,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

    if ($obflush && ob_get_level() > 0) {
        @ob_flush();
    }
    if ($doFlush) {
        @flush();
    }
    usleep($sleepUs);
}
PHP

cat > "$LAB/compose.yml" <<YML
services:
  app:
    image: php:8.3-fpm-alpine
    volumes:
      - $LAB/public:/var/www/html/public:ro
      - $LAB/conf/php.ini:/usr/local/etc/php/conf.d/custom.ini:ro
      - $LAB/conf/www.conf:/usr/local/etc/php-fpm.d/www.conf:ro
  web:
    image: nginx:alpine
    depends_on: [app]
    ports:
      - "$PORTA:80"
    volumes:
      - $LAB/public:/var/www/html/public:ro
      - $LAB/conf/nginx.conf:/etc/nginx/nginx.conf:ro
      - $LAB/conf/default.conf:/etc/nginx/conf.d/default.conf:ro
YML

echo "==> Subindo nginx + php-fpm..."
docker compose -p "$PROJETO" -f "$LAB/compose.yml" up -d >/dev/null 2>&1 || {
    echo "nao consegui subir os containers" >&2; exit 2; }

for _ in $(seq 1 30); do
    curl -sf -m 2 -o /dev/null "http://127.0.0.1:${PORTA}/health" && break
    sleep 1
done

NIVEL="$(curl -s -m 10 -D - -o /dev/null "http://127.0.0.1:${PORTA}/index.php?n=1&sleep_us=1000" | tr -d '\r' | awk -F': ' '/^X-Ob-Level/ {print $2}')"
echo "==> ob_get_level() sob este php.ini: ${NIVEL:-?}  (0 significaria que o ob_flush() e inocuo aqui)"
echo

# ---------------------------------------------------------------------------
medir() { # $1 rotulo  $2 query  $3 esperado (sim|nao)
    local rotulo="$1" query="$2" esperado="$3"
    local res linhas primeira total fracao obtido

    res="$(curl -N -s --max-time 40 "http://127.0.0.1:${PORTA}/index.php?${query}" \
        | perl -MTime::HiRes=time -ne '
            BEGIN { $t0 = time; $n = 0 }
            $n++; $t = time - $t0; $p = $t if $n == 1;
            END { printf("%d %.6f %.6f\n", $n, $p // 0, time - $t0) }')"

    read -r linhas primeira total <<<"$res"
    fracao="$(awk -v p="$primeira" -v t="$total" 'BEGIN { printf "%.1f", (t > 0 ? 100 * p / t : 100) }')"
    obtido="$(awk -v f="$fracao" 'BEGIN { print (f < 50 ? "sim" : "nao") }')"

    printf '  %-52s 1a linha a %5s%% do total  streaming=%-3s ' "$rotulo" "$fracao" "$obtido"
    if [ "$obtido" = "$esperado" ]; then
        echo "OK"
        return 0
    fi
    echo "INESPERADO (esperava ${esperado})"
    return 1
}

echo "==> Medindo (20 linhas, 250ms entre elas; ~5s por variante)"
echo
FALHAS=0
medir "como esta hoje"                      "n=20&sleep_us=250000"                        sim || FALHAS=1
medir "MUTACAO: sem X-Accel-Buffering: no"  "n=20&sleep_us=250000&accel=0"                nao || FALHAS=1
medir "MUTACAO: sem ob_flush()"             "n=20&sleep_us=250000&obflush=0"              nao || FALHAS=1
medir "MUTACAO: sem flush() de SAPI"        "n=20&sleep_us=250000&flush=0"                nao || FALHAS=1
echo

if [ "$FALHAS" = 0 ]; then
    echo "TUDO COMO ESPERADO."
    echo
    echo "A primeira linha atravessa o nginx desta configuracao em uma fracao minima"
    echo "do tempo do feed, e cada uma das tres pecas e necessaria: removendo qualquer"
    echo "uma delas o feed inteiro passa a chegar de uma vez no fim."
    echo
    echo "O que este script NAO responde, e continua na secao 3 do runbook: um CDN ou"
    echo "balanceador NA FRENTE deste nginx, que nao esta versionado aqui. Para isso,"
    echo "scripts/clics/verifica-streaming-deploy.sh contra a URL real."
    exit 0
fi

echo "ALGUMA VARIANTE NAO SE COMPORTOU COMO O ESPERADO -- leia a tabela acima." >&2
echo "Se 'como esta hoje' deu streaming=nao, a configuracao versionada regrediu." >&2
echo "Se uma MUTACAO deu streaming=sim, a peca que ela desliga deixou de ser" >&2
echo "necessaria -- o que quer dizer que a guarda correspondente na suite virou" >&2
echo "teatro e precisa ser revista." >&2
exit 1
