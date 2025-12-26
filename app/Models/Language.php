<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Language extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contest_id',
        'name',
        'extension',
        'compile_command',
        'run_command',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * Get default languages with unique extensions
     * Each language has a unique extension identifier to avoid database conflicts
     */
    public static function getDefaultLanguages(): array
    {
        return [
            // C/C++ Languages
            ['name' => 'C (GCC 13)', 'extension' => 'c_gcc13', 'file_ext' => 'c', 'compile_command' => 'gcc -static -O2 -std=c17 -o {output} {source} -lm', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'C (Clang 17)', 'extension' => 'c_clang17', 'file_ext' => 'c', 'compile_command' => 'clang -static -O2 -std=c17 -o {output} {source} -lm', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'C99 (GCC 13)', 'extension' => 'c99_gcc', 'file_ext' => 'c', 'compile_command' => 'gcc -static -O2 -std=c99 -o {output} {source} -lm', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'C++ (G++ 13)', 'extension' => 'cpp_gpp13', 'file_ext' => 'cpp', 'compile_command' => 'g++ -static -O2 -std=c++20 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'C++14 (G++ 13)', 'extension' => 'cpp14_gpp', 'file_ext' => 'cpp', 'compile_command' => 'g++ -static -O2 -std=c++14 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'C++17 (G++ 13)', 'extension' => 'cpp17_gpp', 'file_ext' => 'cpp', 'compile_command' => 'g++ -static -O2 -std=c++17 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'C++ (Clang 17)', 'extension' => 'cpp_clang', 'file_ext' => 'cpp', 'compile_command' => 'clang++ -static -O2 -std=c++20 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],

            // Java
            ['name' => 'Java (OpenJDK 21)', 'extension' => 'java21', 'file_ext' => 'java', 'compile_command' => 'javac {source}', 'run_command' => 'java -Xmx{memory}m {classname}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'Java (OpenJDK 17)', 'extension' => 'java17', 'file_ext' => 'java', 'compile_command' => 'javac {source}', 'run_command' => 'java -Xmx{memory}m {classname}', 'is_active' => false, 'category' => 'compiled'],

            // Python
            ['name' => 'Python 3.12', 'extension' => 'py3', 'file_ext' => 'py', 'compile_command' => 'python3 -m py_compile {source}', 'run_command' => 'python3 {source}', 'is_active' => true, 'category' => 'interpreted'],
            ['name' => 'Python 3 (PyPy)', 'extension' => 'pypy3', 'file_ext' => 'py', 'compile_command' => 'pypy3 -m py_compile {source}', 'run_command' => 'pypy3 {source}', 'is_active' => true, 'category' => 'interpreted'],
            ['name' => 'Python 2.7', 'extension' => 'py2', 'file_ext' => 'py', 'compile_command' => 'python2 -m py_compile {source}', 'run_command' => 'python2 {source}', 'is_active' => false, 'category' => 'interpreted'],

            // JavaScript / Node.js (using NVM)
            ['name' => 'JavaScript (Node 24)', 'extension' => 'js_node24', 'file_ext' => 'js', 'compile_command' => 'node --check {source}', 'run_command' => 'source ~/.nvm/nvm.sh && nvm use 24 && node {source}', 'is_active' => true, 'category' => 'interpreted'],
            ['name' => 'JavaScript (Node 22)', 'extension' => 'js_node22', 'file_ext' => 'js', 'compile_command' => 'node --check {source}', 'run_command' => 'source ~/.nvm/nvm.sh && nvm use 22 && node {source}', 'is_active' => true, 'category' => 'interpreted'],
            ['name' => 'JavaScript (Node 20 LTS)', 'extension' => 'js_node20', 'file_ext' => 'js', 'compile_command' => 'node --check {source}', 'run_command' => 'source ~/.nvm/nvm.sh && nvm use 20 && node {source}', 'is_active' => true, 'category' => 'interpreted'],
            ['name' => 'TypeScript (Node 22)', 'extension' => 'ts', 'file_ext' => 'ts', 'compile_command' => 'npx tsc --strict --outFile {output}.js {source}', 'run_command' => 'source ~/.nvm/nvm.sh && nvm use 22 && node {executable}.js', 'is_active' => true, 'category' => 'compiled'],

            // JVM Languages
            ['name' => 'Kotlin (1.9)', 'extension' => 'kt', 'file_ext' => 'kt', 'compile_command' => 'kotlinc {source} -include-runtime -d {output}.jar', 'run_command' => 'java -jar {executable}.jar', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'Scala 3', 'extension' => 'scala', 'file_ext' => 'scala', 'compile_command' => 'scalac {source}', 'run_command' => 'scala {classname}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Groovy 4', 'extension' => 'groovy', 'file_ext' => 'groovy', 'compile_command' => 'groovyc {source}', 'run_command' => 'groovy {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Clojure', 'extension' => 'clj', 'file_ext' => 'clj', 'compile_command' => 'clojure -M --main clojure.main --eval "(compile \'main)"', 'run_command' => 'clojure {source}', 'is_active' => false, 'category' => 'interpreted'],

            // .NET Languages
            ['name' => 'C# (.NET 8)', 'extension' => 'cs_dotnet', 'file_ext' => 'cs', 'compile_command' => 'dotnet build', 'run_command' => 'dotnet run', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'C# (Mono)', 'extension' => 'cs_mono', 'file_ext' => 'cs', 'compile_command' => 'mcs -out:{output}.exe {source}', 'run_command' => 'mono {executable}.exe', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'F# (.NET 8)', 'extension' => 'fs_dotnet', 'file_ext' => 'fs', 'compile_command' => 'dotnet build', 'run_command' => 'dotnet run', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Visual Basic (.NET 8)', 'extension' => 'vb', 'file_ext' => 'vb', 'compile_command' => 'dotnet build', 'run_command' => 'dotnet run', 'is_active' => false, 'category' => 'compiled'],

            // Systems Languages
            ['name' => 'Rust (1.75)', 'extension' => 'rs', 'file_ext' => 'rs', 'compile_command' => 'rustc -O -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'Go (1.22)', 'extension' => 'go', 'file_ext' => 'go', 'compile_command' => 'go build -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'D (DMD)', 'extension' => 'd_dmd', 'file_ext' => 'd', 'compile_command' => 'dmd -of={output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'D (LDC)', 'extension' => 'd_ldc', 'file_ext' => 'd', 'compile_command' => 'ldc2 -of={output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Nim', 'extension' => 'nim', 'file_ext' => 'nim', 'compile_command' => 'nim c -o:{output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Zig', 'extension' => 'zig', 'file_ext' => 'zig', 'compile_command' => 'zig build-exe {source} -o {output}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],

            // Scripting Languages
            ['name' => 'PHP 8.3', 'extension' => 'php', 'file_ext' => 'php', 'compile_command' => 'php -l {source}', 'run_command' => 'php {source}', 'is_active' => true, 'category' => 'interpreted'],
            ['name' => 'Ruby 3.3', 'extension' => 'rb', 'file_ext' => 'rb', 'compile_command' => 'ruby -c {source}', 'run_command' => 'ruby {source}', 'is_active' => true, 'category' => 'interpreted'],
            ['name' => 'Perl 5', 'extension' => 'perl', 'file_ext' => 'pl', 'compile_command' => 'perl -c {source}', 'run_command' => 'perl {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Lua 5.4', 'extension' => 'lua', 'file_ext' => 'lua', 'compile_command' => 'luac -p {source}', 'run_command' => 'lua {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Bash', 'extension' => 'sh', 'file_ext' => 'sh', 'compile_command' => 'bash -n {source}', 'run_command' => 'bash {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'AWK (GAWK)', 'extension' => 'awk', 'file_ext' => 'awk', 'compile_command' => 'gawk --lint -f {source} /dev/null 2>&1', 'run_command' => 'gawk -f {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Sed', 'extension' => 'sed', 'file_ext' => 'sed', 'compile_command' => 'sed -n "q" {source}', 'run_command' => 'sed -f {source}', 'is_active' => false, 'category' => 'interpreted'],

            // Functional Languages
            ['name' => 'Haskell (GHC)', 'extension' => 'hs', 'file_ext' => 'hs', 'compile_command' => 'ghc -O2 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'OCaml', 'extension' => 'ml', 'file_ext' => 'ml', 'compile_command' => 'ocamlopt -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Erlang', 'extension' => 'erl', 'file_ext' => 'erl', 'compile_command' => 'erlc {source}', 'run_command' => 'erl -noshell -s main start -s init stop', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Elixir', 'extension' => 'ex', 'file_ext' => 'ex', 'compile_command' => 'elixirc {source}', 'run_command' => 'elixir {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Julia', 'extension' => 'jl', 'file_ext' => 'jl', 'compile_command' => 'julia --compile=min {source} 2>&1', 'run_command' => 'julia {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'R', 'extension' => 'r', 'file_ext' => 'r', 'compile_command' => 'Rscript --vanilla -e "parse(\'{source}\')"', 'run_command' => 'Rscript --vanilla {source}', 'is_active' => false, 'category' => 'interpreted'],

            // Lisp Family
            ['name' => 'Common Lisp (SBCL)', 'extension' => 'lisp_sbcl', 'file_ext' => 'lisp', 'compile_command' => 'sbcl --noinform --non-interactive --load {source}', 'run_command' => 'sbcl --script {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Common Lisp (CLISP)', 'extension' => 'lisp_clisp', 'file_ext' => 'lisp', 'compile_command' => 'clisp -c {source}', 'run_command' => 'clisp {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Scheme (Guile)', 'extension' => 'scm', 'file_ext' => 'scm', 'compile_command' => 'guild compile {source}', 'run_command' => 'guile {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Racket', 'extension' => 'rkt', 'file_ext' => 'rkt', 'compile_command' => 'raco make {source}', 'run_command' => 'racket {source}', 'is_active' => false, 'category' => 'interpreted'],

            // Pascal/Delphi
            ['name' => 'Pascal (FPC)', 'extension' => 'pas_fpc', 'file_ext' => 'pas', 'compile_command' => 'fpc -O2 -o{output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'Pascal (GPC)', 'extension' => 'pas_gpc', 'file_ext' => 'pas', 'compile_command' => 'gpc -O2 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],

            // Fortran
            ['name' => 'Fortran (GFortran)', 'extension' => 'f90', 'file_ext' => 'f90', 'compile_command' => 'gfortran -O2 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Fortran 77', 'extension' => 'f77', 'file_ext' => 'f', 'compile_command' => 'gfortran -std=legacy -O2 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],

            // Assembly
            ['name' => 'Assembly x64 (NASM)', 'extension' => 'asm64', 'file_ext' => 'asm', 'compile_command' => 'nasm -f elf64 {source} -o {output}.o && ld {output}.o -o {output}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Assembly x86 (NASM)', 'extension' => 'asm32', 'file_ext' => 'asm', 'compile_command' => 'nasm -f elf32 {source} -o {output}.o && ld -m elf_i386 {output}.o -o {output}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],

            // Apple/Mobile
            ['name' => 'Swift', 'extension' => 'swift', 'file_ext' => 'swift', 'compile_command' => 'swiftc -O -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Objective-C', 'extension' => 'objc', 'file_ext' => 'm', 'compile_command' => 'clang -framework Foundation -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],

            // Other Modern Languages
            ['name' => 'Dart', 'extension' => 'dart', 'file_ext' => 'dart', 'compile_command' => 'dart compile exe {source} -o {output}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Crystal', 'extension' => 'cr', 'file_ext' => 'cr', 'compile_command' => 'crystal build {source} -o {output}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'V', 'extension' => 'vlang', 'file_ext' => 'v', 'compile_command' => 'v -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],

            // Logic/Prolog
            ['name' => 'Prolog (SWI)', 'extension' => 'prolog_swi', 'file_ext' => 'pl', 'compile_command' => 'swipl -g "halt" -l {source}', 'run_command' => 'swipl -g "main,halt" -l {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Prolog (GNU)', 'extension' => 'prolog_gnu', 'file_ext' => 'pro', 'compile_command' => 'gplc {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],

            // Database/Query
            ['name' => 'SQL (SQLite)', 'extension' => 'sql', 'file_ext' => 'sql', 'compile_command' => 'sqlite3 :memory: ".read {source}" 2>&1', 'run_command' => 'sqlite3 :memory: < {source}', 'is_active' => false, 'category' => 'interpreted'],

            // Esoteric Languages
            ['name' => 'Brainfuck', 'extension' => 'bf', 'file_ext' => 'bf', 'compile_command' => 'bf -c {source}', 'run_command' => 'bf {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Whitespace', 'extension' => 'ws', 'file_ext' => 'ws', 'compile_command' => 'wspace {source} 2>&1', 'run_command' => 'wspace {source}', 'is_active' => false, 'category' => 'interpreted'],

            // Scientific
            ['name' => 'Octave', 'extension' => 'octave', 'file_ext' => 'oct', 'compile_command' => 'octave --no-gui --silent --eval "source(\'{source}\')"', 'run_command' => 'octave --no-gui --silent {source}', 'is_active' => false, 'category' => 'interpreted'],

            // Text Processing
            ['name' => 'CoffeeScript', 'extension' => 'coffee', 'file_ext' => 'coffee', 'compile_command' => 'coffee -c {source}', 'run_command' => 'coffee {source}', 'is_active' => false, 'category' => 'interpreted'],

            // Other
            ['name' => 'Ada (GNAT)', 'extension' => 'adb', 'file_ext' => 'adb', 'compile_command' => 'gnatmake -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'COBOL', 'extension' => 'cob', 'file_ext' => 'cob', 'compile_command' => 'cobc -x -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Icon', 'extension' => 'icn', 'file_ext' => 'icn', 'compile_command' => 'icont -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Pike', 'extension' => 'pike', 'file_ext' => 'pike', 'compile_command' => 'pike -e "compile_file(\"{source}\")"', 'run_command' => 'pike {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Smalltalk (GST)', 'extension' => 'st', 'file_ext' => 'st', 'compile_command' => 'gst --quiet {source} 2>&1', 'run_command' => 'gst {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Tcl', 'extension' => 'tcl', 'file_ext' => 'tcl', 'compile_command' => 'tclsh {source} 2>&1', 'run_command' => 'tclsh {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Forth (GForth)', 'extension' => 'forth', 'file_ext' => 'fs', 'compile_command' => 'gforth {source} -e bye', 'run_command' => 'gforth {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'BC', 'extension' => 'bc', 'file_ext' => 'bc', 'compile_command' => 'bc -l {source} < /dev/null', 'run_command' => 'bc -l {source}', 'is_active' => false, 'category' => 'interpreted'],
        ];
    }

    /**
     * Get all available languages for selection
     */
    public static function getAllLanguages(): array
    {
        return collect(self::getDefaultLanguages())->map(function ($lang, $index) {
            return array_merge($lang, ['id' => $index + 1]);
        })->all();
    }

    /**
     * Get the actual file extension for this language
     */
    public function getFileExtension(): string
    {
        $defaults = collect(self::getDefaultLanguages())->keyBy('extension');
        return $defaults[$this->extension]['file_ext'] ?? $this->extension;
    }
}
