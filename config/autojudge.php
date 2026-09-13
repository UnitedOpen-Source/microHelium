<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Auto-Judge Configuration
    |--------------------------------------------------------------------------
    */

    'enabled' => env('AUTOJUDGE_ENABLED', true),



    /*
    |--------------------------------------------------------------------------
    | Default Time Limit
    |--------------------------------------------------------------------------
    |
    | Default time limit in seconds for program execution.
    |
    */
    'time_limit' => env('AUTOJUDGE_TIME_LIMIT', 10),

    /*
    |--------------------------------------------------------------------------
    | Default Memory Limit
    |--------------------------------------------------------------------------
    |
    | Default memory limit in megabytes.
    |
    */
    'memory_limit' => env('AUTOJUDGE_MEMORY_LIMIT', 512),

    /*
    |--------------------------------------------------------------------------
    | Max File Size
    |--------------------------------------------------------------------------
    |
    | Maximum submission file size in kilobytes.
    |
    */
    'max_file_size' => env('AUTOJUDGE_MAX_FILE_SIZE', 100),

    /*
    |--------------------------------------------------------------------------
    | Output Limit
    |--------------------------------------------------------------------------
    |
    | Maximum output size in kilobytes.
    |
    */
    'output_limit' => env('AUTOJUDGE_OUTPUT_LIMIT', 1024),

    /*
    |--------------------------------------------------------------------------
    | Compilation Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time for compilation in seconds.
    |
    */
    'compile_timeout' => env('AUTOJUDGE_COMPILE_TIMEOUT', 30),



    /*
    |--------------------------------------------------------------------------
    | Bwrap Path
    |--------------------------------------------------------------------------
    |
    | Path to the bwrap binary for sandbox isolation.
    |
    */
    'bwrap_path' => env('AUTOJUDGE_BWRAP_PATH', '/usr/bin/bwrap'),

    /*
    |--------------------------------------------------------------------------
    | Use Bwrap
    |--------------------------------------------------------------------------
    |
    | Use bwrap for sandbox isolation. Disabling it lets submitted code run
    | unconfined on the host and must never be done in a real deployment;
    | it exists so the non-judging parts of the test suite can run on a
    | machine without bubblewrap (see phpunit.xml).
    |
    */
    'use_bwrap' => env('AUTOJUDGE_USE_BWRAP', true),

    /*
    |--------------------------------------------------------------------------
    | Sandbox System Paths
    |--------------------------------------------------------------------------
    |
    | The read-only allowlist mounted inside the judge sandbox: the language
    | toolchains and the shared libraries they need, and nothing else.
    |
    | This is an allowlist on purpose. Binding "/" read-only would confine
    | writes but leave *reads* wide open, which
    | docs/specs/49-judge-isolation.md explicitly rules out ("sem acesso a
    | .env, banco, Redis, Docker socket ou diretórios de outras tentativas",
    | "não ler arquivo sentinela fora da tentativa, [...] não acessar
    | arquivo de outro run"). The application root (/var/www/html) is the
    | notable omission: that is where .env, the source tree, every other
    | run's directory and every problem's hidden test data live. Paths that
    | don't exist are skipped, so one list covers all three images.
    |
    | Anything outside this list that a specific judging step legitimately
    | needs -- the test case input file, a problem's compile/run script, the
    | {judge_runtime} helpers -- is bound individually, per invocation, by
    | AutoJudgeService.
    |
    */
    'sandbox_paths' => array_filter(explode(',', (string) env(
        'AUTOJUDGE_SANDBOX_PATHS',
        '/usr,/bin,/sbin,/lib,/lib64,/etc,/opt,/go'
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sandbox File Size Limits (KB)
    |--------------------------------------------------------------------------
    |
    | `ulimit -f` applied inside the sandbox, in 1024-byte increments (bash's
    | unit for -f), so neither a build nor a running program can fill the
    | disk.
    |
    | Compilation gets the larger budget: a statically linked binary, a
    | kotlinc -include-runtime jar or a dotnet build output is legitimately
    | several MB.
    |
    | There is deliberately no `ulimit -v` anywhere: the JVM, Go and
    | Rust runtimes reserve large virtual address ranges at startup, so an
    | address-space cap fails them regardless of how much memory they
    | actually touch. Resident memory stays with the per-language {memory}
    | flag (Java's -Xmx and friends).
    |
    */
    'compile_max_file_kb' => (int) env('AUTOJUDGE_COMPILE_MAX_FILE_KB', 262144),
    'run_max_file_kb' => (int) env('AUTOJUDGE_RUN_MAX_FILE_KB', 32768),

    /*
    |--------------------------------------------------------------------------
    | Sandbox Process Limit
    |--------------------------------------------------------------------------
    |
    | `ulimit -u` applied to a submission's execution inside the sandbox, to
    | cap fork bombs. Generous enough for a JVM's thread pool. Not applied to
    | compilation, where build tools legitimately fan out across cores.
    |
    */
    'run_max_processes' => (int) env('AUTOJUDGE_RUN_MAX_PROCESSES', 256),

    /*
    |--------------------------------------------------------------------------
    | Address-space grace per language (MB)
    |--------------------------------------------------------------------------
    |
    | Issue #86. Where `ulimit -v` is applied it is `memory_limit + grace`,
    | and it is a CRASH BARRIER rather than the measurement: the verdict
    | comes from peak RSS (see rss_time_path below). That split is DMOJ's
    | design.
    |
    | DMOJ's shipped address_grace table was the starting point and holds
    | for Go (768) and Node (1024) -- their Node figure is the same 1 GB we
    | arrived at independently. It does NOT hold for the JVM and .NET on
    | this image, which need several times more; those are measured below
    | and given no barrier at all.
    |
    | The grace exists because `ulimit -v` caps ADDRESS SPACE, and the JVM,
    | Go and V8 reserve large virtual ranges at startup: without headroom
    | they refuse to boot no matter how little memory they actually touch.
    | Our own measurement said V8 fails silently below roughly 1 GB of
    | address space; DMOJ ships 1024 MB for Node. Same number, found
    | independently.
    |
    | A language not listed here gets `default`.
    |
    */
    'memory_grace_mb' => [
        'default' => (int) env('AUTOJUDGE_MEMORY_GRACE_MB', 64),

        // Measured here, at a 256 MB problem limit. DMOJ's numbers hold for
        // Go and Node; the JVM and .NET on this image need far more than
        // DMOJ ships, so they are handled differently below.
        'go' => 768,
        'js_node24' => 1024,
        'js_node22' => 1024,
        'js_node20' => 1024,
        'ts' => 1024,
        'py3' => 128,
        'py2' => 128,
        'pypy3' => 128,
        'rb' => 64,

        // null = no address-space barrier at all, and that is deliberate.
        // Measured on this image, the smallest `ulimit -v` each of these
        // will even boot under, for a 256 MB problem limit:
        //
        //     java     2048 MB   "Could not allocate compressed class
        //                         space: 1073741824 bytes" below that
        //     kotlin   4096 MB   (java -jar with the bundled runtime)
        //     C#       3072 MB   "GC heap initialization failed with
        //                         error 0x8007000E" below that
        //
        // A barrier seven to fifteen times the limit bounds nothing worth
        // bounding. These keep their own runtime caps instead -- -Xmx and
        // DOTNET_GCHeapHardLimit, wired up in #104 -- and the MLE verdict
        // comes from measured peak RSS either way, which is the whole
        // point of measuring rather than inferring.
        'java21' => null,
        'java17' => null,
        'kt' => null,
        'scala' => null,
        'groovy' => null,
        'clj' => null,
        'cs_dotnet' => null,
        'cs_mono' => null,
        'fs_dotnet' => null,
        'vb' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Peak-memory measurement
    |--------------------------------------------------------------------------
    |
    | Issue #86 -- where the MLE verdict actually comes from. The run is
    | wrapped in `time -f`, whose %M is the peak resident set size in KB,
    | and a run whose peak exceeds the problem's memory limit is MLE
    | regardless of how it died.
    |
    | This is how DMOJ produces MLE with no cgroups and no privileges at
    | all. cgroup v2's memory.max would be a harder cap, but it costs a
    | privileged judge container (the isolate manual: "If you still want to
    | use containers, you are on your own and you probably have to make
    | them privileged") and it still does not guarantee an OOM kill -- an
    | allocation that simply returns NULL looks like any other crash there
    | too. Tracked in #86.
    |
    | If the binary is absent the run is judged exactly as before, without
    | an MLE verdict: a missing measurement must not break judging.
    |
    */
    'rss_time_path' => env('AUTOJUDGE_TIME_PATH', '/usr/bin/time'),
];
