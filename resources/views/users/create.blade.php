@extends('layout.app')

@section('content')
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="header">
                <h4 class="title">Criar</h4>
            </div>
            <div class="content">
							@include('users.partials.errors')
              {!! Form::open(['route' => 'users.store']) !!}

          			@include('users.partials.form')

          		{!! Form::close() !!}
            </div>
        </div>
    </div>
</div>
@endsection
