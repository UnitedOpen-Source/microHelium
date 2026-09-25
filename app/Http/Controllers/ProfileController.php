<?php

namespace App\Http\Controllers;

use App\Models\Clarification;
use App\Models\Run;
use App\Models\SosCall;
use App\Models\Task;
use Helium\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use App\Support\SourceText;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        // current_password is only checked when the user is actually
        // changing their password -- otherwise a stale/incorrect value
        // left by a password manager's autocomplete would block unrelated
        // profile edits (e.g. just fixing a typo in the fullname).
        $changingPassword = $request->filled('password');

        $validated = $request->validate([
            'fullname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->user_id, 'user_id')],
            'current_password' => $changingPassword ? ['required', 'current_password'] : ['nullable'],
            'password' => ['nullable', 'confirmed', 'min:8'],
        ]);

        $user->fullname = $validated['fullname'];
        $user->email = $validated['email'];

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return back()->with('success', __('Perfil atualizado com sucesso!'));
    }

    /**
     * Issue #395 -- o titular baixa o que o sistema guarda sobre ele (LGPD
     * art. 18, II e V).
     *
     * So a propria conta: a rota nao recebe id, e toda consulta abaixo parte
     * de `$request->user()`. O que entra e o que fica de fora, e por que,
     * esta em docs/specs/395-anonimizar-em-vez-de-apagar.md -- em especial
     * `scores`, que ficam fora porque exporta-los durante a verificacao
     * anunciaria o veredito que a mascara das submissoes esconde (#138).
     */
    public function export(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $runs = Run::with(['contest', 'problem', 'language', 'answer'])
            ->where('user_id', $user->user_id)
            ->orderBy('id')
            ->get()
            ->map(fn (Run $run) => $this->exportRun($this->maskWithheldVerdict($run)));

        $clarifications = Clarification::with('problem')
            ->where('user_id', $user->user_id)
            ->orderBy('id')
            ->get()
            ->map(fn (Clarification $c) => [
                'contest_id' => $c->contest_id,
                'problema' => $c->problem?->short_name,
                'pergunta' => $c->question,
                // So a resposta que foi dada. Em `answering` o campo pode
                // ter o rascunho de quem estava respondendo.
                'resposta' => in_array($c->status, ['answered', 'broadcast_site', 'broadcast_all'], true) ? $c->answer : null,
                'status' => $c->status,
                'tempo_de_prova_s' => $c->contest_time,
                'criado_em' => $c->created_at?->toIso8601String(),
            ]);

        $prints = Task::where('user_id', $user->user_id)
            ->where('is_system', false)
            ->orderBy('id')
            ->get()
            ->map(fn (Task $t) => [
                'contest_id' => $t->contest_id,
                'descricao' => $t->description,
                'arquivo' => $t->filename,
                'status' => $t->status,
                'tempo_de_prova_s' => $t->contest_time,
                'criado_em' => $t->created_at?->toIso8601String(),
            ]);

        $sos = SosCall::where('user_id', $user->user_id)
            ->orderBy('id')
            ->get()
            ->map(fn (SosCall $call) => [
                'contest_id' => $call->contest_id,
                'nota' => $call->note,
                'status' => $call->status,
                'tempo_de_prova_s' => $call->contest_time,
                'criado_em' => $call->created_at?->toIso8601String(),
            ]);

        $payload = [
            'gerado_em' => now()->toIso8601String(),
            'conta' => [
                'user_id' => $user->user_id,
                'fullname' => $user->fullname,
                'username' => $user->username,
                'email' => $user->email,
                'user_type' => $user->user_type,
                // `$hidden` no model existe para nao vazar a terceiros; aqui
                // quem le e o titular.
                'birthdate' => $user->birthdate?->toDateString(),
                'icpc_id' => $user->icpc_id,
                'label' => $user->label,
                'descricao' => $user->description,
                'instituicao' => $user->organization?->name,
                'contest_id' => $user->contest_id,
                'site_id' => $user->site_id,
                'conta_gerenciada' => $user->managed_by !== null,
                'visibilidade_do_perfil' => $user->profile_visibility,
                'ultimo_ip' => $user->last_ip,
                'ultimo_login_em' => $user->last_login_at,
                'criado_em' => $user->created_at?->toIso8601String(),
            ],
            'submissoes' => $runs->values(),
            'esclarecimentos' => $clarifications->values(),
            'impressoes' => $prints->values(),
            'chamados_sos' => $sos->values(),
        ];

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="meus-dados-'.$user->user_id.'.json"',
            'Cache-Control' => 'no-store, private',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function exportRun(Run $run): array
    {
        $source = null;
        $format = null;

        if ($run->source_file && Storage::disk('local')->exists($run->source_file)) {
            $content = (string) Storage::disk('local')->get($run->source_file);
            // O mesmo criterio do envio e do treino (SourceText, #268/#390): texto e UTF-8
            // valido sem byte nulo; o resto vai em base64, que e o unico
            // jeito de um binario sobreviver dentro de JSON.
            if (SourceText::isText($content)) {
                $source = $content;
                $format = 'texto';
            } else {
                $source = base64_encode($content);
                $format = 'base64';
            }
        }

        return [
            'contest_id' => $run->contest_id,
            'numero' => $run->run_number,
            'problema' => $run->problem?->short_name,
            'linguagem' => $run->language?->name,
            'arquivo' => $run->filename,
            'tempo_de_prova_s' => $run->contest_time,
            'status' => $run->status,
            'veredito' => $run->answer?->short_name,
            'criado_em' => $run->created_at?->toIso8601String(),
            'codigo_fonte' => $source,
            'codigo_fonte_formato' => $format,
        ];
    }
}
