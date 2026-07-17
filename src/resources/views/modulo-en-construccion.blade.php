@extends('layouts.app')

@section('title', $titulo)
@section('page-title', $titulo)

@section('content')
    <header class="gei-page-heading">
        <h1>{{ $titulo }}</h1>
        <p>{{ $seccion }}</p>
    </header>

    <section class="gei-card gei-module-placeholder">
        <div class="gei-module-placeholder__icon" aria-hidden="true">
            ◫
        </div>

        <h2>Módulo preparado</h2>

        <p>
            La ruta y el acceso desde el menú ya están configurados.
            La funcionalidad de esta pantalla se incorporará en la etapa correspondiente.
        </p>
    </section>
@endsection
