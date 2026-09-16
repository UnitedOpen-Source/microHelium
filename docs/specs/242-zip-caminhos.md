# Caminhos que saem do próprio pacote (issue #242)

Quatro lugares extraem um ZIP que alguém enviou:

| porta | serviço | como a recusa aparece |
|---|---|---|
| `backend.import-package` | `ProblemPackageImportController` | frase na sessão, formulário mantém o que foi digitado |
| `backend.import-boca/upload` | `BocaImporterService` | frase na sessão |
| `POST /api/problems` | `Api\ProblemController` | 422 com a mensagem |
| — | `ProblemPackageService::importFromZip` | `ZipPathException` |

## Não é fuga de diretório

Medi antes de escrever a guarda. O `extractTo()` do PHP **não deixa escapar** do destino (PHP 8.5, libzip 1.22.8):

| entrada no ZIP | onde caiu |
|---|---|
| `../a.txt` | `alvo/a.txt` |
| `../../b.txt` | `alvo/b.txt` |
| `x/../../c.txt` | `alvo/c.txt` |
| `/tmp/abs-d.txt` | `alvo/tmp/abs-d.txt` |

Nada saiu. Chamar isto de Zip Slip seria descrever errado o que acontece — e um conserto guiado pela descrição errada mediria a coisa errada.

## O que sobra

O PHP resolve a travessia **reescrevendo o caminho em silêncio**. Sem erro, sem aviso.

`x/../../data/secret/01.ans` vira `data/secret/01.ans` e **passa por cima de um arquivo legítimo do próprio pacote**. Num pacote de problema isso troca um caso de teste: o pacote é aceito, o problema é importado, e o sintoma aparece longe da causa — uma submissão correta reprovada **durante a prova**, com o placar já andando.

`Tests\Unit\SafeZipExtractorTest::test_php_flattens_instead_of_escaping_which_is_why_the_guard_exists` guarda essa medição como teste, para que a razão não vire folclore. Se um dia ela falhar, a biblioteca mudou — e a guarda continua correta, porque não depende dela.

## A guarda

`SafeZipExtractor::escapingEntry()` percorre os nomes antes de qualquer escrita e devolve o primeiro que for absoluto ou tiver um segmento `..`. `extractTo()` recusa o arquivo **inteiro** nesse caso: meia extração no disco da máquina que serve a prova é pior que nenhuma.

O arquivo inteiro, e não só a entrada ruim, porque um pacote que tenta reescrever caminhos não é um pacote do qual se queira aproveitar o resto.

## O que **não** se recusa

Dois pontos no meio de um nome são legítimos: `problem_statement/a..b.pdf`, `x/y..z/w.txt`, `..oculto/x.txt`. Uma guarda que os recusasse rejeitaria pacotes bons e a recusa não diria por quê. São cinco controles positivos no teste da guarda, mais um em cada ponta do fluxo de importação.
