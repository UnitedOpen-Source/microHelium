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

# Issue #305 -- os invocadores de Clojure, Racket e Tcl, iguais aos do
# Dockerfile.judge. Ver o comentario la para por que eles tem nome proprio
# em vez de serem um `java -cp ...` escrito no catalogo.
COPY --chmod=755 docker/judge/bin/ /usr/local/bin/

# TypeScript (npx tsc, used by the "TypeScript (Node 24)" language)
ARG TYPESCRIPT_VERSION=7.0.2
RUN npm install -g "typescript@${TYPESCRIPT_VERSION}"

# Kotlin has no Alpine package; JetBrains only ships it as a GitHub release
# zip. This is the same install method used by every other Kotlin judge
# container (it's pure JVM + a launcher script, so it's arch-independent
# given the JDK above).
ARG KOTLIN_VERSION=2.4.20
RUN wget -q "https://github.com/JetBrains/kotlin/releases/download/v${KOTLIN_VERSION}/kotlin-compiler-${KOTLIN_VERSION}.zip" -O /tmp/kotlin.zip \
    && unzip -q /tmp/kotlin.zip -d /opt \
    && rm /tmp/kotlin.zip \
    && ln -s /opt/kotlinc/bin/kotlinc /usr/local/bin/kotlinc \
    && ln -s /opt/kotlinc/bin/kotlin /usr/local/bin/kotlin

# Free Pascal has no Alpine package either. Its official releases ship a
# pre-built compiler binary per architecture (no compilation needed); we
# only need the compiler + RTL units, not the full units-* extras or docs.
# The SourceForge download occasionally serves a truncated file under
# automated tools, hence the retry loop with byte-count verification.
ARG FPC_VERSION=3.2.2
RUN set -eu; \
    arch="$(apk --print-arch)"; \
    case "$arch" in \
        x86_64) fpc_arch=x86_64-linux; fpc_bin=ppcx64 ;; \
        aarch64) fpc_arch=aarch64-linux; fpc_bin=ppca64 ;; \
        *) echo "Unsupported arch for FPC: $arch" >&2; exit 1 ;; \
    esac; \
    url="https://sourceforge.net/projects/freepascal/files/Linux/${FPC_VERSION}/fpc-${FPC_VERSION}.${fpc_arch}.tar/download"; \
    tries=0; \
    until wget -q -c "$url" -O /tmp/fpc.tar && tar -tf /tmp/fpc.tar >/dev/null 2>&1; do \
        tries=$((tries + 1)); \
        [ "$tries" -ge 6 ] && { echo "Failed to download a valid FPC tarball after $tries attempts" >&2; exit 1; }; \
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
