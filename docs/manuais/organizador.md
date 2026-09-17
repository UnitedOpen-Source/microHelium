# Manual do Organizador

Para quem tem papel **`admin`** e responde pela competição: montar o evento,
cadastrar gente e problemas, conduzir o ciclo da prova e publicar o resultado.

Papéis vizinhos: [juiz](juiz.md) (julgamento), [staff](staff.md) (operação e
sede), [participante](participante.md) (o que a equipe vê).

---

## 1. Papéis do sistema

O papel fica em `users.user_type` e decide o que a pessoa alcança.

| Papel | Para quem | Alcança |
|---|---|---|
| `admin` | organização | tudo: `/backend/*`, e também `/judge/*`, `/staff/*`, `/site/*` |
| `judge` | banca técnica | `/judge/*` — fila, verificação, pausa de problema, rejulgamento |
| `staff` | apoio do evento | `/staff/*` — tarefas, impressão, S.O.S., relatório |
| `site` | coordenação de uma sede | `/site/*` — equipes, tarefas, clarificações e S.O.S. daquela sede |
| `team` | equipe competidora | superfície de competidor |
| `score` | painel/telão | leitura de placar |
| `system` | contas de serviço | tratado como `admin` na autorização |

**Princípio:** dê o menor papel que resolve. Um coordenador de sede não
precisa de `admin` para tocar a sede dele — `site` existe exatamente para
isso.

---

## 2. Montar a competição

### Caminho rápido: o assistente

`/backend/contest-wizard` conduz a criação de um contest do zero. Serve para
começar; tudo que ele cria continua editável depois.

### Ordem que funciona

**1. Contest.** `/backend/contest/{id}/edit` — nome, início, duração,
congelamento, penalidade por envio errado, tamanho máximo de arquivo, e se é
**público** ou **privado**.

> O congelamento é dado em **minutos antes do fim** — `freeze_time: 60` numa
> prova de 5 horas congela na quarta hora. **`freeze_time: 0` significa "não
> congela"**, e não "congela no fim".

> `is_public` nasce **falso**. Um contest privado não aparece para visitante
> anônimo — nem o placar, nem a lista de problemas, nem o relógio. Isso é
> proposital. Marque público só quando quiser que o placar seja aberto.

**2. Sedes.** `/backend/sites`. Toda instalação tem pelo menos uma. Na sede
você pode configurar a **faixa de IP**: contas ligadas àquela sede só entram
de dentro daquela rede.

**3. Linguagens.** `/backend/languages`. Só o que estiver cadastrado *e*
instalado no judgehost pode ser usado. Cadastrar uma linguagem que o
judgehost não tem produz erro de compilação em prova.

**4. Problemas.** Três caminhos:

- `/backend/import-package` — pacote no formato **ICPC/Kattis**
  (`problem.yaml`, `data/`, `submissions/`). É o formato que o Polygon
  exporta e que o resto do mundo usa.
- `/backend/import-boca` — pacote **BOCA**, por upload ou a partir do GitHub.
- `/backend/exercises` — cadastro manual.

> **Sobre o limite de tempo no pacote ICPC:** o importador exige que o pacote
> **diga** o limite — em `domjudge-problem.ini` (`timelimit`), num arquivo
> `.timelimit` na raiz, ou em `limits.time_limit` no `problem.yaml`. Se o
> pacote não disser, a importação é **recusada**, e isso é de propósito: o
> formato legacy deriva o limite das soluções de referência, derivar ainda
> não existe aqui, e escolher um número por conta própria seria escolher
> vereditos.

**5. Banco de problemas.** `/backend/problem-bank` guarda problemas para
reuso entre edições. `/backend/bank-governance` controla quem é dono do quê
e o que é compartilhado.

**6. Usuários.** `/backend/users`. Criação individual ou em lote, definição
de papel, vínculo com contest e sede. `/backend/users/{user}/reset-link` gera
link de ativação/redefinição — é como entregar senha sem mandar senha por
mensagem.

`/backend/managed-accounts` cuida de contas administradas por terceiros (ex.:
uma instituição que gerencia as contas dos alunos dela).

**7. Organizações.** `/backend/organizations` — as instituições que as
equipes representam. É o que aparece no placar e nos relatórios da ICPC.

**8. Judgehosts.** `/backend/judge-machines` lista as máquinas de julgamento,
o que cada uma sabe rodar e se estão vivas.

> Antes da prova, rode `php artisan judgehost:selftest` **em cada máquina que
> vai julgar**. Ele prova naquela máquina que o sandbox confina de verdade.
> Uma máquina que reprova no selftest não deve julgar.

**9. Ativar.** `/backend/contest/{id}/activate` marca o contest como o evento
ativo. É o que o relógio e o placar passam a mostrar.

---

## 3. Conduzir a prova

`/backend/contest/{contest}/operations` é o painel de operação. As ações
também existem em `/backend/configurations`.

### O ciclo

```
Rascunho → Agendado → Em andamento → Congelado → Encerrado → Finalizado → Publicado
```

**Acabar e congelar são coisas diferentes.** Encerrar a prova não publica o
resultado: o placar pode continuar congelado até a cerimônia. É isso que
permite revelar o pódio ao vivo.

### As operações

| Operação | Rota | O que faz |
|---|---|---|
| **Congelar agora** | `POST /backend/contest/freeze` | congela o placar imediatamente, antes do horário automático |
| **Encerrar agora** | `POST /backend/contest/end` | encerra imediatamente |
| **Revelar** | `POST /backend/contest/{contest}/unfreeze` | descongela o placar — é a cerimônia |
| **Finalizar** | `POST /backend/contest/{contest}/finalize` | fecha o resultado e deriva a premiação |

**"Encerrar agora" encerra agora** — não no fim do minuto corrente. Isso já
foi um defeito (a prova aceitava envios por até 59 segundos depois) e foi
corrigido. Se você clicou, acabou.

### Ajustes de tempo

`POST /backend/contest/{contest}/time-adjustments` — para quando algo
interrompe a prova: queda de energia numa sede, problema com defeito, atraso
na largada.

O ajuste **não reescreve os horários já gravados**. Ele registra um intervalo
removido, e o fim da prova passa a considerá-lo. Por isso o horário de
término vem do servidor: `início + duração` deixa de bastar quando há
ajuste.

`DELETE .../time-adjustments/{adjustment}` desfaz um ajuste — e a remoção é
reversível e auditável, porque alguém vai perguntar depois por que aquela sede
teve quarenta minutos a mais.

### Global ou por sede

O ajuste tem **escopo**, e os dois casos existem no mesmo mecanismo:

| Escopo | O que é | Quando usar |
|---|---|---|
| **Prova inteira** | o intervalo removido que a ICPC especifica | algo parou a competição toda |
| **Uma sede** | a extensão por sede, como o MOJ faz | queda de energia numa sede só |

O segundo é o caso real da Maratona: o regulamento manda somar uma fração do
tempo de parada **daquela sede**, e sem isso a sede faz a conta no papel e a
classificação não bate com o placar.

> **Limite conhecido:** o **congelamento**, esse sim, é do contest inteiro —
> não há congelamento por sede. Não confunda com o ajuste de tempo, que tem
> escopo.

### Premiação

A finalização deriva a premiação. **As medalhas vêm zeradas por padrão** —
quantas de cada tipo é decisão de quem organiza, e o sistema não arbitra isso
por você. Configure antes da cerimônia.

---

## 4. Acompanhar

| Tela | Para quê |
|---|---|
| `/backend/submissions` | todos os envios da prova |
| `/backend/clarifications` | fila de clarificações, com resposta e broadcast |
| `/judge/health` | saúde do julgamento: fila, máquinas, atrasos |
| `/backend/judge-machines` | máquinas, capacidades e comparação de velocidade |
| `/backend/similarity` | detecção de semelhança entre códigos |
| `/backend/logs` | registro de operações sensíveis |
| `/backend/webcast` | transmissão do placar |
| `/staff/report` | relatório operacional |

### Calibração de máquinas

A tela de máquinas compara a velocidade dos judgehosts usando os **envios
aceitos que a própria prova produz**. Se uma máquina estiver muito mais lenta
que as outras, a mesma solução pode receber TLE nela e AC na outra.

Hoje o sistema **mede e avisa** — não compensa. Se a divergência for grande,
**tire a máquina do parque** antes da prova. A decisão de derivar limite por
calibração está registrada em
[`docs/specs/251-limite-de-tempo-derivado.md`](../specs/251-limite-de-tempo-derivado.md).

---

## 5. Integrações

- **Contest API (ICPC/CLICS)** — `/api/clics/*`, incluindo o **event feed**
  (`/api/clics/contests/{id}/event-feed`), que é o que um *resolver* lê para
  a cerimônia.
- **API autenticada** — tokens Sanctum, emitidos e revogáveis pelo titular.
  `/api/health` para monitoramento, `docs/api/openapi.yaml` para a
  especificação.
- **Exportações** — placar e relatórios.

> **Antes de usar o resolver numa prova real**, rode a homologação descrita em
> [`docs/runbooks/252-event-feed-streaming.md`](../runbooks/252-event-feed-streaming.md).
> O streaming do feed depende de configuração de proxy que não dá para
> verificar com teste automatizado.

---

## 6. Backup

`php artisan backup:create` gera um ponto de restauração.

Faça um **antes** da prova e **depois** dela. Um backup que nunca foi
restaurado não é um backup — teste a restauração antes do evento, não durante.

---

## 7. Checklist do evento

### Semanas antes
- [ ] Contest criado, com início, duração, congelamento e penalidade
- [ ] Sedes cadastradas, com faixa de IP se for usar trava de rede
- [ ] Linguagens cadastradas **e** instaladas nos judgehosts
- [ ] Problemas importados; enunciados abrem; limites conferidos
- [ ] Organizações cadastradas
- [ ] `judgehost:selftest` passou em **cada** máquina de julgamento
- [ ] Máquinas comparadas na calibração; as destoantes fora do parque

### Dias antes
- [ ] Contas criadas e distribuídas; equipes conseguem entrar
- [ ] Ambiente de treino (`/practice`) aberto e testado
- [ ] Prova de carga de envios feita
- [ ] Backup criado **e restaurado** num ambiente de teste
- [ ] Event feed homologado, se for usar resolver
- [ ] Papéis conferidos: ninguém com `admin` sem precisar

### No dia
- [ ] Relógio do servidor conferido
- [ ] Contest ativado
- [ ] Juízes e staff conseguem acessar as telas deles
- [ ] Alguém de plantão na fila de clarificações e na de S.O.S.

### Durante
- [ ] Fila de julgamento sem acúmulo (`/judge/health`)
- [ ] Clarificações sendo respondidas
- [ ] Congelamento aconteceu no horário, e a tela **diz** que está congelado

### Depois
- [ ] Encerrar
- [ ] Conferir se há rejulgamento pendente antes de revelar
- [ ] Revelar (cerimônia)
- [ ] Finalizar e conferir a premiação
- [ ] Exportar placar e relatórios
- [ ] Backup final

---

## 8. O que pode dar errado, e o que fazer

**A fila de julgamento parou de andar.**
Veja `/judge/health`. Provavelmente um judgehost caiu. Há um *watchdog* que
recupera runs presas, mas ele recupera em silêncio — a fila crescendo é o
sinal. Suba outro judgehost; o sistema distribui sozinho.

**Um problema está com defeito.**
Peça ao juiz para **pausar** o problema (`/judge/problems/{problem}/pause`).
Enquanto pausado, os envios esperam em vez de receber veredito errado.
Corrija, retome, e rejulgue o que já tinha veredito.

**Uma sede caiu.**
Registre um **ajuste de tempo**. Lembre que o ajuste é do contest inteiro —
não há extensão só para uma sede.

**Precisa corrigir vereditos em lote.**
Rejulgamento em lote, com **prévia obrigatória**: o juiz vê o que vai mudar
antes de aplicar, e pode cancelar. Veja o [manual do juiz](juiz.md).

**O placar está estranho depois de um rejulgamento.**
O rejulgamento recalcula a pontuação. Se ainda parecer errado, `/backend/logs`
mostra o que foi feito e por quem.

**Uma equipe não consegue entrar.**
Nesta ordem: senha; trava de IP da sede; conta ativada; papel correto;
bloqueio por excesso de tentativas.
