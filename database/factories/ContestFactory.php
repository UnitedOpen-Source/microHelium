<?php

namespace Database\Factories;

use App\Models\Contest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contest>
 */
class ContestFactory extends Factory
{
    protected $model = Contest::class;

    /**
     * Issue #213 -- o tempo deixa de ser sorteado.
     *
     * `start_time` era `dateTimeBetween('-1 month', '+1 month')`, com
     * `duration`, `freeze_time` e `is_active` tambem aleatorios. Para
     * qualquer teste que usasse o factory sem fixar essas colunas, se o
     * contest estava rodando, congelado, ou nem tinha comecado era CARA OU
     * COROA a cada execucao -- tres perguntas que o produto responde o tempo
     * todo (isRunning(), isFrozen(), getContestTime()) decididas por sorteio
     * no fixture.
     *
     * Este arquivo ja carregava a licao, escrita quando aconteceu com outra
     * coluna: `is_public` era boolean(50), e por isso qualquer teste que
     * agisse como visitante passava metade das vezes -- tres passavam, e so
     * apareceu quando a listagem comecou a honrar a flag (#135).
     *
     * Aconteceu de novo, pelo mesmo mecanismo. Enquanto o congelamento NAO
     * FAZIA NADA (#211), o sorteio nunca aparecia; assim que passou a
     * cortar, testes que semeavam placar sem run nenhum comecaram a falhar
     * -- quando a moeda caia em "congelado". O padrao e o que preocupa: o
     * fixture aleatorio esconde o bug ate o dia em que o codigo comeca a
     * funcionar. Um teste que passa por sorteio nao estava testando o que
     * dizia.
     *
     * O padrao agora e um contest que COMECOU AGORA: rodando, longe do
     * congelamento, com o relogio no zero. Quem precisa de outra coisa diz
     * qual -- running(), frozen(), notStarted(), finished().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'start_time' => now(),
            'duration' => 300,
            // Minutos ANTES do fim, entao o congelamento deste padrao comeca
            // aos 240 minutos -- quatro horas depois de now(). Nenhum teste
            // cai nele por acidente.
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            // Issue #135: was faker->boolean(50). is_public gates who may
            // see a contest's problems, so randomising it made any test that
            // acts as a guest pass about half the time -- three of them did,
            // and only showed it once the listing started honouring the flag.
            // Matches the column's own default; a test that wants a public
            // contest now says so.
            'is_public' => false,
            'unlock_key' => null,
        ];
    }

    /** Rodando, com o relogio ja andado: util para gravar contest_time. */
    public function running(int $elapsedMinutes = 30): static
    {
        return $this->state(fn () => [
            'is_active' => true,
            'start_time' => now()->subMinutes($elapsedMinutes),
            'duration' => 300,
            'freeze_time' => 60,
        ]);
    }

    /** Dentro da janela de congelamento, e ainda correndo. */
    public function frozen(): static
    {
        return $this->state(fn () => [
            'is_active' => true,
            'start_time' => now()->subMinutes(270),
            'duration' => 300,
            'freeze_time' => 60,
            'unfrozen_at' => null,
        ]);
    }

    public function notStarted(): static
    {
        return $this->state(fn () => [
            'is_active' => true,
            'start_time' => now()->addHour(),
            'duration' => 300,
        ]);
    }

    /** Acabou. Continua congelado ate alguem descongelar -- ver #189. */
    public function finished(): static
    {
        return $this->state(fn () => [
            'is_active' => true,
            'start_time' => now()->subMinutes(400),
            'duration' => 300,
            'freeze_time' => 60,
        ]);
    }
}
