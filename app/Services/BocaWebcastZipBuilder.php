<?php

namespace App\Services;

use App\Models\Contest;
use App\Models\Run;
use Helium\User;
use RuntimeException;
use ZipArchive;

/**
 * Builds a BOCA-format webcast ZIP for one contest: `contest`, `runs`,
 * `version`, `time`, `icpc`, all field-separated with FS (byte 0x1C), per
 * docs/specs/44-webcast.md's "Formato BOCA: evidencia e limite conhecido".
 *
 * The spec's "Gate de integracao" recorded that the consumer repository
 * linked from the issue 404'd, so the layout was reconstructed from a
 * textual breakdown of BOCA's producer and marked UNVERIFIED. The consumer
 * has since been located -- it moved to wuerges/maratona-animeitor-rust --
 * and this class is now written against its actual parser rather than
 * against an interpretation of the producer:
 *
 *   server/service/src/webcast.rs   reads `time`, `contest`, `runs`
 *   server/service/src/dataio.rs    ContestFile/RunTuple/Team::from_string
 *   server/data/src/lib.rs          Letter::from_str, ALPHABET
 *
 * Four things that parser requires were not what this class emitted, and
 * each of them made the import fail outright rather than degrade:
 *
 *   1. `contest` line 1 is the contest name ALONE. The timing parameters
 *      are line 2 and there are exactly FOUR of them.
 *   2. Those four are maximum_time, current_time, score_freeze_time,
 *      penalty -- current_time was missing entirely.
 *   3. A run's problem field parses as `Letter`, which rejects anything
 *      outside A-Z. A numeric problem id fails, and one bad line rejects
 *      the whole runs file.
 *   4. `time` is parsed with a bare i64 parse and no trim, so a trailing
 *      newline fails.
 *
 * Encoding and the FS separator were already right.
 *
 * Encoding: UTF-8 throughout (accents preserved, not transliterated) --
 * the spec asks to preserve accents "no encoding acordado" without naming
 * one; UTF-8 is this implementation's documented choice.
 */
class BocaWebcastZipBuilder
{
    /** Field separator BOCA's webcast producer uses: byte 0x1C (ASCII FS). */
    public const FS = "\x1C";

    public function build(Contest $contest): string
    {
        $teams = $this->teams($contest);
        $runs = $this->runs($contest, $teams);

        $tmpPath = tempnam(sys_get_temp_dir(), 'webcast_');
        if ($tmpPath === false) {
            throw new RuntimeException('Nao foi possivel criar arquivo temporario para o export.');
        }
        // ZipArchive::open() refuses to create a file at a path that
        // already exists with content unless OVERWRITE is passed on newer
        // libzip, but tempnam() already created an empty file -- delete it
        // first so ZipArchive creates it fresh.
        @unlink($tmpPath);
        $zipPath = $tmpPath.'.zip';

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Nao foi possivel criar o arquivo ZIP do webcast.');
        }

        $zip->addFromString('version', $this->versionFile());
        $zip->addFromString('contest', $this->contestFile($contest, $teams));
        $zip->addFromString('runs', $this->runsFile($runs));
        $zip->addFromString('time', $this->timeFile($contest));
        $zip->addFromString('icpc', $this->icpcFile());
        $zip->close();

        return $zipPath;
    }

    /**
     * Spec: "version contem 1.0 com newline."
     */
    private function versionFile(): string
    {
        return "1.0\n";
    }

    /**
     * Spec: "icpc esta vazio nesse producer."
     */
    private function icpcFile(): string
    {
        return '';
    }

    /**
     * Elapsed contest time in minutes, capped at the duration once the
     * contest has ended.
     *
     * No trailing newline, and that is not a style choice: webcast.rs does
     *
     *     let time_data: i64 = read_from_zip(&mut zip, "time")?.parse()?;
     *
     * with no trim, and Rust's i64 parse rejects "42\n".
     */
    private function timeFile(Contest $contest): string
    {
        // Contest::getContestTime() (fixed in #76 -- previously returned a
        // negative signed diff on this app's pinned Carbon 3) now correctly
        // returns positive elapsed seconds, or 0 before the contest starts.
        return (string) $this->elapsedMinutes($contest);
    }

    /**
     * The layout ContestFile::from_string() in the consumer's dataio.rs
     * actually reads:
     *
     *   line 1  contest name, alone, no separators
     *   line 2  maximum_time FS current_time FS score_freeze_time FS penalty
     *   line 3  number_teams FS number_problems
     *   then    exactly number_teams lines of  login FS institution FS name
     *
     * It stops after the team lines, so anything further is ignored. The
     * per-problem detail lines this class used to append existed only so a
     * run could name its problem by id; runs now use letters (see
     * problemLetter()), so they are gone rather than left as unread
     * speculation.
     *
     * Units are minutes throughout. score_freeze_time is measured from the
     * start, while this app's Contest::freeze_time is "minutes before the
     * end", hence the subtraction.
     */
    private function contestFile(Contest $contest, array $teams): string
    {
        $fs = self::FS;
        $problemCount = $contest->problems()->count();

        $lines = [];
        $lines[] = $this->sanitizeField($contest->name);

        $lines[] = implode($fs, [
            (string) $contest->duration,
            (string) $this->elapsedMinutes($contest),
            // $contest->freeze_time is an accessor returning the instant
            // the board freezes; the stored column is the minutes-before-end
            // this needs.
            (string) max(0, (int) $contest->duration - (int) ($contest->getAttributes()['freeze_time'] ?? 0)),
            (string) $contest->penalty,
        ]);

        $lines[] = implode($fs, [(string) count($teams), (string) $problemCount]);

        foreach ($teams as $team) {
            $lines[] = implode($fs, [
                (string) $team['id'],
                $this->sanitizeField($team['institution']),
                $this->sanitizeField($team['name']),
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Elapsed contest time in minutes, never past the contest's duration.
     * Shared by the `time` file and the contest file's current_time.
     */
    private function elapsedMinutes(Contest $contest): int
    {
        // Contest::getContestTime() (fixed in #76 -- previously returned a
        // negative signed diff on this app's pinned Carbon 3) returns
        // positive elapsed seconds, or 0 before the contest starts.
        return (int) min(floor($contest->getContestTime() / 60), $contest->duration);
    }

    /**
     * Spec: "runs tem ID, tempo convertido para minutos, ID da equipe,
     * problema e resultado; codigos observados: Y aceito, ? pendente, X
     * para erros nao penalizados especificos, N para demais respostas.
     * Fonte consulta resultado completo, sem corte de freeze."
     *
     * "Sem corte de freeze" is honored literally: every run is included
     * regardless of the contest's freeze_time, using the live, unfrozen
     * result -- this export is only ever reachable by an admin session or
     * a scoped webcast credential, never by the public scoreboard.
     *
     * The problem field is a LETTER. RunTuple::from_string parses it as
     * `Letter`, whose FromStr rejects any character outside A-Z, and
     * read_runs() maps over every line with `?` -- so a single numeric id
     * does not degrade one run, it rejects the entire runs file.
     */
    private function runsFile(array $runs): string
    {
        $fs = self::FS;
        $lines = [];

        foreach ($runs as $run) {
            $lines[] = implode($fs, [
                (string) $run['id'],
                (string) $run['minutes'],
                (string) $run['team_id'],
                $run['problem_letter'],
                $run['result'],
            ]);
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /**
     * @return list<array{id:int,name:string,institution:string}>
     */
    private function teams(Contest $contest): array
    {
        // Every team that ever belonged to this contest is included
        // regardless of its current is_enabled state -- a team disabled
        // after the contest ended must not silently disappear from (or,
        // worse, orphan a run in) a historical export.
        return $contest->users()
            ->where('user_type', User::TYPE_TEAM)
            ->with('site')
            ->orderBy('user_id')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->user_id,
                'name' => $user->fullname ?? ('Equipe #'.$user->user_id),
                'institution' => $user->site->name ?? '',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id:int,name:string,institution:string}>  $teams
     * @return list<array{id:int,minutes:int,team_id:int,problem_letter:string,result:string}>
     */
    private function runs(Contest $contest, array $teams): array
    {
        $teamIds = array_column($teams, 'id');
        $letters = $this->problemLetters($contest);

        // Spec: "validar que todos os runs referenciam equipes do mesmo
        // export" -- scoping by contest_id already prevents a run from a
        // *different* export leaking in here (there's no way for a Run
        // row to reference a team from another contest's export, since
        // teams() above is itself scoped to $contest). What this
        // whereIn() guards against instead is a run authored by a
        // non-team account in the SAME contest: routes/api.php's
        // apiResource('runs', ...) lets any authenticated user (including
        // admin/judge/staff test submissions) create a Run with no
        // user_type restriction. Such a run is simply not a team result
        // and is excluded from the webcast rather than aborting the whole
        // export for every admin who ever created a debug run -- an
        // earlier version of this method threw a RuntimeException here
        // instead, which meant one stray admin-authored run permanently
        // broke this contest's export until manually deleted.
        $runs = Run::where('contest_id', $contest->id)
            ->whereIn('user_id', $teamIds)
            // `contest` for issue #138's resultCode() gate -- eager, or the
            // withheld check below is one query per run in the export.
            ->with(['answer', 'contest:id,verification_required'])
            ->orderBy('id')
            ->get();

        return $runs
            // A run whose problem is gone has no letter the consumer could
            // match, and an unmatched letter is a hard parse failure there.
            ->filter(fn (Run $run) => isset($letters[$run->problem_id]))
            ->map(fn (Run $run) => [
                'id' => $run->id,
                'minutes' => (int) floor($run->contest_time / 60),
                'team_id' => $run->user_id,
                'problem_letter' => $letters[$run->problem_id],
                'result' => $this->resultCode($run),
            ])->values()->all();
    }

    /**
     * Problem id => the letter the consumer will accept for it.
     *
     * Positional, not this app's own `short_name`, and deliberately so: the
     * consumer never learns the labels an organiser chose. It only gets
     * number_problems from the contest file and generates the letters
     * itself (data/src/lib.rs problem_letters()), so a run naming problem
     * "X" in a three-problem contest is an UnmatchedProblem even though "X"
     * is a perfectly valid Letter. Position in Contest::problems() ordering
     * (sort_order) is the only mapping guaranteed to land inside the range
     * the consumer generated.
     *
     * @return array<int, string>
     */
    private function problemLetters(Contest $contest): array
    {
        $problems = $contest->problems()->get(['id']);
        $letters = $this->letterSequence($problems->count());

        $map = [];
        foreach ($problems->values() as $index => $problem) {
            $map[$problem->id] = $letters[$index];
        }

        return $map;
    }

    /**
     * The same sequence data/src/lib.rs builds: combinations with
     * replacement over A-Z, of length 1, then 2, then 3 -- A..Z, AA, AB,
     * ..., ZZ, AAA, ... Twenty-six problems is already far beyond any real
     * contest; the longer forms exist so this cannot silently run out.
     *
     * @return list<string>
     */
    private function letterSequence(int $count): array
    {
        $alphabet = range('A', 'Z');
        $letters = [];

        foreach ($alphabet as $a) {
            $letters[] = $a;
        }

        for ($i = 0; $i < 26 && count($letters) < $count; $i++) {
            for ($j = $i; $j < 26 && count($letters) < $count; $j++) {
                $letters[] = $alphabet[$i].$alphabet[$j];
            }
        }

        return $letters;
    }

    private function resultCode(Run $run): string
    {
        if ($run->status !== 'judged' || ! $run->answer) {
            return '?';
        }

        // Issue #138. Exporting this ZIP is admin-only, but the artifact is
        // not: it feeds a public webcast animator, which is a screen in the
        // contest hall. '?' is already this format's "no verdict yet", so a
        // withheld run needs no new encoding on the consumer side -- it
        // simply has not been judged as far as the broadcast is concerned,
        // and the next export after verification carries the real code.
        if ($run->isVerdictWithheld()) {
            return '?';
        }

        if ($run->answer->is_accepted) {
            return 'Y';
        }

        // "X para erros nao penalizados especificos" -- this
        // implementation's documented mapping is BOCA's own convention of
        // treating Compilation Error as non-penalized.
        if ($run->answer->short_name === 'CE') {
            return 'X';
        }

        return 'N';
    }

    /**
     * Spec: "Nome/instituicao nao podem conter FS/newlines que corrompam
     * registros."
     */
    private function sanitizeField(?string $value): string
    {
        return str_replace([self::FS, "\r", "\n"], ' ', (string) $value);
    }
}
