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
        <li class="nav-item"><span class="nav-link disabled">Clientes / Propietarios — próxima etapa</span></li>
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
                    Activos con conflicto / revisión
                    <span class="badge text-bg-light ms-1">{{ $resumen['activos_revision'] }}</span>
                </a>
                <a
                    class="btn {{ $vista === 'inactivos' ? 'btn-secondary' : 'btn-outline-secondary' }}"
                    href="{{ route('archivo.unificacion.index', ['vista' => 'inactivos', 'conflicto' => 'todos']) }}"
                >
                    Inactivos <span class="badge text-bg-light ms-1">{{ $resumen['inactivos'] }}</span>
                </a>
            </div>

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

    <div class="row g-3 mb-3">
        <div class="col-xl-8">
            <div class="card h-100">
                <div class="card-header fw-semibold">Buscar dentro de esta vista</div>
                <div class="card-body">
                    <form method="GET" action="{{ route('archivo.unificacion.index') }}" class="row g-2 align-items-end">
                        <input type="hidden" name="vista" value="{{ $vista }}">
                        <input type="hidden" name="conflicto" value="{{ $filtroInactivos }}">
                        <div class="col-lg-9">
                            <label for="q" class="form-label">ID, domicilio, cuenta de propietario o cuenta de inquilino</label>
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
    </div>

    @php
        $tituloVista = match ($vista) {
            'activos_revision' => 'Activos con conflicto / revisión',
            'inactivos' => 'Inmuebles inactivos',
            default => 'Activos OK',
        };
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
            <form method="GET" action="{{ route('archivo.unificacion.inmuebles.comparar') }}">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 65px;">Queda</th>
                                <th style="width: 70px;">Absorbe</th>
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
                                    <td><input class="form-check-input" type="radio" name="principal" value="{{ $inmueble->id }}" required></td>
                                    <td><input class="form-check-input" type="radio" name="secundario" value="{{ $inmueble->id }}" required></td>
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
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">No hay inmuebles para los filtros indicados.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($resultados->count() > 0)
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top">
                        <small class="text-muted">Podés comparar dos registros de esta lista o usar “Comparación directa por ID”.</small>
                        <button class="btn btn-outline-primary" type="submit">Comparar seleccionados</button>
                    </div>
                @endif
            </form>
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

    @if ($texto !== '' && $candidatosBusqueda->isNotEmpty())
        <div class="card mb-4 border-warning">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="fw-semibold">Similitudes encontradas en esta búsqueda</span>
                <small class="text-muted">Sólo sugerencias: tolera puntuación, P/PISO, OF/OFICINA y ceros a la izquierda</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>Confianza</th><th>Inmueble A</th><th>Inmueble B</th><th>Lectura comparable</th><th>Evidencia</th><th class="text-end">Acciones</th></tr></thead>
                        <tbody>
                            @foreach ($candidatosBusqueda as $candidato)
                                <tr>
                                    <td>
                                        <span class="badge {{ $candidato->confianza === 'ALTA' ? 'text-bg-danger' : 'text-bg-warning' }}">{{ $candidato->confianza }}</span>
                                        @if ($candidato->estado_decision === 'CONFLICTIVO') <span class="badge text-bg-danger">CONFLICTIVO</span> @endif
                                    </td>
                                    <td><strong>#{{ $candidato->id_a }}</strong> <span class="badge text-bg-secondary">{{ $candidato->estado_a }}</span><br>{{ $candidato->domicilio_a }}</td>
                                    <td><strong>#{{ $candidato->id_b }}</strong> <span class="badge text-bg-secondary">{{ $candidato->estado_b }}</span><br>{{ $candidato->domicilio_b }}</td>
                                    <td><code>{{ $candidato->domicilio_comparable }}</code></td>
                                    <td>
                                        {{ $candidato->motivo }}
                                        @if ($candidato->partidas_compartidas) <div><small><strong>Partida:</strong> {{ $candidato->partidas_compartidas }}</small></div> @endif
                                        @if ($candidato->cuentas_compartidas) <div><small><strong>Cuenta propietario compartida:</strong> {{ $candidato->cuentas_compartidas }}</small></div> @endif
                                    </td>
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

    @if ($conflictosVisibles->isNotEmpty())
        <div class="card mb-4 border-danger">
            <div class="card-header fw-semibold">Detalle de conflictos de los inmuebles mostrados</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>ID</th><th>Inmueble</th><th>Cuenta inquilino</th><th>Cuenta propietario</th><th>Motivo / evidencia</th><th>Última detección</th><th style="min-width: 340px;">Decisión humana</th></tr></thead>
                        <tbody>
                            @foreach ($conflictosVisibles as $conflicto)
                                @php
                                    $detalleConflicto = is_string($conflicto->detalle) ? (json_decode($conflicto->detalle, true) ?: []) : ((array) ($conflicto->detalle ?? []));
                                    $esConflictoIdentidad = str_starts_with((string) $conflicto->motivo, 'PARTIDA_') || str_starts_with((string) $conflicto->motivo, 'CLAVE_MIGRACION_');
                                    $candidatosDetalle = $detalleConflicto['inmuebles_candidatos'] ?? [];
                                    $partidasDetalle = $detalleConflicto['partidas_coincidentes'] ?? $detalleConflicto['partidas_ambiguas'] ?? [];
                                    $candidatoSugerido = count($candidatosDetalle) === 1 ? (int) $candidatosDetalle[0] : null;
                                @endphp
                                <tr>
                                    <td>#{{ $conflicto->id }}</td>
                                    <td>#{{ $conflicto->inmueble_id }} — {{ $conflicto->domicilio ?: '—' }}</td>
                                    <td>{{ $conflicto->cuenta_inquilino ?: '—' }}</td>
                                    <td>{{ $conflicto->cuenta_propietario ?: '—' }}</td>
                                    <td>
                                        <code>{{ $conflicto->motivo }}</code>
                                        @if ($partidasDetalle !== []) <div class="small"><strong>Partida(s):</strong> {{ implode(', ', $partidasDetalle) }}</div> @endif
                                        @if ($candidatosDetalle !== []) <div class="small"><strong>Candidato(s):</strong> #{{ implode(', #', $candidatosDetalle) }}</div> @endif
                                    </td>
                                    <td>{{ $conflicto->ultima_deteccion_at }}</td>
                                    <td>
                                        @if ($esConflictoIdentidad && $conflicto->cuenta_inquilino)
                                            <form method="POST" action="{{ route('archivo.unificacion.inmuebles.conflicto.resolver', ['conflicto' => $conflicto->id]) }}" class="d-flex gap-2 mb-2">
                                                @csrf
                                                <input type="hidden" name="decision" value="ASOCIAR_EXISTENTE">
                                                <input class="form-control form-control-sm" type="number" name="inmueble_id" min="1" value="{{ $candidatoSugerido }}" placeholder="ID canónico" required>
                                                <button class="btn btn-sm btn-outline-primary" type="submit" onclick="return confirm('¿Asociar esta identidad COBOL al inmueble indicado?');">Asociar</button>
                                            </form>
                                            <form method="POST" action="{{ route('archivo.unificacion.inmuebles.conflicto.resolver', ['conflicto' => $conflicto->id]) }}">
                                                @csrf
                                                <input type="hidden" name="decision" value="CREAR_SEPARADO">
                                                <button class="btn btn-sm btn-outline-secondary" type="submit" onclick="return confirm('¿Mantener esta identidad COBOL como inmueble separado?');">Mantener / crear separado</button>
                                            </form>
                                        @else
                                            <span class="small text-muted">No es un conflicto de identidad de inmueble; se resolverá en el módulo correspondiente.</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if ($vista === 'activos_revision' && $resumen['conflictos_sin_inmueble'] > 0)
        <details class="card mb-4">
            <summary class="card-header fw-semibold" style="cursor:pointer;">
                Conflictos de importación todavía sin inmueble asociado ({{ $resumen['conflictos_sin_inmueble'] }})
            </summary>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>ID</th><th>Cuenta inquilino</th><th>Cuenta propietario</th><th>Motivo</th><th>Última detección</th></tr></thead>
                        <tbody>
                            @foreach ($conflictosSinInmueble as $conflicto)
                                <tr><td>#{{ $conflicto->id }}</td><td>{{ $conflicto->cuenta_inquilino ?: '—' }}</td><td>{{ $conflicto->cuenta_propietario ?: '—' }}</td><td><code>{{ $conflicto->motivo }}</code></td><td>{{ $conflicto->ultima_deteccion_at }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </details>
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
                                <td>{{ $unificacion->created_at }}</td>
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
