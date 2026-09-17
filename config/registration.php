<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auto-cadastro
    |--------------------------------------------------------------------------
    |
    | Issue #275. Ligado, `/register` aceita cadastro publico -- e a conta
    | nasce DESABILITADA, sem sessao, esperando liberacao da organizacao em
    | /backend/users.
    |
    | Desligado, a rota responde 404 e o link desaparece da tela de login. E
    | o que uma instalacao que so aceita contas criadas pela organizacao quer:
    | /backend/users, `teams:import` e a conta gerenciada do #47 continuam
    | sendo os caminhos.
    |
    | O padrao e `true` porque a rota ja existia e e linkada na tela de
    | login: fechar por omissao mudaria o comportamento de quem ja usa. Quem
    | quer fechar, fecha.
    |
    */

    'open' => (bool) env('REGISTRATION_OPEN', true),

];
