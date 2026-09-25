@extends('layouts.app')

@section('title', 'Sedes')
@section('page-title', 'Sedes')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Sedes</h1>
            <p class="text-muted mb-0">
                Sedes operativas disponibles en GeI.
            </p>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Código</th>
                            <th>Nombre</th>
                            <th class="text-center">Usuarios asignados</th>
                            <th class="text-center">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sucursales as $sucursal)
                            <tr>
                                <td class="ps-3 fw-semibold">{{ $sucursal->codigo }}</td>
                                <td>{{ $sucursal->nombre }}</td>
                                <td class="text-center">{{ $sucursal->usuarios_count }}</td>
                                <td class="text-center">
                                    @if ($sucursal->activa)
                                        <span class="badge text-bg-success">Activa</span>
                                    @else
                                        <span class="badge text-bg-secondary">Inactiva</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    No hay sedes cargadas.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
