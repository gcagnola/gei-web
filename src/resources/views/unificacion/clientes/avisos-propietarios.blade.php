@extends('layouts.app')
@section('title', 'Avisos de propietarios')
@section('page-title', 'Unificación')
@section('content')
<div class="container-fluid py-3 pb-5">
    <h1 class="h3">Clientes / Propietarios</h1>
    <p class="text-muted">Avisos de titularidad detectados al importar inmuebles. No implican que el inmueble esté duplicado.</p>
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link" href="{{ route('archivo.unificacion.index') }}">Inmuebles</a></li>
        <li class="nav-item"><a class="nav-link" href="{{ route('archivo.unificacion.clientes.index') }}">Clientes</a></li>
        <li class="nav-item"><span class="nav-link active">Avisos de propietarios</span></li>
    </ul>
    <div class="alert alert-info">Cada aviso conserva su cuenta COBOL y su inmueble de origen. Si existe una revisión de cliente pendiente, podés abrirla directamente. Los avisos de relación se vuelven a evaluar al importar inmuebles, una vez corregida la titularidad.</div>
    <form method="GET" action="{{ route('archivo.unificacion.clientes.index') }}" class="row g-2 mb-3">
        <input type="hidden" name="vista" value="avisos_propietarios">
        <div class="col-md-9">
            <label for="q" class="form-label">Cuenta COBOL, domicilio, motivo o ID</label>
            <input class="form-control" id="q" name="q" value="{{ $texto }}" maxlength="180">
        </div>
        <div class="col-md-3 align-self-end"><button class="btn btn-primary" type="submit">Buscar</button></div>
    </form>
    <div class="card mb-3">
        <div class="card-header">{{ $avisos->total() }} aviso(s) de titularidad</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Inmueble</th><th>Cuenta propietario</th><th>Cuenta inquilino</th><th>Motivo</th><th>Acción</th></tr></thead>
                <tbody>
                @forelse ($avisos as $aviso)
                    <tr>
                        <td>{{ $aviso->inmueble_id ? '#'.$aviso->inmueble_id : 'Sin asociar' }} — {{ $aviso->domicilio ?: 'Sin domicilio asociado' }}<div class="small text-muted">{{ $aviso->inmueble_estado }}</div></td>
                        <td>{{ $aviso->cuenta_propietario ?: '—' }}</td>
                        <td>{{ $aviso->cuenta_inquilino ?: '—' }}</td>
                        <td>
                            {{ match ($aviso->motivo) {
                                'PROPIETARIO_EN_CONFLICTO' => 'Identidad del propietario pendiente de revisión',
                                'CUENTA_PROPIETARIO_NO_ENCONTRADA' => 'Cuenta del propietario no encontrada',
                                'CUENTA_PROPIETARIO_AMBIGUA' => 'Cuenta del propietario ambigua',
                                default => 'Relación de copropietarios incompleta',
                            } }}
                            <details class="small mt-1"><summary>Ver evidencia · aviso #{{ $aviso->id }}</summary>
                                <code>{{ $aviso->motivo }}</code>
                                <div>Última detección: {{ $aviso->ultima_deteccion_at }}</div>
                                <pre class="text-wrap mb-0">{{ is_string($aviso->detalle) ? $aviso->detalle : json_encode($aviso->detalle, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                            </details>
                        </td>
                        <td>
                            @if ($aviso->revision_cliente_id)
                                <a class="btn btn-sm btn-primary" href="{{ route('archivo.unificacion.clientes.conflicto.revisar', $aviso->revision_cliente_id) }}">Revisar propietario</a>
                            @else
                                <span class="small text-muted">Sin revisión de identidad de cliente pendiente. Revisar la cuenta o la relación de titulares.</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No hay avisos para los filtros indicados.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $avisos->links() }}
    <a href="{{ route('archivo.unificacion.clientes.index', ['vista' => 'activos_revision']) }}" class="btn btn-outline-secondary">Revisiones COBOL de clientes</a>
</div>
@endsection
