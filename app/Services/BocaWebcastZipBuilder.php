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
 * That section is explicit that this format was reconstructed from a
 * textual field breakdown of BOCA's src/admin/report/webcast.php (read via
 * the GitHub API on 2026-09-10), not from re-deriving the producer's exact
 * byte layout ourselves, and that byte-for-byte compatibility with any
 * real Animeitor consumer is UNVERIFIED (the linked consumer repo 404'd
 * during that research). Every field this class writes beyond the ones the
 * spec explicitly names (see the per-file docblocks below) is this
 * implementation's own documented interpretation, not a confirmed BOCA
 * constant. config('webcast.export_enabled') stays false until a real
 * fixture confirms this -- see config/webcast.php.
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
     * Spec: no explicit unit conversion is named for `time` beyond runs'
     * "tempo convertido para minutos" -- this implementation uses the same
     * minutes convention for consistency: elapsed contest time in minutes,
     * capped at the contest duration once it has ended.
     */
    private function timeFile(Contest $contest): string
    {
        // Deliberately not using Contest::getContestTime() here: it calls
        // now()->diffInSeconds($this->start_time) with no explicit
        // $absolute argument, which returns a *signed* difference on the
        // Carbon version this app pins (negative once the contest has
        // started) -- a pre-existing quirk in that model method, out of
        // scope for issue #44 to fix. Computed directly here instead, with
        // absolute=true forced explicitly so the sign convention can't
        // silently flip again.
        if (! $contest->start_time || now()->lt($contest->start_time)) {
            return "0\n";
        }

        $elapsedSeconds = $contest->start_time->diffInSeconds(now(), true);
        $minutes = (int) min(floor($elapsedSeconds / 60), $contest->duration);

        return $minutes."\n";
    }

    /**
     * Spec breakdown: "contest contem nome, duracao/lastmileanswer/
     * lastmilescore/penalidade em minutos, quantidades de equipes/
     * problemas, linhas de equipe (ID, instituicao, nome) e linhas de
     * configuracao finais."
     *
     * lastmileanswer/lastmilescore have no equivalent column in this
     * app's Contest model (no "freeze last-mile" setting is modeled), so
     * both are written as 0 (disabled) -- a documented placeholder, not a
     * discovered BOCA default.
     *
     * The spec names team lines explicitly but only a *count* of problems,
     * not per-problem detail lines. Runs still need to identify which
     * problem they're for, so this implementation also emits one line per
     * problem (id, short_name, name) as its own documented addition beyond
     * the literal spec text -- this is exactly the kind of gap the spec's
     * "Gate de integracao" flags as needing a real consumer fixture to
     * confirm or correct.
     *
     * "linhas de configuracao finais" is written as a single trailing line
     * with the site count -- also this implementation's placeholder.
     */
    private function contestFile(Contest $contest, array $teams): string
    {
        $fs = self::FS;
        $problems = $contest->problems()->orderBy('sort_order')->get(['id', 'short_name', 'name']);
        $siteCount = $contest->sites()->count();

        $lines = [];
        $lines[] = implode($fs, [
            $this->sanitizeField($contest->name),
            (string) $contest->duration,
            '0', // lastmileanswer (minutes) -- no equivalent setting; see docblock.
            '0', // lastmilescore (minutes) -- no equivalent setting; see docblock.
            (string) $contest->penalty,
        ]);

        $lines[] = implode($fs, [(string) count($teams), (string) $problems->count()]);

        foreach ($teams as $team) {
            $lines[] = implode($fs, [
                (string) $team['id'],
                $this->sanitizeField($team['institution']),
                $this->sanitizeField($team['name']),
            ]);
        }

        foreach ($problems as $problem) {
            $lines[] = implode($fs, [
                (string) $problem->id,
                $this->sanitizeField($problem->short_name),
                $this->sanitizeField($problem->name),
            ]);
        }

        // Final configuration line(s) -- placeholder (site count only).
        $lines[] = (string) $siteCount;

        return implode("\n", $lines)."\n";
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
     * (once can_export ships) a scoped webcast credential, never by the
     * public scoreboard.
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
                (string) $run['problem_id'],
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
                'name' => $user->fullname ?: ('Equipe #'.$user->user_id),
                'institution' => $user->site->name ?? '',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id:int,name:string,institution:string}>  $teams
     * @return list<array{id:int,minutes:int,team_id:int,problem_id:int,result:string}>
     */
    private function runs(Contest $contest, array $teams): array
    {
        $teamIds = array_column($teams, 'id');

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
            ->with('answer')
            ->orderBy('id')
            ->get();

        return $runs->map(fn (Run $run) => [
            'id' => $run->id,
            'minutes' => (int) floor($run->contest_time / 60),
            'team_id' => $run->user_id,
            'problem_id' => $run->problem_id,
            'result' => $this->resultCode($run),
        ])->values()->all();
    }

    private function resultCode(Run $run): string
    {
        if (! in_array($run->status, ['judged'], true) || ! $run->answer) {
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
