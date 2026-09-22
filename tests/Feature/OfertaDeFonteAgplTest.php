<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #266 -- a seção 13 da AGPL-3.0 tem de valer na tela, e não só no
 * `LICENSE`.
 *
 * A migração para AGPL-3.0-or-later foi feita nos arquivos que declaram a
 * licença, e `LicenseDeclarationsAgreeTest` guarda os cinco para que não
 * voltem a se contradizer. Sobrou a obrigação que a licença nova cria e a
 * anterior não criava:
 *
 * > if you modify the Program, your modified version must prominently offer
 * > all users interacting with it remotely through a computer network [...]
 * > an opportunity to receive the Corresponding Source of your version
 *
 * Um juiz online é exatamente "interacting with it remotely": a equipe usa o
 * sistema pelo navegador e nunca recebe binário nenhum. Sem a oferta na
 * página, uma instalação modificada do microHelium descumpre a própria
 * licença que o repositório passou a declarar.
 *
 * O teste renderiza as páginas em vez de ler o Blade. Uma asserção sobre o
 * texto do template passaria com o rodapé dentro de um `@if` que nunca é
 * verdadeiro, ou num layout que nenhuma página estende -- e a oferta que
 * ninguém vê não é oferta.
 */
class OfertaDeFonteAgplTest extends TestCase
{
    /**
     * O login é a primeira tela de quem chega, e já é uso pela rede.
     */
    public function test_a_tela_de_login_oferece_o_fonte_e_nomeia_a_licenca(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('AGPL-3.0-or-later', $html);
        $this->assertStringContainsString(config('app.source_url'), $html);
    }

    /**
     * E o layout de dentro, que é onde a equipe passa a prova inteira.
     */
    public function test_a_pagina_de_ajuda_oferece_o_fonte_e_nomeia_a_licenca(): void
    {
        $html = $this->get('/ajuda')->assertOk()->getContent();

        $this->assertStringContainsString('AGPL-3.0-or-later', $html);
        $this->assertStringContainsString(config('app.source_url'), $html);
    }

    /**
     * O que a seção 13 pede é o fonte DESTA instalação, não o do projeto de
     * origem: quem aplicou patches e aponta para o repositório canônico está
     * oferecendo o fonte de outra coisa.
     *
     * Sem esta asserção o teste passaria com a URL escrita no template, que
     * é justamente o que não cumpre a obrigação para quem modifica.
     */
    public function test_a_oferta_segue_o_fonte_configurado_e_nao_um_link_fixo(): void
    {
        config(['app.source_url' => 'https://exemplo.invalido/meu-fork']);

        foreach (['/login', '/ajuda'] as $rota) {
            $html = $this->get($rota)->assertOk()->getContent();

            $this->assertStringContainsString(
                'https://exemplo.invalido/meu-fork',
                $html,
                "A oferta em {$rota} ignora `app.source_url`: uma instalação modificada não consegue apontar para o fonte dela."
            );
            $this->assertStringNotContainsString(
                'github.com/UnitedOpen-Source/microHelium',
                $html,
                "A oferta em {$rota} mantém o repositório de origem mesmo com outro fonte configurado."
            );
        }
    }

    /**
     * O padrão serve a instalação sem modificação, que é a maioria.
     */
    public function test_o_padrao_aponta_para_o_repositorio_canonico(): void
    {
        $this->assertSame(
            'https://github.com/UnitedOpen-Source/microHelium',
            config('app.source_url')
        );
    }
}
