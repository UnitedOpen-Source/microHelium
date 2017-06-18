@extends('layout')

@section('content')
	<div class="col-xs-12 col-sm-8">
		<h2>
			Editar producto
			<a href="{{ route('products.index') }}" class="btn btn-default pull-right">		Regresar
			</a>
		</h2>
		<hr>
		@include('products.partials.errors')
		{!! Form::model($product, ['route' => ['products.update', $product->id], 'method' => 'PUT']) !!}
			
			@include('products.partials.form')
			
		{!! Form::close() !!}
	</div>
	<div class="col-xs-12 col-sm-4">
		@include('products.partials.aside')
	</div>
@endsection