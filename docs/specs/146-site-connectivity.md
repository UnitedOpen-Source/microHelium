# Sede sem link com o servidor central — issue #146

Decisão registrada: **não construir replicação por sede.** O que entra no
lugar é um requisito operacional escrito e um caminho barato para o caso
transitório.

Isto responde à recomendação do próprio #146 — *"medir a necessidade antes
de construir"* — e a medição aqui não foi de throughput, foi de prática:
o que os sistemas reais fazem, e o que a Maratona de fato pede às sedes.

## O que o #146 assumia, e o que se confirmou

> "O BOCA sobrevive a isso e nós não."

**Verdadeiro do BOCA-como-projeto, falso do BOCA-como-implantado.**

### A parte verdadeira

Cada sede do BOCA é uma instalação completa — PostgreSQL próprio, interface
própria, autojudge próprio. `src/private/autojudging.php` conecta no banco
**local**; `src/team/run.php` grava numa transação local. Nada no caminho de
submeter ou julgar toca a rede.

A sincronização é sempre iniciada pela sede local
([`doc/ADMIN.txt`](https://github.com/cassiopc/boca/blob/master/doc/ADMIN.txt)):

> "the users of type 'site' on the main server have an important
> characteristic: they are not allowed to directly log in to the main site,
> but connections are established from the local sites."

Um POST para `site/getsite.php` leva o delta local **para cima** e traz o
delta principal **para baixo** na mesma resposta. Delta por `updatetime`,
merge last-write-wins (`if($updatetime > $t || $insert)`), e a marca
d'água só avança **depois de um import bem-sucedido** — todo caminho de
falha (`"timeout at authorization"`, `"timeout at transferring"`,
`"Data corrupted"`) retorna sem avançá-la. Então depois de uma queda, a
próxima transferência bem-sucedida reenvia tudo desde o último sucesso.

O gatilho é um botão de admin com `<meta http-equiv="refresh"
content="60">` — **não há cron nem daemon** para transferência entre sedes.
Roda enquanto alguém deixar aquela aba aberta.

Colisão de identidade de run é estruturalmente impossível: a chave primária
é `("contestnumber", "runsitenumber", "runnumber")`. Desde 2017 os números
também são derivados de microtime (`myunique()`), com retry em savepoint
na colisão.

E o autor documentou a tolerância a partição em 2004 — **incluindo o preço**
([de Campos & Ferreira, WEI 2004](https://sol.sbc.org.br/index.php/wei/article/view/44570),
§3.7):

> "caso um servidor perca sua conexão com os demais, nenhum dos outros é
> afetado […] e a competição pode continuar normalmente, **agora com a
> necessidade de duas equipes de juízes** (uma para cada parte desconexa da
> rede)."

O §3.6 do mesmo artigo dá o motivo do multi-sede, e **não é** tolerância a
falha:

> "Apenas com processamento centralizado das submissões e dúvidas podemos
> ter igualdade de condições para todos os participantes […] pois tudo será
> processado pelo mesmo grupo de juízes."

Multi-sede existe por **escala continental e justiça de julgamento**.
Sobreviver à partição é consequência declarada, não o objetivo.

### A parte falsa

**Desde 2016 a Maratona não implanta o BOCA assim.** A correção é
centralizada, e as máquinas das equipes falam com os **servidores centrais**,
não com um servidor da sede. O [Manual do Diretor de
Sede](https://maratona.sbc.org.br/manual.pdf) (2025), §3.3:

> "É fundamental que seja garantido acesso à Internet na sua sede, para que
> isso funcione. Como plano B ao acesso usual, você deverá ter uma opção de
> acesso alternativo à Internet, como o de uma provedora comercial."

E §4.3: *"A correção é centralizada, assim os juízes que corrigirão os
problemas estão em outras cidades."* A
[página de ambiente](https://maratona.sbc.org.br/sobre/ambiente_computacional.html)
é mais curta ainda: *"As sedes devem garantir o acesso à Internet com
redundância."* — e anuncia que **em 2026 o BOCA sai**, substituído pelo MOJ.

Confirmação independente da topologia: o
[maratona-firewall](https://github.com/maratona-linux/maratona-firewall)
oficial bloqueia todo tráfego exceto para um punhado de IPs únicos do
"BOCA central".

A regra que sobrevive é outra, e é mais modesta — desde a centralização,
cada sede precisa ter um **plano B de correção**
([regras 2015](https://maratona.ime.usp.br/2015/regras15.html)):

> "deverão manter um esquema alternativo de correção das submissões para o
> caso de algum problema ocorrer."

## O que ninguém faz

**DOMjudge diz o contrário, explicitamente**
([config-advanced](https://www.domjudge.org/docs/manual/main/config-advanced.html)):

> "there must be a relatively reliable network connection between the
> locations and the central DOMjudge installation, **because teams cannot
> submit or query the scoreboard if the network is down**."

A única alternativa por sede que ele oferece é agregação de placar
depois do fato (`scoreboard:merge`) — só placares, sem submissões,
julgamentos, clarificações ou escrita de volta.

**CLICS não exige tolerância a partição em lugar nenhum.** O requisito é de
durabilidade: *"The CCS must not lose more than 1 minute of contest data on
a failure of any part of the contest system."*

## O mecanismo que aparece três vezes

O que de fato resolve, nos três lugares onde aparece, é o mesmo:
**fila na ponta, com carimbo de tempo preservado e id único por sede,
esvaziada na reconexão.**

1. **BOCA, no cliente**: `tools/boca-submit-run-cron` varre
   `/root/submissions/*.bocarun` a cada 2 minutos, renomeia para
   `.processed` no sucesso e deixa o arquivo na fila no timeout. O material
   entregue aos competidores em 2019 tem uma seção chamada literalmente
   [*"A internet caiu, e agora?"*](https://maratona.ime.usp.br/hist/2019/primfase19/provas/competicao/info_maratona.pdf):

   > "Se a internet cair você poderá verificar se existe alguma submissão
   > ainda na fila local […] com o comando: `boca-submit-list`. […] No caso
   > de uma falha temporária de internet, pode levar alguns minutos até que
   > tudo apareça devidamente no servidor (isso não afeta o horário da
   > submissão, não se preocupe)."

2. **CLICS, como padrão permitido** (Contest API, *"Use cases for POSTing
   and PUTting submissions"*):

   > "each site runs a proxy that **would still be reachable if connectivity
   > with the central CCS is lost** […] each site could be assigned a unique
   > prefix such that the proxy server itself can generate unique `id`s"

3. **BOCA, no servidor**: a chave `(contest, site, runnumber)` é a mesma
   ideia de prefixo por sede, do lado de dentro.

## A decisão

**Não replicar.** Três razões, em ordem de peso:

1. **A necessidade que o issue supunha não é a que a prática documenta.**
   Desde 2016 a Maratona pede link redundante e um plano B de correção —
   não uma instalação autônoma por sede. O preço que o próprio autor do
   BOCA registrou em 2004 é uma segunda banca de juízes por partição, o que
   é uma decisão de regulamento e não de software.
2. **Conflito de dados em placar de competição é problema sério**, como o
   #146 já dizia. O merge do BOCA é last-write-wins sobre `updatetime`;
   é aceitável quando cada linha tem um dono claro por sede, e deixa de ser
   no instante em que duas sedes tocam a mesma linha.
3. **Ninguém mais faz.** O DOMjudge diz na documentação que a equipe não
   submete com a rede fora; o CLICS pede 60 segundos de durabilidade e não
   fala em partição.

### O que entra no lugar

**Requisito operacional, escrito.** Uma sede do microHelium precisa de link
estável até o servidor central, com redundância — a mesma coisa que a
Maratona pede às suas. Está no README.

**Fila na ponta para a queda transitória**, que é o caso comum (segundos a
minutos, não a prova inteira): o cliente de linha de comando do #145
(`bin/mh`) é o lugar natural, porque já roda na máquina da equipe. Registrado
como próximo passo concreto, com uma ressalva que não pode ser esquecida:

> O carimbo de tempo do BOCA é confiável porque o cliente dele é um cron de
> root numa imagem de prova controlada. O `bin/mh` é um script que a equipe
> controla, então **aceitar um horário afirmado pelo cliente seria aceitar
> uma submissão antedatada**. A fila da ponta aqui pode poupar a equipe de
> redigitar o comando; não pode preservar o horário sem uma história de
> confiança que hoje não existe.

### O que reabre esta decisão

Um relato concreto — uma sede real, numa prova real, que perdeu o link por
tempo suficiente para custar submissões. Isso não foi encontrado em lugar
nenhum: nem em relato de prova, nem em lista de discussão, nem nas issues
do `cassiopc/boca`. Um único caso documentado vale mais do que tudo que
está escrito acima.
