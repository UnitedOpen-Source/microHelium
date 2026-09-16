<?php

namespace App\Services\Icpc;

/**
 * Issue #200 -- um pacote que nao da para importar, com o motivo legivel.
 *
 * Excecao propria e nao \Exception generica porque a diferenca importa para
 * quem chama: um pacote invalido e erro de quem enviou e vira 422, enquanto
 * uma falha de disco e nossa e vira 500. Com uma excecao so, o controller
 * teria que ler a mensagem para decidir.
 */
class IcpcPackageException extends \RuntimeException {}
