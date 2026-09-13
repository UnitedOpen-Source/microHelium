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
    | Languages where the memory limit is enforced with `ulimit -v`
    |--------------------------------------------------------------------------
    |
    | Issue #86. Until now resident memory was capped only by the
    | per-language {memory} placeholder in run_command -- which exists for
    | Java and Kotlin (-Xmx) and for nothing else. Measured in the judge
    | image, a C submission allocating in a loop reached 4 GB and kept
    | going; under `ulimit -v 262144` the same program fails its malloc at
    | 240 MB, and the Python equivalent raises MemoryError.
    |
    | It is a list rather than a blanket setting because `ulimit -v` caps
    | ADDRESS SPACE, not resident memory, and runtimes that reserve large
    | virtual ranges at startup die under it no matter how little they
    | actually touch. Measured in the same image at a 256 MB cap:
    |
    |     C, C++, Pascal, Rust, Python, Ruby, PHP   run normally
    |     Java / Kotlin    "Error occurred during initialization of VM"
    |     Go               "failed to reserve page summary memory"
    |     Node / TypeScript  silent failure below ~1 GB of address space
    |
    | So the JVM languages keep -Xmx, and Go, Node, TypeScript and C# keep
    | today's behaviour until their own runtime flags are wired up or
    | cgroups land -- see #86, which stays open for the unified answer.
    |
    | An extension missing from this list is never worse off than before:
    | it simply gets no rlimit.
    |
    */
    'memory_rlimit_languages' => array_filter(explode(',', (string) env(
        'AUTOJUDGE_MEMORY_RLIMIT_LANGUAGES',
        'c_gcc13,c_clang17,c99_gcc,cpp_gpp13,cpp14_gpp,cpp17_gpp,cpp_clang,'
        .'pas_fpc,pas_gpc,rs,py3,py2,pypy3,php,rb,perl,lua'
    ))),
];
