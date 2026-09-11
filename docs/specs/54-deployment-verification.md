# #54 — Rollback por imagem e smoke pós-deploy

## Contexto e objetivo

Definir procedimento rápido e verificável de retorno à versão anterior sem reconstrução, incluindo compatibilidade dos assets do frontend. Escopo desta entrega: spec para backend/ops; nenhum deploy/rollback executado.

## Escopo proposto

- Imagens identificadas por SHA/digest imutável; registrar current/previous digest e manifesto de release, incluindo migrations aplicadas. Preferir apontar deploy ao digest, preservando tags legíveis como referência.
- Antes de promover, validar schema compatível com versão anterior (expand/contract). Rollback de imagem não deve automaticamente reverter migration nem apagar dados. Se migration incompatível, procedimento deve declarar que rollback simples não é seguro e indicar plano de recuperação testado.
- Publicar HTML/PHP e `public/build/manifest.json` com assets do MESMO build. Manter assets com hash da versão anterior por janela de cache/deploy para páginas abertas não receberem404. Não misturar manifest novo com bundle antigo, nem depender de Vite dev server.
- Procedimento revisável, ex.: `rollback PREV=<digest>` valida imagem e compatibilidade, troca app/queue/scheduler de forma coordenada, reinicia workers com drenagem e executa smoke. Não reconstruir nem repetir migrations no rollback.

## Smoke pós-deploy

Contra URL realmente publicada: `/up`, `/api/health`, página pública, CSS/JS referenciados pelo manifest, login sem envio de credenciais em logs, rotas protegidas negando sessão ausente, consulta de API via conta canário restrita. Verificar status+conteúdo esperado, não apenas HTTP200 de proxy. Health de Redis/queue/scheduler e executor deve vir de endpoint interno restrito; nunca expor segredos em health público.

Julgamento real em produção somente por canário isolado, explicitamente configurado fora de rankings/estatísticas, com fonte inofensiva e cleanup próprio. **Não apontar PHPUnit com RefreshDatabase/migrate:fresh para banco de produção.** A suíte atual de testes é para banco descartável; escrever script dedicado de leitura/canário para smoke live.

Frontend novo: abrir `/practice` e uma ferramenta por conta autorizada; backend ainda indisponível deve mostrar mensagem honesta, mas não contar como integração funcional aprovada. Gates diferenciam shell/assets saudáveis de feature habilitada com endpoint ausente. Chunks lazy precisam baixar após navegação direta.

## Critérios de aceite

Ensaio em staging: promoção → smoke com identificador de release → falha induzida → rollback ao digest anterior → smoke verde; todos sem rebuild e sem perda de dados. Página antiga ainda carrega assets; jobs em voo não duplicam efeitos e versões de worker/schema compatíveis. Falha de smoke bloqueia promoção/alerta responsável; log contém release/tempo/status e nenhum token.

## Perguntas para decisão

Registro de imagens, retenção de digests/assets, dono da decisão de rollback, RTO esperado, política de migrations e disponibilidade do canário. Nenhuma nova página de operação de produção é necessária nesta fase.
