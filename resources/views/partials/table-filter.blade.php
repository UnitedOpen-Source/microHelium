<div class="filter-toolbar" data-table-filter="{{ $tableId }}">
    <label>Buscar {{ $searchLabel ?? 'registros' }}<input type="search" placeholder="{{ $placeholder ?? 'Digite para filtrar…' }}" autocomplete="off"></label>
    @if($difficulty ?? false)
    <label>Dificuldade<select><option value="">Todas as dificuldades</option><option value="easy">Fácil</option><option value="medium">Médio</option><option value="hard">Difícil</option></select></label>
    @endif
    <button type="button" class="button-secondary" data-filter-clear>Limpar</button>
    <p role="status" class="text-xs text-muted-foreground pb-3"></p>
</div>
