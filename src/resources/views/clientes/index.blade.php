@extends('layouts.app')

@section('title', 'Clientes')
@section('page-title', 'Clientes')

@section('content')
    @if (session('estado'))
        <div class="alert alert-success" role="alert">
            {{ session('estado') }}
        </div>
    @endif

    @if ($errors->has('liquidacion'))
        <div class="alert alert-danger" role="alert">
            {{ $errors->first('liquidacion') }}
        </div>
    @endif

    <div class="gei-clientes">
        <aside class="gei-card gei-clientes__listado">
            <div class="gei-clientes__listado-header">
                <div>
                    <h1>Clientes</h1>
                    <p>{{ $clientes->total() }} registros encontrados</p>
                </div>

                <div class="d-flex flex-column align-items-end gap-2">
                    <div class="d-flex flex-wrap justify-content-end gap-2">
                        @if ($mostrarValidacion)
                            <a href="{{ route('clientes.validacion-pendientes.csv') }}" class="btn btn-outline-secondary">
                                Pendientes CSV
                            </a>
                        @endif

                        <a href="{{ route('clientes.create') }}" class="btn gei-button gei-button--primary">
                            + Nuevo
                        </a>
                    </div>

                    <form method="GET" action="{{ route('clientes.index') }}" class="form-check m-0">
                        <input type="hidden" name="buscar" value="{{ $busqueda }}">
                        <input type="hidden" name="filtro" value="{{ $filtro }}">
                        <input type="hidden" name="actividad" value="{{ $actividad }}">
                        @if (! $mostrarValidacion)
                            <input type="hidden" name="validacion" value="todos">
                        @else
                            <input type="hidden" name="validacion" value="{{ $validacion }}">
                        @endif

                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="mostrar_validacion"
                            name="mostrar_validacion"
                            value="1"
                            @checked($mostrarValidacion)
                            onchange="this.form.submit()"
                        >
                        <label class="form-check-label small text-muted" for="mostrar_validacion">
                            Mostrar auditoría
                        </label>
                    </form>
                </div>
            </div>

            <form method="GET" action="{{ route('clientes.index') }}" class="gei-clientes__filtros">
                <label for="buscar" class="visually-hidden">Buscar clientes</label>
                <div class="input-group">
                    <input
                        type="search"
                        id="buscar"
                        name="buscar"
                        value="{{ $busqueda }}"
                        class="form-control"
                        placeholder="Nombre, documento, CUIT..."
                    >
                    <button class="btn btn-outline-secondary" type="submit">Buscar</button>
                </div>

                <div class="gei-clientes__filter-options" aria-label="Filtrar clientes">
                    @foreach ([
                        'todos' => 'Todos',
                        'propietarios' => 'Propietarios',
                        'inquilinos' => 'Inquilinos',
                    ] as $valor => $etiqueta)
                        <a
                            href="{{ route('clientes.index', array_filter(['buscar' => $busqueda, 'filtro' => $valor, 'mostrar_validacion' => $mostrarValidacion ? 1 : null, 'validacion' => $mostrarValidacion ? $validacion : null, 'actividad' => 'activos'])) }}"
                            class="{{ $filtro === $valor ? 'is-active' : '' }}"
                        >
                            {{ $etiqueta }}
                        </a>
                    @endforeach
                </div>

                @if ($mostrarValidacion)
                    <div class="gei-clientes__filter-options" aria-label="Filtrar por auditoría">
                        @foreach ([
                            'todos' => 'Auditoría: todos',
                            'validados' => 'Revisados',
                            'pendientes' => 'A revisar',
                        ] as $valor => $etiqueta)
                            <a
                                href="{{ route('clientes.index', array_filter(['buscar' => $busqueda, 'filtro' => $filtro, 'mostrar_validacion' => 1, 'validacion' => $valor, 'actividad' => $actividad])) }}"
                                class="{{ $validacion === $valor ? 'is-active' : '' }}"
                            >
                                {{ $etiqueta }}
                            </a>
                        @endforeach
                    </div>
                @endif

                <div class="gei-clientes__filter-options" aria-label="Filtrar por actividad">
                    @foreach ([
                        'todos' => 'Actividad: todos',
                        'activos' => 'Activos',
                        'inactivos' => 'Inactivos',
                    ] as $valor => $etiqueta)
                        <a
                            href="{{ route('clientes.index', array_filter(['buscar' => $busqueda, 'filtro' => $filtro, 'mostrar_validacion' => $mostrarValidacion ? 1 : null, 'validacion' => $mostrarValidacion ? $validacion : null, 'actividad' => $valor])) }}"
                            class="{{ $actividad === $valor ? 'is-active' : '' }}"
                        >
                            {{ $etiqueta }}
                        </a>
                    @endforeach
                </div>
            </form>

            <div class="gei-clientes__items">
                @forelse ($clientes as $cliente)
                    <a
                        href="{{ route('clientes.show', [
                            'cliente' => $cliente,
                            'buscar' => $busqueda,
                            'filtro' => $filtro,
                            'mostrar_validacion' => $mostrarValidacion ? 1 : null,
                            'validacion' => $mostrarValidacion ? $validacion : null,
                            'actividad' => $actividad,
                            'page' => $clientes->currentPage(),
                        ]) }}"
                        class="gei-cliente-item {{ $clienteSeleccionado?->codigo_cliente === $cliente->codigo_cliente ? 'is-active' : '' }}"
                        @if ($clienteSeleccionado?->codigo_cliente === $cliente->codigo_cliente) data-selected-client @endif
                    >
                        <div class="gei-cliente-item__heading">
                            <strong>{{ $cliente->nombre_visible }}</strong>
                            <span>#{{ $cliente->codigo_cliente }}</span>
                        </div>

                        <div class="gei-cliente-item__meta">
                            <span>{{ trim((string) $cliente->doctipo) ?: 'Sin documento' }}</span>
                            <span>{{ trim((string) $cliente->docnro) ?: '—' }}</span>
                        </div>

                        <div class="mt-2">
                            @if ($mostrarValidacion)
                                @if ($cliente->web_validada)
                                    <span class="badge text-bg-success">Revisado</span>
                                @else
                                    <span class="badge text-bg-warning">A revisar</span>
                                @endif
                            @endif

                            @if ($filtro === 'inquilinos' && $actividad === 'activos')
                                <span class="badge text-bg-primary">Inquilino activo</span>
                            @elseif ($filtro === 'inquilinos' && $actividad === 'inactivos')
                                <span class="badge text-bg-secondary">Inquilino inactivo</span>
                            @elseif ($cliente->web_operativo)
                                <span class="badge text-bg-primary">Operativo</span>
                            @else
                                <span class="badge text-bg-secondary">No operativo</span>
                            @endif
                        </div>

                        @if (trim((string) $cliente->localidad) !== '')
                            <small>{{ trim((string) $cliente->localidad) }}</small>
                        @endif
                    </a>
                @empty
                    <div class="gei-empty-state">
                        No se encontraron clientes con esos criterios.
                    </div>
                @endforelse
            </div>

            @if ($clientes->hasPages())
                <div class="gei-clientes__pagination">
                    {{ $clientes->onEachSide(1)->links() }}
                </div>
            @endif
        </aside>

        <section class="gei-clientes__detalle">
            @if ($clienteSeleccionado)
                <div class="gei-client-detail-heading">
                    <div>
                        <span class="gei-client-detail-heading__eyebrow">
                            {{ trim((string) $clienteSeleccionado->personeria) }}
                            · Cliente #{{ $clienteSeleccionado->codigo_cliente }}
                            @if ($mostrarValidacion)
                                · {{ $clienteSeleccionado->web_validada ? 'Revisado' : 'A revisar' }}
                            @endif
                            · {{ $clienteSeleccionado->web_operativo ? 'Operativo' : 'No operativo' }}
                        </span>
                        <h1>{{ $clienteSeleccionado->nombre_visible }}</h1>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button
                            class="btn btn-outline-secondary"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#datosCliente"
                            aria-expanded="false"
                            aria-controls="datosCliente"
                        >
                            Mostrar Datos
                        </button>

                        <a href="{{ route('clientes.edit', $clienteSeleccionado) }}" class="btn btn-outline-primary">
                            Modificar
                        </a>
                    </div>
                </div>

                <div class="collapse" id="datosCliente">
                    <div class="row g-3 mb-4">
                        <div class="col-xl-6">
                            <article class="gei-card gei-detail-card h-100">
                                <h2>Identificación</h2>
                                <dl class="gei-detail-list">
                                    <div><dt>Documento</dt><dd>{{ trim((string) $clienteSeleccionado->doctipo) ?: '—' }} {{ trim((string) $clienteSeleccionado->docnro) }}</dd></div>
                                    <div><dt>CUIT</dt><dd>{{ trim((string) $clienteSeleccionado->cuit) ?: '—' }}</dd></div>
                                    <div><dt>Condición IVA</dt><dd>{{ trim((string) $clienteSeleccionado->condicion_iva) ?: '—' }}</dd></div>
                                    <div><dt>Nacionalidad</dt><dd>{{ trim((string) $clienteSeleccionado->nacionalidad) ?: '—' }}</dd></div>
                                </dl>
                            </article>
                        </div>

                        <div class="col-xl-6">
                            <article class="gei-card gei-detail-card h-100">
                                <h2>Domicilio y contacto</h2>
                                <dl class="gei-detail-list">
                                    <div><dt>Domicilio</dt><dd>{{ trim((string) $clienteSeleccionado->domicilio) ?: '—' }}</dd></div>
                                    <div><dt>Ubicación</dt><dd>{{ collect([$clienteSeleccionado->localidad, $clienteSeleccionado->provincia])->map(fn ($valor) => trim((string) $valor))->filter()->implode(', ') ?: '—' }}</dd></div>
                                    <div><dt>Teléfono</dt><dd>{{ trim((string) $clienteSeleccionado->telefonos) ?: '—' }}</dd></div>
                                    <div><dt>Celular</dt><dd>{{ trim((string) $clienteSeleccionado->celular) ?: '—' }}</dd></div>
                                    <div><dt>Correo</dt><dd>{{ trim((string) $clienteSeleccionado->email) ?: '—' }}</dd></div>
                                </dl>
                            </article>
                        </div>

                        <div class="col-12">
                            <article class="gei-card gei-detail-card">
                                <h2>Información laboral</h2>
                                <dl class="gei-detail-list gei-detail-list--columns">
                                    <div><dt>Profesión</dt><dd>{{ trim((string) $clienteSeleccionado->profesion) ?: '—' }}</dd></div>
                                    <div><dt>Lugar de trabajo</dt><dd>{{ trim((string) $clienteSeleccionado->lugar_de_trabajo) ?: '—' }}</dd></div>
                                </dl>
                            </article>
                        </div>
                    </div>
                </div>

                <article class="gei-card gei-detail-card">
                    <div class="gei-section-title">
                        <div>
                            <h2>Contratos de alquiler</h2>
                            <p>Contratos donde el cliente figura como inquilino.</p>
                        </div>
                    </div>

                    @forelse ($contratos as $contrato)
                        <div class="gei-contract">
                            <div class="gei-contract__heading">
                                <div>
                                    <strong>{{ $contrato->nombre_visible }}</strong>
                                    <span class="gei-status gei-status--{{ mb_strtolower($contrato->estado) }}">
                                        {{ $contrato->estado }}
                                    </span>
                                </div>
                                <span>
                                    Participación inquilino:
                                    {{ number_format((float) $contrato->porcentaje_participacion, 2, ',', '.') }}%
                                </span>
                            </div>

                            <div class="gei-contract__facts">
                                <span>Inicio: {{ $contrato->fecha_inicio?->format('d/m/Y') ?? '—' }}</span>
                                <span>Fin: {{ $contrato->fecha_fin?->format('d/m/Y') ?? '—' }}</span>
                                <span>Importe inicial: $ {{ number_format((float) $contrato->importe_inicial, 2, ',', '.') }}</span>
                            </div>

                            <div class="gei-contract__properties">
                                @forelse ($contrato->inmuebles as $inmueble)
                                    <div>
                                        <strong>{{ $inmueble->tipo?->nombre ?? 'Inmueble' }}</strong>
                                        <span>{{ $inmueble->domicilio_visible }}</span>
                                        @if ($inmueble->propietarios->isNotEmpty())
                                            <div class="gei-contract__property-owner">
                                                <strong>{{ $inmueble->propietarios->count() === 1 ? 'Propietario' : 'Propietarios' }}</strong>
                                                <span>{{ $inmueble->propietarios->map->nombre_visible->implode(' · ') }}</span>
                                            </div>
                                        @else
                                            <small class="text-muted">Sin propietarios vinculados.</small>
                                        @endif
                                    </div>
                                @empty
                                    <span class="text-muted">Sin inmuebles vinculados.</span>
                                @endforelse
                            </div>
                        </div>
                    @empty
                        <div class="gei-empty-state">
                            Este cliente no tiene contratos de alquiler vinculados.
                        </div>
                    @endforelse

                    @if ($contratos?->hasPages())
                        <div class="mt-3">
                            {{ $contratos->onEachSide(1)->links() }}
                        </div>
                    @endif
                </article>

                <article class="gei-card gei-detail-card mt-4">
                    <div class="gei-section-title">
                        <div>
                            <h2>Liquidaciones</h2>
                            <p>Liquidaciones asociadas a la cuenta de propietario.</p>
                        </div>
                    </div>

                    @if ((int) $clienteSeleccionado->id_prop === 0)
                        <div class="gei-empty-state">
                            Este cliente no tiene cuenta de propietario vinculada.
                        </div>
                    @else
                        <form method="GET" action="{{ route('clientes.show', $clienteSeleccionado) }}" class="row g-2 align-items-end mt-3">
                            <input type="hidden" name="buscar" value="{{ $busqueda }}">
                            <input type="hidden" name="filtro" value="{{ $filtro }}">
                            <input type="hidden" name="actividad" value="{{ $actividad }}">
                            @if ($mostrarValidacion)
                                <input type="hidden" name="mostrar_validacion" value="1">
                                <input type="hidden" name="validacion" value="{{ $validacion }}">
                            @endif

                            <div class="col-md-2">
                                <label for="liquidacion_anio" class="form-label">Año</label>
                                <input
                                    type="number"
                                    id="liquidacion_anio"
                                    name="liquidacion_anio"
                                    value="{{ $liquidacionAnio }}"
                                    class="form-control"
                                    min="1900"
                                    max="2100"
                                >
                            </div>

                            <div class="col-md-2">
                                <label for="liquidacion_mes" class="form-label">Mes</label>
                                <input
                                    type="number"
                                    id="liquidacion_mes"
                                    name="liquidacion_mes"
                                    value="{{ $liquidacionMes }}"
                                    class="form-control"
                                    min="1"
                                    max="12"
                                >
                            </div>

                            <div class="col-md-4">
                                <label for="liquidacion_periodo" class="form-label">Período</label>
                                <input
                                    type="search"
                                    id="liquidacion_periodo"
                                    name="liquidacion_periodo"
                                    value="{{ $liquidacionPeriodo }}"
                                    class="form-control"
                                    placeholder="Junio/2026"
                                >
                            </div>

                            <div class="col-md-4 d-flex gap-2">
                                <button class="btn btn-outline-primary" type="submit">Filtrar</button>
                                <a href="{{ route('clientes.show', [
                                    'cliente' => $clienteSeleccionado,
                                    'buscar' => $busqueda,
                                    'filtro' => $filtro,
                                    'actividad' => $actividad,
                                    'mostrar_validacion' => $mostrarValidacion ? 1 : null,
                                    'validacion' => $mostrarValidacion ? $validacion : null,
                                ]) }}" class="btn btn-outline-secondary">Limpiar</a>
                            </div>
                        </form>

                        <div class="table-responsive mt-3">
                            <table class="table table-sm align-middle gei-liquidaciones-table">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Período</th>
                                        <th>Número</th>
                                        <th>Comprobante</th>
                                        <th>Cuenta</th>
                                        <th>PDF</th>
                                        <th class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($liquidaciones as $liquidacion)
                                        <tr>
                                            <td>{{ $liquidacion->fecha?->format('d/m/Y') ?? '—' }}</td>
                                            <td>{{ $liquidacion->periodo_limpio ?: '—' }}</td>
                                            <td>{{ (int) $liquidacion->punto_venta }}-{{ (int) $liquidacion->numero }}</td>
                                            <td>{{ (int) $liquidacion->numero_de_comprobante ?: '—' }}</td>
                                            <td>{{ (int) $liquidacion->nro_cuenta }}</td>
                                            <td>
                                                @if ($liquidacion->pdf_disponible)
                                                    <span class="badge text-bg-success">Disponible</span>
                                                @else
                                                    <span class="badge text-bg-warning">PDF no encontrado</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                <div class="btn-group btn-group-sm" role="group" aria-label="Acciones de liquidación">
                                                    @if ($liquidacion->pdf_disponible)
                                                        <a
                                                            href="{{ route('clientes.liquidaciones.ver', [$clienteSeleccionado, $liquidacion]) }}"
                                                            class="btn btn-outline-secondary"
                                                            target="_blank"
                                                            rel="noopener"
                                                        >
                                                            Ver
                                                        </a>
                                                        <a
                                                            href="{{ route('clientes.liquidaciones.descargar', [$clienteSeleccionado, $liquidacion]) }}"
                                                            class="btn btn-outline-secondary"
                                                        >
                                                            Descargar
                                                        </a>
                                                        <button
                                                            type="button"
                                                            class="btn btn-outline-primary"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#enviarLiquidacion{{ $liquidacion->numero_de_liquidacion }}"
                                                        >
                                                            Enviar
                                                        </button>
                                                    @else
                                                        <button type="button" class="btn btn-outline-secondary" disabled>Ver</button>
                                                        <button type="button" class="btn btn-outline-secondary" disabled>Descargar</button>
                                                        <button type="button" class="btn btn-outline-secondary" disabled>Enviar</button>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="text-muted">
                                                No se encontraron liquidaciones para esos criterios.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        @foreach ($liquidaciones as $liquidacion)
                            @if ($liquidacion->pdf_disponible)
                                <div class="modal fade" id="enviarLiquidacion{{ $liquidacion->numero_de_liquidacion }}" tabindex="-1" aria-labelledby="enviarLiquidacion{{ $liquidacion->numero_de_liquidacion }}Label" aria-hidden="true">
                                    <div class="modal-dialog">
                                        <form method="POST" action="{{ route('clientes.liquidaciones.enviar', [$clienteSeleccionado, $liquidacion]) }}" class="modal-content">
                                            @csrf

                                            <div class="modal-header">
                                                <h1 class="modal-title fs-5" id="enviarLiquidacion{{ $liquidacion->numero_de_liquidacion }}Label">
                                                    Enviar liquidación
                                                </h1>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                            </div>

                                            <div class="modal-body">
                                                <p class="mb-2">
                                                    Liquidación {{ (int) $liquidacion->punto_venta }}-{{ (int) $liquidacion->numero }}
                                                    · {{ $liquidacion->periodo_limpio ?: 'Sin período' }}
                                                </p>

                                                <label for="destinatario{{ $liquidacion->numero_de_liquidacion }}" class="form-label">
                                                    Destinatario
                                                </label>
                                                <input
                                                    type="email"
                                                    id="destinatario{{ $liquidacion->numero_de_liquidacion }}"
                                                    name="destinatario"
                                                    value="{{ trim((string) $clienteSeleccionado->email) }}"
                                                    class="form-control"
                                                    required
                                                >
                                            </div>

                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                                    Cancelar
                                                </button>
                                                <button type="submit" class="btn gei-button gei-button--primary">
                                                    Enviar por correo
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            @endif
                        @endforeach

                        @if ($liquidaciones?->hasPages())
                            <div class="mt-3">
                                {{ $liquidaciones->onEachSide(1)->links() }}
                            </div>
                        @endif
                    @endif
                </article>
            @else
                <div class="gei-card gei-empty-state gei-empty-state--large">
                    Seleccioná un cliente para consultar sus datos.
                </div>
            @endif
        </section>
    </div>
@endsection
