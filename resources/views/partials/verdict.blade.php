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
<span class="verdict-badge {{ $verdictStyles[$verdict[0]] }}" @if($compact ?? false) aria-label="{{ $verdict[1] }}" title="{{ $verdict[1] }}" @endif>
    <span aria-hidden="true">{{ $verdict[2] }}</span>
    {{ ($compact ?? false) && isset($verdictMap[$verdictCode]) ? $verdictCode : $verdict[1] }}
</span>
