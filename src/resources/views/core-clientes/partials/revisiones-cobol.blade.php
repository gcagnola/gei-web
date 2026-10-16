<div class="alert alert-light border shadow-sm mb-3">
    <div class="fw-semibold">Revisiones de identidad COBOL pendientes</div>
    <div class="small text-muted">
        No son duplicados entre sí. Son identidades de origen que la importación no pudo asociar automáticamente.
        Su resolución se conserva para las próximas importaciones.
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body py-2">
        <div class="small text-muted">Clientes activos con revisión COBOL</div>
        <div class="fs-4 fw-semibold">{{ number_format($resumenRevision['activos_revision'], 0, ',', '.') }}</div>
    </div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body py-2">
        <div class="small text-muted">Orígenes sin cliente activo asociado</div>
        <div class="fs-4 fw-semibold">{{ number_format($conflictosSinClienteTotal, 0, ',', '.') }}</div>
    </div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body py-2">
        <div class="small text-muted">Clientes activos sin revisión COBOL</div>
        <div class="fs-4 fw-semibold">{{ number_format($resumenRevision['activos_ok'], 0, ',', '.') }}</div>
    </div></div></div>
</div>

<form class="card card-body border-0 shadow-sm mb-3" method="GET" action="{{ route('core-clientes.index') }}">
    <input type="hidden" name="modo" value="revisiones">
    <label class="form-label small" for="buscar-revisiones">Buscar cliente por ID, nombre, CUIT, documento o cuenta COBOL</label>
    <div class="d-flex gap-2">
        <input class="form-control" id="buscar-revisiones" name="buscar" value="{{ $buscar }}" maxlength="180">
        <button class="btn btn-primary">Buscar</button>
        <a class="btn btn-outline-secondary" href="{{ route('core-clientes.index', ['modo' => 'revisiones']) }}">Limpiar</a>
    </div>
</form>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold">Clientes con revisión COBOL</span>
        <span class="text-muted small">{{ number_format($resultadosRevision->total(), 0, ',', '.') }} resultado(s)</span>
    </div>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>ID</th><th>Cliente</th><th>CUIT / Documento</th><th>Roles</th><th>Cuentas</th><th>Cuenta COBOL a revisar</th><th>Acción</th></tr></thead>
        <tbody>
        @forelse ($resultadosRevision as $fila)
            <tr>
                <td><strong>#{{ $fila->id }}</strong></td>
                <td>{{ $fila->nombre }}</td>
                <td>{{ $fila->cuit ?: '—' }} <div class="small text-muted">{{ $fila->numero_documento ?: '' }}</div></td>
                <td>{{ $fila->roles ?: '—' }}</td>
                <td class="small">{{ $fila->cuentas ?: '—' }}</td>
                <td class="small">{{ $fila->cuentas_revision_cobol ?: '—' }}</td>
                <td>
                    @if($fila->revision_cobol_id)
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('archivo.unificacion.clientes.conflicto.revisar', $fila->revision_cobol_id) }}">
                            Revisar @if((int) $fila->revisiones_cobol_count > 1)({{ $fila->revisiones_cobol_count }})@endif
                        </a>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No se encontraron clientes con revisión COBOL.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    @if($resultadosRevision->hasPages())
        <div class="card-footer bg-white">{{ $resultadosRevision->onEachSide(1)->links() }}</div>
    @endif
</div>

@if($conflictosRevision->isNotEmpty())
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold">Orígenes pendientes relacionados con los clientes de esta página</div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>#</th><th>Cliente</th><th>Origen</th><th>Cuenta COBOL</th><th>Motivo</th><th>Acción</th></tr></thead>
            <tbody>
            @foreach($conflictosRevision as $cf)
                <tr>
                    <td>#{{ $cf->id }}</td>
                    <td>#{{ $cf->cliente_visible_id }} — {{ $cf->cliente_visible_nombre ?: '—' }}</td>
                    <td>{{ $cf->entidad_origen }}</td>
                    <td>{{ $cf->clave_origen }}</td>
                    <td>{{ $cf->motivo }}</td>
                    <td><a class="btn btn-sm btn-outline-primary" href="{{ route('archivo.unificacion.clientes.conflicto.revisar', $cf->id) }}">Revisar</a></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@endif

@if($conflictosSinClienteTotal > 0)
    <details class="card border-0 shadow-sm mb-3">
        <summary class="card-header bg-white fw-semibold" style="cursor:pointer">
            Orígenes COBOL activos sin cliente operativo asociado ({{ $conflictosSinClienteTotal }})
        </summary>
        <div class="card-body small text-muted">Se muestran como máximo 100 casos. La revisión no implica fusionar clientes.</div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>#</th><th>Origen</th><th>Cuenta</th><th>Motivo</th><th>Acción</th></tr></thead>
            <tbody>
            @foreach ($conflictosSinCliente as $cf)
                <tr>
                    <td>#{{ $cf->id }}</td><td>{{ $cf->entidad_origen }}</td><td>{{ $cf->clave_origen }}</td><td>{{ $cf->motivo }}</td>
                    <td><a class="btn btn-sm btn-outline-primary" href="{{ route('archivo.unificacion.clientes.conflicto.revisar', $cf->id) }}">Revisar</a></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </details>
@endif
