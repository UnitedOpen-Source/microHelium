# Manual do Juiz

Para quem tem papel **`judge`** e responde pelo **julgamento técnico**: a fila
de runs, a confirmação de vereditos, a pausa de um problema com defeito e o
rejulgamento.

Papéis vizinhos: [organizador](organizador.md) (configuração e ciclo da
prova), [staff](staff.md) (operação e sede), [participante](participante.md)
(o que a equipe vê).

---

## 1. O que o juiz decide, e o que não decide

**Decide:** se um veredito vai para a equipe, se um problema deve parar de ser
julgado, e o que precisa ser rejulgado.

**Não decide:** quando a prova começa, congela, encerra ou é revelada — isso é
do organizador. Nem quem pode entrar, nem quais são os problemas.

**Nunca edita um envio.** Envio é registro do evento, não rascunho. Veredito
errado se conserta com **rejulgamento**, que fica registrado e é auditável.
Essa é a garantia de que o placar final pode ser explicado depois.

---

## 2. A fila de runs

`/judge/runs`.

Cada run passa por: **pendente → reivindicada por um judgehost → julgada →
(se a prova exigir) verificada → visível para a equipe**.

Os vereditos possíveis:

| Sigla | Nome |
|---|---|
| AC | Accepted |
| WA | Wrong Answer |
| TLE | Time Limit Exceeded |
| MLE | Memory Limit Exceeded |
| RE | Runtime Error |
| CE | Compilation Error |
| PE | Presentation Error |
| CS | Contact Staff |

### Verificação manual

Quando a prova exige confirmação humana, o veredito do juiz automático fica
retido até alguém confirmar:

- `POST /judge/runs/{run}/verify` — libera o veredito para a equipe.
- `POST /judge/runs/{run}/unverify` — volta atrás, se você liberou errado.
- `POST /judge/runs/{run}` — ação sobre a run.

### Sobrescrever um veredito à mão

Diferente de verificar. **Verificar é aprovar o que a máquina já disse;
sobrescrever é discordar dela.**

Por isso só o segundo **pede sua senha, toda vez**, e gera um **registro
próprio** — quem mudou o quê, de que para que, quando e por quê. Não é
burocracia: é o que o padrão da ICPC exige, e é o que permite explicar o
placar meses depois.

A senha é pedida a cada ação, e não uma vez por sessão, de propósito: o risco
não é invasor remoto, é a estação da banca aberta numa sala com gente
passando.

### Ritmo

Verificação existe para pegar o caso estranho, não para revisar tudo. Se a
fila de verificação virar gargalo, a prova atrasa para todo mundo por igual —
combine com o organizador, antes da prova, o que de fato precisa de
confirmação.

---

## 3. Saúde do julgamento

`/judge/health`.

O que olhar, em ordem de urgência:

1. **A fila está crescendo?** Se entra mais run do que sai, algo parou.
2. **Quantos judgehosts estão vivos?** Máquina que parou de dar sinal não
   está julgando.
3. **Há runs presas?** Existe um *watchdog* que recupera run abandonada por
   um judgehost que caiu — mas ele **recupera em silêncio**. A fila crescendo
   é o seu aviso, não uma mensagem de erro.

`/backend/judge-machines` (admin) mostra as máquinas, o que cada uma sabe
rodar, e a comparação de velocidade entre elas.

> **Se uma máquina estiver muito mais lenta que as outras**, a mesma solução
> pode receber TLE nela e AC na outra — e a equipe não tem como saber qual
> pegou o envio dela. Hoje o sistema **mede e avisa**, não compensa. Uma
> máquina destoante deve sair do parque, não ser tolerada.

### Histórico

`/judge/history` — o que foi julgado, por quem e quando.

---

## 4. Problema com defeito: pausar

Descobriu que um problema tem caso de teste errado, enunciado ambíguo ou
gabarito furado?

```
POST /judge/problems/{problem}/pause
POST /judge/problems/{problem}/resume
```

**Pause antes de consertar.** Enquanto pausado, os envios daquele problema
**ficam esperando na fila** em vez de receber veredito errado.

Sem a pausa, o que acontece é o pior cenário: as equipes recebem WA da banca,
pagam penalidade por um erro que não é delas, e você depois precisa desfazer
tudo — o que nem sempre é possível sem discussão.

**Sequência recomendada:**

1. **Pause** o problema.
2. Avise a banca e o organizador.
3. Corrija (caso de teste, gabarito, enunciado).
4. Se o enunciado mudou, mande **clarificação em broadcast** para todas as
   equipes.
5. **Retome** o problema.
6. **Rejulgue** os envios que receberam veredito com o problema defeituoso.

---

## 5. Rejulgamento

`/judge/rejudgings`.

### Individual

Para um caso isolado — uma run que foi julgada num momento ruim.

### Em lote

Para quando o problema afetou muita gente. O fluxo é deliberadamente lento:

```
POST /judge/rejudgings/{contest}/preview   → prévia: o que vai mudar
POST /judge/rejudgings/{contest}           → cria o conjunto
GET  /judge/rejudgings/set/{rejudging}     → revisa
POST /judge/rejudgings/set/{rejudging}/apply   → aplica
POST /judge/rejudgings/set/{rejudging}/cancel  → cancela
```

**A prévia é obrigatória, e é o ponto do desenho.** Você vê o conjunto exato
de runs atingidas e o que muda antes de qualquer coisa acontecer. Enquanto
não aplicar, dá para cancelar.

Todo rejulgamento pede **motivo**. Escreva o motivo de verdade — ele é o que
explica o placar final quando alguém perguntar meses depois.

### Cuidados

- **Runs já aceitas ficam de fora por padrão.** O conjunto só as inclui se
  você marcar explicitamente *incluir aceitas* — porque rejulgar um AC pode
  tirar pontos de quem já resolveu.
- **O rejulgamento recalcula o placar.** Confira o placar depois de aplicar.
- **Rejulgue antes de revelar.** Aplicar rejulgamento depois da cerimônia
  significa mudar um pódio já anunciado.
- **Um rejulgamento em lote grande enche a fila.** Perto do fim da prova,
  pesa. Combine o momento com o organizador.

---

## 6. Clarificações

As clarificações são o canal oficial entre equipes e banca — e o que você
responde ali vale para a prova inteira.

- **Responda só o que está no enunciado.** A resposta padrão "leia o
  enunciado" existe porque qualquer informação extra é vantagem para quem
  perguntou.
- **Se a dúvida afeta todo mundo, responda em broadcast.** Uma ambiguidade
  real esclarecida só para uma equipe é vantagem competitiva concedida por
  acidente.
- **Se a dúvida revelou um defeito**, pause o problema antes de responder.

Clarificações têm categoria e respostas prontas, para a fila não virar um
balcão único.

---

## 7. Antes da prova

- [ ] Sei entrar em `/judge/runs`, `/judge/health` e `/judge/rejudgings`
- [ ] Sei se esta prova exige **verificação manual**, e o que precisa ser
      verificado
- [ ] Testei **pausar e retomar** um problema num contest de teste
- [ ] Fiz um **rejulgamento de prévia** num contest de teste e vi como é a
      tela
- [ ] Sei quem chamar quando a fila parar
- [ ] Conferi com o organizador que `judgehost:selftest` passou em todas as
      máquinas
- [ ] Sei a política de penalidade da prova

---

## 8. Situações comuns

**A fila parou.**
`/judge/health` → veja judgehosts vivos. Um judgehost caído não avisa
sozinho; a fila crescendo é o sintoma. Avise o organizador para subir outro.

**Uma run está "em julgamento" há muito tempo.**
Pode ser fila longa, pode ser judgehost que morreu no meio. O watchdog
devolve a run para a fila, em silêncio. Se não voltar, veja a saúde.

**Uma equipe reclama de veredito.**
A reclamação não chega por clarificação como pedido de revisão — mas quando
chega, o caminho é: olhe a run, confirme se o julgamento está certo. Se
estiver errado, **rejulgue com motivo**. Não edite.

**Descobri o defeito depois do congelamento.**
Pause e rejulgue mesmo assim. O placar está congelado para o público, mas os
dados por trás continuam vivos — e é melhor revelar um placar correto do que
um placar bonito.

**Duas soluções parecem iguais.**
`/backend/similarity` (admin) faz a detecção. Semelhança é **indício**, não
veredito: a decisão sobre plágio é da organização, não do sistema.
