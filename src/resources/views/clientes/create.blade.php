@extends('layouts.app')

@section('title', 'Nuevo cliente')
@section('page-title', 'Nuevo cliente')

@section('content')
    <header class="gei-page-heading">
        <h1>Nuevo cliente</h1>
        <p>Completá los datos para incorporar un cliente.</p>
    </header>

    @include('clientes.partials.formulario')
@endsection
