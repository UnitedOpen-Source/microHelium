# 188 — Gestão de organizações

## O buraco

A #46 entregou "um problema pertence a uma organização, e quem é `editor` dela pode editá-lo" — e **nenhum jeito de criar uma organização**. `Organization::create()` não era chamado em `app/` nem em `routes/`. O próprio model dizia:

> creation/management of organizations is a separate phase

Na prática: `/backend/bank-governance` abria com o **seletor de proprietário vazio** em qualquer instalação real, e metade da #46 só era alcançável por `php artisan tinker`. Não era bug — era uma fase deixada para depois. Esta é a fase.

## A decisão que estava aberta

A #46 registra que o modelo **organização + membership** foi implementado *porque a spec já o adotava como padrão, não porque a pergunta foi respondida*. A alternativa era um `owner_user_id` no problema, e a #188 pede para confirmar o modelo antes de construir telas de membership — porque telas são o que torna a escolha cara de desfazer.

**Confirmado: organização + membership.** Três razões, e a primeira não existia quando a pergunta foi feita:

1. **A #195 publicou `/organizations` na Contest API da ICPC.** Ali organização é um objeto de primeira classe que as equipes referenciam (`organization_id`), e no vocabulário da ICPC é a universidade. Trocar por `owner_user_id` deixaria aquele endpoint sem nada para reportar — e é um endpoint de uma especificação externa, não uma escolha nossa.
2. **O caso real é um acervo compartilhado.** Vários professores da mesma instituição editando os problemas dela é exatamente o que membership modela. Com `owner_user_id`, alguém sair da instituição vira reatribuição manual problema a problema.
3. **A transferência de propriedade da #46 já pressupõe isso**: `ProblemBankOwnershipTransfer` registra `from_organization_id` / `to_organization_id`.

## Arquivar, e o que ele **não** faz

A issue pede que isto esteja escrito e não inferido.

| | |
| --- | --- |
| os problemas | **continuam** apontando para a organização arquivada |
| o seletor de proprietário | ela **some** |
| receber problemas novos | **recusado**, na escrita e não só na tela |
| um problema que já é dela | **continua editável** |
| os membros dela | **continuam** podendo editar os problemas dela |
| desfazer | sim — é um timestamp anulável |

**Por que os problemas não são desassociados:** uma limpeza que zerasse `owning_org_id` transformaria "esta instituição saiu" em "estes 200 problemas não são de ninguém", e a #46 trata problema sem dono como caso legado que só admin vê.

**Por que os membros mantêm a edição:** tirar isso deixaria problemas que ninguém alcança — o mesmo orfanato, por outro caminho. Arquivar é *"não receba mais"*, não *"fique intocável"*.

**Por que a recusa é na escrita:** sumir do seletor não basta. O seletor é uma dica de apresentação, e o endpoint aceita o id que o cliente mandar. Sem a recusa, "arquivada" seria uma sugestão.

**Por que só quando o dono muda:** um problema que já pertence à organização arquivada continua salvável. Editar as etiquetas dele não pode exigir transferi-lo primeiro, ou arquivar congelaria o acervo inteiro daquela instituição. Fixado por mutação — remover a condição `$ownerChanged` derruba dois testes.

**Por que a listagem de organizações mostra as arquivadas** (ao contrário do seletor): é a tela onde se **desarquiva**. Escondê-las ali tornaria a ação inalcançável.

## Quem pode

`admin`, e não apenas `auth` como o vizinho `bank-governance`.

A diferença é deliberada: lá a autorização é **por política**, porque um editor mexe nos problemas *da sua* organização. Aqui o assunto é **quem é editor** — deixar um editor gerenciar a própria membership seria deixar que ele se promova, e promover a si mesmo é a única coisa que uma permissão nunca pode permitir.

## Uma fixture que faltava

Não existia `ProblemBankFactory`; a fixture do repositório (`ProblemBankPolicyTest::bankAttributes()`) sorteia `code` com `rand(10000, 99999)` numa coluna `unique` — 90 mil valores, colisão rara e não impossível, que é exatamente a forma das duas correções de `AnswerFactory`: um flake que ninguém consegue atribuir.

A factory nova conta em sequência. E grava `version` explicitamente em vez de confiar no default da coluna: o Eloquent não lê defaults do banco de volta, então o modelo recém-criado ficaria com `version` NULL em memória, e um teste que mandasse `(string) $bank->version` enviaria string vazia — que o `ConvertEmptyStringsToNull` transforma em null, e o endpoint responderia "Versão ausente". Uma falha que parece do produto e é da fixture.
