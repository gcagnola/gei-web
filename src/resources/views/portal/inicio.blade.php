@extends('layouts.portal')
@section('title', 'Mi cuenta')
@section('content')
@php($cliente = $portalUsuario->cliente)
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div><h1 class="h3 mb-1">Hola, {{ $cliente->nombre_visible }}</h1><p class="text-muted mb-0">Portal de Autogestión</p></div>
</div>
@if (! $portalUsuario->datos_confirmados_at)
    <div class="alert alert-warning"><strong>Confirmá tus datos de contacto.</strong> Necesitamos un teléfono actualizado antes de continuar.</div>
@endif

<div class="card shadow-sm border-0 mb-4"><div class="card-body p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h2 class="h5 mb-1">Documentos</h2>
            <p class="text-muted mb-0">Consultá tus comprobantes y documentos disponibles.</p>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('portal.comprobantes-arca.index') }}" class="btn btn-outline-primary">Comprobantes ARCA</a>
        @if (config('portal.funcionalidades.cuenta_corriente', false))
            <a href="{{ route('portal.cuenta-corriente.index') }}" class="btn btn-outline-primary">Cuenta corriente (prueba)</a>
        @endif
        @if (config('portal.funcionalidades.recibos', false))
            <a href="{{ route('portal.recibos.index') }}" class="btn btn-outline-primary">Recibos</a>
        @endif
        @if($cliente->roles->contains('codigo', 'PROPIETARIO'))
            <a href="{{ route('portal.liquidaciones-propietario.index') }}" class="btn btn-outline-primary">Liquidaciones propietario</a>
            <a href="{{ route('portal.impuestos-garantizados.index') }}" class="btn btn-outline-primary">Impuestos garantizados</a>
        @endif
    </div>
</div></div>

@if (config('portal.funcionalidades.transferencias', false))
<div class="card shadow-sm border-0 mb-4"><div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <h2 class="h5 mb-1">Transferencias</h2>
        <p class="text-muted mb-0">Informá una transferencia y adjuntá el comprobante para su verificación.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('portal.transferencias.index') }}" class="btn btn-outline-primary">Mis transferencias</a>
        <a href="{{ route('portal.transferencias.create') }}" class="btn btn-primary">Informar transferencia</a>
    </div>
</div>
@endif

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0"><div class="card-body p-4">
            <h2 class="h5">Datos de contacto</h2>
            <dl class="row mb-4">
                <dt class="col-sm-4">CUIT</dt><dd class="col-sm-8">{{ $cliente->cuit ?: '—' }}</dd>
                <dt class="col-sm-4">Documento</dt><dd class="col-sm-8">{{ $cliente->numero_documento ?: '—' }}</dd>
                <dt class="col-sm-4">Email</dt><dd class="col-sm-8">{{ $cliente->email ?: '—' }}</dd>
            </dl>
            <form method="POST" action="{{ route('portal.datos.update') }}">
                @csrf @method('PUT')
                <div class="mb-3"><label class="form-label">Teléfono / celular *</label><input class="form-control" name="telefono" value="{{ old('telefono', $cliente->telefono) }}" maxlength="100" required></div>
                <div class="mb-3"><label class="form-label">Teléfono alternativo</label><input class="form-control" name="telefono_alternativo" value="{{ old('telefono_alternativo', $cliente->telefono_alternativo) }}" maxlength="100"></div>
                <button class="btn gei-button gei-button--primary" type="submit">Guardar y confirmar datos</button>
            </form>
        </div></div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm border-0"><div class="card-body p-4">
            <h2 class="h5">Cambiar contraseña</h2>
            <form method="POST" action="{{ route('portal.clave.update') }}">
                @csrf @method('PUT')
                <div class="mb-3"><label class="form-label">Contraseña actual</label><input class="form-control" type="password" name="password_actual" required></div>
                <div class="mb-3"><label class="form-label">Nueva contraseña</label><input class="form-control" type="password" name="password" required></div>
                <div class="mb-3"><label class="form-label">Repetir nueva contraseña</label><input class="form-control" type="password" name="password_confirmation" required></div>
                <button class="btn btn-outline-primary" type="submit">Cambiar contraseña</button>
            </form>
        </div></div>
    </div>
</div>
@endsection
