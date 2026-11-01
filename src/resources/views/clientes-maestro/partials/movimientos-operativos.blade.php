{{-- Consulta de solo lectura: cuentas autorizadas en el controlador, nunca personas Core. --}}
@php
    // PostgreSQL NUMERIC(2 decimales): convertir sin acumulación en coma flotante.
    $centavos = static function ($importe): int {
        $texto = trim((string) ($importe ?? '0'));
        if (! preg_match('/^(-?)([0-9]+)(?:\.([0-9]{1,2}))?$/D', $texto, $partes)) {
            throw new \InvalidArgumentException('Importe monetario inesperado en movimientos operativos.');
        }
        $valor = ((int) $partes[2] * 100) + (int) str_pad($partes[3] ?? '0', 2, '0');
        return ($partes[1] === '-' ? -1 : 1) * $valor;
    };
    $moneda = static fn (int $valor): string => ($valor < 0 ? '-$ ' : '$ ').number_format(abs($valor) / 100, 2, ',', '.');
    $resumenPorCuenta = $resumenesOperativos->keyBy(static fn ($r): string => $r->rol.'|'.$r->cuenta);
    $movimientosPorCuenta = $movimientosOperativos->groupBy(static fn ($m): string => $m->tipo.'|'.$m->cuenta_cobol);
@endphp
@forelse($movimientosPorCuenta as $claveCuenta => $movimientosCuenta)
    @php
        $primero = $movimientosCuenta->first();
        $resumenCuenta = $resumenPorCuenta->get($claveCuenta);
        $saldoCentavos = $centavos($resumenCuenta?->saldo_anterior ?? 0);
        $debeMes = 0;
        $haberMes = 0;
    @endphp
    <section class="border rounded mb-3" aria-label="Movimientos operativos {{ $primero->cuenta_cobol }}">
        <header class="bg-light border-bottom px-3 py-2 d-flex flex-wrap justify-content-between gap-2">
            <strong>{{ $primero->tipo }} · Cuenta {{ $formatearCuentaCobol($primero->cuenta_cobol) }}</strong>
            <small class="text-muted">{{ $movimientosCuenta->count() }} movimientos · {{ substr($mes, 4, 2) }}/{{ substr($mes, 0, 4) }} · Origen: gei_db</small>
        </header>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>Fecha</th><th>Código</th><th>Número</th><th>Descripción</th>
                    <th class="text-end">Debe</th><th class="text-end">Haber</th><th class="text-end">Saldo</th>
                </tr></thead>
                <tbody>
                    <tr class="table-secondary fw-semibold">
                        <td colspan="4">SALDOS ANTERIORES</td>
                        <td colspan="3" class="text-end">{{ $moneda($saldoCentavos) }}</td>
                    </tr>
                    @foreach($movimientosCuenta as $m)
                        @php
                            $debe = $centavos($m->debe ?? 0);
                            $haber = $centavos($m->haber ?? 0);
                            $afecta = ! in_array($m->afecta_saldo, [false, 0, '0', 'f', 'false'], true);
                            if ($afecta) {
                                $saldoCentavos += $debe - $haber;
                                $debeMes += $debe;
                                $haberMes += $haber;
                            }
                        @endphp
                        <tr>
                            <td class="text-nowrap">{{ $m->fecha ? \Carbon\CarbonImmutable::parse($m->fecha)->format('d/m/Y') : '—' }}</td>
                            <td>
                                <span class="fw-semibold">{{ $m->codigo ?: '—' }}</span>
                                @if($m->contabilidad_tooltip)
                                    <span class="ms-1 {{ $m->contabilidad_completa ? 'text-primary' : 'text-danger' }}"
                                          title="{{ $m->contabilidad_tooltip }}" style="cursor:help"
                                          aria-label="{{ $m->contabilidad_completa ? 'Imputación contable completa' : 'Imputación contable incompleta' }}">ⓘ</span>
                                @endif
                            </td>
                            <td>{{ $m->numero ?: '—' }}</td>
                            <td>
                                {{ $m->descripcion ?: ($m->concepto_descripcion ?: '—') }}
                                @if($m->concepto_descripcion && $m->descripcion)
                                    <div class="small text-muted">{{ $m->concepto_descripcion }}</div>
                                @endif
                                @unless($afecta)
                                    <div class="small text-muted">Informativo: no afecta el saldo.</div>
                                @endunless
                            </td>
                            <td class="text-end">{{ $debe !== 0 ? $moneda($debe) : '—' }}</td>
                            <td class="text-end">{{ $haber !== 0 ? $moneda($haber) : '—' }}</td>
                            <td class="text-end fw-semibold">{{ $moneda($saldoCentavos) }}</td>
                        </tr>
                    @endforeach
                    <tr class="table-light fw-semibold">
                        <td colspan="4" class="text-end">Movimientos que afectan saldo</td>
                        <td class="text-end">{{ $moneda($debeMes) }}</td>
                        <td class="text-end">{{ $moneda($haberMes) }}</td>
                        <td></td>
                    </tr>
                    <tr class="table-light fw-bold">
                        <td colspan="6" class="text-end">SALDO AL CORTE</td>
                        <td class="text-end">{{ $moneda($saldoCentavos) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <footer class="p-2 text-muted small">
            Proyección operativa Debe − Haber desde los movimientos fechados; no reemplaza el cierre COBOL
            ni la simulación GIMB38. Las fechas ausentes se informan en el cuadro de saldos operativos.
        </footer>
    </section>
@empty
    <div class="text-muted text-center py-3">No hay movimientos registrados para este mes en las cuentas sin cierre COBOL disponible.</div>
@endforelse
