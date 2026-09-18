<?php

namespace Tests\Support;

use ZipArchive;

/**
 * Issue #268 -- monta um `.sb3` a partir de código, e não de um blob.
 *
 * Um `.sb3` é um ZIP com um `project.json` dentro. Guardar um binário de
 * terceiro no repositório para testar Scratch tornaria o teste impossível de
 * revisar: ninguém sabe o que um `.sb3` faz sem abrir o editor. Montado
 * aqui, o programa que está sendo julgado é legível na revisão -- que é o
 * ponto de o teste existir.
 *
 * A convenção de entrada e saída é a do `scratch-run` (VNOJ):
 *
 *     ask [read_token] and wait   lê um token separado por espaço
 *     ask [outra coisa] and wait  lê uma linha inteira
 *     say [texto]                 escreve com quebra de linha
 *     think [texto]               escreve sem quebra
 *
 * O andaime (palco, sprite, meta) veio de `tests/echo.json` do próprio
 * `scratch-run`, sem as listas de costume e som: os arquivos de mídia não
 * estão no pacote, e a VM apenas avisa e segue. Manter as listas vazias
 * evita o aviso e não muda o programa.
 */
class ScratchProject
{
    /**
     * Lê dois tokens do stdin e diz a soma -- o "A + B" das outras
     * linguagens da suíte.
     */
    public static function sumOfTwoTokens(): string
    {
        $var = 'primeiro-valor';

        return self::zip(self::project([
            'quando' => [
                'opcode' => 'event_whenflagclicked',
                'next' => 'le-a', 'parent' => null,
                'inputs' => (object) [], 'fields' => (object) [],
                'shadow' => false, 'topLevel' => true, 'x' => 0, 'y' => 0,
            ],
            'le-a' => [
                'opcode' => 'sensing_askandwait',
                'next' => 'guarda-a', 'parent' => 'quando',
                'inputs' => ['QUESTION' => [1, [10, 'read_token']]],
                'fields' => (object) [], 'shadow' => false, 'topLevel' => false,
            ],
            'guarda-a' => [
                'opcode' => 'data_setvariableto',
                'next' => 'le-b', 'parent' => 'le-a',
                'inputs' => ['VALUE' => [3, 'resposta-a', [10, '']]],
                'fields' => ['VARIABLE' => ['a', $var]],
                'shadow' => false, 'topLevel' => false,
            ],
            'resposta-a' => [
                'opcode' => 'sensing_answer',
                'next' => null, 'parent' => 'guarda-a',
                'inputs' => (object) [], 'fields' => (object) [],
                'shadow' => false, 'topLevel' => false,
            ],
            'le-b' => [
                'opcode' => 'sensing_askandwait',
                'next' => 'diz', 'parent' => 'guarda-a',
                'inputs' => ['QUESTION' => [1, [10, 'read_token']]],
                'fields' => (object) [], 'shadow' => false, 'topLevel' => false,
            ],
            'diz' => [
                'opcode' => 'looks_say',
                'next' => null, 'parent' => 'le-b',
                'inputs' => ['MESSAGE' => [3, 'soma', [10, '']]],
                'fields' => (object) [], 'shadow' => false, 'topLevel' => false,
            ],
            'soma' => [
                'opcode' => 'operator_add',
                'next' => null, 'parent' => 'diz',
                'inputs' => [
                    'NUM1' => [3, 'le-a-valor', [4, '']],
                    'NUM2' => [3, 'resposta-b', [4, '']],
                ],
                'fields' => (object) [], 'shadow' => false, 'topLevel' => false,
            ],
            'le-a-valor' => [
                'opcode' => 'data_variable',
                'next' => null, 'parent' => 'soma',
                'inputs' => (object) [],
                'fields' => ['VARIABLE' => ['a', $var]],
                'shadow' => false, 'topLevel' => false,
            ],
            'resposta-b' => [
                'opcode' => 'sensing_answer',
                'next' => null, 'parent' => 'soma',
                'inputs' => (object) [], 'fields' => (object) [],
                'shadow' => false, 'topLevel' => false,
            ],
        ], [$var => ['a', 0]]));
    }

    /**
     * Um projeto que responde SEMPRE a mesma coisa -- o WA da suíte.
     */
    public static function alwaysSaysZero(): string
    {
        return self::zip(self::project([
            'quando' => [
                'opcode' => 'event_whenflagclicked',
                'next' => 'diz', 'parent' => null,
                'inputs' => (object) [], 'fields' => (object) [],
                'shadow' => false, 'topLevel' => true, 'x' => 0, 'y' => 0,
            ],
            'diz' => [
                'opcode' => 'looks_say',
                'next' => null, 'parent' => 'quando',
                'inputs' => ['MESSAGE' => [1, [10, '0']]],
                'fields' => (object) [], 'shadow' => false, 'topLevel' => false,
            ],
        ]));
    }

    /**
     * Um laço sem fim -- o TLE da suíte.
     */
    public static function loopsForever(): string
    {
        return self::zip(self::project([
            'quando' => [
                'opcode' => 'event_whenflagclicked',
                'next' => 'sempre', 'parent' => null,
                'inputs' => (object) [], 'fields' => (object) [],
                'shadow' => false, 'topLevel' => true, 'x' => 0, 'y' => 0,
            ],
            'sempre' => [
                'opcode' => 'control_forever',
                'next' => null, 'parent' => 'quando',
                'inputs' => ['SUBSTACK' => [2, 'nada']],
                'fields' => (object) [], 'shadow' => false, 'topLevel' => false,
            ],
            'nada' => [
                'opcode' => 'data_setvariableto',
                'next' => null, 'parent' => 'sempre',
                'inputs' => ['VALUE' => [1, [10, '1']]],
                'fields' => ['VARIABLE' => ['a', 'v']],
                'shadow' => false, 'topLevel' => false,
            ],
        ], ['v' => ['a', 0]]));
    }

    /**
     * Um ZIP que não é um projeto -- o CE da suíte, que vem do `--check`.
     */
    public static function notAProject(): string
    {
        $path = self::temporary();

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('project.json', '{"isto":"nao e um projeto scratch"}');
        $zip->close();

        return $path;
    }

    /**
     * @param  array<string, mixed>  $blocks
     * @param  array<string, array{0: string, 1: int|string}>  $variables
     * @return array<string, mixed>
     */
    private static function project(array $blocks, array $variables = []): array
    {
        return [
            'targets' => [
                [
                    'isStage' => true, 'name' => 'Stage',
                    'variables' => $variables === [] ? (object) [] : $variables,
                    'lists' => (object) [], 'broadcasts' => (object) [], 'comments' => (object) [],
                    // O palco tambem precisa de `blocks`, mesmo vazio: o
                    // esquema do SB3 o exige em TODO target, e sem ele o
                    // projeto inteiro e recusado com
                    // "should have required property 'blocks'".
                    'blocks' => (object) [],
                    'currentCostume' => 0, 'costumes' => [], 'sounds' => [],
                    'volume' => 100, 'layerOrder' => 0, 'tempo' => 60,
                    'videoTransparency' => 50, 'videoState' => 'off', 'textToSpeechLanguage' => null,
                ],
                [
                    'isStage' => false, 'name' => 'Sprite1',
                    'variables' => (object) [], 'lists' => (object) [],
                    'broadcasts' => (object) [], 'comments' => (object) [],
                    'currentCostume' => 0, 'costumes' => [], 'sounds' => [],
                    'blocks' => $blocks,
                    'volume' => 100, 'layerOrder' => 1, 'visible' => true,
                    'x' => 0, 'y' => 0, 'size' => 100, 'direction' => 90,
                    'draggable' => false, 'rotationStyle' => 'all around',
                ],
            ],
            'monitors' => [],
            'extensions' => [],
            'meta' => ['semver' => '3.0.0', 'vm' => '0.2.0', 'agent' => 'microHelium test'],
        ];
    }

    /**
     * @param  array<string, mixed>  $project
     */
    private static function zip(array $project): string
    {
        $path = self::temporary();

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('project.json', (string) json_encode($project, JSON_UNESCAPED_SLASHES));
        $zip->close();

        return $path;
    }

    private static function temporary(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mh-sb3-').'.sb3';
        touch($path);

        return $path;
    }
}
