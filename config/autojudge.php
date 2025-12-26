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
];
