<?php

namespace App\Console\Commands;

use App\Support\Judge\DockerfileToolchain;
use App\Support\Judge\ToolchainProfile;
use App\Support\Judge\ToolchainRequirement;
use Illuminate\Console\Command;

/**
 * Issue #306, segundo passo -- o que um perfil manda instalar, dito em voz
 * alta.
 *
 * O manifesto do #353 ja sabia responder isso para o catalogo inteiro, mas
 * so por dentro: a informacao existia em PHP e nao saia de la. Este comando
 * e a saida. Com `--apk` ele imprime exatamente o argumento de `apk add` de
 * uma imagem sob medida -- a forma em que o dado e util para quem constroi,
 * e a forma em que ele pode ser MEDIDO sem construir nada:
 *
 *     php artisan judge:toolchain-profile maratona --apk \
 *       | xargs docker run --rm --network host php:8.3-cli-alpine \
 *           apk add --simulate --no-cache
 *
 * E foi assim que os numeros da docs/specs/306-perfis-de-toolchain.md foram
 * levantados. Construir as quatro imagens levaria horas; simular a
 * instalacao levou segundos, e responde a pergunta que a #306 faz -- quanto
 * custa cada perfil -- sem depender de ninguem ter lembrado de medir.
 *
 * O comando NAO constroi imagem e NAO escreve Dockerfile. Trocar o perfil de
 * build e o passo seguinte da #306, e depende de decisoes que a propria
 * issue lista como abertas (rejulgamento de prova antiga, mudanca de
 * linguagem na vespera, o ambiente de treino).
 */
class JudgeToolchainProfileCommand extends Command
{
    protected $signature = 'judge:toolchain-profile
        {profile? : O perfil (sem argumento, lista os que existem)}
        {--apk : So a lista de pacotes apk, numa linha, pronta para `apk add`}
        {--json : Tudo que o perfil resolve, em JSON}
        {--corta : O que o Dockerfile.judge deixa de instalar com este perfil (docker/judge/perfil/<perfil>.corta)}
        {--dockerfile=Dockerfile.judge : De qual imagem ler os pinos de versao}';

    protected $description = 'Resolve um perfil de linguagens homologadas na lista de instalacao correspondente';

    public function handle(): int
    {
        $profiles = ToolchainProfile::all();
        $name = (string) ($this->argument('profile') ?? '');

        if ($name === '') {
            return $this->listProfiles($profiles);
        }

        $profile = ToolchainProfile::named($name);

        if ($profile === null) {
            $this->error("Perfil desconhecido: {$name}. Conhecidos: ".implode(', ', array_keys($profiles)).'.');

            return self::FAILURE;
        }

        $path = base_path((string) $this->option('dockerfile'));

        if (! is_file($path)) {
            $this->error("Nao achei o Dockerfile: {$path}");

            return self::FAILURE;
        }

        $image = DockerfileToolchain::fromFile($path, base_path());

        if ($this->option('corta') === true) {
            foreach ($profile->cuts() as $cut) {
                $this->line($cut);
            }

            return self::SUCCESS;
        }

        if ($this->option('apk') === true) {
            $this->line(implode(' ', $profile->apkPackages($image)));

            return self::SUCCESS;
        }

        if ($this->option('json') === true) {
            $this->line((string) json_encode($this->payload($profile, $image), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        return $this->report($profile, $image);
    }

    /**
     * @param  array<string, ToolchainProfile>  $profiles
     */
    private function listProfiles(array $profiles): int
    {
        $rows = [];

        foreach ($profiles as $profile) {
            $rows[] = [
                $profile->name,
                count($profile->homologated()),
                count($profile->runs()),
                $profile->why,
            ];
        }

        $this->table(['perfil', 'homologadas', 'roda', 'por que existe'], $rows);
        $this->line('Detalhe de um: php artisan judge:toolchain-profile <perfil>');

        return self::SUCCESS;
    }

    private function report(ToolchainProfile $profile, DockerfileToolchain $image): int
    {
        $this->info("Perfil `{$profile->name}` -- {$profile->why}");
        $this->newLine();

        $this->line('Homologadas ('.count($profile->homologated()).'): '.implode(' ', $profile->homologated()));

        if ($profile->free() !== []) {
            $this->line('Vem junto ('.count($profile->free()).'): '.implode(' ', $profile->free()));
        }

        $this->newLine();

        foreach ($profile->requirementsByKind() as $kind => $requirements) {
            $targets = array_map(
                fn (ToolchainRequirement $requirement): string => $kind === ToolchainRequirement::APK
                    ? $this->pinned($requirement, $image)
                    : $requirement->target,
                $requirements,
            );

            $this->line(str_pad($kind, 12).' '.implode(' ', $targets));
        }

        $left = $profile->apkPackagesLeftOut($image);

        $this->newLine();
        $this->line('Pacotes apk deste perfil: '.count($profile->apkPackages($image)));
        $this->line('Pacotes apk que o catalogo ativo manda instalar e este perfil nao: '.count($left));

        if ($left !== []) {
            $this->line('  '.implode(' ', $left));
        }

        return self::SUCCESS;
    }

    private function pinned(ToolchainRequirement $requirement, DockerfileToolchain $image): string
    {
        return $image->specFor($requirement);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ToolchainProfile $profile, DockerfileToolchain $image): array
    {
        $requirements = [];

        foreach ($profile->requirementsByKind() as $kind => $group) {
            $requirements[$kind] = array_map(
                fn (ToolchainRequirement $requirement): string => $requirement->target,
                $group,
            );
        }

        return [
            'perfil' => $profile->name,
            'por_que' => $profile->why,
            'homologadas' => $profile->homologated(),
            'vem_junto' => $profile->free(),
            'roda' => $profile->runs(),
            'exigencias' => $requirements,
            'apk' => $profile->apkPackages($image),
            'apk_de_fora' => $profile->apkPackagesLeftOut($image),
            'pinos_lidos_de' => $image->name,
        ];
    }
}
