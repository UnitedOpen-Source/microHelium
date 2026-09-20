% Issue #347 -- o erro de execucao do GNU Prolog chegando ao juiz como erro.
%
% MEDIDO na imagem do Dockerfile.judge, `solution.pro` com divisao por zero
% e os comandos exatos do catalogo:
%
%   gplc --no-top-level -o solution solution.pro   -> codigo 0
%   ./solution                                     -> codigo 0
%       stdout: system_error(cannot_catch_throw(error(evaluation_error(
%               zero_divisor),(is)/2)))
%       stderr: warning: solution.pro:6: user directive failed
%
% Duas coisas erradas na mesma medicao. O processo sai com 0, entao o juiz
% conclui "rodou" -- e o texto do erro sai na SAIDA PADRAO, que e o arquivo
% comparado, entao a saida da equipe deixa de ser a resposta e vira mensagem
% do runtime. O resultado e `WA` para um programa que nem terminou.
%
% A causa e o que o `:- initialization(main).` significa em GNU Prolog: uma
% DIRETIVA. Uma diretiva que joga uma excecao nao tratada nao derruba o
% binario -- o runtime reclama e segue --, e com `--no-top-level` o que vem
% depois e um encerramento normal.
%
% Este arquivo e a diretiva de topo do juiz. Ele chama `main` atraves de
% `catch/3`, escreve o diagnostico em `user_error` (o canal que o juiz le
% como diagnostico, e nao como resposta) e encerra com `halt/1` -- e o
% codigo de `halt/1` e o que o juiz le como `RE`.
%
% A ORDEM NA LINHA DE COMANDO NAO E DECORACAO, e foi medida: o GNU Prolog
% executa as diretivas `initialization/1` na ordem INVERSA da ordem de
% ligacao dos objetos. Por isso o catalogo compila
%
%     gplc --no-top-level -o {output} {source} {judge_runtime}/gprolog/envolucro.pro
%
% com este arquivo POR ULTIMO: assim `helium_executa` roda PRIMEIRO. Com
% este arquivo em primeiro lugar, medido, o `:- initialization(main).` da
% equipe roda antes, o erro escapa como antes, e depois `main` roda uma
% SEGUNDA vez -- num `a+b` correto isso imprime a resposta e em seguida
% morre lendo uma entrada que ja acabou.
%
% E por isso tambem que nao sobra um `main` duplicado: `halt/1` encerra o
% processo na hora, e a diretiva da equipe nunca chega a ser executada.
% Nada e injetado na fonte da equipe -- o envolucro e um segundo arquivo,
% e o contrato (`main/0` com `:- initialization(main).`) continua o mesmo
% que a #305 fixou.

:- initialization(helium_executa).

helium_executa :-
    catch(main, Erro, helium_encerra_com_erro(Erro)),
    !,
    halt(0).

% `main` sem excecao, mas sem sucesso. Num juiz isso e erro de execucao e
% nao resposta errada: o programa nao chegou a produzir resposta nenhuma.
% E o que o SWI-Prolog (`prolog_swi`) ja faz com `--on-error=status`.
helium_executa :-
    format(user_error, "Erro de execucao: o objetivo main/0 falhou.~n", []),
    halt(1).

helium_encerra_com_erro(Erro) :-
    format(user_error, "Erro de execucao: ", []),
    write_term(user_error, Erro, [quoted(true)]),
    nl(user_error),
    halt(1).
