@extends('layouts.portal')
@section('title', 'Informar transferencia')
@section('page-title', 'Autogestión')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Informar transferencia</h1>
        <p class="text-muted mb-0">El aviso quedará pendiente hasta que Administración verifique el comprobante.</p>
    </div>
    <a href="{{ route('portal.transferencias.index') }}" class="btn btn-outline-secondary">Volver</a>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

<div class="card shadow-sm border-0">
    <div class="card-body p-4">
        <form method="POST" action="{{ route('portal.transferencias.store') }}" enctype="multipart/form-data">
            @csrf

            @if($cliente->cuentas->count() > 1)
                <div class="mb-3">
                    <label class="form-label">Cuenta / relación</label>
                    <select name="cliente_cuenta_id" class="form-select">
                        <option value="">Seleccionar...</option>
                        @foreach($cliente->cuentas as $cuenta)
                            <option value="{{ $cuenta->id }}" @selected((string) old('cliente_cuenta_id') === (string) $cuenta->id)>
                                {{ $cuenta->rol }} — {{ $cuenta->cuenta }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @elseif($cliente->cuentas->count() === 1)
                <input type="hidden" name="cliente_cuenta_id" value="{{ $cliente->cuentas->first()->id }}">
                <div class="mb-3">
                    <label class="form-label">Cuenta / relación</label>
                    <div class="form-control bg-light">{{ $cliente->cuentas->first()->rol }} — {{ $cliente->cuentas->first()->cuenta }}</div>
                </div>
            @endif

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Fecha de transferencia *</label>
                    <input type="date" name="fecha_transferencia" class="form-control" value="{{ old('fecha_transferencia', now()->format('Y-m-d')) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Importe *</label>
                    <input type="number" name="importe" class="form-control" min="0.01" step="0.01" value="{{ old('importe') }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Referencia / operación</label>
                    <input type="text" name="referencia" class="form-control" maxlength="120" value="{{ old('referencia') }}">
                </div>
            </div>

            <div class="mt-3">
                <label class="form-label">Comprobante *</label>
                <input type="file" name="comprobante" class="form-control" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                <div class="form-text">PDF, JPG o PNG. Máximo 10 MB.</div>
            </div>

            <div class="mt-3">
                <label class="form-label">Observaciones</label>
                <textarea name="observaciones" class="form-control" rows="4" maxlength="2000">{{ old('observaciones') }}</textarea>
            </div>

            <div class="alert alert-light border mt-4 mb-3">
                Informar una transferencia <strong>no genera todavía un recibo ni modifica la cuenta corriente</strong>. Administración debe verificarla primero.
            </div>

            <button type="submit" class="btn btn-primary">Enviar comprobante</button>
        </form>
    </div>
</div>
@endsection
