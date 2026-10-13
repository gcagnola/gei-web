@extends('layouts.portal')
@section('title', 'Recuperar contraseña')
@section('content')
<div class="row justify-content-center"><div class="col-md-6"><div class="card shadow-sm border-0"><div class="card-body p-4">
    <h1 class="h4">Recuperar contraseña</h1>
    <p class="text-muted">Ingresá tu CUIT o, si no tenés CUIT registrado, tu número de documento.</p>
    <form method="POST" action="{{ route('portal.password.email') }}">
        @csrf
        <div class="mb-3"><label class="form-label" for="identificador">CUIT / Documento</label><input class="form-control" id="identificador" name="identificador" value="{{ old('identificador') }}" required></div>
        <button class="btn gei-button gei-button--primary" type="submit">Enviar enlace</button>
        <a class="btn btn-link" href="{{ route('portal.login') }}">Volver</a>
    </form>
</div></div></div></div>
@endsection
