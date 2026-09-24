<?php

namespace Tests\Feature;

use App\Jobs\JudgeRunJob;
use App\Jobs\RejudgeMemberJob;
use App\Models\Answer;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Language;
use App\Models\Problem;
use App\Models\RejudgingRun;
use App\Models\Run;
use App\Models\Site;
use App\Models\TestCase as ProblemTestCase;
use App\Services\AutoJudgeService;
use App\Services\Judgehost\JudgingToolchain;
use App\Services\Judgehost\MachineCapabilities;
use App\Services\Judgehost\SandboxPreflight;
use App\Services\RejudgingService;
use Helium\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\DefinePerfilDaImagem;
use Tests\TestCase;

/**
 * Issue #392 -- cada julgamento registra com qual versao de toolchain, e em
 * qual perfil de imagem, ele foi feito.
 *
 * O caminho remoto (agente -> HTTP -> ResultController) esta em
 * JudgehostAgentTest e JudgehostPullTest, onde ja moram os dubles de
 * servidor. Aqui ficam o caminho LOCAL (fila), o perfil, e a sinalizacao no
 * rejulgamento.
 */
class VersaoNoJulgamentoTest extends TestCase
{
    use DefinePerfilDaImagem;

    private Contest $contest;

    private Site $site;

    private Problem $problem;

    private Answer $accepted;

    private Answer $wrong;

    /** @var list<string> */
    private array $scratchDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'autojudge.use_bwrap' => false,
            'autojudge.work_dir' => sys_get_temp_dir().'/versao_julgamento_'.getmypid(),
            'autojudge.rss_time_path' => '/nonexistent/gnu-time',
            'autojudge.cgroup_root' => '/sys/fs/cgroup/nao-delegado',
        ]);

        $this->contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subMinutes(5)]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problem = Problem::factory()->create([
            'contest_id' => $this->contest->id,
            'auto_judge' => true,
            'time_limit' => 5,
            'memory_limit' => 256,
        ]);
        $this->accepted = Answer::factory()->create([
            'contest_id' => $this->contest->id, 'short_name' => 'AC', 'is_accepted' => true,
        ]);
        $this->wrong = Answer::factory()->create([
            'contest_id' => $this->contest->id, 'short_name' => 'WA', 'is_accepted' => false,
        ]);
    }

    protected function tearDown(): void
    {
        $this->restaurarPerfilDaImagem();

        foreach ($this->scratchDirs as $dir) {
            $this->removeTree($dir);
        }

        $this->removeTree(sys_get_temp_dir().'/versao_julgamento_'.getmypid());

        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * Uma maquina que responde a versao que o teste quiser.
     *
     * @param  array<string, string>  $versoes
     */
    private function maquina(array $versoes): MachineCapabilities
    {
        return new class($versoes) extends MachineCapabilities
        {
            /** @param  array<string, string>  $versoes */
            public function __construct(private array $versoes) {}

            public function versionsOf(array $extensions): array
            {
                return array_intersect_key($this->versoes, array_flip($extensions));
            }
        };
    }

    private function permissivePreflight(): SandboxPreflight
    {
        return new class extends SandboxPreflight
        {
            public function blocker(): ?array
            {
                return null;
            }
        };
    }

    /**
     * Um envio de verdade: fonte e caso de teste no disco, onde o juiz local
     * os le.
     */
    private function envio(Language $language, string $arquivo, string $fonte, string $entrada, string $esperado, array $atributos = []): Run
    {
        $team = User::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_type' => 'team',
        ]);

        $dir = storage_path('app/versao_julgamento_'.getmypid());
        @mkdir($dir, 0755, true);
        $this->scratchDirs[] = $dir;

        $rel = 'versao_julgamento_'.getmypid();
        file_put_contents($dir.'/'.$team->user_id.'_'.$arquivo, $fonte);
        file_put_contents($dir.'/1_'.$team->user_id.'.in', $entrada);
        file_put_contents($dir.'/1_'.$team->user_id.'.out', $esperado);

        if (! $this->problem->testCases()->exists()) {
            ProblemTestCase::create([
                'problem_id' => $this->problem->id,
                'number' => 1,
                'input_file' => $rel.'/1_'.$team->user_id.'.in',
                'output_file' => $rel.'/1_'.$team->user_id.'.out',
                'input_hash' => hash('sha256', $entrada),
                'output_hash' => hash('sha256', $esperado),
                'is_sample' => false,
            ]);
        }

        return Run::factory()->create(array_merge([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'problem_id' => $this->problem->id,
            'language_id' => $language->id,
            'status' => 'pending',
            'answer_id' => null,
            'filename' => $arquivo,
            'source_file' => $rel.'/'.$team->user_id.'_'.$arquivo,
            'source_hash' => hash('sha256', $fonte),
        ], $atributos));
    }

    private function php(): Language
    {
        return Language::factory()->create([
            'contest_id' => $this->contest->id,
            'extension' => 'php',
            'name' => 'PHP (versao no julgamento)',
            'compile_command' => 'true',
            'run_command' => 'php {source}',
        ]);
    }

    // --- o perfil ---------------------------------------------------------

    public function test_imagem_que_nao_diz_o_perfil_e_a_completa(): void
    {
        $this->definirPerfilDaImagem(null);
        $this->assertSame('completo', JudgingToolchain::perfil());

        $this->definirPerfilDaImagem('   ');
        $this->assertSame('completo', JudgingToolchain::perfil(), 'perfil vazio tem de valer o padrao, e nao virar ""');

        $this->definirPerfilDaImagem(' maratona ');
        $this->assertSame('maratona', JudgingToolchain::perfil());
    }

    public function test_uma_sonda_que_explode_vira_versao_desconhecida_e_nao_derruba_nada(): void
    {
        $quebrada = new class extends MachineCapabilities
        {
            public function versionsOf(array $extensions): array
            {
                throw new RuntimeException('toolchain travou');
            }
        };

        $this->definirPerfilDaImagem('maratona');

        $this->assertSame(
            ['toolchain_version' => null, 'toolchain_profile' => 'maratona'],
            (new JudgingToolchain($quebrada))->carimbo('cpp17_gpp')
        );
    }

    // --- caminho local (fila) ---------------------------------------------

    /**
     * A fila julga de verdade um envio em PHP e grava a versao que a sonda
     * REAL leu -- pela linha `php` de `ToolchainVersions`, contra o PHP que
     * roda a suite -- e o perfil do ambiente.
     *
     * PHP pelo mesmo motivo do teste irmao do agente: e a unica linguagem do
     * catalogo que existe com certeza onde quer que esta suite rode.
     */
    public function test_a_fila_grava_a_versao_e_o_perfil_com_que_julgou(): void
    {
        $this->definirPerfilDaImagem('scripting');

        $run = $this->envio(
            $this->php(),
            'sol.php',
            "<?php \$l = explode(' ', trim(fgets(STDIN))); echo \$l[0] + \$l[1], \"\\n\";\n",
            "3 4\n",
            "7\n",
        );

        (new JudgeRunJob($run))->handle(app(AutoJudgeService::class), $this->permissivePreflight());

        $run->refresh();

        $this->assertSame('judged', $run->status);
        $this->assertSame($this->accepted->id, $run->answer_id, 'o envio correto tinha de dar AC: '.$run->auto_judge_result.' '.$run->auto_judge_stderr);
        $this->assertSame(PHP_VERSION, $run->toolchain_version, 'a fila julgou e nao registrou com qual versao');
        $this->assertSame('scripting', $run->toolchain_profile);
    }

    /**
     * Linguagem sem receita de versao (customizada) continua julgando, e
     * grava null -- "nao disse", nunca "nao tem".
     */
    public function test_linguagem_sem_receita_de_versao_julga_e_grava_null(): void
    {
        $this->definirPerfilDaImagem(null);

        $sh = Language::factory()->create([
            'contest_id' => $this->contest->id,
            'extension' => 'sh',
            'compile_command' => 'true',
            'run_command' => 'bash {source}',
        ]);

        $run = $this->envio($sh, 'sol.sh', "read a b\necho \$((a + b))\n", "3 4\n", "7\n");

        (new JudgeRunJob($run))->handle(app(AutoJudgeService::class), $this->permissivePreflight());

        $run->refresh();

        $this->assertSame($this->accepted->id, $run->answer_id);
        $this->assertNull($run->toolchain_version);
        $this->assertSame('completo', $run->toolchain_profile);
    }

    // --- rejulgamento avulso ----------------------------------------------

    /**
     * O rejulgamento avulso nao apaga a versao anterior, e o julgamento novo
     * com OUTRA versao escreve um aviso no log da prova.
     */
    public function test_rejulgar_com_outra_versao_do_compilador_fica_no_log_da_prova(): void
    {
        Queue::fake();

        $cpp = Language::factory()->create(['contest_id' => $this->contest->id, 'extension' => 'cpp17_gpp']);
        $run = $this->envio($cpp, 'sol.cpp', 'int main(){}', "\n", "\n", [
            'status' => 'judged',
            'answer_id' => $this->wrong->id,
            'toolchain_version' => '13.2.1',
            'toolchain_profile' => 'maratona',
        ]);

        Sanctum::actingAs(User::factory()->create(['user_type' => 'admin']));
        $this->postJson("/api/runs/{$run->id}/rejudge")->assertOk();

        $run->refresh();
        $this->assertSame('pending', $run->status);
        $this->assertSame('13.2.1', $run->toolchain_version, 'o rejulgamento apagou a versao anterior, e nao ha mais com o que comparar');

        $this->app->instance(JudgingToolchain::class, new JudgingToolchain($this->maquina(['cpp17_gpp' => '15.2.0'])));
        $this->definirPerfilDaImagem('completo');

        (new JudgeRunJob($run))->handle($this->juizQueResponde('AC'), $this->permissivePreflight());

        $run->refresh();
        $this->assertSame($this->accepted->id, $run->answer_id);
        $this->assertSame('15.2.0', $run->toolchain_version);
        $this->assertSame('completo', $run->toolchain_profile);

        $aviso = ContestLog::where('contest_id', $this->contest->id)
            ->get()
            ->first(fn (ContestLog $log) => ($log->context['event'] ?? null) === 'toolchain_changed');

        $this->assertNotNull($aviso, 'o veredito mudou junto com o compilador e nada no log da prova diz isso');
        $this->assertSame('warning', $aviso->type);
        $this->assertSame('13.2.1', $aviso->context['from']);
        $this->assertSame('15.2.0', $aviso->context['to']);
        $this->assertSame('maratona', $aviso->context['profile_from']);
        $this->assertSame('completo', $aviso->context['profile_to']);
        $this->assertStringContainsString('13.2.1 -> 15.2.0', $aviso->message);
    }

    public function test_rejulgar_com_a_mesma_versao_nao_avisa_nada(): void
    {
        $cpp = Language::factory()->create(['contest_id' => $this->contest->id, 'extension' => 'cpp17_gpp']);
        $run = $this->envio($cpp, 'sol.cpp', 'int main(){}', "\n", "\n", [
            'toolchain_version' => '15.2.0',
            'toolchain_profile' => 'completo',
        ]);

        $this->app->instance(JudgingToolchain::class, new JudgingToolchain($this->maquina(['cpp17_gpp' => '15.2.0'])));

        (new JudgeRunJob($run))->handle($this->juizQueResponde('AC'), $this->permissivePreflight());

        $this->assertSame('judged', $run->fresh()->status);
        $this->assertFalse(
            ContestLog::where('contest_id', $this->contest->id)->get()
                ->contains(fn (ContestLog $log) => ($log->context['event'] ?? null) === 'toolchain_changed')
        );
    }

    /**
     * Um juiz que devolve o veredito dado sem compilar nada -- o que se
     * exercita aqui e o que acontece DEPOIS do julgamento. Tudo o mais
     * (`judge()`, o carimbo, `recordVerdict()`) e o codigo de producao.
     */
    private function juizQueResponde(string $veredito): AutoJudgeService
    {
        return new class($veredito) extends AutoJudgeService
        {
            public function __construct(private string $veredito)
            {
                parent::__construct();
            }

            protected function executeJudging(Run $run): array
            {
                return ['verdict' => $this->veredito, 'message' => $this->veredito, 'stdout' => '', 'stderr' => ''];
            }
        };
    }

    // --- rejulgamento em lote ---------------------------------------------

    public function test_o_lote_guarda_a_versao_antiga_e_a_nova_e_sinaliza_a_mudanca(): void
    {
        Queue::fake();

        $cpp = Language::factory()->create(['contest_id' => $this->contest->id, 'extension' => 'cpp17_gpp']);
        $gcc13 = $this->envio($cpp, 'a.cpp', 'int main(){}', "\n", "\n", [
            'status' => 'judged', 'answer_id' => $this->wrong->id,
            'toolchain_version' => '13.2.1', 'toolchain_profile' => 'maratona',
        ]);
        $gcc15 = $this->envio($cpp, 'b.cpp', 'int main(){}', "\n", "\n", [
            'status' => 'judged', 'answer_id' => $this->wrong->id,
            'toolchain_version' => '15.2.0', 'toolchain_profile' => 'completo',
        ]);
        $semVersao = $this->envio($cpp, 'c.cpp', 'int main(){}', "\n", "\n", [
            'status' => 'judged', 'answer_id' => $this->wrong->id,
            'toolchain_version' => null, 'toolchain_profile' => null,
        ]);

        $admin = User::factory()->create(['user_type' => 'admin', 'contest_id' => $this->contest->id]);
        $service = app(RejudgingService::class);
        $rejudging = $service->create($this->contest, ['problem_id' => $this->problem->id], 'caso 7 errado', false, $admin);

        $membro = fn (Run $run) => RejudgingRun::where('rejudging_id', $rejudging->id)->where('run_id', $run->id)->first();

        $this->assertSame('13.2.1', $membro($gcc13)->old_toolchain_version, 'a versao antiga tem de ser capturada ao montar o conjunto');
        $this->assertSame('maratona', $membro($gcc13)->old_toolchain_profile);

        // O julgamento de sombra, de verdade, neste "worker" com GCC 15.
        $this->app->instance(JudgingToolchain::class, new JudgingToolchain($this->maquina(['cpp17_gpp' => '15.2.0'])));
        $this->app->instance(AutoJudgeService::class, $this->juizQueResponde('AC'));
        $this->definirPerfilDaImagem('completo');

        foreach ([$gcc13, $gcc15, $semVersao] as $run) {
            (new RejudgeMemberJob($membro($run)->id))->handle(app(AutoJudgeService::class), $service);
        }

        $this->assertSame('15.2.0', $membro($gcc13)->new_toolchain_version);
        $this->assertSame('completo', $membro($gcc13)->new_toolchain_profile);

        $this->assertTrue($membro($gcc13)->changesToolchain());
        $this->assertFalse($membro($gcc15)->changesToolchain(), 'mesma versao nao e mudanca');
        $this->assertFalse($membro($semVersao)->changesToolchain(), '"nao disse" nao e "mudou"');

        $previa = $service->preview($rejudging->fresh());

        $this->assertSame(1, $previa['toolchain_changes']);

        $item = collect($previa['items'])->firstWhere('run_id', $gcc13->id);
        $this->assertTrue($item['toolchain_changed']);
        $this->assertSame('13.2.1', $item['toolchain_from']);
        $this->assertSame('15.2.0', $item['toolchain_to']);

        $service->apply($rejudging->fresh(), $admin);

        // O veredito novo foi produzido pelo GCC 15, entao e ele que passa a
        // descrever o run -- inclusive o que antes nao dizia versao nenhuma.
        foreach ([$gcc13, $gcc15, $semVersao] as $run) {
            $run->refresh();
            $this->assertSame($this->accepted->id, $run->answer_id);
            $this->assertSame('15.2.0', $run->toolchain_version);
            $this->assertSame('completo', $run->toolchain_profile);
        }

        $aplicado = ContestLog::where('contest_id', $this->contest->id)->get()
            ->first(fn (ContestLog $log) => ($log->context['event'] ?? null) === 'rejudging_applied');
        $this->assertSame(1, $aplicado->context['toolchain_changed']);
    }

    public function test_a_tela_do_lote_mostra_a_mudanca_de_toolchain(): void
    {
        Queue::fake();

        $cpp = Language::factory()->create(['contest_id' => $this->contest->id, 'extension' => 'cpp17_gpp']);
        $run = $this->envio($cpp, 'a.cpp', 'int main(){}', "\n", "\n", [
            'status' => 'judged', 'answer_id' => $this->wrong->id,
            'toolchain_version' => '13.2.1', 'toolchain_profile' => 'maratona',
        ]);

        $admin = User::factory()->create(['user_type' => 'admin', 'contest_id' => $this->contest->id]);
        $rejudging = app(RejudgingService::class)->create($this->contest, ['problem_id' => $this->problem->id], 'caso 7 errado', false, $admin);

        RejudgingRun::where('rejudging_id', $rejudging->id)->where('run_id', $run->id)->update([
            'new_answer_id' => $this->accepted->id,
            'new_verdict' => 'AC',
            'new_toolchain_version' => '15.2.0',
            'new_toolchain_profile' => 'completo',
            'judged_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('judge.rejudgings.show', $rejudging))
            ->assertOk()
            ->assertSee('Julgados com outra versão do toolchain')
            ->assertSee('13.2.1 → 15.2.0');
    }
}
