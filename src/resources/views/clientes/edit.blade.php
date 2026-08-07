@extends('layouts.app')

@section('title', 'Modificar cliente')
@section('page-title', 'Modificar cliente')

@section('content')
    <header class="gei-page-heading">
        <h1>Modificar cliente</h1>
        <p>{{ $cliente->nombre_visible }} · Cliente #{{ $cliente->id }}</p>
    </header>

    @include('clientes.partials.formulario')
@endsection
