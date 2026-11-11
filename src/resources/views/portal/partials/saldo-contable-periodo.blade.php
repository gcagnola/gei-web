{{-- GEI_PORTAL_SALDO_APERTURA_20261110: saldo del libro historico, distinto de la deuda LCCINQ. --}}
@if($seleccionada !== null && $periodo !== '')
    @if($saldoContable !== null)
        @php
            $formatearMontoContable = static fn ($valor): string => '$ '.number_format(abs((float) $valor), 2, ',', '.');
            $periodoContableTexto = substr($periodo, 4, 2).'/'.substr($periodo, 0, 4);
            $fechasSinDefinirContable = (int) $saldoContable->fechas_sin_definir;
        @endphp
        <section class="card border-primary shadow-sm mb-3" aria-label="Resumen contable del periodo">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between gap-2 align-items-center">
                <strong>Resumen contable · {{ $periodoContableTexto }}</strong>
                <span class="small text-muted">{{ $seleccionada['rol'] }} · {{ $seleccionada['cuenta'] }}</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6 col-lg-3">
                        <div class="small text-muted">Saldo inicial del período</div>
                        <div class="fs-5 fw-semibold">{{ $formatearMontoContable($saldoContable->saldo_anterior) }}</div>
                        @if((float) $saldoContable->saldo_anterior < 0)
                            <div class="text-success small">Saldo a favor</div>
                        @endif
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="small text-muted">Débitos del período</div>
                        <div class="fs-5">{{ $formatearMontoContable($saldoContable->debe_mes) }}</div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="small text-muted">Créditos del período</div>
                        <div class="fs-5">{{ $formatearMontoContable($saldoContable->haber_mes) }}</div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="small text-muted">Saldo contable al corte</div>
                        <div class="fs-5 fw-bold">{{ $formatearMontoContable($saldoContable->saldo_al_corte) }}</div>
                        @if((float) $saldoContable->saldo_al_corte < 0)
                            <div class="text-success small">Saldo a favor</div>
                        @endif
                    </div>
                </div>
                @if($fechasSinDefinirContable > 0)
                    <div class="alert alert-warning mt-3 mb-0 py-2 small">
                        Hay {{ $fechasSinDefinirContable }} movimientos sin fecha que no pueden asignarse al saldo de apertura ni al período. Este resumen puede ser incompleto.
                    </div>
                @endif
                <p class="small text-muted mt-3 mb-0">
                    Arrastre contable según movimientos históricos registrados que afectan saldo.
                    Es una referencia de libro histórico, no una deuda exigible;
                    <strong>no se suma</strong> al detalle de pendientes del cierre COBOL.
                </p>
            </div>
        </section>
    @else
        <div class="alert alert-warning small mb-3">
            No hay una cuenta corriente histórica registrada para calcular el saldo inicial de este período.
            No se supone saldo inicial cero.
        </div>
    @endif
@endif
