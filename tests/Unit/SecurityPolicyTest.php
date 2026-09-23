<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Issue #398: até aqui, quem achasse uma fuga do sandbox do juiz e seguisse o
 * README mandaria o relato para `security@example.com` -- um endereço de
 * exemplo, que não entrega para ninguém. Num projeto que executa código de
 * terceiros, esse era o único canal documentado.
 *
 * O canal passou a ser o reporte privado de vulnerabilidade do GitHub,
 * descrito em SECURITY.md. Este teste impede as duas regressões que
 * recolocariam o problema: o README voltar a apontar para um endereço de
 * exemplo, ou SECURITY.md perder o link do canal.
 *
 * O que ele não consegue verificar é se o reporte privado está LIGADO nas
 * configurações do repositório -- isso não mora no disco. Está ligado desde
 * a #398; se alguém desligar, o link de SECURITY.md passa a dar 404.
 */
class SecurityPolicyTest extends TestCase
{
    private const CANAL = 'https://github.com/UnitedOpen-Source/microHelium/security/advisories/new';

    public function test_security_md_aponta_para_o_reporte_privado()
    {
        $politica = file_get_contents(base_path('SECURITY.md'));

        $this->assertStringContainsString(
            self::CANAL,
            $politica,
            'SECURITY.md deixou de apontar para o reporte privado de vulnerabilidade.'
        );
    }

    public function test_readme_encaminha_para_security_md_e_nao_para_endereco_de_exemplo()
    {
        $readme = file_get_contents(base_path('README.md'));

        $this->assertStringContainsString(
            '[SECURITY.md](SECURITY.md)',
            $readme,
            'A seção Security do README deixou de encaminhar para SECURITY.md.'
        );

        $this->assertStringNotContainsString(
            '@example.com',
            $readme,
            'O README voltou a indicar um endereço de exemplo como canal de contato.'
        );
    }
}
