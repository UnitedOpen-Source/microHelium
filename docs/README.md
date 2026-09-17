# Documentação do microHelium

Plataforma web para maratonas de programação no estilo ICPC: múltiplos
contests, múltiplas sedes, auto-judge distribuído em sandbox, placar com
congelamento e revelação, clarificações, rejulgamento auditável e Contest API.

## Comece por aqui

| Você quer… | Vá para |
|---|---|
| **usar o sistema** (competir, organizar, julgar, atender) | [**Manuais**](manuais/) |
| entender **como o sistema é por dentro** | [Especificação de software](#especificação-de-software) |
| saber **por que** algo foi feito de um jeito | [Decisões de projeto](#decisões-de-projeto-specs) |
| **integrar** com a API | [`api/openapi.yaml`](api/openapi.yaml) |
| **operar** algo específico | [Runbooks](#runbooks) |

---

## Manuais

Um por papel, escrito para quem vai usar — não para quem vai programar.

- [**Participante**](manuais/participante.md) — competir: enviar, acompanhar,
  clarificação, S.O.S.
- [**Organizador**](manuais/organizador.md) — montar o evento, conduzir o
  ciclo da prova, publicar o resultado.
- [**Juiz**](manuais/juiz.md) — fila, verificação, pausa de problema,
  rejulgamento.
- [**Staff e sede**](manuais/staff.md) — S.O.S., tarefas, impressão,
  coordenação de sede.

[Índice dos manuais](manuais/README.md)

---

## Especificação de requisitos (SRS)

- [**SRS — Especificação de Requisitos de Software**](software-spec/srs.md)

Documento **único, consolidado e normativo**. Usa DEVE / NÃO DEVE / PODE, e
identificadores estáveis (`RF-*`, `RNF-*`, `RN-*`, `UC-*`, `ENT-*`, `INT-*`)
para serem citados em issues, PRs, testes e decisões.

Cobre, numa peça só:

| Parte | Conteúdo |
|---|---|
| 1–2 | definição, escopo, glossário, stakeholders, arquitetura, **RN-001…RN-060** |
| 3 | **F01…F25** com critérios de aceite, não funcionais em 10 categorias, interfaces |
| 4–5 | priorização P0/P1/P2 e rastreabilidade |
| 6–9 | classes, processo de desenvolvimento, cronograma e custo |
| **A** | casos de uso detalhados **UC-01…UC-26** |
| **B** | catálogo UML: pacotes, componentes, implantação, máquinas de estado, sequência, atividades |
| **C** | modelagem de dados: DER, dicionário de entidades, integridade |
| **D** | contratos de API e integrações, incluindo CLICS |
| **E** | segurança, privacidade e fronteiras de confiança |
| **F** | estratégia de testes e critérios de aceite de release |
| **G** | eventos, auditoria e observabilidade |
| **H** | checklist operacional de competição |

Ele descreve **comportamento implementado**, não intenção. Se a tela divergir
do documento, o documento está desatualizado — abra uma issue.

Os diagramas ficam em [`software-spec/`](software-spec/): classe, caso de uso e
a fonte editável.

---

## Decisões de projeto (specs)

Uma issue que chega num ponto de decisão vira um documento aqui: o que foi
decidido, com que evidência, e **o que reabriria a decisão**.

### Ciclo da prova e placar
- [211 — O congelamento do placar](specs/211-congelamento.md)
- [223 — O estado da prova, do servidor para o relógio](specs/223-estado-no-relogio.md)
- [225 — Os atalhos legados faziam o oposto do que prometiam](specs/225-ciclo-de-encerramento.md)
- [198 — Intervalos de tempo removidos da prova](specs/198-intervalos-removidos.md)
- [202 — Finalizar a prova e derivar a premiação](specs/202-finalizar.md)
- [44 — Transmissão BOCA e cerimônia](specs/44-webcast.md)

### Julgamento
- [49 — Isolamento obrigatório do juiz](specs/49-judge-isolation.md)
- [194 — Autoteste do sandbox na máquina de julgamento](specs/194-judgehost-selftest.md)
- [53 — Exploração de julgamento distribuído](specs/53-distributed-judging.md)
- [53 — Gestão de máquinas de julgamento (fase 4)](specs/53-judge-management.md)
- [126 — Vazão de julgamento: medir antes de justificar](specs/126-judging-throughput.md)
- [196 — Tempo medido, fase 1: medir e avisar](specs/196-tempo-medido.md)
- [251 — O limite derivado, e o que "por host" quer dizer](specs/251-limite-de-tempo-derivado.md)
- [45 — Recuperação de envios sem atividade](specs/45-watchdog.md)
- [192 — Rejulgamento em lote, com prévia](specs/192-rejulgamento.md)

### Problemas e pacotes
- [200 — Importar o formato de pacote da ICPC/Kattis](specs/200-pacote-icpc.md)
- [242 — Caminhos que saem do próprio pacote](specs/242-zip-caminhos.md)
- [147 — Importação de evento por arquivo](specs/147-event-import.md)
- [46 — Propriedade do banco e etiquetas](specs/46-bank-ownership.md)
- [42 — Análise de similaridade](specs/42-similarity.md)
- [43 — Treino Livre, fase 1](specs/43-practice.md)

### Integração
- [195 — A Contest API da ICPC, fase 1](specs/195-contest-api.md)
- [219 — O event feed da Contest API](specs/219-event-feed.md)

### Pessoas, sedes e acesso
- [188 — Gestão de organizações](specs/188-organizacoes.md)
- [47 — Contas gerenciadas e privacidade](specs/47-managed-accounts.md)
- [48 — Consolidar autorização sem mudar comportamento](specs/48-authorization.md)
- [146 — Sede sem link com o servidor central](specs/146-site-connectivity.md)

### Deploy
- [54 — Rollback por imagem e smoke pós-deploy](specs/54-deployment-verification.md)

---

## Runbooks

Procedimento para executar quando chegar a hora.

- [252 — Homologação do streaming do event feed](runbooks/252-event-feed-streaming.md)
  — o `curl -N` exato, o critério de passa/falha, e a homologação com um
  resolver de verdade. **Rode antes de qualquer prova que use resolver.**

---

## Frontend

- [Design](frontend-design.md)
- [Acessibilidade](frontend-accessibility.md)
- [Auditoria de prontidão de release](frontend-release-audit.md)
- [Auditoria visual](frontend-visual-audit.md)
- [Revisão de workspaces por issue](frontend-issue-workspaces-review.md)

---

## Handoffs

[`handoffs/`](handoffs/) — estado do trabalho no momento em que uma sessão
terminou, para outra pessoa (ou outra sessão) retomar sem redescobrir
contexto.

---

## Como manter isto vivo

- Mudou **papel, rota, entidade, ciclo de vida, julgamento ou placar**? A
  especificação e os manuais mudam **no mesmo PR** — ou o PR justifica por
  que não há impacto documental.
- Uma decisão arquitetural vira `docs/specs/<issue>-<tema>.md`, com a
  evidência e o que a reabriria.
- Um procedimento que alguém vai executar vira `docs/runbooks/`.
- Um manual descreve **o que o sistema faz**, não o que se pretendia que ele
  fizesse. Divergiu da tela? O manual está errado.
