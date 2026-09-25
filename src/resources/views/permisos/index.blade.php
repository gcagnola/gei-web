@extends('layouts.app')

@section('title', 'Permisos')
@section('page-title', 'Permisos')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Permisos por perfil</h1>
            <p class="text-muted mb-0">Definí a qué módulos puede acceder cada perfil.</p>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <div class="fw-semibold mb-1">No se pudieron guardar los permisos:</div>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('permisos.update') }}">
        @csrf
        @method('PUT')

        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Sección</th>
                                <th>Módulo</th>
                                @foreach ($perfiles as $perfil)
                                    <th class="text-center" style="min-width: 130px">{{ $perfil->nombre }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($modulos as $modulo)
                            <tr>
                                <td class="ps-3 text-muted">{{ $modulo->seccion }}</td>
                                <td class="fw-semibold">{{ $modulo->nombre }}</td>
                                @foreach ($perfiles as $perfil)
                                    @php
                                        $esAdministrador = $perfil->codigo === 'ADMINISTRADOR';
                                        $asignado = $esAdministrador || $perfil->modulos->contains('id', $modulo->id);
                                    @endphp
                                    <td class="text-center">
                                        @if ($esAdministrador)
                                            <input class="form-check-input" type="checkbox" checked disabled title="El Administrador tiene acceso total">
                                        @else
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                name="permisos[{{ $perfil->id }}][]"
                                                value="{{ $modulo->id }}"
                                                @checked($asignado)
                                            >
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end mt-3">
            <button type="submit" class="btn btn-primary">Guardar</button>
        </div>
    </form>

    <div class="alert alert-light border mt-3 mb-0 small text-muted">
        El perfil ADMINISTRADOR siempre tiene acceso total. Los permisos se validan también en el servidor: ocultar un menú no habilita el acceso por URL.
    </div>
</div>
@endsection
