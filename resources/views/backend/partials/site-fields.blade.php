@php
    $isEdit = $site !== null;
@endphp
<div class="p-6 space-y-4">
    <div>
        <label for="{{ $idPrefix }}_name" class="block text-sm font-medium text-foreground mb-1">Nome do site</label>
        <input id="{{ $idPrefix }}_name" type="text" name="name" value="{{ old('name', $site->name ?? '') }}" maxlength="100" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Ex: Sede Central, Laboratorio 2" required>
    </div>
    <div>
        <label for="{{ $idPrefix }}_ip_address" class="block text-sm font-medium text-foreground mb-1">IP / rede (opcional)</label>
        <input id="{{ $idPrefix }}_ip_address" type="text" name="ip_address" value="{{ old('ip_address', $site->ip_address ?? '') }}" maxlength="200" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
    </div>
    <div>
        <label for="{{ $idPrefix }}_chief_judge_name" class="block text-sm font-medium text-foreground mb-1">Juiz responsavel (opcional)</label>
        <input id="{{ $idPrefix }}_chief_judge_name" type="text" name="chief_judge_name" value="{{ old('chief_judge_name', $site->chief_judge_name ?? '') }}" maxlength="50" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
    </div>
    <div>
        <label for="{{ $idPrefix }}_score_visibility" class="block text-sm font-medium text-foreground mb-1">Placar visto pelos times deste site</label>
        <select id="{{ $idPrefix }}_score_visibility" name="score_visibility" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
            <option value="all" @selected(old('score_visibility', $site->score_visibility ?? 'all') === 'all')>Todos os sites</option>
            <option value="own_site" @selected(old('score_visibility', $site->score_visibility ?? 'all') === 'own_site')>Apenas este site</option>
        </select>
    </div>
    <div>
        <label for="{{ $idPrefix }}_max_judge_wait_time" class="block text-sm font-medium text-foreground mb-1">Tempo max. de espera para julgamento (minutos)</label>
        <input id="{{ $idPrefix }}_max_judge_wait_time_display" type="number" min="1" value="{{ intdiv((int) old('max_judge_wait_time', $site->max_judge_wait_time ?? 900), 60) }}" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" oninput="document.getElementById('{{ $idPrefix }}_max_judge_wait_time').value = this.value * 60">
        <input type="hidden" id="{{ $idPrefix }}_max_judge_wait_time" name="max_judge_wait_time" value="{{ old('max_judge_wait_time', $site->max_judge_wait_time ?? 900) }}">
        <p class="text-sm text-muted-foreground mt-1">Runs pendentes por mais tempo que isso aparecem marcados como atrasados na tela de julgamento.</p>
    </div>
    @if($isEdit)
    <div>
        <label class="flex items-center gap-2">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $site->is_active)) class="rounded border-border">
            <span class="text-sm text-foreground">Site ativo</span>
        </label>
    </div>
    @endif
    @php
        $otherSites = $sites->reject(fn ($s) => $isEdit && $s->id === $site->id);
        $selectedRoutes = old('judging_routes', $isEdit ? $site->judgingRoutes->pluck('source_site_id')->all() : []);
    @endphp
    @if($otherSites->isNotEmpty())
    <div>
        <p id="{{ $idPrefix }}_routes_hint" class="block text-sm font-medium text-foreground mb-1">Este site também julga runs dos sites:</p>
        <div role="group" aria-describedby="{{ $idPrefix }}_routes_hint" class="space-y-2 max-h-40 overflow-y-auto">
            @foreach($otherSites as $other)
            <label class="flex items-center gap-2 p-2 border border-border rounded-lg hover:bg-muted/50 cursor-pointer">
                <input type="checkbox" name="judging_routes[]" value="{{ $other->id }}" @checked(in_array($other->id, $selectedRoutes)) class="rounded border-border">
                <span class="text-sm">{{ $other->name }}</span>
            </label>
            @endforeach
        </div>
        <p class="text-sm text-muted-foreground mt-1">Deixe tudo desmarcado para que este site julgue apenas suas proprias submissoes.</p>
    </div>
    @endif
</div>
