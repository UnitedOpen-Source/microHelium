@extends('layouts.app')
@section('content')
<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="header">
                <h4 class="title">Placar</h4>
            </div>
            <div class="content table-responsive table-full-width">
                <table class="table table-hover table-striped">
                    <thead>
                      <th>ID</th>
                      <th>Equipe</th>
                      <th>Pontuação</th>
                    </thead>
                    <tbody>
                      @foreach ($teams as $team)
                        <tr>
                          <td>{{ $team->id }}</td>
                          <td>{{ $team->teamName }}</td>
                          <td>{{ $team->score }} pts</td>
                        </tr>
                      @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-8">
      <div class="card">
        <div class="header">
          <h4 class="title">Dúvidas?</h4>
          <br/>
        </div>
      </div>
    </div>
</div>
@endsection
