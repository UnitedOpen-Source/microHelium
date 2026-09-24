@php
    $verdictCode = strtoupper($code ?? '');
    $verdictMap = [
        'AC' => ['success', 'Aceito', '✓'],
        'WA' => ['destructive', 'Resposta incorreta', '×'],
        'TLE' => ['warning', 'Tempo excedido', '!'],
        'MLE' => ['warning', 'Memória excedida', '!'],
        'CE' => ['destructive', 'Erro de compilação', '×'],
        'RE' => ['destructive', 'Erro de execução', '×'],
        'RTE' => ['destructive', 'Erro de execução', '×'],
        'PE' => ['warning', 'Erro de apresentação', '!'],
        'CS' => ['info', 'Consulte a organização', 'i'],
    ];
    $verdict = $verdictMap[$verdictCode] ?? ['neutral', $verdictCode && $verdictCode !== 'PENDING' ? $verdictCode : (($status ?? '') === 'judging' ? 'Em avaliação' : 'Na fila'), '·'];
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
