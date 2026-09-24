# Declaração de acessibilidade do microHelium

**Versão da declaração:** 1 · **Data da medição:** 24/09/2026 · **Issue:** [#394](https://github.com/UnitedOpen-Source/microHelium/issues/394)

Este documento diz **o que foi medido** na interface do microHelium, **com qual
ferramenta** e **o que não foi medido**. Não é uma declaração de conformidade
integral com a WCAG 2.1 AA, e não deve ser apresentado como tal. É o insumo
técnico que a instituição que hospeda o microHelium pode anexar à própria
declaração (LBI art. 63, EN 301 549, ADA Title II / ACR), completando-o com a
verificação manual que ainda falta.

## Resumo

| Pergunta | Resposta |
|---|---|
| Norma de referência | WCAG 2.1, níveis A e AA |
| Estado | **Parcialmente verificado.** Nenhuma violação automática detectada nas telas cobertas; a parte da WCAG que exige julgamento humano não foi verificada |
| Verificação automática | axe-core no Playwright, em todo push e pull request (job `browser` do CI) |
| Leitor de tela | **Não verificado** |
| Navegação só por teclado, de ponta a ponta | **Não verificada** como fluxo; partes cobertas por testes de DOM (ver abaixo) |
| Conteúdo enviado pela organização (enunciados em PDF, imagens) | Fora desta declaração: é responsabilidade de quem publica |

## O que é conferido automaticamente

**Ferramentas, nas versões usadas nesta medição:**

| Ferramenta | Versão |
|---|---|
| axe-core | 4.13.0 |
| @axe-core/playwright | 4.13.0 |
| @playwright/test | 1.63.0 |
| Chromium (do Playwright) | 153.0.8010.12 |

**Regras:** as 69 regras do axe-core com as tags `wcag2a`, `wcag2aa`,
`wcag21a` e `wcag21aa`. Nenhuma regra está desligada. Regras de "boas
práticas" e de WCAG 2.2 não fazem parte da medição.

**Telas**, cada uma no tema claro **e** no escuro (20 varreduras), com dados de
exemplo carregados por `tests/Browser/seed-a11y.php`, e janela de 1280 × 2400
px para que toda a barra lateral fique visível e o contraste dela seja medido:

| Perfil | Tela | Caminho |
|---|---|---|
| visitante | login | `/login` |
| visitante | início | `/` |
| equipe | lista de problemas | `/exercises` |
| equipe | enunciado | `/exercise/{id}` |
| equipe | envio | `/submit/{id}` |
| equipe | envios e veredito | `/submissions` |
| equipe | clarificações | `/clarifications` |
| equipe | placar | `/scoreboard` |
| administração | painel | `/home` |
| administração | lista de competições | `/backend/configurations` |

**Critério de falha:** o teste (`tests/Browser/accessibility.spec.js`) reprova
com qualquer violação dessas regras e também com qualquer resultado que o axe
marca como "incompleto" (precisa de revisão humana), exceto um caso nomeado e
justificado no próprio teste: contraste de um glifo decorativo já oculto de
tecnologia assistiva (o ✓ do veredito, a → de um botão). Exceções a violações
só entram por regra **e** seletor, com motivo; hoje a lista está **vazia**.

### Resultado da medição de 24/09/2026

- **Violações:** 0 nas 20 varreduras, antes e depois das correções deste PR.
- **Resultados "incompletos"** (o axe não decidiu), encontrados e tratados:

| Achado | Onde | Critério | Tratamento |
|---|---|---|---|
| `aria-prohibited-attr`: nome acessível por `aria-label` num `<span>` sem papel, o que a ARIA 1.2 proíbe; a tecnologia assistiva pode ignorar o nome e ler só "AC" em vez de "Aceito" (não conferido com leitor de tela) | selo de veredito compacto em `/` e `/home` (`resources/views/partials/verdict.blade.php`) | 4.1.2 | **Corrigido**: o nome completo é texto real, visualmente oculto; o código curto fica oculto da tecnologia assistiva |
| `form-field-multiple-labels`: dois `<label for>` no mesmo campo de arquivo | `/submit/{id}` (`resources/views/exercises/submit.blade.php`) | 3.3.2 | **Corrigido**: um único `<label>` (a área de soltar), e o título nomeia o campo por `aria-labelledby` |
| `color-contrast` "não determinado": links da barra lateral fora da área visível do contêiner com rolagem | `/home`, `/backend/configurations` (≈ 20 nós por tela) | 1.4.3 | **Medido**: com a janela alta o axe calcula o contraste desses links, e eles passam |
| `color-contrast` "não determinado": parágrafo que se sobrepõe a outro elemento | `/exercise/{id}` | 1.4.3 | **Medido**: passa com a janela alta |
| `color-contrast` em glifo decorativo (✓, →) | `/`, `/home`, `/submissions` | — | **Aceito, com motivo**: não é texto, já está com `aria-hidden`, e tem a cor do texto ao lado, que é medido |

Reintroduzir qualquer um dos dois defeitos corrigidos reprova o teste; retirar
o `lang` do `<html>` reprova `/login` com `html-has-lang`. Os três casos foram
conferidos à mão antes do PR.

## O que **não** é conferido

Uma ferramenta automática encontra só parte das falhas de acessibilidade. As
seguintes não foram verificadas e **não se afirma que atendem**:

- **Leitor de tela real** (NVDA, Orca, VoiceOver, TalkBack): ordem de leitura,
  anúncios, o placar que se atualiza sozinho. É a proposta 2 da #394, pendente.
- **Teclado de ponta a ponta:** ordem de foco e ausência de armadilha em cada
  fluxo. Há testes de DOM para diálogos, formulários e assistente
  (`tests/Frontend/accessibility.test.js`, revisão de #32/#35 em
  [`frontend-accessibility.md`](frontend-accessibility.md)), mas não um
  percurso completo por teclado nas telas acima.
- **Telas fora da lista:** as demais telas de administração, juiz, staff e
  sede, o Treino Livre e o editor Scratch (#267/#268, que herda a
  acessibilidade do próprio Scratch).
- **Estados que a varredura não abre:** diálogos abertos, mensagens de erro de
  validação, menus expandidos, placar congelado.
- **Zoom e reflow** (1.4.4, 1.4.10), espaçamento de texto (1.4.12),
  conteúdo em foco ou hover (1.4.13), com exceção do que as regras do axe
  listadas abaixo cobrem.
- **Outros navegadores:** só Chromium.
- **Conteúdo da organização:** enunciados, imagens e anexos publicados por
  quem organiza a competição.

## Critério por critério (WCAG 2.1 A e AA)

Legenda:

- **Auto, sem falha:** há regras do axe para o critério, e nenhuma falhou nas
  telas cobertas. **Não** quer dizer que o critério é atendido: o axe cobre só
  a parte mecânica dele.
- **Não verificado:** nenhuma regra automática cobre o critério e não houve
  verificação manual datada. Não se afirma nada.

| Critério | Nível | Estado | Regras do axe / observação |
|---|---|---|---|
| 1.1.1 Conteúdo não textual | A | Auto, sem falha | `image-alt`, `svg-img-alt`, `role-img-alt`, `input-image-alt`, `object-alt`, `aria-meter-name`, `aria-progressbar-name` |
| 1.2.1 Somente áudio e somente vídeo | A | Auto, sem falha | `audio-caption` só detecta áudio sem alternativa; não há mídia nas telas cobertas |
| 1.2.2 Legendas (pré-gravadas) | A | Auto, sem falha | `video-caption`; idem |
| 1.2.3 Audiodescrição ou alternativa | A | Não verificado | |
| 1.2.4 Legendas (ao vivo) | AA | Não verificado | |
| 1.2.5 Audiodescrição (pré-gravada) | AA | Não verificado | |
| 1.3.1 Informações e relações | A | Auto, sem falha | `list`, `listitem`, `definition-list`, `dlitem`, `td-has-header`, `th-has-data-cells`, `td-headers-attr`, `table-fake-caption`, `p-as-heading`, `aria-required-children`, `aria-required-parent` |
| 1.3.2 Sequência significativa | A | Não verificado | |
| 1.3.3 Características sensoriais | A | Não verificado | |
| 1.3.4 Orientação | AA | Auto, sem falha | `css-orientation-lock` |
| 1.3.5 Identificar o propósito da entrada | AA | Auto, sem falha | `autocomplete-valid` |
| 1.4.1 Uso de cor | A | Auto, sem falha | `link-in-text-block` apenas |
| 1.4.2 Controle de áudio | A | Auto, sem falha | `no-autoplay-audio` |
| 1.4.3 Contraste (mínimo) | AA | Auto, sem falha | `color-contrast`, nos dois temas; também `tests/Frontend/design-tokens.test.js` (tokens a 4,5:1) |
| 1.4.4 Redimensionar texto | AA | Auto, sem falha | `meta-viewport` apenas; zoom de 200% não verificado |
| 1.4.5 Imagens de texto | AA | Não verificado | |
| 1.4.10 Reflow | AA | Não verificado | `tests/Browser/release-audit.spec.js` (opcional) mede rolagem horizontal em 375 px em três telas de administração |
| 1.4.11 Contraste não textual | AA | Não verificado | tokens de borda e foco a 3:1 em `design-tokens.test.js`; não medido na página renderizada |
| 1.4.12 Espaçamento de texto | AA | Auto, sem falha | `avoid-inline-spacing` apenas |
| 1.4.13 Conteúdo em foco ou hover | AA | Não verificado | |
| 2.1.1 Teclado | A | Auto, sem falha | `scrollable-region-focusable`, `frame-focusable-content`, `server-side-image-map`; fluxo completo não verificado |
| 2.1.2 Sem bloqueio do teclado | A | Não verificado | diálogos: `tests/Frontend/accessibility.test.js` |
| 2.1.4 Atalhos de teclado por caractere | A | Não verificado | |
| 2.2.1 Tempo ajustável | A | Auto, sem falha | `meta-refresh` apenas |
| 2.2.2 Pausar, parar, ocultar | A | Auto, sem falha | `blink`, `marquee` apenas; placar com atualização automática não verificado |
| 2.3.1 Três flashes | A | Não verificado | |
| 2.4.1 Ignorar blocos | A | Auto, sem falha | `bypass` |
| 2.4.2 Página com título | A | Auto, sem falha | `document-title` |
| 2.4.3 Ordem do foco | A | Não verificado | diálogos: `tests/Frontend/accessibility.test.js` |
| 2.4.4 Finalidade do link (em contexto) | A | Auto, sem falha | `link-name`, `area-alt` |
| 2.4.5 Várias formas | AA | Não verificado | |
| 2.4.6 Cabeçalhos e rótulos | AA | Não verificado | |
| 2.4.7 Foco visível | AA | Não verificado | tokens de foco a 3:1 em `design-tokens.test.js` |
| 2.5.1 Gestos de ponteiro | A | Não verificado | |
| 2.5.2 Cancelamento de ponteiro | A | Não verificado | |
| 2.5.3 Rótulo no nome | A | Auto, sem falha | `label-content-name-mismatch` |
| 2.5.4 Atuação por movimento | A | Não verificado | |
| 3.1.1 Idioma da página | A | Auto, sem falha | `html-has-lang`, `html-lang-valid`, `html-xml-lang-mismatch`. O `lang="pt-BR"` é fixo nos layouts; fica errado no primeiro idioma novo (#397) |
| 3.1.2 Idioma de partes | AA | Auto, sem falha | `valid-lang` |
| 3.2.1 Em foco | A | Não verificado | |
| 3.2.2 Em entrada | A | Não verificado | |
| 3.2.3 Navegação consistente | AA | Não verificado | |
| 3.2.4 Identificação consistente | AA | Não verificado | |
| 3.3.1 Identificação do erro | A | Não verificado | `tests/Frontend/accessibility.test.js` (erros associados por `aria-describedby`) |
| 3.3.2 Rótulos ou instruções | A | Auto, sem falha | `form-field-multiple-labels` (corrigido neste PR) |
| 3.3.3 Sugestão de erro | AA | Não verificado | |
| 3.3.4 Prevenção de erros (legal, financeiro, dados) | AA | Não verificado | |
| 4.1.1 Análise sintática | A | Não verificado | obsoleto na WCAG 2.2; o axe 4.13 não tem regra para ele |
| 4.1.2 Nome, função, valor | A | Auto, sem falha | `label`, `button-name`, `select-name`, `aria-*` (valid, allowed, prohibited, required), `nested-interactive`, `aria-hidden-focus`, `frame-title`, e outras |
| 4.1.3 Mensagens de status | AA | Não verificado | |

Totais: 50 critérios; **22** com verificação automática parcial sem falha,
**28** não verificados. Nenhum critério é declarado "atende".

## Como reproduzir

Igual ao job `browser` do CI (`.github/workflows/ci.yml`):

```sh
touch database/browser.sqlite
DB_CONNECTION=sqlite DB_DATABASE=database/browser.sqlite php artisan migrate --force
APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE=database/browser.sqlite php tests/Browser/seed-a11y.php
npm ci && npm run build && npx playwright install chromium
APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE=database/browser.sqlite \
  CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=sync \
  npx playwright test tests/Browser/accessibility.spec.js
```

## Como manter esta declaração

- Atualize a data, as versões e a tabela de resultado quando o teste mudar ou
  quando as ferramentas forem atualizadas.
- Uma tela nova no fluxo do participante entra em `SCREENS`, no teste, e na
  tabela de telas acima.
- Um critério só passa de "Não verificado" com evidência datada: um teste, ou
  um roteiro manual executado e registrado em `docs/runbooks/`.

## Pendências da #394

- Roteiro com leitor de tela (NVDA, Orca) executado e datado.
- Espaço configurável no rodapé para o símbolo e o link de acessibilidade
  (LBI art. 63, §1º).
- Decisões pedidas ao mantenedor: meta 2.1 AA ou 2.2 AA; visão estável do
  placar; Scratch explicitamente fora.
