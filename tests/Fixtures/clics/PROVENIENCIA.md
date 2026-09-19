# De onde vieram estes arquivos

`json-schema/` é **cópia literal** de
<https://github.com/icpc/ccs-specs/tree/2023-06/json-schema>, 37 arquivos, 33.506 bytes.

Baixado em 19/09/2026 com:

```sh
curl -sL -o ccs-specs.tar.gz \
  https://codeload.github.com/icpc/ccs-specs/tar.gz/refs/heads/2023-06
tar -xzf ccs-specs.tar.gz --strip-components=1 -C /tmp/ccs
cp /tmp/ccs/json-schema/*.json tests/Fixtures/clics/json-schema/
```

## Por que `2023-06` e não `2026-01`

Porque é a versão que **nós dizemos implementar**. `ContestApiController::index()` —
o endpoint `api`, um dos dois obrigatórios da spec — responde:

```php
'version' => '2023-06',
'version_url' => 'https://ccs-specs.icpc.io/2023-06/contest_api',
```

O docblock de `ClicsPresenter` aponta para `2026-01`, que é outra versão. Os dois
apontam para o mesmo lugar nos pontos que este diretório verifica: `required`, `enum`
e o formato da linha do event feed são idênticos entre `2023-06` e `2026-01`
(conferido arquivo a arquivo). Quando deixarem de ser, é este arquivo que diz contra
o que a suíte está medindo.

Conferir se a cópia está desatualizada é um `diff` contra o branch:

```sh
diff -r tests/Fixtures/clics/json-schema /tmp/ccs/json-schema
```

## Por que vendorizar em vez de baixar no teste

Um teste que baixa a spec na hora de rodar é um teste que **fica vermelho quando a
rede cai** e, pior, que muda de resultado sem ninguém ter mudado uma linha de código.
A cópia congela a referência; atualizá-la é um commit, com diff revisável.
