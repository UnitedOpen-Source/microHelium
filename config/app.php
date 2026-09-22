<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */

    'name' => env('APP_NAME', 'MicroHelium'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Código-fonte desta instalação
    |--------------------------------------------------------------------------
    |
    | Issue #266 -- o projeto passou a AGPL-3.0-or-later, e a seção 13 dessa
    | licença obriga quem roda uma versão MODIFICADA e a oferece pela rede a
    | oferecer também o fonte dela a quem a usa.
    |
    | Por isso esta é uma configuração, e não um link fixo no template: o
    | endereço que cumpre a obrigação é o do fonte de QUEM ESTÁ RODANDO. Uma
    | instalação que aplicou patches e aponta para o repositório de origem
    | não está oferecendo o fonte da versão que a pessoa está usando.
    |
    | O padrão é o repositório canônico, que é a resposta certa para uma
    | instalação sem modificações.
    |
    */

    'source_url' => env('APP_SOURCE_URL', 'https://github.com/UnitedOpen-Source/microHelium'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
