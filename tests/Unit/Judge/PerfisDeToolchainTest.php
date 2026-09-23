<?php

namespace Tests\Unit\Judge;

use App\Support\Judge\DockerfileToolchain;
use App\Support\Judge\ToolchainProfile;
use PHPUnit\Framework\TestCase;

/**
 * Issue #306 -- a tabela de perfis do documento para de envelhecer em
 * silencio.
 *
 * ## O defeito, que e de forma e nao de conteudo
 *
 * `docs/specs/306-perfis-de-toolchain.md` traz uma tabela com cinco colunas
 * por perfil. Tres delas -- `Homologadas`, `Roda` e `Pacotes apk` -- sao
 * DERIVADAS do repositorio: quem as responde e o `ToolchainProfile`, a
 * partir do catalogo ativo e dos pinos do Dockerfile. As outras duas sao
 * medicao de fora (`apk add --simulate`).
 *
 * O perfil `completo` e o unico definido com `homologadas = null`, ou seja,
 * "o catalogo ativo inteiro, resolvido em tempo de execucao". Logo, TODA
 * entrada que passa a `is_active => true` muda a linha dele -- e entre
 * 18/09 e 23/09/2026 isso aconteceu tres vezes (`cs_dotnet10` no #358,
 * `gportugol` no #371, `java17` no #370). Na terceira, a tabela ja dizia
 * `48 | 48 | 38` para uma realidade de `51 | 51 | 40`, e nenhum teste
 * reprovava.
 *
 * O #365 entrou justamente para que o custo de cada perfil deixasse de ser
 * estimativa. Uma tabela medida que envelhece sem reprovar ninguem e o
 * comeco do caminho de volta: ela nao vira prosa vaga, vira numero errado
 * com cara de medido, que e pior.
 *
 * ## O que este teste NAO faz
 *
 * Ele nao mede tamanho. `Instalado` e `Acima da base` sao `apk add
 * --simulate` rodado numa imagem, com data e arquitetura registradas no
 * documento -- nenhum teste rapido alcanca isso, e fingir que alcanca seria
 * o modo de falha que este repositorio cataloga. O que este teste faz e
 * garantir que, quando as colunas derivadas mudarem, alguem seja OBRIGADO a
 * abrir o documento -- e a mensagem de falha manda remedir as outras duas na
 * mesma visita.
 *
 * E a mesma regra do `InventarioDeRuntimeTest` (#368) sobre a spec da #300:
 * o documento nao e prosa livre, e uma vista do que o codigo ja sabe.
 */
class PerfisDeToolchainTest extends TestCase
{
    private const DOCUMENTO = 'docs/specs/306-perfis-de-toolchain.md';

    private static function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * A linha da tabela de um perfil, ja separada em colunas.
     *
     * @return array{homologadas: int, roda: int, pacotes: int}|null
     */
    private static function linhaDoDocumento(string $perfil): ?array
    {
        $texto = (string) file_get_contents(self::raiz().'/'.self::DOCUMENTO);

        foreach (explode("\n", $texto) as $linha) {
            if (! str_starts_with(trim($linha), '| `'.$perfil.'`')) {
                continue;
            }

            $colunas = array_map('trim', explode('|', trim($linha, "| \t")));

            // | perfil | homologadas | roda | pacotes apk | instalado | acima |
            if (count($colunas) < 4) {
                return null;
            }

            return [
                'homologadas' => (int) $colunas[1],
                'roda' => (int) $colunas[2],
                'pacotes' => (int) preg_replace('/\D/', '', $colunas[3]),
            ];
        }

        return null;
    }

    public function test_a_tabela_do_documento_bate_com_o_que_os_perfis_resolvem(): void
    {
        $imagem = DockerfileToolchain::fromFile(
            self::raiz().'/Dockerfile.judge',
            self::raiz()
        );

        $divergencias = [];

        foreach (ToolchainProfile::all() as $nome => $perfil) {
            $documento = self::linhaDoDocumento($nome);

            if ($documento === null) {
                $divergencias[] = "{$nome}: o perfil existe no codigo e nao tem linha na tabela do documento";

                continue;
            }

            $real = [
                'homologadas' => count($perfil->homologated()),
                'roda' => count($perfil->runs()),
                'pacotes' => count($perfil->apkPackages($imagem)),
            ];

            foreach ($real as $coluna => $valor) {
                if ($documento[$coluna] !== $valor) {
                    $divergencias[] = "{$nome}: coluna `{$coluna}` diz {$documento[$coluna]} e o codigo resolve {$valor}";
                }
            }
        }

        $this->assertSame(
            [],
            $divergencias,
            'a tabela de '.self::DOCUMENTO." nao descreve mais o que os perfis resolvem:\n  "
            .implode("\n  ", $divergencias)."\n\n"
            ."Isto quase sempre significa que uma linguagem foi ligada ou desligada: o perfil\n"
            ."`completo` e o catalogo ativo inteiro, entao ele se move a cada `is_active`.\n\n"
            ."Ao corrigir a tabela, REMEDIR tambem as colunas `Instalado` e `Acima da base`,\n"
            ."que nenhum teste alcanca -- a receita esta no proprio documento:\n\n"
            ."    php artisan judge:toolchain-profile <perfil> --apk \\\n"
            ."      | xargs docker run --rm --network host php:8.3-cli-alpine \\\n"
            ."          apk add --simulate --no-cache\n"
        );
    }

    /**
     * O controle que impede o teste acima de ficar verde por estar vazio.
     *
     * Se um dia `ToolchainProfile::all()` voltar uma lista vazia -- ou se o
     * parser da tabela deixar de achar as linhas --, o laco acima nao teria o
     * que comparar e passaria. Esta assercao fixa o que a tabela precisa ter:
     * uma linha por perfil, e o `completo` entre elas.
     */
    public function test_todo_perfil_do_codigo_tem_linha_na_tabela(): void
    {
        $perfis = array_keys(ToolchainProfile::all());

        $this->assertNotEmpty($perfis, 'ToolchainProfile::all() voltou vazio');
        $this->assertContains('completo', $perfis, 'o perfil `completo` e o que a tabela usa como teto');

        foreach ($perfis as $nome) {
            $this->assertNotNull(
                self::linhaDoDocumento($nome),
                "o perfil `{$nome}` nao tem linha na tabela de ".self::DOCUMENTO
            );
        }
    }
}
