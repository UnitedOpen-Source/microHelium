<?php

namespace App\Http\Controllers;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Judgehost;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Rejudging;
use App\Models\Site;
use App\Services\RejudgingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Issue #192/#245 -- rejulgamento em lote pela tela.
 *
 * O #192 entregou o servico inteiro e nenhuma porta: ate aqui, corrigir um
 * caso de teste errado no meio da prova significava montar a chamada a API a
 * mao, com o relogio andando e as equipes esperando.
 *
 * Adaptador de sessao/CSRF sobre o MESMO RejudgingService que a API usa,
 * como o ContestOperationsController faz para a cerimonia. Nenhuma regra
 * mora aqui: o servico decide, esta classe mostra e pergunta.
 *
 * O portao e o mesmo da API (`role:judge,admin`), e pela mesma razao escrita
 * na spec: quem pode rejulgar um envio pode rejulgar o problema inteiro, e a
 * diferenca entre as duas coisas e de escala, nao de autoridade. O que
 * protege contra o acidente nao e o perfil -- e a previa, o motivo
 * obrigatorio e a exclusao dos aceitos por padrao.
 */
class RejudgingScreenController extends Controller
{
    public function __construct(private RejudgingService $rejudgings)
    {
        $this->middleware(['auth', 'role:judge,admin']);
    }

    public function index(Request $request): View
    {
        $contests = Contest::query()->competition()->orderByDesc('start_time')->get();
        $contest = $this->chosen($request, $contests);

        return view('judge.rejudgings', [
            'contests' => $contests,
            'contest' => $contest,
            'rejudgings' => $contest
                ? Rejudging::where('contest_id', $contest->id)->with('creator:user_id,fullname')->orderByDesc('id')->get()
                : collect(),
            'previa' => $request->session()->get('previa'),
        ] + $this->options($contest));
    }

    /**
     * Quantos envios o criterio pega, sem montar conjunto nenhum.
     *
     * Existe separado porque montar o conjunto DISPARA JULGAMENTO DE VERDADE:
     * descobrir que o filtro pegou 4000 envios em vez de 40 depois de a fila
     * ja estar cheia e tarde. E o passo em que se descobre que o criterio
     * pegou mais gente do que se queria, entao e o primeiro botao.
     */
    public function preview(Request $request, Contest $contest): RedirectResponse
    {
        $filtros = $this->filters($request);
        $incluirAceitos = $request->boolean('include_accepted');

        $pegos = $this->rejudgings->select($contest, $filtros, $incluirAceitos)->count();
        $comAceitos = $this->rejudgings->select($contest, $filtros, true)->count();

        return back()->with('previa', [
            'contest_id' => $contest->id,
            'matches' => $pegos,
            // Tirar um AC de uma equipe no meio da prova e a coisa mais cara
            // que um rejulgamento faz. Dizer quantos ficaram de fora e o que
            // permite decidir se e isso mesmo que se queria.
            'accepted_excluded' => $incluirAceitos ? 0 : $comAceitos - $pegos,
            'include_accepted' => $incluirAceitos,
            // A conversao minuto -> segundo volta visivel: uma conta que
            // acontece escondida e uma conta que ninguem confere.
            'seconds_from' => $filtros['contest_time_from'] ?? null,
            'seconds_to' => $filtros['contest_time_to'] ?? null,
        ])->withInput();
    }

    public function store(Request $request, Contest $contest): RedirectResponse
    {
        $request->validate([
            // Um rejulgamento em lote muda a classificacao de gente que nao
            // pediu nada, e "por que" e a primeira pergunta de quem contesta
            // o resultado depois.
            'reason' => 'required|string|max:255',
        ]);

        $rejudging = $this->rejudgings->create(
            $contest,
            $this->filters($request),
            (string) $request->input('reason'),
            $request->boolean('include_accepted'),
            $request->user()
        );

        return redirect()->route('judge.rejudgings.show', $rejudging)
            ->with('success', "Conjunto #{$rejudging->id} montado. Os envios estao sendo julgados em sombra; nada mudou para as equipes ainda.");
    }

    public function show(Rejudging $rejudging): View
    {
        // Os membros sao julgados em segundo plano, e o status so vira
        // `ready` quando alguem reconta. Sem isto a tela mostraria
        // "preparando" para sempre e o botao de aplicar nunca apareceria.
        $this->rejudgings->refreshReadiness($rejudging);

        return view('judge.rejudging', [
            'rejudging' => $rejudging->fresh()->load('creator:user_id,fullname'),
            'previa' => $this->rejudgings->preview($rejudging),
        ]);
    }

    public function apply(Request $request, Rejudging $rejudging): RedirectResponse
    {
        $this->rejudgings->refreshReadiness($rejudging);
        $atual = $rejudging->fresh();

        if (! $atual->isDecidable()) {
            return back()->with('error', "Este conjunto esta em \"{$atual->status}\" e nao pode ser aplicado. So um conjunto pronto, com todos os membros julgados, pode.");
        }

        $this->rejudgings->apply($atual, $request->user());

        return back()->with('success', 'Vereditos gravados. O placar foi recomposto.');
    }

    public function cancel(Request $request, Rejudging $rejudging): RedirectResponse
    {
        if (! $rejudging->isOpen()) {
            return back()->with('error', "Este conjunto ja foi \"{$rejudging->status}\" e nao pode ser cancelado.");
        }

        $this->rejudgings->cancel($rejudging, $request->user());

        return back()->with('success', 'Conjunto descartado. Nenhum veredito foi tocado.');
    }

    /**
     * Os campos que os requisitos de CCS nomeiam, mais a sede.
     *
     * Vazio nao e filtro: um `problem_id` em branco no formulario significa
     * "qualquer problema", e passa-lo adiante como `null` faria o servico
     * procurar envios sem problema, que nao existem.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $validado = $request->validate([
            'problem_id' => 'nullable|exists:problems,id',
            'language_id' => 'nullable|exists:languages,id',
            'user_id' => 'nullable|integer',
            'site_id' => 'nullable|exists:sites,id',
            'judgehost_id' => 'nullable|exists:judgehosts,id',
            'answer_id' => 'nullable|exists:answers,id',
            'contest_minute_from' => 'nullable|integer|min:0',
            'contest_minute_to' => 'nullable|integer|min:0',
        ]);

        // `runs.contest_time` e em SEGUNDOS, e quem opera a prova fala em
        // minutos -- "os envios da primeira hora". Os campos do formulario
        // tem outro NOME de proposito: `contest_time_from` significando
        // minutos aqui e segundos na API seria a mesma chave com duas
        // unidades, que e como se digita 90 querendo o minuto 90 e se
        // seleciona quase nada, em silencio.
        $filtros = array_filter([
            'contest_time_from' => $this->toSeconds($validado['contest_minute_from'] ?? null),
            'contest_time_to' => $this->toSeconds($validado['contest_minute_to'] ?? null),
        ], fn ($valor) => $valor !== null);

        unset($validado['contest_minute_from'], $validado['contest_minute_to']);

        return array_filter($validado, fn ($valor) => $valor !== null && $valor !== '') + $filtros;
    }

    private function toSeconds(mixed $minutos): ?int
    {
        return $minutos === null || $minutos === '' ? null : (int) $minutos * 60;
    }

    /**
     * @param  Collection<int, Contest>  $contests
     */
    private function chosen(Request $request, Collection $contests): ?Contest
    {
        return $contests->firstWhere('id', $request->integer('contest_id')) ?? $contests->first();
    }

    /**
     * As opcoes de cada filtro, restritas a competicao escolhida.
     *
     * Um seletor com os problemas de TODAS as competicoes deixaria montar um
     * criterio que nunca pega nada, e o erro so apareceria na previa como
     * "0 envios" -- sem dizer que a causa foi escolher o problema errado.
     *
     * @return array<string, mixed>
     */
    private function options(?Contest $contest): array
    {
        if (! $contest) {
            return ['problems' => collect(), 'languages' => collect(), 'sites' => collect(), 'answers' => collect(), 'judgehosts' => collect()];
        }

        return [
            'problems' => Problem::where('contest_id', $contest->id)->orderBy('short_name')->get(),
            'languages' => Language::where('contest_id', $contest->id)->orderBy('name')->get(),
            'sites' => Site::where('contest_id', $contest->id)->orderBy('name')->get(),
            'answers' => Answer::where('contest_id', $contest->id)->orderBy('short_name')->get(),
            'judgehosts' => Judgehost::orderBy('name')->get(),
        ];
    }
}
