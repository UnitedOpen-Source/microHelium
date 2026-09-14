<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Issue #142 -- writes one connection's contents to a .sql file that is
 * readable without this application: `mysql < database.sql` for MySQL,
 * `sqlite3 db.sqlite < database.sql` for SQLite. Restoring *into* a running
 * microHelium is out of scope for #142; being able to read the data with
 * standard tools while microHelium is the thing that is broken is the part
 * that removes the risk.
 *
 * Two drivers, deliberately implemented by different means:
 *
 *   mysql   an external mysqldump, because that is the file format every
 *           organiser and every hosting provider already knows how to
 *           restore, and because reimplementing a correct MySQL dump
 *           (character sets, generated columns, triggers, routines) in PHP
 *           would be a worse copy of a battle-tested tool.
 *   sqlite  PHP, over the connection that is already open. SQLite is the
 *           development and test database; requiring the `sqlite3` binary
 *           to be installed would make the backup path untestable and would
 *           add a dependency for the one driver that does not need it. It
 *           also means an in-memory database (the test suite) dumps like
 *           any other, so the tests exercise this class rather than a mock.
 *
 * Whatever the driver, the file ends with the completion marker and the
 * caller checks for it: a dump truncated by a full disk or a killed child
 * process must not be handed over as if it were a backup.
 */
class DatabaseDumper
{
    /**
     * The last line mysqldump writes ("-- Dump completed on ..."), which the
     * SQLite path emits too so that one check covers both.
     */
    public const COMPLETION_MARKER = '-- Dump completed';

    /**
     * @throws BackupFailedException when the dump cannot be produced in full
     */
    public function dump(string $connection, string $target): void
    {
        $driver = config("database.connections.{$connection}.driver");

        if ($driver === null) {
            throw new BackupFailedException("Conexao de banco '{$connection}' nao existe em config/database.php.");
        }

        match ($driver) {
            // 'mariadb' is Laravel's own separate driver name for the same
            // wire protocol and the same dump tool.
            'mysql', 'mariadb' => $this->dumpMysql($connection, $target),
            'sqlite' => $this->dumpSqlite($connection, $target),
            default => throw new BackupFailedException(
                "Backup nao suporta o driver '{$driver}' (conexao '{$connection}'). Suportados: mysql, sqlite."
            ),
        };

        $this->assertComplete($target, $connection);
    }

    private function dumpMysql(string $connection, string $target): void
    {
        $config = config("database.connections.{$connection}");
        $binary = $this->locateBinary((string) config('backup.mysql.binary'));

        $defaultsFile = $this->writeDefaultsFile($config);

        try {
            $command = array_merge(
                // --defaults-extra-file must be the first argument;
                // mysqldump rejects it anywhere else.
                [$binary, "--defaults-extra-file={$defaultsFile}"],
                (array) config('backup.mysql.flags', []),
                ["--result-file={$target}", (string) $config['database']]
            );

            // Array form: no shell, so a password or a database name with
            // shell metacharacters in it cannot turn into another command.
            // stdout is a pipe only because --result-file already takes the
            // dump itself; stderr is what we need for the error message.
            $process = proc_open(
                $command,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );

            if (! is_resource($process)) {
                throw new BackupFailedException("Nao foi possivel executar '{$binary}'.");
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                @unlink($target);

                throw new BackupFailedException(
                    "mysqldump falhou (codigo {$exitCode}): ".$this->summarise($stderr ?: $stdout)
                );
            }
        } finally {
            @unlink($defaultsFile);
        }
    }

    /**
     * Credentials go through a 0600 options file, never on the command line:
     * arguments are visible in `ps` to every user on the contest host, and
     * mysqldump itself warns about --password= for exactly that reason.
     */
    private function writeDefaultsFile(array $config): string
    {
        $defaultsFile = tempnam(sys_get_temp_dir(), 'mhbkp_');

        if ($defaultsFile === false) {
            throw new BackupFailedException('Nao foi possivel criar arquivo temporario para as credenciais do dump.');
        }

        // Before writing, not after: between creation and chmod the file
        // must never be readable by anyone else.
        chmod($defaultsFile, 0600);

        $options = ['host' => $config['host'] ?? null, 'port' => $config['port'] ?? null];

        // An empty unix_socket (the default in config/database.php) is not
        // the same as no socket: mysqldump would try to connect to "" and
        // fail instead of using host/port.
        if (! empty($config['unix_socket'])) {
            $options = ['socket' => $config['unix_socket']];
        }

        $options['user'] = $config['username'] ?? null;
        $options['password'] = $config['password'] ?? null;

        $contents = "[client]\n";
        foreach ($options as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $contents .= $key.'='.$this->quoteOptionValue((string) $value)."\n";
        }

        // Option groups the other client ignores. MariaDB's dump tool
        // verifies the server certificate by default, so against a MySQL
        // container with the self-signed certificate it generates on first
        // boot every dump dies with "self-signed certificate in certificate
        // chain". The application's own PDO connection does not verify that
        // certificate either (config/database.php sets no SSL options), so
        // this matches the trust the app already places in its database
        // rather than lowering it. Oracle's mysqldump reads [client] and
        // [mysqldump] only and never sees this group -- which is why it is
        // a separate group and not a flag.
        $contents .= "\n[mariadb-dump]\nssl-verify-server-cert=0\n";

        file_put_contents($defaultsFile, $contents);

        return $defaultsFile;
    }

    /**
     * MySQL option files take a double-quoted value with backslash escapes;
     * a password containing # or a trailing space is otherwise silently
     * mangled (# starts a comment, trailing whitespace is trimmed).
     */
    private function quoteOptionValue(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private function locateBinary(string $binary): string
    {
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            if (! is_executable($binary)) {
                throw new BackupFailedException(
                    "mysqldump nao encontrado em '{$binary}'. Instale o cliente MySQL/MariaDB no host ou ajuste BACKUP_MYSQLDUMP."
                );
            }

            return $binary;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$binary;

            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new BackupFailedException(
            "'{$binary}' nao encontrado no PATH. Instale o cliente MySQL/MariaDB no host (pacote mariadb-client) ou aponte BACKUP_MYSQLDUMP para o binario."
        );
    }

    /**
     * SQLite's own `.dump` output shape, written from PHP: schema first,
     * then the rows, wrapped in one transaction so a partial restore leaves
     * nothing behind.
     */
    private function dumpSqlite(string $connection, string $target): void
    {
        $pdo = DB::connection($connection)->getPdo();

        $handle = fopen($target, 'w');

        if ($handle === false) {
            throw new BackupFailedException("Nao foi possivel escrever o dump em {$target}.");
        }

        try {
            $database = config("database.connections.{$connection}.database");

            fwrite($handle, "-- microHelium backup (issue #142)\n");
            fwrite($handle, '-- SQLite '.$pdo->getAttribute(PDO::ATTR_SERVER_VERSION).", database {$database}\n");
            fwrite($handle, '-- Generated on '.now()->toDateTimeString()."\n\n");
            fwrite($handle, "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n");

            // Tables before everything else: an index or a trigger cannot be
            // created before the table it is attached to. sqlite_% names are
            // SQLite's internal bookkeeping (autoindexes, sequences), which
            // it recreates itself and refuses to have created by hand.
            $objects = $pdo->query(
                "SELECT type, name, sql FROM sqlite_master
                 WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%'
                 ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name",
                PDO::FETCH_ASSOC
            );

            foreach ($objects as $object) {
                fwrite($handle, $object['sql'].";\n");

                if ($object['type'] === 'table') {
                    $this->writeSqliteRows($pdo, $handle, $object['name']);
                }
            }

            fwrite($handle, "COMMIT;\nPRAGMA foreign_keys=ON;\n");
            fwrite($handle, "\n".self::COMPLETION_MARKER.' on '.now()->toDateTimeString()."\n");
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function writeSqliteRows(PDO $pdo, $handle, string $table): void
    {
        // FETCH_ASSOC explicitly: PDO's default is FETCH_BOTH, which
        // returns every value twice -- once under its column name and once
        // under its position -- so array_keys() on a row would put "0", "1",
        // "2" into the column list and produce INSERT statements the
        // database rejects on restore.
        $rows = $pdo->query('SELECT * FROM '.$this->quoteIdentifier($table), PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $columns = implode(', ', array_map($this->quoteIdentifier(...), array_keys($row)));
            $values = implode(', ', array_map(fn ($value) => $this->quoteValue($pdo, $value), $row));

            fwrite($handle, "INSERT INTO {$this->quoteIdentifier($table)} ({$columns}) VALUES ({$values});\n");
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function quoteValue(PDO $pdo, mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_int($value), is_float($value) => (string) $value,
            // Not a bound parameter and not addslashes(): PDO::quote() is
            // the only escaping that is defined for this driver, and source
            // files submitted by teams are full of quotes and backslashes.
            default => $pdo->quote((string) $value),
        };
    }

    /**
     * The failure this guards against is the quiet one: mysqldump exiting 0
     * after a short write, or a dump aborted halfway leaving a file that
     * looks plausible until the day someone tries to restore it.
     */
    private function assertComplete(string $target, string $connection): void
    {
        $size = is_file($target) ? filesize($target) : 0;

        if ($size === 0) {
            @unlink($target);

            throw new BackupFailedException("O dump da conexao '{$connection}' saiu vazio; backup abortado.");
        }

        $handle = fopen($target, 'r');
        fseek($handle, max(0, $size - 512));
        $tail = (string) fread($handle, 512);
        fclose($handle);

        if (! str_contains($tail, self::COMPLETION_MARKER)) {
            @unlink($target);

            throw new BackupFailedException(
                "O dump da conexao '{$connection}' terminou sem a marca de conclusao (arquivo truncado); backup abortado."
            );
        }
    }

    /**
     * mysqldump's stderr is mostly noise on a modern client (MariaDB prints
     * a deprecation banner on every single call); the operator needs the
     * line that says what went wrong, which is the last one.
     */
    private function summarise(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn ($line) => $line !== ''));

        return $lines === [] ? 'sem saida de erro.' : end($lines);
    }
}
