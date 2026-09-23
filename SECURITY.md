# Política de segurança

*English summary at the end.*

O microHelium executa **código enviado por terceiros**. Uma falha no isolamento
do juiz não é um bug comum: é execução arbitrária na máquina de quem hospeda a
prova. Por isso reportes de segurança têm um canal próprio, **privado**, e não
devem ser abertos como issue pública.

## Como reportar

Use o **reporte privado de vulnerabilidade do GitHub**:

1. Abra <https://github.com/UnitedOpen-Source/microHelium/security/advisories/new>
   (aba *Security* → *Report a vulnerability*).
2. Descreva o problema, a versão (commit) afetada e, se possível, um passo a
   passo ou prova de conceito mínima.

O reporte fica visível só para você e para os mantenedores até ser publicado.

**Não** abra issue, PR ou discussão pública com os detalhes antes da correção.

## O que esperar

O projeto é mantido por voluntários e **não tem SLA**. O que nos propomos a
fazer:

- confirmar o recebimento em até **7 dias**;
- dizer se reconhecemos o problema e qual o plano, assim que ele for
  reproduzido;
- publicar um *security advisory* depois da correção, com crédito a quem
  reportou, se a pessoa quiser.

## Versões suportadas

Não há releases versionadas. **Só o branch `master` recebe correções de
segurança.** Instalações devem acompanhar o `master`.

## O que está no escopo

- **Fuga do sandbox do juiz**: código submetido que lê ou escreve fora da
  área de trabalho, acessa a rede, lê segredos da máquina, escapa do limite de
  memória ou de processos, ou persiste entre execuções. O critério de
  referência é o `php artisan judgehost:selftest`: um caso que ele deveria
  pegar e não pega é vulnerabilidade.
- **Vazamento de casos de teste escondidos**, inclusive para judgehosts
  remotos fora da prova em curso (ver #186).
- **Vazamento de informação protegida da prova**: placar durante o
  congelamento, código-fonte de outra equipe, clarificações privadas.
- **Autenticação e autorização**: acesso a painel de juiz ou administração
  sem o papel correspondente, uso de token de judgehost fora do seu escopo,
  submissão em nome de outra conta.
- **Dados de menores**: exposição de perfil, histórico ou data de nascimento
  que a política de privacidade por idade (#47) deveria esconder.

## O que não está no escopo

- **Instalações configuradas contra a documentação.** Em especial, julgar no
  stack de desenvolvimento (`docker-compose.dev.yml`) ou com
  `privileged: true`: o README avisa que isso **não confina**, e o
  `autojudge:start` recusa subir numa máquina que não consegue confinar.
- **Defeitos em compiladores e runtimes de terceiros** (GCC, JDK, Node,
  Portugol Studio, `scratch-run` etc.) que não resultem em fuga do sandbox.
  Esses vão para o projeto de origem; acompanhamos os que nos afetam na #309.
- **Negação de serviço por volume** contra uma instalação auto-hospedada:
  limite de taxa e capacidade são responsabilidade de quem hospeda.
- Relatórios automáticos de scanner sem demonstração de impacto.

---

## English summary

microHelium runs **untrusted, user-submitted code**. Please report security
issues **privately** through GitHub's private vulnerability reporting at
<https://github.com/UnitedOpen-Source/microHelium/security/advisories/new>, not
as a public issue.

- The project is volunteer-maintained and has **no SLA**. We aim to
  acknowledge reports within **7 days** and to publish an advisory, with
  credit if you want it, after a fix.
- Only the `master` branch is supported; there are no versioned releases.
- **In scope:** judge sandbox escapes (anything `php artisan judgehost:selftest`
  should catch but does not), hidden test case leaks, leaks of protected
  contest data (frozen scoreboard, other teams' source, private
  clarifications), authentication and authorization bypasses, and exposure of
  minors' data protected by the age-based privacy policy.
- **Out of scope:** installations that contradict the documentation (e.g.
  judging on the development stack or with `privileged: true`), bugs in
  third-party compilers and runtimes that do not escape the sandbox,
  volumetric denial of service against self-hosted instances, and unverified
  scanner output.
