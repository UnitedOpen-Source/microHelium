# Revisão de UI/UX e prontidão do microHelium

Revisão: 15–16/09/2026. Base final: `327d540` de `master` (atualizada após as entregas do backend). Trabalho isolado em `feat/frontend-release-audit`; implementação de organizações e Contest API preservadas.

## Resultado e limite

Esta revisão corrige interações que falhavam com a CSP, contexto dos formulários, estados da competição e recuperação nas páginas Vue. Acrescenta operações de finalização usando serviços reais, sem prometer funcionalidades futuras. Não certifica o produto inteiro como pronto para competição oficial: o ciclo legado de congelamento/encerramento em #225 continua sendo um bloqueador, além da homologação operacional.

## Achados e intervenções

| Área / telas | Evidência | Correção / critério |
| --- | --- | --- |
| Administração: usuários, competições, banco, sedes e linguagens | `onsubmit` de confirmação era bloqueado pela CSP; a submissão podia prosseguir sem confirmação | #221: diálogo nativo com ação descrita, Cancelar em foco, Escape e submissão única preservando o botão original |
| Sedes, linguagens, usuários, times, detalhes do banco | Modais identificados apenas por sufixo `Modal`; IDs de edição terminavam em número | #222: marcador explícito, nome acessível, fundo inerte, contenção/restauração de foco e rolagem |
| Sedes e linguagens | Modais dentro de `tbody`; `old('name')` usado em todas as linhas | Modais fora da tabela; identidade do formulário preserva somente o registro enviado; erro abre e foca o campo correto; checkbox desmarcado permanece desmarcado |
| Sedes | Valor visível em minutos dependia de `oninput` para enviar segundos | Campo em segundos, unidade explícita, exemplo de conversão, sem conversão oculta ou arredondamento |
| Seleção de competição | `onchange` bloqueado e navegação automática | Botão explícito “Abrir competição” |
| Importação BOCA e envio por arquivo | Nome do arquivo não era atualizado ao selecionar | Listener de `change`; BOCA continua sendo o único formato anunciado |
| Esclarecimentos | Respostas prontas usavam `onclick` | Listener preenche, emite `input` e foca o texto para revisão; não envia automaticamente |
| Configurações / relógio | Estados locais divergiam do congelamento/revelação do servidor | #223: estado por competição, ligação para operações, relógio respeita `is_frozen` e `is_finalized`, freeze continua visível depois do término |
| Operações da competição | Serviços de preflight/finalização/premiação sem fluxo administrativo | Nova tela com evento identificado, impedimentos, revelação confirmada, revalidação ao finalizar, premiação somente após finalização |
| Treino e demais páginas Vue | Falha de atualização escondia o conteúdo anterior; navegação substituía histórico | #224: manter última consulta com aviso de desatualização, bloquear mutações até recuperar, entradas no histórico e restauração por Voltar/Avançar |
| Treino | Código podia ser perdido ao sair sem envio | Indicador de bytes/alterações e `beforeunload`; rascunho somente em memória, sem armazenar código privado em localStorage |
| Sessão expirada | Erro sem caminho para recuperar | Ações para entrar/atualizar, orientação para copiar texto antes de sair e ajuda em caso de falta de permissão |

## Decisões de design

- Preservar a identidade verde-petróleo e os tokens semânticos já existentes. Os testes de contraste continuam verificando texto (4,5:1), limites de campos e foco (3:1), nos temas claro e escuro. Uma nova paleta não corrigiria os problemas de interação encontrados.
- Ação principal por etapa; ações destrutivas com consequência escrita e confirmação; estado não comunicado somente por cor.
- Tabelas mantêm rolagem interna, botões têm alvo de pelo menos 44 px e modais permitem acessar campos/ações por teclado em telas pequenas.
- Usar linguagem de tarefa: conferir pendências, revelar placar, finalizar competição. Distinguir término do tempo, revelação e finalização.
- Heurísticas aplicadas: visibilidade do estado; prevenção de erros; controle do usuário; consistência; reconhecimento; recuperação; redução da carga de memória. Campos com unidade explícita e escopo nomeado evitam decisões baseadas em lembrança.

## Contratos e dependências para o backend

O detalhamento está em [spec de integração](specs/frontend-release-readiness.md). As issues existentes #188, #195, #196, #198, #200 e #219 receberam comentários antes da implementação desta revisão.

| Dependência | Situação observada / decisão |
| --- | --- |
| #188 / PR #226 Organizações | Backend integrado durante a revisão; ainda falta ligar a gestão de organizações a uma tela administrativa; contrato em `188-organizacoes.md` |
| #195 / PR #220 Contest API | Fase REST integrada durante a revisão; o feed também chegou em #232. Homologar o resolver com a ferramenta externa real |
| #219 / PR #232 Event feed | Integrado durante a revisão; ainda requer ensaio do resolver, reconexão e cerimônia com a ferramenta externa |
| #196 / PR #230 Calibração | Medição e comparação de envios aceitos integradas; falta apresentar `/api/frontend/judgehosts/calibration` na tela de máquinas. Não altera vereditos; fase 2 de soluções de referência continua futura |
| #198 / PR #227 Ajustes de tempo | Intervalos removidos integrados. O relógio desta revisão consome o término global do servidor; gestão dos intervalos e apresentação por sede precisam de interface própria e homologação do ciclo de revelação |
| #200 / PR #231 ICPC/Kattis | Importador API integrado durante a revisão; o formulário de importação do banco continua sendo especificamente BOCA. A nova importação por problema precisa de sua interface |
| #225 Ciclo legado | Atalhos retirados da UI; verificação de evento não iniciado corrigida no serviço compartilhado. Encerramento antecipado/congelamento legado ainda precisam ser alinhados |

As interfaces adicionais dessas novas APIs estão reunidas na [issue #233](https://github.com/UnitedOpen-Source/microHelium/issues/233), com os contratos já entregues e critérios de aceitação.

## Validação reproduzível

```sh
npm run test:frontend
npm run build
vendor/bin/phpunit --no-coverage --filter='Frontend|ContestFinalization|ContestAdminScreen|SiteController|Language'
```

A suíte de navegador padrão verifica os bundles de produção sob a CSP real:

```sh
npm run test:browser
```

Para os testes administrativos adicionais, use **somente uma worktree de testes** com `.env` local, chave própria, `DB_CONNECTION=sqlite`, `DB_DATABASE` apontando para `database/audit.sqlite`, `SESSION_DRIVER=file`, fila sem execução automática e nenhum dado real. Crie esse arquivo vazio, migre o banco e rode:

```sh
php artisan migrate --force
php tests/Browser/seed-audit.php
BROWSER_RELEASE_AUDIT=1 npm run test:browser
```

O seeder recusa outro banco/ambiente. A conta `ui-audit@example.test` / `audit-local-only` é exclusivamente uma fixture descartável. Os testes administrativos são opt-in para nunca tentar autenticar essa conta em uma instalação real. A suíte exige que `public/hot` não exista.

Resultado da validação: 39 testes de frontend aprovados; 10 testes Chromium aprovados (incluindo 375/768/1440 px); PHPStan sem erros. A suíte PHP completa é repetida sobre a base atualizada e seu resultado final fica no PR. Casos dependentes do ambiente Linux/judgehost podem ser pulados neste macOS; não representam homologação do julgamento em produção.

A análise automatizada em telas pequenas encontrou e corrigiu a fuga do texto `sr-only` da tabela (containing block) e o seletor de competição sem quebra de linha. O estado HTTP 429 agora usa a apresentação de erro em português. A inspeção manual com leitor de tela e a revisão visual por pessoas continuam necessárias; testes automatizados não são certificação WCAG. O navegador conectado para inspeção visual não estava disponível nesta sessão.

## Portas para a versão final

1. Integrar as correções de interface e resolver #225 no backend; verificar competição sem freeze, freeze até após o fim, revelação e finalização.
2. Homologar papéis separados (admin, juiz, coordenador, staff, equipe e visitante), incluindo respostas 401/403/419 e privacidade entre competições.
3. Ensaiar uma prova completa com hosts de julgamento reais, queda de conexão, retomada, esclarecimentos, revisão de vereditos e premiação.
4. Validar restauração de backup, observabilidade e procedimentos operacionais. As telas de saúde não substituem esse ensaio.
5. Fazer revisão visual nos dois temas, teclado, zoom de 200%, leitor de tela e dispositivos reais. Confirmar ajuda e textos com organizadores/equipes.
6. Condicionar o anúncio de formatos/importadores e integrações externas à conclusão das respectivas issues; evitar bloquear o uso básico por funcionalidades opcionais ainda não contratadas.
