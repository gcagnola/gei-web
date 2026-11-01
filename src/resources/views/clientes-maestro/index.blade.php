@extends('layouts.app')

@section('title', 'Clientes · Maestro operativo')
@section('page-title', 'Clientes · Maestro operativo')

@push('styles')
<style>
    /* Tabla operativa con navegación a la ficha completa por cliente. */
    .gei-maestro-fila { cursor: pointer; }
    .gei-maestro-fila a:focus-visible { outline: 2px solid var(--gei-primary); outline-offset: 3px; }
    .gei-maestro-cuenta { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .gei-maestro-estado { white-space: nowrap; }
    @media (max-width: 991.98px) {
        .gei-maestro-tabla { min-width: 950px; }
    }
</style>
@endpush

@section('content')
@php
    $sedeCuenta = static fn (string $cuenta): string => match (substr($cuenta, 0, 4)) {
        '1103', '1202' => 'SF',
        '2103', '2202' => 'ST',
        default => 'Sin sede',
    };
    $parametros = request()->except(['seleccionar', 'page']);
@endphp

<div class="container-fluid py-3">
    @if(session('aviso'))<div class="alert alert-warning">{{ session('aviso') }}</div>@endif
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">Clientes</h1>
            <div class="text-muted small">Maestro operativo de GeI · Fuente: gei_db.public.clientes · Una identidad por cliente canónico.</div>
        </div>
        <span class="badge text-bg-secondary">Sólo lectura</span>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3" aria-label="Filtrar por rol COBOL">
        <div class="btn-group" role="group" aria-label="Rol COBOL">
            @foreach(['TODOS' => 'Todos', 'PROPIETARIO' => 'Propietarios', 'INQUILINO' => 'Inquilinos'] as $opcionRol => $etiquetaRol)
                <a class="btn btn-sm {{ $rol === $opcionRol ? 'btn-primary' : 'btn-outline-primary' }}"
                   href="{{ route('clientes-maestro.index', [...$parametros, 'rol' => $opcionRol]) }}"
                   @if($rol === $opcionRol) aria-current="page" @endif>{{ $etiquetaRol }}</a>
            @endforeach
        </div>
    </div>

    <form method="GET" action="{{ route('clientes-maestro.index') }}" class="card card-body border-0 shadow-sm mb-3">
        <input type="hidden" name="rol" value="{{ $rol }}">
        <div class="row g-2 align-items-end">
            <div class="col-lg-4">
                <label for="buscar" class="form-label small">Buscar</label>
                <input id="buscar" name="buscar" type="search" class="form-control" value="{{ $buscar }}"
                       placeholder="Nombre, CUIT, documento, email o cuenta COBOL" maxlength="120">
            </div>
            <div class="col-md-2">
                <label for="sede" class="form-label small">Sede</label>
                <select id="sede" name="sede" class="form-select">
                    @if(count($sedesPermitidas) > 1)
                        <option value="TODAS" @selected($sede === 'TODAS')>Todas las permitidas</option>
                    @endif
                    @foreach($sedesPermitidas as $codigoSede)
                        <option value="{{ $codigoSede }}" @selected($sede === $codigoSede)>
                            {{ $codigoSede === 'SF' ? 'Santa Fe' : 'Santo Tomé' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="estado" class="form-label small">Actividad</label>
                <select id="estado" name="estado" class="form-select">
                    <option value="todos" @selected($estado === 'todos')>Todos</option>
                    <option value="activos" @selected($estado === 'activos')>Con actividad</option>
                    <option value="inactivos" @selected($estado === 'inactivos')>Sin actividad</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="periodo_actividad" class="form-label small">Período actividad</label>
                <select id="periodo_actividad" name="periodo_actividad" class="form-select">
                    @foreach($periodosActividad as $opcionPeriodo)
                        <option value="{{ $opcionPeriodo }}" @selected($periodoActividad === $opcionPeriodo)>
                            {{ substr($opcionPeriodo, 4, 2) }}/{{ substr($opcionPeriodo, 0, 4) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Buscar</button>
                <a class="btn btn-outline-secondary" href="{{ route('clientes-maestro.index') }}">Limpiar</a>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
            <div class="fw-semibold">Clientes canónicos</div>
            <div class="small text-muted">
                {{ number_format($clientes->total(), 0, ',', '.') }} registros encontrados
                <span class="ms-2">· Seleccioná una fila para abrir su ficha completa</span>
            </div>
        </div>
        <div id="gei-maestro-tabla" class="table-responsive">
            <table class="table table-hover align-middle mb-0 gei-maestro-tabla">
                <thead class="table-light">
                    <tr>
                        <th>Cliente</th>
                        <th>CUIT / IVA</th>
                        <th>Documento</th>
                        <th>Rol(es)</th>
                        <th>Cuentas COBOL</th>
                        <th>Actividad del período</th>
                        <th class="text-end">Ficha</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($clientes as $cliente)
                        <tr id="gei-maestro-cliente-{{ $cliente->id }}"
                            class="gei-maestro-fila"
                            data-cliente="{{ $cliente->id }}">
                            <td>
                                <a class="fw-semibold text-decoration-none link-body-emphasis js-gei-maestro-seleccionar"
                                   href="{{ route('clientes-maestro.show', ['cliente' => $cliente->id, ...$parametros, 'page' => $clientes->currentPage()]) }}">
                                    {{ $cliente->nombre_visible }}
                                </a>
                                <div class="small text-muted">Cliente #{{ $cliente->id }}</div>
                                @if($cliente->domicilio)
                                    <div class="small text-muted">{{ $cliente->domicilio }}{{ $cliente->localidad ? ' · '.$cliente->localidad : '' }}</div>
                                @endif
                                @if($cliente->email)
                                    <div class="small">{{ $cliente->email }}</div>
                                @endif
                            </td>
                            <td>{{ $cliente->cuit ?: '—' }}</td>
                            <td>{{ trim(($cliente->tipo_documento ?: '').' '.($cliente->numero_documento ?: '')) ?: '—' }}</td>
                            <td>
                                @forelse($cliente->cuentas->pluck('rol')->unique() as $rolCuenta)
                                    <span class="badge text-bg-light border text-dark me-1">{{ $rolCuenta }}</span>
                                @empty
                                    <span class="text-muted small">Sin rol COBOL</span>
                                @endforelse
                            </td>
                            <td class="small">
                                @forelse($cliente->cuentas as $cuenta)
                                    <div class="gei-maestro-cuenta mb-1">
                                        {{ $cuenta->cuenta }}
                                        @php $codigoSedeCuenta = $sedeCuenta($cuenta->cuenta); @endphp
                                        <span class="badge {{ $codigoSedeCuenta === 'SF' ? 'text-bg-primary' : ($codigoSedeCuenta === 'ST' ? 'text-bg-info' : 'text-bg-secondary') }}">{{ $codigoSedeCuenta }}</span>
                                    </div>
                                @empty
                                    <span class="text-muted">Sin cuenta COBOL</span>
                                @endforelse
                            </td>
                            <td class="gei-maestro-estado">
                                <span class="badge {{ $cliente->actividad_periodo === true ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ $cliente->actividad_periodo === null ? 'Sin cuentas visibles' : ($cliente->actividad_periodo ? 'Con actividad' : 'Sin actividad') }}
                                </span>
                                <div class="small text-muted mt-1">
                                    Administrativo: {{ $cliente->activo === null ? 'Sin definir' : ($cliente->activo ? 'Activo' : 'Inactivo') }}
                                </div>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary js-gei-maestro-seleccionar"
                                   href="{{ route('clientes-maestro.show', ['cliente' => $cliente->id, ...$parametros, 'page' => $clientes->currentPage()]) }}"
                                   aria-label="Abrir ficha de {{ $cliente->nombre_visible }}">
                                    Abrir ficha
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No se encontraron clientes con los filtros aplicados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($clientes->hasPages())
            <div class="card-footer bg-white">{{ $clientes->onEachSide(1)->links() }}</div>
        @endif
    </div>
    <p class="small text-muted mt-3 mb-0">La actividad corresponde al período calculado; «sin actividad» no equivale a baja contractual. Las actualizaciones diarias aún no incorporadas al recálculo pueden no estar reflejadas. Este maestro no modifica estados ni accesos.</p>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('click', function (evento) {
    if (evento.defaultPrevented || evento.button !== 0 || evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.altKey) return;
    if (evento.target.closest('a, button, input, select')) return;
    const fila = evento.target.closest('tr.gei-maestro-fila');
    const enlace = fila?.querySelector('a.js-gei-maestro-seleccionar');
    if (enlace) window.location.assign(enlace.href);
});
</script>
@endpush
