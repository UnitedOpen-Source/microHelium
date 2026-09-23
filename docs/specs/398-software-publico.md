# #398 — Canal de segurança e metadados de software público

## O buraco

O juiz executa código de terceiros, e o único canal de segurança documentado
era o README mandando escrever para **`security@example.com`**: um endereço
de exemplo, que não entrega para ninguém. Não havia `SECURITY.md`, e o reporte
privado de vulnerabilidade do GitHub estava **desligado**
(`GET /repos/UnitedOpen-Source/microHelium/private-vulnerability-reporting`
→ `{"enabled":false}`). Na prática, quem achasse uma fuga do sandbox tinha duas
saídas: um e-mail que some, ou uma issue pública.

Em paralelo, o projeto não aparecia em nenhum catálogo de software público,
porque não tinha o `publiccode.yml` que esses catálogos leem.

## O que entrou

| Peça | Onde | Guardado por |
|---|---|---|
| Política de segurança: canal, escopo, fora de escopo, expectativa de resposta | `SECURITY.md` | `tests/Unit/SecurityPolicyTest.php` |
| README encaminha para `SECURITY.md`, sem o endereço de exemplo | `README.md`, seção *Security* | `SecurityPolicyTest` |
| Reporte privado de vulnerabilidade **ligado** | configuração do repositório | não mora no disco; ver abaixo |
| Metadados `publiccode.yml` em `pt` e `en` | `publiccode.yml` | job `publiccode` do CI (validador oficial) |
| Licença do `publiccode.yml` igual às outras cinco declarações | `publiccode.yml`, `legal.license` | `LicenseDeclarationsAgreeTest::test_publiccode_yml_declara_o_mesmo_spdx` |
| Descrição e tópicos do repositório | configuração do repositório | não mora no disco |

### O escopo de segurança segue o que o projeto já mede

A linha entre "vulnerabilidade" e "instalação errada" já estava traçada no
código. `SECURITY.md` só a escreve:

- **Dentro:** o que o `judgehost:selftest` deveria pegar e não pega; vazamento
  de caso de teste escondido (#186); placar congelado, fonte de outra equipe,
  clarificação privada; autenticação e autorização; dados de menor protegidos
  pela #47.
- **Fora:** julgar no stack de desenvolvimento ou com `privileged: true`. O
  README já diz que isso não confina, e o `autojudge:start` recusa subir numa
  máquina que não confina (#282). Também ficam fora os defeitos de toolchain
  de terceiro que não escapam do sandbox, que vão para o upstream e para a #309.

### O validador do `publiccode.yml`

É o `publiccode-parser` oficial ([italia/publiccode-parser-go](https://github.com/italia/publiccode-parser-go)),
**fixado em `v5.4.3` e pelo SHA-256** do `checksums.txt` da release, pelo mesmo
motivo que as actions do CI são fixadas por commit. Roda com `-no-network`: o
que pode regredir num PR é o esquema, e as URLs checadas seriam as do próprio
repositório.

Medido antes de fixar:

```
$ publiccode-parser -no-network publiccode.yml   # rc=0
$ publiccode-parser publiccode.yml               # rc=0, com checagem de URL
```

Duas decisões que o validador forçou:

- `categories` é uma lista fechada. `e-learning` não existe; entrou
  `learning-management-system`, a mais próxima da lista oficial.
- `publiccodeYmlVersion: '0'` é o valor que o parser atual pede. `'0.2'` é
  aceito com aviso e lido como v0.7.

## O que ficou para decisão humana, e por quê

| Item da #398 | Estado | Por que não entrou |
|---|---|---|
| Prazo de resposta de segurança | `SECURITY.md` promete **confirmação em até 7 dias** e diz que **não há SLA** | é o valor mais conservador que um projeto de voluntários sustenta; o mantenedor pode mudar |
| Contato em `publiccode.yml` | só o nome do mantenedor, **sem e-mail** | o validador não exige e-mail; publicar um endereço pessoal em mais um lugar é decisão de quem é dono dele |
| Candidatura a Digital Public Good | não feita | o indicador de **titularidade clara** depende da #266, que espera o consentimento dos titulares |
| Portal do Software Público Brasileiro | não avaliado | não foi conferido se o portal ainda recebe projetos em 2026, nem se aceita AGPL-3.0 |
| DCO para contribuições futuras | não adotado | muda o fluxo de todo contribuidor; é decisão do mantenedor, pergunta 3 da issue |

## Critérios de aceite

1. `SECURITY.md` existe e aponta para o formulário de reporte privado, e o
   formulário abre (o reporte está ligado).
2. O README não indica mais nenhum endereço `@example.com` e encaminha para
   `SECURITY.md`.
3. `publiccode.yml` passa no validador oficial fixado, no CI.
4. Mudar a licença em qualquer uma das seis declarações sem mudar nas outras
   reprova o `LicenseDeclarationsAgreeTest`. Isso foi conferido trocando
   `legal.license` por `AGPL-3.0-only`: o teste reprovou.
