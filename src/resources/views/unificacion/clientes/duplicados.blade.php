@extends('layouts.app')

@section('title', 'Posibles duplicados de clientes')
@section('page-title', 'Posibles duplicados de clientes')

@section('content')
<div class="container-fluid py-3">
    @if(session('aviso'))<div class="alert alert-warning">{{ session('aviso') }}</div>@endif
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">Posibles duplicados del maestro de clientes</h1>
            <div class="text-muted small">Identidades de <code>gei_db.public.clientes</code> con evidencia coincidente.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('clientes-maestro.index') }}">Clientes maestro</a>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('archivo.unificacion.clientes.revisiones') }}">Revisiones COBOL</a>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('archivo.unificacion.clientes.historial') }}">Historial de unificaciones</a>
        </div>
    </div>

    <div class="alert alert-info border mb-3">
        <strong>Importante:</strong> esta vista identifica candidatos mediante CUIT, documento y cuentas COBOL
        de clientes canónicos. No realiza fusiones.
        Las coincidencias por nombre o por cierre COBOL <strong>no prueban identidad</strong>.
        La consulta histórica por cuenta y período está disponible en <strong>Clientes maestro → Datos → Histórico por cuenta</strong>.
    </div>

    <form method="GET" class="card card-body border-0 shadow-sm mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md">
                <label for="buscar-duplicado" class="form-label small">Buscar identidad, CUIT, documento o cuenta</label>
                <input id="buscar-duplicado" name="buscar" class="form-control" maxlength="180"
                       value="{{ $buscar }}" placeholder="Nombre, CUIT, documento o cuenta COBOL">
            </div>
            <div class="col-md-auto"><button type="submit" class="btn btn-primary w-100">Buscar</button></div>
            <div class="col-md-auto"><a class="btn btn-outline-secondary w-100" href="{{ route('archivo.unificacion.clientes.duplicados') }}">Limpiar</a></div>
        </div>
    </form>

    <p class="text-muted small mb-3">
        {{ number_format($pares->total(), 0, ',', '.') }} par(es) pendiente(s) visible(s) para sus sucursales
        ({{ implode(', ', $sedes) }}). Una coincidencia <strong>no prueba</strong> que sean la misma persona.
    </p>

    @forelse($pares as $par)
        @php
            $a = $clientes->get((int) $par->id_a);
            $b = $clientes->get((int) $par->id_b);
            $cuentasA = $cuentas->get((int) $par->id_a, collect());
            $cuentasB = $cuentas->get((int) $par->id_b, collect());
        @endphp
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong>Clientes #{{ $par->id_a }} y #{{ $par->id_b }}</strong>
                    <span class="badge {{ $par->confianza === 'ALTA' ? 'text-bg-warning' : 'text-bg-secondary' }} ms-2">
                        Evidencia {{ $par->confianza }}
                    </span>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('archivo.unificacion.clientes.comparar', ['principal' => $par->id_a, 'secundario' => $par->id_b]) }}">Comparar identidades</a>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-2">{{ implode(' · ', $par->motivos) }}</p>
                <div class="row g-3">
                    @foreach([[$a, $cuentasA], [$b, $cuentasB]] as [$cliente, $cuentasCliente])
                        <div class="col-lg-6">
                            <div class="border rounded p-3 h-100">
                                <div class="fw-semibold">#{{ $cliente->id }} · {{ $cliente->nombre ?: 'Sin nombre' }}</div>
                                <div class="small">CUIT: {{ $cliente->cuit ?: '—' }}</div>
                                <div class="small">Documento: {{ trim(($cliente->tipo_documento ?? '').' '.($cliente->numero_documento ?? '')) ?: '—' }}</div>
                                <div class="small text-muted mt-2">Cuentas operativas visibles:</div>
                                @foreach($cuentasCliente as $cuenta)
                                    <span class="badge text-bg-light border me-1 mb-1">{{ $cuenta->rol }} · {{ $cuenta->cuenta }}</span>
                                @endforeach
                                <div class="mt-2"><a href="{{ route('clientes-maestro.show', ['cliente' => $cliente->id]) }}" class="small">Ver ficha operativa</a></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @empty
        <div class="alert alert-light border">No hay candidatos pendientes con el filtro aplicado.</div>
    @endforelse
    {{ $pares->links() }}
</div>
@endsection
