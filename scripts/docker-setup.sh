#!/usr/bin/env bash
set -euo pipefail

echo "=== MicroHelium Zero-Host Setup ==="

# 1. Configuração Idempotente do .env e Registro de UID/GID
if [ ! -f .env ]; then
    echo "Criando .env a partir de .env.docker..."
    cp .env.docker .env
fi

# Garantir DOCKER_UID e DOCKER_GID gravados no .env
CURRENT_UID=$(id -u)
CURRENT_GID=$(id -g)

if ! grep -q "^DOCKER_UID=" .env; then
    echo "DOCKER_UID=${CURRENT_UID}" >> .env
else
    sed -i "s/^DOCKER_UID=.*/DOCKER_UID=${CURRENT_UID}/" .env
fi

if ! grep -q "^DOCKER_GID=" .env; then
    echo "DOCKER_GID=${CURRENT_GID}" >> .env
else
    sed -i "s/^DOCKER_GID=.*/DOCKER_GID=${CURRENT_GID}/" .env
fi

export DOCKER_UID="${CURRENT_UID}"
export DOCKER_GID="${CURRENT_GID}"

# Carrega APP_PORT do .env se não estiver no ambiente
if [ -z "${APP_PORT:-}" ] && [ -f .env ]; then
    APP_PORT=$(grep -E '^APP_PORT=' .env | cut -d= -f2 | tr -d '\r"' || true)
fi
APP_PORT="${APP_PORT:-8000}"
export APP_PORT

# 2. Limpeza de resíduos de desenvolvimento anteriores
rm -f public/hot

# 3. Trap de Limpeza dos Dockerfiles Temporários
cleanup() {
    rm -f Dockerfile.slim Dockerfile.dev.slim Dockerfile.judge.slim
}
trap cleanup EXIT INT TERM

# 4. Build Zero-Host com Containerização Pura do Python
JUDGE_PROFILE="${JUDGE_PROFILE:-maratona}"
if [ "$JUDGE_PROFILE" = "maratona" ] || [ "$JUDGE_PROFILE" = "minimal" ]; then
    echo "Processando Dockerfiles slim com container Python..."
    docker run --rm \
        --user "${CURRENT_UID}:${CURRENT_GID}" \
        -v "$(pwd):/app" \
        -w /app \
        python:3.12-alpine python3 docker/strip_builders.py

    DOCKERFILE_APP=Dockerfile.slim \
    DOCKERFILE_DEV=Dockerfile.dev.slim \
    DOCKERFILE_JUDGE=Dockerfile.judge.slim \
    JUDGE_PROFILE="$JUDGE_PROFILE" \
    docker compose build app
else
    JUDGE_PROFILE="$JUDGE_PROFILE" docker compose build app
fi

# 5. Instalação Determinística de Dependências PHP e Node
echo "Instalando dependências do Composer..."
docker compose run --rm -T composer install --optimize-autoloader

echo "Instalando dependências frontend e compilando assets Vite..."
docker compose run --rm -T node sh -c "npm ci && npm run build"

# 6. Inicialização com Espera Saudável do Banco de Dados
echo "Subindo MySQL e Redis com espera de healthcheck..."
docker compose up -d --wait --wait-timeout 120 db redis

# 7. Configuração da Aplicação Laravel
if ! grep -q "^APP_KEY=base64:[A-Za-z0-9+/=]\+" .env 2>/dev/null; then
    echo "Gerando nova APP_KEY..."
    docker compose run --rm -T artisan key:generate --force
else
    echo "APP_KEY existente preservada com sucesso."
fi

echo "Vinculando storage público..."
docker compose run --rm -T artisan storage:link --force

echo "Executando migrações do banco de dados..."
docker compose run --rm -T artisan migrate --force

echo "Populando dados iniciais e usuário administrador (Seed)..."
docker compose run --rm -T artisan db:seed --force

# 8. Inicialização da Stack Completa
echo "Iniciando todos os serviços..."
docker compose up -d

# 9. Verificação de Fumaça com Retry
echo "Aguardando estabilização dos serviços web..."
for _ in {1..15}; do
    HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:${APP_PORT}/up" || true)
    if [ "$HTTP_STATUS" = "200" ]; then
        break
    fi
    printf "."
    sleep 2
done
echo ""

echo "Executando testes de fumaça..."
SMOKE_PROD=0 ./scripts/smoke.sh

echo "=== Setup Zero-Host concluído com sucesso! Acesse http://localhost:${APP_PORT:-8000} ==="
