<?php

namespace Tests\Unit;

use App\Services\SafeZipExtractor;
use App\Services\ZipPathException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use ZipArchive;

/**
 * Issue #242 -- extrair ZIP enviado por alguém sem deixar um caminho ser
 * reescrito em silêncio.
 */
class SafeZipExtractorTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = storage_path('app/temp/safezip-'.uniqid());
        @mkdir($this->workspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*') ?: [] as $path) {
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * @param  array<int, string>  $entries
     */
    private function zip(array $entries): ZipArchive
    {
        $path = $this->workspace.'/'.uniqid('z').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach ($entries as $name) {
            $zip->addFromString($name, 'conteudo');
        }

        $zip->close();

        $aberto = new ZipArchive;
        $aberto->open($path);

        return $aberto;
    }

    // -- o que se recusa -----------------------------------------------------

    #[TestWith(['../a.txt'])]
    #[TestWith(['../../b.txt'])]
    #[TestWith(['x/../../c.txt'])]
    #[TestWith(['/tmp/abs-d.txt'])]
    #[TestWith(['./../f.txt'])]
    #[TestWith(['x/./../../g.txt'])]
    #[TestWith(['a/..'])]
    public function test_an_entry_that_does_not_stay_put_is_named(string $entrada): void
    {
        $this->assertSame($entrada, (new SafeZipExtractor)->escapingEntry($this->zip([$entrada])));
    }

    /**
     * Controle positivo, e o mais importante desta classe: dois pontos NO
     * MEIO de um nome são legítimos. Uma guarda que os recusasse rejeitaria
     * pacotes bons, e a recusa não diria por quê.
     */
    #[TestWith(['problem_statement/a..b.pdf'])]
    #[TestWith(['data/secret/01.ans'])]
    #[TestWith(['submissions/accepted/solucao.cpp'])]
    #[TestWith(['..oculto/x.txt'])]
    #[TestWith(['x/y..z/w.txt'])]
    public function test_a_legitimate_name_is_not_mistaken_for_traversal(string $entrada): void
    {
        $this->assertNull((new SafeZipExtractor)->escapingEntry($this->zip([$entrada])));
    }

    // -- a extração ----------------------------------------------------------

    public function test_refusing_writes_nothing(): void
    {
        $destino = $this->workspace.'/destino';

        try {
            (new SafeZipExtractor)->extractTo($this->zip(['ok.txt', 'x/../../fora.txt']), $destino);
            $this->fail('um caminho que sai do diretório tinha que ser recusado');
        } catch (ZipPathException $e) {
            $this->assertStringContainsString('x/../../fora.txt', $e->getMessage());
        }

        // Nem o `ok.txt`, que é legítimo: o arquivo é recusado inteiro, e a
        // verificação vem antes de qualquer escrita de propósito -- meia
        // extração no disco da máquina que serve a prova é pior que nenhuma.
        $this->assertFalse(is_dir($destino), 'nada pode ter sido escrito');
    }

    public function test_a_clean_archive_extracts(): void
    {
        $destino = $this->workspace.'/limpo';

        (new SafeZipExtractor)->extractTo($this->zip(['problem.yaml', 'data/secret/01.ans']), $destino);

        $this->assertFileExists($destino.'/problem.yaml');
        $this->assertFileExists($destino.'/data/secret/01.ans');
    }

    /**
     * A medição que motivou a guarda, guardada como teste para que a razão
     * não vire folclore.
     *
     * O `extractTo()` do PHP NÃO deixa escapar do destino -- o que ele faz é
     * reescrever o caminho em silêncio. É por isso que um pacote com
     * `x/../../data/secret/01.ans` passaria por cima de um caso de teste
     * legítimo do próprio pacote sem erro e sem aviso, e é isso que a guarda
     * impede.
     *
     * Se um dia esta asserção falhar, a biblioteca mudou de comportamento --
     * e a guarda continua correta de qualquer forma, porque não depende dela.
     */
    public function test_php_flattens_instead_of_escaping_which_is_why_the_guard_exists(): void
    {
        $destino = $this->workspace.'/medicao';
        @mkdir($destino, 0o755, true);

        $zip = $this->zip(['x/../../data/secret/01.ans']);
        $zip->extractTo($destino);
        $zip->close();

        $this->assertFileExists(
            $destino.'/data/secret/01.ans',
            'o PHP achatou o caminho para dentro do destino, sobrescrevendo um caminho legítimo'
        );
        $this->assertFileDoesNotExist(
            dirname($destino).'/data/secret/01.ans',
            'e não escapou -- a guarda não é sobre fuga de diretório'
        );
    }
}
