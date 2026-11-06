{{-- UNA sola vista para Administración y Autogestión. La autorización se verifica en los controladores. --}}
@php
    $monedaLccinq = static fn (int $c): string => '$ '.($c < 0 ? '-' : '').number_format(abs($c) / 100, 2, ',', '.');
    $fechaLccinq = static fn (?string $f): string => $f && preg_match('/^\d{4}-\d\d-\d\d$/', $f)
        ? substr($f, 8, 2).'/'.substr($f, 5, 2).'/'.substr($f, 0, 4) : '—';
@endphp
@forelse(($cuadrosCuentaCobol ?? []) as $cuadro)
<section class="card mb-3 shadow-sm" aria-label="Cuenta corriente COBOL {{ $cuadro['cuenta'] }}">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>{{ $cuadro['rol'] }} · CTA. NRO.: {{ substr($cuadro['cuenta'],0,4) }}/{{ substr($cuadro['cuenta'],4,5) }}/{{ substr($cuadro['cuenta'],9,2) }}</strong>
        <span class="small text-muted">Sede {{ $cuadro['sede'] }} · Cierre {{ $fechaLccinq($cuadro['cierre']) }} · Consulta {{ $fechaLccinq($cuadro['fecha_calculo']) }} · {{ $cuadro['fuente'] }}</span>
    </div>
    @if($cuadro['nombre'] || $cuadro['domicilio'])
        <div class="card-body py-2 small">
            @if($cuadro['nombre'])<div><strong>DEUDOR:</strong> {{ $cuadro['nombre'] }}</div>@endif
            @if($cuadro['domicilio'])<div><strong>DOMICILIO:</strong> {{ $cuadro['domicilio'] }}</div>@endif
        </div>
    @endif
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th rowspan="2" scope="col">Nº</th><th rowspan="2" scope="col">Detalle</th>
                    <th rowspan="2" scope="col">Vencimiento</th>
                    <th rowspan="2" scope="col" class="text-end">Valor nominal</th>
                    <th colspan="3" scope="colgroup" class="text-center">INTERESES</th>
                </tr>
                <tr><th scope="col" class="text-end">Tasa</th><th scope="col" class="text-end">Días</th><th scope="col" class="text-end">Importes</th></tr>
            </thead>
            <tbody>
                <tr class="table-secondary fw-bold"><th colspan="7">D É B I T O S</th></tr>
                @foreach($cuadro['debitos'] as $n => $fila)
                    <tr>
                        <td>{{ str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT) }}</td>
                        <td>{{ $fila['detalle'] }} <span class="text-muted small">· {{ $fila['codigo'] }}/{{ $fila['numero'] }}</span></td>
                        <td class="text-nowrap">{{ $fechaLccinq($fila['vence']) }}</td>
                        <td class="text-end text-nowrap">{{ $monedaLccinq($fila['importe']) }}</td>
                        <td class="text-end">{{ $fila['tasa'] === null ? '—' : $fila['tasa'] }}</td>
                        <td class="text-end">{{ $fila['dias'] ?? '—' }}</td>
                        <td class="text-end text-nowrap">{{ $fila['interes'] === null ? '—' : $monedaLccinq($fila['interes']) }}</td>
                    </tr>
                @endforeach
                <tr class="table-light fw-bold">
                    <td colspan="3" class="text-end">TOTAL DÉBITOS</td>
                    <td class="text-end">{{ $monedaLccinq($cuadro['total_debitos']) }}</td>
                    <td colspan="2"></td>
                    <td class="text-end">{{ $cuadro['total_intereses'] === null ? '—' : $monedaLccinq($cuadro['total_intereses']) }}</td>
                </tr>
                <tr class="table-secondary fw-bold"><th colspan="7">C R É D I T O S</th></tr>
                @foreach($cuadro['creditos'] as $n => $fila)
                    <tr><td>{{ str_pad((string) (count($cuadro['debitos']) + $n + 1), 3, '0', STR_PAD_LEFT) }}</td>
                        <td>{{ $fila['detalle'] }} <span class="text-muted small">· {{ $fila['codigo'] }}/{{ $fila['numero'] }}</span></td>
                        <td>—</td><td class="text-end text-nowrap">{{ $monedaLccinq($fila['importe']) }}</td>
                        <td colspan="3"></td></tr>
                @endforeach
                <tr class="table-light fw-bold"><td colspan="3" class="text-end">TOTAL CRÉDITOS</td>
                    <td class="text-end">{{ $monedaLccinq($cuadro['total_creditos']) }}</td><td colspan="3"></td></tr>
            </tbody>
            <tfoot>
                <tr class="{{ $cuadro['deuda_total'] !== null ? 'table-dark' : 'table-light' }} fw-bold">
                    <td colspan="6" class="text-end">SALDO</td>
                    <td class="text-end text-nowrap"
                        @if($cuadro['deuda_total'] === null) title="Saldo de capital; no incluye intereses calculados" @endif
                    >{{ $monedaLccinq($cuadro['deuda_total'] ?? $cuadro['saldo_sin_intereses']) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>
@empty
<div class="alert alert-light border">No hay cuenta corriente para mostrar.</div>
@endforelse
