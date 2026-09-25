@extends('layouts.app')

@section('title', 'Perfiles')
@section('page-title', 'Perfiles')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Perfiles</h1>
            <p class="text-muted mb-0">Administrá los perfiles disponibles para los usuarios.</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoPerfil">
            Agregar perfil
        </button>
    </div>

    @if (session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger py-2">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <div class="fw-semibold mb-1">No se pudo guardar el perfil:</div>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Código</th>
                            <th>Nombre</th>
                            <th>Descripción</th>
                            <th>Estado</th>
                            <th class="text-center">Usuarios</th>
                            <th class="text-end pe-3" style="width: 170px">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($perfiles as $perfil)
                        <tr>
                            <td class="ps-3 fw-semibold">{{ $perfil->codigo }}</td>
                            <td>{{ $perfil->nombre }}</td>
                            <td>{{ $perfil->descripcion ?: '—' }}</td>
                            <td>
                                <span class="badge {{ $perfil->activo ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ $perfil->activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                            <td class="text-center">{{ $perfil->usuarios_count }}</td>
                            <td class="text-end pe-3">
                                <div class="d-inline-flex gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-secondary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalEditarPerfil{{ $perfil->id }}"
                                    >Editar</button>

                                    <form method="POST" action="{{ route('perfiles.destroy', $perfil) }}" class="d-inline" onsubmit="return confirm('¿Eliminar el perfil {{ addslashes($perfil->nombre) }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                            @disabled($perfil->codigo === 'ADMINISTRADOR' || $perfil->usuarios_count > 0)
                                        >Eliminar</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No hay perfiles cargados.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="alert alert-light border mt-3 mb-0 small text-muted">
        Un perfil con usuarios asignados no se puede eliminar. El perfil ADMINISTRADOR es reservado por el sistema.
    </div>
</div>

<div class="modal fade" id="modalNuevoPerfil" tabindex="-1" aria-labelledby="modalNuevoPerfilLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('perfiles.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="modalNuevoPerfilLabel">Agregar perfil</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="nuevo_codigo">Código</label>
                        <input type="text" class="form-control" id="nuevo_codigo" name="codigo" value="{{ old('codigo') }}" maxlength="30" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="nuevo_nombre">Nombre</label>
                        <input type="text" class="form-control" id="nuevo_nombre" name="nombre" value="{{ old('nombre') }}" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="nuevo_descripcion">Descripción</label>
                        <textarea class="form-control" id="nuevo_descripcion" name="descripcion" rows="3" maxlength="255">{{ old('descripcion') }}</textarea>
                    </div>
                    <div class="form-check">
                        <input type="hidden" name="activo" value="0">
                        <input class="form-check-input" type="checkbox" id="nuevo_activo" name="activo" value="1" @checked(old('activo', '1') === '1')>
                        <label class="form-check-label" for="nuevo_activo">Activo</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

@foreach ($perfiles as $perfil)
    <div class="modal fade" id="modalEditarPerfil{{ $perfil->id }}" tabindex="-1" aria-labelledby="modalEditarPerfilLabel{{ $perfil->id }}" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('perfiles.update', $perfil) }}">
                    @csrf
                    @method('PUT')
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalEditarPerfilLabel{{ $perfil->id }}">Editar perfil</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="codigo_{{ $perfil->id }}">Código</label>
                            <input
                                type="text"
                                class="form-control"
                                id="codigo_{{ $perfil->id }}"
                                name="codigo"
                                value="{{ $perfil->codigo }}"
                                maxlength="30"
                                required
                                @disabled($perfil->codigo === 'ADMINISTRADOR')
                            >
                            @if ($perfil->codigo === 'ADMINISTRADOR')
                                <input type="hidden" name="codigo" value="ADMINISTRADOR">
                            @endif
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="nombre_{{ $perfil->id }}">Nombre</label>
                            <input type="text" class="form-control" id="nombre_{{ $perfil->id }}" name="nombre" value="{{ $perfil->nombre }}" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="descripcion_{{ $perfil->id }}">Descripción</label>
                            <textarea class="form-control" id="descripcion_{{ $perfil->id }}" name="descripcion" rows="3" maxlength="255">{{ $perfil->descripcion }}</textarea>
                        </div>
                        <div class="form-check">
                            <input type="hidden" name="activo" value="0">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="activo_{{ $perfil->id }}"
                                name="activo"
                                value="1"
                                @checked($perfil->activo)
                                @disabled($perfil->codigo === 'ADMINISTRADOR')
                            >
                            @if ($perfil->codigo === 'ADMINISTRADOR')
                                <input type="hidden" name="activo" value="1">
                            @endif
                            <label class="form-check-label" for="activo_{{ $perfil->id }}">Activo</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach

@if ($errors->any())
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('modalNuevoPerfil');
    if (modalElement && window.bootstrap) {
        new bootstrap.Modal(modalElement).show();
    }
});
</script>
@endif
@endsection
