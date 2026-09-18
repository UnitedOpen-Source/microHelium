# Issue #293 — as chaves estrangeiras passam a ser aplicadas

## O que estava errado

A conexão `sqlite` de `config/database.php` **não tinha**
`foreign_key_constraints`, e o SQLite desliga as chaves por padrão. Medido
dentro da suíte:

```
driver: sqlite
pragma foreign_keys: [{"foreign_keys":0}]
users tem FK para organizations? SIM
```

A constraint **estava no DDL** e **não era aplicada**. O esquema inteiro
declarava `cascadeOnDelete()` e `nullOnDelete()` que não aconteciam — nem nos
testes, nem numa instalação SQLite de produção.

O repositório já sabia que referência pendurada derruba tela. O
`Backend\SiteController::destroy()` limpa à mão e diz por quê:

> *"Site uses SoftDeletes, so the users.site_id / site_judging_routes foreign
> keys' nullOnDelete()/cascadeOnDelete() never fire (...) without this, users
> and judging routes are left pointing at a now-trashed site, which crashes
> JudgeController the next time that judge loads /judge/runs."*

O que ninguém sabia é que, no SQLite, a chave **também não disparava no
delete real**.

## O tamanho do estrago, medido antes de consertar

A issue pedia para *"rodar a suíte inteira com as FKs ligadas e **listar** o
que quebra, em vez de consertar às cegas"*. O resultado:

**Quatro problemas em 1690 testes.** O esquema estava em boa forma; o que
faltava era a chave de configuração.

Três eram **testes inventando órfãos** — exatamente o que a issue previu:

| Teste | O que fazia |
|---|---|
| `ProblemModelTest::testHasManyRunsRelationship` | `'language_id' => 1` sem criar a linguagem |
| `RunTest::test_is_judged_returns_correct_value` | `'answer_id' => 1` sem criar o veredito |
| `UserModelTest::testUserAttributesAreFillable` | `'contest_id' => 1, 'site_id' => 1` sem criar nenhum dos dois |

Os três montavam linhas que **não poderiam existir em produção** e afirmavam
coisas sobre elas. Consertados criando as linhas de verdade.

## O quarto era meu, e virou uma remoção honesta

`CompetitorAffiliationTest::test_the_backfill_skips_a_membership_whose_organization_is_gone`,
do #270, montava o cenário apagando a organização e **contando com a linha de
governança sobreviver** — o que funcionava porque as chaves estavam
desligadas.

Com elas aplicadas, apagar a organização apaga a governança **em cascata**, e
o estado deixou de existir. E não dá para recriar: o `RefreshDatabase` envolve
cada teste numa transação, e no SQLite `PRAGMA foreign_keys` é **no-op dentro
de transação**.

O ramo `skipped_missing_organization` **fica** — defende as instalações que
rodaram sem as chaves, que até este PR eram todas as de SQLite, e quem apaga
linha por SQL cru. Mas fica **sem guarda de teste**, e isso está escrito no
serviço e no arquivo de teste. Um teste que não consegue exercitar o ramo não
o protege, e fingir que protege é o modo de falha que este repositório
catalogou.

## O controle positivo, que é o ponto

A issue foi explícita:

> **Controle positivo obrigatório:** antes de concluir qualquer coisa, provar
> que as FKs estão de fato ligadas (...) Sem isso, "os testes passam"
> significa "as FKs continuam desligadas".

`tests/Feature/ForeignKeyEnforcementTest.php` faz isso, e o primeiro teste é
literalmente ler o `PRAGMA`. Os outros cinco provam o comportamento:
referência inexistente é recusada, `nullOnDelete` dispara, `cascadeOnDelete`
dispara, e — o contra-caso — apagar uma prova **não** apaga as pessoas dela.

Esse último não é enfeite: sem ele o teste de cascata passaria igual se o
cascade levasse usuário junto, e perder conta de equipe porque alguém apagou a
prova do ano passado seria pior que a referência pendurada.

A mutação que a issue pede — desligar de novo — derruba **os seis**.

## A porta de fuga

`DB_FOREIGN_KEYS=false` devolve o comportamento antigo. Existe para quem tenha
um banco com referência pendurada já gravada e precise subir a aplicação antes
de limpar: sem ela, atualizar quebraria a instalação em vez de avisá-la.

O padrão é **ligado**, e há teste para isso — uma garantia opcional por
omissão não é garantia.

## O que isto não desfaz

`TeamAffiliation` continua conferindo a existência da organização em código, e
deve continuar: uma instalação que rodou sem as chaves pode ter a referência
pendurada **já gravada**, e o banco só recusa escritas novas. A conferência é
o que impede aquele dado antigo de virar `organization_id` citada em `teams`
que `/organizations` não lista — a integridade referencial que o #195
estabeleceu como o único requisito duro da fase 1.
