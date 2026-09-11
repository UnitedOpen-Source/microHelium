<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webcast export enabled
    |--------------------------------------------------------------------------
    |
    | Issue #44's spec ("Formato BOCA: evidencia e limite conhecido") is
    | explicit: the Animeitor consumer repo linked from the issue returned
    | 404 during research, so byte-format compatibility with any real
    | consumer has never been verified against a real fixture. Per the
    | spec's own instruction ("Ate isso, manter can_export=false"), this
    | stays false until that verification happens -- flipping it on is a
    | product/ops decision for whoever confirms the consumer, not something
    | this deploy should default to.
    |
    | The export endpoint and ZIP builder are fully implemented and tested
    | regardless of this flag (see tests/Feature/WebcastExportTest.php) --
    | this only gates the `can_export` capability the frontend reads, and
    | the export route itself refuses to serve when it's off.
    |
    */
    'export_enabled' => env('WEBCAST_EXPORT_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Maximum credential lifetime
    |--------------------------------------------------------------------------
    |
    | Ceiling (in days) on how far in the future a webcast credential's
    | expires_at may be set, per the spec's "validade futura com teto
    | configuravel".
    |
    */
    'max_credential_lifetime_days' => env('WEBCAST_MAX_CREDENTIAL_LIFETIME_DAYS', 30),

];
