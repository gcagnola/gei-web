@extends('layouts.app')

@section('title', 'Unificación de clientes')
@section('page-title', 'Unificación')

@section('content')
<div class="container-fluid py-3 pb-5">
    <div class="mb-3">
        <h1 class="h3 mb-1">Unificación</h1>
        <p class="text-muted mb-0">Clientes. La decisión final siempre es manual.</p>
    </div>

    @if (session('estado')) <div class="alert alert-success">{{ session('estado') }}</div> @endif
    @if ($errors->any())
        <div class="alert alert-danger">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link" href="{{ route('archivo.unificacion.index') }}">Inmuebles</a></li>
        <li class="nav-item"><span class="nav-link active">Clientes</span></li>
    </ul>

    <div class="card mb-3"><div class="card-body py-2">
        <div class="d-flex flex-wrap gap-2">
            <a class="btn {{ $vista === 'activos_ok' ? 'btn-success' : 'btn-outline-success' }}" href="{{ route('archivo.unificacion.clientes.index', ['vista' => 'activos_ok']) }}">Activos OK <span class="badge text-bg-light ms-1">{{ $resumen['activos_ok'] }}</span></a>
            <a class="btn {{ $vista === 'activos_revision' ? 'btn-danger' : 'btn-outline-danger' }}" href="{{ route('archivo.unificacion.clientes.index', ['vista' => 'activos_revision']) }}">Activos con revisión COBOL <span class="badge text-bg-light ms-1">{{ $resumen['activos_revision'] }}</span></a>
            <a class="btn {{ $vista === 'inactivos' ? 'btn-secondary' : 'btn-outline-secondary' }}" href="{{ route('archivo.unificacion.clientes.index', ['vista' => 'inactivos', 'conflicto' => 'todos']) }}">Inactivos <span class="badge text-bg-light ms-1">{{ $resumen['inactivos'] }}</span></a>
        </div>
        @if ($vista === 'inactivos')
            <div class="d-flex flex-wrap gap-2 mt-2 pt-2 border-top">
                <span class="small text-muted align-self-center">Mostrar:</span>
                <a class="btn btn-sm {{ $filtroInactivos === 'todos' ? 'btn-secondary' : 'btn-outline-secondary' }}" href="{{ route('archivo.unificacion.clientes.index', ['vista'=>'inactivos','conflicto'=>'todos','q'=>$texto ?: null]) }}">Todos ({{ $resumen['inactivos'] }})</a>
                <a class="btn btn-sm {{ $filtroInactivos === 'sin_conflicto' ? 'btn-secondary' : 'btn-outline-secondary' }}" href="{{ route('archivo.unificacion.clientes.index', ['vista'=>'inactivos','conflicto'=>'sin_conflicto','q'=>$texto ?: null]) }}">Sin revisión COBOL ({{ $resumen['inactivos_sin_conflicto'] }})</a>
                <a class="btn btn-sm {{ $filtroInactivos === 'con_conflicto' ? 'btn-danger' : 'btn-outline-danger' }}" href="{{ route('archivo.unificacion.clientes.index', ['vista'=>'inactivos','conflicto'=>'con_conflicto','q'=>$texto ?: null]) }}">Con revisión COBOL ({{ $resumen['inactivos_con_conflicto'] }})</a>
            </div>
        @endif
    </div></div>

    @if ($vista === 'activos_revision')
        <div class="alert alert-info py-2">
            <strong>Importante:</strong> estos clientes no son duplicados entre sí.
            Cada fila está involucrada en una identidad/origen COBOL cuya asociación automática quedó pendiente.
            Los posibles duplicados de personas se muestran por separado como <strong>Similitudes encontradas</strong> al buscar por nombre, CUIT, documento o cuenta.
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-xl-8"><div class="card h-100">
            <div class="card-header fw-semibold">Buscar dentro de esta vista</div>
            <div class="card-body"><form method="GET" action="{{ route('archivo.unificacion.clientes.index') }}" class="row g-2 align-items-end">
                <input type="hidden" name="vista" value="{{ $vista }}"><input type="hidden" name="conflicto" value="{{ $filtroInactivos }}">
                <div class="col-lg-9"><label class="form-label" for="q">ID, nombre, CUIT, documento, email o cuenta COBOL</label><input class="form-control" id="q" name="q" value="{{ $texto }}" maxlength="180"></div>
                <div class="col-lg-3 d-grid"><button class="btn btn-primary">Buscar</button></div>
            </form></div>
        </div></div>
        <div class="col-xl-4"><div class="card h-100">
            <div class="card-header fw-semibold">Comparación directa por ID</div>
            <div class="card-body"><form method="GET" action="{{ route('archivo.unificacion.clientes.comparar') }}" class="row g-2">
                <div class="col-6"><label class="form-label">Queda</label><input class="form-control" type="number" name="principal" min="1" required></div>
                <div class="col-6"><label class="form-label">Absorbe</label><input class="form-control" type="number" name="secundario" min="1" required></div>
                <div class="col-12 d-grid"><button class="btn btn-outline-primary">Comparar IDs</button></div>
            </form></div>
        </div></div>
    </div>

    @php
        $titulo = match($vista) { 'activos_revision' => 'Activos con revisión COBOL pendiente', 'inactivos' => 'Clientes inactivos', default => 'Activos OK' };
    @endphp
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between"><strong>{{ $titulo }}</strong><small class="text-muted">{{ number_format($resultados->total(), 0, ',', '.') }} resultado(s)</small></div>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
            <thead><tr>@if ($vista === 'inactivos')<th>Queda</th><th>Absorbe</th>@endif<th>ID</th><th>Nombre</th><th>CUIT / Documento</th><th>Roles</th><th>{{ $vista === 'activos_revision' ? 'Cuentas del cliente' : 'Cuentas COBOL' }}</th>@if ($vista === 'activos_revision')<th>Cuenta(s) COBOL a revisar</th>@endif<th>Estado</th>@if ($vista === 'activos_revision')<th>Acción</th>@endif</tr></thead>
            <tbody>
            @forelse ($resultados as $fila)
                <tr>
                    @if ($vista === 'inactivos')
                        <td><input class="form-check-input js-principal" type="radio" name="principal_visual" value="{{ $fila->id }}"></td>
                        <td><input class="form-check-input js-secundario" type="radio" name="secundario_visual" value="{{ $fila->id }}"></td>
                    @endif
                    <td><strong>{{ $fila->id }}</strong></td>
                    <td>{{ $fila->nombre }}</td>
                    <td>
                        @if ($fila->cuit)<div>CUIT {{ $fila->cuit }}</div>@endif
                        @if ($fila->numero_documento)<small class="text-muted">{{ $fila->tipo_documento }} {{ $fila->numero_documento }}</small>@endif
                    </td>
                    <td>{{ $fila->roles ?: '—' }}</td>
                    <td>{{ $fila->cuentas ?: '—' }}</td>
                    @if ($vista === 'activos_revision')
                        <td>
                            @foreach (array_filter(array_map('trim', explode(',', (string) ($fila->cuentas_revision_cobol ?? '')))) as $cuentaRevision)
                                <div><strong>{{ $cuentaRevision }}</strong></div>
                            @endforeach
                            @if (empty(trim((string) ($fila->cuentas_revision_cobol ?? ''))))
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    @endif
                    <td>
                        <span class="badge {{ $fila->operativo_activo ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $fila->operativo_activo ? 'ACTIVO' : 'INACTIVO' }}</span>
                        @if ($fila->conflicto_pendiente)<span class="badge text-bg-danger">REVISIÓN COBOL</span>@else<span class="badge text-bg-success">OK</span>@endif
                    </td>
                    @if ($vista === 'activos_revision')
                        <td class="text-nowrap">
                            @if ($fila->revision_cobol_id)
                                <a class="btn btn-sm btn-primary" href="{{ route('archivo.unificacion.clientes.conflicto.revisar', $fila->revision_cobol_id) }}">
                                    Revisar
                                    @if ((int) $fila->revisiones_cobol_count > 1)
                                        <span class="badge text-bg-light ms-1">{{ $fila->revisiones_cobol_count }} revisiones</span>
                                    @endif
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $vista === 'activos_revision' ? 9 : ($vista === 'inactivos' ? 8 : 6) }}" class="text-center text-muted py-4">Sin registros.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        <div class="card-footer d-flex justify-content-between align-items-center gap-3">
            <div>{{ $resultados->onEachSide(1)->links() }}</div>
            @if ($vista === 'inactivos')
                <button id="comparar-seleccionados" type="button" class="btn btn-outline-primary">Comparar seleccionados</button>
            @else
                <span class="small text-muted">La revisión se resuelve sobre el origen COBOL, no comparando estas filas entre sí.</span>
            @endif
        </div>
    </div>

    @if ($candidatosBusqueda->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header fw-semibold">Similitudes encontradas en esta búsqueda</div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>Confianza</th><th>Cliente A</th><th>Cliente B</th><th>Evidencia</th><th>Acciones</th></tr></thead>
                <tbody>@foreach ($candidatosBusqueda as $c)
                    <tr>
                        <td><span class="badge {{ $c->confianza === 'ALTA' ? 'text-bg-danger' : ($c->confianza === 'MEDIA' ? 'text-bg-warning' : 'text-bg-secondary') }}">{{ $c->confianza }}</span></td>
                        <td><strong>#{{ $c->id_a }}</strong><br>{{ $c->cliente_a->nombre }}</td>
                        <td><strong>#{{ $c->id_b }}</strong><br>{{ $c->cliente_b->nombre }}</td>
                        <td>{{ implode('; ', $c->motivos) }}</td>
                        <td class="text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('archivo.unificacion.clientes.comparar', ['principal'=>$c->id_a,'secundario'=>$c->id_b]) }}">Comparar</a>
                            <form class="d-inline" method="POST" action="{{ route('archivo.unificacion.clientes.candidato') }}">@csrf<input type="hidden" name="id_a" value="{{ $c->id_a }}"><input type="hidden" name="id_b" value="{{ $c->id_b }}"><input type="hidden" name="decision" value="MANTENER_SEPARADOS"><button class="btn btn-sm btn-outline-secondary">Son distintos</button></form>
                            <form class="d-inline" method="POST" action="{{ route('archivo.unificacion.clientes.candidato') }}">@csrf<input type="hidden" name="id_a" value="{{ $c->id_a }}"><input type="hidden" name="id_b" value="{{ $c->id_b }}"><input type="hidden" name="decision" value="CONFLICTIVO"><button class="btn btn-sm btn-outline-danger">Conflictivo</button></form>
                        </td>
                    </tr>
                @endforeach</tbody>
            </table></div>
        </div>
    @endif

    @if ($vista === 'activos_revision' && $conflictosVisibles->isNotEmpty())
        <div class="card mb-3 border-danger">
            <div class="card-header fw-semibold">Detalle de revisiones COBOL de los clientes mostrados</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>#</th><th>Cliente</th><th>Origen</th><th>Cuenta COBOL</th><th>Actividad</th><th>Motivo</th><th>Última detección</th><th>Acción</th></tr></thead>
                    <tbody>
                    @foreach ($conflictosVisibles as $cf)
                        <tr>
                            <td>{{ $cf->id }}</td>
                            <td><strong>#{{ $cf->cliente_visible_id }}</strong> — {{ $cf->cliente_visible_nombre ?: '—' }}</td>
                            <td>{{ $cf->entidad_origen }}</td>
                            <td><strong>{{ $cf->clave_origen }}</strong></td>
                            <td><span class="badge {{ $cf->estado_origen === 'ACTIVO' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $cf->estado_origen ?: '—' }}</span></td>
                            <td>{{ $cf->motivo }}</td>
                            <td>{{ $cf->ultima_deteccion_at ? \Carbon\Carbon::parse($cf->ultima_deteccion_at)->format('d/m/Y H:i') : '—' }}</td>
                            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('archivo.unificacion.clientes.conflicto.revisar', $cf->id) }}">Revisar</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($vista === 'activos_revision' && $conflictosSinCliente->isNotEmpty())
        <details class="card mb-3">
            <summary class="card-header fw-semibold">
                Revisiones COBOL todavía sin cliente asociado ({{ $conflictosSinClienteTotal }})
            </summary>
            <div class="card-body py-2">
                <div class="small text-muted mb-2">
                    Estas identidades COBOL todavía no pudieron vincularse a un cliente canónico o candidato visible.
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>#</th><th>Origen</th><th>Cuenta COBOL</th><th>Actividad</th><th>Motivo</th><th>Última detección</th><th>Acción</th></tr></thead>
                    <tbody>
                    @foreach ($conflictosSinCliente as $cf)
                        <tr>
                            <td>{{ $cf->id }}</td>
                            <td>{{ $cf->entidad_origen }}</td>
                            <td><strong>{{ $cf->clave_origen }}</strong></td>
                            <td><span class="badge {{ $cf->estado_origen === 'ACTIVO' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $cf->estado_origen ?: '—' }}</span></td>
                            <td>{{ $cf->motivo }}</td>
                            <td>{{ $cf->ultima_deteccion_at ? \Carbon\Carbon::parse($cf->ultima_deteccion_at)->format('d/m/Y H:i') : '—' }}</td>
                            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('archivo.unificacion.clientes.conflicto.revisar', $cf->id) }}">Revisar</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif

    <details class="card mb-3" open>
        <summary class="card-header fw-semibold">Historial de revisiones COBOL resueltas — {{ $revisionesCobolResueltas->count() }}</summary>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Origen</th>
                        <th>Cuenta COBOL</th>
                        <th>Decisión</th>
                        <th>Cliente asociado</th>
                        <th>Usuario</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($revisionesCobolResueltas as $r)
                    <tr>
                        <td class="text-nowrap">{{ $r->updated_at ? \Carbon\Carbon::parse($r->updated_at)->format('d/m/Y H:i') : '—' }}</td>
                        <td>{{ $r->entidad_origen }}</td>
                        <td><strong>{{ $r->clave_origen }}</strong></td>
                        <td>
                            @if ($r->decision === 'ASOCIAR_EXISTENTE')
                                <span class="badge text-bg-primary">ASOCIAR EXISTENTE</span>
                            @elseif ($r->decision === 'CREAR_SEPARADO')
                                <span class="badge text-bg-secondary">MANTENER SEPARADO</span>
                            @else
                                <span class="badge text-bg-light border">{{ $r->decision }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($r->cliente_id)
                                <strong>#{{ $r->cliente_id }}</strong>{{ $r->cliente_nombre ? ' — '.$r->cliente_nombre : '' }}
                            @else
                                <span class="text-muted">Persona separada</span>
                            @endif
                        </td>
                        <td>{{ $r->usuario_nombre ?: ($r->usuario_id ? '#'.$r->usuario_id : '—') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">Todavía no hay revisiones COBOL resueltas.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </details>

    <details class="card mb-3"><summary class="card-header fw-semibold">Historial de unificaciones — {{ $ultimasUnificaciones->count() }} cliente(s) absorbido(s)</summary>
        <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Auditoría</th><th>Principal</th><th>Absorbido</th><th>Usuario</th><th>Fecha</th><th>Estado</th></tr></thead><tbody>
        @forelse ($ultimasUnificaciones as $u)
            <tr><td>#{{ $u->id_unificacion }}</td><td>#{{ $u->id_registro_principal }} — {{ $u->principal_nombre }}</td><td>#{{ $u->id_registro_absorbido }} — {{ $u->absorbido_nombre }}</td><td>{{ $u->usuario_nombre ?: '—' }}</td><td>{{ $u->created_at ? \Carbon\Carbon::parse($u->created_at)->format('d/m/Y H:i') : '—' }}</td><td><span class="badge text-bg-secondary">{{ $u->estado }}</span></td></tr>
        @empty <tr><td colspan="6" class="text-center text-muted">Sin unificaciones de clientes.</td></tr>
        @endforelse
        </tbody></table></div>
    </details>
</div>

<script>
document.getElementById('comparar-seleccionados')?.addEventListener('click', () => {
    const p = document.querySelector('.js-principal:checked')?.value;
    const s = document.querySelector('.js-secundario:checked')?.value;
    if (!p || !s) { alert('Seleccione cuál queda y cuál será absorbido.'); return; }
    if (p === s) { alert('Debe seleccionar dos clientes distintos.'); return; }
    const url = new URL(@json(route('archivo.unificacion.clientes.comparar')), window.location.origin);
    url.searchParams.set('principal', p); url.searchParams.set('secundario', s); window.location.href = url.toString();
});
</script>
@endsection
