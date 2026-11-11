{{-- GEI_PORTAL_CC_PREVISUALIZACION_DIAGNOSTICA_20261111
     Libro mensual único: saldo inicial + asientos, sin sumar de nuevo capital COBOL.
     Intereses de LCCINQ: detalle sobre obligaciones pendientes (mismo servicio de Administración).
     Si no existe cálculo validado, el capital NO se publica como deuda definitiva. --}}
@forelse(($cuadrosCuentaCobol ?? []) as $cuadro)
    @if($saldoContable !== null && $seleccionada !== null
        && $seleccionada['cuenta'] === $cuadro['cuenta'] && $seleccionada['rol'] === $cuadro['rol'])
        @php
            $aCentavos = static fn ($n): int => \App\Services\CuentaCorrienteLccinqCompartidaService::centavos($n ?? '0.00');
            $dineroLibro = static fn (int $c): string => '$ '.number_format(abs($c) / 100, 2, ',', '.').($c < 0 ? ' CR' : '');
            $fechaLibro = static fn ($f): string => $f ? \Illuminate\Support\Carbon::parse($f)->format('d/m/Y') : '—';
            $sInicial = $aCentavos($saldoContable->saldo_anterior);
            $sFinal = $aCentavos($saldoContable->saldo_al_corte);
            $dMes = $aCentavos($saldoContable->debe_mes);
            $hMes = $aCentavos($saldoContable->haber_mes);
            $debitosLibro = $movimientosLibro->filter(static fn ($m): bool => $m->debe_centavos !== 0);
            $creditosLibro = $movimientosLibro->filter(static fn ($m): bool => $m->haber_centavos !== 0);
            $totalIntereses = $cuadro['total_intereses'];
            $capitalConciliado = $sFinal === (int) $cuadro['saldo_sin_intereses'];
            $conciliado = $libroConciliado && $sInicial + $dMes - $hMes === $sFinal;
            $haySinFecha = (int) $saldoContable->fechas_sin_definir > 0;
            $fechaConsulta = (string) ($cuadro['fecha_calculo'] ?? '');
            $fechaCierre = (string) ($cuadro['cierre'] ?? '');
            $hayMovimientosPosteriores = $fechaCierre !== '' && $movimientosLibro->contains(
                static fn ($m): bool => (string) $m->fecha > $fechaCierre
            );
            // Solo total exigible cuando coinciden libro historico, capital COBOL y calculo GIMB38.
            $esDiagnostico = (bool) ($cuadro['solo_diagnostico'] ?? false);
            $baseVerificable = $conciliado && !$haySinFecha && $capitalConciliado
                && $totalIntereses !== null && $fechaCierre !== ''
                && $fechaConsulta >= $fechaCierre
                && ($cuadro['fuente'] ?? '') === 'CIERRE_PUBLICADO';
            // La comprobacion de un cierre COBOL independiente NO esta terminada.
            // En diagnostico se permite visualizar una estimacion diferenciada,
            // pero NUNCA se rotula como saldo exigible.
            $importeConfiable = !$esDiagnostico && $baseVerificable && !$hayMovimientosPosteriores;
            $totalPagar = $importeConfiable ? $sFinal + (int) $totalIntereses : null;
            $totalPreview = $esDiagnostico && $baseVerificable
                ? $sFinal + (int) $totalIntereses : null;
            $numeroFila = 0;
        @endphp
        <section class="card mb-3 shadow-sm" aria-label="Cuenta corriente {{ $cuadro['cuenta'] }}">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <strong>{{ $cuadro['rol'] }} · CTA. NRO.: {{ substr($cuadro['cuenta'], 0, 4) }}/{{ substr($cuadro['cuenta'], 4, 5) }}/{{ substr($cuadro['cuenta'], 9, 2) }}</strong>
                <span class="small text-muted">Sede {{ $cuadro['sede'] }} · <strong class="text-body">Cierre {{ $fechaLibro($fechaCierre) }}</strong> · <strong class="text-body">Consulta {{ $fechaLibro($fechaConsulta) }}</strong></span>
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
                            <th rowspan="2">Nº</th>
                            <th rowspan="2">Fecha</th>
                            <th rowspan="2">Detalle</th>
                            <th rowspan="2">Vencimiento</th>
                            <th rowspan="2" class="text-end">Débitos</th>
                            <th rowspan="2" class="text-end">Créditos</th>
                            <th colspan="3" class="text-center">INTERESES</th>
                            <th rowspan="2" class="text-end">Saldo</th>
                        </tr>
                        <tr>
                            <th class="text-end">Tasa</th>
                            <th class="text-end">Días</th>
                            <th class="text-end">Importes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-info fw-bold">
                            <th colspan="9">SALDO INICIAL · {{ substr($periodo, 4, 2) }}/{{ substr($periodo, 0, 4) }}</th>
                            <td class="text-end text-nowrap">{{ $dineroLibro($sInicial) }}</td>
                        </tr>
                        <tr class="table-secondary fw-bold"><th colspan="10">D É B I T O S</th></tr>
                        @foreach($debitosLibro as $m)
                            @php $numeroFila++; @endphp
                            <tr>
                                <td>{{ str_pad((string) $numeroFila, 3, '0', STR_PAD_LEFT) }}</td>
                                <td class="text-nowrap">{{ $fechaLibro($m->fecha) }}</td>
                                <td>{{ $m->descripcion ?: 'Movimiento contable' }} <span class="small text-muted">· {{ $m->codigo }}/{{ $m->numero }}</span></td>
                                <td>—</td>
                                <td class="text-end text-nowrap">{{ $dineroLibro($m->debe_centavos) }}</td>
                                <td></td><td></td><td></td><td></td><td></td>
                            </tr>
                        @endforeach
                        <tr class="table-light fw-bold">
                            <td colspan="4" class="text-end">TOTAL DÉBITOS</td>
                            <td class="text-end text-nowrap">{{ $dineroLibro($dMes) }}</td>
                            <td colspan="5"></td>
                        </tr>
                        <tr class="table-secondary fw-bold"><th colspan="10">C R É D I T O S</th></tr>
                        @foreach($creditosLibro as $m)
                            @php $numeroFila++; @endphp
                            <tr>
                                <td>{{ str_pad((string) $numeroFila, 3, '0', STR_PAD_LEFT) }}</td>
                                <td class="text-nowrap">{{ $fechaLibro($m->fecha) }}</td>
                                <td>{{ $m->descripcion ?: 'Movimiento contable' }} <span class="small text-muted">· {{ $m->codigo }}/{{ $m->numero }}</span></td>
                                <td>—</td><td></td>
                                <td class="text-end text-nowrap">{{ $dineroLibro($m->haber_centavos) }}</td>
                                <td></td><td></td><td></td><td></td>
                            </tr>
                        @endforeach
                        <tr class="table-light fw-bold">
                            <td colspan="5" class="text-end">TOTAL CRÉDITOS</td>
                            <td class="text-end text-nowrap">{{ $dineroLibro($hMes) }}</td>
                            <td colspan="4"></td>
                        </tr>
                        <tr class="table-light fw-bold">
                            <td colspan="9" class="text-end">SUBTOTAL CAPITAL (SIN INTERESES)</td>
                            <td class="text-end text-nowrap">{{ $dineroLibro($sFinal) }}</td>
                        </tr>
                        <tr class="table-secondary fw-bold"><th colspan="10">I N T E R E S E S  Y  A C C E S O R I O S  ·  GIMB38</th></tr>
                        @foreach($cuadro['debitos'] as $pendiente)
                            @php $numeroFila++; @endphp
                            <tr>
                                <td>{{ str_pad((string) $numeroFila, 3, '0', STR_PAD_LEFT) }}</td>
                                <td class="text-nowrap">{{ $fechaLibro($fechaConsulta) }}</td>
                                <td>Sobre: {{ $pendiente['detalle'] }}
                                    <span class="small text-muted">· {{ $pendiente['codigo'] }}/{{ $pendiente['numero'] }} · Capital pendiente: {{ $dineroLibro((int) $pendiente['importe']) }}</span></td>
                                <td class="text-nowrap">{{ $fechaLibro($pendiente['vence']) }}</td>
                                {{-- El capital pendiente ya esta en el saldo del libro: nunca sumar otra vez. --}}
                                <td></td><td></td>
                                <td class="text-end">{{ $pendiente['tasa'] === null ? '—' : $pendiente['tasa'].' %' }}</td>
                                <td class="text-end">{{ $pendiente['dias'] ?? '—' }}</td>
                                <td class="text-end text-nowrap">{{ $pendiente['interes'] === null ? '—' : $dineroLibro((int) $pendiente['interes']) }}</td>
                                <td></td>
                            </tr>
                        @endforeach
                        <tr class="table-light fw-bold">
                            <td colspan="8" class="text-end">TOTAL INTERESES Y ACCESORIOS AL {{ $fechaLibro($fechaConsulta) }}</td>
                            <td class="text-end text-nowrap">{{ $totalIntereses === null ? 'SIN VALIDAR' : $dineroLibro((int) $totalIntereses) }}</td>
                            <td></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="table-dark fw-bold">
                            <th colspan="9" class="text-end">{{ $totalPagar !== null ? 'TOTAL A PAGAR AL '.$fechaLibro($fechaConsulta) : ($totalPreview !== null ? 'TOTAL DIAGNÓSTICO AL '.$fechaLibro($fechaConsulta).' (NO EXIGIBLE)' : 'TOTAL A PAGAR PENDIENTE DE VALIDACIÓN') }}</th>
                            <td class="text-end text-nowrap">
                                @if($totalPagar !== null)
                                    {{ $dineroLibro($totalPagar) }}
                                    @if($totalPagar < 0)<span class="small">(saldo a favor)</span>@endif
                                @elseif($totalPreview !== null)
                                    {{ $dineroLibro($totalPreview) }} <span class="small">(diagnóstico)</span>
                                @else — @endif
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @if($esDiagnostico)
                <div class="alert alert-info m-2 py-2 small" role="status">
                    <strong>PREVISUALIZACIÓN EN DESARROLLO:</strong> los intereses GIMB38 se calcularon
                    usando los parámetros verificados del cierre, pero todavía no existe una
                    conciliación independiente de esta cuenta contra una impresión COBOL
                    del mismo corte. No usar este resultado para cobrar ni publicar saldos.
                </div>
            @endif
            @if(!$conciliado || !$capitalConciliado || $haySinFecha || $hayMovimientosPosteriores || $totalIntereses === null)
                <div class="alert alert-warning m-2 py-2 small" role="status">
                    @if(!$conciliado)
                        Los asientos del mes no concilian con el saldo contable.
                    @elseif($haySinFecha)
                        Hay movimientos sin fecha que pueden afectar el saldo.
                    @elseif(!$capitalConciliado)
                        El saldo contable difiere del capital pendiente del cierre COBOL. No se duplica el capital.
                    @elseif($hayMovimientosPosteriores)
                        Hay movimientos posteriores al cierre publicado. No se presenta un total exigible sin una nueva publicación.
                    @elseif($totalIntereses === null)
                        Faltan parámetros GIMB38 válidos o la conciliación no está habilitada. Se muestra el capital, no un total exigible.
                    @endif
                </div>
            @endif
            <div class="small text-muted px-3 py-2">
                Una sola cuenta corriente: saldo inicial más movimientos del mes. Las tasas y los intereses provienen del servicio LCCINQ compartido con Administración; el capital pendiente COBOL se informa para referencia y no se suma nuevamente.
                @if($fechaCierre !== '' && $fechaConsulta > $fechaCierre)
                    La consulta usa el último cierre publicado ({{ $fechaLibro($fechaCierre) }}); pagos o cambios posteriores todavía no publicados pueden modificar el resultado.
                @endif
            </div>
        </section>
    @else
        <div class="alert alert-warning" role="status">
            No hay libro contable suficiente para construir el saldo inicial de esta cuenta. No se supone saldo cero ni se informa un total exigible.
        </div>
    @endif
@empty
    <div class="alert alert-light border">No hay cuenta corriente para mostrar.</div>
@endforelse
