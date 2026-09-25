@extends('layouts.app')

@section('title', 'Conceptos')
@section('page-title', 'Conceptos')

@section('content')
<div class="container-fluid px-0">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Conceptos</h1>
            <p class="text-muted mb-0">Conceptos de cuentas corrientes de inquilinos y propietarios.</p>
        </div>
    </div>

    @if (session('ok'))
        <div class="alert alert-success">{{ session('ok') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <strong>Revisá los datos ingresados.</strong>
            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="gei-card p-3 p-lg-4 mb-4">
        <h2 class="h5 mb-3">{{ $conceptoEditar ? 'Modificar concepto' : 'Nuevo concepto' }}</h2>

        <form method="POST" action="{{ $conceptoEditar ? route('parametros.conceptos.update', $conceptoEditar) : route('parametros.conceptos.store') }}">
            @csrf
            @if ($conceptoEditar)
                @method('PUT')
            @endif

            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-2">
                    <label class="form-label" for="dominio">Tipo</label>
                    <select class="form-select" id="dominio" name="dominio" required>
                        @php($dominioForm = old('dominio', $conceptoEditar?->dominio ?? 'INQ'))
                        <option value="INQ" @selected($dominioForm === 'INQ')>Inquilino</option>
                        <option value="PROP" @selected($dominioForm === 'PROP')>Propietario</option>
                    </select>
                </div>

                <div class="col-12 col-md-2">
                    <label class="form-label" for="codigo">Código</label>
                    <input
                        class="form-control"
                        id="codigo"
                        name="codigo"
                        value="{{ old('codigo', $conceptoEditar?->codigo) }}"
                        maxlength="2"
                        inputmode="numeric"
                        pattern="[0-9]{2}"
                        required
                    >
                </div>

                <div class="col-12 col-md-5">
                    <label class="form-label" for="descripcion">Descripción</label>
                    <input
                        class="form-control"
                        id="descripcion"
                        name="descripcion"
                        value="{{ old('descripcion', $conceptoEditar?->descripcion) }}"
                        maxlength="120"
                        required
                    >
                </div>

                <div class="col-12 col-md-1">
                    <label class="form-label" for="activo">Estado</label>
                    @php($activoForm = (string) old('activo', $conceptoEditar ? (int) $conceptoEditar->activo : 1))
                    <select class="form-select" id="activo" name="activo" required>
                        <option value="1" @selected($activoForm === '1')>Activo</option>
                        <option value="0" @selected($activoForm === '0')>Inactivo</option>
                    </select>
                </div>

                <div class="col-12 col-md-2 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">{{ $conceptoEditar ? 'Guardar' : 'Agregar' }}</button>
                    @if ($conceptoEditar)
                        <a class="btn btn-outline-secondary" href="{{ route('parametros.conceptos.index', request()->except('editar')) }}">Cancelar</a>
                    @endif
                </div>
            </div>
        </form>
    </div>

    <div class="gei-card p-3 p-lg-4">
        <form method="GET" action="{{ route('parametros.conceptos.index') }}" class="row g-3 mb-4 align-items-end">
            <div class="col-12 col-md-2">
                <label class="form-label" for="filtro-dominio">Tipo</label>
                <select class="form-select" id="filtro-dominio" name="dominio">
                    <option value="">Todos</option>
                    <option value="INQ" @selected($dominio === 'INQ')>Inquilino</option>
                    <option value="PROP" @selected($dominio === 'PROP')>Propietario</option>
                </select>
            </div>

            <div class="col-12 col-md-2">
                <label class="form-label" for="filtro-estado">Estado</label>
                <select class="form-select" id="filtro-estado" name="estado">
                    <option value="activos" @selected($estado === 'activos')>Activos</option>
                    <option value="inactivos" @selected($estado === 'inactivos')>Inactivos</option>
                    <option value="todos" @selected($estado === 'todos')>Todos</option>
                </select>
            </div>

            <div class="col-12 col-md-5">
                <label class="form-label" for="q">Buscar</label>
                <input class="form-control" id="q" name="q" value="{{ $texto }}" placeholder="Código o descripción">
            </div>

            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Buscar</button>
                <a class="btn btn-outline-secondary" href="{{ route('parametros.conceptos.index') }}">Limpiar</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 120px;">Tipo</th>
                        <th style="width: 90px;">Código</th>
                        <th>Descripción</th>
                        <th style="width: 110px;">Estado</th>
                        <th style="width: 135px;">Origen</th>
                        <th class="text-end" style="width: 210px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($conceptos as $concepto)
                        <tr class="{{ $concepto->activo ? '' : 'text-muted' }}">
                            <td>{{ $concepto->dominio === 'INQ' ? 'Inquilino' : 'Propietario' }}</td>
                            <td><strong>{{ $concepto->codigo }}</strong></td>
                            <td>{{ $concepto->descripcion ?: '—' }}</td>
                            <td>{{ $concepto->activo ? 'Activo' : 'Inactivo' }}</td>
                            <td>{{ $concepto->origen_cobol ?: 'Manual' }}</td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-2">
                                    <a
                                        class="btn btn-sm btn-outline-secondary"
                                        href="{{ route('parametros.conceptos.index', array_merge(request()->except('page', 'editar'), ['editar' => $concepto->id])) }}"
                                    >Modificar</a>

                                    <a
                                        class="btn btn-sm btn-outline-secondary"
                                        href="{{ route('parametros.conceptos.caja', $concepto) }}"
                                    >Caja</a>

                                    @if ($concepto->activo)
                                        <form method="POST" action="{{ route('parametros.conceptos.destroy', $concepto) }}" onsubmit="return confirm('¿Dar de baja este concepto?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Baja</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No hay conceptos para los filtros seleccionados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($conceptos->hasPages())
            <div class="mt-4">
                {{ $conceptos->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
