@extends('layouts.portal')
@section('title', 'Comprobantes ARCA')
@section('page-title', 'Autogestión')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Comprobantes ARCA</h1>
        <p class="text-muted mb-0">Comprobantes asociados a tu cliente y a tus cuentas registradas.</p>
    </div>
    <a href="{{ route('portal.inicio') }}" class="btn btn-outline-secondary">Volver</a>
</div>

<form method="GET" class="card border-0 shadow-sm mb-4">
    <div class="card-body d-flex flex-wrap align-items-end gap-3">
        <div>
            <label class="form-label">Período</label>
            <select name="periodo" class="form-select" onchange="this.form.submit()">
                @forelse($periodos as $opcion)
                    <option value="{{ $opcion }}" @selected($periodo === $opcion)>
                        {{ substr($opcion, 4, 2) }}/{{ substr($opcion, 0, 4) }}
                    </option>
                @empty
                    <option value="">Sin períodos disponibles</option>
                @endforelse
            </select>
        </div>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Cuenta</th>
                    <th>Comprobante</th>
                    <th class="text-end">Total</th>
                    <th>PDF</th>
                </tr>
            </thead>
            <tbody>
                @forelse($comprobantes as $comprobante)
                    @php($esFactura = ($comprobante->fuente_portal ?? '') === 'FACTURA')
                    <tr>
                        <td>
                            @if($esFactura && !empty($comprobante->fecha_comprobante))
                                {{ \Carbon\Carbon::parse($comprobante->fecha_comprobante)->format('d/m/Y') }}
                            @else
                                {{ $comprobante->fecha ?: '—' }}
                            @endif
                        </td>
                        <td>{{ $comprobante->cuenta_cobol ?: '—' }}</td>
                        <td>
                            {{ $comprobante->tipo_codigo ?: '—' }}
                            {{ $comprobante->punto_venta ?: '' }}-{{ $comprobante->numero_comprobante ?: '' }}
                        </td>
                        <td class="text-end">
                            @if(is_numeric($comprobante->total ?? null))
                                $ {{ number_format((float) $comprobante->total, 2, ',', '.') }}
                            @else
                                {{ $comprobante->total ?: '—' }}
                            @endif
                        </td>
                        <td>
                            @if($comprobante->pdf_disponible ?? false)
                                @if($esFactura)
                                    <a href="{{ route('portal.comprobantes-arca.factura', ['factura' => $comprobante->id_factura]) }}" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">Ver</a>
                                @elseif(filled($comprobante->nombre_archivo ?? null))
                                    <a href="{{ route('portal.comprobantes-arca.ver', ['periodo' => $periodo, 'archivo' => $comprobante->nombre_archivo]) }}" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">Ver</a>
                                @endif
                            @else
                                <span class="text-muted">Sin PDF</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No hay comprobantes para este período.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
