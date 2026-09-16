<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #195 -- os cabecalhos que a Contest API exige, e a autenticacao
 * OPCIONAL que ela pressupoe.
 *
 * A spec pede `Access-Control-Allow-Origin: *`: as ferramentas que consomem
 * isto (resolver, analisadores, paineis) sao paginas servidas de outro
 * lugar, e sem o cabecalho o navegador delas nao le nada -- uma falha que
 * aparece so no dia do evento, no console de alguem.
 *
 * A autenticacao e opcional de proposito. A leitura e anonima por desenho:
 * um placar publico e um shadow externo nao tem conta aqui. O que a
 * autenticacao muda nao e o ACESSO, e se a resposta vem congelada ou nao --
 * por isso um token invalido nao pode derrubar a requisicao, so deixa o
 * leitor anonimo.
 */
class ClicsHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Resolve o usuario quando ha credencial, sem exigir nenhuma. O
        // guard do Sanctum devolve null para um token ausente ou invalido,
        // e null aqui significa "visitante", que e um estado legitimo.
        if ($request->bearerToken() !== null) {
            $request->setUserResolver(fn () => auth('sanctum')->user());
        }

        $response = $next($request);

        // Sem `Access-Control-Allow-Origin` aqui.
        //
        // A spec exige o cabecalho, e ele JA vem: a configuracao de CORS do
        // framework cobre `api/*` com `allowed_origins: ['*']`. Uma mutacao
        // mostrou que remover a linha daqui nao derrubava teste nenhum --
        // porque era uma segunda implementacao do mesmo cabecalho, nao uma
        // que faltava. Duas fontes para o mesmo header e como uma delas
        // muda sem ninguem notar.
        //
        // O REQUISITO continua fixado por teste
        // (ContestApiTest::test_cors_is_open_as_the_spec_requires), entao
        // se alguem publicar e estreitar config/cors.php a falha aparece
        // aqui em vez de aparecer no navegador de um consumidor.
        $response->headers->set('Cache-Control', 'no-cache');

        return $response;
    }
}
