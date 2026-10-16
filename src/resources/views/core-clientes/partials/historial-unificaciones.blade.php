<div class="alert alert-light border shadow-sm mb-3">
    <div class="fw-semibold">Historial de identidad y conciliación</div>
    <div class="small text-muted">Las unificaciones operativas y las decisiones sobre orígenes COBOL se conservan con auditoría. Esta vista es sólo de consulta.</div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Unificaciones aplicadas (últimas {{ $ultimasUnificaciones->count() }})</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Auditoría</th><th>Cliente canónico</th><th>Cliente absorbido</th><th>Usuario</th><th>Fecha</th><th>Estado</th></tr></thead>
        <tbody>
        @forelse($ultimasUnificaciones as $u)
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
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Decisiones sobre orígenes COBOL (últimas {{ $revisionesCobolResueltas->count() }})</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Fecha</th><th>Origen</th><th>Cuenta COBOL</th><th>Decisión</th><th>Cliente asociado</th><th>Usuario</th></tr></thead>
        <tbody>
        @forelse ($revisionesCobolResueltas as $r)
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
</div>
