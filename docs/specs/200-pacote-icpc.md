# 200 — Importar o formato de pacote da ICPC/Kattis

## O que estava fechado

O `ProblemPackageService` lia **só o formato do BOCA**. Conferido: `problem.yaml`, `domjudge-problem.ini` e `testdata.yaml` não apareciam em lugar nenhum de `app/`.

O formato da ICPC/Kattis é o que o resto do mundo produz e consome — o ICPC Problem Archive publica nele, o Polygon (>50 mil problemas preparados) exporta para ele, e problemtools, BAPCtools, Kattis, DOMjudge, PC², CMS e Omogenjudge leem ele. Os requisitos de CCS o exigem como piso: *"The CCS MUST support the ICPC subset"*.

Um problema preparado no Polygon precisava ser convertido à mão.

## Importar, não adotar

O formato interno continua sendo o do BOCA (`input/`, `output/`, `description/`, `compare/`); o importador escreve nele. Trocar o formato interno seria outra issue, muito maior, e não é o que falta: o que falta é **conseguir receber o que o resto do mundo produz**.

## O validador de saída é o caso que importa

Os dois contratos não se parecem:

```
ICPC    validator <entrada> <resposta> <dir_feedback> < saída_da_equipe
        42 = aceito, 43 = errado, outra coisa = validador quebrado

aqui    bash compare/<ext> <entrada> <esperado> <obtido>
        0 = aceito, qualquer outra = errado
```

Três diferenças, e **cada uma sozinha basta para julgar errado**: a saída da equipe vai por stdin e não por argumento; o terceiro argumento é um *diretório* e não um arquivo; e `42` — o código de **aceito** — não é zero, então um validador da ICPC ligado direto **reprovaria toda submissão correta**.

Daí o shim, que é a parte substantiva desta entrega. Ele também distingue **validador quebrado** de **resposta errada** (sai 2, não 1): reprovar a equipe por um defeito nosso é a mesma injustiça que o #45 evita ao não pontuar o `CS` que ele próprio cria.

### `compare/default`

A convenção do BOCA é por **linguagem** (`compare/<ext>`), e faz sentido lá. O `output_validators/` da ICPC pertence ao **problema** — um validador de saída não muda porque a equipe escreveu em Python.

A escolha entre os dois caminhos é feita no `AutoJudgeService`, onde o `file_exists` já estava, e **não** em `Problem::getCompareScriptPath()`: aquele método é um construtor de caminho puro, testável sem disco, e escolher entre caminhos é decisão de quem já está olhando o disco.

## Detalhes que decidem se a importação é correta

**`data/sample/` vs `data/secret/`.** O importador do BOCA chutava *"os dois primeiros casos são amostra"*. O formato da ICPC diz qual é qual — e um caso secreto marcado como amostra é entrada escondida **entregue à equipe**.

**Grupos de teste.** `data/secret/<grupo>/` é como o formato organiza grupos. Pontuação parcial por grupo está fora de escopo (é modelo de IOI), mas os *arquivos* precisam ser lidos: um pacote agrupado importado sem descer nas subpastas viraria um problema com menos casos — e um problema com zero casos **aceita tudo**.

**`name` pode ser string ou mapa de idioma.** O formato permite os dois, e um pacote do Polygon costuma usar o mapa. Preferimos `pt_BR`, depois `pt`, depois `en` — e não "o primeiro", porque a ordem de um mapa YAML não é uma escolha de quem escreveu.

**O bit de execução do validador** sobrevive à cópia. Um `run` sem ele faz o shim sair 2 em todo caso de teste, e o problema inteiro vira erro de julgamento.

**`submissions/accepted/`** é guardado mesmo sem uso imediato: é o pré-requisito do #196, que quer medir o limite de tempo em vez de aceitar um número digitado.

## Versão: detectar, não adivinhar

Existem `legacy`, `legacy-icpc` e `2025-09`. A `2025-09` **renomeia diretórios** (`problem_statement/`→`statement/`, `output_validators/`→`output_validator/`).

Ler um pacote 2025-09 com as regras do legacy **não dá erro**: dá um problema sem enunciado e sem validador, em silêncio. Por isso a versão vem do `problem_format_version` e a desconhecida é **recusada em voz alta**.

Mesma razão para recusar problema interativo: importado como pass-fail, vira um problema que julga errado, e o sintoma aparece na prova.

## Um endpoint, dois formatos

A detecção é pelo **conteúdo** (existe `problem.yaml`?) e não por um campo que quem envia teria que preencher: quem exportou do Polygon não sabe — nem deveria precisar saber — qual dos dois formatos esta plataforma chama de nativo.

Um pacote inválido responde **422 com o motivo legível**, não 500. É para isso que a exceção é própria: um pacote ruim é erro de quem enviou, uma falha de disco é nossa.

## O primeiro achado da suíte foi de teste, não de produto

Um teste passava por causa de arquivo deixado por outro. `RefreshDatabase` zera o banco entre testes e **não zera o disco**, e o `basename` sai do nome do problema — o mesmo em quase todos os casos. O `compare/default` escrito por um teste com validador ficava no lugar para o teste seguinte, que afirma que ele *não* existe.

A limpeza do diretório de problemas no `tearDown` é o conserto, e não zelo.

## A tela (issue #233)

O leitor e o importador acima existiam desde o #200 e **só eram alcançáveis por chamada manual**. A #233 pede a tela, e nomeia o erro a não cometer: *"não apontar o formulário BOCA de importação do banco para um endpoint que importa problemas de competição com outro contrato"*.

São dois destinos diferentes, e é por isso que são dois formulários:

| | `backend.import-boca` | `backend.import-package` |
|---|---|---|
| serviço | `BocaImporterService::importFromZip` | `IcpcPackageReader` + `IcpcPackageImporter` |
| entrada | ZIP de **competição inteira** do BOCA | pacote de **um problema** ICPC/Kattis |
| destino | banco de problemas | uma competição escolhida |
| campos | `boca_zip` | `contest_id` + `package` |

Reapontar um para o outro não daria erro: daria, em silêncio, a coisa errada. Um teste guarda isso — ele lê o HTML do formulário do BOCA e verifica que a ação dele continua sendo a do BOCA.

### Conferir antes de gravar

A issue pede "diagnóstico de pacote". Um diagnóstico que só existe **depois** de importar chega tarde: o problema errado já está na prova. Por isso a caixa *"Apenas conferir o pacote, sem importar"* — ela lê o pacote, mostra versão do formato, limites, quantos casos de amostra e secretos, se há validador de saída, se há enunciado e quantas soluções de referência vieram, e **não escreve nada**, nem no banco nem em disco.

Dois números do diagnóstico têm consequência na prova e aparecem como frase, não como "sim/não" numa lista:

- **validador de saída presente** muda *como* o problema é julgado — o programa do pacote passa a decidir, em vez da comparação padrão;
- **enunciado ausente** significa que as equipes verão o problema sem texto até que alguém escreva um.

### A prova de treino não aparece

Os problemas da prova de treino são instantâneos versionados publicados pelo `PracticePublisher` (#43). Um problema posto ali à mão diverge da biblioteca e não volta sozinho. A prova de treino fica fora do seletor **e** o POST montado à mão é recusado com 404 — as duas portas concordam.

### O diretório de extração não sobrevive à requisição

Nem quando a leitura falha: a limpeza está num `finally`. Um pacote recusado que deixasse o ZIP extraído em `storage/` encheria o disco da máquina que serve a prova, e o sintoma apareceria longe da causa.

## Fora de escopo

Grupos com pontuação parcial (modelo de IOI), problemas interativos, multi-pass, e a versão 2025-09.
