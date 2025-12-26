@extends('layouts.app')

@section('title', $exercise->exerciseName ?? 'Problema')

@section('content')
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="header">
                <div class="row">
                    <div class="col-md-8">
                        <h4 class="title">
                            <span class="label label-primary" style="font-size: 16px; margin-right: 10px;">
                                {{ request()->route('id') ? chr(65 + request()->route('id') - 1) : 'A' }}
                            </span>
                            {{ $exercise->exerciseName }}
                        </h4>
                        <p class="category">{{ $exercise->category ?? 'Geral' }}</p>
                    </div>
                    <div class="col-md-4 text-right">
                        @if(($exercise->difficulty ?? 'medium') == 'easy')
                            <span class="label label-success">Facil</span>
                        @elseif(($exercise->difficulty ?? 'medium') == 'medium')
                            <span class="label label-warning">Medio</span>
                        @else
                            <span class="label label-danger">Dificil</span>
                        @endif
                        <span class="label label-info">{{ $exercise->score ?? 100 }} pts</span>
                    </div>
                </div>
            </div>
            <div class="content">
                <!-- Problem Description -->
                <div class="problem-section">
                    <h5><strong>Descricao</strong></h5>
                    <div style="text-align: justify; line-height: 1.8;">
                        {!! nl2br(e($exercise->description ?? 'Sem descricao disponivel.')) !!}
                    </div>
                </div>

                <hr>

                <!-- Input Format -->
                <div class="problem-section">
                    <h5><strong>Entrada</strong></h5>
                    <div style="text-align: justify; line-height: 1.8;">
                        {!! nl2br(e($exercise->input_format ?? 'Formato de entrada nao especificado.')) !!}
                    </div>
                </div>

                <hr>

                <!-- Output Format -->
                <div class="problem-section">
                    <h5><strong>Saida</strong></h5>
                    <div style="text-align: justify; line-height: 1.8;">
                        {!! nl2br(e($exercise->output_format ?? 'Formato de saida nao especificado.')) !!}
                    </div>
                </div>

                <hr>

                <!-- Examples -->
                <div class="problem-section">
                    <h5><strong>Exemplos</strong></h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="panel panel-default">
                                <div class="panel-heading"><strong>Entrada de Exemplo</strong></div>
                                <div class="panel-body">
                                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; margin: 0;">{{ $exercise->sample_input ?? '5\n3 1 4 1 5' }}</pre>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="panel panel-default">
                                <div class="panel-heading"><strong>Saida de Exemplo</strong></div>
                                <div class="panel-body">
                                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; margin: 0;">{{ $exercise->sample_output ?? $exercise->expectedOutcome ?? '14' }}</pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                @if($exercise->notes ?? false)
                <hr>
                <div class="problem-section">
                    <h5><strong>Notas</strong></h5>
                    <div class="alert alert-info">
                        {!! nl2br(e($exercise->notes)) !!}
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <!-- Submit Button -->
        <div class="card">
            <div class="content text-center">
                <a href="/submit/{{ $exercise->exercise_id }}" class="btn btn-success btn-fill btn-lg">
                    <i class="pe-7s-upload"></i> Submeter Solucao
                </a>
            </div>
        </div>

        <!-- Limits -->
        <div class="card">
            <div class="header">
                <h4 class="title"><i class="pe-7s-config"></i> Limites</h4>
            </div>
            <div class="content">
                <table class="table">
                    <tr>
                        <td><strong>Tempo Limite</strong></td>
                        <td>{{ $exercise->time_limit ?? 10 }} segundos</td>
                    </tr>
                    <tr>
                        <td><strong>Memoria</strong></td>
                        <td>{{ $exercise->memory_limit ?? 512 }} MB</td>
                    </tr>
                    <tr>
                        <td><strong>Pontos</strong></td>
                        <td>{{ $exercise->score ?? 100 }} pts</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Languages -->
        <div class="card">
            <div class="header">
                <h4 class="title"><i class="pe-7s-tools"></i> Linguagens Aceitas</h4>
            </div>
            <div class="content">
                <span class="label label-info" style="margin: 2px;">C</span>
                <span class="label label-primary" style="margin: 2px;">C++</span>
                <span class="label label-warning" style="margin: 2px;">Java</span>
                <span class="label label-success" style="margin: 2px;">Python 3</span>
            </div>
        </div>

        <!-- Navigation -->
        <div class="card">
            <div class="content">
                <a href="/exercises" class="btn btn-default btn-block">
                    <i class="pe-7s-back"></i> Voltar para Lista
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
