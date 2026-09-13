<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Print queue
    |--------------------------------------------------------------------------
    |
    | Issue #94 -- a team sends a file, the staff at their site pick it up
    | and print it. This is how printing works at an on-site contest: the
    | competitor has no printer at the desk.
    |
    | The size cap and the extension allowlist are both deliberate. A five
    | hour contest with two hundred teams is a queue anyone can flood, and
    | "print this" is not a reason to accept arbitrary binaries onto the
    | staff machine that opens them.
    |
    */

    'max_file_size_kb' => (int) env('PRINT_MAX_FILE_SIZE_KB', 512),

    // Source files and plain documents. Extensions, not MIME: the file is
    // never executed and never served inline, so what matters is that a
    // staff member opening it in a printer dialog gets something printable.
    'allowed_extensions' => array_filter(explode(',', (string) env(
        'PRINT_ALLOWED_EXTENSIONS',
        'txt,pdf,c,cc,cpp,cxx,h,hpp,java,py,js,ts,kt,cs,rs,go,php,rb,pas,md'
    ))),

];
