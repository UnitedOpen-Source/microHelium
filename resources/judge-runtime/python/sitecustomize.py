"""Issue #327 -- sobe o limite de recursao do CPython para toda submissao.

O padrao documentado do CPython e 1000, e uma DFS recursiva sobre um grafo
de 10 mil vertices -- profundidade banal numa prova -- recebia `RE` com
`RecursionError` em Python enquanto passava em vinte outras linguagens. A
escolha de linguagem nao pode mudar o algoritmo que se pode escrever.

`sys.setrecursionlimit` tem de rodar DENTRO do processo do competidor, e sem
editar a fonte dele. O modulo `site` importa `sitecustomize` na partida, e o
`run_command` do catalogo poe este diretorio no `PYTHONPATH` -- entao o
comando continua sendo `python3 {source}`, e o traceback continua apontando
para o arquivo que a equipe submeteu.

O NUMERO e medido, nao chutado. Nesta imagem, `ulimit -s` e 8192 KB e o
sandbox nao mexe nele. A armadilha que a issue avisa -- subir o limite e
trocar um `RecursionError` legivel por um SIGSEGV mudo -- NAO se realiza
aqui, e isso foi conferido e nao suposto: do CPython 3.12 em diante as
chamadas de funcao Python pura nao consomem pilha de C, e o guarda de pilha
de C e separado do `setrecursionlimit`. Medido, com o limite em 2.000.000:

    f(100.000)    rc=0
    f(200.000)    rc=0
    f(400.000)    rc=0
    f(1.000.000)  rc=0

e a recursao INFINITA, com o limite em 100.000 e em 1.000.000, morre nas
duas com `RecursionError: maximum recursion depth exceeded` -- rc=1, stderr
legivel, nenhum segfault.

200.000 e o valor escolhido: cobre com folga os 10^5 vertices que e o
tamanho usual de um problema de grafos.

O `tracebacklimit` NAO e enfeite, e nao e separavel do numero acima.

Subir o limite de recursao encarece o FRACASSO, e nao o sucesso: uma
recursao legitima de 10^5 niveis custa 0,00 s de CPU, mas uma que nao
termina tem de formatar um traceback com um quadro por nivel. Medido aqui,
recursao infinita, custo de CPU ate o `RecursionError`:

    limite  10.000     0,24 s
    limite  20.000     0,46 s
    limite  50.000     1,16 s
    limite 100.000     2,25 s
    limite 200.000     4,80 s

O limite padrao de um problema e 1 SEGUNDO de CPU. Sem mais nada, subir o
limite de recursao para 200.000 trocaria o `RE` com `RecursionError` por um
`TLE` -- de novo um veredito que nao diz a verdade sobre o que aconteceu,
que e justamente o que a #327 pede para nao fazer. Foi medido e nao
suposto: a primeira versao deste arquivo fez exatamente isso, e o teste de
recursao infinita pegou.

`sys.tracebacklimit` conserta as duas pontas de uma vez, porque o custo
estava na IMPRESSAO do traceback e nao na recursao. Com ele em 20, mesma
recursao infinita:

    limite  50.000     0,11 s
    limite 100.000     0,16 s
    limite 200.000     0,34 s

e o `RecursionError` continua na ultima linha. De quebra, a equipe le 14
linhas em vez de milhares de quadros repetidos.

O efeito colateral e que TODA excecao passa a mostrar no maximo 20 quadros.
Numa submissao de maratona a pilha inteira raramente chega perto disso, e o
que importa -- a linha do erro e a mensagem -- e o que o traceback imprime
por ultimo.
"""

import sys

sys.setrecursionlimit(200000)
sys.tracebacklimit = 20
