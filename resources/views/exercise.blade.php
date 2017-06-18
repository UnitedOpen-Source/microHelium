@extends('layouts.app')

@section('content')
<div class="row">
  <div class="col-md-12">
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
              <td>{{ $exercise->name }}</td>
              <td>{{ $exercise->category }}</td>
              <td>{{ $exercise->score }}</td>
              <td><a href="exercicio.html">Ir para exercicio</a></td>
            </tr>
            @endforeach
            <tr>
              <td>2</td>
              <td>Exercicio 2</td>
              <td>Web</td>
              <td>20 pts</td>
              <td><a href="exercicio.html">Ir para exercicio</a></td>
            </tr>
            <tr>
              <td>3</td>
              <td>Exercicio 3</td>
              <td>Python</td>
              <td>20 pts</td>
              <td><a href="exercicio.html">Ir para exercicio</a></td>
            </tr>
            <tr>
              <td>4</td>
              <td>Exercicio 4</td>
              <td>Java</td>
              <td>20 pts</td>
              <td><a href="exercicio.html">Ir para exercicio</a></td>
            </tr>
            <tr>
              <td>5</td>
              <td>Exercicio 5</td>
              <td>Crypto</td>
              <td>20 pts</td>
              <td><a href="exercicio.html">Ir para exercicio</a></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
@endsection
