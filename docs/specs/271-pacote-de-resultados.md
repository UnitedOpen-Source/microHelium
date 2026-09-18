# Issue #271 — o pacote de resultados

## O que é

Um ZIP gerado ao fim da competição, para envio a um site nacional que agrega
as edições e produz um ranking. Segue o precedente do `Backup\BackupService`:
um `manifest.json` que diz *"o que é este arquivo"*, escrito para quem o abre
num dia ruim, num shell, sem este repositório aberto ao lado.

```
manifest.json       identidade da prova, datas, finalização, e o SHA-256 de
                    cada arquivo do pacote
standings.json      formato CLICS: equipes, problemas, classificação, prêmios
standings.csv       as cinco colunas do BOCA, inalteradas
organizations.json  as instituições referenciadas
```

Dois consumidores num pacote só: a ferramenta que lê CLICS, e quem já espera o
arquivo do BOCA.

## A propriedade que sustenta a confiança

**Exportar duas vezes a mesma prova finalizada produz os mesmos bytes**,
exceto `generated_at`.

Com isso o site nacional não precisa *confiar* no arquivo: ele pede a
reexportação e compara. Adulteração aparece como **divergência**, não como
suspeita.

Isso custou cuidado em quatro pontos, e cada um tem guarda:

| Ponto | O que fazia quebrar |
|---|---|
| ordem | coleções ordenadas explicitamente; mapas do manifesto passam por `ksort` antes de serializar, porque `json_encode` preserva ordem de inserção |
| relógio | **nada** além de `generated_at` lê `now()` |
| caminhos | nenhum caminho absoluto entra no conteúdo |
| o ZIP | entradas com `mtime` **fixo** — sem isso o contêiner difere mesmo com conteúdo idêntico, porque o formato guarda a data de cada entrada |

O último é o que torna a promessa honesta: "idêntico byte a byte" seria uma
promessa que o próprio formato quebra.

## Duas guardas que não guardavam, e como apareceram

As mutações são o que pegou, e vale registrar porque as duas passaram limpas
na primeira rodada.

**O `mtime` das entradas.** O teste original exportava dois pacotes com
`travel(2)->days()` entre eles e comparava o hash. Ele não guardava nada:
`travel()` move o Carbon, **não** `time()`. A mutação que troca a constante
por `time()` passava limpa, porque as duas exportações caem no mesmo segundo
de relógio real. Consertado verificando a propriedade **direto na entrada do
ZIP**, via `statIndex()`.

**A ordem dos problemas.** Aqui houve duas correções, e a segunda é sobre
honestidade.

Escrevi no primeiro comentário que `ClicsPresenter::problems()` fazia um
`->get()` cru, sem ordem. **Estava errado**: `Contest::problems()` já aplica
`orderBy('sort_order')`. O que faltava eram os **desempates** — `sort_order`
não é único, e o `ordinal` da Contest API sai do **índice** da coleção, então
dois problemas empatados trocavam de posição publicada entre leituras.

E a guarda comportamental disso **não é observável no SQLite**: medido, sem os
desempates o motor devolve a ordem certa por coincidência do índice que
escolhe. Mesma família do `PRAGMA foreign_keys = 0` (#293) — garantia que a
plataforma de teste dá de graça e a de produção pode não dar.

A saída foi nomear o conceito: `Problem::scopeInContestOrder()`. Um escopo, e
não três `orderBy` soltos no chamador, porque *"a ordem dos problemas de uma
prova"* é um conceito do domínio e precisa ter um lugar só — e porque assim a
garantia é **verificável**: um teste pergunta ao escopo qual SQL ele produz, e
remover qualquer um dos três `orderBy` derruba.

## Privacidade

A régua: **o pacote não exporta mais do que o placar público já mostra, mais a
chave de agregação.**

`icpc_id` da equipe **entra**, por decisão explícita e escrita: é identificador
pessoal, e é justamente o que torna a agregação possível. Entra por decisão, e
não por efeito colateral.

O que **não** entra: data de nascimento, e-mail, e qualquer campo que a
`ProfilePrivacyPolicy` proteja — este sistema tem contas gerenciadas de menores
com `profile_visibility` privado por padrão (#47).

**E quem pegou o vazamento foi o teste, não a leitura do código.**
`Leaderboard::getScoreboard()` devolve `'user' => $user`, o **modelo inteiro**.
O `$hidden` de `Helium\User` cobre `password`, `remember_token` e `birthdate`,
e **não cobre `email`**: serializar a linha crua poria o e-mail de cada equipe
num arquivo destinado a um site nacional. O teste que varre o ZIP inteiro
procurando campo protegido derrubou na primeira execução.

Por isso a classificação é **projetada campo a campo** em
`ResultsBundleBuilder::standingsRows()`. Projetar também estabiliza o formato
publicado: o pacote deixa de mudar de forma quando a tela de placar muda de
forma, e um receptor nacional não é um consumidor que se possa quebrar de leve.

## Identidade do evento

`contests.id` é auto-incremento **local**: a prova 12 de uma instalação e a
prova 12 de outra colidem. Três colunas novas:

| Coluna | Papel |
|---|---|
| `uuid` | identificador estável, gerado uma vez no `creating` e **fora do `$fillable`** — é o que permite dizer "este pacote é uma nova exportação daquela prova" em vez de "este é outro evento" |
| `edition` | a edição ("2026") |
| `phase` | a fase ("regional", "final nacional") |

`edition` e `phase` existem porque uma regional e uma final nacional não são o
mesmo tipo de evento no agregado, e o nome da prova sozinho não carrega essa
distinção de forma legível por máquina.

## O portão

Nada sai de prova que ainda pode mudar. `assertExportable()` recusa:

- **prova não finalizada** — exportar provisório, com rejulgamento pendente ou
  placar ainda congelado, envenena o agregado;
- **o Treino Livre** (#43) — não é um evento, não tem classificação, e não
  pode ser alcançado por uma exportação com forma de evento.

O endpoint responde **409, e não 422**: nada no *pedido* está errado — é o
estado da prova que ainda não permite. Um 422 mandaria o cliente procurar o
campo inválido que ele mandou, e não há nenhum.

O pacote sai sempre **descongelado**, e sem alternativa: a prova está
finalizada, então o que sai é a classificação final. Um pacote de resultados
congelado seria um pacote que esconde o resultado, o que não é resultado
nenhum.

## Fora de escopo

- **O site nacional que recebe.** Isto entrega o arquivo, não o receptor.
- **Assinatura digital por sede.** Considerada e adiada: resolve sede
  maliciosa, mas traz distribuição, rotação e revogação de chaves, e só faz
  sentido quando houver um receptor real com quem combinar o protocolo. O
  checksum com reexportação cobre adulteração acidental e edição manual, que é
  o risco de hoje.
- **Importar de volta** um pacote de outra instalação.
