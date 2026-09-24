<?php

namespace App\Http\Middleware;

use App\Support\InterfaceLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #397 -- o idioma que a pessoa escolheu no seletor.
 *
 * Fica na sessao, e nao num cookie proprio nem no usuario: funciona antes do
 * login (o seletor esta na tela de entrada) e sobrevive a ele, porque o login
 * regenera a sessao sem descarta-la. Idioma por usuario e o passo seguinte da
 * issue, com este como fallback.
 *
 * Sem negociacao por `Accept-Language`, de proposito: uma instalacao
 * brasileira cujos navegadores estao em ingles passaria a mostrar a interface
 * num idioma que ainda nao tem traducao.
 */
class SetLocale
{
    public const SESSION_KEY = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $locale = $request->session()->get(self::SESSION_KEY);

            if (InterfaceLocale::isSupported($locale)) {
                app()->setLocale($locale);
            }
        }

        return $next($request);
    }
}
