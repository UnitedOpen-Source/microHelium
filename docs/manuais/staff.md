# Manual do Staff e da Coordenação de Sede

Para quem faz a prova **acontecer no mundo físico**: atender equipe, resolver
chamado, imprimir, cuidar da sala.

Cobre dois papéis:

- **`staff`** — apoio do evento: tarefas, impressão, S.O.S., relatório.
- **`site`** — coordenação de **uma sede**: equipes, tarefas, clarificações e
  S.O.S. **daquela sede**.

Papéis vizinhos: [organizador](organizador.md) (configuração e ciclo da
prova), [juiz](juiz.md) (julgamento), [participante](participante.md) (o que a
equipe vê).

---

## 1. A diferença entre as duas filas

Esta é a coisa mais importante do manual.

| | **Clarificação** | **S.O.S.** |
|---|---|---|
| É sobre | o **enunciado** de um problema | o **mundo físico** |
| Quem responde | a **banca** (juiz) | **staff / sede** |
| Exemplos | "o N pode ser zero?" | computador travou, energia caiu, falta papel |
| Onde | `/clarifications` | `/sos` → `/staff/sos` |

**Não responda clarificação técnica.** Uma resposta sua sobre o enunciado,
dada com boa intenção, pode dar vantagem a uma equipe e comprometer a prova
inteira. Encaminhe para a banca.

**Não deixe S.O.S. virar clarificação.** Se a equipe pediu socorro porque o
teclado quebrou, resolva — não mande abrir clarificação.

---

## 2. S.O.S.

`/staff/sos`.

O ciclo é de dois passos, e os dois importam:

```
POST /staff/sos/{sosCall}/acknowledge   → "eu vi, estou indo"
POST /staff/sos/{sosCall}/resolve       → "resolvido"
```

**Reconheça rápido, mesmo que não vá resolver agora.** A equipe do outro lado
não sabe se o chamado chegou. Um chamado reconhecido é uma equipe que volta a
competir em vez de ficar chamando de novo.

**Só marque resolvido quando estiver resolvido.** O registro serve para o
relatório do evento e para saber onde a operação falhou.

**Uma equipe não consegue abrir um segundo chamado enquanto o primeiro não for
resolvido.** Isso é proposital: evita que a mesma mesa encha a fila e esconda
as outras. Consequência prática — enquanto você não marcar como resolvido, a
equipe **não tem como pedir outra coisa**. Mais uma razão para não deixar
chamado pendurado.

Há também limite de chamadas por minuto. Se uma equipe está esbarrando nos
limites, vá até a mesa: é sinal de que algo está mesmo errado.

---

## 3. Tarefas

`/staff/tasks` (staff) e `/site/tasks` (sede).

```
GET  /staff/tasks/{task}/file       → baixa o arquivo da tarefa
POST /staff/tasks/{task}/complete   → conclui
```

A fila de tarefas cobre a logística: **balões**, impressão, entrega,
conferência.

### Balões

Quando uma equipe resolve um problema pela primeira vez, o sistema cria
sozinho uma **tarefa de entrega de balão** na fila da **sede daquela equipe**,
já com a **cor do problema**.

Três coisas que valem saber:

- **É o primeiro AC que vale.** Um rejulgamento que reconfirma o mesmo AC
  **não** gera um segundo balão.
- **Treino não gera balão.** Ambiente de prática não tem cerimônia.
- **A cor vem do problema**, não da sua escolha. Se a cor na tela não bater
  com o balão que você tem, fale com o organizador — quem cadastra a cor é
  ele.

Entregar balão é mais do que logística: é o que a sala inteira usa para saber
como a prova está indo, e é metade da atmosfera do evento.

### Impressão

A equipe pede em `/print` e a tarefa aparece na sua fila com o arquivo
anexado. Fluxo prático:

1. Baixe o arquivo.
2. Imprima.
3. **Entregue na mesa da equipe.**
4. Só então marque como concluída.

Marcar como concluída antes de entregar quebra a única coisa que a fila
garante: que ninguém ficou sem o que pediu.

Há limite de pedidos por hora por equipe.

---

## 4. Coordenação de sede

`/site/dashboard` é a visão da sua sede.

| Tela | Para quê |
|---|---|
| `/site/teams` | equipes da sede; `POST` cadastra |
| `/site/tasks` | tarefas da sede |
| `/site/clarifications` | clarificações da sede, com resposta |
| `/staff/sos` | chamados |
| `/staff/report` | relatório operacional |

O papel `site` vê **a sua sede**, não a instalação inteira. Isso é
proposital: coordenar uma sede não exige — nem deveria dar — acesso às outras.

### Se a sede perder conexão com o servidor central

O microHelium é **uma instalação central com várias sedes**, não uma
instalação por sede. Sem link, a sede perde as operações online até a conexão
voltar. Não há modo offline.

O que fazer:

1. **Avise o organizador imediatamente.** Ele pode registrar um **ajuste de
   tempo** para compensar a interrupção.
2. **Anote tudo à mão**: horário do corte, quais equipes foram afetadas, o
   que cada uma estava fazendo.
3. **Não deixe ninguém submeter por outro caminho.**
4. Quando voltar, confirme com cada equipe que os envios dela aparecem em
   `/submissions`.

> **O ajuste pode ser só da sua sede.** O sistema aceita os dois escopos:
> intervalo removido da prova inteira, ou extensão apenas da sede afetada —
> que é o caso previsto no regulamento da Maratona para queda de energia. Por
> isso a sua anotação de **horário de início e fim da parada** é o insumo da
> decisão: sem ela, não há em que basear o ajuste.
>
> O **congelamento**, esse sim, é do contest inteiro. Não existe congelamento
> só de uma sede.

---

## 5. Login de equipe: os quatro motivos

Quando uma equipe não entra, é quase sempre um destes, nesta ordem:

1. **Senha errada.** Confira com calma; o campo distingue maiúscula.
2. **Excesso de tentativas.** Erros seguidos bloqueiam por alguns minutos.
   Espere em vez de tentar mais.
3. **Trava de IP da sede.** Se a sede tem faixa de rede configurada, a conta
   só entra de dentro daquela rede. Máquina no Wi-Fi errado, ou cabo na
   tomada errada, dá esse erro — **e a mensagem fala em e-mail inválido**, o
   que confunde. Este é o motivo mais fácil de diagnosticar errado.
4. **Conta não ativada.** Contas criadas com link de ativação precisam que a
   senha seja definida antes do primeiro login.

Se nenhum resolve, chame o organizador: pode ser papel errado ou conta em
outro contest.

---

## 6. Antes da prova

- [ ] Consigo entrar e abrir `/staff/sos` e `/staff/tasks`
      (ou `/site/dashboard`)
- [ ] Testei o fluxo de impressão inteiro: pedido → baixar → imprimir →
      concluir
- [ ] Testei reconhecer e resolver um S.O.S. de teste
- [ ] Sei os quatro motivos de uma equipe não conseguir entrar
- [ ] Sei **quem é a banca** e como falo com ela
- [ ] Sei **quem é o organizador** e como falo com ele
- [ ] Sei o que fazer se a sede cair
- [ ] Tenho papel e caneta para registrar incidente quando o sistema não
      estiver acessível

---

## 7. Durante a prova

**A cada 10 minutos, olhe:**
- fila de S.O.S. — tem chamado não reconhecido?
- fila de tarefas — tem impressão parada?

**Encaminhe sem resolver por conta própria:**
- dúvida sobre enunciado → banca
- suspeita de defeito num problema → banca, **com urgência** (ela pode pausar
  o problema)
- placar estranho, relógio errado, prova que não encerra → organizador

**Resolva você:**
- qualquer coisa física
- login (os quatro motivos acima)
- equipe perdida na interface

---

## 8. Depois da prova

- [ ] Fila de S.O.S. zerada, tudo marcado como resolvido
- [ ] Fila de tarefas zerada
- [ ] `/staff/report` conferido
- [ ] Incidentes anotados à mão passados ao organizador
- [ ] Equipamento da sala conferido

---

## 9. Postura

Três coisas que separam uma operação boa de uma ruim:

**Reconheça antes de resolver.** A equipe precisa saber que o pedido chegou.
Trinta segundos de "estou indo" valem mais que dez minutos de silêncio
seguidos de solução.

**Não improvise sobre o enunciado.** É a regra que mais custa quando é
quebrada — e é sempre quebrada com boa intenção.

**Anote.** Durante a prova ninguém tem tempo de investigar; depois, ninguém
lembra. A anotação de horário é o que permite ao organizador decidir sobre
ajuste de tempo com base em fato, e não em memória.
