<?php

namespace Database\Factories;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use Helium\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Run>
 */
class RunFactory extends Factory
{
    protected $model = Run::class;

    /**
     * Conta para a vida do processo.
     *
     * `run_number` era `unique()->numberBetween(1, 1000)`: mil valores para
     * o processo inteiro, e o gerador unico do faker desiste quando acaba --
     * o mesmo teto que o #192 encontrou em ProblemFactory (26 letras) e a
     * mesma familia dos dois consertos de AnswerFactory. Uma sequencia nao
     * tem teto e custa o mesmo.
     */
    private static int $sequence = 0;

    /**
     * Issue #228 -- nem `status` nem `contest_time` sao sorteados.
     *
     * `status` era randomElement(['pending','judged','judging']), e ele
     * decide se o run conta para o placar, se esta pendente, e se o watchdog
     * do #45 o adota. Medido: 37 das 102 chamadas de Run::factory()->create()
     * na suite nao dizem o status, entao 37 testes recebiam um de tres
     * estados por sorteio.
     *
     * `contest_time` era numberBetween(0, 18000) -- de zero a cinco horas.
     * Isso virou perigoso no #211: hoje o contest_time decide se o run
     * aparece no placar congelado, e num contest padrao (300 min, freeze 60)
     * o corte esta aos 240, entao cerca de 20% dos sorteios caiam DENTRO da
     * janela. Oitenta e dois testes tiravam na sorte de que lado do corte
     * estavam.
     *
     * O padrao agora e um envio pendente, feito no minuto 30 -- cedo, longe
     * de qualquer corte, e sem veredito. Quem precisa de outra coisa diz:
     * ->judged($answer), ->judging(), ->at($minuto).
     *
     * Terceira vez que este remedio e aplicado: #135 (is_public), #213
     * (start_time), #198 (is_active). Ver a issue #228.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = ++self::$sequence;

        return [
            'contest_id' => Contest::factory(),
            'site_id' => Site::factory(),
            'user_id' => User::factory(),
            'problem_id' => Problem::factory(),
            'language_id' => Language::factory(),
            'run_number' => $n,
            'filename' => 'solucao'.$n.'.txt',
            'source_file' => 'runs/'.$this->faker->uuid().'.txt',
            'source_hash' => $this->faker->sha256(),
            'contest_time' => 30 * 60,
            'status' => 'pending',
            'answer_id' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending', 'answer_id' => null, 'judged_time' => null]);
    }

    public function judging(): static
    {
        return $this->state(fn () => ['status' => 'judging', 'answer_id' => null, 'judged_time' => null]);
    }

    /**
     * Julgado, com veredito -- e com `judged_time` preenchido junto.
     *
     * Os dois andam juntos de proposito: um run `judged` sem judged_time e
     * um estado que nenhum caminho de producao produz (todos os
     * finalizadores gravam os dois), e uma fixture que o produzisse faria um
     * teste afirmar coisas sobre uma linha impossivel.
     */
    public function judged(?Answer $answer = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'judged',
            'answer_id' => $answer?->id,
            'judged_time' => $attributes['contest_time'] ?? 30 * 60,
        ]);
    }

    /** No minuto $minute de prova. */
    public function at(int $minute): static
    {
        return $this->state(fn (array $attributes) => array_merge(
            ['contest_time' => $minute * 60],
            ($attributes['status'] ?? null) === 'judged' ? ['judged_time' => $minute * 60] : [],
        ));
    }
}
