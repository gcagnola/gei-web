@extends('layouts.app')

@section('title', 'Inicio')
@section('page-title', 'Inicio')

@section('content')
    <section class="gei-card gei-welcome">
        <div class="gei-welcome__content">
            <h1>
                Bienvenido, {{ auth()->user()->nombre_limpio }}
            </h1>

            <p>
                Sistema de administración de Guastavino e Imbert.
                Seleccioná una opción del menú para comenzar.
            </p>
        </div>
    </section>
@endsection
