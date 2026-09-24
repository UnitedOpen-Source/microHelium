# Issue #397 — a interface passa a ser traduzível, começando pelo competidor

**Status:** primeiro passo. A issue inteira (51 componentes, 101 templates) não
cabe num PR; este entrega a **infraestrutura**, **corrige o padrão** e migra
**uma área de ponta a ponta** — o fluxo do competidor — em `pt_BR` e `es`, com
um teste que impede a área migrada de voltar a ter texto escrito direto no
código. O que falta está na última seção.

Convenção da issue: **[fato]** tem fonte ou caminho; **[decisão]** é o que este
PR escolhe; **[fora]** fica para depois.

## 1. O descompasso do padrão

**[fato]** `config/app.php` tinha `locale` = `env('APP_LOCALE', 'en')` e
`fallback_locale` = `en`, com a interface inteira em português. E os arquivos
de `resources/lang/pt_BR/` **eram cópias em inglês** dos de `en/` (`auth.php`:
`'These credentials do not match our records.'`; `validation.php` de uma versão
antiga do Laravel, sem metade das regras). A hipótese da issue — "mensagens de
validação podem sair em inglês ao lado de rótulos em português" — não era
hipótese: com `APP_LOCALE=pt_BR` elas saíam em inglês **do mesmo jeito**.

**[decisão]**

- `APP_LOCALE` e `APP_FALLBACK_LOCALE` passam a ter padrão `pt_BR` (é o idioma
  real da interface). `.env.example` diz isso explicitamente.
- `resources/lang/pt_BR/{auth,pagination,passwords,validation}.php` passam a
  estar **em português**, com todas as chaves do `validation.php` do framework
  instalado. Um teste compara as chaves com
  `vendor/laravel/framework/src/Illuminate/Translation/lang/en/`, para que uma
  regra nova do Laravel não caia em inglês sem ninguém ver.
- `<html lang>` deixa de ser `pt-BR` fixo em `layouts/app`, `layouts/auth` e
  `errors/layout`: passa a ser `str_replace('_', '-', app()->getLocale())`.
  Fecha o ponto de WCAG 3.1.1 que a #394 aponta — um leitor de tela lendo a
  página em espanhol com pronúncia portuguesa é o mesmo defeito ao contrário.

## 2. Uma fonte única de strings para Blade e Vue

### Laravel `lang/` e não `vue-i18n` [decisão]

| | `vue-i18n` | arquivos `lang/` do Laravel servidos ao Vue |
|---|---|---|
| Fontes de verdade | duas (Blade continua em `lang/`) | **uma** |
| Peso no bundle, medido | **60,3 KB** min. / **21,4 KB** gzip com o compilador de mensagens JIT; **44,0 / 16,0 KB** sem ele (mensagens pré-compiladas, exige plugin no Vite) | **< 1 KB** (`resources/js/i18n.js`) |
| CSP (#143, sem `unsafe-eval`) | compatível desde a compilação JIT (v9.3+) | só lê um JSON; nada a compilar |
| Uso real hoje | 2 componentes Vue na área do competidor, 9 textos | idem |

Medição do `vue-i18n` 11.4.12 (versão corrente em 24/09/2026) com `vue`
externo, `esbuild --bundle --minify`, `NODE_ENV=production`, API de composição
e sem devtools — isto é, só o que a biblioteca acrescentaria ao `app.js`.

O fluxo do competidor é **quase todo Blade** (10 templates e 2 layouts contra 2
componentes Vue). Adotar `vue-i18n` para 9 textos de relógio e tema, e manter
outro sistema para as centenas do Blade, seria o pior dos dois mundos; e a
#249 reduziu o bundle de propósito. Se um dia a interface virar SPA com
pluralização pesada, a troca é local: o `t(chave, substituições)` de
`i18n.js` tem a forma do `t()` do `vue-i18n`, e o catálogo já é JSON.

### Formato [decisão]

- **JSON do Laravel, chave = o texto em `pt_BR`.** `{{ __('Placar') }}`. O
  português é a língua-fonte: `pt_BR` não precisa de `pt_BR.json` (a chave já é o
  texto), a view continua legível, e os testes que conferem texto em português
  continuam valendo sem alteração. Chave que falta em `es` cai no português —
  degradação visível, não quebra.
- `resources/lang/es.json` — textos do Blade e dos controladores.
- `resources/lang/frontend/es.json` — textos que o **Vue** usa. Registrado no
  tradutor com `addJsonPath()`, então o Blade enxerga os dois; mas **só este
  arquivo** é enviado ao navegador, dentro de
  `<script type="application/json" id="i18n-catalog">` no layout. Um JSON não é
  executado, então a CSP não se importa; ainda assim leva o `nonce`.
- Texto com marcação (o `<br>` e o `<em>` do slogan do login) usa `{!! __() !!}`:
  a tradução vem do repositório, não do usuário.

### Idioma escolhido [decisão]

- Idiomas oferecidos: `config('app.supported_locales')` =
  `pt_BR` (Português (Brasil)) e `es` (Español). **`en` não entra no seletor**
  enquanto não tiver tradução — oferecer um idioma que mostra português seria
  mentir no seletor.
- `POST /locale` (`locale.update`) grava a escolha **na sessão** e volta à página.
  Funciona antes do login (o seletor aparece na tela de entrada) e sobrevive ao
  login porque a sessão é regenerada, não descartada.
- `App\Http\Middleware\SetLocale`, no grupo `web`: idioma da sessão, se for um
  dos suportados; senão, o padrão da instalação. Sem negociação por
  `Accept-Language` de propósito: uma instalação brasileira com navegadores em
  inglês passaria a mostrar a interface num idioma sem tradução.
- O seletor é um formulário de botões (`partials/locale-switcher`), sem JS:
  um clique, funciona sob a CSP, e não muda de contexto ao mudar o foco de um
  `<select>` (WCAG 3.2.2). Cada nome de idioma leva `lang` próprio.

## 3. O teste que impede a volta

`tests/Unit/InterfaceStringsTest.php` varre **só os arquivos migrados**
(lista explícita no teste — a área cresce aumentando a lista) e reprova:

- Blade: texto entre tags, e os atributos `title`, `aria-label`, `placeholder`
  e `alt`, com letras fora de `{{ }}`; e literal PHP com cara de interface
  (tem espaço, acento, ou começa com maiúscula seguida de minúsculas) que não
  seja argumento de `__()` — pega `@section('title', 'Placar')` e o mapa de
  vereditos do `partials/verdict`.
- Vue/JS: o mesmo no `<template>`, e literal no `<script>` fora de `t()`.
- Fica fora da varredura, por desenho: comentários, `<svg>`, `<style>`, e o
  conteúdo marcado com `translate="no"` (código de exemplo), que é o próprio
  HTML dizendo "não traduza".
- Lista curta de termos que **não se traduzem** (nome do produto, `S.O.S.`,
  siglas de veredito `AC`/`WA`/…, unidades `s`/`MB`/`KB`/`min`), cada um
  explicado no teste.

O mesmo teste confere que **toda** chave usada nos arquivos migrados existe em
`es` (e que as do Vue estão no catálogo que vai ao navegador), que não há chave
órfã em `es`, e roda o próprio detector contra um trecho com texto literal —
um detector que não acha nada passaria para sempre.

## 4. A área migrada: o competidor, de ponta a ponta

Entrar → ver a visão geral → lista de problemas → problema → enviar → minhas
submissões → uma submissão → placar, e o que envolve essas telas.

| Arquivo | O que é |
|---|---|
| `layouts/app.blade.php` | casca de todas as telas: menu (todas as seções), cabeçalho, rodapé, avisos |
| `layouts/auth.blade.php` | casca da tela de entrada |
| `auth/login.blade.php` | entrar |
| `home.blade.php` | visão geral |
| `exercises/index.blade.php` | lista de problemas |
| `exercises/show.blade.php` | problema |
| `exercises/submit.blade.php` | envio |
| `submissions.blade.php` | minhas submissões |
| `submission-show.blade.php` | uma submissão |
| `scoreboard.blade.php` | placar |
| `partials/{brand,error-summary,table-filter,verdict,locale-switcher}` | peças dessas telas |
| `components/ContestTimer.vue` | relógio da prova |
| `components/ThemeToggle.vue` | tema claro/escuro |
| `app.js` | aviso de falha ao abrir uma página de funcionalidade |

Além das views, as mensagens que os controladores mandam **para essas telas**:
as recusas do login (`routes/web.php`), as de `SubmitController` e as de
`SubmissionController`. Os textos continuam os mesmos em português — inclusive
os que estão sem acento, porque são a chave e há testes que os conferem.

**Fora da área, de propósito:** `exercises/partials/form.blade.php` é código
morto (usa o `Form::` do laravelcollective, que o projeto não tem, e nenhuma
view o inclui) e fica como está.

## 5. Medição

Mesmas receitas da issue, no `master` (`1c6ac84`) e neste PR:

| O quê | Antes | Depois |
|---|---:|---:|
| Componentes `.vue` em `resources/js` | 51 | 51 |
| ...que usam o `t()` de tradução | 0 | **2** (os 2 da área do competidor) |
| Templates `.blade.php` em `resources/views` | 101 | 102 (+ `partials/locale-switcher`) |
| ...migrados (sem texto literal, conferido por teste) | 0 | **15** |
| Chamadas `__()`, `@lang()` ou `trans()` em `resources/views` + `app` + `routes` | 8 | ver `## Medição final` abaixo |
| Chaves em `es` | 0 | ver abaixo |
| `resources/lang/pt_BR/*.php` em português | 0 de 4 | **4 de 4** |
| `<html lang>` fixo | 3 layouts | **0** |

### Medição final

(preenchida ao fim da implementação, com os comandos usados)

## 6. O que falta (a issue continua aberta)

Na ordem da própria issue e da #394:

1. **Participante, o resto:** `clarifications.blade.php`, `sos.blade.php`,
   `print.blade.php`, `more-info.blade.php` (ajuda), `wizard.blade.php`,
   `profile/`, `auth/register`, `auth/activate`, `auth/passwords/email`,
   páginas de erro (`errors/*`).
2. **Treino livre:** `features/practice*.blade.php` e `features/Practice.vue`
   (e `features/api.js`, que formata datas com `'pt-BR'` fixo — deve passar a
   ler `document.documentElement.lang`).
3. **Juiz:** `judge/*`, `features/JudgeHistory.vue`, `JudgeMachines.vue`,
   `JudgingHealth.vue`.
4. **Administração e sede:** `backend/*`, `site/*`, `staff/*`, `users/*` e as
   demais telas de `features/`. `ui/wizard.js` também fixa `'pt-BR'`.
5. **Modelos e controladores fora da área:** `ProblemBank::getDifficultyLabelAttribute()`
   (`'Facil'`/`'Medio'`/`'Dificil'`), `PracticeController` e os demais
   `withErrors`/`with('success')` que não chegam às telas migradas.
6. **`en`** como terceiro idioma — só entra no seletor com a área migrada
   completa em inglês.
7. **Idioma por usuário** (coluna em `users`), com o da sessão e o padrão da
   instalação como fallback.
8. **Mensagens do juiz** (vereditos e erros de compilação gerados pelos
   invocadores) — pergunta 2 da issue, sem resposta do mantenedor.
9. **Revisão nativa do `es`** — pergunta 3 da issue. A tradução deste PR foi
   feita com cuidado, mas não por falante nativo; está marcada assim no PR.
