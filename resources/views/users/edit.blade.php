@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="header">
                <h4 class="title">Editar</h4>
            </div>
            <div class="content">
							{!! Form::model($user, ['route' => ['users.update', $user->id], 'method' => 'PUT']) !!}

              <div class="form-group">
                {!! Form::label('teamname', 'Nome da Equipe') !!}
                {!! Form::text('teamname', null, ['class' => 'form-control']) !!}
              </div>
              <div class="form-group">
                {!! Form::label('username', 'Nome de Usuário') !!}
                {!! Form::text('username', null, ['class' => 'form-control']) !!}
              </div>
              <div class="form-group">
                {!! Form::label('name', 'Nome Completo') !!}
                {!! Form::text('name', null, ['class' => 'form-control']) !!}
              </div>
              <div class="form-group">
                {!! Form::label('birthday', 'Data de Nascimento') !!}
                {!! Form::text('birthday', null, ['class' => 'form-control']) !!}
              </div>
              <div class="form-group">
                {!! Form::label('short', 'Aqui você pode adicionar uma breve descrição...') !!}
                {!! Form::textarea('short', null, ['class' => 'form-control']) !!}
              </div>
              <div class="form-group">
                {!! Form::submit('ENVIAR', ['class' => 'btn btn-info btn-fill pull-right']) !!}
              </div>
              <div class="clearfix"></div>

							{!! Form::close() !!}
            </div>
        </div>
    </div>
</div>
@endsection
