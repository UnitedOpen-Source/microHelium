<?php

namespace App\Services;

use App\Models\AccountActivation;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\IdempotencyKey;
use App\Models\Leaderboard;
use App\Models\OrganizationMembership;
use App\Models\Run;
use App\Models\Task;
use Helium\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Issue #395 -- atender um pedido de exclusao sem reescrever o placar.
 *
 * `Backend\UserController::destroy()` fazia `$user->delete()`, e o model nao
 * usa SoftDeletes: era um DELETE, e as chaves estrangeiras com
 * `cascadeOnDelete` levavam `runs`, `scores` e `leaderboard` junto. A equipe
 * sumia do placar de uma prova ja finalizada e todo mundo abaixo dela subia.
 *
 * Aqui a linha de `users` fica, e o que identifica a pessoa sai: nome, login,
 * e-mail, nascimento, `icpc_id`, IPs, senha, tokens, e o codigo-fonte que ela
 * escreveu. O que fica e o que o placar e a trilha da prova precisam -- os
 * envios com tempo e veredito, a pontuacao, a sede, a instituicao. A lista
 * completa, e o porque de cada item que fica, esta em
 * docs/specs/395-anonimizar-em-vez-de-apagar.md.
 */
class AccountAnonymizer
{
    public static function pseudonym(User $user): string
    {
        return "Participante {$user->user_id}";
    }

    public function anonymize(User $user, ?User $actor = null): void
    {
        if ($user->isAnonymized()) {
            return;
        }

        $userId = (int) $user->user_id;
        $oldEmail = $user->email;

        // Arquivos se apagam DEPOIS do commit: um rollback no meio nao
        // devolve um arquivo apagado, e um envio cujo fonte sumiu mas cuja
        // conta ainda tem nome seria o pior dos dois estados.
        $files = [];

        DB::transaction(function () use ($user, $actor, $userId, $oldEmail, &$files) {
            foreach (Run::withTrashed()->where('user_id', $userId)->get() as $run) {
                if ($run->source_file !== '' && $run->source_file !== null) {
                    $files[] = $run->source_file;
                }

                // O nome do arquivo era do competidor, e podia ser o nome
                // dele ("joao_silva.cpp"). A extensao fica: e ela que diz em
                // que linguagem o envio foi feito para quem ler a linha.
                $extension = pathinfo((string) $run->filename, PATHINFO_EXTENSION);

                $run->forceFill([
                    'source_file' => '',
                    'filename' => 'submission'.($extension !== '' ? '.'.$extension : ''),
                ])->saveQuietly();
            }

            foreach (Task::withTrashed()->where('user_id', $userId)->whereNotNull('file_path')->get() as $task) {
                $files[] = (string) $task->file_path;
                $task->forceFill(['file_path' => null])->saveQuietly();
            }

            $user->tokens()->delete();
            AccountActivation::where('user_id', $userId)->delete();
            OrganizationMembership::where('user_id', $userId)->delete();
            IdempotencyKey::where('user_id', $userId)->delete();

            if ($oldEmail !== null && $oldEmail !== '') {
                DB::table('password_resets')->where('email', $oldEmail)->delete();
            }

            ContestLog::where('user_id', $userId)->update(['ip_address' => null]);

            $user->forceFill([
                'fullname' => self::pseudonym($user),
                'username' => "anonimo-{$userId}",
                'email' => null,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'birthdate' => null,
                'icpc_id' => null,
                'description' => null,
                'permitted_ip' => null,
                'last_ip' => null,
                'last_login_at' => null,
                'profile_visibility' => 'private',
                // Derruba sessao viva (EnsureAccountIsEnabled) e recusa
                // token novo. O placar nao perde a linha por isso: ver
                // ScoreboardTeams::competitors().
                'is_enabled' => false,
                'anonymized_at' => now(),
                'anonymized_by' => $actor?->user_id,
            ])->save();

            foreach ($this->contestIds($user) as $contestId) {
                ContestLog::log(
                    $contestId,
                    'info',
                    "Conta #{$userId} anonimizada a pedido do titular",
                    userId: $actor?->user_id,
                    context: [
                        'anonymized_user_id' => $userId,
                        'actor_user_id' => $actor?->user_id,
                    ],
                );
            }
        });

        foreach ($files as $path) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * As provas em que a conta estava inscrita ou competiu -- e onde o
     * registro da anonimizacao vai. Uma conta de administrador pode nao ter
     * nenhuma; `contest_logs.contest_id` e obrigatorio, entao ela fica so
     * com `anonymized_at`/`anonymized_by`.
     *
     * @return list<int>
     */
    private function contestIds(User $user): array
    {
        $ids = collect([$user->contest_id, $user->site?->contest_id])
            ->merge(Run::withTrashed()->where('user_id', $user->user_id)->distinct()->pluck('contest_id'))
            ->merge(Leaderboard::where('user_id', $user->user_id)->distinct()->pluck('contest_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return Contest::whereIn('id', $ids)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
