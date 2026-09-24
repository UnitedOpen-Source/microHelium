<?php

namespace App\Services\Curriculum;

use RuntimeException;

/**
 * Issue #396 -- o arquivo foi recusado antes de qualquer escrita. Carrega
 * todos os erros encontrados, não só o primeiro: quem prepara um CSV de 141
 * linhas quer a lista inteira de uma vez.
 */
class CurriculumImportException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode("\n", $errors));
    }
}
