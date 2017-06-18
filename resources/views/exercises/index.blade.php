@extends('layouts.app')

@section('content')
<div class="row">
  <div class="col-md-6">
    <div class="card">
      <div class="header">
        <h4 class="title">Lista de Exercicios</h4>
        <p class="category">Qualquer dúvida estamos a disposição</p>
      </div>
      <div class="content table-responsive table-full-width">
        <table class="table table-hover table-striped">
          <thead>
            <th>ID</th>
            <th>Descrição</th>
            <th>Categoria</th>
            <th>Valor</th>
            <th>#</th>
          </thead>
          <tbody>
            @foreach ($exercises as $exercise)
            <tr>
              <td>{{ $exercise->id }}</td>
              <td>{{ $exercise->exerciseName }}</td>
              <td>{{ $exercise->category }}</td>
              <td>{{ $exercise->score }} pts</td>
              <td><a href="./exercise/{{ $exercise->id }}">Ir para exercicio</a></td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card">
      <div class="header">
        <h4 class="title">Dúvidas?</h4>
        <br/>
      </div>
    </div>
  </div>
</div>
@endsection
