@extends('layouts.app')
@section('content')
<div class="row">
    <div class="col-md-12">
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
                        <tr>
                          <td>1</td>
                          <td>Hackerz Inc.</td>
                          <td>2550 pts</td>
                        </tr>
                        <tr>
                          <td>2</td>
                          <td>CodeCon</td>
                          <td>2400 pts</td>
                        </tr>
                        <tr>
                          <td>3</td>
                          <td>PyPy</td>
                          <td>2300 pts</td>
                        </tr>
                        <tr>
                          <td>4</td>
                          <td>Javeiros</td>
                          <td>1800 pts</td>
                        </tr>
                        <tr>
                          <td>5</td>
                          <td>WannaCry</td>
                          <td>1700 pts</td>
                        </tr>
                    </tbody>
                </table>

            </div>
        </div>
    </div>
</div>
@endsection
