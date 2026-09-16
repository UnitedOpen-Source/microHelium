<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Services\Icpc\IcpcPackageException;
use App\Services\Icpc\IcpcPackageImporter;
use App\Services\Icpc\IcpcPackageReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use ZipArchive;

/**
 * Issue #200/#233 -- importar um pacote de problema PARA UMA COMPETICAO.
 *
 * Separado do formulario de importacao do BOCA que ja existe
 * (`backend.import-boca`), e a issue pede isso em voz alta: aquele usa
 * BocaImporterService e traz um contest inteiro PARA O BANCO. Contrato
 * diferente, resultado diferente, e reapontar um para o outro entregaria
 * silenciosamente a coisa errada.
 *
 * Adaptador de sessao/CSRF sobre os mesmos servicos que a API usa, como o
 * ContestOperationsController faz para a cerimonia.
 */
class ProblemPackageImportController extends Controller
{
    public function show(Request $request): View
    {
        // Issue #43: a prova de treino nao recebe problema importado a mao
        // -- os problemas dela sao instantaneos versionados do
        // PracticePublisher, e editar por fora diverge a biblioteca.
        $contests = Contest::query()->competition()->orderByDesc('start_time')->pluck('name', 'id');

        return view('backend.import-package', [
            'contests' => $contests,
            'diagnostico' => $request->session()->get('diagnostico'),
        ]);
    }

    public function store(Request $request, IcpcPackageReader $reader, IcpcPackageImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'contest_id' => 'required|exists:contests,id',
            'package' => 'required|file|mimes:zip|max:102400',
            'short_name' => 'nullable|string|max:10',
            // Conferir sem escrever nada. A issue pede "diagnostico de
            // pacote", e um diagnostico que so existe DEPOIS de importar
            // chega tarde: o problema errado ja esta na prova.
            'dry_run' => 'sometimes|boolean',
        ]);

        $contest = Contest::findOrFail($validated['contest_id']);
        abort_if($contest->is_practice, 404);

        $extractDir = storage_path('app/temp/import_'.uniqid());

        try {
            $zip = new ZipArchive;

            if ($zip->open($request->file('package')->getRealPath()) !== true) {
                return back()->with('error', 'Não foi possível abrir o arquivo ZIP.')->withInput();
            }

            @mkdir($extractDir, 0o755, true);
            $zip->extractTo($extractDir);
            $zip->close();

            try {
                $pacote = $reader->read($extractDir);
            } catch (IcpcPackageException $e) {
                // O motivo volta legivel em vez de virar 500: um pacote
                // invalido e erro de quem enviou, e quem enviou consegue
                // consertar se souber o que esta errado.
                return back()->with('error', $e->getMessage())->withInput();
            }

            $diagnostico = $this->descrever($pacote);

            if ($request->boolean('dry_run')) {
                return back()->with('diagnostico', $diagnostico + ['importado' => false])->withInput();
            }

            $problema = $importer->import($contest, $extractDir, array_filter([
                'short_name' => $validated['short_name'] ?? null,
            ]));

            ContestLog::info($contest->id, "Problema {$problema->short_name} importado de pacote ICPC/Kattis", [
                'event' => 'icpc_package_imported',
                'problem_id' => $problema->id,
                'format_version' => $pacote['version'],
                'user_id' => $request->user()?->user_id,
            ]);

            return back()->with('diagnostico', $diagnostico + [
                'importado' => true,
                'problema' => "{$problema->short_name} — {$problema->name}",
            ]);
        } finally {
            $this->apagar($extractDir);
        }
    }

    /**
     * O que o pacote diz sobre si, para a pessoa conferir ANTES de aceitar.
     *
     * @param  array<string, mixed>  $pacote
     * @return array<string, mixed>
     */
    private function descrever(array $pacote): array
    {
        return [
            'nome' => $pacote['name'],
            'versao' => $pacote['version'],
            'licenca' => $pacote['license'],
            'time_limit' => $pacote['time_limit'],
            'memory_limit' => $pacote['memory_limit'],
            'amostras' => count($pacote['samples']),
            'secretos' => count($pacote['secret']),
            // Um validador proprio muda como o problema e julgado, entao a
            // pessoa precisa saber que ele existe antes de importar.
            'validador' => $pacote['output_validators'] !== [],
            'enunciado' => $pacote['statement'] !== null,
            // Guardadas para o #196, que quer medir o limite de tempo em vez
            // de aceitar um numero digitado.
            'solucoes' => count($pacote['accepted_submissions']),
        ];
    }

    private function apagar(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*') ?: [] as $path) {
            is_dir($path) ? $this->apagar($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
