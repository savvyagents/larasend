<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Open Registration
    |--------------------------------------------------------------------------
    |
    | Registration is always available while the instance has no users, so
    | the first person to visit can create the owner account. After that,
    | new members are invited from workspace settings. Set this to true to
    | keep public self-registration open after the first user exists.
    |
    */

    'open_registration' => env('LARASEND_OPEN_REGISTRATION', false),

    /*
    |--------------------------------------------------------------------------
    | Marketing Landing Page
    |--------------------------------------------------------------------------
    |
    | Most self-hosted installs run on a private or internal-only domain, so
    | there's no reason for an anonymous visitor to land on public marketing
    | copy there — they should go straight to sign-in (or setup, if nobody
    | owns the instance yet). Set this to true only for a public-facing
    | instance that intentionally serves as the project's marketing site.
    |
    */

    'show_landing_page' => env('LARASEND_SHOW_LANDING_PAGE', false),

];
