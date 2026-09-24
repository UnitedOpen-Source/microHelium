# #394 — Verificação automática de acessibilidade e declaração de conformidade

## O buraco

A revisão manual de #32/#35 corrigiu foco, diálogos e erros de formulário, mas
nada impede que ela regrida: `package.json` não tinha `axe-core`, e a suíte do
Playwright (`tests/Browser/`) só confere erros de console e a CSP. Também não
havia um documento que uma instituição pudesse anexar à própria declaração de
acessibilidade (LBI art. 63, ADA Title II, EN 301 549).

## Escopo deste PR

Itens 1 e 3 das propostas da issue. Os itens 2 (roteiro com leitor de tela
executado) e 4 (espaço configurável para o símbolo de acessibilidade) ficam de
fora, e a declaração diz isso.

| Peça | Onde |
|---|---|
| `@axe-core/playwright` como dependência de desenvolvimento | `package.json` |
| Fixtures isoladas para as telas autenticadas | `tests/Browser/seed-a11y.php` (recusa qualquer banco que não seja `database/browser.sqlite`) |
| Varredura axe nas telas cobertas | `tests/Browser/accessibility.spec.js` |
| Job `browser` do CI roda o seed antes do Playwright | `.github/workflows/ci.yml` |
| Declaração de conformidade | `docs/acessibilidade.md` |

## Contrato do teste

- **Regras:** tags `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa` do axe-core. Nada
  de `best-practice` nem de WCAG 2.2: o teste mede exatamente o que a
  declaração promete (WCAG 2.1 A/AA, o denominador comum de ADA e EN 301 549).
- **Telas:** as seis que a issue #394 e o fluxo do participante pedem, mais as
  vizinhas que custam uma linha:

  | Perfil | Caminho | O que é |
  |---|---|---|
  | visitante | `/login` | login |
  | visitante | `/` | início, com a competição em andamento |
  | equipe | `/exercises` | lista de problemas |
  | equipe | `/exercise/{id}` | enunciado |
  | equipe | `/submit/{id}` | envio |
  | equipe | `/submissions` | envios e veredito |
  | equipe | `/clarifications` | clarificações |
  | equipe | `/scoreboard` | placar |
  | admin | `/home` | painel |
  | admin | `/backend/configurations` | lista de competições (backend principal) |

- **Temas:** cada tela é varrida no tema claro e no escuro, porque o contraste
  (1.4.3) depende dos dois conjuntos de tokens.
- **Falha:** qualquer violação cujo `id` de regra não esteja na lista de
  exceções conhecidas reprova o teste, com a regra, o impacto e os seletores na
  mensagem.
- **Exceções conhecidas:** lista nominal no topo do spec, por regra **e** por
  seletor, cada uma com motivo e issue. Uma exceção que deixa de acontecer
  também reprova o teste (exceção morta esconde regressão futura). Nenhuma regra
  é desligada com `disableRules`.

## Casos de teste

1. Com o fixture carregado e o bundle construído, as dez telas nos dois temas
   passam sem violação fora da lista de exceções.
2. Resultados "incompletos" do axe (precisa de revisão humana) também
   reprovam, salvo o caso nomeado em `isAcceptedIncomplete()` (contraste de
   glifo decorativo com `aria-hidden`). Sem isso, os dois defeitos corrigidos
   aqui (`aria-prohibited-attr`, `form-field-multiple-labels`) passariam: o axe
   só os relata como incompletos.
3. Negativos conferidos à mão antes do PR: tirar o `lang` do layout de
   autenticação reprova `/login` com `html-has-lang`; desfazer as duas
   correções reprova `/`, `/home` e `/submit/{id}`.
4. O seed recusa rodar contra outro banco que não `database/browser.sqlite`.

## Medição inicial (antes das correções)

- Violações WCAG 2.1 A/AA: **0** nas 20 varreduras.
- Incompletos: `aria-prohibited-attr` (selo de veredito compacto),
  `form-field-multiple-labels` (campo de arquivo do envio), e ~45 nós de
  `color-contrast` "não determinado" na barra lateral e no enunciado, que a
  janela alta (1280 × 2400) passou a medir, e que passam.

## Passos

1. Spec (este arquivo).
2. Seed + spec do Playwright; rodar e medir as violações atuais.
3. Corrigir as violações nas telas cobertas; o que não der, vira exceção
   nominal com motivo.
4. Job `browser` do CI roda o seed.
5. `docs/acessibilidade.md`, com as versões medidas e a data.
