# Manuais do microHelium

Um manual por papel. Cada um assume que você **não leu os outros** e descreve
só o que aquele papel faz.

| Manual | Para quem | Papel no sistema |
|---|---|---|
| [**Participante**](participante.md) | quem vai competir | `team` |
| [**Organizador**](organizador.md) | quem monta e conduz o evento | `admin` |
| [**Juiz**](juiz.md) | quem responde pelo julgamento técnico | `judge` |
| [**Staff e sede**](staff.md) | quem faz a prova acontecer na sala | `staff`, `site` |

## Por onde começar

**Vai competir?** [Participante](participante.md) — e faça o roteiro da
seção 5 antes do dia da prova.

**Vai organizar um evento pela primeira vez?**
[Organizador](organizador.md), na ordem: seção 2 (montar), seção 3 (conduzir),
seção 7 (checklist). Depois leia os outros três para saber o que você está
delegando.

**Vai julgar?** [Juiz](juiz.md) — as seções 4 (pausar) e 5 (rejulgamento) são
as que você vai precisar sob pressão, então leia antes.

**Vai atender equipes?** [Staff](staff.md) — a seção 1 (clarificação × S.O.S.)
e a seção 5 (os quatro motivos de login) resolvem a maior parte dos chamados.

## O que cada papel alcança

```
admin  →  /backend/*  +  /judge/*  +  /staff/*  +  /site/*
judge  →  /judge/*
staff  →  /staff/*
site   →  /site/*        (só a sede dele)
team   →  superfície de competidor
```

Dê sempre o menor papel que resolve.

## Se algo aqui estiver errado

Estes manuais descrevem o comportamento do sistema, não a intenção dele. Se a
tela fizer diferente do que está escrito, **o manual está errado** — abra uma
issue.

Para o porquê das decisões por trás de cada comportamento, veja
[`docs/specs/`](../specs/); para a arquitetura, o
[índice da documentação](../README.md).
