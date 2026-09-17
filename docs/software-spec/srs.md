# microHelium — Especificação de Requisitos de Software

**Projeto:** microHelium
**Repositório:** `UnitedOpen-Source/microHelium`
**Organização:** United Open Source

Documento único e consolidado. Descreve o sistema **como ele é**: papéis,
arquitetura, regras de negócio, requisitos funcionais e não funcionais,
interfaces e rastreabilidade.

> Este documento descreve **comportamento implementado**. Onde a tela divergir
> do que está escrito aqui, o documento está errado — abra uma issue. As
> decisões de projeto por trás de cada comportamento ficam em
> [`docs/specs/`](../specs/); os manuais de uso, em
> [`docs/manuais/`](../manuais/).

---

## Sumário

1. [Introdução](#1-introdução)
2. [Visão geral do sistema](#2-visão-geral-do-sistema)
3. [Requisitos](#3-requisitos)
4. [Priorização de requisitos](#4-priorização-de-requisitos)
5. [Rastreabilidade](#5-rastreabilidade)
6. [Modelo de dados](#6-modelo-de-dados)
7. [Rotas e superfícies](#7-rotas-e-superfícies)
8. [Deploy e operação](#8-deploy-e-operação)
9. [Testes e critérios de aceite](#9-testes-e-critérios-de-aceite)

---

# 1. Introdução

## 1.1 Definição da solução

O microHelium é uma plataforma web para organizar e executar **competições de
programação no estilo ICPC**: as equipes recebem um conjunto de problemas,
submetem código-fonte, e o sistema **compila e executa** esse código contra
casos de teste ocultos, em ambiente isolado, devolvendo um veredito.

O problema que ele resolve é o de uma competição não caber num sistema
genérico de submissão: ela tem um relógio oficial, um placar que congela antes
do fim, uma banca que precisa corrigir erros sem apagar história, várias sedes
físicas acontecendo ao mesmo tempo, e a obrigação de produzir um resultado que
possa ser **explicado e auditado** depois.

## 1.2 Escopo

**Está no escopo:**

- ciclo de vida completo da competição, do rascunho à publicação do resultado;
- cadastro de pessoas, equipes, organizações e sedes, com papéis;
- problemas, casos de teste ocultos, banco de problemas e importação de
  pacotes nos formatos BOCA e ICPC/Kattis;
- recebimento, compilação, execução e julgamento de código submetido, em
  sandbox, distribuído entre várias máquinas de julgamento;
- placar ICPC com congelamento, revelação e finalização com premiação;
- clarificações, filas operacionais (S.O.S., tarefas, impressão);
- operação multi-sede a partir de uma instalação central;
- auditoria de decisões, eventos e rejulgamentos;
- API autenticada e Contest API da ICPC, incluindo *event feed*.

**Está fora do escopo:**

- substituir a infraestrutura física das sedes;
- operação **offline** por sede — uma sede sem link com o servidor central
  perde as operações online até a conexão voltar;
- executar código submetido dentro do processo web: isso exige o ambiente de
  isolamento do judgehost;
- decidir por quem organiza questões de regulamento (plágio, número de
  medalhas, uso de internet pelas equipes). O sistema fornece a evidência; a
  decisão é humana.

## 1.3 Público-alvo

Organizadores de competições de programação, bancas técnicas, equipes de
apoio, coordenadores de sede e equipes competidoras. Também integradores que
consomem a API e ferramentas padrão da ICPC, como *resolvers*.

## 1.4 Definições, acrônimos e abreviações

| Termo | Definição |
|---|---|
| **Contest** | a competição: nome, início, duração, congelamento, penalidade, visibilidade. Pode haver vários; um é o evento ativo |
| **Problema** | item da prova: enunciado, casos de teste, limites de tempo e memória |
| **Caso de teste** | par entrada/saída esperada. Os **secretos** nunca são expostos a competidores |
| **Run / submissão** | um envio de código-fonte por uma equipe para um problema |
| **Veredito** | resultado do julgamento de uma run |
| **Judgehost** | máquina que compila e executa código submetido, em sandbox |
| **Sandbox** | ambiente de isolamento (bubblewrap + cgroup v2) onde o código roda |
| **Lease / claim** | reserva de uma run por um judgehost, para que dois não julguem a mesma |
| **Sede (*site*)** | local físico da competição. Segmentação lógica, não instalação autônoma |
| **Placar (*scoreboard*)** | classificação: problemas resolvidos e penalidade de tempo |
| **Congelamento (*freeze*)** | período final em que o placar público para de refletir envios novos |
| **Revelação (*unfreeze*)** | ato de descongelar o placar — a cerimônia |
| **Finalização** | fechamento do resultado, com corte de classificação e premiação |
| **Clarificação** | pergunta oficial de uma equipe à banca sobre um enunciado |
| **S.O.S.** | fila operacional para problemas físicos, distinta da fila de julgamento |
| **Rejulgamento (*rejudge*)** | rejulgar runs já julgadas, com prévia e motivo registrados |
| **Ajuste de tempo** | intervalo removido da prova, que estende o fim sem reescrever horários |
| **Banco de problemas** | acervo de problemas reutilizáveis entre edições |
| **CLICS** | *Contest API* padrão da ICPC |
| **Event feed** | fluxo NDJSON de eventos do contest, consumido por *resolvers* |
| **RBAC** | controle de acesso baseado em papéis |

**Veredictos:**

| Sigla | Nome | Significado |
|---|---|---|
| AC | Accepted | aceito |
| WA | Wrong Answer | saída incorreta |
| TLE | Time Limit Exceeded | excedeu o tempo |
| MLE | Memory Limit Exceeded | excedeu a memória |
| RE | Runtime Error | falha em execução |
| CE | Compilation Error | não compilou |
| PE | Presentation Error | saída certa, formatação errada |
| CS | Contact Staff | situação fora do comum |

## 1.5 Referências

- ICPC **Contest API / CLICS** — formato do event feed e dos recursos do
  contest.
- **Problem Package Format** (ICPC/Kattis), versões *legacy* e *2025-09*.
- **DOMjudge** — documentação de judgehosts e de importação de pacotes.
- **BOCA** — formato de pacote de problema suportado na importação.
- [`docs/api/openapi.yaml`](../api/openapi.yaml) — especificação da API.
- [`docs/specs/`](../specs/) — decisões de projeto, uma por issue.
- [`docs/runbooks/`](../runbooks/) — procedimentos operacionais.
- [`docs/manuais/`](../manuais/) — manuais por papel.

## 1.6 Organização do documento

A seção 2 dá a visão geral: stakeholders, arquitetura, controle de acesso,
ciclo de vida e regras de negócio. A seção 3 lista os requisitos funcionais e
não funcionais. As seções 4 e 5 tratam de prioridade e rastreabilidade. As
seções 6 a 9 descrevem modelo de dados, superfícies, deploy e critérios de
aceite.

---

# 2. Visão geral do sistema

## 2.1 Stakeholders e responsabilidades

| Papel | Necessidade | Responsabilidade no sistema |
|---|---|---|
| **Administrador** (`admin`) | configurar e conduzir a competição | contests, usuários, problemas, banco, organizações, sedes, ciclo de vida, configurações. Maior autoridade funcional |
| **Juiz** (`judge`) | garantir julgamento correto | fila de runs, verificação de vereditos, pausa de problema defeituoso, clarificações, rejulgamento |
| **Staff** (`staff`) | fazer o evento acontecer | tarefas, impressão, S.O.S., relatório operacional |
| **Coordenador de sede** (`site`) | operar uma sede | equipes, tarefas, clarificações e chamados **daquela sede** |
| **Equipe** (`team`) | competir | consultar problemas, submeter, acompanhar envios, placar, clarificação, S.O.S. |
| **Painel** (`score`) | exibir o placar | leitura de placar |
| **Serviço** (`system`) | integração automatizada | contas de serviço; tratadas como `admin` na autorização |
| **Público** | acompanhar | superfícies explicitamente públicas, respeitando visibilidade e congelamento |

**Princípio de atribuição:** o menor papel que resolve. Coordenar uma sede não
exige `admin`; `site` existe para isso.

## 2.2 Arquitetura

```
Navegador (Vue 3)            Consumidor de API
      |                             |
routes/web.php               routes/api.php
Auth / Role / Throttle       Sanctum + gates
      \                           /
              Controllers
                  |
            Serviços de domínio
          /       |       \        \
   Modelos    Storage   Fila de runs  ContestLog/Eventos
      |                      |
   Banco SQL            Judgehost
                             |
                         Sandbox (bubblewrap + cgroup v2)
                             |
                     Compiladores / runtimes
```

**Backend:** PHP 8.3+, Laravel 13, Laravel Sanctum (tokens), Spatie Permission
(papéis), Symfony YAML. Domínio distribuído entre modelos Eloquent,
controllers, policies, middleware e serviços.

**Frontend:** Vue 3, Vite, Tailwind CSS 4, Chart.js. Páginas Blade com ilhas
Vue montadas por `features/mount.js` a partir de `data-attributes`, e
endpoints dedicados ao frontend.

**Persistência:** MySQL, PostgreSQL ou SQLite. Migrations são a fonte de
evolução do esquema.

**Julgamento:** processo separado do web. Judgehosts reivindicam runs por
*lease*, compilam e executam em sandbox, e devolvem veredito e medições.

## 2.3 Controle de acesso

A API separa **duas superfícies**: a do **competidor**, com o que é preciso
para jogar, e a de **staff/juiz/administração**, com as operações
privilegiadas.

```
requisição → autenticado?
   não → rotas públicas / login / emissão de token
   sim → tipo de operação
          competidor  → escopo próprio + dados publicados
          julgamento  → exige judge ou admin, senão 403
          administração → exige admin, senão 403
```

**Regras centrais:**

- autenticação web por **sessão**; API por **token Sanctum**, revogável pelo
  titular;
- login pode ser limitado por **endereço/rede da sede** associada, e é
  sujeito a limite de tentativas;
- **runs são registros append-only**: erro de julgamento se corrige por
  rejulgamento, nunca por edição do envio;
- **sobrescrever veredito à mão** exige confirmação de senha **a cada ação** e
  gera registro próprio — distinto de *verificar*, que é aprovar o que a
  máquina já disse;
- **casos de teste ocultos e pacotes completos** ficam fora da superfície de
  equipe;
- a visão de competidor aplica a política de congelamento; a de staff pode
  ignorá-la;
- um contest **privado** (padrão) não é visível a anônimo: nem placar, nem
  lista de problemas, nem relógio.

## 2.4 Ciclo de vida da competição

```
Rascunho → Agendado → Em andamento → Congelado → Encerrado → Finalizado → Publicado
                           ↑ ajuste de tempo ↑
```

**Encerrar e revelar são atos distintos.** Encerrar a prova não publica o
resultado: o placar pode permanecer congelado até a cerimônia. É isso que
permite revelar o pódio ao vivo.

- **Congelamento** é dado em **minutos antes do fim**. `freeze_time = 0`
  significa *não congela*.
- **"Encerrar agora" encerra no instante do comando**, não ao fim do minuto
  corrente.
- **Ajustes de tempo** representam interrupções (queda de energia, problema
  defeituoso) como **intervalos removidos**. Não reescrevem horários já
  gravados; o fim da prova passa a considerá-los, e por isso o horário de
  término vem do servidor.
- **Finalização** deriva o corte de classificação e a premiação. As
  quantidades de medalhas nascem **zeradas**: declarar medalhista é afirmação
  para fora, e o sistema não arbitra isso.
- O **relógio do cliente** usa estado e hora do servidor, nunca o relógio da
  máquina de quem olha.

## 2.5 Regras de negócio

| ID | Regra |
|---|---|
| **RN-01** | O placar ordena por **problemas resolvidos** (desc), depois por **penalidade de tempo** (asc) |
| **RN-02** | A penalidade de um problema resolvido é o **minuto do AC** mais um valor fixo por **cada envio anterior ao AC** naquele problema |
| **RN-03** | Envios em problemas **não resolvidos** não geram penalidade |
| **RN-04** | Envios **posteriores** ao AC de um problema não são contados |
| **RN-05** | A **ordem** dos envios vem do tempo bruto; o **valor** exibido e pago vem do tempo ajustado. Dois envios dentro de um intervalo removido não empatam |
| **RN-06** | Durante o congelamento a equipe continua submetendo e recebendo **seus próprios** vereditos; o que para é a visão pública |
| **RN-07** | Uma prova encerrada **continua congelada** até alguém revelar |
| **RN-08** | Um problema **pausado** retém os envios na fila em vez de devolver veredito |
| **RN-09** | Rejulgamento em lote exige **prévia** e **motivo**; runs aceitas ficam de fora salvo inclusão explícita |
| **RN-10** | Dois judgehosts não podem julgar a mesma run: a reivindicação é atômica |
| **RN-11** | Uma run que nenhum judgehost pode julgar é **devolvida com motivo**, em vez de circular indefinidamente |
| **RN-12** | Código submetido **nunca** executa sem confinamento. Sandbox indisponível é falha, não aviso |
| **RN-13** | Um pacote de problema que **não declara** limite de tempo é recusado na importação, em vez de receber um valor arbitrário |
| **RN-14** | Contests de **prática** nunca são o evento oficial, e envios de prática não entram no placar |
| **RN-15** | Todo caminho de arquivo extraído de um pacote deve permanecer **dentro** do próprio pacote |

## 2.6 Casos de uso

| Caso de uso | Ator | Descrição |
|---|---|---|
| **UC-01** Configurar competição | Administrador | criar contest, definir tempo, congelamento, penalidade e visibilidade |
| **UC-02** Gerir pessoas e papéis | Administrador | criar contas, atribuir papéis, vincular a contest, sede e organização |
| **UC-03** Cadastrar problemas | Administrador | criar manualmente ou importar pacote BOCA / ICPC-Kattis; gerir casos de teste |
| **UC-04** Autenticar | Todos | sessão (web) ou token (API), sujeito a limite de tentativas e política de rede |
| **UC-05** Consultar problemas | Equipe, público | listar e abrir problemas autorizados, com enunciado e limites |
| **UC-06** Submeter solução | Equipe | enviar código-fonte para um problema, escolhendo a linguagem |
| **UC-07** Julgar automaticamente | Sistema | reivindicar run, compilar e executar em sandbox, registrar veredito e medições |
| **UC-08** Verificar veredito | Juiz | confirmar ou desfazer confirmação de um veredito retido |
| **UC-09** Sobrescrever veredito | Juiz | registrar veredito manual, com senha e registro próprio |
| **UC-10** Pausar problema | Juiz | suspender o julgamento de um problema com defeito, e retomar |
| **UC-11** Rejulgar | Juiz | criar conjunto com prévia e motivo; aplicar ou cancelar |
| **UC-12** Acompanhar envios | Equipe | listar os próprios envios e ver o detalhe de cada um |
| **UC-13** Consultar placar | Todos | ver a classificação conforme visibilidade e congelamento |
| **UC-14** Clarificar | Equipe, Juiz | perguntar sobre um enunciado; responder de forma privada ou em broadcast |
| **UC-15** Pedir socorro | Equipe, Staff | abrir chamado operacional; reconhecer e resolver |
| **UC-16** Pedir impressão | Equipe, Staff | solicitar impressão; executar e concluir a tarefa |
| **UC-17** Conduzir o ciclo | Administrador | congelar, encerrar, revelar e finalizar; registrar ajustes de tempo |
| **UC-18** Operar sede | Coordenador de sede | acompanhar equipes, tarefas, clarificações e chamados da sede |
| **UC-19** Integrar | Consumidor de API | consumir Contest API e event feed com token |
| **UC-20** Auditar | Administrador | consultar eventos, operações sensíveis e histórico de julgamento |

---

# 3. Requisitos

## 3.1 Requisitos funcionais

| ID | Requisito |
|---|---|
| **RF-01** | Criar, configurar, ativar, congelar, encerrar, finalizar e publicar contests, conforme papel autorizado |
| **RF-02** | Gerir usuários, papéis, sedes e organizações |
| **RF-03** | Criar e importar problemas, e administrar casos de teste ocultos |
| **RF-04** | Permitir que equipes consultem enunciados e submetam código-fonte |
| **RF-05** | Enfileirar runs e distribuí-las com segurança para judgehosts **compatíveis** |
| **RF-06** | Compilar e executar código em sandbox, registrando veredito e medições de tempo e memória |
| **RF-07** | Exigir verificação manual quando configurada, e permitir verificar e desfazer por staff autorizado |
| **RF-08** | Calcular e exibir o placar, com congelamento e revelação controlada |
| **RF-09** | Permitir clarificações privadas e em broadcast, com escopo correto |
| **RF-10** | Permitir rejulgamento individual e em lote, com prévia e auditoria |
| **RF-11** | Registrar eventos do contest e operações sensíveis |
| **RF-12** | Operar múltiplas sedes a partir de uma instalação central |
| **RF-13** | Permitir ajustes de tempo e refletir a hora oficial do servidor no cliente |
| **RF-14** | Oferecer filas operacionais de staff: tarefas, impressão e S.O.S. |
| **RF-15** | Exportar placar e relatórios em formatos suportados |
| **RF-16** | Fornecer API autenticada por tokens revogáveis |
| **RF-17** | Suportar banco de problemas, com propriedade e compartilhamento governados |
| **RF-18** | Suportar ambientes de prática sem confundi-los com o evento oficial |
| **RF-19** | Provar, na máquina que vai julgar, que o sandbox realmente confina (`judgehost:selftest`) |
| **RF-20** | Medir o tempo por máquina e **avisar** quando os judgehosts não forem comparáveis |
| **RF-21** | Expor a Contest API da ICPC e o event feed consumível por *resolvers* |
| **RF-22** | Detectar similaridade entre códigos submetidos, quando habilitado |
| **RF-23** | Gerar ponto de restauração (backup) sob comando |
| **RF-24** | Recuperar runs sem atividade, devolvendo-as à fila |

## 3.2 Requisitos não funcionais

### 3.2.1 Usabilidade

| ID | Requisito |
|---|---|
| **RNF-U1** | Linguagem consistente entre telas; HTML semântico e foco navegável por teclado |
| **RNF-U2** | Mensagens de erro associadas ao campo que as causou |
| **RNF-U3** | Estado e veredito nunca dependem **apenas** de cor; contraste suficiente |
| **RNF-U4** | O usuário sabe, a todo momento, em que estado a prova está |
| **RNF-U5** | Um envio não concluído pede confirmação antes de ser descartado |

### 3.2.2 Confiabilidade

| ID | Requisito |
|---|---|
| **RNF-C1** | Reivindicação de run atômica: dois workers não julgam o mesmo item |
| **RNF-C2** | Reconciliação e nova tentativa **idempotentes** e auditáveis |
| **RNF-C3** | Run abandonada por judgehost que caiu volta para a fila |
| **RNF-C4** | Backup e trilha de auditoria suficientes para recuperação e investigação |
| **RNF-C5** | *Health checks* para a aplicação e para a API |

### 3.2.3 Desempenho

| ID | Requisito |
|---|---|
| **RNF-D1** | Workers de julgamento escalam horizontalmente enquanto o banco suportar concorrência |
| **RNF-D2** | SQLite serve para desenvolvimento, **não** para julgamento de alta concorrência |
| **RNF-D3** | O event feed entrega cada linha **enquanto** a prova acontece, sem bufferizar até o fim |

### 3.2.4 Suportabilidade

| ID | Requisito |
|---|---|
| **RNF-S1** | Migrations são a fonte de evolução do esquema |
| **RNF-S2** | A especificação OpenAPI acompanha mudanças da API |
| **RNF-S3** | Testes de autorização cobrem toda rota autenticada nova |
| **RNF-S4** | Decisões arquiteturais ficam registradas em `docs/specs/`, vinculadas a issues |
| **RNF-S5** | Mudança em papel, rota, entidade, ciclo de vida, julgamento ou placar atualiza esta especificação **no mesmo PR** |

### 3.2.5 Segurança

| ID | Requisito |
|---|---|
| **RNF-G1** | Menor privilégio por papel e por superfície |
| **RNF-G2** | Casos de teste, fontes de terceiros e pacotes completos nunca expostos a competidores |
| **RNF-G3** | Senhas com hashing do framework; tokens revogáveis |
| **RNF-G4** | Login sujeito a limite de tentativas e, quando configurado, a restrição de rede por sede |
| **RNF-G5** | Código não confiável executa **fora** do processo web, em sandbox, sem acesso à raiz da aplicação nem a segredos |
| **RNF-G6** | Limite de memória por mecanismo do SO compatível com runtimes que reservam grande espaço virtual |
| **RNF-G7** | Operações críticas de ciclo de vida e julgamento são auditáveis |
| **RNF-G8** | Caminhos extraídos de pacotes são recusados se saírem do próprio pacote |

### 3.2.6 Restrições de projeto

| ID | Restrição |
|---|---|
| **RNF-R1** | Multi-sede pressupõe conectividade estável com o servidor central; não há modo offline por sede |
| **RNF-R2** | O congelamento é do contest, não da sede |
| **RNF-R3** | O judgehost exige ambiente Linux com bubblewrap e cgroup v2 |
| **RNF-R4** | Heterogeneidade de hardware é **avisada, não compensada** |

## 3.3 Interfaces

**Usuário.** Aplicação web responsiva, com superfícies distintas por papel.

**Software.** Compiladores e runtimes instalados nos judgehosts; bubblewrap e
cgroup v2 para isolamento; banco relacional; armazenamento de arquivos.

**Comunicação.** HTTP/HTTPS. API REST autenticada por token. Contest API em
JSON e event feed em `application/x-ndjson`, servido em streaming.

**Hardware.** Judgehosts devem ter configuração **uniforme** entre si; a
divergência é medida e reportada na tela de calibração.

## 3.4 Documentação

Manuais por papel, decisões de projeto por issue, runbooks operacionais e
especificação OpenAPI — todos versionados no repositório.

## 3.5 Licenciamento e padrões aplicáveis

Projeto de código aberto. Adere ao **Problem Package Format** da ICPC/Kattis e
à **Contest API (CLICS)** da ICPC; suporta o formato de pacote **BOCA** na
importação.

---

# 4. Priorização de requisitos

| Prioridade | Requisitos | Critério |
|---|---|---|
| **Essencial** | RF-01, RF-04, RF-05, RF-06, RF-08, RF-19; RNF-G1, G2, G5; RNF-C1 | sem isto não há competição, ou há competição insegura |
| **Alta** | RF-02, RF-03, RF-07, RF-09, RF-10, RF-11, RF-13, RF-24; RNF-C2, C3, C4 | necessários para conduzir e corrigir o evento |
| **Média** | RF-12, RF-14, RF-15, RF-16, RF-17, RF-18, RF-21, RF-23 | ampliam alcance e integração |
| **Desejável** | RF-20, RF-22 | qualidade e apoio à decisão humana |

---

# 5. Rastreabilidade

| Área | Onde vive |
|---|---|
| Configuração do evento | modelo `Contest`, ciclo de vida, `/backend/configurations`, endpoints de contest |
| Pessoas e papéis | `users` + papéis/permissões + vínculo com sede e organização |
| Problemas | `problems`, `test_cases`, banco de problemas, importadores |
| Equipes | usuários do tipo equipe, vínculo com sede |
| Relógio | início, duração, fim, ajustes de tempo e `/api/contest/current` |
| Placar | `Leaderboard`/`Score`, congelamento e revelação |
| Autenticação | sessão + Sanctum + limite de tentativas + política de IP |
| Rotas | `routes/web.php`, `routes/api.php`, `routes/clics.php`, APIs de frontend |
| Modelo de dados | migrations e modelos Eloquent |
| Decisões | `docs/specs/<issue>-<tema>.md` |

---

# 6. Modelo de dados

Relações que estruturam o fluxo principal:

```
CONTEST 1─N SITE          CONTEST 1─N SCORE        CONTEST 1─N CLARIFICATION
CONTEST 1─N PROBLEM       CONTEST 1─N REJUDGING    CONTEST 1─N CONTEST_EVENT
CONTEST 1─N TIME_ADJUSTMENT                        CONTEST 1─N USER
ORGANIZATION N─M USER     PROBLEM 1─N TEST_CASE    PROBLEM_BANK ──origem──> PROBLEM
USER 1─N RUN              PROBLEM 1─N RUN          JUDGEHOST 1─N RUN
```

**Grupos de tabelas:**

- **Competição:** `contests`, `contest_events`, `contest_time_adjustments`,
  `scores`
- **Sedes e operação:** `sites`, `site_judging_routes`, `tasks`, `sos_calls`
- **Problemas:** `problems`, `test_cases`, `problem_bank`,
  `problem_language_limits`
- **Julgamento:** `runs`, `judgehosts`, `judgehost_capabilities`,
  `rejudgings`, `rejudging_runs`
- **Comunicação:** `clarifications`
- **Identidade:** `users`, papéis e permissões, `personal_access_tokens`,
  `account_activations`
- **Governança:** `organizations`, `organization_memberships`, transferências
  de propriedade
- **Integridade e operação:** `idempotency_keys`, `backups`, logs e eventos

Diagramas em [`Diagrama de Classe.png`](Diagrama%20de%20Classe.png) e
[`Modelo de Caso de Uso.png`](Modelo%20de%20Caso%20de%20Uso.png).

---

# 7. Rotas e superfícies

## 7.1 Web

| Superfície | Rotas |
|---|---|
| Pública | `/`, `/scoreboard`, `/exercises`, `/practice`, `/ajuda` |
| Autenticação | `/login`, `/logout`, `/register`, `/password/*`, `/activate/{token}` |
| Equipe | `/home`, `/exercise/{problema}`, `/submit/{problema}`, `/submissions`, `/submission/{run}`, `/clarifications`, `/print`, `/sos`, `/profile` |
| Juiz | `/judge/runs`, `/judge/health`, `/judge/history`, `/judge/problems/{p}/pause`, `/judge/problems/{p}/resume`, `/judge/rejudgings/*` |
| Staff | `/staff/tasks`, `/staff/sos`, `/staff/report` |
| Sede | `/site/dashboard`, `/site/teams`, `/site/tasks`, `/site/clarifications` |
| Administração | `/backend/*` — contests, usuários, problemas, banco, organizações, sedes, linguagens, importadores, máquinas, similaridade, webcast, logs, operações |

`/print` e `/sos` são exclusivas do papel equipe e têm limite de requisições.

## 7.2 API

Emissão e revogação de tokens; contests e estado; problemas e download seguro
de enunciado; runs; placar e pontuação própria; clarificações; e a superfície
de juiz/admin (casos de teste ocultos, exportação de pacote, finalização,
premiação, ajustes de tempo, rejulgamento, verificação, ciclo de vida).

`/api/health` para monitoramento; `/api/openapi.yaml` publica a especificação
mantida em [`docs/api/openapi.yaml`](../api/openapi.yaml).

## 7.3 Contest API (CLICS)

`/api/clics/*` — contests, estado, problemas, equipes, organizações, grupos,
linguagens, tipos de julgamento e o **event feed**
(`/api/clics/contests/{id}/event-feed`), em NDJSON com streaming.

---

# 8. Deploy e operação

```
Navegador/API → servidor web / proxy → Laravel (PHP-FPM) → banco + storage
                                     ↘ judgehost → sandbox → compiladores
```

**Preparação:**

1. instalar dependências PHP e Node;
2. configurar `.env` e a chave da aplicação;
3. executar migrations;
4. construir os assets do frontend;
5. escolher o banco adequado à carga esperada;
6. configurar sandbox e toolchains nos judgehosts;
7. iniciar um ou mais workers;
8. testar `/up` e `/api/health`;
9. executar `judgehost:selftest` em **cada** máquina de julgamento;
10. fazer prova de carga de submissões antes do evento real.

---

# 9. Testes e critérios de aceite

**Backend.** Autenticação e autorização de rotas; ciclo de vida do contest;
congelamento, encerramento e finalização; reivindicação concorrente de runs e
idempotência; isolamento de dados entre equipes, sedes e contests;
rejulgamento e recálculo do placar.

**Frontend.** Suíte por Node e suíte de navegador sob a CSP real. Fluxos
críticos: login, envio de solução, placar, clarificação, fila do juiz,
rejulgamento e configuração do contest.

**Segurança operacional — antes de cada evento:**

- validar o sandbox com código malicioso de teste;
- confirmar que `.env` e arquivos do host **não** são visíveis de dentro do
  sandbox;
- simular TLE, MLE, RE, CE e WA;
- validar congelamento, encerramento e revelação;
- simular perda de conectividade de uma sede;
- validar a restauração de um backup;
- confirmar a sincronização de horário;
- homologar o streaming do event feed contra o deploy real
  ([runbook](../runbooks/252-event-feed-streaming.md)).

**Regra de guarda.** Toda proteção deste documento que vira teste é verificada
por **mutação**: remove-se a proteção, confirma-se que o teste esperado falha,
e restaura-se. Um teste verde contra um mecanismo que não funciona é o modo de
falha recorrente deste projeto, e é contra ele que a prática existe.
