<div class="small mb-2">
    <strong>Histórico COBOL:</strong> {{ $rol }} · {{ $cuenta }} · {{ $sedeCuenta }}
    <span class="text-muted">({{ $registros->count() }} registros originales)</span>
</div>
<div class="table-responsive">
    <table class="table table-sm table-striped align-middle mb-0">
        <thead><tr>
            <th>Período</th><th>Nombre en COBOL</th><th>CUIT en COBOL</th>
            <th>Domicilio en COBOL</th><th>Activo</th>
        </tr></thead>
        <tbody>
        @forelse($registros as $registro)
            <tr>
                <td>{{ substr($registro->periodo, 4, 2) }}-{{ substr($registro->periodo, 0, 4) }}</td>
                <td>{{ $registro->nombre_origen ?: '—' }}</td>
                <td>{{ $registro->cuit_origen ?: '—' }}</td>
                <td>{{ $registro->domicilio_origen ?: '—' }}</td>
                <td>{{ $registro->activo_en_periodo === null ? 'No determinado' : ($registro->activo_en_periodo ? 'Sí' : 'No') }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-muted text-center py-3">Sin registros originales para esta cuenta.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
