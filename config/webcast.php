<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webcast export enabled
    |--------------------------------------------------------------------------
    |
    | Issue #44's spec held this false behind an integration gate: the
    | Animeitor consumer repository linked from the issue returned 404, so
    | byte-format compatibility had never been verified and the spec said
    | "ate isso, manter can_export=false".
    |
    | That gate is closed. The consumer is wuerges/maratona-animeitor-rust,
    | and a ZIP from BocaWebcastZipBuilder was fed to its own loader --
    | compiled from source at commit
    | 555ba636e39da5585768218a1ac164666672ac0c, not to a reimplementation --
    | which parsed it: the contest name, the timing parameters, both teams,
    | the problem count, and all eight runs with Y/X/N/? mapping to
    | Yes/Unk/No/Wait. The details are in the PR that flipped this.
    |
    | Still an env override, because turning the export on is a decision
    | about disclosure for a given deployment, not only about format.
    |
    */
    'export_enabled' => env('WEBCAST_EXPORT_ENABLED', true),

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
