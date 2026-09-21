<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Issue #266: o repositório afirmava duas licenças ao mesmo tempo. `LICENSE`
 * trazia o texto da GNU GPL v3 -- e era o que o GitHub reportava --, enquanto
 * `composer.json` e o `README.md` declaravam MIT, com o README mandando o
 * leitor "conferir no arquivo LICENSE", que dizia o contrário. `package.json`
 * não declarava nada, e o SRS registrava o campo como pendente justamente por
 * não poder escolher no lugar do projeto.
 *
 * O PR #278 alinhou as cinco fontes em AGPL-3.0-or-later. O que não existia
 * era qualquer coisa impedindo a divergência de voltar: as declarações são
 * editadas em arquivos diferentes, por motivos diferentes, e nada as
 * comparava entre si. Foi assim que MIT e GPL conviveram por anos.
 *
 * Este teste lê cada fonte no disco e exige que todas digam o mesmo. Ele não
 * decide qual é a licença -- se o projeto relicenciar de novo, a constante
 * abaixo muda junto com os arquivos, de propósito: a troca passa a ser um ato
 * único e visível em vez de cinco edições que podem ficar pela metade.
 */
class LicenseDeclarationsAgreeTest extends TestCase
{
    /**
     * O identificador SPDX que todas as fontes devem declarar.
     */
    private const SPDX = 'AGPL-3.0-or-later';

    /**
     * Primeira linha do texto canônico da licença correspondente ao SPDX
     * acima. É o que distingue AGPL de GPL: os dois textos são parecidos e
     * trocar um pelo outro não muda o tamanho do arquivo de forma óbvia.
     */
    private const LICENSE_TITLE = 'GNU AFFERO GENERAL PUBLIC LICENSE';

    public function test_arquivo_license_traz_o_texto_da_licenca_declarada()
    {
        $license = file_get_contents(base_path('LICENSE'));

        $this->assertStringContainsString(
            self::LICENSE_TITLE,
            $license,
            'O arquivo LICENSE não traz o texto da licença que o projeto declara.'
        );

        // A AGPL-3.0 é "Version 3, 19 November 2007"; a GPL-3.0 é
        // "Version 3, 29 June 2007". Sem esta checagem, um LICENSE truncado ou
        // com cabeçalho trocado passaria pela asserção de título acima.
        $this->assertStringContainsString(
            'Version 3, 19 November 2007',
            $license,
            'O LICENSE não traz a data de publicação da AGPL-3.0.'
        );

        // O texto canônico da AGPL-3.0 tem a cláusula de rede -- é ela, e não
        // o cabeçalho, que diferencia a licença na prática.
        $this->assertStringContainsString(
            'Remote Network Interaction',
            $license,
            'O LICENSE não contém a cláusula de rede (seção 13), que é o que '
            .'distingue a AGPL da GPL.'
        );
    }

    public function test_composer_json_declara_o_mesmo_spdx()
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);

        $this->assertSame(
            self::SPDX,
            $composer['license'] ?? null,
            'composer.json declara licença diferente do resto do repositório.'
        );
    }

    public function test_package_json_declara_o_mesmo_spdx()
    {
        $package = json_decode(file_get_contents(base_path('package.json')), true);

        $this->assertSame(
            self::SPDX,
            $package['license'] ?? null,
            'package.json declara licença diferente do resto do repositório.'
        );
    }

    public function test_readme_afirma_a_mesma_licenca_e_aponta_para_o_arquivo()
    {
        $readme = $this->normalizarEspacos(file_get_contents(base_path('README.md')));

        $this->assertStringContainsString(
            'GNU Affero General Public License v3.0 or later',
            $readme,
            'A seção License do README não nomeia a licença do projeto.'
        );

        $this->assertStringContainsString(
            '[LICENSE](LICENSE)',
            $readme,
            'O README deixou de apontar para o arquivo LICENSE.'
        );

        // A contradição original: o README dizia MIT enquanto mandava conferir
        // no LICENSE, que dizia GPL. Nomear outra licença aqui é o sintoma.
        $this->assertStringNotContainsString(
            'licensed under the MIT',
            $readme,
            'O README voltou a afirmar MIT.'
        );
    }

    public function test_srs_afirma_a_licenca_em_vez_de_registra_la_como_pendente()
    {
        $srs = $this->normalizarEspacos(
            file_get_contents(base_path('docs/software-spec/srs.md'))
        );

        $this->assertStringContainsString(
            'GNU '.self::SPDX,
            $srs,
            'O SRS não declara a licença do projeto.'
        );

        // O campo nasceu marcado como "pendente de resolução" porque as fontes
        // se contradiziam; voltar a esse estado significa que a contradição
        // voltou.
        $this->assertStringNotContainsString(
            'pendente de resolução',
            $srs,
            'O SRS voltou a registrar a licença como pendente de resolução.'
        );
    }

    /**
     * O README e o SRS quebram linha no meio das frases, então "or\nlater"
     * não casa com "or later" sem normalizar antes.
     */
    private function normalizarEspacos(string $texto): string
    {
        return preg_replace('/\s+/', ' ', $texto);
    }
}
