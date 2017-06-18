@extends('layouts.app')

@section('content')
<div class="row">
  <div class="col-md-12">
    <div class="card">
      <div class="header">
        <h4 class="title">Usuários</h4>
        <p class="category">Descrição</p>
      </div>
      <div class="content table-responsive table-full-width">
        <table class="table table-hover table-striped">
          <thead>
            <th>ID</th>
            <th>Nome Completo</th>
            <th>Username</th>
            <th>Email</th>
            <th>#</th>
          </thead>
          <tbody>
            @foreach ($users as $user)
            <tr>
              <td>{{ $user->id }}</td>
              <td>{{ $user->name }}</td>
              <td>{{ $user->username }}</td>
              <td>{{ $user->email }}</td>
              <td><a href="{{ route('users.edit', $user->id) }}">Editar</a> | <a href="{{ route('users.destroy', $user->id) }}">Deletar</a></td>
            </tr>
            @endforeach
          </tbody>
        </table>
        {{ $users->links() }}
      </div>
    </div>
  </div>
</div>
@endsection
