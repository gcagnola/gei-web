@extends('layouts.app')

@section('title', 'Clientes')
@section('page-title', 'Clientes')

@section('content')
    @php
        $formatearCuentaCobol = static function ($cuenta): string {
            $original = trim((string) $cuenta);
            $digitos = preg_replace('/\D+/', '', $original) ?? '';

            if (strlen($digitos) === 11) {
                return substr($digitos, 0, 4).'/'.substr($digitos, 4, 5).'/'.substr($digitos, 9, 2);
            }

            return $original !== '' ? $original : '—';
        };
    @endphp
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
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('clientes.comprobantes-arca.index') }}" class="btn btn-outline-primary">Comprobantes ARCA</a>
                    <a href="{{ route('clientes.create') }}" class="btn gei-button gei-button--primary">+ Nuevo</a>
                </div>
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
                    <div class="col-md-4">
                        <select name="rol" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filtrar por rol">
                            <option value="TODOS" @selected($rol === 'TODOS')>Todos los roles</option>
                            <option value="PROPIETARIO" @selected($rol === 'PROPIETARIO')>Propietarios</option>
                            <option value="INQUILINO" @selected($rol === 'INQUILINO')>Inquilinos</option>
                            <option value="GARANTE" @selected($rol === 'GARANTE')>Garantes</option>
                            <option value="PROVEEDOR" @selected($rol === 'PROVEEDOR')>Proveedores</option>
                            <option value="OTRO" @selected($rol === 'OTRO')>Otros</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="actividad" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filtrar por estado">
                            <option value="activos" @selected($actividad === 'activos')>Activos</option>
                            <option value="inactivos" @selected($actividad === 'inactivos')>Inactivos</option>
                            <option value="todos" @selected($actividad === 'todos')>Todos</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <select name="facturacion" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filtrar por facturación">
                            <option value="todos" @selected($facturacion === 'todos')>Toda facturación</option>
                            <option value="especial" @selected($facturacion === 'especial')>Facturación especial</option>
                            <option value="no_facturar" @selected($facturacion === 'no_facturar')>No facturar</option>
                            <option value="individual" @selected($facturacion === 'individual')>Individual / copropietarios</option>
                            <option value="agrupacion" @selected($facturacion === 'agrupacion')>Agrupación especial</option>
                            <option value="fiscal" @selected($facturacion === 'fiscal')>Tratamiento fiscal especial</option>
                        </select>
                    </div>
                </div>
            </form>

            <div class="gei-clientes__items">
                @forelse ($clientes as $cliente)
                    <a
                        href="{{ route('clientes.show', ['cliente' => $cliente, 'buscar' => $busqueda, 'rol' => $rol, 'actividad' => $actividad, 'facturacion' => $facturacion, 'page' => $clientes->currentPage()]) }}"
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
                            {{ $cliente->cuentas->pluck('cuenta')->map($formatearCuentaCobol)->implode(' · ') ?: 'Sin cuenta COBOL' }}
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
                                        <td><strong>{{ $formatearCuentaCobol($cuenta->cuenta) }}</strong></td>
                                        <td>{{ $cuenta->activo === null ? 'Sin informar' : ($cuenta->activo ? 'Activa' : 'Inactiva') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">El cliente no tiene cuentas vinculadas.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="gei-card p-4 mb-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                        <div>
                            <h3 class="h5 mb-1">Facturación</h3>
                            <p class="text-muted small mb-0">
                                Configuración operativa por cuenta. Los valores especiales reemplazan el comportamiento general.
                            </p>
                        </div>
                    </div>

                    @forelse ($cuentasFacturacion as $cuentaFacturacion)
                        @php
                            $esEspecialFacturacion =
                                $cuentaFacturacion->facturable === false ||
                                ($cuentaFacturacion->modalidad_facturacion ?? 'NORMAL') !== 'NORMAL' ||
                                ($cuentaFacturacion->destinatario_facturacion ?? 'PROPIETARIO') !== 'PROPIETARIO' ||
                                ($cuentaFacturacion->agrupacion_facturacion ?? 'CONSOLIDADA') !== 'CONSOLIDADA' ||
                                ($cuentaFacturacion->tratamiento_fiscal ?? 'AUTOMATICO') !== 'AUTOMATICO' ||
                                filled($cuentaFacturacion->observaciones_facturacion);

                            $beneficiariosCuenta = $beneficiariosFacturacion->get(
                                $cuentaFacturacion->id,
                                collect()
                            );
                        @endphp

                        <form
                            method="POST"
                            action="{{ route('clientes.facturacion.update', [
                                'cliente' => $clienteSeleccionado->id,
                                'cuentaCorriente' => $cuentaFacturacion->id,
                            ]) }}"
                            class="border rounded p-3 mb-3"
                        >
                            @csrf
                            @method('PUT')

                            <input type="hidden" name="volver_buscar" value="{{ $busqueda }}">
                            <input type="hidden" name="volver_rol" value="{{ $rol }}">
                            <input type="hidden" name="volver_actividad" value="{{ $actividad }}">
                            <input type="hidden" name="volver_facturacion" value="{{ $facturacion }}">
                            <input type="hidden" name="volver_page" value="{{ $clientes->currentPage() }}">

                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                <div>
                                    <strong>{{ $formatearCuentaCobol($cuentaFacturacion->cuenta) }}</strong>
                                    <span class="badge text-bg-light ms-1">{{ ucfirst(strtolower($cuentaFacturacion->dominio)) }}</span>
                                    @if($esEspecialFacturacion)
                                        <span class="badge text-bg-warning ms-1">Facturación especial</span>
                                    @endif
                                </div>

                                @if(!$cuentaFacturacion->cliente_id)
                                    <span class="badge text-bg-danger">Bloqueada: identidad sin resolver</span>
                                @endif
                            </div>

                            <div class="row g-3">
                                <div class="col-md-2">
                                    <label class="form-label">Facturar</label>
                                    <select class="form-select" name="facturable" required>
                                        <option value="1" @selected((bool) $cuentaFacturacion->facturable)>Sí</option>
                                        <option value="0" @selected($cuentaFacturacion->facturable === false)>No</option>
                                    </select>
                                </div>

                                <div class="col-md-2">
                                    <label class="form-label">Modalidad</label>
                                    <select class="form-select" name="modalidad_facturacion" required>
                                        <option value="NORMAL" @selected(($cuentaFacturacion->modalidad_facturacion ?? 'NORMAL') === 'NORMAL')>Normal</option>
                                        <option value="INDIVIDUAL" @selected($cuentaFacturacion->modalidad_facturacion === 'INDIVIDUAL')>Individual</option>
                                    </select>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label">Destinatario</label>
                                    <select class="form-select" name="destinatario_facturacion" required>
                                        <option value="PROPIETARIO" @selected(($cuentaFacturacion->destinatario_facturacion ?? 'PROPIETARIO') === 'PROPIETARIO')>Titular de la cuenta</option>
                                        <option value="COPROPIETARIOS" @selected($cuentaFacturacion->destinatario_facturacion === 'COPROPIETARIOS')>Copropietarios / beneficiarios</option>
                                    </select>
                                </div>

                                <div class="col-md-2">
                                    <label class="form-label">Agrupación</label>
                                    <select class="form-select" name="agrupacion_facturacion" required>
                                        <option value="CONSOLIDADA" @selected(($cuentaFacturacion->agrupacion_facturacion ?? 'CONSOLIDADA') === 'CONSOLIDADA')>Consolidada</option>
                                        <option value="POR_INMUEBLE" @selected($cuentaFacturacion->agrupacion_facturacion === 'POR_INMUEBLE')>Por inmueble</option>
                                        <option value="POR_MOVIMIENTO" @selected($cuentaFacturacion->agrupacion_facturacion === 'POR_MOVIMIENTO')>Por movimiento</option>
                                    </select>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label">Tratamiento fiscal</label>
                                    <select class="form-select" name="tratamiento_fiscal" required>
                                        <option value="AUTOMATICO" @selected(($cuentaFacturacion->tratamiento_fiscal ?? 'AUTOMATICO') === 'AUTOMATICO')>Automático</option>
                                        <option value="FORZAR_A" @selected($cuentaFacturacion->tratamiento_fiscal === 'FORZAR_A')>Forzar A</option>
                                        <option value="FORZAR_B" @selected($cuentaFacturacion->tratamiento_fiscal === 'FORZAR_B')>Forzar B</option>
                                        <option value="SIN_CATEGORIZAR" @selected($cuentaFacturacion->tratamiento_fiscal === 'SIN_CATEGORIZAR')>Sin categorizar</option>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Observaciones</label>
                                    <textarea class="form-control" name="observaciones_facturacion" rows="2">{{ $cuentaFacturacion->observaciones_facturacion }}</textarea>
                                </div>
                            </div>

                            <div class="mt-3 text-end">
                                <button class="btn btn-outline-primary" type="submit">Guardar facturación</button>
                            </div>
                        </form>

                        @if($beneficiariosCuenta->isNotEmpty())
                            @php
                                $beneficiariosActivos = $beneficiariosCuenta->filter(
                                    fn ($beneficiario) => (bool) $beneficiario->activo
                                );

                                $sumaPorcentajesBeneficiarios = $beneficiariosActivos->sum(
                                    fn ($beneficiario) => (float) $beneficiario->porcentaje
                                );

                                $beneficiariosSinCliente = $beneficiariosActivos->filter(
                                    fn ($beneficiario) => empty($beneficiario->cliente_id)
                                );

                                $porcentajesCompletos = abs($sumaPorcentajesBeneficiarios - 100.0) < 0.000001;
                                $beneficiariosVinculados = $beneficiariosSinCliente->isEmpty();

                                if (! $beneficiariosVinculados) {
                                    $estadoBeneficiarios = 'BLOQUEADA';
                                    $claseEstadoBeneficiarios = 'text-bg-danger';
                                } elseif (! $porcentajesCompletos) {
                                    $estadoBeneficiarios = 'INCOMPLETA';
                                    $claseEstadoBeneficiarios = 'text-bg-warning';
                                } else {
                                    $estadoBeneficiarios = 'OK';
                                    $claseEstadoBeneficiarios = 'text-bg-success';
                                }
                            @endphp

                            <div class="ms-md-4 mb-4">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <div class="small fw-semibold">
                                        Beneficiarios de facturación
                                    </div>

                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <span class="small text-muted">
                                            Total activo:
                                            <strong>{{ number_format($sumaPorcentajesBeneficiarios, 2, ',', '.') }} %</strong>
                                        </span>

                                        <span class="badge {{ $claseEstadoBeneficiarios }}">
                                            {{ $estadoBeneficiarios }}
                                        </span>
                                    </div>
                                </div>

                                @if(! $beneficiariosVinculados)
                                    <div class="alert alert-danger py-2 px-3 mb-3">
                                        Hay {{ $beneficiariosSinCliente->count() }}
                                        beneficiario(s) activo(s) sin vínculo con clientes.
                                        La cuenta queda bloqueada para emisión fiscal.
                                    </div>
                                @elseif(! $porcentajesCompletos)
                                    <div class="alert alert-warning py-2 px-3 mb-3">
                                        Los beneficiarios activos suman
                                        {{ number_format($sumaPorcentajesBeneficiarios, 2, ',', '.') }} %.
                                        Para facturación a copropietarios deben sumar 100 %.
                                    </div>
                                @endif

                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>ID origen</th>
                                                <th>Cliente</th>
                                                <th class="text-end">Porcentaje</th>
                                                <th>Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($beneficiariosCuenta as $beneficiarioFacturacion)
                                                <tr class="{{ ! $beneficiarioFacturacion->activo ? 'text-muted' : '' }}">
                                                    <td>
                                                        {{ $beneficiarioFacturacion->identificador_origen ?: '—' }}
                                                    </td>

                                                    <td>
                                                        @if($beneficiarioFacturacion->cliente_id)
                                                            {{ $beneficiarioFacturacion->cliente_nombre ?: 'Cliente #'.$beneficiarioFacturacion->cliente_id }}
                                                            <span class="badge text-bg-light ms-1">
                                                                Vinculado
                                                            </span>
                                                        @else
                                                            <span class="text-danger fw-semibold">
                                                                SIN CLIENTE VINCULADO
                                                            </span>
                                                        @endif
                                                    </td>

                                                    <td class="text-end">
                                                        {{ number_format((float) $beneficiarioFacturacion->porcentaje, 2, ',', '.') }} %
                                                    </td>

                                                    <td>
                                                        @if(! $beneficiarioFacturacion->activo)
                                                            <span class="badge text-bg-secondary">
                                                                Inactivo
                                                            </span>
                                                        @elseif(! $beneficiarioFacturacion->cliente_id)
                                                            <span class="badge text-bg-danger">
                                                                Bloquea emisión
                                                            </span>
                                                        @else
                                                            <span class="badge text-bg-success">
                                                                Activo
                                                            </span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                <div class="small text-muted mt-2">
                                    Información proveniente de la migración COBOL.
                                    En esta etapa se muestra sólo para control y no se modifica desde GeI-Web.
                                </div>
                            </div>
                        @elseif(
                            ($cuentaFacturacion->destinatario_facturacion ?? 'PROPIETARIO') === 'COPROPIETARIOS'
                        )
                            <div class="ms-md-4 mb-4">
                                <div class="alert alert-warning py-2 px-3 mb-0">
                                    <strong>Configuración incompleta:</strong>
                                    la cuenta está configurada para facturar a copropietarios,
                                    pero no tiene beneficiarios registrados.
                                </div>
                            </div>
                        @endif
                    @empty
                        <div class="text-muted">El cliente no tiene cuentas corrientes vinculadas para facturación.</div>
                    @endforelse
                </article>

                @unless ($esSoloInquilino)
                @php
                    $inmueblesActivos = $inmuebles->filter(
                        fn ($inmueble) => strtoupper(trim((string) $inmueble->estado)) === 'ACTIVO'
                    );

                    $inmueblesNoActivos = $inmuebles->reject(
                        fn ($inmueble) => strtoupper(trim((string) $inmueble->estado)) === 'ACTIVO'
                    );
                @endphp

                <article class="gei-card p-4 mb-4">
                    <h3 class="h5">Inmuebles como propietario</h3>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Domicilio</th><th>Cuentas COBOL</th><th>Estado</th></tr></thead>
                            <tbody>
                                @forelse ($inmueblesActivos as $inmueble)
                                    <tr>
                                        <td>{{ $inmueble->domicilio }}</td>
                                        <td>{{ collect(explode(' · ', (string) $inmueble->cuentas))->filter()->map($formatearCuentaCobol)->implode(' · ') ?: '—' }}</td>
                                        <td><span class="badge text-bg-success">{{ $inmueble->estado }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">No tiene inmuebles activos.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($inmueblesNoActivos->isNotEmpty())
                        <details class="mt-3">
                            <summary class="fw-semibold text-muted" style="cursor: pointer;">
                                Inmuebles no activos ({{ $inmueblesNoActivos->count() }})
                            </summary>

                            <div class="table-responsive mt-2">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>Domicilio</th><th>Cuentas COBOL</th><th>Estado</th></tr></thead>
                                    <tbody>
                                        @foreach ($inmueblesNoActivos as $inmueble)
                                            <tr>
                                                <td>{{ $inmueble->domicilio }}</td>
                                                <td>{{ collect(explode(' · ', (string) $inmueble->cuentas))->filter()->map($formatearCuentaCobol)->implode(' · ') ?: '—' }}</td>
                                                <td><span class="badge text-bg-secondary">{{ $inmueble->estado ?: 'INACTIVO' }}</span></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    @endif
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
                                        <td><strong>{{ $formatearCuentaCobol($reparto->cuenta_impresa ?: $reparto->cuenta) }}</strong></td>
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
                            <thead><tr><th>Cuenta</th><th>Inmueble alquilado</th><th>Propietario</th><th>Desde</th><th>Hasta</th><th>Estado</th></tr></thead>
                            <tbody>
                                @forelse ($contratos as $contrato)
                                    <tr>
                                        <td>{{ $formatearCuentaCobol($contrato->cuenta_inquilino) }}</td>
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
                                    <tr><td colspan="6" class="text-muted">No tiene contratos vinculados.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>

                @unless ($esSoloInquilino)
                @php
                    $inquilinosActivos = $inquilinosDePropietario->filter(
                        fn ($vinculo) => strtoupper(trim((string) $vinculo->estado)) === 'ACTIVO'
                    );

                    $inquilinosNoActivos = $inquilinosDePropietario->reject(
                        fn ($vinculo) => strtoupper(trim((string) $vinculo->estado)) === 'ACTIVO'
                    );
                @endphp

                <article class="gei-card p-4 mb-4">
                    <h3 class="h5">Inquilinos de sus inmuebles</h3>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Inquilino</th><th>CUIT</th><th>Cuenta</th><th>Inmueble</th><th>Desde</th><th>Hasta</th><th>Estado</th></tr></thead>
                            <tbody>
                                @forelse ($inquilinosActivos as $vinculo)
                                    <tr>
                                        <td>
                                            <a href="{{ route('clientes.show', ['cliente' => $vinculo->inquilino_id, 'rol' => 'INQUILINO', 'actividad' => 'todos']) }}">
                                                {{ trim($vinculo->inquilino_nombre) }}
                                            </a>
                                        </td>
                                        <td>{{ $vinculo->inquilino_cuit ?: '—' }}</td>
                                        <td>{{ $formatearCuentaCobol($vinculo->cuenta_inquilino) }}</td>
                                        <td>{{ $vinculo->inmueble_domicilio }}</td>
                                        <td>{{ $vinculo->fecha_inicio ? \Carbon\Carbon::parse($vinculo->fecha_inicio)->format('d/m/Y') : '—' }}</td>
                                        <td>{{ $vinculo->fecha_fin ? \Carbon\Carbon::parse($vinculo->fecha_fin)->format('d/m/Y') : '—' }}</td>
                                        <td><span class="badge text-bg-success">{{ $vinculo->estado }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-muted">No tiene inquilinos activos vinculados.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($inquilinosNoActivos->isNotEmpty())
                        <details class="mt-3">
                            <summary class="fw-semibold text-muted" style="cursor: pointer;">
                                Inquilinos no activos ({{ $inquilinosNoActivos->count() }})
                            </summary>

                            <div class="table-responsive mt-2">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>Inquilino</th><th>CUIT</th><th>Cuenta</th><th>Inmueble</th><th>Desde</th><th>Hasta</th><th>Estado</th></tr></thead>
                                    <tbody>
                                        @foreach ($inquilinosNoActivos as $vinculo)
                                            <tr>
                                                <td>
                                                    <a href="{{ route('clientes.show', ['cliente' => $vinculo->inquilino_id, 'rol' => 'INQUILINO', 'actividad' => 'todos']) }}">
                                                        {{ trim($vinculo->inquilino_nombre) }}
                                                    </a>
                                                </td>
                                                <td>{{ $vinculo->inquilino_cuit ?: '—' }}</td>
                                                <td>{{ $formatearCuentaCobol($vinculo->cuenta_inquilino) }}</td>
                                                <td>{{ $vinculo->inmueble_domicilio }}</td>
                                                <td>{{ $vinculo->fecha_inicio ? \Carbon\Carbon::parse($vinculo->fecha_inicio)->format('d/m/Y') : '—' }}</td>
                                                <td>{{ $vinculo->fecha_fin ? \Carbon\Carbon::parse($vinculo->fecha_fin)->format('d/m/Y') : '—' }}</td>
                                                <td><span class="badge text-bg-secondary">{{ $vinculo->estado ?: 'INACTIVO' }}</span></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    @endif
                </article>
                @endunless

                <article class="gei-card p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-2">
                        <div>
                            <h3 class="h5 mb-1">Últimos documentos</h3>
                            <p class="text-muted small mb-0">
                                Liquidaciones, impuestos garantizados y comprobantes fiscales asociados al cliente.
                                La factura se muestra aunque su PDF no esté disponible.
                            </p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Período</th>
                                    <th>Cuenta</th>
                                    <th>Documentos</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($documentos as $documentoPeriodo)
                                    @php
                                        $cantidadLiquidaciones = $documentoPeriodo->liquidaciones->count();
                                        $facturasPeriodo = $documentoPeriodo->facturas ?? collect();
                                        $comprobantesArca = $documentoPeriodo->comprobantes_arca ?? collect();
                                        $cantidadArca = $comprobantesArca->count();

                                        $nombreTipoFactura = static function ($tipo): string {
                                            return match ((int) $tipo) {
                                                1 => 'Factura A',
                                                3 => 'Nota de crédito A',
                                                6 => 'Factura B',
                                                8 => 'Nota de crédito B',
                                                default => 'Comprobante '.$tipo,
                                            };
                                        };
                                    @endphp
                                    <tr>
                                        <td class="text-nowrap align-top">
                                            {{ substr($documentoPeriodo->periodo, 4, 2) }}/{{ substr($documentoPeriodo->periodo, 0, 4) }}
                                        </td>
                                        <td class="align-top">
                                            @foreach ($documentoPeriodo->cuentas as $cuentaDocumento)
                                                <div class="text-nowrap">{{ $formatearCuentaCobol($cuentaDocumento) }}</div>
                                            @endforeach
                                        </td>
                                        <td>
                                            @if ($facturasPeriodo->isNotEmpty())
                                                <div class="vstack gap-2 mb-2">
                                                    @foreach ($facturasPeriodo as $factura)
                                                        @php
                                                            $numeroVisible =
                                                                str_pad((string) ($factura->punto_venta ?? ''), 4, '0', STR_PAD_LEFT)
                                                                .'-'.
                                                                str_pad((string) ($factura->numero_comprobante ?? ''), 8, '0', STR_PAD_LEFT);

                                                        @endphp

                                                        <div class="border rounded px-2 py-2">
                                                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                                                <div>
                                                                    <div>
                                                                        <strong>
                                                                            {{ $nombreTipoFactura($factura->tipo_comprobante) }}
                                                                            {{ $numeroVisible }}
                                                                        </strong>

                                                                        <span class="badge text-bg-success ms-1">
                                                                            {{ ucfirst(strtolower((string) $factura->estado)) }}
                                                                        </span>
                                                                    </div>

                                                                    <div class="small text-muted mt-1">
                                                                        {{ $factura->fecha_comprobante ? \Carbon\Carbon::parse($factura->fecha_comprobante)->format('d/m/Y') : '—' }}
                                                                        · Total:
                                                                        <strong>${{ number_format((float) $factura->total, 2, ',', '.') }}</strong>
                                                                    </div>

                                                                    <div class="small text-muted">
                                                                        CAE {{ $factura->cae ?: '—' }}
                                                                        @if ($factura->vencimiento_cae)
                                                                            · Vto. {{ \Carbon\Carbon::parse($factura->vencimiento_cae)->format('d/m/Y') }}
                                                                        @endif
                                                                    </div>
                                                                </div>

                                                                <div>
                                                                    @if ($factura->pdf_disponible)
                                                                        <a
                                                                            href="{{ route('facturas.pdf.ver', ['factura' => $factura->id_factura]) }}"
                                                                            target="_blank"
                                                                            rel="noopener"
                                                                            class="btn btn-sm btn-outline-primary"
                                                                        >
                                                                            Ver PDF
                                                                        </a>
                                                                    @else
                                                                        <span class="badge text-bg-light">
                                                                            PDF no disponible
                                                                        </span>
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif

                                            <div class="d-flex flex-wrap gap-1 align-items-start">
                                                @foreach ($documentoPeriodo->liquidaciones as $liquidacion)
                                                    @if ($liquidacion->pdf_disponible ?? false)
                                                        <a
                                                            href="{{ route('propietarios.liquidaciones.ver', $liquidacion->id) }}"
                                                            target="_blank"
                                                            rel="noopener"
                                                            class="btn btn-sm btn-outline-primary"
                                                            title="Liquidación {{ $formatearCuentaCobol($liquidacion->cuenta_impresa ?: $liquidacion->cuenta) }}"
                                                        >
                                                            Liquidación{{ $cantidadLiquidaciones > 1 ? ' '.$liquidacion->numero_interno : '' }}
                                                        </a>
                                                    @endif

                                                    @if ($liquidacion->impuestos_pdf_disponible ?? false)
                                                        <a
                                                            href="{{ route('propietarios.liquidaciones.impuestos.ver', $liquidacion->id) }}"
                                                            target="_blank"
                                                            rel="noopener"
                                                            class="btn btn-sm btn-outline-primary"
                                                            title="Impuestos garantizados {{ $formatearCuentaCobol($liquidacion->cuenta_impresa ?: $liquidacion->cuenta) }}"
                                                        >
                                                            Imp. garantizados{{ $cantidadLiquidaciones > 1 ? ' '.$liquidacion->numero_interno : '' }}
                                                        </a>
                                                    @endif
                                                @endforeach

                                                @if ($cantidadArca === 1)
                                                    @php $comprobanteArca = $comprobantesArca->first(); @endphp
                                                    <a
                                                        href="{{ route('comprobantes-arca.ver', [
                                                            'periodo' => $documentoPeriodo->periodo,
                                                            'archivo' => $comprobanteArca->nombre_archivo,
                                                        ]) }}"
                                                        target="_blank"
                                                        rel="noopener"
                                                        class="btn btn-sm btn-outline-primary"
                                                        title="{{ $comprobanteArca->nombre_archivo }}"
                                                    >
                                                        {{ pathinfo($comprobanteArca->nombre_archivo, PATHINFO_FILENAME) }}
                                                    </a>
                                                @elseif ($cantidadArca > 1)
                                                    <div class="dropdown">
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-outline-primary dropdown-toggle"
                                                            data-bs-toggle="dropdown"
                                                            aria-expanded="false"
                                                        >
                                                            ARCA sin migrar ({{ $cantidadArca }})
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end" style="min-width: 330px;">
                                                            @foreach ($comprobantesArca as $comprobanteArca)
                                                                <li>
                                                                    <a
                                                                        class="dropdown-item"
                                                                        href="{{ route('comprobantes-arca.ver', [
                                                                            'periodo' => $documentoPeriodo->periodo,
                                                                            'archivo' => $comprobanteArca->nombre_archivo,
                                                                        ]) }}"
                                                                        target="_blank"
                                                                        rel="noopener"
                                                                    >
                                                                        {{ pathinfo($comprobanteArca->nombre_archivo, PATHINFO_FILENAME) }}
                                                                        <small class="d-block text-muted">
                                                                            Cuenta {{ $formatearCuentaCobol($comprobanteArca->cuenta_cobol) }}
                                                                        </small>
                                                                    </a>
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-muted">
                                            No tiene documentos vinculados en los últimos períodos disponibles.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>
            @else
                <div class="gei-card gei-empty-state gei-empty-state--large">Seleccioná un cliente para consultar sus datos.</div>
            @endif
        </section>
    </div>
@endsection