# 225 — Os atalhos legados faziam o oposto do que prometiam

## Medido antes de consertar

Uma prova congelada, com um solve **dentro** da janela:

```
[1] congelado? true    placar mostra o solve? false
[2] POST /contest/freeze  -> freeze_time=0   congelado? false   mostra? TRUE
[3] POST /contest/end     -> is_active=false congelado? false   mostra? TRUE
[4] preflight de prova que não começou: []
```

**Os dois botões publicavam a classificação.** O primeiro chamava-se *"Placar congelado!"*.

## Por que ninguém tinha notado

Os dois atalhos escreviam **direto na tabela** com `DB::table(...)->update(...)`, sem passar pelo modelo. Nenhuma das regras que o #189 instalou em `Contest::isFrozen()` era consultada no caminho da escrita — só na leitura, onde o estrago aparecia.

E os testes E2E **fixavam o defeito como expectativa**:

```php
// Verify scoreboard is frozen (freeze_time set to 0)
$this->assertDatabaseHas('contests', ['id' => $contestId, 'freeze_time' => 0]);
```

O comentário diz *"frozen"* sobre o valor que significa **sem congelamento**. É a forma mais pura do modo de falha que este repositório já catalogou: um teste verde contra um mecanismo que não pode funcionar.

## Os três consertos

### 1. Congelar agora não pode usar zero

`freeze_time` é *"minutos antes do fim"*, então congelar neste instante é uma janela do tamanho do que falta: `max(1, minutos restantes)`.

O `max(1, ...)` cobre a prova que já acabou — qualquer janela positiva mantém o placar congelado depois do término, porque o #189 fez o congelamento sobreviver ao fim, que é a cerimônia inteira.

### 2. Encerrar não é desativar

Encurta a **duração** em vez de gravar `is_active = false`. Isso flui por todo consumidor do relógio que já existe — `isRunning()`, `end_time`, o corte do congelamento, a extensão por sede do #198 — sem coluna nova e sem um segundo conceito de *"quando acabou"*.

O contest **continua ativo**: ele é o evento corrente até alguém trocar. Terminar e revelar são decisões separadas, e é por isso que existem dois botões.

A duração é medida descontando os intervalos removidos (#198): somar de volta os minutos descontados faria a prova *"acabar"* depois do instante em que se pediu que ela acabasse — e ela continuaria aceitando envios.

### 3. `is_active` saiu de `isFrozen()`

Este é o conserto fundo, e o que fecha a porta para sempre. Enquanto `is_active` estava naquela condição, **desativar por qualquer caminho publicava a classificação** — o atalho de encerrar era só uma das formas de chegar lá.

Estar ativo é *"este é o evento corrente"*, e não *"a classificação já foi liberada"*. A única coisa que termina um congelamento é alguém revelar, e isso é `unfrozen_at` (#189).

### 4. Preflight sem início

Uma prova que **não começou** passava no preflight sem nenhum impedimento: `isRunning()` é falso para ela, e sem envio, sem erro de julgamento e sem clarificação pendente — o estado natural de quem não aconteceu — a lista saía vazia.

Finalizar é a organização **afirmando** que não sobrou nada pendente que pudesse mudar a classificação. De uma prova que não começou, sobra tudo.

## A sequência que a issue pede

Fixada por teste, em ordem: **congelar → terminar → placar ainda oculto → revelar → finalizar.** Finalizar antes de revelar continua recusado pelo `still_frozen` do #202.

## Onde as regras moram agora

Num serviço, e não em closures na rota. A issue pede as mesmas regras nos caminhos web e API, e **duas cópias de uma regra sobre publicar classificação é como uma delas fica para trás**. A competição vem do scope `competition()` e não do primeiro `is_active` da tabela: o contest de treino do #43 nunca é "o evento".

Ator e motivo vão para o log de contest em nível `warning`, nos dois caminhos.
