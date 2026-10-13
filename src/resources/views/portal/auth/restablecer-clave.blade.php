@extends('layouts.portal')
@section('title', 'Nueva contraseña')
@section('content')
<div class="row justify-content-center"><div class="col-md-6"><div class="card shadow-sm border-0"><div class="card-body p-4">
    <h1 class="h4">Nueva contraseña</h1>
    <form method="POST" action="{{ route('portal.password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="mb-3"><label class="form-label">Nueva contraseña</label><input class="form-control @error('password') is-invalid @enderror" type="password" name="password" required>@error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="mb-3"><label class="form-label">Repetir contraseña</label><input class="form-control" type="password" name="password_confirmation" required></div>
        <button class="btn gei-button gei-button--primary" type="submit">Guardar contraseña</button>
    </form>
</div></div></div></div>
@endsection
