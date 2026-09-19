@php
    $submitted = old('_form_key') === $idPrefix;
    $value = fn ($key, $default = null) => $submitted ? old($key, $default) : $default;
    $isEdit = $language !== null;
@endphp
<input type="hidden" name="_form_key" value="{{ $idPrefix }}">
<div class="p-6 space-y-4">
    <div>
        <label for="{{ $idPrefix }}_name" class="block text-sm font-medium text-foreground mb-1">Nome da linguagem</label>
        <input id="{{ $idPrefix }}_name" type="text" name="name" value="{{ $value('name', $language->name ?? '') }}" maxlength="50" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Ex: C++ (G++ 15)" required>
        <p class="text-sm text-muted-foreground mt-1">É o nome que a equipe vê na tela de envio. Único dentro desta competição.</p>
    </div>
    <div>
        <label for="{{ $idPrefix }}_extension" class="block text-sm font-medium text-foreground mb-1">Identificador (extensão)</label>
        <input id="{{ $idPrefix }}_extension" type="text" name="extension" value="{{ $value('extension', $language->extension ?? '') }}" maxlength="20" pattern="[A-Za-z0-9_]+" aria-describedby="{{ $idPrefix }}_extension_hint" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground font-mono focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Ex: cpp_gpp13" required>
        <p id="{{ $idPrefix }}_extension_hint" class="text-sm text-muted-foreground mt-1">Apenas letras, números e underscore. É também o nome usado pelos scripts <code>compile/</code> e <code>run/</code> do pacote do problema, quando existirem. Único dentro desta competição.</p>
    </div>

    {{-- The commands below become the shell command line the judge runs.
         Documenting the placeholders here is not decoration: the list comes
         from AutoJudgeService, and getting one wrong (using {memory} at
         compile time, for instance) produces a compilation error for every
         submission in the language, mid-contest. --}}
    <div>
        <label for="{{ $idPrefix }}_compile_command" class="block text-sm font-medium text-foreground mb-1">Comando de compilação</label>
        <textarea id="{{ $idPrefix }}_compile_command" name="compile_command" rows="2" maxlength="2000" aria-describedby="{{ $idPrefix }}_compile_hint" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground font-mono text-sm focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Ex: g++ -static -O2 -std=c++20 -o {output} {source}" required>{{ $value('compile_command', $language->compile_command ?? '') }}</textarea>
        <div id="{{ $idPrefix }}_compile_hint" class="text-sm text-muted-foreground mt-1 space-y-1">
            <p>Linguagens interpretadas usam aqui uma verificação de sintaxe (ex: <code>php -l {source}</code>). Substituições disponíveis:</p>
            <ul class="list-disc pl-5">
                @foreach($compilePlaceholders as $placeholder => $meaning)
                <li><code>{{ $placeholder }}</code> — {{ $meaning }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    <div>
        <label for="{{ $idPrefix }}_run_command" class="block text-sm font-medium text-foreground mb-1">Comando de execução</label>
        <textarea id="{{ $idPrefix }}_run_command" name="run_command" rows="2" maxlength="2000" aria-describedby="{{ $idPrefix }}_run_hint" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground font-mono text-sm focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Ex: ./{executable}" required>{{ $value('run_command', $language->run_command ?? '') }}</textarea>
        <div id="{{ $idPrefix }}_run_hint" class="text-sm text-muted-foreground mt-1 space-y-1">
            <p>Substituições disponíveis (as de compilação <strong>não</strong> valem aqui):</p>
            <ul class="list-disc pl-5">
                @foreach($runPlaceholders as $placeholder => $meaning)
                <li><code>{{ $placeholder }}</code> — {{ $meaning }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    <div>
        <label class="flex items-center gap-2">
            <input type="checkbox" name="is_active" value="1" @checked($value('is_active', $submitted ? false : ($isEdit ? $language->is_active : true))) class="rounded border-border">
            <span class="text-sm text-foreground">Linguagem ativa (disponível para envio)</span>
        </label>
        <p class="text-sm text-muted-foreground mt-1">Desative para impedir novos envios sem afetar as submissões já feitas — é o que fazer quando a máquina do judge não tem o compilador.</p>
    </div>

    <div class="rounded-lg border border-border bg-muted/40 p-3">
        <p class="text-sm text-foreground"><strong>Atenção:</strong> estes dois campos viram linha de comando executada pelas máquinas de julgamento. Confira o comando antes de salvar; toda alteração fica registrada no <a href="{{ route('backend.logs') }}" class="text-primary hover:underline">log da competição</a> com o seu usuário.</p>
    </div>
</div>
