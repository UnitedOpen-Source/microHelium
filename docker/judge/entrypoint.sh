#!/bin/sh
# Issue #86 -- hand the judge user a cgroup v2 subtree, then drop to it.
#
# A per-run `memory.max` is the only memory limit that works for every
# language: `ulimit -v` caps address space, which the JVM and .NET reserve
# several gigabytes of before executing a line of submitted code (see the
# measurements in config/autojudge.php). Setting one needs a writable
# cgroup hierarchy, and Docker mounts /sys/fs/cgroup read-only unless the
# container is privileged (moby#46763), so this runs as root in a
# privileged container and delegates a subtree to uid 1000.
#
# Every step is best-effort. A judge that cannot get cgroups still judges:
# AutoJudgeService falls back to the peak-RSS measurement that has produced
# the MLE verdict since #108. Refusing to start would turn a missing
# kernel feature into a dead contest, so nothing here is fatal except
# being unable to drop privileges.

set -u

CGROUP_MOUNT=/sys/fs/cgroup
DELEGATED="$CGROUP_MOUNT/judge"

# The judge process itself has to live inside the delegated subtree, not
# above it: cgroup v2 only lets a process be migrated when the caller can
# write the cgroup.procs of the COMMON ANCESTOR of source and destination.
# From the container's cgroup root that ancestor is the root itself, which
# stays root-owned, and every migration would fail with EPERM.
WORKER_CGROUP="$DELEGATED/main"

# And it cannot live in $DELEGATED directly either: cgroup v2's "no
# internal processes" rule forbids a cgroup from holding processes while
# its subtree_control distributes a controller to children.
INIT_CGROUP="$CGROUP_MOUNT/init"

JUDGE_UID=1000
JUDGE_GID=1000

log() {
    echo "judge-entrypoint: $*" >&2
}

# Delegates $DELEGATED to uid 1000. Returns non-zero, having changed
# nothing the judge depends on, when this kernel or this container cannot
# support it.
delegate_cgroup_subtree() {
    if [ ! -f "$CGROUP_MOUNT/cgroup.controllers" ]; then
        log "no cgroup v2 hierarchy at $CGROUP_MOUNT; memory limits will use peak RSS only"
        return 1
    fi

    if ! grep -qw memory "$CGROUP_MOUNT/cgroup.controllers"; then
        log "the memory controller is not available here; memory limits will use peak RSS only"
        return 1
    fi

    # The container's own processes must leave the cgroup root before a
    # controller can be enabled for its children -- the "no internal
    # processes" rule again. They are moved to a sibling leaf, not into
    # the judge's subtree, so nothing the judge can write to holds them.
    if ! mkdir -p "$INIT_CGROUP" 2>/dev/null; then
        log "cannot create $INIT_CGROUP (is the container privileged?); memory limits will use peak RSS only"
        return 1
    fi

    # A pid can exit between the read and the write -- including the
    # subshell that produced the list -- so a failure here is expected and
    # only matters if it leaves the root non-empty, which the next step
    # detects.
    for pid in $(cat "$CGROUP_MOUNT/cgroup.procs" 2>/dev/null); do
        echo "$pid" > "$INIT_CGROUP/cgroup.procs" 2>/dev/null || true
    done

    if ! echo "+memory" > "$CGROUP_MOUNT/cgroup.subtree_control" 2>/dev/null; then
        log "cannot enable the memory controller on $CGROUP_MOUNT; memory limits will use peak RSS only"
        return 1
    fi

    if ! mkdir -p "$DELEGATED" "$WORKER_CGROUP" 2>/dev/null; then
        log "cannot create $DELEGATED; memory limits will use peak RSS only"
        return 1
    fi

    if ! echo "+memory" > "$DELEGATED/cgroup.subtree_control" 2>/dev/null; then
        log "cannot enable the memory controller on $DELEGATED; memory limits will use peak RSS only"
        return 1
    fi

    # Exactly the four files the kernel's delegation interface names, and
    # not one more. Documentation/admin-guide/cgroup-v2.rst is explicit
    # that the delegatee must NOT be given the parent's resource control
    # files: owning $DELEGATED/memory.max would let submitted code raise
    # the ceiling its own per-run cgroup is capped under.
    chown "$JUDGE_UID:$JUDGE_GID" \
        "$DELEGATED" \
        "$DELEGATED/cgroup.procs" \
        "$DELEGATED/cgroup.threads" \
        "$DELEGATED/cgroup.subtree_control" 2>/dev/null || {
        log "cannot chown $DELEGATED to $JUDGE_UID; memory limits will use peak RSS only"
        return 1
    }

    # Move this process -- and so everything it execs -- into the subtree.
    if ! echo "$$" > "$WORKER_CGROUP/cgroup.procs" 2>/dev/null; then
        log "cannot join $WORKER_CGROUP; memory limits will use peak RSS only"
        return 1
    fi

    log "delegated $DELEGATED to uid $JUDGE_UID"
    return 0
}

# Started with an explicit --user, or otherwise not root: there is nothing
# to delegate and nothing to drop. The judge runs exactly as it did before
# this entrypoint existed.
if [ "$(id -u)" != "0" ]; then
    exec "$@"
fi

delegate_cgroup_subtree || true

# Submitted code must never run as root, so unlike everything above this
# is fatal. setpriv comes from util-linux, which Dockerfile.judge installs.
if ! command -v setpriv > /dev/null 2>&1; then
    log "FATAL: setpriv not found, refusing to run the judge as root"
    exit 1
fi

exec setpriv --reuid="$JUDGE_UID" --regid="$JUDGE_GID" --init-groups --inh-caps=-all -- "$@"
