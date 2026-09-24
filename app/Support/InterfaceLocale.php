<?php

namespace App\Support;

/**
 * Issue #397 -- os idiomas da interface, e o que o navegador precisa saber
 * deles.
 *
 * O portugues e o idioma-fonte: a chave de cada texto nos arquivos JSON de
 * resources/lang e o proprio texto em pt_BR, entao pt_BR nao tem arquivo
 * JSON -- a chave ja e a traducao. Os outros idiomas tem dois:
 *
 *   resources/lang/<idioma>.json           o que so o Blade e o PHP usam
 *   resources/lang/frontend/<idioma>.json  o que o Vue usa
 *
 * O segundo e registrado no tradutor com `addJsonPath()` (ver
 * AppServiceProvider), entao o Blade enxerga os dois. So ele vai ao
 * navegador, dentro do layout: mandar o catalogo inteiro em toda pagina
 * cresceria com cada tela migrada, e o Vue usa uma fracao dele.
 */
final class InterfaceLocale
{
    /**
     * @return array<string, string> codigo => nome no proprio idioma
     */
    public static function supported(): array
    {
        /** @var array<string, string> $locales */
        $locales = config('app.supported_locales', []);

        return $locales;
    }

    public static function isSupported(mixed $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, self::supported());
    }

    /** O valor do atributo `lang` do HTML: BCP 47 usa hifen, o Laravel usa `_`. */
    public static function htmlLang(?string $locale = null): string
    {
        return str_replace('_', '-', $locale ?? app()->getLocale());
    }

    public static function frontendCatalogPath(string $locale): string
    {
        return lang_path('frontend/'.$locale.'.json');
    }

    /**
     * O catalogo que o `t()` de resources/js/i18n.js le.
     *
     * @return array<string, string>
     */
    public static function frontendCatalog(?string $locale = null): array
    {
        $path = self::frontendCatalogPath($locale ?? app()->getLocale());

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
