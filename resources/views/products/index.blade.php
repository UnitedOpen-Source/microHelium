@extends('layout')

@section('content')
	<div class="col-xs-12 col-sm-8">
		<h2>
			Lista de productos
			<a href="{{ route('products.create') }}" class="btn btn-primary pull-right">Nuevo</a>
		</h2>
		<hr>
		@include('products.partials.info')
		<table class="table table-hover table-striped">
			<thead>
				<tr>
					<th width="20px">ID</th>
					<th>Nombre del producto</th>
					<th colspan="3">&nbsp;</th>
				</tr>
			</thead>
			<tbody>
				@foreach($products as $product)
				<tr>
					<td>{{ $product->id }}</td>
					<td>
						<strong>{{ $product->name }}</strong>
						{{ $product->short }}
					</td>
					<td width="20px">
						<a href="{{ route('products.show', $product->id) }}" class="btn btn-link">
							Ver
						</a>
					</td>
					<td width="20px">
						<a href="{{ route('products.edit', $product->id) }}" class="btn btn-link">
							Editar
						</a>
					</td>
					<td width="20px">
						{!! Form::open(['route' => ['products.destroy', $product->id], 'method' => 'DELETE']) !!}
							<button class="btn btn-link">
								Borrar
							</button>							
						{!! Form::close() !!}
					</td>
				</tr>
				@endforeach
			</tbody>
		</table>
		{!! $products->render() !!}
	</div>
	<div class="col-xs-12 col-sm-4">
		@include('products.partials.aside')
	</div>
@endsection