<?php

return [

    /*
    |--------------------------------------------------------------------------
    | S.O.S. calls
    |--------------------------------------------------------------------------
    |
    | Issue #139 -- BOCA's S.O.S. button. A team at an on-site contest has no
    | other channel: the machine froze, the keyboard died, someone is unwell.
    | It goes to the staff of that team's site, which is a different queue,
    | with different people and a different urgency, from the clarification
    | queue the jury reads.
    |
    | There is deliberately no "cooldown" knob here. The button is never rate
    | limited into silence, because the one time it matters is the one time a
    | team cannot wait; what bounds the queue instead is that a team may hold
    | only one unresolved call at a time (the unique index in the migration).
    | The queue is therefore at most one row per team, however hard the button
    | is pressed, and a team whose call is still open does not need to press
    | it again -- it has not gone anywhere.
    |
    */

    // The team's own words, optional. Two hundred characters is enough for
    // "teclado quebrado, baia 14" and short enough that nobody writes an
    // essay instead of pressing the button. It is untrusted display data:
    // stored as typed, escaped at render, and never fed to the audit log.
    'note_max_length' => (int) env('SOS_NOTE_MAX_LENGTH', 200),

    // How many of their own past calls a team sees under the button.
    'history_limit' => (int) env('SOS_HISTORY_LIMIT', 10),

];
