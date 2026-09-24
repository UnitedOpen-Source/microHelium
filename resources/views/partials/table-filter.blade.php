<div class="filter-toolbar" data-table-filter="{{ $tableId }}">
    <label>{{ __('Buscar :what', ['what' => $searchLabel ?? __('registros')]) }}<input type="search" placeholder="{{ $placeholder ?? __('Digite para filtrar…') }}" autocomplete="off"></label>
    @if($difficulty ?? false)
    <label>{{ __('Dificuldade') }}<select><option value="">{{ __('Todas as dificuldades') }}</option><option value="easy">{{ __('Fácil') }}</option><option value="medium">{{ __('Médio') }}</option><option value="hard">{{ __('Difícil') }}</option></select></label>
    @endif
    <button type="button" class="button-secondary" data-filter-clear>{{ __('Limpar') }}</button>
    <p role="status" class="text-xs text-muted-foreground pb-3"></p>
</div>
