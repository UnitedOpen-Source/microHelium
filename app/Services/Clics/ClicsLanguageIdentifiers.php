<?php

namespace App\Services\Clics;

/**
 * Issue #333 -- o identificador CLICS de cada linguagem do catalogo.
 *
 * ## Por que existe
 *
 * O `languages` da Contest API emitia `id` = autoincremento de `languages`,
 * que e uma coluna `contest_id`-scoped: a MESMA linguagem tem id diferente
 * em cada prova, e `id: "3"` e Java numa e Rust na seguinte.
 *
 * A spec (2023-06, "JSON property types") nao deixa margem:
 *
 *   "IDs are assigned by the person or system that is the source of the
 *    object, and must be maintained by downstream systems."
 *
 * E a secao "Known languages":
 *
 *   "Below is a list of standardized identifiers for known languages [...]
 *    When providing one of these languages, the corresponding identifier
 *    should be used. [...] In case multiple versions of a language are
 *    provided, those must have separate, unique identifiers. It is
 *    recommended to choose new identifiers with a suffix appended to an
 *    existing one. For example `cpp17` to specify the ISO 2017 version of
 *    C++."
 *
 * E o que esta tabela faz: `cpp_gpp13` (nosso slug interno) vira `cpp`,
 * `cpp17_gpp` vira `cpp17`, `java21` vira `java21`, `py3` vira `python3`.
 *
 * ## Por que uma tabela aqui e nao uma coluna em `languages`
 *
 * Uma coluna `clics_id` exigiria migracao mais preenchimento a partir do
 * catalogo, e o catalogo (`Language::getDefaultLanguages()`) ja e a fonte da
 * verdade sobre quais linguagens existem. O que faltava nao era onde
 * guardar: era a traducao para o vocabulario da spec, que e assunto da
 * camada CLICS e de mais nenhuma. Guardar a traducao junto do tradutor
 * mantem a decisao num arquivo so, e um slug que nao esteja aqui continua
 * funcionando (cai no proprio slug).
 *
 * ## A regra da instalacao que criou uma linguagem propria
 *
 * Fallback e o proprio slug -- estavel entre provas, que e a propriedade que
 * faltava. Nao-conforme com "Known languages" (que diz *should*), e
 * conforme com a definicao de ID (que diz *must*).
 */
class ClicsLanguageIdentifiers
{
    /**
     * Slug interno (`languages.extension`) => identificador CLICS.
     *
     * Os identificadores da tabela "Known languages" da spec aparecem sem
     * sufixo (`c`, `cpp`, `java`, `python3`, `javascript`, `pascal`,
     * `prolog`, `ada`, `csharp`, `objectivec`, `haskell`, `kotlin`, `go`,
     * `php`, `ruby`, `rust`, `scala`, `python2`) e vao para a versao que o
     * catalogo marca como a padrao da linguagem; as outras versoes recebem o
     * sufixo que a spec recomenda.
     *
     * @var array<string, string>
     */
    private const IDENTIFICADORES = [
        // C / C++
        'c_gcc13' => 'c',
        'c_clang17' => 'c_clang',
        'c99_gcc' => 'c99',
        'cpp_gpp13' => 'cpp',
        'cpp14_gpp' => 'cpp14',
        'cpp17_gpp' => 'cpp17',
        'cpp_clang' => 'cpp_clang',

        // JVM
        'java25' => 'java',
        'java21' => 'java21',
        'java17' => 'java17',
        'kt' => 'kotlin',
        'scala' => 'scala',
        'groovy' => 'groovy',
        'clj' => 'clojure',

        // Python e afins
        'py3' => 'python3',
        'pypy3' => 'python3_pypy',
        'py2' => 'python2',

        // Web
        'js_node24' => 'javascript',
        'js_node22' => 'javascript_node22',
        'js_node20' => 'javascript_node20',
        'ts' => 'typescript',
        'coffee' => 'coffeescript',

        // .NET
        'cs_dotnet' => 'csharp',
        'cs_mono' => 'csharp_mono',
        'fs_dotnet' => 'fsharp',
        'vb' => 'visualbasic',

        // Sistemas
        'rs' => 'rust',
        'go' => 'go',
        'd_ldc' => 'd',
        'd_dmd' => 'd_dmd',
        'nim' => 'nim',
        'zig' => 'zig',
        'vlang' => 'v',
        'swift' => 'swift',
        'objc' => 'objectivec',
        'asm64' => 'asm64',
        'asm32' => 'asm32',

        // Scripts
        'php' => 'php',
        'rb' => 'ruby',
        'perl' => 'perl',
        'lua' => 'lua',
        'sh' => 'bash',
        'awk' => 'awk',
        'sed' => 'sed',
        'tcl' => 'tcl',
        'r' => 'r',
        'octave' => 'octave',
        'sql' => 'sql',

        // Funcionais
        'hs' => 'haskell',
        'ml' => 'ocaml',
        'erl' => 'erlang',
        'ex' => 'elixir',
        'jl' => 'julia',
        'lisp_sbcl' => 'lisp',
        'lisp_clisp' => 'lisp_clisp',
        'scm' => 'scheme',
        'rkt' => 'racket',

        // Classicas
        'pas_fpc' => 'pascal',
        'pas_gpc' => 'pascal_gpc',
        'f90' => 'fortran',
        'f77' => 'fortran77',
        'adb' => 'ada',
        'cob' => 'cobol',
        'prolog_swi' => 'prolog',
        'prolog_gnu' => 'prolog_gnu',
        'st' => 'smalltalk',
        'icn' => 'icon',
        'pike' => 'pike',
        'forth' => 'forth',
        'bc' => 'bc',

        // Fora da distribuicao padrao (#305) e esotericas
        'scratch' => 'scratch',
        'portugol_studio' => 'portugol',
        'dart' => 'dart',
        'cr' => 'crystal',
        'bf' => 'brainfuck',
        'ws' => 'whitespace',
    ];

    /**
     * O identificador CLICS do slug, ou o proprio slug.
     */
    public static function para(?string $slug): string
    {
        $slug = trim((string) $slug);

        return self::IDENTIFICADORES[$slug] ?? $slug;
    }

    /**
     * A tabela inteira, para quem precisa conferi-la (a suite confere que
     * nenhum identificador se repete -- dois ids iguais fariam duas
     * linguagens diferentes virarem a mesma no consumidor).
     *
     * @return array<string, string>
     */
    public static function tabela(): array
    {
        return self::IDENTIFICADORES;
    }
}
