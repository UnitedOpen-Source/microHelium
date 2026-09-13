<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lease
    |--------------------------------------------------------------------------
    |
    | Issue #53 -- how long a judge machine may hold a run before the server
    | takes it back.
    |
    | DOMjudge has no equivalent: its give-back runs when a judgehost
    | re-registers, which covers a host that comes back and not one that
    | dies. DOMjudge#2476 is what the gap looks like in practice -- a World
    | Finals judging that stayed assigned to a crashed host and needed a
    | manual rejudge.
    |
    | The value has to exceed the longest a legitimate judging can take: a
    | problem's time limit times its test-case count, plus compilation. Ten
    | minutes is comfortable for the contests this is sized for; raise it
    | before lowering it, because expiring a live judging hands the same run
    | to a second machine.
    |
    */
    'lease_seconds' => (int) env('JUDGEHOST_LEASE_SECONDS', 600),

];
