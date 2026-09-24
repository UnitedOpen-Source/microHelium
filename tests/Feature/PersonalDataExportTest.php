<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Clarification;
use App\Models\Contest;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use Helium\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Issue #395 -- o titular baixa os proprios dados (LGPD art. 18, II e V).
 *
 * `routes/web.php` expunha so `GET /profile` e `PUT /profile`: nao havia
 * como a pessoa ver, de uma vez, o que o sistema guarda sobre ela.
 */
class PersonalDataExportTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problem;

    private Answer $yes;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->contest = Contest::factory()->create();
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'A']);
        $this->yes = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'is_accepted' => true]);
    }

    private function team(string $name, array $attributes = []): User
    {
        return $this->createTestUser(array_merge([
            'fullname' => $name,
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
        ], $attributes));
    }

    private function submit(User $team, string $source, array $attributes = []): Run
    {
        $path = "runs/{$this->contest->id}/{$team->user_id}/".uniqid('run_', true).'_main.cpp';
        Storage::disk('local')->put($path, $source);

        return Run::factory()->create(array_merge([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'problem_id' => $this->problem->id,
            'status' => 'judged',
            'answer_id' => $this->yes->id,
            'judged_time' => 600,
            'filename' => 'main.cpp',
            'source_file' => $path,
        ], $attributes));
    }

    private function export(User $user): TestResponse
    {
        return $this->actingAs($user)->get(route('profile.export'));
    }

    public function test_exige_login(): void
    {
        $this->get(route('profile.export'))->assertRedirect(route('login'));
    }

    public function test_traz_a_conta_e_os_envios_com_codigo_fonte(): void
    {
        $team = $this->team('Maria Aluna', ['birthdate' => '2012-03-04', 'icpc_id' => 'ICPC-1']);
        $this->submit($team, "int main() { return 42; }\n");

        $response = $this->export($team)->assertOk();

        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $data = $response->json();
        $this->assertSame($team->user_id, $data['conta']['user_id']);
        $this->assertSame('Maria Aluna', $data['conta']['fullname']);
        // `birthdate` e `$hidden` para terceiros; o titular recebe.
        $this->assertSame('2012-03-04', $data['conta']['birthdate']);
        $this->assertSame('ICPC-1', $data['conta']['icpc_id']);
        $this->assertArrayNotHasKey('password', $data['conta']);

        $this->assertCount(1, $data['submissoes']);
        $this->assertSame('A', $data['submissoes'][0]['problema']);
        $this->assertSame('YES', $data['submissoes'][0]['veredito']);
        $this->assertSame("int main() { return 42; }\n", $data['submissoes'][0]['codigo_fonte']);
        $this->assertSame('texto', $data['submissoes'][0]['codigo_fonte_formato']);
    }

    public function test_nao_traz_dados_de_outra_conta(): void
    {
        $team = $this->team('Maria Aluna');
        $other = $this->team('Outra Equipe');
        $this->submit($other, 'segredo da outra equipe');
        Clarification::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $other->user_id,
            'question' => 'pergunta da outra equipe',
        ]);

        $body = $this->export($team)->assertOk()->getContent();

        $this->assertStringNotContainsString('segredo da outra equipe', (string) $body);
        $this->assertStringNotContainsString('pergunta da outra equipe', (string) $body);
        $this->assertStringNotContainsString('Outra Equipe', (string) $body);
    }

    public function test_veredito_retido_pela_verificacao_nao_sai(): void
    {
        $this->contest->update(['verification_required' => true]);
        $team = $this->team('Maria Aluna');
        $this->submit($team, 'x', ['verified_at' => null]);

        $data = $this->export($team)->assertOk()->json();

        $this->assertNull($data['submissoes'][0]['veredito']);
        $this->assertSame('judging', $data['submissoes'][0]['status']);
    }

    public function test_resposta_de_esclarecimento_so_quando_respondida(): void
    {
        $team = $this->team('Maria Aluna');
        Clarification::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'question' => 'pergunta respondida',
            'answer' => 'resposta publicada',
            'status' => 'answered',
        ]);
        Clarification::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'question' => 'pergunta em analise',
            'answer' => 'rascunho do juiz',
            'status' => 'answering',
        ]);

        $data = $this->export($team)->assertOk()->json();
        $byQuestion = collect($data['esclarecimentos'])->keyBy('pergunta');

        $this->assertSame('resposta publicada', $byQuestion['pergunta respondida']['resposta']);
        $this->assertNull($byQuestion['pergunta em analise']['resposta']);
    }

    public function test_fonte_binario_vai_em_base64(): void
    {
        $team = $this->team('Maria Aluna');
        $this->submit($team, "PK\x03\x04\x00\x00binario");

        $data = $this->export($team)->assertOk()->json();

        $this->assertSame('base64', $data['submissoes'][0]['codigo_fonte_formato']);
        $this->assertSame("PK\x03\x04\x00\x00binario", base64_decode($data['submissoes'][0]['codigo_fonte']));
    }
}
