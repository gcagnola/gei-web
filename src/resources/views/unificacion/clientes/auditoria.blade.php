@extends('layouts.app')

@section('title', 'Auditoría de clientes')
@section('page-title', 'Auditoría de clientes')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">Auditoría de identidades de clientes</h1>
            <p class="small text-muted mb-0">
                Revisiones y unificaciones sobre el maestro operativo de GeI-Web.
                La información COBOL se conserva como evidencia de origen.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('clientes-maestro.index') }}">Clientes maestro</a>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('archivo.unificacion.clientes.index') }}">Unificación de clientes</a>
        </div>
    </div>

    <div class="btn-group mb-3" role="group" aria-label="Vistas de auditoría">
        <a class="btn btn-sm btn-outline-primary" href="{{ route('archivo.unificacion.clientes.duplicados') }}">Posibles duplicados</a>
        <a class="btn btn-sm {{ $modo === 'revisiones' ? 'btn-primary' : 'btn-outline-primary' }}"
           href="{{ route('archivo.unificacion.clientes.revisiones') }}">Revisiones COBOL</a>
        <a class="btn btn-sm {{ $modo === 'historial' ? 'btn-primary' : 'btn-outline-primary' }}"
           href="{{ route('archivo.unificacion.clientes.historial') }}">Historial de unificaciones</a>
    </div>

    @if($modo === 'revisiones')
        @include('unificacion.clientes.partials.revisiones-cobol')
    @else
        @include('unificacion.clientes.partials.historial-unificaciones')
    @endif
</div>
@endsection
