@extends('layouts.app')

@section('title', 'Clientes')
@section('page-title', 'Clientes')

@section('content')
    @if (session('estado'))
        <div class="alert alert-success" role="alert">{{ session('estado') }}</div>
    @endif

    <div class="gei-clientes">
        <aside class="gei-card gei-clientes__listado">
            <div class="gei-clientes__listado-header">
                <div>
                    <h1>Clientes</h1>
                    <p>{{ $clientes->total() }} registros encontrados</p>
                </div>
                <a href="{{ route('clientes.create') }}" class="btn gei-button gei-button--primary">+ Nuevo</a>
            </div>

            <form method="GET" action="{{ route('clientes.index') }}" class="p-3 border-bottom">
                <div class="input-group mb-2">
                    <input
                        type="search"
                        name="buscar"
                        value="{{ $busqueda }}"
                        class="form-control"
                        placeholder="Nombre, documento, CUIT o cuenta"
                        aria-label="Buscar clientes"
                    >
                    <button class="btn btn-outline-primary" type="submit">Buscar</button>
                </div>
                <div class="row g-2">
                    <div class="col-md-7">
                        <select name="rol" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filtrar por rol">
                            <option value="TODOS" @selected($rol === 'TODOS')>Todos los roles</option>
                            <option value="PROPIETARIO" @selected($rol === 'PROPIETARIO')>Propietarios</option>
                            <option value="INQUILINO" @selected($rol === 'INQUILINO')>Inquilinos</option>
                            <option value="GARANTE" @selected($rol === 'GARANTE')>Garantes</option>
                            <option value="PROVEEDOR" @selected($rol === 'PROVEEDOR')>Proveedores</option>
                            <option value="OTRO" @selected($rol === 'OTRO')>Otros</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <select name="actividad" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filtrar por estado">
                            <option value="activos" @selected($actividad === 'activos')>Activos</option>
                            <option value="inactivos" @selected($actividad === 'inactivos')>Inactivos</option>
                            <option value="todos" @selected($actividad === 'todos')>Todos</option>
                        </select>
                    </div>
                </div>
            </form>

            <div class="gei-clientes__items">
                @forelse ($clientes as $cliente)
                    <a
                        href="{{ route('clientes.show', ['cliente' => $cliente, 'buscar' => $busqueda, 'rol' => $rol, 'actividad' => $actividad, 'page' => $clientes->currentPage()]) }}"
                        class="gei-cliente-item {{ $clienteSeleccionado?->id === $cliente->id ? 'is-active' : '' }}"
                    >
                        <div class="gei-cliente-item__heading">
                            <strong>{{ $cliente->nombre_visible }}</strong>
                            <span>#{{ $cliente->id }}</span>
                        </div>
                        <div class="gei-cliente-item__meta">
                            @forelse ($cliente->roles as $rolCliente)
                                <span>{{ $rolCliente->nombre }}</span>
                            @empty
                                <span>Sin rol</span>
                            @endforelse
                        </div>
                        <div class="small text-muted mt-1">
                            {{ $cliente->cuentas->pluck('cuenta')->implode(' · ') ?: 'Sin cuenta COBOL' }}
                        </div>
                    </a>
                @empty
                    <div class="p-4 text-muted">No se encontraron clientes.</div>
                @endforelse
            </div>

            @if ($clientes->hasPages())
                <div class="p-3">{{ $clientes->onEachSide(1)->links() }}</div>
            @endif
        </aside>

        <section class="gei-clientes__detalle">
            @if ($clienteSeleccionado)
                <article class="gei-card p-4 mb-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <h2 class="mb-1">{{ $clienteSeleccionado->nombre_visible }}</h2>
                            @php
                                $esPropietario = $clienteSeleccionado->roles->contains(function ($rolCliente) {
                                    $codigo = strtoupper(trim((string) ($rolCliente->codigo ?? '')));
                                    $nombre = strtoupper(trim((string) ($rolCliente->nombre ?? '')));

                                    return $codigo === 'PROPIETARIO' || $nombre === 'PROPIETARIO';
                                });
                                $esInquilino = $clienteSeleccionado->roles->contains(function ($rolCliente) {
                                    $codigo = strtoupper(trim((string) ($rolCliente->codigo ?? '')));
                                    $nombre = strtoupper(trim((string) ($rolCliente->nombre ?? '')));

                                    return $codigo === 'INQUILINO' || $nombre === 'INQUILINO';
                                });
                                $esSoloInquilino = $esInquilino && $clienteSeleccionado->roles->count() === 1;
                            @endphp
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                @foreach ($clienteSeleccionado->roles as $rolCliente)
                                    <span class="badge text-bg-primary">{{ $rolCliente->nombre }}</span>
                                @endforeach
                                @if ($clienteSeleccionado->activo || ! $esPropietario)
                                    <span class="badge {{ $clienteSeleccionado->activo ? 'text-bg-success' : 'text-bg-secondary' }}">
                                        {{ $clienteSeleccionado->activo ? 'Activo' : 'Inactivo' }}
                                    </span>
                                @endif
                            </div>
                        </div>
                        <a href="{{ route('clientes.edit', $clienteSeleccionado) }}" class="btn btn-outline-primary">Modificar</a>
                    </div>

                    <dl class="row mt-4 mb-0">
                        <dt class="col-sm-3">Tipo de persona</dt>
                        <dd class="col-sm-9">{{ ucfirst(strtolower($clienteSeleccionado->tipo_persona)) }}</dd>
                        <dt class="col-sm-3">Documento</dt>
                        <dd class="col-sm-9">{{ trim(($clienteSeleccionado->tipo_documento ?? '').' '.($clienteSeleccionado->numero_documento ?? '')) ?: '—' }}</dd>
                        <dt class="col-sm-3">CUIT</dt>
                        <dd class="col-sm-9">{{ $clienteSeleccionado->cuit ?: '—' }}</dd>
                        <dt class="col-sm-3">Condición IVA</dt>
                        <dd class="col-sm-9">{{ $clienteSeleccionado->condicion_iva ?: '—' }}</dd>
                        <dt class="col-sm-3">Domicilio</dt>
                        <dd class="col-sm-9">{{ collect([$clienteSeleccionado->domicilio, $clienteSeleccionado->localidad, $clienteSeleccionado->provincia])->filter()->implode(', ') ?: '—' }}</dd>
                        <dt class="col-sm-3">Contacto</dt>
                        <dd class="col-sm-9">{{ collect([$clienteSeleccionado->telefono, $clienteSeleccionado->telefono_alternativo, $clienteSeleccionado->email])->filter()->implode(' · ') ?: '—' }}</dd>
                    </dl>
                </article>

                <article class="gei-card p-4 mb-4">
                    <h3 class="h5">Cuentas COBOL</h3>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Rol</th><th>Cuenta</th><th>Estado</th></tr></thead>
                            <tbody>
                                @forelse ($clienteSeleccionado->cuentas as $cuenta)
                                    <tr>
                                        <td>{{ ucfirst(strtolower($cuenta->rol)) }}</td>
                                        <td><strong>{{ $cuenta->cuenta }}</strong></td>
                                        <td>{{ $cuenta->activo === null ? 'Sin informar' : ($cuenta->activo ? 'Activa' : 'Inactiva') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">El cliente no tiene cuentas vinculadas.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>

                @unless ($esSoloInquilino)
                <article class="gei-card p-4 mb-4">
                    <h3 class="h5">Inmuebles como propietario</h3>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Domicilio</th><th>Cuentas COBOL</th><th>Estado</th></tr></thead>
                            <tbody>
                                @forelse ($inmuebles as $inmueble)
                                    <tr>
                                        <td>{{ $inmueble->domicilio }}</td>
                                        <td>{{ $inmueble->cuentas ?: '—' }}</td>
                                        <td>{{ $inmueble->estado }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">No tiene inmuebles vinculados.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="gei-card p-4 mb-4">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                        <div>
                            <h3 class="h5 mb-1">Reparto de cobro vigente</h3>
                            <p class="text-muted small mb-0">
                                Beneficiarios y porcentajes tomados de la última liquidación procesada.
                                Este reparto no implica titularidad del inmueble.
                            </p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Cuenta</th><th>Beneficiario</th><th class="text-end">Porcentaje</th><th>Último período</th></tr></thead>
                            <tbody>
                                @forelse ($repartos as $reparto)
                                    @php
                                        $porcentajeReparto = number_format((float) $reparto->porcentaje, 3, ',', '.');
                                        if (str_ends_with($porcentajeReparto, ',000')) {
                                            $porcentajeReparto = substr($porcentajeReparto, 0, -4);
                                        }
                                    @endphp
                                    <tr>
                                        <td><strong>{{ $reparto->cuenta_impresa ?: $reparto->cuenta }}</strong></td>
                                        <td>
                                            {{ $reparto->beneficiario }}
                                            @if ($reparto->cliente_id)
                                                <span class="badge text-bg-light ms-1">Cliente vinculado</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ $porcentajeReparto }} %</td>
                                        <td>{{ substr($reparto->ultimo_periodo, 4, 2) }}/{{ substr($reparto->ultimo_periodo, 0, 4) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted">Todavía no hay un reparto de cobro sincronizado para sus cuentas.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>
                @endunless

                <article class="gei-card p-4 mb-4">
                    <h3 class="h5">Contratos como inquilino</h3>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Contrato</th><th>Cuenta</th><th>Inmueble alquilado</th><th>Propietario</th><th>Desde</th><th>Hasta</th><th>Estado</th></tr></thead>
                            <tbody>
                                @forelse ($contratos as $contrato)
                                    <tr>
                                        <td>{{ $contrato->codigo_origen }}</td>
                                        <td>{{ $contrato->cuenta_inquilino }}</td>
                                        <td>{{ $contrato->inmuebles ?: '—' }}</td>
                                        <td>
                                            {{ $contrato->propietarios ?: '—' }}
                                            @if (! $contrato->propietarios && $contrato->cuenta_propietario)
                                                <span class="text-muted">(cuenta {{ $contrato->cuenta_propietario }})</span>
                                            @endif
                                        </td>
                                        <td>{{ $contrato->fecha_inicio ? \Carbon\Carbon::parse($contrato->fecha_inicio)->format('d/m/Y') : '—' }}</td>
                                        <td>{{ $contrato->fecha_fin ? \Carbon\Carbon::parse($contrato->fecha_fin)->format('d/m/Y') : '—' }}</td>
                                        <td>{{ $contrato->estado }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-muted">No tiene contratos vinculados.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>

                @unless ($esSoloInquilino)
                <article class="gei-card p-4 mb-4">
                    <h3 class="h5">Inquilinos de sus inmuebles</h3>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Inquilino</th><th>CUIT</th><th>Cuenta</th><th>Inmueble</th><th>Desde</th><th>Hasta</th><th>Estado</th></tr></thead>
                            <tbody>
                                @forelse ($inquilinosDePropietario as $vinculo)
                                    <tr>
                                        <td>
                                            <a href="{{ route('clientes.show', ['cliente' => $vinculo->inquilino_id, 'rol' => 'INQUILINO', 'actividad' => 'todos']) }}">
                                                {{ trim($vinculo->inquilino_nombre) }}
                                            </a>
                                        </td>
                                        <td>{{ $vinculo->inquilino_cuit ?: '—' }}</td>
                                        <td>{{ $vinculo->cuenta_inquilino }}</td>
                                        <td>{{ $vinculo->inmueble_domicilio }}</td>
                                        <td>{{ $vinculo->fecha_inicio ? \Carbon\Carbon::parse($vinculo->fecha_inicio)->format('d/m/Y') : '—' }}</td>
                                        <td>{{ $vinculo->fecha_fin ? \Carbon\Carbon::parse($vinculo->fecha_fin)->format('d/m/Y') : '—' }}</td>
                                        <td>{{ $vinculo->estado }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-muted">No tiene inquilinos vinculados mediante contratos.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>
                @endunless

                @unless ($esSoloInquilino)
                <article class="gei-card p-4">
                    <h3 class="h5">Últimas liquidaciones de propietario</h3>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Período</th><th>Número</th><th>Cuenta</th><th>Comprobante</th><th class="text-end">Total</th><th></th></tr></thead>
                            <tbody>
                                @forelse ($liquidaciones as $liquidacion)
                                    <tr>
                                        <td>{{ substr($liquidacion->periodo, 4, 2) }}/{{ substr($liquidacion->periodo, 0, 4) }}</td>
                                        <td>{{ $liquidacion->numero_interno }}</td>
                                        <td>{{ $liquidacion->cuenta_impresa }}</td>
                                        <td>{{ $liquidacion->comprobante }}</td>
                                        <td class="text-end">$ {{ number_format((float) $liquidacion->total_final, 2, ',', '.') }}</td>
                                        <td class="text-end">
                                            @if ($liquidacion->pdf_ruta)
                                                <a href="{{ route('propietarios.liquidaciones.ver', $liquidacion->id) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">Ver PDF</a>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-muted">No tiene liquidaciones vinculadas.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>
                @endunless
            @else
                <div class="gei-card gei-empty-state gei-empty-state--large">Seleccioná un cliente para consultar sus datos.</div>
            @endif
        </section>
    </div>
@endsection
