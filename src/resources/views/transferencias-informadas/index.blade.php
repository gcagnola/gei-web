@extends('layouts.app')
@section('title', 'Transferencias informadas')
@section('content')
<div class="container-fluid px-0">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Transferencias informadas</h1>
            <p class="text-muted mb-0">Avisos enviados desde Autogestión. Verificar no genera movimientos financieros.</p>
        </div>
    </div>

    @if(session('estado'))<div class="alert alert-success">{{ session('estado') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="card border-0 shadow-sm mb-4"><div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Estado</label>
                <select name="estado" class="form-select">
                    @foreach(['PENDIENTE' => 'Pendientes', 'VERIFICADA' => 'Verificadas', 'RECHAZADA' => 'Rechazadas', 'TODAS' => 'Todas'] as $valor => $texto)
                        <option value="{{ $valor }}" @selected($estado === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Cliente</label>
                <input type="search" name="buscar" class="form-control" value="{{ $buscar }}" placeholder="Nombre, CUIT o documento">
            </div>
            <div class="col-md-3"><button class="btn btn-primary w-100">Buscar</button></div>
        </form>
    </div></div>

    <div class="card border-0 shadow-sm"><div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Informada</th><th>Cliente</th><th>Cuenta</th><th>Fecha</th><th class="text-end">Importe</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                @forelse($transferencias as $transferencia)
                    <tr>
                        <td>{{ optional($transferencia->created_at)->format('d/m/Y H:i') }}</td>
                        <td>
                            <strong>{{ $transferencia->cliente?->nombre }}</strong><br>
                            <span class="small text-muted">{{ $transferencia->cliente?->cuit ?: $transferencia->cliente?->numero_documento }}</span>
                        </td>
                        <td>{{ $transferencia->clienteCuenta?->cuenta ?: '—' }}</td>
                        <td>{{ optional($transferencia->fecha_transferencia)->format('d/m/Y') }}</td>
                        <td class="text-end">$ {{ number_format((float) $transferencia->importe, 2, ',', '.') }}</td>
                        <td>
                            @if($transferencia->estado === 'PENDIENTE')<span class="badge text-bg-warning">Pendiente</span>
                            @elseif($transferencia->estado === 'VERIFICADA')<span class="badge text-bg-success">Verificada</span>
                            @elseif($transferencia->estado === 'RECHAZADA')<span class="badge text-bg-danger">Rechazada</span>
                            @else<span class="badge text-bg-secondary">{{ $transferencia->estado }}</span>@endif
                        </td>
                        <td class="text-end"><a href="{{ route('transferencias-informadas.show', $transferencia) }}" class="btn btn-sm btn-outline-primary">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-5">No hay transferencias para mostrar.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div></div>
    <div class="mt-3">{{ $transferencias->links() }}</div>
</div>
@endsection
