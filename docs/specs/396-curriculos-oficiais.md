# #396 — Currículos oficiais como dado: modelo genérico, BNCC Computação como primeira carga

Relacionadas: #390 (BNCC Computação; esta spec responde à pergunta 2 dela: tabela, não `tags`), #43 (Treino Livre), #46 (banco de problemas e governança), #397 (i18n).

## O problema

`ProblemBank` só tem `tags` livres. Não há como perguntar "que problemas praticam `EF06CO02`?", e se a marcação nascer "BNCC" o segundo currículo (CSTA, KS3, NAP argentino) vira refatoração. O mesmo problema de "soma de lista" pode ser `EF06CO02` na BNCC e um item de KS3 na Inglaterra ao mesmo tempo, e só uma relação N:N representa isso sem duplicar o problema.

## Modelo

```
curriculum_frameworks   id, slug (único), name, jurisdiction, version, locale,
                        source_url, source_consulted_at (data), source_sha256 (nulo),
                        source_notes (nulo), timestamps
curriculum_outcomes     id, curriculum_framework_id → frameworks (cascade),
                        code, stage (nulo), axis (nulo), text, position, timestamps
                        único (curriculum_framework_id, code)
problem_bank_outcomes   problem_bank_id → problem_bank (cascade),
                        curriculum_outcome_id → curriculum_outcomes (cascade),
                        chave primária composta, timestamps
```

- **Currículo → etapa → habilidade.** A etapa é uma coluna (`stage`, texto livre como o documento a escreve: "6º ano", "KS3"), não uma tabela: nenhum currículo examinado dá à etapa atributos próprios, e a ordem vem de `position` (a ordem do documento). Se um dia a etapa precisar de atributos, vira tabela sem mudar o contrato da API.
- **`code` é obrigatório e único por currículo.** É a chave natural que torna a reimportação idempotente. Currículos em prosa (KS3, NAP) usam um código gerado por quem prepara o CSV (ex. `KS3-3`), como a própria #396 sugere. Não há código nulo.
- **`axis` é opcional.** A BNCC tem eixos no Infantil e no Fundamental; o Ensino Médio é organizado por competência específica, não por eixo, e fica com `axis` nulo em vez de um eixo inferido.
- **`locale`** registra o idioma original do texto. Tradução está fora de escopo (#397).
- **Global da instalação.** Currículo derivado por rede/organização (pergunta 2 da #396) fica para quando houver um caso real; o modelo não impede acrescentar `organization_id` nulo depois.
- Os `tags` livres **continuam** existindo para tema, técnica, origem.

## Importação: `php artisan curriculum:import <arquivo.csv>`

O texto oficial muda de versão e não deve morar em migration. O CSV e um manifesto JSON **com o mesmo nome** ficam lado a lado:

- `<nome>.json`: `slug`, `name`, `jurisdiction`, `version`, `locale`, `source_url`, `source_consulted_at` (AAAA-MM-DD) obrigatórios; `source_sha256`, `source_notes` opcionais.
- `<nome>.csv`: UTF-8 (BOM tolerado), cabeçalho obrigatório com `code`, `stage`, `axis`, `text` (nessa ordem ou não; colunas desconhecidas são recusadas). A ordem das linhas é a `position`.

Regras, todas verificadas **antes** de escrever qualquer coisa:

| Situação | Resultado |
|---|---|
| Arquivo ou manifesto ausente, manifesto sem campo obrigatório, JSON inválido | erro, código de saída 1, nada gravado |
| Cabeçalho sem `code` ou `text`, coluna desconhecida ou repetida | erro, nada gravado |
| Linha com número de colunas diferente do cabeçalho | erro com o número da linha |
| `code` vazio, fora de `[A-Za-z0-9._-]{1,32}`, ou repetido no arquivo | erro com o número da linha |
| `text` vazio; `stage`/`axis` acima de 100 caracteres | erro com o número da linha |
| Byte que não é UTF-8 válido | erro com o número da linha |
| CSV sem nenhuma habilidade | erro |

Com o arquivo válido, tudo é gravado numa transação:

- currículo: localizado por `slug`, criado ou atualizado com o manifesto;
- habilidade: localizada por (`currículo`, `code`); criada, atualizada (texto, etapa, eixo, posição) ou deixada como está;
- **habilidade que existe no banco e não está no CSV é mantida e listada no relatório**, nunca apagada: ela pode estar associada a problemas, e apagar em silêncio uma associação feita por um professor é pior do que manter uma linha a mais. Remover é decisão humana.
- saída: `criadas: N, atualizadas: N, inalteradas: N`, mais as ausentes do CSV. Rodar duas vezes o mesmo arquivo dá `criadas: 0, atualizadas: 0`.

O seed não chama o importador: a carga é um passo explícito de instalação (`php artisan curriculum:import database/curricula/bncc-computacao-2022.csv`).

## A primeira carga: BNCC Computação

`database/curricula/bncc-computacao-2022.{csv,json}`.

- **Fonte:** anexo ao Parecer CNE/CEB nº 2/2022, "Computação na Educação Básica – Complemento à BNCC", a que remete o art. 1º, §2º, da Resolução CNE/CEB nº 1/2022, na cópia de `basenacionalcomum.mec.gov.br`, **consultada em 24/09/2026**. SHA-256 do PDF registrado no manifesto (75 páginas).
- **141 habilidades**: `EI03CO01`–`11`, `EF01CO..`–`EF09CO..`, os agregados por etapa `EF15CO01`–`09` ("1º ao 5º ano") e `EF69CO01`–`12` ("6º ao 9º ano"), e `EM13CO01`–`26`.
- **Como foram conferidas:** o texto de cada habilidade foi extraído do PDF oficial pela posição na página (coluna "HABILIDADE"), e o eixo pela célula da coluna "EIXO" que contém a habilidade (as fronteiras são as linhas horizontais da tabela). O texto extraído foi então comparado com uma segunda leitura independente do mesmo PDF (`pdftotext`, ordem de leitura): as 141 coincidem caractere a caractere. Páginas com fronteira de eixo no meio (ex. 4º ano, 7º ano) foram conferidas também visualmente.
- **`EF05CO011`**: o PDF imprime esse código com três dígitos na 11ª habilidade do 5º ano. Entra **como está impresso**; corrigir para `EF05CO11` seria inventar um código que o documento não tem. (A #390 contou 140 códigos porque a expressão regular dela exigia dois dígitos.)
- **Aspas tipográficas** e a ausência de ponto final em `EF06CO01` são do original.
- Não entraram: objetos de conhecimento, competências específicas, explicações e exemplos. São conteúdo pedagógico, fora do escopo da #396.

## API e tela

Tudo sob o contrato existente de `/api/frontend/*` (sessão + CSRF, envelope `data`).

- `GET /api/frontend/curricula` (autenticado): currículos com as habilidades, na ordem do documento, para o seletor da tela de governança. `{ data: { frameworks: [ { id, slug, name, version, jurisdiction, locale, source_url, source_consulted_at, outcomes: [ { id, code, stage, axis, text } ] } ] } }`.
- `GET /api/frontend/bank-governance`: cada item ganha `outcomes: [ { id, code, stage, axis, text, framework: { slug, name } } ]`.
- `PATCH /api/frontend/bank-governance/{bank}`: aceita `outcome_ids` (lista de inteiros, até 50). **Se o campo não vier, as associações não mudam** — clientes antigos continuam válidos. Se vier, substitui o conjunto (`sync`) na mesma transação e com a mesma trava de versão e a mesma autorização (`update`) das etiquetas. Id inexistente, repetido ou não inteiro → 422 em `outcome_ids`.
- `GET /api/frontend/practice/problems/{id}`: `problem.skills` com as habilidades do problema do banco (código, texto, etapa, eixo, currículo). Associação é metadado de catálogo, não enunciado: mostra o estado atual do banco, não o do snapshot publicado.
- `GET /api/frontend/practice/problems?skill=<código>`: filtra a biblioteca pelos problemas associados a uma habilidade com esse código (em qualquer currículo). Os itens ganham `skills` (só os códigos).
- Tela `/backend/bank-governance`: ao "Organizar", uma lista de habilidades com busca por código/texto e caixas de seleção, agrupada por currículo e etapa. Tela `/practice/problems/{id}`: seção "Habilidades" com código, texto e currículo; cada código leva à biblioteca filtrada por ele.

## Testes

- Importação: carga da BNCC real (141, contagem por etapa, `EF05CO011`, `EM13CO..` sem eixo, fonte gravada); reimportação idempotente (0 criadas/0 atualizadas); reimportação com texto alterado atualiza sem duplicar e preserva associações; habilidade ausente do CSV é mantida e reportada; CSV malformado (colunas, código repetido, código vazio, texto vazio, UTF-8 inválido, cabeçalho desconhecido, arquivo vazio, manifesto ausente/inválido) falha sem gravar nada.
- Relações: currículo ↔ habilidades, problema ↔ habilidades nos dois sentidos, cascata ao apagar problema.
- API: seletor exige login; governança lista e grava associações, recusa ids inválidos, respeita versão e autorização, e não mexe nas associações quando `outcome_ids` não vem; Treino Livre mostra as habilidades e filtra por código.

## Fora de escopo (continua na #396 / #390)

- Relatório de cobertura por organização ("destas N habilidades do 6º ano, o acervo cobre X").
- Filtro navegável currículo → etapa → habilidade no Treino Livre (hoje o filtro é por código).
- Segundo currículo (KS3, CSTA). O CSTA tem licença de associação; a decisão de versionar ou não o CSV dele é do mantenedor (pergunta 1 da #396).
- Currículo por organização (pergunta 2 da #396).
- Tradução dos textos oficiais (#397).
