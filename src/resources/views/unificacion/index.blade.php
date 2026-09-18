@extends('layouts.app')

@section('title', 'Unificación')
@section('page-title', 'Unificación')

@section('content')
<div class="container-fluid py-3 pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1">Unificación</h1>
            <p class="text-muted mb-0">Revisión manual. Ningún candidato se fusiona automáticamente.</p>
        </div>
    </div>

    @if (session('estado'))
        <div class="alert alert-success">{{ session('estado') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><span class="nav-link active">Inmuebles</span></li>
        <li class="nav-item"><a class="nav-link" href="{{ route('archivo.unificacion.clientes.index') }}">Clientes</a></li>
    </ul>

    {{-- Vistas operativas: el usuario trabaja sobre un universo acotado y comprensible. --}}
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap gap-2">
                <a
                    class="btn {{ $vista === 'activos_ok' ? 'btn-success' : 'btn-outline-success' }}"
                    href="{{ route('archivo.unificacion.index', ['vista' => 'activos_ok']) }}"
                >
                    Activos OK <span class="badge text-bg-light ms-1">{{ $resumen['activos_ok'] }}</span>
                </a>
                <a
                    class="btn {{ $vista === 'activos_revision' ? 'btn-danger' : 'btn-outline-danger' }}"
                    href="{{ route('archivo.unificacion.index', ['vista' => 'activos_revision']) }}"
                >
                    Activos: revisión de inmueble
                    <span class="badge text-bg-light ms-1">{{ $resumen['activos_revision'] }}</span>
                </a>
                <a
                    class="btn {{ $vista === 'inactivos' ? 'btn-secondary' : 'btn-outline-secondary' }}"
                    href="{{ route('archivo.unificacion.index', ['vista' => 'inactivos', 'conflicto' => 'todos']) }}"
                >
                    Inactivos <span class="badge text-bg-light ms-1">{{ $resumen['inactivos'] }}</span>
                </a>
            </div>

            <a class="btn {{ $vista === 'cobol_sin_asociar' ? 'btn-warning' : 'btn-outline-warning' }} mt-2"
               href="{{ route('archivo.unificacion.index', ['vista' => 'cobol_sin_asociar']) }}">COBOL sin asociar <span class="badge text-bg-light">{{ $resumen['conflictos_sin_inmueble'] }}</span></a>

            @if ($vista === 'inactivos')
                <div class="d-flex flex-wrap gap-2 mt-2 pt-2 border-top">
                    <span class="small text-muted align-self-center me-1">Mostrar:</span>
                    <a
                        class="btn btn-sm {{ $filtroInactivos === 'todos' ? 'btn-secondary' : 'btn-outline-secondary' }}"
                        href="{{ route('archivo.unificacion.index', ['vista' => 'inactivos', 'conflicto' => 'todos', 'q' => $texto ?: null]) }}"
                    >Todos ({{ $resumen['inactivos'] }})</a>
                    <a
                        class="btn btn-sm {{ $filtroInactivos === 'sin_conflicto' ? 'btn-secondary' : 'btn-outline-secondary' }}"
                        href="{{ route('archivo.unificacion.index', ['vista' => 'inactivos', 'conflicto' => 'sin_conflicto', 'q' => $texto ?: null]) }}"
                    >Sin conflicto ({{ $resumen['inactivos_sin_conflicto'] }})</a>
                    <a
                        class="btn btn-sm {{ $filtroInactivos === 'con_conflicto' ? 'btn-danger' : 'btn-outline-danger' }}"
                        href="{{ route('archivo.unificacion.index', ['vista' => 'inactivos', 'conflicto' => 'con_conflicto', 'q' => $texto ?: null]) }}"
                    >Con conflicto ({{ $resumen['inactivos_con_conflicto'] }})</a>
                </div>
            @endif
        </div>
    </div>

    <div class="alert alert-info py-2">
        “Activos OK” indica que no hay revisión pendiente de identidad del inmueble.
        Los avisos de titularidad se revisan en
        <a href="{{ route('archivo.unificacion.clientes.index', ['vista' => 'avisos_propietarios']) }}">Clientes / Propietarios ({{ $resumen['avisos_clientes'] }} avisos)</a>.
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-8">
            <div class="card h-100">
                <div class="card-header fw-semibold">Buscar dentro de esta vista</div>
                <div class="card-body">
                    <form method="GET" action="{{ route('archivo.unificacion.index') }}" class="row g-2 align-items-end">
                        <input type="hidden" name="vista" value="{{ $vista }}">
                        <input type="hidden" name="conflicto" value="{{ $filtroInactivos }}">
                        <div class="col-lg-9">
                            <label for="q" class="form-label">{{ $vista === 'cobol_sin_asociar' ? 'ID de aviso, cuenta COBOL o motivo' : 'ID, domicilio, cuenta de propietario o cuenta de inquilino' }}</label>
                            <input
                                type="text"
                                class="form-control"
                                id="q"
                                name="q"
                                value="{{ $texto }}"
                                maxlength="180"
                                autocomplete="off"
                            >
                        </div>
                        <div class="col-lg-3 d-grid">
                            <button class="btn btn-primary" type="submit">Buscar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        @if ($vista !== 'cobol_sin_asociar')
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header fw-semibold">Comparación directa por ID</div>
                <div class="card-body">
                    <form method="GET" action="{{ route('archivo.unificacion.inmuebles.comparar') }}" class="row g-2">
                        <div class="col-6">
                            <label class="form-label" for="comparar_principal">Queda</label>
                            <input class="form-control" id="comparar_principal" type="number" name="principal" min="1" placeholder="ID" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="comparar_secundario">Absorbe</label>
                            <input class="form-control" id="comparar_secundario" type="number" name="secundario" min="1" placeholder="ID" required>
                        </div>
                        <div class="col-12 d-grid mt-2">
                            <button class="btn btn-outline-primary" type="submit">Comparar IDs</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        @endif
    </div>

    @if ($vista !== 'cobol_sin_asociar')
    @php
        $tituloVista = match ($vista) {
            'activos_revision' => 'Activos: revisión de inmueble',
            'inactivos' => 'Inmuebles inactivos',
            default => 'Activos OK',
        };
        $permitirSeleccionComparacion = $vista !== 'activos_ok';
    @endphp

    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="fw-semibold">{{ $tituloVista }}</span>
            <small class="text-muted">
                {{ number_format($resultados->total(), 0, ',', '.') }} resultado(s)
                @if ($texto !== '') — búsqueda: “{{ $texto }}” @endif
            </small>
        </div>
        <div class="card-body p-0">
            <form id="comparar-lista" method="GET" action="{{ route('archivo.unificacion.inmuebles.comparar') }}"></form>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                @if ($permitirSeleccionComparacion)
                                    <th style="width: 65px;">Queda</th>
                                    <th style="width: 70px;">Absorbe</th>
                                @endif
                                <th>ID</th>
                                <th>Domicilio</th>
                                <th>Propietario</th>
                                <th>Cuenta propietario</th>
                                <th>Inquilino actual</th>
                                <th>Partida</th>
                                <th>Estado / atención</th>
                                <th class="text-center">Contr.</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($resultados as $inmueble)
                                @php
                                    $esCandidatoActivo = $vista === 'activos_revision' && $candidatosActivos->contains(
                                        fn ($c) => (int) $c->id_a === (int) $inmueble->id || (int) $c->id_b === (int) $inmueble->id
                                    );
                                @endphp
                                <tr>
                                    @if ($permitirSeleccionComparacion)
                                        <td><input class="form-check-input" form="comparar-lista" type="radio" name="principal" value="{{ $inmueble->id }}" required></td>
                                        <td><input class="form-check-input" form="comparar-lista" type="radio" name="secundario" value="{{ $inmueble->id }}" required></td>
                                    @endif
                                    <td class="fw-semibold">{{ $inmueble->id }}</td>
                                    <td style="min-width: 220px;">
                                        <div>{{ $inmueble->domicilio }}</div>
                                        @if ($inmueble->domicilio_normalizado !== $inmueble->domicilio)
                                            <small class="text-muted">{{ $inmueble->domicilio_normalizado }}</small>
                                        @endif
                                    </td>
                                    <td>{{ $inmueble->propietarios_nombres ?: '—' }}</td>
                                    <td>{{ $inmueble->cuentas_propietario ?: '—' }}</td>
                                    <td>
                                        {{ $inmueble->cuentas_inquilino_activas ?: '—' }}
                                        @if (!$inmueble->cuentas_inquilino_activas && $inmueble->cuentas_inquilino)
                                            <div><small class="text-muted">Hist.: {{ $inmueble->cuentas_inquilino }}</small></div>
                                        @endif
                                    </td>
                                    <td>{{ $inmueble->partidas_vigentes ?: '—' }}</td>
                                    <td style="min-width: 155px;">
                                        <span class="badge {{ $inmueble->estado === 'ACTIVO' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $inmueble->estado }}</span>
                                        @if ((int) $inmueble->conflictos_pendientes > 0)
                                            <span class="badge text-bg-danger">{{ $inmueble->conflictos_pendientes }} conflicto(s)</span>
                                            @if ($inmueble->motivos_conflicto)
                                                <div class="small text-danger mt-1">{{ $inmueble->motivos_conflicto }}</div>
                                            @endif
                                        @endif
                                        @if ($esCandidatoActivo)
                                            <span class="badge text-bg-warning">POSIBLE DUPLICADO</span>
                                        @endif
                                        @if ((int) $inmueble->conflictos_pendientes === 0 && !$esCandidatoActivo && $vista === 'activos_ok')
                                            <span class="badge text-bg-success">OK</span>
                                        @endif
                                    </td>
                                    <td class="text-center">{{ $inmueble->contratos_activos }}</td>
                                </tr>
                                @if ($conflictosVisibles->has($inmueble->id))
                                    <tr><td colspan="{{ $permitirSeleccionComparacion ? 10 : 8 }}">
                                        <details>
                                            <summary class="text-primary" style="cursor:pointer;">Revisar inmueble #{{ $inmueble->id }}</summary>
                                            <div class="p-3">
                                                @foreach ($conflictosVisibles->get($inmueble->id) as $conflicto)
                                                    <div class="border-bottom pb-3 mb-3">@include('unificacion.decision-inmueble')</div>
                                                @endforeach
                                            </div>
                                        </details>
                                    </td></tr>
                                @endif

                            @empty
                                <tr>
                                    <td colspan="{{ $permitirSeleccionComparacion ? 10 : 8 }}" class="text-center text-muted py-4">No hay inmuebles para los filtros indicados.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($resultados->count() > 0 && $permitirSeleccionComparacion)
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top">
                        <small class="text-muted">Podés comparar dos registros de esta lista o usar “Comparación directa por ID”.</small>
                        <button form="comparar-lista" class="btn btn-outline-primary" type="submit">Comparar seleccionados</button>
                    </div>
                @endif
        </div>
    </div>

    @if ($resultados->hasPages())
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                @if ($resultados->onFirstPage())
                    <button class="btn btn-sm btn-outline-secondary" disabled>← Anterior</button>
                @else
                    <a class="btn btn-sm btn-outline-secondary" href="{{ $resultados->previousPageUrl() }}">← Anterior</a>
                @endif
            </div>
            <small class="text-muted">Página {{ $resultados->currentPage() }} de {{ $resultados->lastPage() }}</small>
            <div>
                @if ($resultados->hasMorePages())
                    <a class="btn btn-sm btn-outline-secondary" href="{{ $resultados->nextPageUrl() }}">Siguiente →</a>
                @else
                    <button class="btn btn-sm btn-outline-secondary" disabled>Siguiente →</button>
                @endif
            </div>
        </div>
    @endif

    @if ($texto !== '' && $gruposCandidatosBusqueda->isNotEmpty())
        <div class="card mb-4 border-warning">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="fw-semibold">Similitudes encontradas en esta búsqueda</span>
                <small class="text-muted">Agrupadas por lectura comparable. Son sugerencias; elegís explícitamente qué queda y qué se absorbe.</small>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach ($gruposCandidatosBusqueda as $grupo)
                        <div class="col-12">
                            <div class="border rounded p-3 {{ $grupo->tiene_conflictivo ? 'border-danger' : 'border-warning' }}">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                    <div>
                                        <span class="badge {{ $grupo->confianza === 'ALTA' ? 'text-bg-danger' : 'text-bg-warning' }}">{{ $grupo->confianza }}</span>
                                        @if ($grupo->tiene_conflictivo)
                                            <span class="badge text-bg-danger">CONFLICTIVO</span>
                                        @endif
                                        <strong class="ms-1">{{ $grupo->domicilio_comparable }}</strong>
                                    </div>
                                    <small class="text-muted">{{ $grupo->items->count() }} registros candidatos</small>
                                </div>

                                <div class="table-responsive mb-2">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr><th>ID</th><th>Domicilio original</th><th>Estado</th></tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($grupo->items as $item)
                                                <tr>
                                                    <td class="fw-semibold">#{{ $item->id }}</td>
                                                    <td>{{ $item->domicilio }}</td>
                                                    <td><span class="badge text-bg-secondary">{{ $item->estado }}</span></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                <div class="small mb-3">
                                    @if ($grupo->cuentas_compartidas)
                                        <div><strong>Cuenta propietario compartida:</strong> {{ $grupo->cuentas_compartidas }}</div>
                                    @endif
                                    @if ($grupo->partidas_compartidas)
                                        <div><strong>Partida compartida:</strong> {{ $grupo->partidas_compartidas }}</div>
                                    @endif
                                    @if ($grupo->motivos)
                                        <div class="text-muted">{{ $grupo->motivos }}</div>
                                    @endif
                                </div>

                                <form method="GET" action="{{ route('archivo.unificacion.inmuebles.comparar') }}" class="row g-2 align-items-end">
                                    <div class="col-md-5">
                                        <label class="form-label">Inmueble que queda</label>
                                        <select class="form-select" name="principal" required>
                                            <option value="">Seleccionar...</option>
                                            @foreach ($grupo->items as $item)
                                                <option value="{{ $item->id }}">#{{ $item->id }} — {{ $item->domicilio }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">Inmueble a absorber</label>
                                        <select class="form-select" name="secundario" required>
                                            <option value="">Seleccionar...</option>
                                            @foreach ($grupo->items as $item)
                                                <option value="{{ $item->id }}">#{{ $item->id }} — {{ $item->domicilio }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-2 d-grid">
                                        <button class="btn btn-outline-primary" type="submit">Comparar</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    @if ($vista === 'activos_revision' && $candidatosActivos->isNotEmpty())
        <div class="card mb-4 border-warning">
            <div class="card-header fw-semibold">Posibles duplicados entre inmuebles activos</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>Confianza</th><th>Inmueble A</th><th>Inmueble B</th><th>Motivo</th><th class="text-end">Acciones</th></tr></thead>
                        <tbody>
                            @foreach ($candidatosActivos as $candidato)
                                <tr>
                                    <td><span class="badge {{ $candidato->confianza === 'ALTA' ? 'text-bg-danger' : ($candidato->confianza === 'MEDIA' ? 'text-bg-warning' : 'text-bg-secondary') }}">{{ $candidato->confianza }}</span></td>
                                    <td><strong>#{{ $candidato->id_a }}</strong><br>{{ $candidato->domicilio_a }}</td>
                                    <td><strong>#{{ $candidato->id_b }}</strong><br>{{ $candidato->domicilio_b }}</td>
                                    <td>{{ $candidato->motivo }}</td>
                                    <td class="text-end text-nowrap">
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('archivo.unificacion.inmuebles.comparar', ['principal' => $candidato->id_a, 'secundario' => $candidato->id_b]) }}">Comparar</a>
                                        <form method="POST" action="{{ route('archivo.unificacion.inmuebles.candidato') }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="id_a" value="{{ $candidato->id_a }}"><input type="hidden" name="id_b" value="{{ $candidato->id_b }}"><input type="hidden" name="decision" value="MANTENER_SEPARADOS">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Son distintos</button>
                                        </form>
                                        <form method="POST" action="{{ route('archivo.unificacion.inmuebles.candidato') }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="id_a" value="{{ $candidato->id_a }}"><input type="hidden" name="id_b" value="{{ $candidato->id_b }}"><input type="hidden" name="decision" value="CONFLICTIVO">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Conflictivo</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @endif

    @if ($vista === 'cobol_sin_asociar')
        <div class="card mb-4">
            <div class="card-header fw-semibold">COBOL sin asociar — {{ $conflictosSinInmueble->total() }} aviso(s)</div>
            <div class="card-body">
                <p class="text-muted">Registros pendientes sin inmueble asociado. Revisá las partidas y los candidatos antes de decidir. Una decisión se aplica en la próxima importación.</p>
                @forelse ($conflictosSinInmueble as $conflicto)
                    <div class="border rounded p-3 mb-3">
                        @include('unificacion.decision-inmueble')
                    </div>
                @empty
                    <p class="text-muted mb-0">No hay registros para los filtros indicados.</p>
                @endforelse
                {{ $conflictosSinInmueble->links() }}
            </div>
        </div>
    @endif

    <details class="card mb-5">
        <summary class="card-header fw-semibold" style="cursor:pointer;">
            Historial de unificaciones — {{ $resumen['unificados'] }} inmueble(s) absorbido(s)
        </summary>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead><tr><th>Auditoría</th><th>Principal</th><th>Absorbido</th><th>Usuario</th><th>Fecha</th><th>Estado</th></tr></thead>
                    <tbody>
                        @forelse ($ultimasUnificaciones as $unificacion)
                            <tr>
                                <td>#{{ $unificacion->id_unificacion }}</td>
                                <td>#{{ $unificacion->id_registro_principal }} — {{ $unificacion->principal_domicilio }}</td>
                                <td>#{{ $unificacion->id_registro_absorbido }} — {{ $unificacion->absorbido_domicilio }}</td>
                                <td>{{ $unificacion->usuario_nombre ?: '—' }}</td>
                                <td>{{ $unificacion->created_at ? \Illuminate\Support\Carbon::parse($unificacion->created_at)->format('d/m/Y H:i') : '—' }}</td>
                                <td><span class="badge text-bg-secondary">{{ $unificacion->estado }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">Todavía no hay unificaciones registradas.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </details>
</div>
@endsection
