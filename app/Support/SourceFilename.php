<?php

namespace App\Support;

/**
 * Issue #311 -- o nome do arquivo submetido, saneado UMA vez, para os dois
 * caminhos de submissao.
 *
 * Esta regra ja existia, mas so no `SubmitController` (o formulario web). O
 * `Api\RunController::store()` gravava `getClientOriginalName()` cru, e a
 * validacao da API (`required|file|max:N`) nao diz nada sobre o nome. Medido
 * na issue: nomes com `;` e com `$(...)` voltavam 201 e gravados identicos ao
 * enviado.
 *
 * Por que isso importa mesmo com sandbox: o valor gravado em `runs.filename`
 * e substituido SEM ESCAPE em `{source}`/`{output}`/`{executable}`/
 * `{classname}` na linha de compilacao e de execucao, que o
 * `AutoJudgeService` entrega a `bash -c`. O `bwrap` continua contendo o
 * estrago (sem rede, sem `/var/www/html` montado), entao isto nao e escalada
 * de privilegio -- e defesa em profundidade, e o modelo de integridade do
 * juiz: uma submissao marcada como "C" tem de ser compilada PELO comando da
 * linguagem C, e nao pelo que o nome do arquivo mandar.
 *
 * E, acima de tudo, a assimetria: a mesma equipe estava protegida pela
 * interface web e desprotegida pelo cliente de linha de comando. Ter a regra
 * num lugar so e o que impede a proxima entrada de nascer torta.
 *
 * O que a regra NAO faz, de proposito: travessia de caminho ja nao passa --
 * o `getClientOriginalName()` do Symfony aplica `basename()` antes de
 * chegar aqui, e a sonda da issue confirmou. O `basename()` abaixo e cinto
 * e suspensorio para quem chamar isto com um caminho de outra origem.
 *
 * O que ela preserva, tambem de proposito: `[A-Za-z0-9._-]` sobrevive
 * intacto. Isso nao e conveniencia, e requisito -- o Java exige que o nome
 * do arquivo case com o da classe publica, e o catalogo deriva
 * `{classname}` deste mesmo valor. Uma sanitizacao mais agressiva (ou um
 * `escapeshellarg` aplicado onde o valor e IDENTIFICADOR e nao caminho)
 * quebraria `Main.java`.
 */
final class SourceFilename
{
    /**
     * O corte em 150 e o mesmo numero que o caminho web ja usava antes desta
     * classe existir; ele esta aqui para o limite nao divergir de novo.
     */
    public const MAX_LENGTH = 150;

    public static function sanitize(string $name): string
    {
        $name = basename($name);
        $name = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $name = ltrim($name, '.');

        return $name !== '' ? substr($name, 0, self::MAX_LENGTH) : 'source';
    }
}
