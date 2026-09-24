<?php

namespace App\Support;

/**
 * Issue #390 (P2) -- "este fonte e texto?", respondido num lugar so.
 *
 * A regra nasceu na #268, dentro de `SubmissionController`, para decidir se
 * o fonte de um envio e renderizado ou baixado: um `.sb3` e um ZIP, e
 * despeja-lo num `<pre>` enchia a pagina de U+FFFD. A #390 trouxe a mesma
 * pergunta para a validacao do Treino Livre, e duas copias da regra
 * divergiriam na primeira mudanca.
 *
 * UTF-8 valido e sem byte nulo. O byte nulo entra na conta porque ha binario
 * que passa por UTF-8 valido por acaso -- um ZIP sem compressao com conteudo
 * ASCII, por exemplo, cujo cabecalho `PK\x03\x04\x14\x00` so e denunciado
 * pelo `\0` -- e nenhum fonte de programa legitimo tem um.
 */
final class SourceText
{
    public static function isText(string $content): bool
    {
        return $content === ''
            || (! str_contains($content, "\0") && mb_check_encoding($content, 'UTF-8'));
    }
}
