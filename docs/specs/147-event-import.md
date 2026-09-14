# Importação de evento por arquivo — issue #147

`php artisan event:import <arquivo> [--contest=<id>] [--format=auto|yaml|json] [--apply] [--force]`

Provisiona a competição inteira — contest, sedes, linguagens e problemas —
a partir de **um** arquivo declarativo. É o equivalente ao
`src/system/importxml.php` do BOCA, cujo conteúdo é predominantemente sede.

O formato não é o XML do BOCA de propósito: esse arquivo é escrito à mão,
versionado junto com os pacotes de problema e revisado em diff entre uma
edição e outra. YAML e JSON servem melhor para isso. Ler o XML do BOCA
continua razoável como importador *adicional* — produziria o mesmo
`ParsedEvent` e reaproveitaria todo o resto.

## Como usar

```bash
php artisan event:import regional.yml            # prévia: não grava nada
php artisan event:import regional.yml --apply    # aplica (pede confirmação)
php artisan event:import regional.yml --apply --force   # sem confirmação
```

A prévia lista tudo que seria **criado**, **alterado** (com o valor de
antes e o de depois de cada campo) e o que fica **inalterado**. Sem
`--apply` nada é gravado. Com `--apply`, ou tudo entra ou nada entra: o
plano é recalculado dentro da transação e a importação é recusada se
houver qualquer erro.

Exemplo completo e comentado: `tests/Fixtures/event-import/regional.yml`
(o mesmo evento em JSON: `regional.json`).

## Formatos

| Formato | Quando usar |
| --- | --- |
| JSON | Sempre funciona, inclusive na imagem de produção (`composer install --no-dev`). |
| YAML | Aceita comentários e aponta a linha do erro de sintaxe. Depende de `symfony/yaml`, hoje uma dependência **de desenvolvimento** deste projeto. |

Se `symfony/yaml` não estiver instalado, um arquivo YAML é recusado com uma
mensagem explícita (nunca em silêncio). Promover `symfony/yaml` a
dependência de produção é uma mudança de uma linha no `composer.json` e é o
encaminhamento recomendado.

## Identidade e idempotência

Rodar o mesmo arquivo duas vezes não duplica nada. Cada nível é
identificado por algo que está no próprio arquivo, nunca por id — um
arquivo versionado em git e aplicado num servidor novo não conhece id
nenhum:

| Nível | Identidade | Por quê |
| --- | --- | --- |
| contest | `name` (ou `--contest=<id>`) | Não há índice único em `contests.name`; a busca é feita entre competições não-prática e não-removidas, e um nome ambíguo é **recusado**, não adivinhado. |
| sede | `(contest_id, name)` | É literalmente o índice UNIQUE da tabela. |
| linguagem | `(contest_id, extension)` | A tabela tem **dois** índices únicos (`name` e `extension`); a extensão é a chave técnica (vira caminho no judge e nome do arquivo enviado), o nome é rótulo. |
| problema | `(contest_id, basename)` | O basename é o diretório do pacote (`storage/app/problems/<contest>/<basename>`); `short_name` é a letra do placar e é remanejada a cada edição. |

Consequências que valem saber:

- **Renomear uma sede cria outra sede.** A antiga continua dona de
  `users.site_id` e dos runs dela. A prévia mostra a nova como "criar" e a
  antiga como "fora do arquivo".
- **Trocar as letras dos problemas em uma rodada funciona.** Os valores
  que mudam são estacionados num placeholder antes de os finais serem
  escritos, porque o índice único é verificado a cada statement.
- **Nada é removido, nunca.** Linhas que existem na competição e não estão
  no arquivo são listadas e deixadas em paz — apagar uma sede cascateia
  para as equipes dela.
- Linhas removidas (soft delete) ainda ocupam o nome no índice único, então
  são **restauradas** em vez de duplicadas.
- A busca é case-insensitive, porque a colação padrão do MySQL também é.

## O arquivo descreve o estado final

Um campo omitido volta ao padrão; não é um patch. É isso que torna o diff
entre duas edições legível — e é por isso que a prévia existe. A exceção é
o que o arquivo não menciona de jeito nenhum (uma sede inteira, um bloco
inteiro), que é apenas relatado.

## Blocos e campos

Topo: `version` (obrigatório, hoje `1`), `contest`, `sites`, `languages`,
`problems`. Qualquer outra chave, em qualquer nível, é **erro** — um
`freze_time` em vez de `freeze_time` passaria despercebido e congelaria o
placar na hora errada.

### contest

| Campo | Padrão | Observações |
| --- | --- | --- |
| `name` | — | obrigatório, até 100 caracteres; é a identidade |
| `description` | vazio | |
| `start_time` | vazio | ISO 8601 **com fuso**: `2026-09-19T13:00:00-03:00`. Sem fuso é aceito, mas avisado, e lido no fuso do servidor. O valor é convertido para o fuso da aplicação antes de ser gravado. |
| `duration` | 300 | minutos, 1..10080 |
| `freeze_time` | 60 | minutos antes do fim; maior que `duration` é aceito com aviso |
| `penalty` | 20 | minutos, 0..120 |
| `max_file_size` | 100 | KB |
| `is_active` | false | |
| `is_public` | false | |
| `unlock_key` | vazio | |

`is_practice` **não** é importável: o contest técnico do Treino Livre
(#43) não é uma competição.

Uma competição criada por aqui recebe as respostas padrão (`answers`) da
mesma lista que a tela de criação usa — sem elas um run julgado fica com
`answer_id` nulo.

### sites

| Campo | Padrão | Observações |
| --- | --- | --- |
| `name` | — | obrigatório, até 100; é a identidade |
| `ip_address` | vazio | lista separada por vírgula de IPs/redes; validada com a mesma regra da tela de sedes |
| `is_active` | true | |
| `permit_logins` | true | |
| `auto_judge` | false | |
| `duration` | vazio | minutos; vazio = herda da competição |
| `freeze_time` | vazio | minutos; vazio = herda da competição |
| `max_runtime` | 600 | segundos |
| `chief_judge_name` | vazio | até 50 |
| `score_visibility` | `all` | `all` ou `own_site` |
| `max_judge_wait_time` | 900 | segundos, mínimo 60 |
| `judges_for` | (ausente) | nomes das sedes cujos runs esta sede julga |

`judges_for` vira `site_judging_routes`. Ausente = o arquivo não fala sobre
roteamento desta sede e o que foi configurado à mão fica intacto; lista
vazia = afirmação, apaga as rotas. A própria sede na lista é ignorada com
aviso.

### languages

Duas formas:

```yaml
- preset: cpp_gpp13        # nome e comandos vêm do catálogo do microHelium
  is_active: true          # opcional; name/compile_command/run_command também podem ser sobrescritos

- name: "Haskell (GHC 9)"  # descrição por extenso
  extension: hs            # só letras, números e underscore (vira caminho no judge)
  compile_command: "ghc -O2 -o {output} {source}"
  run_command: "./{executable}"
```

`preset` e `extension` juntos e diferentes são conflito. Linguagens do
catálogo que não estiverem no arquivo **não** são criadas — o arquivo é a
fonte da verdade.

### problems

| Campo | Padrão | Observações |
| --- | --- | --- |
| `short_name` | — | obrigatório, até 10, letras/números/`_`/`-` |
| `name` | — | obrigatório, até 200 |
| `basename` | — | obrigatório; é nome de diretório e é a identidade |
| `description` | vazio | |
| `color_name` / `color_hex` | vazio | `#RRGGBB` |
| `time_limit` | 1 | segundos |
| `memory_limit` | 256 | MB |
| `output_limit` | 1024 | KB |
| `auto_judge` | true | |
| `is_fake` | false | |
| `sort_order` | posição na lista | |
| `package` | — | caminho do zip, relativo ao próprio arquivo |

`package` delega ao `ProblemPackageService` — o mesmo caminho da tela de
importação de problemas — que lê o `problem.info`, move o pacote para
`storage/app/problems/<contest>/<basename>` e registra os casos de teste. O
que o arquivo diz vence o que o `problem.info` diz. O pacote é importado
**uma vez**, na criação do problema: reimportar duplicaria os casos de
teste, então para um problema que já existe o `package` é ignorado com
aviso.

## Equipes

Não entram aqui. Equipes vêm do arquivo do ICPC por
`php artisan teams:import` (issue #141), que tem o mesmo formato de
comando (prévia por padrão, `--apply`, `--force`) e cuida dos links de
ativação, que são segredo e não pertencem a um arquivo versionado.

## Erros

Todos são relatados de uma vez, em ordem de arquivo, com o caminho do nó
(`sites[3].max_judge_wait_time`) e, quando há, o nome do item. Erro de
sintaxe YAML traz a linha; JSON não traz (o `json_decode` do PHP não
informa posição), e a mensagem diz isso em vez de fingir.
