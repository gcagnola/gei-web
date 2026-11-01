{{-- Presentacion original de COCCTE/GIMB68 sin recalcular movimientos. --}}
@foreach($cuentasCobolGimb68 as $panelGimb68)
    @php
        $saldos = $panelGimb68['saldos'];
        $dineroCentavos = static fn ($n) => number_format(abs((int) $n) / 100, 2, ',', '.');
        $saldoCentavos = static fn ($n) => $dineroCentavos($n).((int)$n < 0 ? ' CR' : '');
    @endphp
    <section class="card border mb-3" aria-label="Cuenta corriente COBOL {{ $panelGimb68['cuenta'] }}">
        <div class="card-header bg-light d-flex flex-wrap justify-content-between gap-2 align-items-center small">
            <strong>{{ $panelGimb68['rol'] }} · Cuenta {{ $formatearCuentaCobol($panelGimb68['cuenta']) }} · {{ $panelGimb68['sede'] }}</strong>
            <span>Origen: {{ $panelGimb68['fuente'] }} · Cierre: {{ \Carbon\CarbonImmutable::parse($panelGimb68['cierre'])->format('d/m/Y') }} · Período {{ substr($mes, 4, 2) }}/{{ substr($mes, 0, 4) }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0 align-middle">
                <thead class="table-light"><tr>
                    <th>Fecha</th><th>Código</th><th>Comprobante</th><th>Concepto</th>
                    <th class="text-end">Debe</th><th class="text-end">Haber</th><th class="text-end">Saldo</th>
                </tr></thead>
                <tbody>
                    <tr class="table-secondary fw-semibold">
                        <td colspan="4">SALDOS ANTERIORES</td>
                        <td class="text-end">{{ $dineroCentavos($saldos['debitos_anteriores']) }}</td>
                        <td class="text-end">{{ $dineroCentavos($saldos['creditos_anteriores']) }}</td>
                        <td class="text-end">{{ $saldoCentavos($saldos['saldo_anterior']) }}</td>
                    </tr>
                    @forelse($saldos['movimientos'] as $mov)
                        <tr>
                            <td class="text-nowrap">{{ filled($mov['fecha'] ?? null) ? \Carbon\CarbonImmutable::parse($mov['fecha'])->format('d/m/Y') : '—' }}</td>
                            <td>{{ $mov['codigo'] ?? '—' }}</td>
                            <td>{{ $mov['numero'] ?? '—' }}</td>
                            <td>{{ ($mov['descripcion'] ?? null) ?: '—' }}</td>
                            <td class="text-end">{{ number_format((float) ($mov['debe'] ?? 0), 2, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float) ($mov['haber'] ?? 0), 2, ',', '.') }}</td>
                            <td class="text-end fw-semibold">{{ $saldoCentavos($mov['saldo_centavos']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted text-center">Sin movimientos en el período; se conserva el saldo arrastrado.</td></tr>
                    @endforelse
                    <tr class="table-light fw-bold"><td colspan="6" class="text-end">SALDO AL CORTE</td>
                        <td class="text-end">{{ $saldoCentavos($saldos['saldo_actual']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white text-muted small">
            @if($panelGimb68['rol'] === 'PROPIETARIO')
                CTACTEPRO · Proyección de saldo Debe − Haber sobre {{ $panelGimb68['fuente'] }}. Aún debe conciliarse el arrastre inicial con COBOL; no constituye un saldo exigible ni una liquidación.
            @else
                Conciliación GIMB68 / COCCTE sobre {{ $panelGimb68['fuente'] }}. El saldo contable se presenta separado de la simulación de intereses y no constituye deuda exigible.
            @endif
            @if((int) $saldos['saldo_actual'] < 0)
                <strong class="text-success ms-2">Saldo a favor: $ {{ $dineroCentavos($saldos['saldo_actual']) }}.</strong>
            @endif
        </div>
    </section>
@endforeach
@if($incidenciasCobolGimb68 !== [])
    <div class="alert alert-warning small" role="status">
        No se pudo mostrar el cierre COCCTE para todas las cuentas. Se mantiene la consulta operativa disponible.
    </div>
@endif
