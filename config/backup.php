<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup Configuration (issue #142)
    |--------------------------------------------------------------------------
    |
    | `backup:create` writes one numbered ZIP per contest+site holding a SQL
    | dump of the database plus that contest's files. Everything here is
    | about producing an archive an organiser can open with ordinary tools
    | (unzip, mysql <, sqlite3) while the system it came from is broken --
    | that is the whole point of taking it.
    |
    */

    /*
    | Where the archives land, relative to storage/app -- the same root
    | Backup::getFilePath() resolves against, so the model keeps working.
    */
    'directory' => env('BACKUP_DIRECTORY', 'backups'),

    'mysql' => [
        /*
        | Looked up in PATH when it has no slash. Named here rather than
        | hardcoded because the binary is genuinely absent or renamed in
        | some images: Alpine ships MariaDB's client, where the real
        | program is `mariadb-dump` and `mysqldump` is a deprecation
        | wrapper around it.
        */
        'binary' => env('BACKUP_MYSQLDUMP', 'mysqldump'),

        /*
        | --single-transaction: the backup is taken *during* a contest, on a
        |   live database. Without it mysqldump locks every table while it
        |   reads them, so pressing the backup button would stall
        |   submissions. InnoDB gives a consistent snapshot instead.
        | --quick: stream rows instead of buffering a whole table in the
        |   client's memory.
        | --no-tablespaces: dumping tablespace info needs the PROCESS
        |   privilege, which a per-application database user has no reason
        |   to hold. Without this flag MySQL 8 emits an error for it on
        |   every dump.
        | --routines/--triggers: dump what the schema actually contains, so
        |   restoring the file elsewhere reproduces the database rather than
        |   the tables only.
        */
        'flags' => [
            '--single-transaction',
            '--quick',
            '--default-character-set=utf8mb4',
            '--routines',
            '--triggers',
            '--no-tablespaces',
        ],
    ],

    /*
    | storage/app subtrees whose first path segment is the contest id. Each
    | one holds files that database rows point at and that cannot be
    | reconstructed from the dump alone:
    |
    |   runs/     submission sources (Run::source_file) -- the evidence
    |             behind every verdict, and what a rejudge re-reads
    |   prints/   uploaded print requests (Task)
    |   problems/ extracted problem packages, including test data
    |             (Problem::getPackagePath())
    |
    | Deliberately excludes storage/app/temp (scratch space for an import in
    | progress), storage/app/judge (the judge's working directory, rebuilt
    | per run) and storage/app/public (generated, served assets).
    */
    'contest_directories' => ['runs', 'prints', 'problems'],

];
