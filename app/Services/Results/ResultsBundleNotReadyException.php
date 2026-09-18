<?php

namespace App\Services\Results;

use RuntimeException;

/**
 * Issue #271 -- a prova ainda não pode virar pacote.
 *
 * Tipo próprio, e não `RuntimeException` solta, porque quem chama precisa
 * distinguir "esta prova não está pronta" -- que é resposta legível para o
 * organizador -- de "a exportação quebrou", que é erro de servidor.
 */
class ResultsBundleNotReadyException extends RuntimeException {}
