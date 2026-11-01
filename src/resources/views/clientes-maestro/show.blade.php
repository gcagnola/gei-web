@extends('layouts.app')

@section('title', 'Ficha del cliente')
@section('page-title', 'Clientes · Ficha operativa')

@push('styles')
<style>
    .gei-unificado__listado { position: sticky; top: 1rem; }
    .gei-unificado__items { max-height: 70vh; overflow-y: auto; }
    .gei-unificado__item { display: block; padding: .75rem 1rem; border-bottom: 1px solid #e5e7eb; text-decoration: none; color: inherit; }
    .gei-unificado__item:hover { background: #f3f6fa; }
    .gei-unificado__item.is-active { background: #e5f0ff; border-left: 3px solid #0d6efd; padding-left: calc(1rem - 3px); }
    .gei-unificado__item strong { font-size: .875rem; }
    .gei-unificado__item small { color: #64748b; }
    @media (max-width: 1199.98px) { .gei-unificado__listado { position: static; } .gei-unificado__items { max-height: 20rem; } }
</style>
@endpush

@section('content')
@php
    $tabs = [
        'datos' => 'Datos',
        'facturacion' => 'Facturación',
        'inmuebles' => 'Inmuebles / Contratos',
        'cuenta-corriente' => 'Cuenta corriente',
        'liquidaciones' => 'Liquidaciones',
        'impuestos' => 'Impuestos garantizados',
        'facturas' => 'Comprobantes ARCA',
    ];
    // Documentación exclusiva de cuentas propietarias visibles.
    if (! $cuentas->contains('rol', 'PROPIETARIO')) {
        unset($tabs['liquidaciones'], $tabs['impuestos']);
    }
    $v = static fn ($valor) => $valor === null || trim((string) $valor) === '' ? '—' : $valor;
    $pesos = static fn ($n): string => $n === null ? '—' : '$ '.number_format((float) $n, 2, ',', '.');
    $formatearCuentaCobol = static function ($cuenta): string {
        $original = trim((string) $cuenta);
        $digitos = preg_replace('/[^0-9]/', '', $original) ?: '';
        return strlen($digitos) === 11
            ? substr($digitos, 0, 4).'/'.substr($digitos, 4, 5).'/'.substr($digitos, 9, 2)
            : ($original ?: '—');
    };
    $sedeCuenta = static fn (string $cuenta): string => match (substr($cuenta, 0, 4)) {
        '1103', '1202' => 'SF', '2103', '2202' => 'ST', default => '—',
    };
    $consulta = [...$volver, 'periodo' => $periodo, 'mes' => $mes];
    // No conservar una ficha anterior cuando la búsqueda no devuelve ese cliente.
    // Se usan los resultados ya paginados: sin consultas extra a PostgreSQL.
    $mostrarFicha = $clientesLateral->total() > 0
        && ($buscarLateral === '' || $clientesLateral->getCollection()->contains('id', $operativo->id));
@endphp
<div class="container-fluid py-3">
    <div class="row g-3">
        <aside class="col-12 col-xl-3">
            <div class="card border-0 shadow-sm gei-unificado__listado">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <div><strong>Clientes</strong><div class="small text-muted">{{ number_format($clientesLateral->total(), 0, ',', '.') }} registros</div></div>
                    <a href="{{ route('clientes-maestro.index', $volver) }}" class="btn btn-sm btn-outline-secondary">Listado</a>
                </div>
                <form method="GET" action="{{ route('clientes-maestro.show', ['cliente' => $operativo->id]) }}" class="p-2 border-bottom">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <input type="hidden" name="mes" value="{{ $mes }}">
                    <input type="hidden" name="periodo_actividad" value="{{ $periodoActividad }}">
                    <div class="input-group input-group-sm mb-2">
                        <input class="form-control" type="search" name="buscar" value="{{ $buscarLateral }}" placeholder="Nombre, CUIT, documento o cuenta" aria-label="Buscar clientes">
                        <button type="submit" class="btn btn-outline-primary">Buscar</button>
                    </div>
                    <div class="row g-1">
                        <div class="col-4"><select name="rol" class="form-select form-select-sm" aria-label="Rol" onchange="this.form.submit()">
                            @foreach(['TODOS'=>'Todos', 'PROPIETARIO'=>'Propietarios', 'INQUILINO'=>'Inquilinos'] as $valor => $texto)
                                <option value="{{ $valor }}" @selected($filtroRol === $valor)>{{ $texto }}</option>
                            @endforeach
                        </select></div>
                        <div class="col-4"><select name="estado" class="form-select form-select-sm" aria-label="Estado" onchange="this.form.submit()">
                            @foreach(['activos'=>'Con actividad', 'inactivos'=>'Sin actividad', 'todos'=>'Todos'] as $valor => $texto)
                                <option value="{{ $valor }}" @selected($filtroEstado === $valor)>{{ $texto }}</option>
                            @endforeach
                        </select></div>
                        <div class="col-4"><select name="sede" class="form-select form-select-sm" aria-label="Sede" onchange="this.form.submit()">
                            @if(count($sedesPermitidas) > 1)<option value="TODAS" @selected($sedeElegida === 'TODAS')>Ambas</option>@endif
                            @foreach($sedesPermitidas as $sedePermitida)
                                <option value="{{ $sedePermitida }}" @selected($sedeElegida === $sedePermitida)>{{ $sedePermitida }}</option>
                            @endforeach
                        </select></div>
                    </div>
                </form>
                <div id="gei-unificado-scroll" class="gei-unificado__items">
                    @forelse($clientesLateral as $item)
                        <a id="gei-unificado-{{ $item->id }}"
                           href="{{ route('clientes-maestro.show', ['cliente' => $item->id, ...$consulta, 'page' => $clientesLateral->currentPage(), 'tab' => 'datos']) }}"
                           class="gei-unificado__item {{ $item->id === $operativo->id ? 'is-active' : '' }}"
                           @if($item->id === $operativo->id) aria-current="page" @endif>
                            <div class="d-flex justify-content-between gap-2"><strong>{{ $item->nombre_visible }}</strong><small>#{{ $item->id }}</small></div>
                            <small>{{ $item->cuit ?: ($item->numero_documento ?: 'Sin documento') }}</small>
                        </a>
                    @empty
                        <p class="p-3 small text-muted mb-0">No hay clientes con esos filtros.</p>
                    @endforelse
                </div>
                @if($clientesLateral->hasPages())
                    <nav class="card-footer bg-white px-2 py-2" aria-label="Paginación de clientes">
                        <div class="d-flex flex-nowrap justify-content-between align-items-center gap-1">
                            @if($clientesLateral->onFirstPage())
                                <span class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true">‹ Anterior</span>
                            @else
                                <a class="btn btn-sm btn-outline-secondary" rel="prev" href="{{ $clientesLateral->previousPageUrl() }}">‹ Anterior</a>
                            @endif
                            <span class="small text-muted text-nowrap" aria-label="Página {{ $clientesLateral->currentPage() }} de {{ $clientesLateral->lastPage() }}">
                                {{ $clientesLateral->currentPage() }} / {{ $clientesLateral->lastPage() }}
                            </span>
                            @if($clientesLateral->hasMorePages())
                                <a class="btn btn-sm btn-outline-secondary" rel="next" href="{{ $clientesLateral->nextPageUrl() }}">Siguiente ›</a>
                            @else
                                <span class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true">Siguiente ›</span>
                            @endif
                        </div>
                    </nav>
                @endif
            </div>
        </aside>
        <main class="col-12 col-xl-9">
            @if(! $mostrarFicha)
                <div class="card border-0 shadow-sm">
                    <div class="card-body py-5 text-center">
                        @if($clientesLateral->total() === 0)
                            <h2 class="h5 mb-2">No se encontraron clientes</h2>
                            <p class="text-muted mb-0">No hay clientes que coincidan con los filtros aplicados.</p>
                        @else
                            <h2 class="h5 mb-2">Seleccioná un cliente</h2>
                            <p class="text-muted mb-0">Elegí una fila del listado para abrir su ficha.</p>
                        @endif
                    </div>
                </div>
            @else
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <a href="{{ route('clientes-maestro.index', $volver).'#gei-maestro-cliente-'.$operativo->id }}" class="small text-decoration-none">← Volver a Clientes</a>
            <h1 class="h4 my-1">{{ $operativo->nombre_visible }}</h1>
            <div class="d-flex flex-wrap gap-1">
                @foreach($cuentas->pluck('rol')->unique() as $rolCuenta)
                    <span class="badge text-bg-success">{{ $rolCuenta }}</span>
                @endforeach
                <span class="badge {{ $operativo->actividad_periodo === true ? 'text-bg-success' : 'text-bg-secondary' }}">
                    {{ $operativo->actividad_periodo === null ? 'Sin cuentas visibles' : ($operativo->actividad_periodo ? 'Con actividad' : 'Sin actividad') }}
                    · {{ substr($periodoActividad, 4, 2) }}/{{ substr($periodoActividad, 0, 4) }}
                </span>
                <span class="badge text-bg-light border text-dark">
                    Estado administrativo: {{ $operativo->activo === null ? 'Sin definir' : ($operativo->activo ? 'Activo' : 'Inactivo') }}
                </span>
            </div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            @if($administrador)
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('clientes.edit', ['cliente' => $operativo->id]) }}">Editar datos</a>
            @endif
        </div>
    </div>

    @if(session('estado')) <div class="alert alert-success py-2">{{ session('estado') }}</div> @endif
    <div class="alert alert-light border py-2 small">
        Cliente operativo <strong>#{{ $operativo->id }}</strong> · {{ $cuentas->count() }} cuentas COBOL visibles.
        @if($tab === 'cuenta-corriente')
            Movimientos por cuenta COBOL y rol exactos, desde el cierre de prueba
            o las tablas operativas, desde las fuentes operativas.
        @else
            Datos y relaciones obtenidos del maestro operativo de GeI-Web.
        @endif
        La identidad es única; las cuentas de cada sucursal se consultan por separado.
    </div>

    <ul class="nav nav-tabs flex-nowrap overflow-auto mb-3">
        @foreach($tabs as $codigo => $texto)
            <li class="nav-item">
                <a class="nav-link text-nowrap {{ $tab === $codigo ? 'active' : '' }}"
                   href="{{ route('clientes-maestro.show', ['cliente' => $operativo->id, ...$consulta, 'tab' => $codigo]) }}">{{ $texto }}</a>
            </li>
        @endforeach
    </ul>

    @if($tab === 'datos')
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100"><div class="card-body">
                    <h2 class="h6 mb-3">Identidad · Maestro operativo</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Nombre</dt><dd class="col-sm-8">{{ $operativo->nombre_visible }}</dd>
                        <dt class="col-sm-4">CUIT</dt><dd class="col-sm-8">{{ $v($operativo->cuit) }}</dd>
                        <dt class="col-sm-4">Condición IVA</dt><dd class="col-sm-8">{{ $v($operativo->condicion_iva) }}</dd>
                        <dt class="col-sm-4">Documento</dt><dd class="col-sm-8">{{ $v($operativo->numero_documento) }}</dd>
                        <dt class="col-sm-4">Domicilio</dt><dd class="col-sm-8">{{ $v($operativo->domicilio) }}</dd>
                        <dt class="col-sm-4">Localidad</dt><dd class="col-sm-8">{{ $v($operativo->localidad) }}</dd>
                        <dt class="col-sm-4">Provincia</dt><dd class="col-sm-8">{{ $v($operativo->provincia) }}</dd>
                        <dt class="col-sm-4">Teléfono</dt><dd class="col-sm-8">{{ $v($operativo->telefono) }}</dd>
                        <dt class="col-sm-4">Email</dt><dd class="col-sm-8">{{ $v($operativo->email) }}</dd>
                    </dl>
                </div></div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100"><div class="card-body">
                    <h2 class="h6 mb-3">Cuentas COBOL · Visibles por sucursal</h2>
                    <div class="table-responsive"><table class="table table-sm table-hover align-middle">
                        <thead class="table-light"><tr><th>Rol</th><th>Cuenta</th><th>Sede</th><th>Estado</th><th>Histórico</th></tr></thead>
                        <tbody>
                        @forelse($cuentas as $cuenta)
                            <tr>
                                <td>{{ $cuenta->rol }}</td><td class="fw-semibold">{{ $formatearCuentaCobol($cuenta->cuenta) }}</td>
                                <td>{{ $sedeCuenta($cuenta->cuenta) }}</td>
                                <td>
                                    <span class="badge {{ $cuenta->actividad_periodo ? 'text-bg-success' : 'text-bg-secondary' }}">
                                        {{ $cuenta->actividad_periodo ? 'Con actividad' : 'Sin actividad' }}
                                    </span>
                                    <div class="text-muted small">Administrativo:
                                        {{ $cuenta->activo === null ? 'Sin definir' : ($cuenta->activo ? 'Activa' : 'Inactiva') }}
                                    </div>
                                </td>
                                <td><button type="button" class="btn btn-sm btn-outline-secondary js-gei-historico"
                                    data-panel="historico-cuenta-{{ $cuenta->id }}"
                                    data-url="{{ route('clientes-maestro.historico', ['cliente' => $operativo->id, 'rol' => $cuenta->rol, 'cuenta' => $cuenta->cuenta, 'fragmento' => 1]) }}"
                                    aria-expanded="false">Por período ▾</button></td>
                            </tr>
                            <tr id="historico-cuenta-{{ $cuenta->id }}" class="table-light" hidden>
                                <td colspan="5" class="p-3"><div class="js-gei-historico-contenido" role="status" aria-live="polite"></div></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted">Sin cuenta COBOL vinculada.</td></tr>
                        @endforelse
                        </tbody>
                    </table></div>
                </div></div>
            </div>
        </div>
        <div class="card border-0 shadow-sm mt-3"><div class="card-body">
            <h2 class="h6 mb-3">Autogestión</h2>
            @if($administrador)
                <x-portal-estado-cliente :cliente-id="$operativo->id" />
            @else
                <p class="small text-muted mb-0">La administración de accesos de Autogestión está reservada a usuarios autorizados.</p>
            @endif
        </div></div>
    @elseif($tab === 'facturacion')
        <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold">Configuración de facturación por cuenta COBOL visible</div>
            <div class="card-body">
                <p class="small text-muted">La configuración corresponde a cada cuenta COBOL visible. Los cambios se validan por cliente canónico, cuenta, rol y sucursal.</p>
                @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
                @forelse($facturacionesVisibles as $cuentaFacturacion)
                    <div class="border rounded p-3 mb-3">
                        <strong>{{ $formatearCuentaCobol($cuentaFacturacion->cuenta) }}</strong>
                        <span class="badge text-bg-light border text-dark ms-1">{{ $cuentaFacturacion->dominio }}</span>
                        @if($administrador)
                            <form method="POST" action="{{ route('clientes-maestro.facturacion.update', ['cliente' => $operativo->id, 'cuentaCorriente' => $cuentaFacturacion->id]) }}" class="mt-3">
                                @csrf @method('PUT')
                                @foreach($volver as $clave => $valor)
                                    @if(in_array($clave, ['buscar', 'rol', 'estado', 'sede', 'page'], true) && is_scalar($valor))
                                        <input type="hidden" name="volver_{{ $clave }}" value="{{ $valor }}">
                                    @endif
                                @endforeach
                                <input type="hidden" name="volver_mes" value="{{ $mes }}">
                                <div class="row g-2">
                                    <div class="col-lg-2"><label class="form-label small">Facturable</label>
                                        <select class="form-select form-select-sm" name="facturable" required>
                                            <option value="1" @selected((bool) $cuentaFacturacion->facturable)>Sí</option>
                                            <option value="0" @selected($cuentaFacturacion->facturable === false)>No</option>
                                        </select></div>
                                    <div class="col-lg-2"><label class="form-label small">Modalidad</label>
                                        <select class="form-select form-select-sm" name="modalidad_facturacion" required>
                                            @foreach(['NORMAL'=>'Normal', 'INDIVIDUAL'=>'Individual'] as $codigo => $texto)
                                                <option value="{{ $codigo }}" @selected($cuentaFacturacion->modalidad_facturacion === $codigo)>{{ $texto }}</option>
                                            @endforeach
                                        </select></div>
                                    <div class="col-lg-3"><label class="form-label small">Destinatario</label>
                                        <select class="form-select form-select-sm" name="destinatario_facturacion" required>
                                            @foreach(['PROPIETARIO'=>'Titular', 'COPROPIETARIOS'=>'Copropietarios / beneficiarios'] as $codigo => $texto)
                                                <option value="{{ $codigo }}" @selected($cuentaFacturacion->destinatario_facturacion === $codigo)>{{ $texto }}</option>
                                            @endforeach
                                        </select></div>
                                    <div class="col-lg-2"><label class="form-label small">Agrupación</label>
                                        <select class="form-select form-select-sm" name="agrupacion_facturacion" required>
                                            @foreach(['CONSOLIDADA'=>'Consolidada', 'POR_INMUEBLE'=>'Por inmueble', 'POR_MOVIMIENTO'=>'Por movimiento'] as $codigo => $texto)
                                                <option value="{{ $codigo }}" @selected($cuentaFacturacion->agrupacion_facturacion === $codigo)>{{ $texto }}</option>
                                            @endforeach
                                        </select></div>
                                    <div class="col-lg-3"><label class="form-label small">Tratamiento fiscal</label>
                                        <select class="form-select form-select-sm" name="tratamiento_fiscal" required>
                                            @foreach(['AUTOMATICO'=>'Automático', 'FORZAR_A'=>'Forzar A', 'FORZAR_B'=>'Forzar B', 'SIN_CATEGORIZAR'=>'Sin categorizar'] as $codigo => $texto)
                                                <option value="{{ $codigo }}" @selected($cuentaFacturacion->tratamiento_fiscal === $codigo)>{{ $texto }}</option>
                                            @endforeach
                                        </select></div>
                                    <div class="col-12"><label class="form-label small">Observaciones</label>
                                        <textarea class="form-control form-control-sm" name="observaciones_facturacion" maxlength="1000" rows="2">{{ $cuentaFacturacion->observaciones_facturacion }}</textarea></div>
                                </div>
                                <button class="btn btn-sm btn-outline-primary mt-3" type="submit">Guardar facturación</button>
                            </form>
                        @else
                            <dl class="row small mt-2 mb-0">
                                <dt class="col-sm-3">Facturable</dt><dd class="col-sm-3">{{ $cuentaFacturacion->facturable === null ? 'Sin definir' : ($cuentaFacturacion->facturable ? 'Sí' : 'No') }}</dd>
                                <dt class="col-sm-3">Modalidad</dt><dd class="col-sm-3">{{ $v($cuentaFacturacion->modalidad_facturacion) }}</dd>
                                <dt class="col-sm-3">Destinatario</dt><dd class="col-sm-3">{{ $v($cuentaFacturacion->destinatario_facturacion) }}</dd>
                                <dt class="col-sm-3">Agrupación</dt><dd class="col-sm-3">{{ $v($cuentaFacturacion->agrupacion_facturacion) }}</dd>
                                <dt class="col-sm-3">Tratamiento fiscal</dt><dd class="col-sm-3">{{ $v($cuentaFacturacion->tratamiento_fiscal) }}</dd>
                                <dt class="col-sm-3">Observaciones</dt><dd class="col-sm-9">{{ $v($cuentaFacturacion->observaciones_facturacion) }}</dd>
                            </dl>
                        @endif
                        @if(($beneficiariosFacturacion->get($cuentaFacturacion->id) ?? collect())->isNotEmpty())
                            @php
                                $beneficiarios = $beneficiariosFacturacion->get($cuentaFacturacion->id);
                                $beneficiariosActivos = $beneficiarios->where('activo', true);
                                $totalPorcentaje = $beneficiariosActivos->sum(fn ($b) => (float)$b->porcentaje);
                                $beneficiariosSinVinculo = $beneficiariosActivos->contains(fn ($b) => $b->cliente_id === null);
                                $estadoBenef = $beneficiariosSinVinculo ? 'BLOQUEADA' :
                                    (abs($totalPorcentaje - 100.0) < 0.000001 ? 'OK' : 'INCOMPLETA');
                            @endphp
                            <div class="mt-3 border-top pt-2">
                                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                    <strong class="small">Beneficiarios</strong>
                                    <span class="small text-muted">Total activo: {{ number_format($totalPorcentaje, 2, ',', '.') }} %</span>
                                    <span class="badge {{ $estadoBenef === 'OK' ? 'text-bg-success' : 'text-bg-warning' }}">{{ $estadoBenef }}</span>
                                </div>
                                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>Beneficiario</th><th>Identificador</th><th>Porcentaje</th><th>Activo</th>@if($administrador)<th>Acción</th>@endif</tr></thead>
                                    <tbody>
                                        @foreach($beneficiarios as $beneficiario)
                                            @php $formId = 'gei-beneficiario-'.$beneficiario->id_cuenta_facturacion_beneficiario; @endphp
                                            <tr>
                                                <td>{{ $v($beneficiario->beneficiario_nombre) }}</td>
                                                <td>{{ $v($beneficiario->identificador_origen) }}</td>
                                                @if($administrador)
                                                    <td><input form="{{ $formId }}" class="form-control form-control-sm" type="number" step="0.001" min="0.001" max="100" name="porcentaje" value="{{ $beneficiario->porcentaje }}" required style="max-width:120px"></td>
                                                    <td><input form="{{ $formId }}" type="checkbox" name="activo" value="1" @checked($beneficiario->activo) aria-label="Beneficiario activo"></td>
                                                    <td><form id="{{ $formId }}" method="POST" action="{{ route('clientes-maestro.facturacion.beneficiarios.update', ['cliente' => $operativo->id, 'beneficiario' => $beneficiario->id_cuenta_facturacion_beneficiario]) }}">
                                                        @csrf @method('PUT')
                                                        @foreach($volver as $clave => $valor)
                                                            @if(in_array($clave, ['buscar', 'rol', 'estado', 'sede', 'page'], true) && is_scalar($valor))
                                                                <input type="hidden" name="volver_{{ $clave }}" value="{{ $valor }}">
                                                            @endif
                                                        @endforeach
                                                        <input type="hidden" name="volver_mes" value="{{ $mes }}">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">Guardar</button>
                                                    </form></td>
                                                @else
                                                    <td>{{ number_format((float)$beneficiario->porcentaje, 2, ',', '.') }}%</td>
                                                    <td>{{ $beneficiario->activo ? 'Sí' : 'No' }}</td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table></div>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-muted mb-0">Sin configuración de facturación asociada a las cuentas COBOL visibles.</p>
                @endforelse
            </div>
        </div>
    @elseif($tab === 'inmuebles')
        @php
            $inmuebles = $vinculosOperativos['inmuebles'];
            // GEI_FICHA_CONTRATO_AMBOS_ROLES_20261030
            $activos = $inmuebles->where('activo', true);
            $noActivos = $inmuebles->where('activo', false);
            $repartos = $vinculosOperativos['repartos'];
            $contratos = $vinculosOperativos['contratos'];
            $contratosActivos = $contratos->where('estado', 'ACTIVO');
            $contratosNoActivos = $contratos->reject(fn ($c) => $c->estado === 'ACTIVO');
            $inquilinos = $vinculosOperativos['inquilinos'];
            $inquilinosActivos = $inquilinos->filter(fn ($i) => $i->estado === 'ACTIVO' && (bool)$i->vinculo_activo);
            $inquilinosNoActivos = $inquilinos->reject(fn ($i) => $i->estado === 'ACTIVO' && (bool)$i->vinculo_activo);
            $fecha = static fn ($f): string => $f ? \Illuminate\Support\Carbon::parse($f)->format('d/m/Y') : '—';
        @endphp

        @if($fichasContratosError)
            <div class="alert alert-warning small">{{ $fichasContratosError }} Los vínculos operativos siguen disponibles.</div>
        @endif
        <section class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Inmuebles activos del propietario</div>
            <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Domicilio</th><th>Cuentas COBOL</th><th>Estado</th></tr></thead>
                <tbody>
                    @forelse($activos as $inmueble)
                        <tr><td>{{ $inmueble->domicilio }}</td><td>{{ $inmueble->cuentas->map($formatearCuentaCobol)->implode(' · ') }}</td>
                            <td><span class="badge text-bg-success">Activo</span></td></tr>
                    @empty
                        <tr><td colspan="3" class="text-muted py-3">No tiene inmuebles activos asociados a sus cuentas de propietario visibles.</td></tr>
                    @endforelse
                </tbody>
            </table></div>
            @if($noActivos->isNotEmpty())
                <div class="card-body pt-2"><details><summary class="fw-semibold" style="cursor:pointer">Mostrar inmuebles no activos ({{ $noActivos->count() }})</summary>
                    <div class="table-responsive mt-2"><table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Domicilio</th><th>Cuentas COBOL</th><th>Estado</th></tr></thead>
                        <tbody>@foreach($noActivos as $inmueble)
                            <tr><td>{{ $inmueble->domicilio }}</td><td>{{ $inmueble->cuentas->map($formatearCuentaCobol)->implode(' · ') }}</td>
                                <td>{{ $inmueble->estado }}</td></tr>
                        @endforeach</tbody>
                    </table></div>
                </details></div>
            @endif
        </section>

        <section class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Reparto de cobro vigente</div>
            <div class="card-body pb-1 small text-muted">Beneficiarios y porcentajes de la última liquidación procesada. Este reparto no implica titularidad del inmueble.</div>
            <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Cuenta</th><th>Beneficiario</th><th class="text-end">Porcentaje</th><th>Último período</th></tr></thead>
                <tbody>@forelse($repartos as $reparto)
                    <tr><td>{{ $formatearCuentaCobol($reparto->cuenta_impresa ?: $reparto->cuenta) }}</td>
                        <td>{{ $reparto->beneficiario }}</td>
                        <td class="text-end">{{ number_format((float)$reparto->porcentaje, 3, ',', '.') }} %</td>
                        <td>{{ substr((string)$reparto->ultimo_periodo, 4, 2) }}/{{ substr((string)$reparto->ultimo_periodo, 0, 4) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="text-muted py-3">No hay reparto vigente sincronizado para las cuentas de propietario visibles.</td></tr>
                @endforelse</tbody>
            </table></div>
        </section>

        <section class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Inquilinos de sus inmuebles</div>
            <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Inquilino</th><th>CUIT</th><th>Cuenta</th><th>Inmueble</th><th>Desde</th><th>Hasta</th></tr></thead>
                <tbody>@forelse($inquilinosActivos as $vinculo)
                    <tr><td>{{ $vinculo->inquilino_nombre }}</td><td>{{ $v($vinculo->inquilino_cuit) }}</td>
                        <td>{{ $formatearCuentaCobol($vinculo->cuenta_inquilino) }}</td><td>{{ $vinculo->inmueble_domicilio }}</td>
                        <td>{{ $fecha($vinculo->fecha_inicio) }}</td><td>{{ $fecha($vinculo->fecha_fin) }}</td></tr>
                    <tr><td colspan="6" class="bg-light">
                        @include("clientes-maestro.partials.ficha-contrato-cobol", ["idContratoFicha" => $vinculo->contrato_id, "contextoFicha" => "propietario"])
                    </td></tr>
                @empty
                    <tr><td colspan="6" class="text-muted py-3">No hay inquilinos activos vinculados a inmuebles de esta sucursal.</td></tr>
                @endforelse</tbody>
            </table></div>
            @if($inquilinosNoActivos->isNotEmpty())
                <div class="card-body pt-2"><details><summary class="fw-semibold" style="cursor:pointer">Mostrar inquilinos no activos ({{ $inquilinosNoActivos->count() }})</summary>
                    <div class="table-responsive mt-2"><table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Inquilino</th><th>Cuenta</th><th>Inmueble</th><th>Desde</th><th>Hasta</th><th>Estado</th></tr></thead>
                        <tbody>@foreach($inquilinosNoActivos as $vinculo)
                            <tr><td>{{ $vinculo->inquilino_nombre }}</td><td>{{ $formatearCuentaCobol($vinculo->cuenta_inquilino) }}</td>
                                <td>{{ $vinculo->inmueble_domicilio }}</td><td>{{ $fecha($vinculo->fecha_inicio) }}</td>
                                <td>{{ $fecha($vinculo->fecha_fin) }}</td><td>{{ $vinculo->estado }}</td></tr>
                            <tr><td colspan="6" class="bg-light">
                                @include("clientes-maestro.partials.ficha-contrato-cobol", ["idContratoFicha" => $vinculo->contrato_id, "contextoFicha" => "propietario"])
                            </td></tr>
                        @endforeach</tbody>
                    </table></div>
                </details></div>
            @endif
        </section>

        <section class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Contratos como inquilino</div>
            <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Cuenta</th><th>Inmueble alquilado</th><th>Propietario</th><th>Desde</th><th>Hasta</th><th>Estado</th></tr></thead>
                <tbody>@forelse($contratosActivos as $contrato)
                    <tr><td>{{ $formatearCuentaCobol($contrato->cuenta_inquilino) }}</td>
                        <td>{{ $v($contrato->inmuebles) }}</td><td>{{ $v($contrato->propietarios) }}</td><td>{{ $fecha($contrato->fecha_inicio) }}</td>
                        <td>{{ $fecha($contrato->fecha_fin) }}</td><td><span class="badge text-bg-success">Activo</span></td></tr>
                    <tr><td colspan="6" class="bg-light">
                        @include("clientes-maestro.partials.ficha-contrato-cobol", ["idContratoFicha" => $contrato->id, "contextoFicha" => "inquilino"])
                    </td></tr>
                @empty
                    <tr><td colspan="6" class="text-muted py-3">No tiene contratos activos como inquilino en cuentas autorizadas.</td></tr>
                @endforelse</tbody>
            </table></div>
            @if($contratosNoActivos->isNotEmpty())
                <div class="card-body pt-2"><details><summary class="fw-semibold" style="cursor:pointer">Mostrar contratos no activos ({{ $contratosNoActivos->count() }})</summary>
                    <div class="table-responsive mt-2"><table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Cuenta</th><th>Inmueble alquilado</th><th>Propietario</th><th>Desde</th><th>Hasta</th><th>Estado</th></tr></thead>
                        <tbody>@foreach($contratosNoActivos as $contrato)
                            <tr><td>{{ $formatearCuentaCobol($contrato->cuenta_inquilino) }}</td>
                                <td>{{ $v($contrato->inmuebles) }}</td><td>{{ $v($contrato->propietarios) }}</td><td>{{ $fecha($contrato->fecha_inicio) }}</td>
                                <td>{{ $fecha($contrato->fecha_fin) }}</td><td>{{ $contrato->estado }}</td></tr>
                    <tr><td colspan="6" class="bg-light">
                        @include("clientes-maestro.partials.ficha-contrato-cobol", ["idContratoFicha" => $contrato->id, "contextoFicha" => "inquilino"])
                    </td></tr>
                        @endforeach</tbody>
                    </table></div>
                </details></div>
            @endif
        </section>
    @else
        @php
            $titulo = $tabs[$tab];
            $mesActual = substr($mes, 0, 4).'-'.substr($mes, 4, 2);
        @endphp
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <strong>{{ $titulo }} · {{ substr($mes,4,2) }}-{{ substr($mes,0,4) }}</strong>
                @if($tab === 'cuenta-corriente')
                    @php
                        $mesFecha = \Carbon\CarbonImmutable::create((int) substr($mes, 0, 4), (int) substr($mes, 4, 2), 1);
                        $mesAnterior = $mesFecha->subMonthNoOverflow()->format('Ym');
                        $mesSiguiente = $mesFecha->addMonthNoOverflow()->format('Ym');
                        $mesEnCurso = now()->format('Ym');
                        // Conservar filtros de sede/cliente; no transportar el 'page' de un mes anterior.
                        $filtrosNavegacionMes = request()->only(['buscar', 'rol', 'estado', 'sede']);
                        $rutaMesCuenta = static fn (string $nuevoMes) => route('clientes-maestro.show', [
                            'cliente' => $operativo->id,
                            ...$filtrosNavegacionMes,
                            'tab' => 'cuenta-corriente',
                            'mes' => $nuevoMes,
                        ]);
                    @endphp
                    <nav class="d-flex flex-wrap align-items-center gap-1" aria-label="Navegación mensual de cuenta corriente">
                        <a href="{{ $rutaMesCuenta($mesAnterior) }}" class="btn btn-sm btn-outline-primary" aria-label="Ver mes anterior">&larr; Anterior</a>
                        @if($mesSiguiente <= $mesEnCurso)
                            <a href="{{ $rutaMesCuenta($mesSiguiente) }}" class="btn btn-sm btn-outline-primary" aria-label="Ver mes siguiente">Siguiente &rarr;</a>
                        @else
                            <span class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true">Siguiente &rarr;</span>
                        @endif
                        @if($mes !== $mesEnCurso)
                            <a href="{{ $rutaMesCuenta($mesEnCurso) }}" class="btn btn-sm btn-primary">Mes en curso</a>
                        @else
                            <span class="badge bg-success-subtle text-success-emphasis border">Mes en curso</span>
                        @endif
                    </nav>
                @endif
                <form method="GET" action="{{ route('clientes-maestro.show', ['cliente' => $operativo->id]) }}" class="d-flex align-items-center gap-2">
                    @foreach($consulta as $key => $valor)
                        @if($key !== 'mes' && is_scalar($valor) && $valor !== null)
                            <input type="hidden" name="{{ $key }}" value="{{ $valor }}">
                        @endif
                    @endforeach
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <input type="hidden" name="mes" class="js-gei-mes-valor" value="{{ $mes }}">
                    <div class="dropdown" data-gei-mes-selector>
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                                aria-label="Elegir mes y año">
                            <span class="js-gei-mes-etiqueta">{{ substr($mes,4,2) }}-{{ substr($mes,0,4) }}</span> &#128197;
                        </button>
                        <div class="dropdown-menu p-3 shadow" style="width:270px;max-width:90vw">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary js-gei-mes-anio-atras" aria-label="Año anterior">‹</button>
                                <strong class="js-gei-mes-anio">{{ substr($mes,0,4) }}</strong>
                                <button type="button" class="btn btn-sm btn-outline-secondary js-gei-mes-anio-adelante" aria-label="Año siguiente">›</button>
                            </div>
                            <div class="row row-cols-3 g-1">
                                @foreach(['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'] as $indice => $nombreMes)
                                    @php
                                        $mesOpcion = str_pad($indice + 1, 2, '0', STR_PAD_LEFT);
                                    @endphp
                                    <div class="col"><button type="button" class="btn btn-sm btn-outline-primary w-100 js-gei-mes-elegir"
                                        data-mes="{{ $mesOpcion }}" @if($tab === 'cuenta-corriente') data-hasta="{{ now()->format('Ym') }}" @endif>{{ $nombreMes }}</button></div>
                                @endforeach
                            </div>
                            <div class="text-muted small mt-2">Elegí el año con las flechas y después el mes.</div>
                        </div>
                    </div>
                    <noscript><input type="month" class="form-control form-control-sm" name="mes" value="{{ $mesActual }}"></noscript>
                </form>
            </div>
            @if($tab === 'cuenta-corriente')
                <div class="card-body">
                    {{-- Vista conciliada GIMB68: cuenta por cuenta, sin fusionar ledgers. --}}
                    @include('clientes-maestro.partials.cuenta-corriente-cobol-gimb68')
                    {{-- Evitar dos saldos distintos para las cuentas ya reconstruidas
                         desde el MISMO cierre COBOL que usa Autogestión. --}}
                    @include('clientes-maestro.partials.saldos-e-intereses', [
                        'resumenSaldosCuentas' => $resumenSaldosCuentas->reject(
                            static fn ($r) => $cuentasCobolGimb68->has($r->rol.'|'.$r->cuenta)
                        ),
                    ])
                    @if($actividad->isNotEmpty() || $cuentasCobolGimb68->isEmpty())
                        @include('clientes-maestro.partials.movimientos-operativos', [
                            'movimientosOperativos' => $actividad,
                            'resumenesOperativos' => $resumenSaldosCuentas,
                        ])
                    @endif
                </div>
            @elseif($tab === 'liquidaciones' || $tab === 'impuestos')
                <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Período</th><th>Cuenta COBOL</th><th>Nº</th><th>Propietario</th><th class="text-end">Total</th><th>Documento</th></tr></thead>
                    <tbody>
                        @forelse($actividad as $liq)
                            <tr>
                                <td>{{ substr($liq->periodo,4,2) }}-{{ substr($liq->periodo,0,4) }}</td>
                                <td class="fw-semibold">{{ $formatearCuentaCobol($liq->cuenta) }}</td>
                                <td>{{ $v($liq->numero_interno) }}</td>
                                <td>{{ $v($liq->propietario) }}</td><td class="text-end">{{ $pesos($liq->total_final) }}</td>
                                <td>
                                    @if($tab === 'liquidaciones' && $liq->pdf_disponible)
                                        <a target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"
                                           href="{{ route('clientes-maestro.liquidacion.pdf', ['cliente' => $operativo->id, 'liquidacion' => $liq->id, 'tipo' => 'liquidacion']) }}">Ver PDF</a>
                                    @elseif($tab === 'impuestos' && ($liq->impuestos_pdf_disponible ?? false))
                                        <a target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"
                                           href="{{ route('clientes-maestro.liquidacion.pdf', ['cliente' => $operativo->id, 'liquidacion' => $liq->id, 'tipo' => 'impuestos']) }}">Ver impuestos</a>
                                    @else
                                        <span class="text-muted small">Sin PDF disponible</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">Sin registros para las cuentas autorizadas en el mes elegido.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            @elseif($tab === 'facturas')
                <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th>Lote</th><th>Fecha</th><th>Comprobante</th><th>Cuenta COBOL</th>
                        <th>Detalle del lote</th><th class="text-end">Total</th><th>CAE</th><th>Vto. CAE</th><th>PDF</th>
                    </tr></thead>
                    <tbody>
                        @forelse($actividad as $factura)
                            <tr>
                                <td class="fw-semibold">{{ $v($factura->lote) }}</td>
                                <td>{{ $factura->fecha ? \Illuminate\Support\Carbon::parse($factura->fecha)->format('d/m/Y') : '—' }}</td>
                                <td>{{ $v($factura->comprobante) }}</td>
                                <td class="fw-semibold">{{ $formatearCuentaCobol($factura->cuenta_cobol) }}</td>
                                <td>{{ $v($factura->detalle_lote) }}</td>
                                <td class="text-end">{{ $pesos($factura->total) }}</td>
                                <td>{{ $v($factura->cae) }}</td>
                                <td>{{ $factura->vto_cae ? \Illuminate\Support\Carbon::parse($factura->vto_cae)->format('d/m/Y') : '—' }}</td>
                                <td>
                                    @if($factura->pdf_disponible && $factura->nombre_archivo)
                                        <a target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"
                                           href="{{ route('clientes-maestro.arca.pdf', [
                                               'cliente' => $operativo->id,
                                               'periodo' => $mes,
                                               'archivo' => $factura->nombre_archivo,
                                           ]) }}">Ver PDF</a>
                                    @else
                                        <span class="text-muted small">Sin PDF</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted py-4">Sin facturas ARCA para las cuentas autorizadas en este mes.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            @endif
        </div>
        <p class="small text-muted">Las consultas y los accesos a PDF verifican la cuenta COBOL exacta y la sucursal en cada solicitud. Los archivos inexistentes no se reconstruyen.</p>
    @endif
            @endif {{-- $mostrarFicha --}}
        </main>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const listado = document.getElementById('gei-unificado-scroll');
    const seleccionado = listado?.querySelector('.gei-unificado__item.is-active');
    if (listado && seleccionado) {
        listado.scrollTop = Math.max(0, seleccionado.offsetTop - listado.offsetTop - 100);
    }

    document.querySelectorAll('.js-gei-historico').forEach(function (boton) {
        boton.addEventListener('click', async function () {
            const panel = document.getElementById(boton.dataset.panel);
            if (!panel) return;
            if (!panel.hidden) {
                panel.hidden = true;
                boton.setAttribute('aria-expanded', 'false');
                return;
            }
            panel.hidden = false;
            boton.setAttribute('aria-expanded', 'true');
            const contenido = panel.querySelector('.js-gei-historico-contenido');
            if (panel.dataset.cargado === '1') return;
            contenido.textContent = 'Cargando histórico de esta cuenta…';
            try {
                const respuesta = await fetch(boton.dataset.url, {
                    headers: {'Accept': 'text/html'}, credentials: 'same-origin'
                });
                if (!respuesta.ok) throw new Error('HTTP ' + respuesta.status);
                contenido.innerHTML = await respuesta.text();
                panel.dataset.cargado = '1';
            } catch (error) {
                contenido.textContent = 'No se pudo consultar el histórico (' + error.message + ').';
            }
        });
    });
    document.querySelectorAll('[data-gei-mes-selector]').forEach(function (selector) {
        const formulario = selector.closest('form');
        const campo = formulario.querySelector('.js-gei-mes-valor');
        const etiqueta = selector.querySelector('.js-gei-mes-etiqueta');
        const anioTexto = selector.querySelector('.js-gei-mes-anio');
        let anio = Number(campo.value.substring(0, 4));
        const mesesBotones = Array.from(selector.querySelectorAll('.js-gei-mes-elegir'));
        const hasta = mesesBotones[0]?.dataset.hasta || '';
        const actualizarOpciones = function () {
            anioTexto.textContent = anio;
            mesesBotones.forEach(function (boton) {
                boton.disabled = !!hasta && (String(anio) + boton.dataset.mes > hasta);
            });
        };
        selector.querySelector('.js-gei-mes-anio-atras').addEventListener('click', function () {
            if (anio > 1900) --anio;
            actualizarOpciones();
        });
        selector.querySelector('.js-gei-mes-anio-adelante').addEventListener('click', function () {
            if (anio < (hasta ? Number(hasta.substring(0, 4)) : 2100)) ++anio;
            actualizarOpciones();
        });
        mesesBotones.forEach(function (boton) {
            boton.addEventListener('click', function () {
                const mes = boton.dataset.mes;
                const elegido = String(anio) + mes;
                if (hasta && elegido > hasta) return;
                campo.value = elegido;
                etiqueta.textContent = mes + '-' + anio;
                formulario.submit();
            });
        });
        actualizarOpciones();
    });
});
</script>
@endpush
