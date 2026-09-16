# 219 — O event feed da Contest API

## Por que isto e a coisa que faltava

O #195 entregou a fase 1 da Contest API: os endpoints REST. E dizia, no próprio endpoint `api`, que o feed não existia — porque a documentação do ICPC Tools é direta:

> the only part of the Contest API that is strictly required is the event feed and any file references that the feed refers to

Sem o feed, **o resolver não funciona**. Com ele, funciona.

## A parte cara não é o endpoint

É o **log de mudanças**. Um feed com `since_token` exige um registro durável e totalmente ordenado: um cliente que caiu volta dizendo *"recebi até o token N"* e tem que receber exatamente o que veio depois, na mesma ordem. Um feed que reinicia do zero quando o processo cai é um feed que o resolver não consegue usar.

**O `id` é o token.** Autoincremento é monotônico e único. Um UUID ou um timestamp criaria a pergunta *"qual veio antes"* para dois eventos do mesmo milissegundo — que é exatamente a pergunta que o token existe para não precisar fazer.

## Só o que muda entra no log

Objetos estáticos — contest, problemas, equipes, organizações, grupos, linguagens, tipos de veredito — são emitidos como **fotografia** no início do feed, a partir do estado atual. É como os feeds de CLICS funcionam, e poupa um log para dados que não mudam no meio de uma prova.

Consequência que precisa ficar dita: **com `since_token`, a fotografia não é repetida.** Um cliente que volta já tem os objetos estáticos; repeti-los a cada reconexão faria um resolver redesenhar a tela inteira toda vez que a rede oscilasse.

E as linhas da fotografia **saem sem token**. Um token é uma posição no log, e a fotografia não está no log — dar a ela um token inventado faria um cliente pedir *"depois desse"* e pular eventos reais que ficaram abaixo dele.

## Outbox explícito, não observers

Observers pegam mudanças feitas por qualquer caminho, o que soa melhor — mas disparam também em seed, em migração e em toda factory de teste, e o feed passaria a conter eventos de linhas que nunca existiram numa prova.

Pior: **um observer não sabe se o julgamento caiu na janela de congelamento**, porque essa é uma pergunta sobre o *contest* e sobre o *tempo de prova do envio*, não sobre a linha que mudou.

O risco do outbox é alguém esquecer de chamar, e ele é mitigado como este repositório já resolveu o problema equivalente: **chamando nos pontos por onde tudo converge.** `AutoJudgeService::recordVerdict()` já é *"the one point every judging path converges on"* (#87) — o julgamento local, o que chega por HTTP de um judgehost (#53), o rejulgamento em lote (#192) e o veredito manual passam todos por ele.

O evento de submissão é gravado **fora da transação**, de propósito: o evento conta que o envio *existe*, e um gravado dentro de uma transação que depois desfaz contaria sobre um envio que nunca existiu.

## O congelamento num stream é mais difícil que no REST

No REST basta filtrar uma lista. Num stream, **um evento omitido some para sempre**: se o julgamento da janela nunca for emitido, o cliente nunca fica sabendo; se for emitido na hora, vaza.

A solução é a marca `after_freeze` no evento. O feed público segura os marcados até o descongelamento e aí os entrega **na ordem do token** — que é a ordem em que aconteceram.

Três detalhes que decidem se isso está certo:

- **O envio continua sendo anunciado.** A spec esconde o *julgamento*, não a submissão, e é o que faz o placar poder mostrar célula pendente (#211). Um feed que escondesse o envio deixaria o cliente sem saber nem que a equipe tentou.
- **A marca vem do tempo do ENVIO**, não de quando o julgamento aconteceu. Um envio feito antes do corte e julgado depois continua sendo informação anterior ao congelamento, e escondê-lo esconderia o que o placar congelado já mostra.
- **O token entregue ao público nunca é o de um evento escondido.** Senão o cliente pediria *"depois de N"* e nunca receberia o que estava em N.

## `end_of_updates`

Só depois de finalizar (#202) — e só aí *"não vem mais nada"* é verdade. Finalizar é uma checagem de integridade da prova inteira, não um botão.

## Detalhes de transporte que só falham em produção

- **`X-Accel-Buffering: no`**: sem isso o nginx entre o consumidor e nós guarda a resposta até o fim, e "streaming" vira "tudo de uma vez no final".
- **`flush()` por linha**: sem isso o PHP entrega tudo junto quando a resposta fecha — o que **passa em teste** (o teste lê o corpo inteiro) e falha em uso, porque o resolver espera a primeira linha.

Nenhum dos dois é detectável por um teste de integração comum. Estão aqui porque a falha deles é invisível até o dia do evento.

## Um teste que virou o seu inverso

O #195 tinha um teste exigindo **404** na rota do feed — o registro honesto de uma fronteira: *"esta fase não entrega isto"*. O #219 moveu a fronteira. O teste virou o seu inverso em vez de ser apagado, porque a propriedade que importa é a mesma nos dois casos: **a nota do endpoint `api` e a realidade concordam.**
