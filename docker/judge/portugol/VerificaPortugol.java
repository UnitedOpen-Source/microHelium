import br.univali.portugol.nucleo.ErroCompilacao;
import br.univali.portugol.nucleo.Portugol;
import br.univali.portugol.nucleo.mensagens.ErroAnalise;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Paths;

/**
 * Issue #269 -- a etapa de compilacao do Portugol Studio.
 *
 * O `Console` do Portugol Studio NAO serve como compilador, e isso foi
 * medido de tres formas:
 *
 *   com -no-wait, programa invalido      -> codigo 0   (o defeito da issue)
 *   sem -no-wait, programa invalido      -> codigo 1   (certo)
 *   sem -no-wait, programa VALIDO que le -> codigo 1   (falso CE: stdin
 *                                                       fechado vira
 *                                                       NoSuchElementException)
 *   sem -no-wait, laco infinito          -> TRAVA      (o console executa)
 *
 * Ou seja: nao ha combinacao de flags que analise sem executar. Com
 * -no-wait o erro de sintaxe passaria da compilacao, despejaria as
 * mensagens de erro e viraria WA -- dizendo a equipe que a resposta esta
 * errada quando o programa nem compilou. Sem -no-wait, todo programa que le
 * entrada vira CE, e um laco trava a fila de julgamento.
 *
 * `Portugol.compilarParaAnalise()` e a fachada do nucleo que faz exatamente
 * o que a etapa de compilacao precisa: analisa e NAO executa. Sem stdin, sem
 * laco, e com o codigo de saida que o AutoJudgeService le para decidir CE.
 *
 * O modulo `checker` do Portugol Studio nao serve para isto: e um corretor
 * automatico (Corretor, Comparador, CasoFalho), e nao um validador de
 * sintaxe.
 */
public final class VerificaPortugol {

    public static void main(String[] args) {
        if (args.length < 1) {
            System.err.println("uso: VerificaPortugol <arquivo.por>");
            System.exit(2);
        }

        try {
            // UTF-8 explicito: as palavras-chave do Portugol sao acentuadas,
            // e a codificacao padrao da JVM depende do ambiente -- deixar
            // implicito faria o mesmo arquivo compilar numa maquina e falhar
            // noutra.
            String codigo = new String(Files.readAllBytes(Paths.get(args[0])), StandardCharsets.UTF_8);

            Portugol.compilarParaAnalise(codigo);
        } catch (ErroCompilacao erro) {
            // Em stderr, e nao em stdout: desde a #268 o juiz separa os dois,
            // e a saida comparada nao pode receber mensagem de compilador.
            //
            // Linha e coluna de CADA erro, e nao so "o codigo contem erros":
            // o `getMessage()` do ErroCompilacao e generico, e uma equipe
            // que recebe CE precisa saber ONDE consertar. Mesmo formato que
            // o Console usa, para quem ja conhece a ferramenta reconhecer.
            for (ErroAnalise erroAnalise : erro.getResultadoAnalise().getErros()) {
                System.err.println("ERRO: " + erroAnalise.getMensagem()
                    + ". Linha: " + erroAnalise.getLinha()
                    + ", Coluna: " + erroAnalise.getColuna());
            }

            System.exit(1);
        } catch (Exception erro) {
            // Arquivo ilegivel, codificacao impossivel, o que for. Tratado
            // como erro de compilacao de proposito: o envio nao virou
            // programa, e chamar isso de "erro interno" jogaria em quem
            // opera um problema que e do envio.
            System.err.println("Nao foi possivel analisar o programa: " + erro);
            System.exit(1);
        }

        System.exit(0);
    }
}
