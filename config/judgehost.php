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

    /*
    |--------------------------------------------------------------------------
    | Agent
    |--------------------------------------------------------------------------
    |
    | Issue #116 -- the side of #53 that runs on the judge machine.
    |
    | `server` and `token` are the only two things a partner institution has
    | to configure, and deliberately the only two: the agent speaks HTTP to
    | the server and nothing else. It needs no database credentials -- #119
    | measured that judging a run issues no queries at all -- which is what
    | makes it safe to hand a machine to someone else's rack.
    |
    | The token comes from the environment rather than the command line, so
    | it does not sit in `ps` output for every user on the box.
    |
    */
    'agent' => [
        'server' => rtrim((string) env('JUDGEHOST_SERVER', ''), '/'),
        'token' => env('JUDGEHOST_TOKEN', ''),

        // 204 from fetch-work is the server saying "nothing to do", not an
        // error, and the agent's answer is to ask less often. Without a
        // backoff, fifty idle hosts are fifty requests a second against the
        // server for the whole quiet stretch before a contest starts.
        'poll_min_seconds' => (float) env('JUDGEHOST_POLL_MIN_SECONDS', 1),
        'poll_max_seconds' => (float) env('JUDGEHOST_POLL_MAX_SECONDS', 30),

        // Where fetched work lands. Test cases and package scripts are kept
        // by digest, so a host judging 200 submissions of one problem
        // downloads its test data once.
        'workspace' => env('JUDGEHOST_WORKSPACE', 'judgehost'),

        'request_timeout' => (int) env('JUDGEHOST_REQUEST_TIMEOUT', 30),
    ],

];
