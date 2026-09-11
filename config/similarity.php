<?php

return [

    /*
    |--------------------------------------------------------------------------
    | JPlag engine
    |--------------------------------------------------------------------------
    |
    | Pinned to v6.2.0 -- the last JPlag release built against JDK 21 before
    | v6.3.0 bumped the minimum to JDK 25 (see release notes at
    | https://github.com/jplag/JPlag/releases/tag/v6.3.0 and v6.2.0). The
    | main Dockerfile installs openjdk21-jdk for AutoJudgeService's Java
    | submissions, so v6.2.0 is the newest release that runs on the JVM
    | already present in this image without an unrelated JDK bump.
    |
    | jar_sha256 is the sha256 GitHub itself attests for the release asset
    | (`digest` field on
    | GET /repos/jplag/JPlag/releases/tags/v6.2.0, verified 2026-09-11).
    | JplagSimilarityEngine re-checks the jar on disk against this checksum
    | before every run, so a corrupted download or a jar swapped in by a
    | derived image/volume mount can never be executed silently.
    |
    | The main Dockerfile sets SIMILARITY_JPLAG_VERSION/_JAR_SHA256/_JAR_PATH
    | as real container ENV (not just build ARGs) from the exact same values
    | it downloads and verifies at build time -- that ENV block is the
    | single source of truth actually used in the running container. The
    | literals below are only a fallback for running this app outside that
    | image (e.g. bare `php artisan` on a host with a manually placed jar);
    | keep them in sync with the Dockerfile if you ever do rely on them.
    |
    */
    'jplag' => [
        'version' => env('SIMILARITY_JPLAG_VERSION', '6.2.0'),
        'jar_path' => env('SIMILARITY_JPLAG_JAR_PATH', '/opt/jplag/jplag-6.2.0-jar-with-dependencies.jar'),
        'jar_sha256' => env(
            'SIMILARITY_JPLAG_JAR_SHA256',
            'f2d2b98ce57018d074be023583ce5d1801240e67dd37344ef7834e63ba7e521f'
        ),
        'java_binary' => env('SIMILARITY_JAVA_BINARY', 'java'),
        // null keeps JPlag's own default (9 tokens as of 6.2.0); not
        // exposed to the admin UI, this is an engine-tuning knob only.
        'minimum_token_match' => env('SIMILARITY_MIN_TOKEN_MATCH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caps and concurrency
    |--------------------------------------------------------------------------
    |
    | "Perguntas para decisão" in docs/specs/42-similarity.md explicitly
    | defers these exact numbers to the team; the values below are the
    | concrete defaults this implementation ships with, chosen to keep a
    | single job's JPlag run (which is O(teams^2) comparisons) bounded on
    | the same container that also runs JudgeRunJob.
    |
    */
    'max_teams_per_job' => (int) env('SIMILARITY_MAX_TEAMS', 60),
    'max_pairs_per_report' => (int) env('SIMILARITY_MAX_PAIRS', 300),
    'max_concurrent_per_contest' => (int) env('SIMILARITY_MAX_CONCURRENT_PER_CONTEST', 2),

    // How long a POST /checks request waits for the per-contest creation
    // lock (see SimilarityController::store()) before giving up with a
    // 409. Overridden to a much smaller value in tests exercising lock
    // contention, so that scenario doesn't cost real wall-clock seconds.
    'lock_wait_seconds' => (int) env('SIMILARITY_LOCK_WAIT_SECONDS', 5),

    /*
    |--------------------------------------------------------------------------
    | Process limits
    |--------------------------------------------------------------------------
    */
    'timeout_seconds' => (int) env('SIMILARITY_TIMEOUT_SECONDS', 240),
    'memory_limit_mb' => (int) env('SIMILARITY_MEMORY_LIMIT_MB', 1024),

    /*
    |--------------------------------------------------------------------------
    | Optional shared base code
    |--------------------------------------------------------------------------
    |
    | "código base comum deve poder ser excluído quando configurado pelo
    | administrador no backend" -- this is a server operator setting (an
    | absolute path on the judge/queue container), not a per-request field
    | in the API contract or the Similarity.vue form. Left null by default;
    | when set, it must point to a directory containing the shared
    | boilerplate/template files to exclude from every comparison.
    |
    */
    'base_code_path' => env('SIMILARITY_BASE_CODE_PATH'),
];
