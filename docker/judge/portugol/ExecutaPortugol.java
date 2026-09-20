import br.univali.portugol.Caminhos;
import br.univali.portugol.nucleo.ErroCompilacao;
import br.univali.portugol.nucleo.Portugol;
import br.univali.portugol.nucleo.asa.TipoDado;
import br.univali.portugol.nucleo.execucao.ModoEncerramento;
import br.univali.portugol.nucleo.execucao.ObservadorExecucao;
import br.univali.portugol.nucleo.execucao.ResultadoExecucao;
import br.univali.portugol.nucleo.execucao.es.Armazenador;
import br.univali.portugol.nucleo.execucao.es.Entrada;
import br.univali.portugol.nucleo.execucao.es.Saida;
import br.univali.portugol.nucleo.mensagens.ErroAnalise;
import br.univali.portugol.nucleo.mensagens.ErroExecucao;
import br.univali.portugol.nucleo.programa.Estado;
import br.univali.portugol.nucleo.programa.Programa;
import java.io.File;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Paths;
import java.util.NoSuchElementException;
import java.util.Scanner;
import java.util.concurrent.CountDownLatch;
import java.util.logging.LogManager;

/**
 * Issue #301 -- a etapa de EXECUCAO do Portugol Studio.
 *
 * O `Console` do Portugol Studio nao serve como executor de juiz, e isso foi
 * medido:
 *
 *   sem -no-wait, divisao por zero  -> imprime o erro, codigo 1 -- mas
 *                                      antes disso escreve "Programa
 *                                      finalizado" e "Pressione ENTER para
 *                                      continuar" NA SAIDA COMPARADA, e
 *                                      fica preso lendo stdin quando ele
 *                                      acaba
 *   com -no-wait, divisao por zero  -> codigo 0, stderr VAZIO (o defeito)
 *
 * O `-no-wait` e obrigatorio -- sem ele a saida comparada recebe texto do
 * console e um laco trava a fila --, e e ele que apaga o erro: em
 * `Console.execucaoEncerrada()` a impressao da mensagem e o `System.exit`
 * do codigo de erro moram os dois DENTRO do `if (aguardarParaSair)`, e logo
 * depois vem um `System.exit(NORMAL.ordinal())` incondicional. Como o juiz
 * decide `RE` pelo codigo de saida, erro de execucao virava `WA` -- dizendo
 * a equipe que a resposta esta errada quando o programa nem terminou.
 *
 * Isso esta reportado a montante em UNIVALI-LITE/Portugol-Studio#1218, mas
 * o `Console.java` nao e tocado desde 2019: esperar nao e plano. O caminho
 * que nao depende de ninguem e o mesmo que o `VerificaPortugol` ja usa para
 * a compilacao -- chamar a API do nucleo direto. Aqui:
 * `Portugol.compilarParaExecucao()` devolve um `Programa`, o
 * `ObservadorExecucao` recebe o `ResultadoExecucao`, e o codigo de saida sai
 * do `ModoEncerramento` em vez de sair de um `System.exit` fixo.
 *
 * O contrato com o juiz, e o que cada peca existe para garantir:
 *
 *   - saida do programa (`escreva`)  -> stdout, e SO ela;
 *   - diagnostico de erro            -> stderr, com linha e coluna;
 *   - erro de execucao               -> codigo 1, que o juiz le como `RE`;
 *   - execucao normal                -> codigo 0.
 */
public final class ExecutaPortugol implements Entrada, Saida, ObservadorExecucao {

    /** Solta o `main` quando a execucao termina -- ver `executar()`. */
    private final CountDownLatch encerrada = new CountDownLatch(1);

    private volatile int codigoSaida = 0;

    private Scanner scannerEntrada = null;

    public static void main(String[] args) throws Exception {
        inicializarMecanismoLog();

        if (args.length < 1) {
            System.err.println("uso: ExecutaPortugol <arquivo.por>");
            System.exit(2);
        }

        System.exit(new ExecutaPortugol().executar(new File(args[0])));
    }

    /**
     * @return o codigo de saida do processo.
     */
    private int executar(File arquivo) throws Exception {
        String codigo;

        try {
            // UTF-8 explicito, pela mesma razao do VerificaPortugol: as
            // palavras-chave do Portugol sao acentuadas e a codificacao
            // padrao da JVM depende do ambiente.
            codigo = new String(Files.readAllBytes(Paths.get(arquivo.getPath())), StandardCharsets.UTF_8);
        } catch (Exception erro) {
            System.err.println("Nao foi possivel ler o programa: " + erro);

            return 1;
        }

        Programa programa;

        try {
            // O mesmo par de argumentos que o Console monta: o classpath
            // desta JVM (o invocador poe todos os jars do Portugol nele) e o
            // `javac` que o nucleo chama para compilar o Java que ele gera.
            programa = Portugol.compilarParaExecucao(
                codigo,
                System.getProperty("java.class.path") + File.pathSeparator,
                Caminhos.obterCaminhoExecutavelJavac()
            );
        } catch (ErroCompilacao erroCompilacao) {
            // Nao deveria acontecer -- o `compile_command` do catalogo ja
            // rodou o `portugol-studio-check` e o envio so chega aqui se
            // passou. Se acontecer, e erro do envio e vai para stderr, nunca
            // para a saida comparada.
            for (ErroAnalise erro : erroCompilacao.getResultadoAnalise().getErros()) {
                System.err.println("ERRO: " + erro.getMensagem()
                    + ". Linha: " + erro.getLinha()
                    + ", Coluna: " + erro.getColuna());
            }

            return 1;
        }

        if (programa == null) {
            System.err.println("Nao foi possivel preparar o programa para execucao");

            return 1;
        }

        programa.setEntrada(this);
        programa.setSaida(this);
        programa.adicionarObservadorExecucao(this);
        programa.setDiretorioTrabalho(arquivo.getAbsoluteFile().getParentFile());

        // `executar()` NAO bloqueia: ele submete o programa a um pool de
        // threads e volta na hora. Quem sabe como a execucao terminou e o
        // observador -- dai o latch. O Console resolvia isso chamando
        // `System.exit` de dentro do callback, e foi exatamente esse exit
        // fixo que apagou o codigo de erro.
        programa.executar(new String[0], Estado.BREAK_POINT);

        encerrada.await();

        System.out.flush();
        System.err.flush();

        return codigoSaida;
    }

    @Override
    public void execucaoEncerrada(Programa programa, ResultadoExecucao resultadoExecucao) {
        if (resultadoExecucao.getModoEncerramento() == ModoEncerramento.ERRO) {
            ErroExecucao erro = resultadoExecucao.getErro();

            // Mesmo formato do Console, para quem ja conhece a ferramenta
            // reconhecer -- e com linha e coluna, porque "erro de execucao"
            // sem lugar nao diz a um iniciante onde consertar.
            System.err.println("Erro de execucao: "
                + (erro != null ? erro.getMensagem() : "causa desconhecida")
                + (erro != null ? "\nLinha: " + erro.getLinha() + ", Coluna: " + erro.getColuna() : ""));
            System.err.flush();

            codigoSaida = 1;
        }

        // INTERRUPCAO nao e caso do juiz (ninguem aperta "parar" aqui) e
        // NORMAL e o caminho feliz: os dois saem 0.
        encerrada.countDown();
    }

    // -----------------------------------------------------------------
    // Entrada -- identica a do Console, menos o tratamento de fim de
    // arquivo.
    // -----------------------------------------------------------------

    @Override
    public void solicitaEntrada(TipoDado tipoDado, Armazenador armazenador) {
        try {
            String dado = getScannerEntrada().nextLine();

            switch (tipoDado) {
                case CADEIA:
                    armazenador.setValor(dado);

                    return;
                case CARACTER:
                    armazenador.setValor(dado.charAt(0));

                    return;
                case INTEIRO:
                    armazenador.setValor(Integer.parseInt(dado));

                    return;
                case REAL:
                    armazenador.setValor(Double.parseDouble(dado));

                    return;
                case LOGICO:
                    // `Object` e nao ternario direto: `cond ? true : null`
                    // desembrulha o Boolean e estoura NullPointerException
                    // quando a linha nao e nem "verdadeiro" nem "falso" --
                    // que e justamente o caso que se quer tratar.
                    Object logico = dado.equals("verdadeiro") ? Boolean.TRUE : (dado.equals("falso") ? Boolean.FALSE : null);

                    if (logico == null) {
                        break;
                    }

                    armazenador.setValor(logico);

                    return;
                default:
                    break;
            }
        } catch (NoSuchElementException | NumberFormatException | IndexOutOfBoundsException excecao) {
            // `NoSuchElementException` cobre tambem o fim do arquivo de
            // entrada, e e o unico ponto em que este executor se afasta do
            // Console de proposito: cancelar a leitura faz o nucleo jogar
            // `ErroValorEntradaInvalido`, que traz linha e coluna do `leia`.
            // Deixar a excecao subir crua daria um "erro nao tratado" com
            // nome de classe Java no lugar da mensagem em portugues.
        }

        armazenador.cancelarLeitura();
    }

    private Scanner getScannerEntrada() {
        if (scannerEntrada == null) {
            scannerEntrada = new Scanner(System.in);
        }

        return scannerEntrada;
    }

    // -----------------------------------------------------------------
    // Saida -- tudo em stdout, que e o arquivo que o juiz compara.
    // -----------------------------------------------------------------

    @Override
    public void escrever(String valor) {
        System.out.print(valor);
        System.out.flush();
    }

    @Override
    public void escrever(boolean valor) {
        System.out.print(valor ? "verdadeiro" : "falso");
        System.out.flush();
    }

    @Override
    public void escrever(int valor) {
        System.out.print(Integer.toString(valor));
        System.out.flush();
    }

    @Override
    public void escrever(double valor) {
        System.out.print(Double.toString(valor));
        System.out.flush();
    }

    @Override
    public void escrever(char valor) {
        System.out.print(Character.toString(valor));
        System.out.flush();
    }

    @Override
    public void limpar() {
        // O Console imprime "\033c" no Linux. Aqui nao: a saida comparada
        // nao pode receber sequencia de escape de terminal.
    }

    // -----------------------------------------------------------------
    // O resto do ObservadorExecucao -- eventos de depurador, sem uso aqui.
    // -----------------------------------------------------------------

    @Override
    public void execucaoIniciada(Programa programa) {
    }

    @Override
    public void execucaoPausada() {
    }

    @Override
    public void execucaoResumida() {
    }

    @Override
    public void escopoModificado(String escopo) {
    }

    @Override
    public void highlightLinha(int linha) {
    }

    @Override
    public void highlightDetalhadoAtual(int linha, int coluna, int tamanho) {
    }

    /**
     * O mesmo `logging.properties` que o Console carrega, e pela mesma
     * razao: sem ele o `java.util.logging` do nucleo despeja avisos no
     * stderr, que aqui e o canal de diagnostico da equipe.
     */
    private static void inicializarMecanismoLog() {
        try (InputStream configuracao = ExecutaPortugol.class.getResourceAsStream("/logging.properties")) {
            if (configuracao != null) {
                LogManager.getLogManager().readConfiguration(configuracao);
            }
        } catch (Exception ignorada) {
            // Log mal configurado nao e motivo para recusar um envio.
        }
    }
}
