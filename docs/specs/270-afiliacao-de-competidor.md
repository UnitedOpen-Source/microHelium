# Issue #270 — a instituição pela qual uma equipe compete

## O defeito

A afiliação institucional de uma equipe era **inferida** de
`organization_memberships`, e o próprio código registrava isso:

> *"Issue #195 -- a organizacao de uma equipe. Nao existe
> `users.organization_id`: o vinculo mora em OrganizationMembership (#46)."*

```php
OrganizationMembership::where('user_id', $id)->orderBy('id')->value('organization_id');
```

`organization_memberships` foi criada no #46 para responder **"quem pode editar
as etiquetas e o dono dos problemas desta organização"** — permissão sobre
acervo, com coluna `role` cujo padrão é `editor`. Estava sendo lida como **"por
qual instituição esta equipe compete"**, que é outra pergunta.

| Situação | Antes |
|---|---|
| equipe que não é membro de banco nenhum | **sem instituição** |
| usuário membro de duas organizações | a **primeira por id**, arbitrariamente |
| quem edita o banco de uma e compete por outra | afiliação **errada** |

A primeira era o caso comum — a esmagadora maioria das equipes de uma regional
nunca tocou o banco de problemas. A terceira é a pior, porque **parece certa**.

Um ranking nacional por instituição não se sustenta sobre isso: se duas edições
discordarem sobre a qual universidade uma equipe pertence, o agregado fica
errado e ninguém consegue auditar por quê — a resposta dependia de qual linha
de permissão de banco tem o menor id.

## A modelagem, e por que a dúvida se dissolveu

A issue levantava a escolha: o vínculo é do **usuário** (vale para todas as
provas) ou da **participação naquele contest** (uma equipe pode representar
instituições diferentes em edições diferentes)? *"A segunda é mais fiel à
realidade e mais cara."*

A dúvida não existe neste repositório, e o `TeamImportCommand` já tinha
registrado por quê:

> *"IDENTITY IS (contest_id, icpc_id). (...) Scoped to the contest because the
> same team id genuinely reappears in next year's contest"*

Uma conta de equipe **já é por prova** — `users.contest_id` existe desde
`2025_11_25_000007`, e a mesma equipe no ano seguinte é outra linha. Então
`users.organization_id` é ao mesmo tempo a opção simples **e** a fiel: ela já
é, de fato, afiliação por participação.

## O que foi construído

| Peça | Papel |
|---|---|
| `users.organization_id` | o vínculo, com FK `nullOnDelete()` |
| `organizations.icpc_id`, `formal_name`, `country` | identidade externa, que a Contest API define e não existia |
| `Services\Clics\TeamAffiliation` | substitui `OrganizationMembershipLookup` |
| `Services\CompetitorAffiliationBackfill` | deriva o que a instalação já tem |
| `affiliations:report` | aponta o que falta em vez de inventar |
| `Backend\UserController` + os dois formulários | o escritor |

`ClicsPresenter::teams()` passou a emitir **`icpc_id` da equipe** — a coluna
existe desde o #89 e a Contest API define o campo, mas o método não o incluía.
Sem ele o consumidor não consegue casar a equipe com o cadastro nacional, que é
o ponto inteiro de o campo existir.

## A FK não basta, e isso foi medido

`users.organization_id` tem `foreignId(...)->nullOnDelete()`. Verificado no
MySQL do compose: a coluna e a constraint existem.

**Na conexão SQLite desta suíte, `PRAGMA foreign_keys` vem `0`** — as chaves
estrangeiras estão declaradas e **não são aplicadas**. Um teste que verificasse
`nullOnDelete()` aqui falharia não por defeito do código, mas por a garantia
não existir na plataforma de teste.

O efeito de uma referência pendurada não seria um campo estranho: `teams`
citaria uma `organization_id` que `/organizations` não lista, quebrando a
integridade referencial que o #195 estabeleceu como o **único requisito duro**
da fase 1 — e quebrando em silêncio, como uma linha faltando num painel.

Por isso `TeamAffiliation` **confere a existência** em vez de confiar no banco:
uma consulta por processo, memoizada como o resto. A garantia passa a valer em
qualquer engine.

> Isso é um achado maior que esta issue: **nenhuma FK deste esquema é aplicada
> na suíte**, então nenhum comportamento de cascade ou `nullOnDelete` está
> verificado. Registrado como issue própria.

## A derivação, para quem já rodava com a inferência

Sem ela a migração acrescentaria uma coluna vazia e toda equipe perderia a
instituição que a Contest API vinha relatando — **trocar afiliação errada por
afiliação nenhuma não é conserto**.

Deriva **apenas** de quem tem exatamente uma organização: ali a inferência
antiga e a afiliação coincidem, e não há o que escolher. Quem tem duas ou mais
fica nulo **de propósito**, e `affiliations:report` lista para o organizador
resolver.

Duas propriedades com guarda:

- **não escolhe o ambíguo** — escolher pelo organizador é o defeito;
- **não sobrescreve escolha já feita** (`whereNull('organization_id')`) — sem
  isso, uma segunda execução desfaria o trabalho manual pela organização de
  menor id, o defeito voltando pela porta da manutenção.

Vive num serviço, e não dentro da migração, porque uma derivação de dados que
não pode ser reexecutada nem testada é uma derivação em que ninguém confia.

## Fora de escopo

- O pacote de resultados (#271), que depende desta.
- Importar cadastro de instituições de fonte externa.
- CRUD de organizações: o #46 já registra que a criação/gestão é fase separada
  e que as linhas são administradas fora da UI por ora. Esta issue lê e
  publica o que existe, e não abre essa tela.
