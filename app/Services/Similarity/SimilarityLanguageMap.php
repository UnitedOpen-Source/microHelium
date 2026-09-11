<?php

namespace App\Services\Similarity;

use App\Models\Language;

/**
 * Maps this codebase's Language::extension values (see
 * Language::getDefaultLanguages()) to JPlag's CLI language identifiers, as
 * documented in the JPlag v6.2.0 README's "Supported Languages" table
 * (https://github.com/jplag/JPlag/blob/v6.2.0/README.md, verified
 * 2026-09-11). A language absent from this map has no JPlag parser and is
 * rejected with a 422 on language_id rather than silently attempted.
 *
 * Only languages with a "mature" or "beta" JPlag parser are listed; legacy
 * (`c`, `scheme`) and non-programming-language parsers (EMF, SCXML, text)
 * are omitted even though JPlag ships them, since this codebase's C
 * languages compile against a newer standard than JPlag's legacy C parser
 * targets and comparing raw text would produce noise, not signal.
 */
class SimilarityLanguageMap
{
    private const MAP = [
        'cpp_gpp13' => 'cpp',
        'cpp14_gpp' => 'cpp',
        'cpp17_gpp' => 'cpp',
        'cpp_clang' => 'cpp',
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
        'cs_dotnet' => 'csharp',
        'cs_mono' => 'csharp',
        'rs' => 'rust',
        'go' => 'golang',
        'swift' => 'swift',
        'r' => 'rlang',
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
