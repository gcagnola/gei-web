@extends('layouts.portal')
@section('title', 'Ingreso')
@section('content')
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h1 class="h4 mb-1">Autogestión</h1>
                <p class="text-muted mb-4">Ingresá con tu CUIT. Si no tenés CUIT registrado, usá tu número de documento.</p>
                @if ($errors->any())
                    <div class="alert alert-danger">Usuario o contraseña incorrectos.</div>
                @endif
                <form method="POST" action="{{ route('portal.login.ingresar') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="identificador">CUIT / Documento</label>
                        <input class="form-control" id="identificador" name="identificador" value="{{ old('identificador') }}" autocomplete="username" autofocus required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Contraseña</label>
                        <input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="recordarme" name="recordarme" value="1">
                        <label class="form-check-label" for="recordarme">Recordarme</label>
                    </div>
                    <button class="btn gei-button gei-button--primary w-100" type="submit">Ingresar</button>
                </form>
                <div class="text-center mt-3"><a href="{{ route('portal.password.request') }}">¿Olvidaste tu contraseña?</a></div>
            </div>
        </div>
    </div>
</div>
@endsection
