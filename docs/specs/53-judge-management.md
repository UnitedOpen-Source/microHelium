# Gestão de máquinas de julgamento — issue #53, fase 4

Contrato de backend para a interface de gestão do parque de julgamento.
Publicado **antes** de qualquer ação na UI, que é o que a spec de
[julgamento distribuído](53-distributed-judging.md) exige: *"publicar API e
capacidade `can_manage_judges` antes de adicionar ações à UI"*.

## O problema que isto resolve

As fases 1 a 3 construíram o protocolo inteiro — registro, claim com lease,
heartbeat, fencing token, devolução com motivo, capacidades por linguagem —
e **nada disso é visível para quem organiza a prova**. Hoje:

- a única forma de acrescentar uma máquina é `php artisan judgehost:create`
  num shell do servidor;
- a única forma de saber se uma máquina está viva é ler a tabela `runs`.

Quem está organizando uma regional às 8h da manhã não tem shell naquela
caixa, e a pergunta que essa pessoa faz é sempre a mesma: *quantas máquinas
estão julgando agora, e alguma morreu?*

## Superfície

Sessão web + CSRF, prefixo `/api/frontend`, contrato comum do
[README](README.md) — não é a API de bearer token do `routes/api.php`. Não
confundir com `routes/judgehost.php`, que é o protocolo que as **máquinas**
falam: estas rotas são *sobre* elas, não faladas por elas.

`middleware(['auth', 'admin'])`. Juiz não entra: acrescentar ou desligar
máquina muda a capacidade do evento, e isso é decisão de quem dirige a
prova — um juiz julga a prova que lhe deram.

### `GET /api/frontend/judgehosts`

```json
{
  "data": {
    "items": [
      {
        "id": 3,
        "name": "sala-3",
        "enabled": true,
        "state": "judging",
        "cpu_count": 8,
        "memory_mb": 16384,
        "last_seen_at": "2026-09-14T13:02:11.000000Z",
        "seconds_since_seen": 4,
        "languages": ["c", "cpp", "java", "py"],
        "declares_languages": true,
        "holding": [
          {
            "run_id": 812,
            "run_number": 41,
            "problem": "C",
            "claimed_at": "2026-09-14T13:01:40.000000Z",
            "seconds_held": 35
          }
        ],
        "created_at": "2026-09-10T18:00:00.000000Z"
      }
    ],
    "meta": {
      "total": 6,
      "enabled": 5,
      "judging": 3,
      "stale": 1,
      "lease_seconds": 600,
      "stale_after_seconds": 90
    },
    "capabilities": { "can_manage_judges": true }
  }
}
```

### `POST /api/frontend/judgehosts`

`{ "name": "sala-3" }` → **201**. Exige `Idempotency-Key`.

```json
{ "data": { "judgehost": { ... }, "token": "48-caracteres" } }
```

O `token` aparece **uma única vez**. O banco guarda só o sha256 — mesmo
contrato do `judgehost:create` e do #112. Numa repetição com a mesma
`Idempotency-Key` o campo volta `null`, de propósito: um token que pode ser
buscado duas vezes é um token que pode ser buscado por quem não estava lá
na primeira.

### `PATCH /api/frontend/judgehosts/{id}`

`{ "enabled": false }` → **200**. Exige `Idempotency-Key`.

```json
{ "data": { "judgehost": { ... }, "released_runs": 1 } }
```

Desligar é a chave geral: `Judgehost::authenticate()` recusa máquina
desligada, então da requisição seguinte em diante ela não busca trabalho,
não reporta veredito e não bate heartbeat.

**E devolve o que ela estava segurando.** Sem isso o run fica em `judging`
com dono morto até o lease expirar — dez minutos por padrão, que é muito
tempo para uma submissão que ninguém está julgando. Devolver na hora é
seguro justamente porque a máquina não autentica mais: o fencing token do
#123 recusa o relato dela mesmo que o processo lá ainda termine.

`released_runs` é o número que a pessoa que apertou o botão precisa ver.

Religar é uma pausa desfeita, não uma credencial nova: o mesmo token volta
a funcionar. Quem desligou a máquina errada não precisa reinstalar nada.

## Os cinco estados

Avaliados **nesta ordem**, porque não são mutuamente exclusivos e a ordem é
a resposta a "o que o operador deve olhar primeiro":

| estado | significa | o que a UI deve dizer |
| --- | --- | --- |
| `disabled` | alguém desligou | não é falha de rede; foi decisão |
| `never_seen` | credencial emitida, agente nunca registrou | a máquina ainda não foi instalada ou não alcança o servidor |
| `judging` | segura pelo menos um run agora | ocupada; `holding` diz qual e há quanto tempo |
| `stale` | habilitada e calada além do limite | **é este o alarme**: registrou e parou de responder |
| `idle` | viva e sem trabalho | normal |

Uma máquina desligada **e** calada há muito tempo é `disabled`, não `stale`
— dizer `stale` mandaria o operador procurar um problema de rede que não
existe.

`stale_after_seconds` sai do backoff do próprio agente (3 × o
`poll_max_seconds`, 90s por padrão) e **não** do lease. Um agente ocioso
pede trabalho no máximo a cada 30s; usar o lease aqui chamaria de saudável,
pelo tempo inteiro de uma prova curta, uma máquina morta.

## Duas coisas que a interface não pode fazer

1. **`languages: []` não é "não julga nada".** É "não declarou nada", e a
   fila trata isso como "julga qualquer coisa" — é o comportamento de um
   agente anterior ao #117, e recusar trabalho a ele tiraria um juiz do ar
   numa atualização. Use `declares_languages` para distinguir. Renderizar
   lista vazia como incapacidade faria o operador desligar uma máquina que
   está funcionando.
2. **Não mostrar o token de novo.** Ele não existe mais em lugar nenhum
   depois da resposta de criação. A tela tem que deixar claro, na hora, que
   aquela é a única vez.

## Fora deste contrato

- **Apagar máquina.** `enabled: false` é a revogação, e é o que o README já
  documenta para token perdido. Apagar a linha levaria junto o histórico de
  qual máquina julgou o quê.
- **Mandar a máquina fazer algo** (rejulgar, reiniciar, baixar pacote). O
  modelo é pull e essa é a premissa do #53: nada no sistema conecta na
  máquina de julgamento, que é o que permite ela viver no rack de outra
  instituição sem porta de entrada.
- **Métricas de throughput.** A medição está em
  [126-judging-throughput.md](126-judging-throughput.md) e contradiz a
  premissa original: uma máquina escala até o número de CPUs dela, e o
  distribuído se justifica quando isso acaba.
