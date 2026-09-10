# Revisão de acessibilidade e usabilidade

Revisão de 10/09/2026, vinculada ao PR #35 e à issue #32. O trabalho aplica as [heurísticas de Nielsen](https://www.nngroup.com/articles/ten-usability-heuristics/), critérios pertinentes das [WCAG 2.2](https://www.w3.org/TR/WCAG22/) e as [Web Interface Guidelines](https://github.com/vercel-labs/web-interface-guidelines/blob/main/command.md). Heurísticas são orientações de usabilidade; esta revisão não constitui certificação de conformidade WCAG.

## Problemas corrigidos

| Problema observado | Mudança | Referência |
| --- | --- | --- |
| Modais permitiam interação com o fundo | Isolamento com `inert`, nome acessível, contenção de Tab/Shift+Tab, Escape e devolução do foco; restauração do estado anterior de cada região | Nielsen: controle e liberdade; WCAG 2.1.1, 2.4.3; [padrão de diálogo da W3C](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/) |
| Erros do servidor eram apresentados longe dos campos e podiam deixar o formulário fechado | Resumo com links, mensagens associadas por `aria-describedby`, `aria-invalid`, foco no primeiro campo e abertura automática do diálogo/etapa correspondente | Nielsen: reconhecimento e recuperação de erros; WCAG 3.3.1, 3.3.3 |
| Formulários não indicavam andamento nem protegiam contra cliques repetidos | Mensagem de envio, `aria-busy`, bloqueio de submissões repetidas e restauração ao voltar pelo navegador; botão mantém `name/value` no POST | Nielsen: visibilidade do estado e prevenção de erros; WCAG 4.1.3 |
| Linguagens/problemas do assistente exigiam interação por ponteiro | Labels nativas com checkboxes focáveis, foco visível no cartão e seleção indicada por ícone e borda | Nielsen: consistência; WCAG 1.4.1, 2.1.1, 2.4.7 |
| Falha no assistente apagava entradas e substituía a data | Recuperação de `old()` para campos e seleções, data inicial local somente quando vazia, retorno à etapa com erro e revisão final dos dados | Nielsen: prevenção de erros e reconhecimento |
| Filtros desapareciam ao recarregar ou voltar no histórico | Busca/dificuldade na URL, restauração em `pageshow`/`popstate`, contagem e estado sem resultados | Nielsen: eficiência e visibilidade do estado |
| Autenticação abria o teclado automaticamente e dependia de dicas no placeholder | Remoção de autofocus, autocomplete apropriado, instrução permanente de senha no cadastro, resumo de erros | WCAG 1.3.5, 3.3.2; diretrizes de formulários |
| Foco podia ficar encoberto e os novos blocos de código não eram alcançáveis por teclado | Margem de rolagem sob o cabeçalho, regiões de código focáveis e nomeadas, tratamento de cores forçadas e quebra de nomes longos | WCAG 2.1.1, 2.4.11; [foco não encoberto](https://www.w3.org/WAI/WCAG22/Understanding/focus-not-obscured-minimum) |

## Onde revisar

- `resources/js/ui/dialogs.js`: gerenciamento de diálogo e foco.
- `resources/js/ui/forms.js`: validação, recuperação e andamento.
- `resources/js/ui/wizard.js`: navegação e validação das cinco etapas, sem handlers inline.
- `resources/js/ui.js`: drawer, menu da conta e filtros persistentes.
- `resources/views/partials/error-summary.blade.php`: resumo legível também sem JavaScript.
- `resources/views/backend/contest-wizard.blade.php`: formulários nativos e retenção das entradas.
- `resources/css/app.css`: foco, feedback, estados de seleção e adaptação ao dispositivo.

## Verificação

- `npm run test:frontend`: 9 testes de comportamento no DOM. Cobrem isolamento/foco, validação, IDs únicos entre formulários, erros do servidor, envio duplicado, cancelamento, retorno no histórico, filtros e assistente.
- `vendor/bin/phpunit --no-coverage`: 425 testes e 2.786 assertions; inclui renderização das páginas e retenção de dados após validação real do servidor.
- `npm run build` e `git diff --check`.
- Navegador nesta revisão: abertura do diálogo administrativo, inspeção de `inert`, erro obrigatório e mudança de etapa com foco no título. A revisão anterior também verificou tema claro/escuro e viewport de 390px.
- A extensão do Chrome bloqueou a automação durante o teste de envio administrativo. Por isso, a inspeção visual mobile desta segunda revisão e a recuperação após POST no navegador não foram concluídas; a recuperação está coberta pelos testes de DOM e Laravel.
- Leitor de tela real, combinação de navegadores/tecnologias assistivas e contraste de cada conteúdo fornecido pelos organizadores ainda exigem validação manual. Não se afirma conformidade integral às WCAG.

A integração já inclui o backend do detalhe de submissão (#31). Não há mudanças adicionais em controllers, rotas, migrations ou contratos de API. Filtros atuam sobre os registros carregados; dados de listas continuam sendo atualizados por navegação/recarregamento.
