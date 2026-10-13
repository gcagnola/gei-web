@extends('layouts.portal')
@section('title', 'Mis transferencias')
@section('page-title', 'Autogestión')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Transferencias informadas</h1>
        <p class="text-muted mb-0">Podés consultar el estado de los comprobantes que enviaste.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('portal.inicio') }}" class="btn btn-outline-secondary">Volver</a>
        <a href="{{ route('portal.transferencias.create') }}" class="btn btn-primary">Informar transferencia</a>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Informada</th>
                        <th>Transferencia</th>
                        <th>Cuenta</th>
                        <th class="text-end">Importe</th>
                        <th>Estado</th>
                        <th>Comprobante</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($transferencias as $transferencia)
                    <tr>
                        <td>{{ optional($transferencia->created_at)->format('d/m/Y H:i') }}</td>
                        <td>{{ optional($transferencia->fecha_transferencia)->format('d/m/Y') }}</td>
                        <td>{{ $transferencia->clienteCuenta?->cuenta ?: '—' }}</td>
                        <td class="text-end">$ {{ number_format((float) $transferencia->importe, 2, ',', '.') }}</td>
                        <td>
                            @if($transferencia->estado === 'PENDIENTE')
                                <span class="badge text-bg-warning">Pendiente</span>
                            @elseif($transferencia->estado === 'VERIFICADA')
                                <span class="badge text-bg-success">Verificada</span>
                            @elseif($transferencia->estado === 'RECHAZADA')
                                <span class="badge text-bg-danger">Rechazada</span>
                            @else
                                <span class="badge text-bg-secondary">{{ $transferencia->estado }}</span>
                            @endif
                        </td>
                        <td>
                            @foreach($transferencia->archivos as $archivo)
                                <a href="{{ route('portal.transferencias.archivo', [$transferencia, $archivo]) }}" class="btn btn-sm btn-outline-primary">Ver</a>
                            @endforeach
                        </td>
                    </tr>
                    @if($transferencia->estado === 'RECHAZADA' && $transferencia->rechazo_motivo)
                        <tr>
                            <td colspan="6" class="pt-0 border-top-0 text-danger small">
                                Motivo: {{ $transferencia->rechazo_motivo }}
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-5">Todavía no informaste transferencias.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="mt-3">{{ $transferencias->links() }}</div>
@endsection
