{{-- Issue #397 -- o catalogo que o t() de resources/js/i18n.js le. JSON nao
     e executado, entao a CSP nao o recusa; o nonce vai mesmo assim, para que
     uma auditoria de "todo <script> tem nonce" nao precise de excecao. @json
     escapa <, >, & e aspas, entao um texto traduzido nao fecha a tag. --}}
<script type="application/json" id="i18n-catalog" @cspNonce>@json((object) \App\Support\InterfaceLocale::frontendCatalog())</script>
