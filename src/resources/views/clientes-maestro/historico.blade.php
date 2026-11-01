@extends('layouts.app')

@section('title', 'Histórico COBOL por cuenta')
@section('page-title', 'Clientes · Histórico COBOL por cuenta')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">Histórico por cuenta COBOL</h1>
            <p class="text-muted mb-0">{{ $clienteOperativo->nombre_visible }} · #{{ $clienteOperativo->id }}</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('clientes-maestro.index', ['buscar' => $cuenta, 'seleccionar' => $clienteOperativo->id]) }}">← Volver al maestro</a>
    </div>
    <div class="alert alert-info py-2">
        Cuenta <strong>{{ $cuenta }}</strong> · {{ $rol }} · {{ $sedeCuenta }}.
        Sólo se presentan registros históricos de esta cuenta y este rol, sin documentos ni funciones de edición.
    </div>
    <div class="card shadow-sm border-0">
        <div class="card-header d-flex justify-content-between">
            <strong>Registros originales por período COBOL</strong>
            <span class="badge text-bg-secondary">{{ $registros->count() }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead><tr>
                    <th>Período</th><th>Nombre en COBOL</th><th>CUIT en COBOL</th><th>Domicilio en COBOL</th><th>Activo en período</th>
                </tr></thead>
                <tbody>
                @forelse($registros as $registro)
                    <tr>
                        <td>{{ substr($registro->periodo, 4, 2) }}/{{ substr($registro->periodo, 0, 4) }}</td>
                        <td>{{ $registro->nombre_origen ?: '—' }}</td>
                        <td>{{ $registro->cuit_origen ?: '—' }}</td>
                        <td>{{ $registro->domicilio_origen ?: '—' }}</td>
                        <td>{{ $registro->activo_en_periodo === null ? 'No determinado' : ($registro->activo_en_periodo ? 'Sí' : 'No') }}</td>
                    </tr>
                @empty
                    <tr><td class="text-center text-muted py-3" colspan="5">No hay registros originales de esta cuenta en los períodos COBOL importados.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
