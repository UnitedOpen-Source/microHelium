# #47 — Contas gerenciadas e privacidade

## Contexto e objetivo

Permitir cadastro pela organização sem identidade externa e aplicar privacidade por política etária. Interface administrativa `/backend/managed-accounts`. A idade de 18 é requisito de produto proposto pela issue; este documento não afirma que ela esgota obrigações legais ou de consentimento.

## Regras e decisão pendente

- Cadastro gerenciado começa privado, incluindo nascimento não informado. Não solicitar nascimento no registro público existente nesta fase.
- birthdate é DATE nullable, sem timezone e sem derivar idade no cliente. Servidor calcula aniversário conforme calendário/timezone do produto; escolher e testar política para 29/02 em ano não bissexto. Rejeitar futuro/datas impossíveis. Não mostrar nascimento em listagem, placar, biblioteca ou perfil público.
- Menor de 18: perfil/histórico privado e vinculação externa bloqueada. Data desconhecida: conservar restrição até revisão. UI exibe `privacy_locked` e motivo retornados pelo servidor, não deduz a partir do nome/data.
- **Proposta aguardando decisão do mantenedor:** aos 18, remover restrição etária e liberar opção de publicação, mantendo visibilidade private até escolha explícita. Não publicar histórico automaticamente. Pergunta foi enviada; ausência de resposta não aprova uma transição pública. Exceção “admin torna menor público” da issue precisa de política explícita antes de ser habilitada; não existe botão de override nesta entrega.
- Regras de visibilidade e elegibilidade são campos/conceitos separados: adulto pode continuar privado. Aniversário não exige edição manual e não gera anúncio público.

## API e dados

`GET /api/frontend/managed-accounts?q=&page=1`: `{capabilities:{can_create},contests:[{id,name,sites:[{id,name}]}],items:[{id,fullname,username,contest_name,site_name,privacy_locked:boolean,visibility:"private"|"public",external_linking_allowed:boolean,privacy_reason:string}],meta}`. Sem birthdate, email, senha ou token. Busca administrativa por nome/usuário. Acesso inicial admin apenas.

`POST /api/frontend/managed-accounts`: `{fullname,username,birthdate:null|"YYYY-MM-DD",contest_id,site_id}` → 201 `{data:{id,activation_url:null|string}}`. fullname<=255, username<=80, unique com normalização consistente; site pertence ao contest escolhido. Ignorar/rejeitar user_type/visibility externos e criar role team habilitada apenas conforme processo de ativação. 422 por campo. Idempotência obrigatória para impedir dupla conta após timeout.

Onboarding proposto: criar identidade sem senha utilizável e link local de ativação de uso único, com token hash, expiração curta e fluxo de definir senha. A tela mostra o link somente na resposta de criação e permite ocultar; entregar apenas pelo canal institucional. **Backend precisa implementar a página/endpoint de ativação antes de oferecer `can_create=true`**, ou fornecer fluxo institucional equivalente real e `activation_url:null` com procedimento documentado. Não reutilizar `/password/email`, que ainda é stub no projeto. Não criar senha padrão compartilhada nem enviar e-mail fictício.

Campos propostos em users: birthdate DATE nullable, managed_by nullable FK, profile_visibility default private, managed_at; registro de entrega/ativação separado. `isMinor()` não deve transformar nascimento desconhecido em autorização pública. Policy de privacidade central distingue unknown/minor/adult sem expor estado sensível a terceiros. Mudança de birthdate exige trilha de auditoria restrita; endpoint de edição está fora desta primeira tela.

## Requisitos e bordas

- Aplicar policy em TODAS as saídas relevantes, incluindo APIs legadas, histórico #43, agregados pequenos e exportação #44; filtro na UI não protege dados. Nomes de equipe de eventos podem obedecer política própria, mas nunca adicionar nascimento/nome civil ao export por conveniência.
- Token de ativação não vai para listagem/logs/cache nem permite trocar papel/concurso. Reuso/expiração negados; rate limit. A entrega institucional não pode ser declarada concluída apenas porque a conta foi criada.
- Transição etária em timezone definido, cache de capabilities invalidado ou expiração adequada; permissões reavaliadas no endpoint de publicação/vinculação, não somente no job diário.
- Retenção/correção de nascimento e autorização para cadastro administrado precisam de definição do responsável pelo produto. Coletar apenas os dados necessários para a política acordada.

## Critérios de aceite

1. Conta criada começa privada e sem vinculação externa enquanto protegida; uma requisição manipulada com visibility public não contorna a regra.
2. Aniversário de 18 libera apenas as capacidades aprovadas; testes imediatamente antes/depois e 29/02; desconhecido permanece restrito.
3. Participante/juiz/site recebem403 na listagem administrativa; API pública não expõe birthdate nem histórico de outro usuário protegido.
4. Site de outro concurso é422; username duplicado preserva formulário e foca erro.
5. Token de ativação expira, é de uso único e não concede privilégio administrativo. Repetição da mesma chave não cria usuário extra.

## Perguntas para decisão

Confirmar comportamento aos 18, exceção administrativa de publicação de menores, tratamento de nascimento desconhecido/29/02, prazo do token e procedimento institucional de entrega. Até definição, manter privado e capacidades públicas desabilitadas.
