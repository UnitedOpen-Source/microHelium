<div class="form-group">
	{!! Form::label('teamname', 'Nome da Equipe') !!}
	{!! Form::text('teamname', null, ['class' => 'form-control']) !!}
</div>
<div class="form-group">
	{!! Form::label('username', 'Nome de Usuário') !!}
	{!! Form::text('username', null, ['class' => 'form-control']) !!}
</div>
<div class="form-group">
	{!! Form::label('name', 'Nome Completo) !!}
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
