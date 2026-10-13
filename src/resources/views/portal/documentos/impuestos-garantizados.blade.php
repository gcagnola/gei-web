@extends('layouts.portal')
@section('title', 'Impuestos garantizados')
@section('page-title', 'Autogestión')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Impuestos garantizados</h1>
        <p class="text-muted mb-0">Documentación de impuestos garantizados asociada a tus liquidaciones.</p>
    </div>
    <a href="{{ route('portal.inicio') }}" class="btn btn-outline-secondary">Volver</a>
</div>

<form method="GET" class="card border-0 shadow-sm mb-4">
    <div class="card-body d-flex flex-wrap align-items-end gap-3">
        <div>
            <label class="form-label">Período</label>
            <select name="periodo" class="form-select" onchange="this.form.submit()">
                <option value="">Todos</option>
                @foreach($periodos as $opcion)
                    <option value="{{ $opcion }}" @selected($periodo === $opcion)>
                        {{ substr($opcion, 4, 2) }}/{{ substr($opcion, 0, 4) }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Período</th>
                    <th>Fecha</th>
                    <th>Cuenta</th>
                    <th>Comprobante</th>
                    <th>Impuestos</th>
                </tr>
            </thead>
            <tbody>
                @forelse($liquidaciones as $liquidacion)
                    <tr>
                        <td>{{ $liquidacion->periodo_formateado }}</td>
                        <td>{{ optional($liquidacion->fecha)->format('d/m/Y') ?: '—' }}</td>
                        <td>{{ $liquidacion->cuenta_impresa ?: $liquidacion->cuenta }}</td>
                        <td>{{ $liquidacion->comprobante ?: '—' }}</td>
                        <td>
                            @if($liquidacion->impuestos_pdf_disponible)
                                <a href="{{ route('portal.impuestos-garantizados.ver', $liquidacion->id) }}" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">Ver</a>
                            @else
                                <span class="text-muted">No disponible</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No hay documentos disponibles.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($liquidaciones->hasPages())
        <div class="card-footer bg-white">{{ $liquidaciones->links() }}</div>
    @endif
</div>
@endsection
