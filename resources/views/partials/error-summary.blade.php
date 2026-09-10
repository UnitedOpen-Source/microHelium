@if($errors->any())
    <div data-error-summary role="alert" tabindex="-1" class="error-summary" aria-labelledby="error-summary-title">
        <h2 id="error-summary-title" class="font-semibold">Revise os campos para continuar</h2>
        <p class="mt-1 text-sm">Não foi possível concluir. Corrija os campos indicados e tente novamente.</p>
        <ul class="mt-3 list-disc pl-5 space-y-1 text-sm">
            @foreach($errors->messages() as $field => $messages)
                @foreach($messages as $message)<li data-error-field="{{ $field }}">{{ $message }}</li>@endforeach
            @endforeach
        </ul>
    </div>
@endif
