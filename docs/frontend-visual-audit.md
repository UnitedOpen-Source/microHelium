# Análise visual e funcional do frontend

## Resultado

A terceira revisão do PR #35 consolida o design das páginas ativas. Os principais problemas eram cores sem significado consistente, contraste insuficiente nas bordas dos campos, hierarquia tipográfica irregular e controles que sugeriam funcionalidades inexistentes. A implementação corrige esses pontos na worktree `feat/frontend-design-refresh`, mantendo os contratos do backend.

## Paleta e contraste

O verde-petróleo identifica ações principais e navegação. Verde indica sucesso; âmbar, atenção e limites; vermelho, erros e ações destrutivas; azul, informação. Identidades de linguagens e perfis usam tons neutros/informativos. Cada papel tem fundo suave, texto, preenchimento e hover próprios em ambos os temas.

As medições usam a fórmula de luminância relativa das WCAG e as cores declaradas no CSS. O critério adotado é pelo menos **4,5:1 para textos comuns** e **3:1 para bordas de campos e indicadores de foco**. Referências: [contraste de texto](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html) e [contraste não textual](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html).

| Combinação | Tema claro | Tema escuro |
| --- | ---: | ---: |
| Texto principal / cartão | 14,65:1 | 13,55:1 |
| Texto secundário / cartão | 5,47:1 | 7,55:1 |
| Texto / ação principal | 5,47:1 | 8,67:1 |
| Sucesso / fundo suave | 5,16:1 | 7,26:1 |
| Atenção / fundo suave | 4,86:1 | 7,80:1 |
| Erro / fundo suave | 5,04:1 | 6,37:1 |
| Informação / fundo suave | 5,04:1 | 7,28:1 |
| Borda de campo / fundo da página | 3,04:1 | 4,91:1 |
| Foco / fundo da página | 5,04:1 | 10,39:1 |

Antes, a borda dos campos tinha apenas **1,41:1** no tema claro e **2,66:1** no escuro. Divisórias decorativas continuam sutis; os controles recebem bordas mais fortes. Os testes leem os tokens reais do CSS e verificam 68 combinações entre texto, fundos, hover, campos e foco. Os valores da tabela são arredondados apenas para apresentação; as verificações usam os números completos.

Cores de problemas fornecidas pelo organizador agora aparecem como pequenos marcadores decorativos. Letras e números mantêm a cor de texto do tema. Isso evita identificadores amarelos ou muito escuros ilegíveis, preservando a identificação original da competição.

## Achados e correções

Localizações referem-se aos arquivos após a correção.

- `resources/css/app.css:13` — cores avulsas substituídas por papéis semânticos, com hover e fundos suaves nos dois temas.
- `resources/css/app.css:30` — bordas de campos sem contraste suficiente corrigidas; utilitários antigos não anulam a borda de interação.
- `resources/css/app.css:242` — leitura de tabelas, alinhamento dos botões, tamanho mínimo de controles, campos mobile e estados de foco unificados.
- `resources/css/app.css:276` — ações de tabela não quebram palavras nem encolhem abaixo do texto; tabelas mantêm rolagem dentro da própria região.
- `resources/views/partials/verdict.blade.php:1` — apresentação única de vereditos, com texto e símbolo. `RE` e `RTE` são reconhecidos; fila é diferenciada de avaliação em andamento. Erro de compilação deixa de usar a cor da ação principal.
- `resources/views/exercises/index.blade.php:47` — cor personalizada separada da letra do problema. Prévia do enunciado preserva expressões matemáticas com `<`, antes truncadas por `strip_tags`.
- `resources/views/backend/configurations.blade.php:95` — removidos campos sem salvamento e cronômetro estático com botões inoperantes. A página apresenta referências reais, relógio da competição e ações existentes.
- `resources/views/backend/submissions.blade.php:54` — “Ver código” passa a navegar para o detalhe real. Filtros duplicados sem efeito e botão de rejulgamento sem ação foram removidos.
- `resources/views/backend/clarifications.blade.php:19` — filtros de situação passam a funcionar, indicam seleção, mostram contagem e persistem na URL. Respostas têm labels próprias; respostas sugeridas recebem menor destaque que Enviar.
- `resources/views/backend/problem-bank.blade.php:7` — um único título principal e ação de importação consistente. Busca/dificuldade persistem na URL; detalhes adaptam as colunas e tornam exemplos alcançáveis por teclado.
- `resources/views/backend/import-boca.blade.php:43` — seleção de ZIP acessível por teclado, foco no grupo, arquivo obrigatório e nome selecionado anunciado. Mantido o arrastar e soltar existente.
- `resources/views/backend/contest-edit.blade.php:26` — edição preserva entradas e seleções após erro; título duplicado removido e retorno identificado por texto.
- `resources/views/auth/passwords/email.blade.php:7` — a recuperação por e-mail usava um endpoint que somente devolvia uma mensagem de sucesso. A interface agora orienta solicitar recuperação à organização, sem prometer o envio inexistente.
- `resources/views/partials/theme-init.blade.php:1` — cor do navegador acompanha o tema; controles nativos recebem o esquema adequado.

## Cobertura das páginas

| Área | O que foi revisado nesta rodada |
| --- | --- |
| Início | Hierarquia, contraste, cartões de métricas, título de seção e vereditos compartilhados |
| Problemas | Identificação legível, ações com hierarquia e prévia do enunciado |
| Enunciado | Medida de leitura, contraste do texto longo, exemplos navegáveis por teclado |
| Envio | Ação principal, foco no upload, nome longo de arquivo, editor redimensionável, instruções associadas |
| Placar | Cores semânticas, legenda com os mesmos símbolos das células, classificação fornecida pelo backend |
| Submissões e detalhe | Fila/vereditos, metadados neutros, código e regiões roláveis |
| Clarificações | Respostas, conteúdo longo, hierarquia e formulários |
| Ajuda e primeiros passos | Consistência com a paleta e navegação compartilhada |
| Login e cadastro | Cor da ação, contraste de campos, tema e tipografia |
| Recuperação de acesso | Comunicação fiel à funcionalidade disponível |
| Usuários, times e problemas administrativos | Cores de status, identidade, exclusão, títulos e ações; seleção explícita da competição e adição de problemas ao evento existente |
| Perfil | Link no menu da conta, dados preservados, autocomplete, instruções de senha associadas aos campos e feedback compartilhado |
| Configurações, criação e edição | Controles reais, estados das etapas, preservação de entradas e contraste |
| Banco de problemas e importação | Busca, seleção, modal, arquivo, hierarquia e estados de atenção |
| Julgamento e tarefas | Resultados, contraste e identificação sem depender de cores personalizadas |
| Páginas de erro | Herança da paleta, ações de recuperação e tema |
| Componentes Vue opcionais | Classes de superfícies, botões e vereditos alinhadas ao sistema; não foram ativados em novas rotas |

Templates históricos sem rotas continuam fora do fluxo do produto. Não há necessidade de novas páginas nesta rodada: o ganho vem de tornar as páginas existentes consistentes e funcionais.

## Critérios de usabilidade

A organização dos botões segue hierarquia de tarefas: uma ação principal por grupo, auxiliares discretas, operações destrutivas explícitas. Agrupamento e espaçamento aproximam informações relacionadas; títulos não repetem desnecessariamente o cabeçalho global. Rótulos, símbolos e foco complementam as cores. Essas decisões seguem as [heurísticas de Nielsen](https://www.nngroup.com/articles/ten-usability-heuristics/) e as [Web Interface Guidelines](https://github.com/vercel-labs/web-interface-guidelines/blob/main/command.md), com atenção a consistência, visibilidade do estado, prevenção de erros e reconhecimento.

## Evidências e limites

- Build de produção concluído. CSS passou de 71,90 kB para 63,84 kB nesta rodada, com a redução de variantes avulsas e marcação obsoleta. Não foram adicionadas dependências.
- **14 testes frontend**, incluindo filtros, recuperação, teclado e contraste dos dois temas.
- **489 testes PHP / 2.964 assertions no Linux**, incluindo páginas com um título principal, links de submissões reais, apresentação de fila/erro de execução e recuperação de acesso sem falsa promessa.
- Navegador: inspeção visual desktop do início e da lista de problemas; autenticação local e inspeção do formulário de login. A lista revelou quebra indevida das palavras dos botões, corrigida em CSS.
- A execução em 375px foi interrompida pela janela de outra extensão do Chrome antes de percorrer as páginas. A conferência visual final mobile e escura permanece pendente; os contrastes de ambos os temas foram verificados numericamente. Não houve teste com leitor de tela real nesta rodada.
- O contraste testado cobre as combinações do sistema, não certifica todos os pixels/conteúdos possíveis. A revisão não afirma conformidade integral WCAG.

## Integração final

A master avançou durante a revisão. Foram integrados os PRs #36–#38, mantendo a nova página de perfil e a gestão de problemas reais por competição. O template legado de exercícios foi removido conforme a mudança do backend. Os conflitos de layout e mensagens foram resolvidos e a suíte completa foi executada em um contêiner temporário Linux, sem rede, montando somente esta worktree.

No macOS, 487 testes passam; os dois testes que compilam C falham porque o comando Linux de link estático encontra o Clang do macOS (`crt0.o` ausente). No ambiente Linux, **todos os 489 testes passam**. Não foi alterado o compilador nem o backend para mascarar essa diferença de ambiente.

## Dependências do backend

A recuperação efetiva por e-mail precisa ser implementada antes de voltar a oferecer envio de link. Configurações globais e controles de pausa/reinício não têm ações funcionais na página atual; o frontend deixa de apresentá-los como editáveis. O rejulgamento administrativo precisa de contrato próprio para voltar à lista; o fluxo de julgamento existente foi mantido. Nenhum desses endpoints foi implementado ou alterado nesta revisão.
