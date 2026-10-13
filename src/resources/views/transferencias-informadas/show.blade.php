@extends('layouts.app')
@section('title', 'Transferencia informada')
@section('content')
<div class="container-fluid px-0">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <a href="{{ route('transferencias-informadas.index') }}" class="small text-decoration-none">← Volver</a>
            <h1 class="h3 mt-2 mb-1">Transferencia #{{ $transferencia->id }}</h1>
            <p class="text-muted mb-0">Informada {{ optional($transferencia->created_at)->format('d/m/Y H:i') }}</p>
        </div>
        <div>
            @if($transferencia->estado === 'PENDIENTE')<span class="badge text-bg-warning fs-6">Pendiente</span>
            @elseif($transferencia->estado === 'VERIFICADA')<span class="badge text-bg-success fs-6">Verificada</span>
            @elseif($transferencia->estado === 'RECHAZADA')<span class="badge text-bg-danger fs-6">Rechazada</span>@endif
        </div>
    </div>

    @if(session('estado'))<div class="alert alert-success">{{ session('estado') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm"><div class="card-body p-4">
                <h2 class="h5">Datos informados</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Cliente</dt><dd class="col-sm-8">{{ $transferencia->cliente?->nombre }}</dd>
                    <dt class="col-sm-4">CUIT / Documento</dt><dd class="col-sm-8">{{ $transferencia->cliente?->cuit ?: $transferencia->cliente?->numero_documento ?: '—' }}</dd>
                    <dt class="col-sm-4">Cuenta</dt><dd class="col-sm-8">{{ $transferencia->clienteCuenta?->cuenta ?: '—' }}</dd>
                    <dt class="col-sm-4">Fecha</dt><dd class="col-sm-8">{{ optional($transferencia->fecha_transferencia)->format('d/m/Y') }}</dd>
                    <dt class="col-sm-4">Importe</dt><dd class="col-sm-8 fw-bold">$ {{ number_format((float) $transferencia->importe, 2, ',', '.') }}</dd>
                    <dt class="col-sm-4">Referencia</dt><dd class="col-sm-8">{{ $transferencia->referencia ?: '—' }}</dd>
                    <dt class="col-sm-4">Observaciones</dt><dd class="col-sm-8">{!! nl2br(e($transferencia->observaciones ?: '—')) !!}</dd>
                </dl>

                <hr>
                <h2 class="h6">Comprobante</h2>
                @foreach($transferencia->archivos as $archivo)
                    <a class="btn btn-outline-primary" href="{{ route('transferencias-informadas.archivo', [$transferencia, $archivo]) }}">{{ $archivo->nombre_original }}</a>
                @endforeach
            </div></div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm"><div class="card-body p-4">
                <h2 class="h5">Verificación</h2>

                @if($transferencia->estado === 'PENDIENTE')
                    <div class="alert alert-warning">Esta acción solamente valida el aviso. <strong>No genera recibo, cuenta corriente ni movimiento de caja.</strong></div>
                    <form method="POST" action="{{ route('transferencias-informadas.verificar', $transferencia) }}" class="mb-4">
                        @csrf
                        <button class="btn btn-success" type="submit">Marcar como verificada</button>
                    </form>

                    <form method="POST" action="{{ route('transferencias-informadas.rechazar', $transferencia) }}">
                        @csrf
                        <label class="form-label">Motivo de rechazo</label>
                        <textarea name="rechazo_motivo" class="form-control mb-2" rows="4" maxlength="1000" required></textarea>
                        <button class="btn btn-outline-danger" type="submit">Rechazar</button>
                    </form>
                @else
                    <dl class="mb-0">
                        <dt>Estado</dt><dd>{{ $transferencia->estado }}</dd>
                        <dt>Procesada</dt><dd>{{ optional($transferencia->verificada_at)->format('d/m/Y H:i') ?: '—' }}</dd>
                        <dt>Usuario</dt><dd>{{ $transferencia->verificadaPor?->nombre ?? '—' }}</dd>
                        @if($transferencia->rechazo_motivo)
                            <dt>Motivo</dt><dd>{{ $transferencia->rechazo_motivo }}</dd>
                        @endif
                    </dl>
                @endif
            </div></div>
        </div>
    </div>
</div>
@endsection
