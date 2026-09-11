# #49 — Isolamento obrigatório do juiz: dependência do frontend

## Contexto e objetivo

A issue descreve compilação/execução sem isolamento obrigatório de filesystem/namespace. A UI de treino aumenta a superfície de envio; ela deve depender de uma capacidade real e segura do backend. Nenhum sandbox foi implementado neste PR de frontend.

## Escopo proposto ao backend/runtime

Escolher e fixar runtime isolado (container efêmero ou namespaces equivalentes), com ferramentas por linguagem em filesystem imutável, pasta exclusiva por tentativa e sem acesso a .env, banco, Redis, Docker socket ou diretórios de outras tentativas. Aplicar também à compilação, que executa ferramentas sobre fonte não confiável. Sem rede por padrão; dependências de linguagem pré-instaladas na imagem. Usuário sem privilégios; limites reais de CPU, memória, processos, disco e tempo de parede; matar toda a árvore ao cancelar/estourar limite.

Proposta de faseamento: estabelecer modelo de ameaça/PoC de runtime → contrato de executor → testes de confinamento → migrar cada linguagem → habilitar submissões. Não adaptar apenas `rlimit` e considerar o isolamento resolvido. Falha de disponibilidade/configuração do isolamento deve falhar fechada, sem fallback para execução no host.

## Contrato com o frontend

`GET /api/frontend/practice/problems/{id}` deve devolver `capabilities.can_submit=false` e motivo seguro quando o runtime necessário não estiver saudável. `POST .../runs` revalida o estado, pois o ambiente pode falhar depois do GET; responder503 sem criar falsa confirmação ou enfileirar execução insegura. Histórico distingue indisponibilidade técnica de resposta incorreta; estado de CS segue política existente, sem penalizar equipe por erro operacional. Health pode informar indisponibilidade sem paths/tokens/detalhes de host.

Este contrato também deve ser aplicado ao envio de competição existente pelo backend. Desabilitar um botão sozinho não é proteção; acesso direto à API deve respeitar o mesmo gate. Não habilitar can_submit de Treino Livre antes da validação deste requisito.

## Critérios de aceite

Em ambiente descartável dedicado, testes verificam: não ler arquivo sentinela fora da tentativa, não alterar imagem/host, não acessar arquivo de outro run, não abrir rede, limites de memória/processos/disco/tempo aplicados, cancelamento elimina descendentes, logs não incluem segredos e executor ausente impede execução. Rodar soluções válidas e casos de falha para cada linguagem ativa; documentar verdicts determinísticos. Nunca executar esses testes de confinamento sobre máquina de produção.

## Dados/API e perguntas para decisão

Não requer novo formulário no frontend. Pode adicionar executor_status e reason_code internos, separados do texto público. Backend/ops deve escolher runtime, perfil de recursos, estratégia de imagens/cache e ambientes suportados. A escolha arquitetural não foi tomada pela UI.
