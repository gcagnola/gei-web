@extends('layouts.portal')
@section('title', 'Mis recibos')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Recibos</h1>
        <p class="text-muted mb-0">Consultá los recibos de liquidación emitidos para tus cuentas.</p>
    </div>
    <a href="{{ route('portal.inicio') }}" class="btn btn-outline-secondary">Volver</a>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Fecha</th>
                    <th>Cuenta</th>
                    <th class="text-end">Importe</th>
                    <th class="text-end">Recibo</th>
                </tr>
            </thead>
            <tbody>
            @forelse($recibos as $recibo)
                <tr>
                    <td>{{ optional($recibo->fecha_documento ?: $recibo->recibido_en)->format('d/m/Y') ?: '—' }}</td>
                    <td>{{ $recibo->cuenta ?: '—' }}</td>
                    <td class="text-end fw-semibold">
                        {{ $recibo->importe_total !== null ? '$ '.number_format((float) $recibo->importe_total, 2, ',', '.') : '—' }}
                    </td>
                    <td class="text-end">
                        <a href="{{ route('portal.recibos.pdf', $recibo) }}"
                           target="_blank"
                           rel="noopener"
                           class="link-primary text-decoration-underline fw-semibold">Ver PDF</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center text-muted py-4">No hay recibos disponibles.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($recibos->hasPages())
        <div class="card-footer bg-white">{{ $recibos->links() }}</div>
    @endif
</div>
@endsection
