@extends('layout')

@section('content')
	<div class="col-xs-12 col-sm-8">
		<h2>
			<strong>#{{ $product->id }}</strong> {{ $product->name }}
			<a href="{{ route('products.index') }}" class="btn btn-default pull-right">		Regresar
			</a>
		</h2>
		<hr>
		<p>{{ $product->short }}</p>
		<p>{{ $product->body }}</p>

		<a href="{{ route('products.edit', $product->id) }}" class="btn btn-primary">
			Editar
		</a>
	</div>
	<div class="col-xs-12 col-sm-4">
		@include('products.partials.aside')
	</div>
@endsection