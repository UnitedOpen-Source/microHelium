<?php

namespace App\Services;

use ZipArchive;

/**
 * Issue #242 -- extrair ZIP enviado por alguem sem deixar um caminho
 * reescrito em silencio.
 *
 * Medi o que o `extractTo()` do PHP faz antes de escrever isto. Ele NAO
 * deixa escapar do diretorio de destino: `../a.txt`, `x/../../c.txt` e
 * `/tmp/abs.txt` caem todos dentro do destino. Nao e fuga de diretorio.
 *
 * O que sobra e pior de encontrar. Ele resolve a travessia REESCREVENDO o
 * caminho, sem erro e sem aviso: `x/../../data/secret/01.ans` vira
 * `data/secret/01.ans` e passa por cima de um arquivo legitimo do proprio
 * pacote. Num pacote de problema isso troca um caso de teste -- o pacote e
 * aceito, o problema e importado, e o sintoma aparece longe da causa: uma
 * submissao correta reprovada DURANTE A PROVA.
 *
 * Entao a recusa e nossa, e nao da biblioteca. Nao depender da limpeza do
 * libzip tambem e o que mantem a garantia verdadeira se a versao da imagem
 * mudar.
 */
class SafeZipExtractor
{
    /**
     * O primeiro nome que nao pode ser extraido como esta, ou null.
     *
     * Absoluto ou com um segmento `..`. Dois pontos NO MEIO de um nome
     * (`a..b.pdf`) sao legitimos e passam -- uma guarda que os recusasse
     * rejeitaria pacotes bons sem dizer por que.
     */
    public function escapingEntry(ZipArchive $zip): ?string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (str_starts_with($name, '/') || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Extrai, ou recusa o arquivo inteiro sem escrever nada.
     *
     * A verificacao vem antes de qualquer escrita de proposito: um pacote
     * recusado nao pode deixar meia extracao no disco da maquina que serve
     * a prova.
     *
     * @throws ZipPathException
     */
    public function extractTo(ZipArchive $zip, string $directory): void
    {
        if (($offending = $this->escapingEntry($zip)) !== null) {
            throw new ZipPathException("O arquivo tem um caminho que sai do próprio diretório: {$offending}");
        }

        if (! is_dir($directory)) {
            @mkdir($directory, 0o755, true);
        }

        $zip->extractTo($directory);
    }
}
