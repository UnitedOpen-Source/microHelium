# Manual do Participante

Para quem vai **competir**. Se você é da organização, veja o
[manual do organizador](organizador.md); se vai julgar, o
[manual do juiz](juiz.md); se vai operar a sede, o [manual do staff](staff.md).

---

## 1. Antes da prova

### Sua conta

A conta é criada pela organização e entregue a você — usuário e senha. Não é
conta pessoal: é **a conta da equipe**. Quem submete pela equipe submete por
todos.

Entre em `/login`. Três coisas podem impedir o acesso:

- **Senha errada.** Há limite de tentativas; errar muitas vezes seguidas
  bloqueia por alguns minutos. Chame o staff em vez de insistir.
- **Você está fora da rede da sede.** Se a organização configurou trava de
  IP, a conta só entra da rede daquela sede. A mensagem fala em e-mail
  inválido, mas o motivo pode ser este — chame o staff.
- **A conta ainda não foi ativada.** Contas criadas com link de ativação
  precisam que você defina a senha em `/activate/{token}` antes do primeiro
  login.

### Confira o relógio

Toda página mostra o **relógio da prova**, e ele vem do **servidor**, não do
seu computador. Se o relógio do seu computador estiver errado, o da tela
continua certo. Use o da tela.

O relógio diz em que estado a prova está:

| Estado | O que significa |
|---|---|
| **Não começou** | ainda não dá para submeter |
| **Em andamento** | prova rodando normalmente |
| **Congelado** | a prova continua; o **placar** parou de mostrar resultados novos |
| **Encerrado** | acabou o tempo; não aceita mais envios |
| **Revelado / Finalizado** | o placar final foi publicado |

Se o relógio mostrar **"Estado indisponível"**, a página não conseguiu falar
com o servidor. Não presuma nada sobre o tempo restante — recarregue, e se
persistir, chame o staff.

### Treino

Antes do evento, use `/practice`. É um ambiente separado: os envios de treino
**nunca** entram no placar da prova e não contam penalidade. É o lugar certo
para testar se você sabe submeter, se a linguagem que você quer usar está
disponível e se seu código compila no ambiente do juiz.

`/practice/history` mostra seus envios de treino.

---

## 2. Durante a prova

### Ler os problemas

- `/exercises` — lista dos problemas da prova.
- `/exercise/{problema}` — a página do problema.
- `/exercise/{problema}/enunciado` — o enunciado em PDF, quando o problema
  tem um.

Cada problema tem uma **letra** (A, B, C…) e um nome. O placar usa a letra.

Os limites de **tempo** e de **memória** do problema aparecem na página dele.
Podem ser diferentes por linguagem — o que vale é o que está escrito ali.

### Enviar uma solução

`/submit/{problema}`, ou o botão de envio na página do problema.

1. Escolha o problema.
2. Escolha a linguagem.
3. Anexe o arquivo com o código-fonte.
4. Confirme.

Três cuidados que evitam quase todo problema de envio:

- **Envie o código-fonte, não o executável nem um `.zip`.**
- **A linguagem escolhida tem que bater com o arquivo.** Um `.py` enviado
  como C++ dá erro de compilação.
- **Confira a letra do problema antes de confirmar.** Enviar a solução certa
  no problema errado gasta uma tentativa e ainda pode custar penalidade.

Se você sair da página com um envio começado e não enviado, o sistema
**pergunta antes de descartar**. Se ele perguntou, é porque havia rascunho.

### Acompanhar o resultado

- `/submissions` — todos os seus envios, com estado.
- `/submission/{envio}` — o detalhe de um envio.

O estado passa por **pendente → em julgamento → veredito**. Os veredictos:

| Sigla | Nome | O que aconteceu |
|---|---|---|
| **AC** | Accepted | aceito — o problema está resolvido |
| **WA** | Wrong Answer | compilou, rodou, e a resposta está errada |
| **TLE** | Time Limit Exceeded | passou do tempo limite do problema |
| **MLE** | Memory Limit Exceeded | passou do limite de memória |
| **RE** | Runtime Error | quebrou durante a execução |
| **CE** | Compilation Error | não compilou |
| **PE** | Presentation Error | resposta certa, formatação errada |
| **CS** | Contact Staff | procure o staff — algo fora do comum |

**AC é o único que resolve o problema.** Todos os outros são tentativa
gasta.

Algumas provas exigem que um juiz **confirme** o veredito antes de ele
aparecer para você. Nesse caso o envio fica "em julgamento" por mais tempo do
que a execução levaria. Isso é normal.

### Penalidade

O placar da maratona ordena por **problemas resolvidos** primeiro. Entre
equipes com o mesmo número de problemas, ganha quem tem **menos penalidade**.

A penalidade de um problema resolvido é o **minuto em que veio o AC**, mais
um valor fixo **por cada envio errado anterior** naquele mesmo problema.

Três consequências que valem saber:

- Envios errados em problemas que você **não** resolveu **não contam**.
- Envios feitos **depois** do AC naquele problema **não contam**.
- Só contam os erros **anteriores** ao acerto.

Consequência prática: **chutar custa caro, e só custa se você acabar
resolvendo.** O valor fixo por erro é configurado pela organização e aparece
nas regras do evento.

### Placar

`/scoreboard`. Pode ser aberto por qualquer um, inclusive pelo público.

Perto do fim, a organização **congela** o placar. A partir do congelamento
você continua enviando e continua recebendo seus próprios veredictos
normalmente — o que para é a **visão pública**: as posições deixam de refletir
os envios novos. É assim que a virada final fica em suspense até a cerimônia.

O placar congelado **diz na tela que está congelado**. Se não disser, ele não
está.

---

## 3. Quando algo dá errado

### Dúvida sobre um problema: clarificação

`/clarifications`. É o canal **oficial** para perguntar sobre um enunciado.

- Escreva a pergunta dizendo **a qual problema** ela se refere.
- A banca responde para você, ou responde **para todas as equipes** quando a
  dúvida afeta todo mundo (*broadcast*).
- Leia os broadcasts: eles costumam responder o que você ia perguntar.

A resposta mais comum é **"leia o enunciado"**. Não é má vontade: a banca não
pode dar informação que dê vantagem a uma equipe.

**Clarificação não serve para** pedir ajuda com seu código, perguntar por que
deu WA, ou reclamar de veredito.

### Problema físico: S.O.S.

`/sos`. É a fila **operacional**, separada da fila de julgamento. Use para o
que é do mundo real: computador travou, teclado quebrou, energia caiu, falta
papel, precisa de ajuda na sede.

O staff **reconhece** a chamada (viu, está indo) e depois **resolve**.

**Enquanto o seu chamado não for resolvido, você não consegue abrir outro.**
Por isso descreva bem o que está acontecendo já no primeiro: uma chamada
completa vale mais que cinco repetidas — e, aqui, é literalmente a única que
você tem no momento.

### Imprimir

`/print`, quando a organização habilita. Você envia o texto ou arquivo, e a
impressão sai na sede. Há limite de pedidos por hora.

### Trocar sua senha ou dados

`/profile`.

---

## 4. Regras que valem a pena saber de antemão

- **Envio é definitivo.** Não existe apagar envio. Um erro de julgamento é
  corrigido pela banca com **rejulgamento**, que fica registrado — nunca
  editando o seu envio.
- **Você não vê o código de outras equipes**, nem os casos de teste, nem os
  pacotes dos problemas. Isso não é configurável na sua conta.
- **A banca pode pausar um problema** se descobrir um defeito nele. Enquanto
  pausado, envios para aquele problema ficam parados na fila em vez de
  receber veredito errado. Quando o problema é corrigido, os envios são
  julgados — e os que já tinham veredito errado costumam ser rejulgados.
- **Semelhança entre códigos pode ser verificada.** A organização pode
  habilitar detecção de plágio.

---

## 5. Roteiro rápido

**Antes:**
- [ ] Consigo entrar em `/login`?
- [ ] O relógio na tela aparece e mostra o estado da prova?
- [ ] Fiz um envio de teste em `/practice` na linguagem que vou usar?
- [ ] Sei onde ficam `/exercises`, `/submissions`, `/scoreboard`,
      `/clarifications` e `/sos`?

**Durante, a cada envio:**
- [ ] É o problema certo?
- [ ] É a linguagem certa para este arquivo?
- [ ] É o código-fonte?

**Quando travar:**
- Dúvida de enunciado → `/clarifications`
- Problema físico → `/sos`
- Não consegue entrar, ou o relógio sumiu → chame o staff da sala

---

## 6. Perguntas frequentes

**Enviei e o estado não muda. Travou?**
Não necessariamente. A fila pode estar cheia, ou a prova pode exigir
confirmação manual do juiz. Espere antes de reenviar — reenviar a mesma
solução gasta tentativa e piora a fila.

**Deu TLE mas na minha máquina roda rápido.**
O limite é medido na máquina do juiz, que não é a sua. TLE quase sempre é
complexidade do algoritmo, não velocidade da máquina.

**Deu WA e eu testei todos os exemplos.**
Os exemplos do enunciado são os casos **públicos**. O julgamento usa casos
**secretos**, que cobrem os extremos: valores mínimos e máximos, entrada
vazia, empate, repetição.

**O placar parou de mudar.**
Provavelmente congelou. Confira o aviso de congelamento na tela.

**Posso usar internet / consultar código pronto?**
Depende do regulamento do evento, não do sistema. Pergunte à organização.

**Errei e enviei no problema errado. Dá para cancelar?**
Não. O envio fica registrado. Se ele não for AC e você não resolver aquele
problema, não custa penalidade.
