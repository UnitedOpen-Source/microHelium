<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Language;
use App\Models\ProblemLanguageLimit;
use App\Models\Run;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Language management (issue #140) -- BOCA's src/admin/language.php.
 *
 * The `languages` table existed from the first migration and was only ever
 * written by a seeder: adding a language to a contest, or fixing a wrong
 * compiler flag found during the dress rehearsal, meant opening the
 * database by hand. The commands are per contest on purpose -- compiler
 * version, optimisation level and JVM heap change between editions -- so
 * they have to be editable before the event, by the organiser, not by a
 * migration.
 *
 * Security: `compile_command` and `run_command` become the shell command
 * line the judge executes. This screen is therefore a command-entry screen
 * for an administrator, and it is treated as one:
 *
 *  - it lives in the ['auth','admin'] backend group, the same gate as every
 *    other backend screen -- no new, weaker gate was invented for it;
 *  - every create/edit/delete writes a ContestLog entry (issue #88) naming
 *    the administrator and the command the field became, which is what
 *    answers "why did every Java submission start failing at 14:30";
 *  - there is deliberately NO "test this command" button. The sandbox
 *    (issue #49) is what contains judge execution; a command executed from
 *    a web request would run outside it, on the web host, as the web user.
 */
class LanguageController extends Controller
{
    /**
     * The placeholders AutoJudgeService::buildCompileCommand() actually
     * substitutes -- kept here (and rendered by the form) so the organiser
     * is not guessing. Anything else in the field is passed to the shell
     * verbatim.
     */
    public const COMPILE_PLACEHOLDERS = [
        '{source}' => 'Nome do arquivo enviado (ex: main.c).',
        '{output}' => 'Nome do executável a gerar (o nome do arquivo sem extensão).',
        '{basename}' => 'O mesmo que {output}: o nome do arquivo sem extensão.',
        '{judge_runtime}' => 'Caminho dos scripts auxiliares do judge (resources/judge-runtime).',
    ];

    /**
     * The placeholders AutoJudgeService::executeProgram() substitutes.
     * {output}/{basename} are compile-time only and {memory}/{executable}/
     * {classname} are run-time only -- they are not interchangeable.
     */
    public const RUN_PLACEHOLDERS = [
        '{executable}' => 'Nome do executável gerado na compilação.',
        '{classname}' => 'O mesmo que {executable} (usado por Java/Scala).',
        '{source}' => 'Nome do arquivo enviado, para linguagens interpretadas.',
        '{memory}' => 'Limite de memória em MB aplicado ao problema.',
        '{judge_runtime}' => 'Caminho dos scripts auxiliares do judge (resources/judge-runtime).',
    ];

    public function index(Request $request): View
    {
        // Practice contests are listed too: the Treino Livre contest needs
        // its own language set just as much as a competition does.
        $contests = Contest::orderByDesc('created_at')->get();

        $contestId = (int) $request->query('contest_id', 0);
        $contest = ($contestId ? $contests->firstWhere('id', $contestId) : null)
            ?? $contests->first();

        $languages = collect();
        if ($contest) {
            $languages = Language::where('contest_id', $contest->id)
                // The row needs to know whether anything was ever submitted
                // in this language, because that is what decides whether it
                // can be removed at all (see destroy()). Soft-deleted runs
                // count: they can be restored, and they still reference
                // this language_id.
                ->withCount(['runs' => fn ($query) => $query->withTrashed()])
                ->orderBy('name')
                ->get();
        }

        return view('backend.languages', [
            'contests' => $contests,
            'contest' => $contest,
            'languages' => $languages,
            'compilePlaceholders' => self::COMPILE_PLACEHOLDERS,
            'runPlaceholders' => self::RUN_PLACEHOLDERS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $contestId = (int) $request->input('contest_id');

        $validated = $request->validate(
            ['contest_id' => 'required|exists:contests,id'] + $this->rules($contestId, null),
            $this->messages()
        );

        $language = Language::create([
            'contest_id' => $validated['contest_id'],
            'name' => $validated['name'],
            'extension' => $validated['extension'],
            'compile_command' => $validated['compile_command'],
            'run_command' => $validated['run_command'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        $this->audit($language, 'created', [
            'compile_command' => $language->compile_command,
            'run_command' => $language->run_command,
            'is_active' => $language->is_active,
        ]);

        return redirect()->route('backend.languages', ['contest_id' => $language->contest_id])
            ->with('success', "Linguagem \"{$language->name}\" cadastrada com sucesso!");
    }

    public function update(Request $request, Language $language): RedirectResponse
    {
        $validated = $request->validate(
            $this->rules($language->contest_id, $language->id),
            $this->messages()
        );

        $before = $language->only(['name', 'extension', 'compile_command', 'run_command', 'is_active']);

        $language->update([
            'name' => $validated['name'],
            'extension' => $validated['extension'],
            'compile_command' => $validated['compile_command'],
            'run_command' => $validated['run_command'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        // Only the fields that actually moved, old and new. A diff is what
        // makes the log readable months later; a full dump of every field on
        // every save is not.
        $changes = [];
        foreach ($before as $field => $oldValue) {
            $newValue = $language->{$field};
            if ($oldValue !== $newValue) {
                $changes[$field] = ['from' => $oldValue, 'to' => $newValue];
            }
        }

        $this->audit($language, 'updated', ['changes' => $changes]);

        return redirect()->route('backend.languages', ['contest_id' => $language->contest_id])
            ->with('success', "Linguagem \"{$language->name}\" atualizada com sucesso!");
    }

    /**
     * Removal is refused once anything has been submitted in the language.
     *
     * `runs.language_id` is a real foreign key declared cascadeOnDelete, so
     * a row DELETE here would take every submission in that language with
     * it -- and the scoreboard would silently change, mid-contest or after
     * it. Language also uses SoftDeletes, which does not help: delete()
     * writes deleted_at instead of firing the cascade, but Run::language()
     * then resolves to null, and AutoJudgeService::compile() dereferences
     * $run->language on every rejudge. Either way, deleting a language that
     * has runs breaks something that was already judged.
     *
     * The existing "this language is no longer available" mechanism is
     * is_active = false: SubmitController already refuses a new submission
     * in an inactive language, while runs already made keep their language
     * row, their commands, and their place on the scoreboard. So the answer
     * to "the judge machine does not have kotlinc" is deactivate, not
     * delete, and the screen says exactly that.
     *
     * When there are no runs, the row is removed with forceDelete() rather
     * than soft-deleted: nothing references it, so there is nothing to
     * preserve (the ContestLog entry is the record that it existed), and a
     * soft-deleted row would keep occupying the (contest_id, name) and
     * (contest_id, extension) UNIQUE indexes -- those indexes do not know
     * about deleted_at, so re-adding a language with the same extension
     * after a typo'd one was removed would fail against a row the
     * administrator can no longer see.
     */
    public function destroy(Language $language): RedirectResponse
    {
        $contestId = $language->contest_id;
        $name = $language->name;

        $runCount = Run::withTrashed()->where('language_id', $language->id)->count();

        if ($runCount > 0) {
            return redirect()->route('backend.languages', ['contest_id' => $contestId])
                ->with('error', "A linguagem \"{$name}\" já tem {$runCount} submissão(ões) e não pode ser removida: isso apagaria essas submissões do placar. Desative-a para impedir novos envios.");
        }

        $this->audit($language, 'deleted', [
            'compile_command' => $language->compile_command,
            'run_command' => $language->run_command,
        ]);

        DB::transaction(function () use ($language) {
            // problem_language_limits.language_id is declared
            // cascadeOnDelete, but the cascade is not relied on: SQLite
            // issues no PRAGMA foreign_keys here (config/database.php sets
            // no foreign_key_constraints), so the rows would simply survive
            // on that driver and point at a language id that no longer
            // exists. SiteController::destroy() cleans up its dependants by
            // hand for the same reason. A per-problem limit override has no
            // meaning without its language, so removing it is the right
            // outcome -- the confirm dialog on the screen says so.
            ProblemLanguageLimit::where('language_id', $language->id)->delete();
            $language->forceDelete();
        });

        return redirect()->route('backend.languages', ['contest_id' => $contestId])
            ->with('success', "Linguagem \"{$name}\" removida.");
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(int $contestId, ?int $ignoreId): array
    {
        return [
            // Both UNIQUE indexes on the table are validated, so the
            // administrator gets a message instead of a 500 from the
            // driver. Note the absence of a whereNull('deleted_at'):
            // the index itself has no such condition, so a soft-deleted
            // row still collides and still has to be reported here.
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique('languages', 'name')->where('contest_id', $contestId)->ignore($ignoreId),
            ],
            // The extension is not cosmetic: Problem::getCompileScriptPath()
            // interpolates it straight into a filesystem path
            // (<package>/compile/<extension>) that the judge then executes if
            // it exists, and SubmitController derives the submitted file's
            // name from it. Restricting it to word characters keeps a value
            // like "../../../bin/sh" out of that path.
            'extension' => [
                'required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_]+$/',
                Rule::unique('languages', 'extension')->where('contest_id', $contestId)->ignore($ignoreId),
            ],
            // Both commands are required even though the column is nullable:
            // AutoJudgeService passes them straight to str_replace() and
            // then to the shell, and a null there is a deprecation followed
            // by an empty command line that "succeeds" without compiling
            // anything. Interpreted languages use a syntax check as the
            // compile step (`php -l {source}`), which is what BOCA does too.
            'compile_command' => 'required|string|max:2000',
            'run_command' => 'required|string|max:2000',
            'is_active' => 'nullable|boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'name.required' => 'Informe o nome da linguagem.',
            'name.unique' => 'Já existe uma linguagem com este nome nesta competição.',
            'extension.required' => 'Informe o identificador (extensão) da linguagem.',
            'extension.unique' => 'Já existe uma linguagem com este identificador nesta competição.',
            'extension.regex' => 'O identificador aceita apenas letras, números e underscore (ex: cpp_gpp13).',
            'compile_command.required' => 'Informe o comando de compilação. Para linguagens interpretadas, use uma verificação de sintaxe (ex: php -l {source}).',
            'run_command.required' => 'Informe o comando de execução.',
        ];
    }

    /**
     * Issue #88's log is where an organiser reconstructs the contest. A
     * compile/run command is judge-executed input, so who changed it, when,
     * from where, and what it became is the entry that explains a sudden
     * wave of compilation errors.
     *
     * @param  array<string, mixed>  $context
     */
    private function audit(Language $language, string $action, array $context): void
    {
        $user = auth()->user();

        ContestLog::log(
            contestId: $language->contest_id,
            type: 'info',
            message: "Language \"{$language->name}\" ({$language->extension}) {$action} by ".($user?->username ?? 'unknown'),
            userId: $user?->user_id,
            context: ['language_id' => $language->id, 'extension' => $language->extension] + $context,
        );
    }
}
