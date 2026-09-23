<?php

namespace App\Services\Similarity;

use App\Models\Language;

/**
 * Maps this codebase's Language::extension values (see
 * Language::getDefaultLanguages()) to JPlag's CLI language identifiers. A
 * language absent from this map has no usable JPlag parser and is rejected
 * with a 422 on language_id rather than silently attempted.
 *
 * The identifiers below are the ones `java -jar jplag.jar --help` prints for
 * the pinned 6.2.0 jar, and not the prose of the README. That distinction is
 * not pedantry: this map shipped `golang` from the day it was written (#74,
 * 2026-09-11). That is what the README's table calls Go, and it is NOT a
 * language JPlag accepts -- every Go check died with `Language golang does not
 * exists`. Since 2026-09-23
 * SimilarityLanguageCoverageTest holds the CLI's own list, so a name that only
 * exists in documentation cannot get back in.
 *
 * ## The two parsers JPlag calls "legacy", measured rather than assumed
 *
 * This docblock used to say that `c` and `scheme` were left out because this
 * codebase compiles C against a newer standard than the legacy parser targets.
 * That was a guess, and measurement (JPlag 6.2.0, aarch64, 2026-09-23) does not
 * support it:
 *
 * - `-l c` parsed contest-shaped C17 with zero ANTLR errors -- designated
 *   initializers, declarations inside `for`, VLAs, `restrict`, `[static 1]`,
 *   `_Generic` and `_Static_assert` included -- and scored a renamed-identifier
 *   copy at 1.000 (39-token longest match) against 0.000 for an unrelated
 *   solution to another problem;
 * - `-l cpp` over the same C files is the option that fails: the C99 designated
 *   initializer produces `extraneous input '.'` and the parser recovers with
 *   fewer tokens. Routing C through the C++ parser would be the measurably
 *   worse choice, so `c` it is;
 * - `-l scheme` parsed Guile source cleanly and discriminated the same way
 *   (1.000 against 0.000, 33-token match), so `scm` is mapped too.
 *
 * ## Why Racket stays out, and it is not about syntax quality
 *
 * JPlag's scheme parser only picks up files ending in `.scm`/`.ss`, and
 * JplagSimilarityEngine writes each submission as `submission.{file_ext}` --
 * `submission.rkt` for Racket. Measured: every submission comes back as
 * "Nothing to parse", and the run ends in `Not enough valid submissions!`, i.e.
 * a failed check rather than a missing one. Renaming would not save it either:
 * `#lang racket` on line 1 is a lexical error for that parser. Mapping `rkt`
 * would turn a language that is quietly not analysed into one that fails
 * loudly for the operator, which is worse.
 *
 * The non-programming-language parsers JPlag ships (EMF, SCXML, text) stay out
 * as before: comparing raw text would produce noise, not signal.
 */
class SimilarityLanguageMap
{
    private const MAP = [
        // C -- the three entries share `file_ext` `c`, so the sibling rule in
        // SimilarityLanguageCoverageTest could not see them missing: with none
        // of them mapped there was no sibling to hold them to. Measured in the
        // docblock above.
        'c_gcc13' => 'c',
        'c99_gcc' => 'c',
        'c_clang17' => 'c',
        'cpp_gpp13' => 'cpp',
        'cpp14_gpp' => 'cpp',
        'cpp17_gpp' => 'cpp',
        'cpp_clang' => 'cpp',
        'java25' => 'java',
        'java21' => 'java',
        'java17' => 'java',
        'py3' => 'python3',
        'py2' => 'python3',
        'pypy3' => 'python3',
        'js_node24' => 'javascript',
        'js_node22' => 'javascript',
        'js_node20' => 'javascript',
        'ts' => 'typescript',
        'kt' => 'kotlin',
        'scala' => 'scala',
        'cs_dotnet10' => 'csharp',
        'cs_dotnet' => 'csharp',
        'cs_mono' => 'csharp',
        'rs' => 'rust',
        // `go`, not `golang`: the README's table title is not the CLI's
        // identifier, and JPlag refuses the latter outright.
        'go' => 'go',
        'swift' => 'swift',
        'r' => 'rlang',
        // Guile only. Racket (`rkt`) is deliberately absent -- see the docblock.
        'scm' => 'scheme',
    ];

    public static function jplagLanguageFor(Language $language): ?string
    {
        return self::MAP[$language->extension] ?? null;
    }

    public static function isSupported(Language $language): bool
    {
        return self::jplagLanguageFor($language) !== null;
    }
}
