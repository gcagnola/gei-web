<div class="alert alert-light border shadow-sm mb-3">
    <div class="fw-semibold">Historial de identidad y conciliación</div>
    <div class="small text-muted">Las unificaciones operativas y las decisiones sobre orígenes COBOL se conservan con auditoría. Esta vista es sólo de consulta.</div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Unificaciones aplicadas (total: {{ number_format($unificacionesPaginadas->total(), 0, ",", ".") }})</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Auditoría</th><th>Cliente canónico</th><th>Cliente absorbido</th><th>Usuario</th><th>Fecha</th><th>Estado</th></tr></thead>
        <tbody>
        @forelse($unificacionesPaginadas as $u)
            <tr>
                <td>#{{ $u->id_unificacion }}</td>
                <td>#{{ $u->id_registro_principal }} — {{ $u->principal_nombre }}</td>
                <td>#{{ $u->id_registro_absorbido }} — {{ $u->absorbido_nombre }}</td>
                <td>{{ $u->usuario_nombre ?: '—' }}</td>
                <td>{{ $u->created_at ? \Carbon\Carbon::parse($u->created_at)->format('d/m/Y H:i') : '—' }}</td>
                <td><span class="badge text-bg-secondary">{{ $u->estado }}</span></td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-3">No hay unificaciones registradas.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="small text-muted">
            Mostrando {{ $unificacionesPaginadas->firstItem() ?? 0 }} a {{ $unificacionesPaginadas->lastItem() ?? 0 }}
            de {{ number_format($unificacionesPaginadas->total(), 0, ',', '.') }} unificaciones.
        </span>
        @if($unificacionesPaginadas->hasPages())
            {{ $unificacionesPaginadas->appends(request()->except('unificaciones_page'))->onEachSide(1)->links() }}
        @endif
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Decisiones sobre orígenes COBOL (total: {{ number_format($decisionesCobolPaginadas->total(), 0, ',', '.') }})</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Fecha</th><th>Origen</th><th>Cuenta COBOL</th><th>Decisión</th><th>Cliente asociado</th><th>Usuario</th></tr></thead>
        <tbody>
        @forelse ($decisionesCobolPaginadas as $r)
            <tr>
                <td>{{ $r->updated_at ? \Carbon\Carbon::parse($r->updated_at)->format('d/m/Y H:i') : '—' }}</td>
                <td>{{ $r->entidad_origen }}</td>
                <td>{{ $r->clave_origen }}</td>
                <td><span class="badge text-bg-light border">{{ $r->decision }}</span></td>
                <td>{{ $r->cliente_id ? '#'.$r->cliente_id.' — '.$r->cliente_nombre : 'Persona separada' }}</td>
                <td>{{ $r->usuario_nombre ?: ($r->usuario_id ? '#'.$r->usuario_id : '—') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-3">No hay decisiones de origen COBOL registradas.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="small text-muted">
            Mostrando {{ $decisionesCobolPaginadas->firstItem() ?? 0 }} a {{ $decisionesCobolPaginadas->lastItem() ?? 0 }}
            de {{ number_format($decisionesCobolPaginadas->total(), 0, ',', '.') }} decisiones.
        </span>
        @if($decisionesCobolPaginadas->hasPages())
            {{ $decisionesCobolPaginadas->appends(request()->except('decisiones_page'))->onEachSide(1)->links() }}
        @endif
    </div>
</div>
