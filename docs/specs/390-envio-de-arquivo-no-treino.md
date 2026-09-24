# #390 (P2) — Envio de arquivo no Treino Livre, começando por `.sb3`

Parte de [#390](https://github.com/UnitedOpen-Source/microHelium/issues/390)
(BNCC Computação), proposta **P2**, que resolve a **lacuna 1** daquela issue:
"Scratch não entra no Treino Livre".

## O problema, conferido no código

O Scratch (#268) é julgável dentro de uma prova, mas não no Treino Livre (#43),
que é onde o uso escolar aconteceria. O envio de prática recusa um `.sb3` por
três motivos, todos por construção:

1. `app/Http/Controllers/FrontendApi/PracticeController.php`, `storeRun()`:
   `source` é validado como `['required', 'string']` — não existe campo de
   arquivo — e em seguida `mb_check_encoding($source, 'UTF-8')` recusa com
   422 tudo que não for UTF-8 válido. Um `.sb3` é um ZIP.
2. `resources/js/features/api.js`, `request()`: o corpo é sempre
   `JSON.stringify(body)` com `Content-Type: application/json`. Bytes
   arbitrários não sobrevivem a JSON.
3. `resources/js/features/Practice.vue`: a única entrada de fonte é um
   `<textarea>`.

A spec #43 deixou isso como decisão de fase ("Fonte pelo editor, sem upload de
arquivo nesta fase"), e a #268 registrou a consequência em "Fora de escopo".

## Decisão

**Arquivo só para linguagem cuja fonte é arquivo.** A linguagem decide o canal,
não o participante:

| Linguagem | Campo aceito | Campo recusado (422) |
|---|---|---|
| fonte-arquivo (hoje: Scratch, `file_ext = sb3`) | `source_file` (multipart) | `source` |
| todas as outras | `source` (texto, como antes) | `source_file` |

Por que não abrir upload para todas: a tarefa é trazer o Scratch para o treino,
não mudar o contrato das linguagens de texto. Para elas nada relaxa — e uma
regra fica mais estrita (abaixo).

Quem é fonte-arquivo é dito num lugar só, `Language::receivesSourceAsFile()`,
derivado da extensão real do arquivo (`getFileExtension()`), e não do id da
variante: o `extension` do catálogo às vezes é id de compilador (`c_gcc13`).

## Contrato

`GET /api/frontend/practice/problems/{id}` — cada item de `languages` ganha:

```json
{ "id": 7, "name": "Scratch", "source_kind": "file", "accept": ".sb3" }
{ "id": 1, "name": "C",       "source_kind": "text", "accept": null }
```

`POST /api/frontend/practice/problems/{id}/runs`

- Texto: inalterado, `{language_id, source}` em JSON.
- Arquivo: `multipart/form-data` com `language_id` e `source_file`.
- Mesmo `Idempotency-Key`, mesmo 202 `{data:{id,status:"pending"}}`, mesmos
  403/409/503.

## Reuso, e não um segundo caminho

- **Limite de tamanho**: o mesmo número que o texto do treino já usa,
  `Contest::defaultMaxSourceKb()` (#284: o treino não tem prova, então é o
  padrão da instalação), aplicado com a mesma regra do envio de prova —
  `file|max:<KB>` do Laravel, a que `SubmitController` e `Api\RunController`
  usam.
- **Nome do arquivo**: `RunSubmissionService::filenameFor($language, $nomeDoCliente)`
  — basename saneado, extensão SEMPRE a da linguagem (`.sb3`), nunca a
  que o cliente mandou (#311).
- **Gravação e julgamento**: `RunSubmissionService::submit()`, o mesmo que
  cria o Run da prova. Os bytes vão para o disco sem transformação, e o
  `JudgeRunJob` roda `scratch-run --check` (CE para projeto inválido) e
  `scratch-run` — exatamente o caminho da #268.
- **"É texto?"**: a pergunta que `SubmissionController` já fazia para decidir
  entre renderizar e baixar o fonte (#268) sai para `App\Support\SourceText::isText()`
  e passa a ser usada também na validação do treino.

## A regra que fica mais estrita

Antes, uma linguagem de texto recusava só UTF-8 inválido. Um ZIP **sem
compressão** cujo conteúdo é ASCII passa em `mb_check_encoding` — o cabeçalho
`PK\x03\x04\x14\x00...` tem byte nulo, e NUL é UTF-8 válido. Com
`SourceText::isText()` (UTF-8 válido **e** sem `\0`) o binário numa linguagem
de texto é recusado também por esse caminho. Nenhum fonte legítimo de C,
Python etc. tem byte nulo.

## Idempotência com arquivo

`IdempotencyStore` fazia hash de `json_encode($request->all())`. Um
`UploadedFile` vira `{}` em JSON — dois arquivos diferentes sob a mesma chave
teriam o mesmo hash, e o segundo receberia de volta o 202 do primeiro sem ser
enviado. O arquivo passa a entrar no hash pelo seu `sha256`, então "mesma
chave, arquivo diferente" é 409, como para qualquer payload diferente.

No cliente, `act()` monta a assinatura que decide reusar a chave; com
`FormData`, entra nome, tamanho e `lastModified` do arquivo, para que a
repetição após falha de rede reuse a chave e um arquivo novo gere outra.

## Interface

Selecionar uma linguagem fonte-arquivo troca o `<textarea>` por
`<input type="file" name="source_file" accept=".sb3">`, com o limite em bytes
no texto de ajuda e verificação de tamanho no cliente antes de enviar (o
servidor continua sendo quem decide). Trocar de volta para uma linguagem de
texto devolve o editor com o código que estava nele.

## Casos de teste

1. `.sb3` aceito numa linguagem Scratch: 202, um Run, `filename` saneado e
   terminado em `.sb3` mesmo que o cliente mande `.zip`, bytes gravados idênticos aos
   enviados, `JudgeRunJob` despachado para esse Run.
2. Arquivo acima de `Contest::defaultMaxSourceKb()` recusado com 422 em
   `source_file`, sem Run.
3. Scratch sem arquivo (ou com `source` de texto) recusado com 422.
4. Binário numa linguagem de texto recusado: como `source_file` (422) e como
   `source` com byte nulo ou UTF-8 inválido (422). Nenhum Run.
5. Mesma chave de idempotência com outro arquivo: 409, um Run só.
6. O detalhe do problema anuncia `source_kind`/`accept` por linguagem.
7. Interface: com Scratch selecionado aparece o campo de arquivo com
   `accept=".sb3"` e o envio vai como `FormData`; com C continua o editor.

## Fora de escopo

- O editor Scratch em si (#267, proposta P4 da #390).
- Upload de arquivo para linguagens de texto.
- Similaridade para Scratch (P8 da #390).
- Qualquer mudança no envio de prova, na imagem do juiz ou no CI.
