#!/bin/sh
# Issue #136 -- drop to uid 1000 unless the command manages its own drop.
#
# The application image is not only php-fpm. docker-compose.yml runs the
# same image as the `queue` and `scheduler` services, and the queue is
# where App\Jobs\JudgeRunJob -> AutoJudgeService::judge() compiles and
# executes submitted contestant code. Without this entrypoint that
# happened under uid 0, while the standalone judge image has dropped to
# uid 1000 since #86 -- the identical judging code with two different
# privilege postures, decided by whoever wrote the compose file.
#
# So the guarantee belongs to the image, the same way #86 put it in
# Dockerfile.judge: an operator who writes their own compose file, k8s
# manifest or `docker run` line inherits it without having to know about
# it. A `user:` key in docker-compose.yml would have fixed only the
# compose file in this repository.
#
# Why an entrypoint and not `USER www`: php-fpm's design is a root master
# that binds the socket and reads config, forking workers that setuid to
# the pool user (`user = www` in docker/php/www.conf), and nginx works the
# same way. A `USER` directive would break that, and it is the one root
# process here that is conventional and already drops privilege where it
# matters. An entrypoint can tell the two cases apart; `USER` cannot.
#
# Note this deliberately does NOT delegate a cgroup v2 subtree the way
# docker/judge/entrypoint.sh does. That needs a privileged container, and
# the queue service is not one -- on this path AutoJudgeService already
# falls back to the peak-RSS memory measurement from #108. That gap is
# pre-existing and is the same divergence issue #136 flags at the end:
# two judging paths with different postures. Closing it properly means
# deciding whether judging should happen in the queue container at all,
# which is a larger change than making the uid safe.

set -u

APP_UID=1000
APP_GID=1000

log() {
    echo "app-entrypoint: $*" >&2
}

# Already unprivileged: started with an explicit --user, or by a runtime
# that assigns its own uid (OpenShift does this). There is nothing to
# drop, and trying would fail. Behave exactly as the image did before
# this entrypoint existed.
if [ "$(id -u)" != "0" ]; then
    exec "$@"
fi

# The allowlist is closed, not open: anything not named here drops. A new
# service added to the compose file is therefore unprivileged by default,
# which is the failure mode we want -- the bug this fixes existed because
# `queue` was added to an image whose default was root.
#
# Matched on the basename so `php-fpm`, `/usr/local/sbin/php-fpm` and
# `php-fpm -F` (what docker/supervisor/supervisord.conf runs) are all the
# same case.
case "$(basename -- "${1:-}")" in
    php-fpm|supervisord)
        # Both fork unprivileged children by configuration: www.conf sets
        # `user = www`, and supervisord.conf sets `user=www` on the
        # laravel-queue and laravel-scheduler programs. Keeping the master
        # as root is what lets php-fpm bind its socket with the ownership
        # www.conf asks for.
        exec "$@"
        ;;
esac

# Everything else -- `php artisan queue:work`, the scheduler's
# `/bin/sh -c "while true; ..."` loop, an ad-hoc `docker run <image> php
# artisan ...` -- runs as www.
#
# Alpine's busybox ships a /bin/setpriv that only knows --inh-caps and
# --no-new-privs; --reuid comes from util-linux, which the Dockerfiles
# install for exactly this reason. If it is missing the image was built
# wrong, and running submitted code as root is not an acceptable
# fallback, so this is fatal -- the same call the judge entrypoint makes.
if ! command -v setpriv > /dev/null 2>&1; then
    log "FATAL: setpriv not found (is util-linux installed?), refusing to run as root"
    exit 1
fi

# Not a hard failure, but worth saying out loud. docker-compose.yml bind
# mounts the host checkout over /var/www/html, so the image's own
# www:www ownership is replaced by whatever the host has. uid 1000 not
# being able to write storage/ surfaces as Laravel 500s and silently
# dropped jobs rather than as a startup error, and no test in this
# repository would catch it.
#
# In practice an environment where this warns is already broken: php-fpm
# workers have run as www against the same bind mount since before this
# change, writing the same storage/logs, storage/framework/* and
# bootstrap/cache. So this reports a pre-existing misconfiguration; it
# does not introduce a new requirement. Nothing here chowns anything --
# that tree belongs to the operator, and `chown -R` on a bind mount
# rewrites ownership in their checkout.
for dir in /var/www/html/storage /var/www/html/bootstrap/cache; do
    [ -d "$dir" ] || continue
    if ! setpriv --reuid="$APP_UID" --regid="$APP_GID" --init-groups \
        -- test -w "$dir" 2>/dev/null; then
        log "WARNING: $dir is not writable by uid $APP_UID; expect storage failures (chown -R $APP_UID:$APP_GID or chmod -R a+rwX it on the host)"
    fi
done

exec setpriv --reuid="$APP_UID" --regid="$APP_GID" --init-groups --inh-caps=-all -- "$@"
