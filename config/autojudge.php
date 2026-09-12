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
    | Jail Path
    |--------------------------------------------------------------------------
    |
    | Path to the chroot jail for secure code execution.
    | Set to null to run without jailing (not recommended for production).
    |
    */
    'jail_path' => env('AUTOJUDGE_JAIL_PATH', '/bocajail'),

    /*
    |--------------------------------------------------------------------------
    | Safe Exec Path
    |--------------------------------------------------------------------------
    |
    | Path to the safeexec binary for resource-limited execution.
    |
    */
    'safeexec_path' => env('AUTOJUDGE_SAFEEXEC_PATH', '/usr/bin/safeexec'),

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
    | Judge User
    |--------------------------------------------------------------------------
    |
    | System user to run submissions as for security.
    |
    */
    'judge_user' => env('AUTOJUDGE_USER', 'nobody'),

    /*
    |--------------------------------------------------------------------------
    | Judge Group
    |--------------------------------------------------------------------------
    |
    | System group to run submissions as for security.
    |
    */
    'judge_group' => env('AUTOJUDGE_GROUP', 'nogroup'),

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
    | unit for -f). Compilation gets the larger budget because a statically
    | linked binary, a kotlinc -include-runtime jar or a dotnet publish
    | output is legitimately several MB.
    |
    | Note there is deliberately no `ulimit -v`: the JVM, Go and Rust
    | runtimes reserve large virtual address ranges at startup, so an
    | address-space cap fails them regardless of how much memory they
    | actually touch. Resident memory stays with safeexec (-m/-d) and the
    | per-language {memory} flag.
    |
    */
    'compile_max_file_kb' => (int) env('AUTOJUDGE_COMPILE_MAX_FILE_KB', 262144),
    'run_max_file_kb' => (int) env('AUTOJUDGE_RUN_MAX_FILE_KB', 32768),
];
