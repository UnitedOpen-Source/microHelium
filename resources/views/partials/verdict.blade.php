@php
    $verdictCode = strtoupper($code ?? '');
    $verdictMap = [
        'AC' => ['success', __('Aceito'), '✓'],
        'WA' => ['destructive', __('Resposta incorreta'), '×'],
        'TLE' => ['warning', __('Tempo excedido'), '!'],
        'MLE' => ['warning', __('Memória excedida'), '!'],
        'CE' => ['destructive', __('Erro de compilação'), '×'],
        'RE' => ['destructive', __('Erro de execução'), '×'],
        'RTE' => ['destructive', __('Erro de execução'), '×'],
        'PE' => ['warning', __('Erro de apresentação'), '!'],
        'CS' => ['info', __('Consulte a organização'), 'i'],
    ];
    $verdict = $verdictMap[$verdictCode] ?? ['neutral', $verdictCode && $verdictCode !== 'PENDING' ? $verdictCode : (($status ?? '') === 'judging' ? __('Em avaliação') : __('Na fila')), '·'];
    $verdictStyles = ['success' => 'bg-success-soft text-success', 'destructive' => 'bg-destructive-soft text-destructive', 'warning' => 'bg-warning-soft text-warning', 'info' => 'bg-info-soft text-info', 'neutral' => 'bg-muted text-muted-foreground'];
@endphp
{{-- Issue #394: the compact badge shows the short code ("AC") and used to
     name itself through aria-label on a plain <span>. ARIA 1.2 prohibits a
     name on a generic element, so assistive technology may ignore it and
     read only "AC" (axe: aria-prohibited-attr). The full name is now real text,
     visually hidden, and the code is hidden from assistive technology. --}}
<span class="verdict-badge {{ $verdictStyles[$verdict[0]] }}" @if($compact ?? false) title="{{ $verdict[1] }}" @endif>
    <span aria-hidden="true">{{ $verdict[2] }}</span>
    @if(($compact ?? false) && isset($verdictMap[$verdictCode]))
        <span aria-hidden="true">{{ $verdictCode }}</span><span class="sr-only">{{ $verdict[1] }}</span>
    @else
        {{ $verdict[1] }}
    @endif
</span>
