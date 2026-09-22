# ---------------------------------------------------------------------------
# GNU Prolog e SWI-Prolog (issue #305, Lote D) -- construidos do fonte.
#
# Nenhum dos dois tem pacote no Alpine 3.24, e nenhum dos dois publica
# binario para musl. O porque de cada flag -- inclusive o `-std=gnu17`, sem
# o qual o GNU Prolog 1.5.0 NAO compila com o gcc 15 -- esta escrito no
# Dockerfile.judge, uma vez so.
#
# Esta imagem precisa dos dois pelo mesmo motivo que precisa dos outros
# compiladores: docker-compose.yml roda ELA nos servicos `queue` e
# `scheduler`, e e la que o JudgeRunJob compila e executa codigo submetido.
FROM php:8.3-fpm-alpine AS gprolog-builder

ARG GPROLOG_VERSION=1.5.0
ARG GPROLOG_SHA256=670642b43c0faa27ebd68961efb17ebe707688f91b6809566ddd606139512c01
RUN set -eux; \
    apk add --no-cache gcc make musl-dev libc-dev; \
    wget -q "https://ftp.gnu.org/gnu/gprolog/gprolog-${GPROLOG_VERSION}.tar.gz" -O /tmp/gprolog.tar.gz; \
    echo "${GPROLOG_SHA256}  /tmp/gprolog.tar.gz" | sha256sum -c -; \
    mkdir -p /tmp/gprolog-src; \
    tar -xzf /tmp/gprolog.tar.gz -C /tmp/gprolog-src --strip-components=1; \
    cd /tmp/gprolog-src/src; \
    ./configure \
        --prefix=/opt \
        --with-install-dir=/opt/gprolog \
        --without-links-dir \
        --disable-gui-console \
        --with-c-flags="-std=gnu17 -O2 -fno-strict-aliasing -fcommon -Wno-char-subscripts"; \
    make; \
    make install; \
    test -x /opt/gprolog/bin/gplc; \
    test -x /opt/gprolog/bin/pl2wam

FROM php:8.3-fpm-alpine AS swipl-builder

ARG SWIPL_VERSION=10.0.2
ARG SWIPL_SHA256=e42cc098f7b8a6051c4f79a99b55162d467098aba60f69649bdc7583f0734b57
RUN set -eux; \
    apk add --no-cache cmake samurai gcc g++ musl-dev libc-dev gmp-dev ncurses-dev zlib-dev; \
    wget -q "https://www.swi-prolog.org/download/stable/src/swipl-${SWIPL_VERSION}.tar.gz" -O /tmp/swipl.tar.gz; \
    echo "${SWIPL_SHA256}  /tmp/swipl.tar.gz" | sha256sum -c -; \
    mkdir -p /tmp/swipl-src; \
    tar -xzf /tmp/swipl.tar.gz -C /tmp/swipl-src --strip-components=1; \
    cmake -S /tmp/swipl-src -B /tmp/swipl-build -G Ninja \
        -DCMAKE_BUILD_TYPE=Release \
        -DCMAKE_INSTALL_PREFIX=/opt/swipl \
        -DSWIPL_PACKAGES=OFF \
        -DUSE_GMP=ON; \
    ninja -C /tmp/swipl-build; \
    ninja -C /tmp/swipl-build install; \
    /opt/swipl/bin/swipl --version

# ---------------------------------------------------------------------------
# Portugol Studio (issue #269, parte A) -- construido do fonte.
#
# Issue #302: este estagio existia SO no Dockerfile.judge, e o preco disso
# foi medido. `docker-compose.yml` roda ESTA imagem nos servicos `queue` e
# `scheduler`, e e la que o `JudgeRunJob` compila e executa. Numa instalacao
# que nao sobe o servico `autojudge` -- e o compose de dev nao sobe --, TODA
# submissao passa por aqui. Sem estes dois runtimes, uma submissao CORRETA
# em Portugol Studio virava `CE` com `portugol-studio-check: command not
# found`; o proprio comentario da lista de pacotes do Dockerfile.judge ja
# prometia que as duas listas nao poderiam derivar, e derivaram.
#
# Pior que errar: errar dizendo "erro de compilacao". As duas linguagens sao
# semeadas `is_active => true` globalmente em
# `Language::getDefaultLanguages()`, entao a plataforma OFERECE a linguagem
# no formulario e no Contest Wizard e depois reprova a submissao por um erro
# que nao existe -- e essa reprovacao conta como tentativa errada na
# penalidade do placar.
#
# O porque de cada escolha aqui (o commit fixado em vez da tag, o JDK 11 por
# causa do Gradle 4.10, o `:portugol-console`) esta escrito no
# Dockerfile.judge, uma vez so.
FROM eclipse-temurin:11-jdk AS portugol-studio-builder

ARG PORTUGOL_STUDIO_COMMIT=5640a2e95de1c5d63c11f453c324812b4d6a8f6c

RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/* \
    && git clone --no-checkout https://github.com/UNIVALI-LITE/Portugol-Studio.git /src \
    && cd /src \
    && git fetch --depth 1 origin "${PORTUGOL_STUDIO_COMMIT}" \
    && git checkout --detach "${PORTUGOL_STUDIO_COMMIT}"

WORKDIR /src

RUN ./gradlew :portugol-console:jar --no-daemon -q

# As duas etapas do juiz, e nenhuma delas e o Console -- gemeo do estagio do
# Dockerfile.judge (issues #269 e #301), e as medicoes de cada classe estao
# nos docblocks delas.
COPY docker/judge/portugol/VerificaPortugol.java /verifica/
COPY docker/judge/portugol/ExecutaPortugol.java /verifica/

RUN set -eux; \
    libs="$(find /src/console/build/libs -name '*.jar' | tr '\n' ':')"; \
    mkdir -p /verifica/classes; \
    javac -encoding UTF-8 -cp "$libs" -d /verifica/classes \
        /verifica/VerificaPortugol.java /verifica/ExecutaPortugol.java; \
    test -s /verifica/classes/VerificaPortugol.class; \
    test -s /verifica/classes/ExecutaPortugol.class

# ---------------------------------------------------------------------------
# Scratch (issue #268) -- construido aqui, e nao baixado. Gemeo do estagio do
# Dockerfile.judge; a medicao que descarta os binarios do release (ELF glibc,
# `not found` no musl) esta la.
#
# Issue #302: mesma razao do estagio acima -- esta imagem julga pela fila.
FROM node:24-alpine AS scratch-run-builder

ARG SCRATCH_RUN_COMMIT=02f11e519210f8593cdcf04f257237fc0ceb7180

RUN apk add --no-cache git \
    && git clone --no-checkout https://github.com/VNOI-Admin/scratch-run.git /src \
    && cd /src \
    && git fetch --depth 1 origin "${SCRATCH_RUN_COMMIT}" \
    && git checkout --detach "${SCRATCH_RUN_COMMIT}"

WORKDIR /src
RUN npm install --no-audit --no-fund && npx webpack

RUN test -s /src/dist/index.js

# ---------------------------------------------------------------------------
# G-Portugol (issue #296) -- construido do fonte, e o build e o resultado
# principal desta issue.
#
# A #296 registrava TRES bloqueios medidos, e os tres viviam do mesmo fato:
# o `gpt` nao construia. "Alpine nao empacota ANTLR", "no Debian o `make`
# morre em `Token stream error reading grammar(s)`" e "so ha binario
# x86_64". Os tres caem aqui, e o que os derrubou foi uma variavel:
#
#   O `runantlr` e `java -cp antlr.jar antlr.Tool`, e o ANTLR 2.7.7 (de
#   2006) le o arquivo de gramatica no CHARSET PADRAO DA JVM. Num contêiner
#   sem `LANG`, esse padrao e ASCII. E `lexer.g` declara as palavras-chave
#   ACENTUADAS do portugol (`algoritmo`, `funcao` com cedilha, `inicio` com
#   acento), entao o `í` de `algorítmos` chega como `0xFFFD` e o leitor para:
#
#     ./lexer.g:55:10: unexpected char: 'r'
#     TokenStreamException: unexpected char: 0xFFFD
#
#   Com `-Dfile.encoding=UTF-8` as SEIS gramaticas do projeto passam e o
#   `make` vai ao fim. Nao e a gramatica, nao sao os bytes do arquivo (sao
#   UTF-8 validos nos dois lados do commit que a issue suspeitava): e o
#   ambiente em que o ANTLR foi chamado. A medicao anterior concluiu o
#   contrario porque foi feita num shell que ja tinha `LANG` UTF-8.
#
# E o runtime C++ do ANTLR 2.7, que o Alpine realmente nao empacota, compila
# em musl sem um patch -- so precisa de um `config.sub`/`config.guess` deste
# seculo, porque o de 2006 responde "cannot guess build type" em aarch64.
#
# Resultado: o `gpt` e construido NA IMAGEM, em aarch64 e em x86_64. Isso
# tira a dependencia do unico binario publicado pelo upstream (x86_64) e
# fecha o bloqueio que a #53 abriria num parque arm64.
#
# Custo medido: ~4 min de estagio, e 1,2 MB instalados (`/opt/gportugol`).
# O JDK/JRE e o ANTLR ficam AQUI -- nada disso viaja na imagem final.
FROM php:8.3-fpm-alpine AS gportugol-builder

# Versao E hash (issue #304). O tarball do ANTLR 2.7.7 vem de antlr2.org, e
# o sha256 foi conferido contra o download em 21/09/2026.
ARG ANTLR2_VERSION=2.7.7
ARG ANTLR2_SHA256=853aeb021aef7586bda29e74a6b03006bcb565a755c86b66032d8ec31b67dbb9
RUN set -eux; \
    apk add --no-cache build-base autoconf automake libtool pcre2-dev bison flex openjdk17-jre-headless; \
    wget -q "https://www.antlr2.org/download/antlr-${ANTLR2_VERSION}.tar.gz" -O /tmp/antlr.tar.gz; \
    echo "${ANTLR2_SHA256}  /tmp/antlr.tar.gz" | sha256sum -c -; \
    mkdir -p /tmp/antlr-src; \
    tar -xzf /tmp/antlr.tar.gz -C /tmp/antlr-src --strip-components=1; \
    cd /tmp/antlr-src; \
    cp /usr/share/automake-*/config.sub /usr/share/automake-*/config.guess scripts/; \
    ./configure --prefix=/usr/local --disable-examples; \
    make -C lib/cpp install; \
    install -m 0755 scripts/antlr-config /usr/local/bin/antlr-config; \
    install -m 0644 antlr.jar /usr/local/share/antlr.jar; \
    printf '#!/bin/sh\nexec java -Dfile.encoding=UTF-8 -cp /usr/local/share/antlr.jar antlr.Tool "$@"\n' > /usr/local/bin/runantlr; \
    chmod 0755 /usr/local/bin/runantlr; \
    test -f /usr/local/lib/libantlr.a; \
    test -x /usr/local/bin/antlr-config

# COMMIT, e nao tag: e a tag `1.2.0`, e ela pode se mover. O `-t` (traducao
# para C) e o `EXIT_FAILURE` em erro de analise -- os dois fatos de que esta
# linguagem depende -- foram medidos NESTE commit.
ARG GPORTUGOL_COMMIT=f324b698c851c9455337043452c52bfd10cb1efa
RUN set -eux; \
    apk add --no-cache git; \
    git clone --no-checkout https://github.com/gportugol/gpt.git /tmp/gpt-src; \
    cd /tmp/gpt-src; \
    git fetch --depth 1 origin "${GPORTUGOL_COMMIT}"; \
    git checkout --detach "${GPORTUGOL_COMMIT}"; \
    autoreconf -fi; \
    ./configure --prefix=/opt/gportugol; \
    make; \
    make install; \
    strip /opt/gportugol/bin/gpt; \
    test -x /opt/gportugol/bin/gpt; \
    test -s /opt/gportugol/lib/gpt/base.gpt

# MicroHelium Dockerfile
# PHP 8.3 with required extensions for Laravel 12

FROM php:8.3-fpm-alpine

LABEL maintainer="MicroHelium Team"
LABEL description="MicroHelium - Hackathon and Programming Contest Management Platform"

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
# Issue #303 -- os toolchains abaixo estao com VERSAO FIXADA.
#
# O criterio: tudo que compila ou executa codigo de competidor -- e o
# `bubblewrap`, que o confina -- e fixado; o que so constroi a imagem (git,
# curl, os -dev) nao e. Um veredito nao pode depender do dia em que a imagem
# foi construida, ainda mais com julgamento distribuido (#53), em que a
# imagem e construida tambem por parceiros.
#
# Esta lista tem de acompanhar a do Dockerfile.judge.
RUN apk add --no-cache \
    git \
    curl \
    wget \
    ca-certificates \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    libxml2-dev \
    postgresql-dev \
    linux-headers \
    bubblewrap=0.12.0-r0 \
    # Issue #142: `backup:create` shells out to mysqldump, and this image had
    # no MySQL client at all -- the backup button would have failed on the
    # one host where it matters. Alpine's client is MariaDB's;
    # mariadb-connector-c is what carries the caching_sha2_password auth
    # plugin, and without it the dump cannot even log in to a default
    # MySQL 8 server (the db service in docker-compose.yml).
    mariadb-client \
    mariadb-connector-c \
    $PHPIZE_DEPS \
    # Supervisor for queue workers
    supervisor \
    # Compilers for autojudge -- this list must cover every language
    # Language::getDefaultLanguages() marks is_active by default, or admins
    # can select a language in the Contest Wizard that the judge then can't
    # actually compile/run.
    gcc=15.2.0-r5 \
    clang22=22.1.3-r2 \
    g++=15.2.0-r5 \
    make \
    python3=3.14.7-r1 \
    openjdk21-jdk=21.0.12_p8-r0 \
    openjdk25-jdk=25.0.4_p7-r0 \
    nodejs=24.18.1-r0 \
    npm=11.12.1-r0 \
    dotnet8-sdk=8.0.131-r0 \
    dotnet10-sdk=10.0.303-r0 \
    go=1.26.8-r0 \
    rust=1.96.1-r0 \
    ruby=3.4.9-r0 \
    perl=5.42.2-r0 \
    # Issue #305, Lote C -- o mesmo conjunto do Dockerfile.judge, onde
    # cada um destes foi compilado e executado num `a+b` dentro do
    # sandbox antes de ser ligado no catalogo. A lista das duas imagens
    # tem de andar junta: esta serve o caminho de julgamento pela fila, e
    # uma linguagem ativa que so existisse numa das duas falharia
    # dependendo de qual worker pegasse a submissao (#302).
    lua5.4=5.4.8-r0 \
    gawk=5.3.2-r2 \
    tcl=8.6.17-r1 \
    clojure=1.12.5-r0 \
    R=4.6.0-r0 \
    elixir=1.19.6-r0 \
    erlang27=27.3.4.17-r0 \
    gfortran=15.2.0-r5 \
    gcc-gnat=15.2.0-r5 \
    sbcl=2.6.5-r0 \
    clisp=2.49.95_git250727-r1 \
    guile=3.0.9-r2 \
    racket=9.2-r0 \
    zig=0.16.0-r1 \
    nim=2.2.0-r0 \
    crystal=1.20.3-r0 \
    ldc=1.42.0-r0 \
    ocaml=4.14.3-r0 \
    ghc=9.10.3-r2 \
    # Issue #305, Lote D -- COBOL vem do proprio Alpine (`apk search -x
    # gnucobol`), ao contrario do que a issue supunha.
    gnucobol=3.2-r0 \
    # Bibliotecas de execucao do SWI-Prolog construido nos estagios acima
    # (medido com `ldd`), e o shim de glibc de que o Dart precisa. Ver
    # Dockerfile.judge para a medicao completa.
    gmp=6.3.0-r4 \
    ncurses-libs=6.6_p20260516-r0 \
    zlib=1.3.2-r0 \
    gcompat=1.1.0-r4 \
    libstdc++=15.2.0-r5 \
    # AutoJudgeService::runCustomScript()/runCompareScript() invoke problem
    # compile/run/compare scripts via `bash`, which Alpine doesn't ship by default
    bash=5.3.9-r1 \
    # setpriv, for docker/php/entrypoint.sh's privilege drop (issue #136).
    # Busybox ships a /bin/setpriv that only implements --inh-caps and
    # --no-new-privs; --reuid/--regid are util-linux's. Dockerfile.judge
    # installs this package for the same reason.
    util-linux

# Issue #305 -- os invocadores de Clojure, Racket, Tcl e das duas LTS de
# C#, iguais aos do Dockerfile.judge. Ver o comentario la para por que eles
# tem nome proprio em vez de serem um `java -cp ...`/`bash ...` escrito no
# catalogo.
COPY --chmod=755 docker/judge/bin/ /usr/local/bin/

# TypeScript (npx tsc, used by the "TypeScript (Node 24)" language)
ARG TYPESCRIPT_VERSION=7.0.2
RUN npm install -g "typescript@${TYPESCRIPT_VERSION}"

# Kotlin has no Alpine package; JetBrains only ships it as a GitHub release
# zip. This is the same install method used by every other Kotlin judge
# container (it's pure JVM + a launcher script, so it's arch-independent
# given the JDK above).
#
# Issue #304 -- KOTLIN_SHA256 e o sha256 que o proprio GitHub atesta para
# este artefato (campo `digest` de
# GET /repos/JetBrains/kotlin/releases/tags/v2.4.20, conferido em
# 2026-09-19), e nao um hash calculado a partir do que esta maquina baixou.
# A diferenca e o ponto inteiro: um hash tirado do download nao prova nada
# sobre o download.
#
# Por que isto vale a linha que ocupa: o juiz compila e executa codigo nao
# confiavel por construcao (docs/specs/49-judge-isolation.md), e todo o
# desenho de isolamento parte de que o SANDBOX e hostil e o TOOLCHAIN e
# confiavel. Um compilador trocado roda fora do sandbox, com o privilegio do
# estagio de build -- e o lado do qual nenhum `bwrap` protege. O padrao ja
# existia neste arquivo (o JPlag, mais abaixo); faltava aplicar aos vizinhos.
ARG KOTLIN_VERSION=2.4.20
ARG KOTLIN_SHA256=59e9ca74c7904ef2c122b12114937673ccce68de820a663f0ed66ccf8799e0b7
RUN wget -q "https://github.com/JetBrains/kotlin/releases/download/v${KOTLIN_VERSION}/kotlin-compiler-${KOTLIN_VERSION}.zip" -O /tmp/kotlin.zip \
    && echo "${KOTLIN_SHA256}  /tmp/kotlin.zip" | sha256sum -c - \
    && unzip -q /tmp/kotlin.zip -d /opt \
    && rm /tmp/kotlin.zip \
    && ln -s /opt/kotlinc/bin/kotlinc /usr/local/bin/kotlinc \
    && ln -s /opt/kotlinc/bin/kotlin /usr/local/bin/kotlin

# Free Pascal has no Alpine package either. Its official releases ship a
# pre-built compiler binary per architecture (no compilation needed); we
# only need the compiler + RTL units, not the full units-* extras or docs.
#
# Issue #304 -- este comentario prometia uma "byte-count verification" que
# NAO existia no codigo: o laco so fazia `tar -tf`, que prova que o arquivo
# ABRE, nao que o conteudo e o esperado. Um tar truncado num limite de bloco
# lista sem erro, e um tar integro mas DIFERENTE passava sempre. Um
# comentario que descreve protecao inexistente e pior do que nenhum, porque
# impede que alguem note a falta.
#
# O que existe agora, de verdade: SHA-256 fixado por arquitetura, conferido
# a cada tentativa. O laco continua porque o download do SourceForge
# realmente serve arquivo truncado sob ferramenta automatizada -- mas quem
# decide se a tentativa valeu e o hash, e nao o `tar`.
#
# E o `wget -c` saiu. `-c` RETOMA um download parcial: numa retentativa ele
# costurava o segundo pedaco no primeiro, que e exatamente o modo de falha
# que o laco existia para evitar. Cada tentativa agora baixa limpo.
ARG FPC_VERSION=3.2.2
ARG FPC_SHA256_AARCH64=b39470f9b6b5b82f50fc8680a5da37d2834f2129c65c24c5628a80894d565451
ARG FPC_SHA256_X86_64=5adac308a5534b6a76446d8311fc340747cbb7edeaacfe6b651493ff3fe31e83
RUN set -eu; \
    arch="$(apk --print-arch)"; \
    case "$arch" in \
        x86_64) fpc_arch=x86_64-linux; fpc_bin=ppcx64; fpc_sha256="${FPC_SHA256_X86_64}" ;; \
        aarch64) fpc_arch=aarch64-linux; fpc_bin=ppca64; fpc_sha256="${FPC_SHA256_AARCH64}" ;; \
        *) echo "Unsupported arch for FPC: $arch" >&2; exit 1 ;; \
    esac; \
    url="https://sourceforge.net/projects/freepascal/files/Linux/${FPC_VERSION}/fpc-${FPC_VERSION}.${fpc_arch}.tar/download"; \
    tries=0; \
    until wget -q "$url" -O /tmp/fpc.tar \
        && echo "${fpc_sha256}  /tmp/fpc.tar" | sha256sum -c -; do \
        tries=$((tries + 1)); \
        [ "$tries" -ge 6 ] && { echo "FPC ${FPC_VERSION} (${fpc_arch}): $tries tentativas sem bater o SHA-256 fixado" >&2; exit 1; }; \
        rm -f /tmp/fpc.tar; \
    done; \
    mkdir -p /tmp/fpcx && tar -xf /tmp/fpc.tar -C /tmp/fpcx; \
    cd "/tmp/fpcx/fpc-${FPC_VERSION}.${fpc_arch}"; \
    tar -xf "binary.${fpc_arch}.tar" "base.${fpc_arch}.tar.gz"; \
    mkdir -p "/opt/fpc/${FPC_VERSION}"; \
    tar -xzf "base.${fpc_arch}.tar.gz" -C "/opt/fpc/${FPC_VERSION}"; \
    install -m 755 "/opt/fpc/${FPC_VERSION}/lib/fpc/${FPC_VERSION}/${fpc_bin}" /usr/local/bin/fpc; \
    printf -- '-Fu/opt/fpc/%s/lib/fpc/%s/units/%s/rtl\n-Fl/opt/fpc/%s/lib/fpc/%s/units/%s/rtl\n' \
        "$FPC_VERSION" "$FPC_VERSION" "$fpc_arch" "$FPC_VERSION" "$FPC_VERSION" "$fpc_arch" > /etc/fpc.cfg; \
    rm -rf /tmp/fpc.tar /tmp/fpcx

# ---------------------------------------------------------------------------
# Issue #305, Lote D -- as linguagens que vieram de FORA da distribuicao.
#
# Este bloco e o gemeo do que esta no Dockerfile.judge, e a razao de cada
# escolha (por que o zip GENERICO do Scala e nao o de plataforma, por que a
# linha 3.3 LTS, por que o Dart precisa de gcompat, o que a poda do SDK tira
# e por que ela vem com smoke test) esta escrita la, uma vez so.
ARG SCALA_VERSION=3.3.8
ARG SCALA_SHA256=777423024bb2b4c2b33e18f9c28da7cea117b39478b9bb6811baf7b1a1cf22a7
RUN set -eux; \
    wget -q "https://github.com/scala/scala3/releases/download/${SCALA_VERSION}/scala3-${SCALA_VERSION}.zip" -O /tmp/scala.zip; \
    echo "${SCALA_SHA256}  /tmp/scala.zip" | sha256sum -c -; \
    unzip -q /tmp/scala.zip -d /opt; \
    mv "/opt/scala3-${SCALA_VERSION}" /opt/scala3; \
    rm /tmp/scala.zip; \
    printf '#!/bin/sh\nJAVA_HOME=/usr/lib/jvm/java-21-openjdk\nexport JAVA_HOME\nexec /opt/scala3/bin/scalac "$@"\n' > /usr/local/bin/scalac; \
    printf '#!/bin/sh\nJAVA_HOME=/usr/lib/jvm/java-21-openjdk\nexport JAVA_HOME\nexec /opt/scala3/bin/scala "$@"\n' > /usr/local/bin/scala; \
    chmod 755 /usr/local/bin/scalac /usr/local/bin/scala; \
    mkdir -p /tmp/fumaca-scala; \
    printf 'object Fumaca { def main(args: Array[String]): Unit = println("ok") }\n' > /tmp/fumaca-scala/Fumaca.scala; \
    cd /tmp/fumaca-scala; \
    scalac Fumaca.scala; \
    test "$(scala Fumaca)" = "ok"; \
    cd /; \
    rm -rf /tmp/fumaca-scala

ARG GROOVY_VERSION=4.0.33
ARG GROOVY_SHA256=395a69a81d5e9915d360d630663c1d98534c9dec134cb267798e49370855d93d
RUN set -eux; \
    wget -q "https://archive.apache.org/dist/groovy/${GROOVY_VERSION}/distribution/apache-groovy-binary-${GROOVY_VERSION}.zip" -O /tmp/groovy.zip; \
    echo "${GROOVY_SHA256}  /tmp/groovy.zip" | sha256sum -c -; \
    unzip -q /tmp/groovy.zip -d /opt; \
    mv "/opt/groovy-${GROOVY_VERSION}" /opt/groovy; \
    rm /tmp/groovy.zip; \
    printf '#!/bin/sh\nJAVA_HOME=/usr/lib/jvm/java-21-openjdk\nexport JAVA_HOME\nexec /opt/groovy/bin/groovyc "$@"\n' > /usr/local/bin/groovyc; \
    printf '#!/bin/sh\nJAVA_HOME=/usr/lib/jvm/java-21-openjdk\nexport JAVA_HOME\nexec /opt/groovy/bin/groovy "$@"\n' > /usr/local/bin/groovy; \
    chmod 755 /usr/local/bin/groovyc /usr/local/bin/groovy; \
    mkdir -p /tmp/fumaca-groovy; \
    printf 'println "ok"\n' > /tmp/fumaca-groovy/fumaca.groovy; \
    cd /tmp/fumaca-groovy; \
    groovyc fumaca.groovy; \
    test "$(groovy fumaca.groovy)" = "ok"; \
    cd /; \
    rm -rf /tmp/fumaca-groovy

ARG DART_VERSION=3.13.4
ARG DART_SHA256_AARCH64=1d545609bdf9da6fb5e68fbd96a599e2836e44ee64379991bd4493ee764d2fdb
ARG DART_SHA256_X86_64=6487a10df5eab890d746d14a55f4c70bec3c1c0633f51804eb504cbc0fc395bb
RUN set -eux; \
    arch="$(apk --print-arch)"; \
    case "$arch" in \
        aarch64) dart_arch=arm64; dart_sha256="${DART_SHA256_AARCH64}" ;; \
        x86_64)  dart_arch=x64;   dart_sha256="${DART_SHA256_X86_64}" ;; \
        *) echo "Sem SDK do Dart para a arquitetura $arch" >&2; exit 1 ;; \
    esac; \
    wget -q "https://storage.googleapis.com/dart-archive/channels/stable/release/${DART_VERSION}/sdk/dartsdk-linux-${dart_arch}-release.zip" -O /tmp/dart.zip; \
    echo "${dart_sha256}  /tmp/dart.zip" | sha256sum -c -; \
    unzip -q /tmp/dart.zip -d /opt; \
    rm /tmp/dart.zip; \
    rm -rf /opt/dart-sdk/bin/resources/devtools; \
    rm -f /opt/dart-sdk/bin/dartaotruntime_asan \
          /opt/dart-sdk/bin/dartaotruntime_msan \
          /opt/dart-sdk/bin/dartaotruntime_tsan; \
    find /opt/dart-sdk -name '*.sym' -delete; \
    rm -f /opt/dart-sdk/bin/snapshots/analysis_server.dart.snapshot \
          /opt/dart-sdk/bin/snapshots/analysis_server_aot.dart.snapshot \
          /opt/dart-sdk/bin/snapshots/dartdevc.dart.snapshot \
          /opt/dart-sdk/bin/snapshots/dartdevc_aot.dart.snapshot \
          /opt/dart-sdk/bin/snapshots/dart2js_aot.dart.snapshot \
          /opt/dart-sdk/bin/snapshots/dart2wasm_product.snapshot \
          /opt/dart-sdk/bin/snapshots/dart2bytecode.dart.snapshot \
          /opt/dart-sdk/bin/snapshots/kernel_worker_aot.dart.snapshot \
          /opt/dart-sdk/bin/utils/wasm-opt; \
    printf '#!/bin/sh\nexec /opt/dart-sdk/bin/dart "$@"\n' > /usr/local/bin/dart; \
    chmod 755 /usr/local/bin/dart; \
    mkdir -p /tmp/fumaca-dart; \
    printf 'import "dart:io";\nvoid main() { print(int.parse(stdin.readLineSync()!.trim()) + 1); }\n' > /tmp/fumaca-dart/fumaca.dart; \
    cd /tmp/fumaca-dart; \
    dart compile exe fumaca.dart -o fumaca; \
    test "$(echo 7 | ./fumaca)" = "8"; \
    cd /; \
    rm -rf /tmp/fumaca-dart

COPY --from=swipl-builder /opt/swipl /opt/swipl
RUN set -eux; \
    printf '#!/bin/sh\nexec /opt/swipl/bin/swipl "$@"\n' > /usr/local/bin/swipl; \
    chmod 755 /usr/local/bin/swipl; \
    printf 'main :- write(ok), nl.\n' > /tmp/fumaca.pl; \
    test "$(swipl --on-error=status -g main,halt -l /tmp/fumaca.pl < /dev/null)" = "ok"; \
    rm -f /tmp/fumaca.pl

# O PATH dentro do invocador do `gplc` nao e decoracao: ele chama `pl2wam`,
# `wam2ma` e `ma2asm` pelo PATH, e sem isto a compilacao morre com
# "error trying to execute pl2wam". Ver Dockerfile.judge.
COPY --from=gprolog-builder /opt/gprolog /opt/gprolog
RUN set -eux; \
    printf '#!/bin/sh\nPATH="/opt/gprolog/bin:$PATH"\nexport PATH\nexec /opt/gprolog/bin/gplc "$@"\n' > /usr/local/bin/gplc; \
    chmod 755 /usr/local/bin/gplc; \
    mkdir -p /tmp/fumaca-gprolog; \
    printf 'main :- write(ok), nl.\n:- initialization(main).\n' > /tmp/fumaca-gprolog/fumaca.pro; \
    cd /tmp/fumaca-gprolog; \
    gplc --no-top-level -o fumaca fumaca.pro; \
    test "$(./fumaca < /dev/null)" = "ok"; \
    cd /; \
    rm -rf /tmp/fumaca-gprolog

# ---------------------------------------------------------------------------
# Issue #302 -- Scratch e Portugol Studio, que estavam so na imagem do juiz.
#
# Os dois blocos abaixo sao copia fiel dos do Dockerfile.judge, inclusive o
# smoke test de cada um: uma imagem que julga tem de provar que julga na
# propria construcao, e nao na primeira submissao de uma equipe.
#
# `/opt` ja esta em config/autojudge.php -> sandbox_paths, entao isto fica
# visivel dentro do sandbox sem bind novo.

# G-Portugol (issue #296). O compilador vem do estagio acima; aqui entram o
# diretorio, o invocador e a prova de fumaca.
#
# `/opt` ja esta em config/autojudge.php -> sandbox_paths.
#
# O invocador existe pela regra de sempre: `MachineCapabilities` sonda o
# PRIMEIRO TOKEN do comando com `command -v`, e um comando escrito com o
# caminho absoluto nao anunciaria capacidade nenhuma util.
#
# Dependencias de execucao, medidas com `ldd`: libstdc++, libgcc e pcre2 --
# as tres ja estao na imagem. E o `gcc`, que a lista de pacotes ja instala
# para C, porque a SEGUNDA etapa da compilacao desta linguagem e ele.
#
# A prova de fumaca tem QUATRO metades, e nenhuma e decorativa:
#
#   1. `a+b` correto compila e imprime 8 -- o controle positivo.
#   2. erro de sintaxe SAI != 0 (e nao 0, que e o que o `gpt -i` faz). Sem
#      isto a linguagem repetiria a #301: programa que nem analisa viraria
#      WA em vez de CE.
#   3. o CE veio da ANALISE, e nao do gcc. Sem esta metade a mutacao que
#      troca `gpt -t` por `gpt -i` passa VERDE: o `-i` sai com 0, o script
#      segue para o gcc, o gcc falha por nao achar o C que nunca foi
#      escrito, e o codigo de saida final volta a ser != 0 pelo motivo
#      errado. Medido -- a mutacao foi feita e passou, e e por isso que
#      esta linha existe.
#   4. o executavel gerado e da ARQUITETURA DESTA MAQUINA. E a metade que
#      pega o `gpt -o`: com nasm instalado ele sai com 0 e escreve um ELF
#      i386 em aarch64; nesta imagem, que nao tem nasm, ele nem chega la.
#      Medido -- a mutacao para `gpt -o` foi feita, e esta metade a pegou.
COPY --from=gportugol-builder /opt/gportugol /opt/gportugol
COPY resources/judge-runtime/gportugol/compile.sh /tmp/fumaca-gportugol/compile.sh
RUN set -eux; \
    printf '#!/bin/sh\nexec /opt/gportugol/bin/gpt "$@"\n' > /usr/local/bin/gpt; \
    chmod 755 /usr/local/bin/gpt; \
    cd /tmp/fumaca-gportugol; \
    printf 'algoritmo fumaca;\n\nvari\303\241veis\n  a : inteiro;\n  b : inteiro;\nfim-vari\303\241veis\n\nin\303\255cio\n  a := leia();\n  b := leia();\n  imprima(a + b);\nfim\n' > fumaca.gpt; \
    sh compile.sh fumaca.gpt fumaca; \
    test "$(printf '3 5\n' | ./fumaca)" = "8"; \
    printf 'algoritmo quebrado;\n\nin\303\255cio\n  isto nao e g-portugol @@@\nfim\n' > quebrado.gpt; \
    if sh compile.sh quebrado.gpt quebrado > saida.txt 2> erro.txt; then \
        echo "o gpt saiu 0 num erro de sintaxe -- a #301 de novo, em outra linguagem" >&2; exit 1; \
    fi; \
    grep -q 'quebrado.gpt:4' erro.txt; \
    if grep -q 'defeito do compilador' erro.txt; then \
        echo "o CE veio do gcc falhando por falta de C, e nao da analise do gpt (o compile.sh esta usando o interpretador?)" >&2; exit 1; \
    fi; \
    test ! -s saida.txt; \
    test "$(od -An -tx1 -j18 -N2 fumaca | tr -d ' ')" = "$(od -An -tx1 -j18 -N2 /bin/busybox | tr -d ' ')"; \
    cd /; \
    rm -rf /tmp/fumaca-gportugol

# Scratch (issue #268). O invocador existe porque
# `MachineCapabilities::executableOf()` olha o PRIMEIRO TOKEN do comando e o
# sonda com `command -v`: um run_command escrito como `node /opt/...`
# anunciaria a capacidade "node", e nao "scratch".
COPY --from=scratch-run-builder /src/dist/index.js /opt/scratch-run/index.js
RUN printf '#!/bin/sh\nexec node /opt/scratch-run/index.js "$@"\n' > /usr/local/bin/scratch-run \
    && chmod 755 /usr/local/bin/scratch-run \
    && echo 'x' | scratch-run --version

# Portugol Studio (issue #269, parte A).
#
# O layout importa: `Console.getClassPathParaCompilacao()` monta o classpath
# lendo os jars de um diretorio `lib/` AO LADO da aplicacao -- nao e um fat
# jar solto, e achatar isso quebra a execucao.
#
# A execucao precisa de `javac` EM TEMPO DE EXECUCAO: o Portugol Studio
# compila o programa para Java e chama o compilador. O `openjdk21-jdk` da
# lista de pacotes ja cobre isso, e e a razao de ele nao poder virar `-jre`.
COPY --from=portugol-studio-builder /src/console/build/libs /opt/portugol-studio
#
# O DIRETORIO inteiro, e nao as classes nomeadas uma a uma: o `switch` sobre
# `TipoDado` dentro de `ExecutaPortugol.solicitaEntrada()` faz o javac emitir
# uma classe sintetica `ExecutaPortugol$1`, e sem ela o `leia` morre com
# `NoClassDefFoundError` -- um `Error`, que o nucleo nao captura. Ver
# Dockerfile.judge para a medicao.
COPY --from=portugol-studio-builder /verifica/classes/ /opt/portugol-studio/

# Dois invocadores, e nao um, pela mesma regra de roteamento por capacidade:
# comandos escritos como `java -jar ...` anunciariam "java", e nao
# "portugol_studio".
#
# Issue #301 -- nenhum dos dois e o Console, pelo mesmo motivo medido no
# Dockerfile.judge: com `-no-wait` o console sai com 0 e sem uma linha de
# stderr quando o programa MORRE, e erro de execucao virava `WA` mudo.
#
# Esta imagem julga pela fila (docker-compose roda ELA nos servicos `queue` e
# `scheduler`), entao o invocador dela tem de ser o mesmo -- a #351 trocou o
# do worker autonomo e estes dois ficaram para tras, que e a forma da #302
# uma camada mais fundo. Agora e teste:
# tests/Unit/Judge/ToolchainManifestParityTest::test_os_invocadores_sao_os_mesmos_nos_tres_dockerfiles.
#
# O smoke test tem as duas metades, e o programa valido LE DA ENTRADA de
# proposito (so `escreva` nao toca no caminho que quebrava). O
# `rm -rf /tmp/portugol` no fim nao e faxina: o nucleo escreve ali como root,
# e o diretorio deixado na imagem faz toda execucao do usuario sem privilegio
# morrer com `Permission denied`.
RUN set -eux; \
    printf '#!/bin/sh\nexec java -cp "/opt/portugol-studio:$(find /opt/portugol-studio -name \x27*.jar\x27 | tr \x27\\n\x27 \x27:\x27)" ExecutaPortugol "$@"\n' \
        > /usr/local/bin/portugol-studio; \
    printf '#!/bin/sh\nexec java -cp "/opt/portugol-studio:$(find /opt/portugol-studio -name \x27*.jar\x27 | tr \x27\\n\x27 \x27:\x27)" VerificaPortugol "$@"\n' \
        > /usr/local/bin/portugol-studio-check; \
    chmod 755 /usr/local/bin/portugol-studio /usr/local/bin/portugol-studio-check; \
    mkdir -p /tmp/fumaca-portugol; \
    cd /tmp/fumaca-portugol; \
    printf 'programa {\n  funcao inicio() {\n    inteiro a, b\n    leia(a)\n    leia(b)\n    escreva(a + b)\n  }\n}\n' > fumaca.por; \
    printf '3\n5\n' > entrada.txt; \
    portugol-studio-check fumaca.por; \
    test "$(portugol-studio fumaca.por < entrada.txt)" = "8"; \
    printf 'programa {\n  funcao inicio() {\n    inteiro a = 10, b = 0\n    escreva(a / b)\n  }\n}\n' > divzero.por; \
    if portugol-studio divzero.por < /dev/null > saida.txt 2> erro.txt; then \
        echo "ExecutaPortugol saiu 0 num erro de execucao (issue #301)" >&2; exit 1; \
    fi; \
    grep -q 'Erro de execucao' erro.txt; \
    test ! -s saida.txt; \
    cd /; \
    rm -rf /tmp/fumaca-portugol /tmp/portugol

# JPlag (issue #42, similarity analysis) -- pinned to v6.2.0, the last
# release built against JDK 21 (v6.3.0 bumped the minimum to JDK 25; see
# https://github.com/jplag/JPlag/releases/tag/v6.3.0 and .../v6.2.0),
# matching the openjdk21-jdk installed above rather than requiring a JDK
# bump. JPLAG_SHA256 is the sha256 GitHub itself attests for this release
# asset (the `digest` field on
# GET /repos/jplag/JPlag/releases/tags/v6.2.0, verified 2026-09-11) --
# verified here at build time AND again at runtime by
# App\Services\Similarity\JplagSimilarityEngine before every invocation, so
# a corrupted download or a jar swapped in by a derived image/volume mount
# can never execute silently.
ARG JPLAG_VERSION=6.2.0
ARG JPLAG_SHA256=f2d2b98ce57018d074be023583ce5d1801240e67dd37344ef7834e63ba7e521f
RUN mkdir -p /opt/jplag \
    && wget -q "https://github.com/jplag/JPlag/releases/download/v${JPLAG_VERSION}/jplag-${JPLAG_VERSION}-jar-with-dependencies.jar" \
        -O "/opt/jplag/jplag-${JPLAG_VERSION}-jar-with-dependencies.jar" \
    && echo "${JPLAG_SHA256}  /opt/jplag/jplag-${JPLAG_VERSION}-jar-with-dependencies.jar" | sha256sum -c -
# Exported as real container ENV (not just build-time ARGs) so
# config/similarity.php's env() calls pick up these exact values at
# runtime automatically -- the literals in that config file are only a
# fallback for running outside this image; this ENV block is the one place
# that actually needs updating on a version bump.
ENV SIMILARITY_JPLAG_VERSION=${JPLAG_VERSION} \
    SIMILARITY_JPLAG_JAR_SHA256=${JPLAG_SHA256} \
    SIMILARITY_JPLAG_JAR_PATH=/opt/jplag/jplag-${JPLAG_VERSION}-jar-with-dependencies.jar

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        pgsql \
        mysqli \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
        xml \
        sockets

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install PCOV for code coverage
RUN pecl install pcov && docker-php-ext-enable pcov

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create system user for running Composer and Artisan
RUN addgroup -g 1000 -S www && \
    adduser -u 1000 -S www -G www

# Copy PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Copy supervisor configuration
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf

# Copy application files
COPY --chown=www:www . /var/www/html

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Note: Assets should be pre-built before building the Docker image
# Run 'npm install && npm run build' locally before 'docker compose up'

# Set permissions
RUN chown -R www:www /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Create directories for autojudge and logs
RUN mkdir -p /var/www/html/storage/app/judge \
    && mkdir -p /var/www/html/storage/app/problems \
    && mkdir -p /var/www/html/storage/app/runs \
    && mkdir -p /var/log/php \
    && chown -R www:www /var/www/html/storage \
    && chown -R www:www /var/log/php

# Issue #136 -- the container starts as root and the entrypoint drops to
# www (uid 1000) for everything except php-fpm and supervisord, which are
# root masters by design and fork unprivileged children of their own
# (`user = www` in www.conf, `user=www` on supervisord.conf's queue and
# scheduler programs). PHP-FPM still needs to start as root to read its
# config and bind the socket with the ownership www.conf asks for, which
# is why this is an entrypoint and not a `USER` directive.
#
# What this actually fixes: docker-compose.yml runs THIS image for the
# `queue` and `scheduler` services, and the queue is where JudgeRunJob
# calls AutoJudgeService::judge() -- compiling and executing submitted
# contestant code. Before this, that ran as uid 0, contradicting the
# guarantee Dockerfile.judge has made since #86 for the identical code.
# See docker/php/entrypoint.sh.
COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/app-entrypoint

ENTRYPOINT ["/usr/local/bin/app-entrypoint"]

# Expose port 9000 for PHP-FPM
EXPOSE 9000

CMD ["php-fpm"]
