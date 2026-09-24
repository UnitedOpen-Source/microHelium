<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Support\InterfaceLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Issue #397 -- o seletor de idioma grava a escolha e volta para a pagina.
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(InterfaceLocale::supported()))],
        ]);

        $request->session()->put(SetLocale::SESSION_KEY, $validated['locale']);

        return back();
    }
}
