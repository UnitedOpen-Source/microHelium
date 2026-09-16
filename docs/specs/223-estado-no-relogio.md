# 223 — O estado da prova, do servidor para o relógio

**Escopo deste documento: a metade de backend.** A interface fica com quem cuida do frontend, na worktree anunciada na issue. Isto é o contrato para que ela possa parar de adivinhar.

## O problema

O `ContestTimer` calculava *"acabou"* e *"congelado"* com o **relógio local**, e escondia o aviso de congelamento depois do fim — enquanto o servidor mantinha o placar congelado (#189).

Os dois discordavam na hora exata em que a discordância custa caro: **a cerimônia**.

E `start_time + duration` deixou de bastar para saber quando a prova acaba:

- desde o **#198**, o fim inclui os intervalos removidos da prova;
- desde o **#225**, ele pode ter sido encurtado por um encerramento antecipado.

Um cliente não tem como calcular isso. Tem que perguntar.

## O que `/api/contest/current` passou a dizer

| campo | por que |
| --- | --- |
| `is_ended` | acabar e congelar são coisas diferentes desde o #189 — a prova termina e o placar continua congelado até alguém revelar |
| `is_unfrozen`, `unfrozen_at` | `is_frozen: false` sozinho **não distingue** *"nunca congelou"* de *"foi revelado"* — e a cerimônia é exatamente a segunda |
| `is_finalized`, `finalized_at` | o estado do #202 não aparecia em lugar nenhum da superfície que a interface lê |
| `end_time` | o cliente não tem como calculá-lo (ver acima) |
| `server_time` | para a interface medir a própria defasagem em vez de confiar no relógio da máquina de quem olha |

E a seleção passou a usar o scope `competition()`: sem ele, o contest de **treino** (#43) podia ser escolhido como "o evento", e o relógio da prova mostraria o do treino. **Mesmo defeito que o #225 acabou de consertar nos atalhos legados.**

## Uma armadilha que fica documentada em vez de consertada

Sem competição ativa, a resposta é **`{}`**, não `null` — `response()->json(null)` serializa assim.

`{}` é **truthy**. Um `if (data)` renderiza um relógio para um contest que não existe. O componente atual escapa porque testa `data?.start_time`, que é o jeito certo.

Mantido como está de propósito: o consumidor de hoje lida certo com este formato, e trocar a forma do fio por elegância quebraria quem o lê. O que vale é **a armadilha estar escrita** — e fixada por teste, com uma asserção que falha se alguém "consertar" para `null` sem atualizar o aviso.

## O que este documento não entrega

A interface: relógio alinhado, estados e ações contextuais em Configurações, e o fluxo de prévia/finalização/premiação. Os endpoints que esse fluxo precisa já existem — `GET /contests/{id}/finalize/preflight`, `POST /contests/{id}/finalize`, `GET /contests/{id}/awards` (#202) — e são staff-only, o que atende o critério *"nenhuma equipe acessa dados de premiação restritos"*.
