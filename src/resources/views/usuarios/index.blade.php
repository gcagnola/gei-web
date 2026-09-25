@extends('layouts.app')

@section('title', 'Usuarios')
@section('page-title', 'Usuarios')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Usuarios y sucursales</h1>
            <p class="text-muted mb-0">
                Definí qué sedes operativas puede consultar cada usuario.
            </p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoUsuario">
            Agregar usuario
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
            <div class="fw-semibold mb-1">No se pudo guardar el usuario:</div>
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
                            <th class="ps-3">Usuario</th>
                            <th>Nombre</th>
                            <th>Perfil</th>
                            <th>Estado</th>
                            <th style="min-width: 280px">Sucursales permitidas</th>
                            <th class="text-end pe-3" style="width: 180px">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($usuarios as $usuario)
                        @php
                            $asignadas = $usuario->sucursales->pluck('id')->map(fn ($id) => (int) $id)->all();
                            $formId = 'form-sucursales-' . $usuario->id;
                        @endphp
                        <tr>
                            <td class="ps-3 fw-semibold">{{ $usuario->nombre_usuario }}</td>
                            <td>{{ $usuario->nombre }}</td>
                            <td>{{ $usuario->perfil?->nombre ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $usuario->activo ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ $usuario->activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap align-items-center gap-3">
                                    @foreach ($sucursales as $sucursal)
                                        <div class="form-check mb-0">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                name="sucursales[]"
                                                value="{{ $sucursal->id }}"
                                                id="usuario-{{ $usuario->id }}-sucursal-{{ $sucursal->id }}"
                                                form="{{ $formId }}"
                                                @checked(in_array((int) $sucursal->id, $asignadas, true))
                                            >
                                            <label class="form-check-label" for="usuario-{{ $usuario->id }}-sucursal-{{ $sucursal->id }}">
                                                <span class="badge {{ $sucursal->codigo === 'SF' ? 'text-bg-secondary' : 'text-bg-info' }}">
                                                    {{ $sucursal->codigo }}
                                                </span>
                                                {{ $sucursal->nombre }}
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            </td>
                            <td class="text-end pe-3">
                                <div class="d-inline-flex align-items-center gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalEditarUsuario{{ $usuario->id }}">
                                        Editar
                                    </button>

                                    <form id="{{ $formId }}" method="POST" action="{{ route('usuarios.sucursales.update', $usuario) }}" class="d-inline">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            Guardar
                                        </button>
                                    </form>

                                    @if ((int) auth()->id() !== (int) $usuario->id)
                                        <form method="POST" action="{{ route('usuarios.destroy', $usuario) }}" class="d-inline" onsubmit="return confirm('¿Eliminar el usuario {{ addslashes($usuario->nombre_usuario) }}?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar usuario">
                                                Eliminar
                                            </button>
                                        </form>
                                    @else
                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="No podés eliminar tu propio usuario">
                                            Eliminar
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No hay usuarios cargados.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="alert alert-light border mt-3 mb-0 small text-muted">
        Un usuario sin sucursales asignadas no podrá consultar Inmuebles. Un usuario con una sola sucursal verá únicamente esa sede; con SF y ST podrá trabajar con ambas.
    </div>
</div>

@foreach ($usuarios as $usuario)
<div class="modal fade" id="modalEditarUsuario{{ $usuario->id }}" tabindex="-1" aria-labelledby="modalEditarUsuarioLabel{{ $usuario->id }}" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ route('usuarios.update', $usuario) }}">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditarUsuarioLabel{{ $usuario->id }}">Modificar usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="editar_nombre_usuario_{{ $usuario->id }}">Usuario</label>
                            <input type="text" class="form-control" id="editar_nombre_usuario_{{ $usuario->id }}" name="nombre_usuario" value="{{ session('editar_usuario_id') == $usuario->id ? old('nombre_usuario', $usuario->nombre_usuario) : $usuario->nombre_usuario }}" maxlength="50" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="editar_nombre_{{ $usuario->id }}">Nombre</label>
                            <input type="text" class="form-control" id="editar_nombre_{{ $usuario->id }}" name="nombre" value="{{ session('editar_usuario_id') == $usuario->id ? old('nombre', $usuario->nombre) : $usuario->nombre }}" maxlength="150" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="editar_email_{{ $usuario->id }}">Email</label>
                            <input type="email" class="form-control" id="editar_email_{{ $usuario->id }}" name="email" value="{{ session('editar_usuario_id') == $usuario->id ? old('email', $usuario->email) : $usuario->email }}" maxlength="255" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="editar_perfil_{{ $usuario->id }}">Perfil</label>
                            <select class="form-select" id="editar_perfil_{{ $usuario->id }}" name="perfil_id" required @disabled((int) auth()->id() === (int) $usuario->id)>
                                @foreach ($perfiles as $perfil)
                                    <option value="{{ $perfil->id }}" @selected((string) (session('editar_usuario_id') == $usuario->id ? old('perfil_id', $usuario->perfil_id) : $usuario->perfil_id) === (string) $perfil->id)>
                                        {{ $perfil->nombre }}
                                    </option>
                                @endforeach
                            </select>
                            @if ((int) auth()->id() === (int) $usuario->id)
                                <input type="hidden" name="perfil_id" value="{{ $usuario->perfil_id }}">
                            @endif
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="editar_activo_{{ $usuario->id }}">Estado</label>
                            <select class="form-select" id="editar_activo_{{ $usuario->id }}" name="activo" required @disabled((int) auth()->id() === (int) $usuario->id)>
                                <option value="1" @selected((string) (session('editar_usuario_id') == $usuario->id ? old('activo', $usuario->activo ? '1' : '0') : ($usuario->activo ? '1' : '0')) === '1')>Activo</option>
                                <option value="0" @selected((string) (session('editar_usuario_id') == $usuario->id ? old('activo', $usuario->activo ? '1' : '0') : ($usuario->activo ? '1' : '0')) === '0')>Inactivo</option>
                            </select>
                            @if ((int) auth()->id() === (int) $usuario->id)
                                <input type="hidden" name="activo" value="1">
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="editar_password_{{ $usuario->id }}">Nueva contraseña</label>
                            <input type="password" class="form-control" id="editar_password_{{ $usuario->id }}" name="password" minlength="6">
                            <div class="form-text">Dejar en blanco para conservar la contraseña actual.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="editar_password_confirmation_{{ $usuario->id }}">Repetir nueva contraseña</label>
                            <input type="password" class="form-control" id="editar_password_confirmation_{{ $usuario->id }}" name="password_confirmation" minlength="6">
                        </div>
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

<div class="modal fade" id="modalNuevoUsuario" tabindex="-1" aria-labelledby="modalNuevoUsuarioLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ route('usuarios.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="modalNuevoUsuarioLabel">Agregar usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="nuevo_nombre_usuario">Usuario</label>
                            <input type="text" class="form-control" id="nuevo_nombre_usuario" name="nombre_usuario" value="{{ old('nombre_usuario') }}" maxlength="50" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="nuevo_nombre">Nombre</label>
                            <input type="text" class="form-control" id="nuevo_nombre" name="nombre" value="{{ old('nombre') }}" maxlength="150" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="nuevo_email">Email</label>
                            <input type="email" class="form-control" id="nuevo_email" name="email" value="{{ old('email') }}" maxlength="255" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="nuevo_perfil">Perfil</label>
                            <select class="form-select" id="nuevo_perfil" name="perfil_id" required>
                                <option value="">Elegir...</option>
                                @foreach ($perfiles as $perfil)
                                    <option value="{{ $perfil->id }}" @selected((string) old('perfil_id') === (string) $perfil->id)>
                                        {{ $perfil->nombre }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="nuevo_password">Contraseña</label>
                            <input type="password" class="form-control" id="nuevo_password" name="password" minlength="6" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="nuevo_password_confirmation">Repetir contraseña</label>
                            <input type="password" class="form-control" id="nuevo_password_confirmation" name="password_confirmation" minlength="6" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label d-block">Sucursales permitidas</label>
                            <div class="d-flex flex-wrap gap-4">
                                @foreach ($sucursales as $sucursal)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="sucursales[]" value="{{ $sucursal->id }}" id="nuevo-sucursal-{{ $sucursal->id }}" @checked(in_array((string) $sucursal->id, array_map('strval', old('sucursales', [])), true))>
                                        <label class="form-check-label" for="nuevo-sucursal-{{ $sucursal->id }}">
                                            <span class="badge {{ $sucursal->codigo === 'SF' ? 'text-bg-secondary' : 'text-bg-info' }}">{{ $sucursal->codigo }}</span>
                                            {{ $sucursal->nombre }}
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Crear usuario</button>
                </div>
            </form>
        </div>
    </div>
</div>

@if ($errors->any())
<script>
document.addEventListener('DOMContentLoaded', function () {
    let modalId = null;

    @if (session('formulario_usuario') === 'nuevo')
        modalId = 'modalNuevoUsuario';
    @elseif (session('formulario_usuario') === 'editar' && session('editar_usuario_id'))
        modalId = 'modalEditarUsuario{{ session('editar_usuario_id') }}';
    @endif

    if (modalId && window.bootstrap) {
        const modalElement = document.getElementById(modalId);
        if (modalElement) {
            new bootstrap.Modal(modalElement).show();
        }
    }
});
</script>
@endif
@endsection
