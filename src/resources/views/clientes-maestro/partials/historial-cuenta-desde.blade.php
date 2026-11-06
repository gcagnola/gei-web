{{-- Historial del libro COBOL: consulta desde una fecha, sin saldos ni intereses. --}}
@php
    $moneyHist = static fn (int $cent): string => ($cent < 0 ? '-$ ' : '$ ').number_format(abs($cent) / 100, 2, ',', '.');
    $queryHist = request()->query();
    $omitirHist = ['mes', 'fecha_desde_historial', 'pagina_historial', 'page', 'tab', 'fecha_lccinq', 'fecha_calculo'];
@endphp
<div class="d-flex flex-wrap align-items-end gap-3 mb-3">
    <form method="GET" action="{{ route('clientes-maestro.show', ['cliente' => $operativo->id]) }}" class="d-flex flex-wrap align-items-end gap-2">
        @foreach($queryHist as $kHist => $vHist)
            @if(!in_array($kHist, $omitirHist, true) && is_scalar($vHist))
                <input type="hidden" name="{{ $kHist }}" value="{{ $vHist }}">
            @endif
        @endforeach
        <input type="hidden" name="tab" value="cuenta-corriente">
        <div>
            <label class="form-label mb-1" for="gei-historial-mes">Mes/año</label>
            <select id="gei-historial-mes" name="mes" class="form-select form-select-sm">
                @for($iHist = 0; $iHist < 360; $iHist++)
                    @php($opcionHist = now('America/Argentina/Cordoba')->startOfMonth()->subMonthsNoOverflow($iHist)->format('Ym'))
                    <option value="{{ $opcionHist }}" @selected($opcionHist === $mes)>{{ substr($opcionHist, 4, 2) }}/{{ substr($opcionHist, 0, 4) }}</option>
                @endfor
            </select>
        </div>
        <button class="btn btn-sm btn-outline-primary" type="submit">Desde el mes</button>
    </form>
    <form method="GET" action="{{ route('clientes-maestro.show', ['cliente' => $operativo->id]) }}" class="d-flex flex-wrap align-items-end gap-2">
        @foreach($queryHist as $kHist => $vHist)
            @if(!in_array($kHist, $omitirHist, true) && is_scalar($vHist))
                <input type="hidden" name="{{ $kHist }}" value="{{ $vHist }}">
            @endif
        @endforeach
        <input type="hidden" name="tab" value="cuenta-corriente">
        <div>
            <label class="form-label mb-1" for="gei-historial-desde">Fecha individual (desde)</label>
            <input id="gei-historial-desde" type="date" name="fecha_desde_historial"
                   class="form-control form-control-sm" min="2000-01-01"
                   max="{{ now('America/Argentina/Cordoba')->toDateString() }}"
                   value="{{ $fechaDesdeHistorial }}" required>
        </div>
        <button class="btn btn-sm btn-outline-primary" type="submit">Desde la fecha</button>
    </form>
</div>
{{-- GEI_HISTORIAL_SALDO_INICIAL_20261106: único saldo del historial. No mostrar saldos por fila ni saldo final. --}}
@if(($saldosInicialesHistorial ?? collect())->isNotEmpty())
    <div class="border rounded bg-light p-2 mb-3" aria-label="Saldo inicial de la cuenta corriente">
        <div class="fw-semibold small mb-2">SALDO INICIAL — anterior al {{ substr($fechaDesdeHistorial, 8, 2) }}/{{ substr($fechaDesdeHistorial, 5, 2) }}/{{ substr($fechaDesdeHistorial, 0, 4) }}</div>
        @foreach($saldosInicialesHistorial as $saldoInicialHist)
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center small">
                <span>{{ $saldoInicialHist['rol'] }} · Cuenta {{ $formatearCuentaCobol($saldoInicialHist['cuenta']) }}</span>
                <strong>{{ $moneyHist($saldoInicialHist['saldo_centavos']) }}@if($saldoInicialHist['saldo_centavos'] < 0) <span class="text-success">(a favor)</span>@endif</strong>
            </div>
        @endforeach
        <div class="text-muted small mt-2">Arrastre contable reconstruido (débitos menos créditos anteriores). No equivale a la liquidación de deuda actual ni incluye intereses.</div>
    </div>
@endif
<p class="small text-muted mb-2">Movimientos desde el {{ substr($fechaDesdeHistorial, 8, 2) }}/{{ substr($fechaDesdeHistorial, 5, 2) }}/{{ substr($fechaDesdeHistorial, 0, 4) }}. El asterisco (*) indica un movimiento totalmente pagado.</p>
@if($historialCobolDesde->count())
    @foreach($historialCobolDesde->getCollection()->groupBy(static fn ($m): string => $m['rol'].'|'.$m['cuenta']) as $claveGrupoHist => $filasHist)
        <div class="fw-semibold small mb-1">{{ $filasHist->first()['rol'] }} · Cuenta {{ $formatearCuentaCobol($filasHist->first()['cuenta']) }}</div>
        <div class="table-responsive mb-3">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>Fecha</th><th>Código</th><th>Comprobante</th><th>Concepto</th>
                    <th class="text-end">Débito</th><th class="text-end">Crédito</th>
                </tr></thead>
                <tbody>
                @foreach($filasHist as $movHist)
                    <tr>
                        <td class="text-nowrap">{{ substr($movHist['fecha'], 8, 2) }}/{{ substr($movHist['fecha'], 5, 2) }}/{{ substr($movHist['fecha'], 0, 4) }}</td>
                        <td class="text-nowrap">{{ $movHist['pagado'] ? '*' : '' }}{{ $movHist['codigo'] }}</td>
                        <td>{{ $movHist['numero'] }}</td>
                        <td>{{ $movHist['descripcion'] }}</td>
                        <td class="text-end text-nowrap">{{ $movHist['debe'] !== 0 ? $moneyHist($movHist['debe']) : '—' }}</td>
                        <td class="text-end text-nowrap">{{ $movHist['haber'] !== 0 ? $moneyHist($movHist['haber']) : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
@else
    <p class="text-muted small mb-0">No hay movimientos desde la fecha seleccionada.</p>
@endif
@if($historialCobolDesde->hasPages())
    {{ $historialCobolDesde->links() }}
@endif
