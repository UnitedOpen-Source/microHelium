# Revisão das interfaces das issues

## Resultado

Nove páginas de apresentação (hub administrativo, cinco ferramentas e três telas de treino), seis componentes de domínio carregados por página e dez specs para o backend. Rotas e navegação preservam papéis; modelos, jobs e endpoints de domínio são responsabilidade do backend. A disponibilidade em produção depende dos contratos em [docs/specs](specs/README.md).

## Decisões de UI/UX

- **Visibilidade do estado:** carregamento, operação enviada, processamento assíncrono, vazio, restrição de acesso e falha têm mensagens distintas. Saúde do julgamento separa estado de recuperação de veredito.
- **Prevenção e recuperação de erros:** código preservado, labels e erros associados, foco no campo após422, chave idempotente mantida após erro ambíguo, confirmação de revogação/publicação e versão otimista no banco.
- **Controle do usuário:** cancelamento de confirmação, filtros explícitos, limpar busca, links de retorno e paginação. Atualização manual evita reordenar pares/envios enquanto a pessoa os lê.
- **Reconhecimento e consistência:** mesmos tokens e componentes de ação do design já integrado; equipe/problema/linguagem em linguagem do produto; propriedade e etiquetas separadas; similaridade nunca rotulada como culpa.
- **Privacidade:** capacidades retornadas pelo servidor; nascimento ausente nas listas; segredo de transmissão/link de ativação exibidos apenas após criação e removíveis; nenhum armazenamento persistente no navegador.
- **Acessibilidade:** labels nativos, status/alert, links descritivos, alvos de 44 px, sem dependência exclusiva de cor; exemplos e código escapados; editor redimensionável; layout de uma coluna no celular.
- **Desempenho:** imports dinâmicos por funcionalidade. O restante da aplicação não carrega os seis componentes novos antecipadamente.

Referências utilizadas: [Web Interface Guidelines](https://github.com/vercel-labs/web-interface-guidelines/blob/main/command.md) e skill UI/UX Pro Max. Recomendações da base local foram confrontadas com o design existente; a sugestão automática de landing page de avaliações não se aplica a este produto. Foram mantidos a paleta semântica e o sistema tipográfico já validados, em vez de adotar essa sugestão inadequada.

## Validação executada

- 26 testes de frontend: componentes Vue reais em DOM de teste, permissões de ações, respostas HTTP, preservação de fonte, escape de conteúdo, paginação, idempotência, confirmação de publicação/revogação e remoção de segredo. Inclui 68 pares de contraste dos tokens claro/escuro.
- Rotas de apresentação testadas com visitante, participante, juiz, site e admin; nenhuma nova tabela de domínio necessária para renderizar o shell.
- Suíte completa após atualização da base: **604 testes PHP, 3248 assertions**, em Linux (PHP8.3.33), todos aprovados. Primeiro ensaio sem rede teve timeout no build C#; repetição específica com rede e suíte final passaram.
- Build de produção e Pint dos novos arquivos PHP.
- Biblioteca de treino inspecionada no navegador em desktop escuro; biblioteca e enunciado em 375 px sem overflow horizontal. Enunciado/formulário e tema claro também verificados por navegação/DOM.
- Prévia com fixtures identificada por banner, sem mutações das features. **Revisão visual administrativa não concluída:** o Chrome bloqueou automação após login devido à janela de outra extensão. A revisão dessas telas foi feita por código/testes de componentes; não é uma homologação visual completa.

## Limites de integração

Endpoints `/api/frontend/*` ainda são contratos propostos, portanto retornarão indisponível enquanto não implementados. Fixtures e proxy existem somente em `tests/Frontend`, sem rota de demo em produção. A compatibilidade do webcast com Animeitor aguarda fonte/release válida e teste real. Política de publicação aos 18 segue proposta conservadora pendente de decisão. O executor isolado da #49 é dependência de ativação de novos envios.
