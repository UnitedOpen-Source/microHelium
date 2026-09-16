<?php

namespace App\Services;

/**
 * Issue #242 -- um caminho que sai do proprio diretorio.
 *
 * Excecao propria pela mesma razao que a IcpcPackageException: um arquivo
 * ruim e erro de quem enviou e volta como frase legivel, enquanto uma falha
 * de disco e nossa e vira 500.
 */
class ZipPathException extends \RuntimeException {}
