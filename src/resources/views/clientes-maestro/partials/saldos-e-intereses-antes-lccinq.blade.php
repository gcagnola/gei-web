{{-- Lectura de movimientos operativos + diagnostico COBOL separado. --}}
@if($resumenSaldosCuentas->isNotEmpty())
<div class="card border-secondary-subtle mb-3">
    <div class="card-header bg-light fw-semibold">Saldo inicial y movimientos por cuenta · {{ substr($mes,4,2) }}/{{ substr($mes,0,4) }}</div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead><tr><th>Rol / Cuenta</th><th class="text-end">Saldo inicial*</th><th class="text-end">Debe mes</th><th class="text-end">Haber mes</th><th class="text-end">Saldo final del mes*</th><th class="text-end">Penalidad registrada**</th></tr></thead>
        <tbody>
            <tr class="table-secondary fw-semibold">
                <td colspan="6">SALDOS ANTERIORES AL 01/{{ substr($mes,4,2) }}/{{ substr($mes,0,4) }} — arrastre al inicio del mes seleccionado</td>
            </tr>
            @foreach($resumenSaldosCuentas as $r)
                <tr>
                    <td class="text-nowrap">{{ $r->rol }}<div class="small">{{ $formatearCuentaCobol($r->cuenta) }}</div></td>
                    <td class="text-end">{{ $pesos($r->saldo_anterior) }}</td>
                    <td class="text-end">{{ $pesos($r->debe_mes) }}</td>
                    <td class="text-end">{{ $pesos($r->haber_mes) }}</td>
                    <td class="text-end fw-semibold">{{ $pesos($r->saldo_al_corte) }}</td>
                    <td class="text-end">{{ $r->rol === 'INQUILINO' ? $pesos($r->penalidades_registradas) : '—' }}</td>
                </tr>
                @if($r->fechas_sin_definir > 0)
                    <tr><td colspan="6" class="small text-warning">{{ $r->fechas_sin_definir }} movimiento(s) sin fecha: no se incluyen en los saldos cronológicos.</td></tr>
                @endif
            @endforeach
        </tbody>
    </table></div>
    <div class="card-footer bg-white small text-muted">
        * El saldo inicial corresponde al arrastre de los movimientos anteriores al primer día del mes; el saldo final incluye los movimientos del mes. Proyección Debe − Haber de movimientos operativos que afectan saldo, desde la primera fecha disponible. No sustituye el saldo de cierre conciliado con COBOL ni implica deuda exigible. Se muestra cada cuenta por separado, sin mezclar sedes o roles.<br>
        ** Penalidad almacenada en movimientos de inquilino hasta el corte; NO es la simulación de intereses GIMB38 ni la penalidad acumulada del contrato.
    </div>
</div>
@endif

@if($mostrarInteresesPrueba && $cuentasInquilinasSimulables->isNotEmpty())
<div class="card border-warning mb-3">
    <div class="card-header bg-warning-subtle fw-semibold">Intereses GIMB38 · Simulación de cierre PRUEBA (inquilinos Santa Fe)</div>
    <div class="card-body">
        <div class="alert alert-warning small py-2">Solo GeI-Desarrollo. Se utiliza la misma clase GIMB38 que en Autogestión. No es una deuda exigible, no se recalculan registros históricos ni se publican saldos.</div>
        <form method="GET" class="row g-2 align-items-end mb-3">
            @foreach(request()->query() as $k => $val)
                @if(!in_array($k, ['cuenta_intereses', 'fecha_calculo'], true) && is_scalar($val))
                    <input type="hidden" name="{{ $k }}" value="{{ $val }}">
                @endif
            @endforeach
            <input type="hidden" name="tab" value="cuenta-corriente">
            <div class="col-sm-5">
                <label class="form-label small" for="cuenta_intereses">Cuenta inquilino</label>
                <select class="form-select form-select-sm" name="cuenta_intereses" id="cuenta_intereses">
                    @foreach($cuentasInquilinasSimulables as $cuentaIntereses)
                        <option value="{{ $cuentaIntereses }}" @selected($cuentaIntereses === $cuentaSeleccionadaIntereses)>{{ $formatearCuentaCobol($cuentaIntereses) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-4">
                <label class="form-label small" for="fecha_calculo">Fecha de simulación</label>
                <input class="form-control form-control-sm" type="date" name="fecha_calculo" id="fecha_calculo" value="{{ $fechaCalculoIntereses }}" @if($diagnosticoIntereses) min="{{ $diagnosticoIntereses['cierre'] }}" @endif>
            </div>
            <div class="col-sm-3"><button class="btn btn-sm btn-warning w-100" type="submit">Simular</button></div>
        </form>
        @if($errorIntereses)
            <div class="alert alert-secondary small mb-0">Simulación no disponible: {{ $errorIntereses }}. Se conserva la cuenta corriente operativa sin cambios.</div>
        @endif
        @if($diagnosticoIntereses)
            @php
                $centavosIntereses = static fn (int $n): string => '$ '.($n < 0 ? '-' : '').number_format(abs($n) / 100, 2, ',', '.');
                $totalSimulado = (int) $diagnosticoIntereses['total'];
            @endphp
            <div class="small text-muted mb-2">Cierre COBOL: {{ \Carbon\CarbonImmutable::parse($diagnosticoIntereses['cierre'])->format('d/m/Y') }} · Simulación: {{ \Carbon\CarbonImmutable::parse($diagnosticoIntereses['fecha'])->format('d/m/Y') }} · IVA tipo {{ $diagnosticoIntereses['tipo_iva'] }}.</div>
            <div class="row g-2 mb-3">
                @foreach(['capital'=>'Capital pendiente','creditos'=>'Créditos no aplicados','interes'=>'Intereses acumulados','iva'=>'IVA de intereses','sobretasa'=>'Sobretasa','total'=>'Saldo neto simulado'] as $campoInteres => $etiquetaInteres)
                    <div class="col-6 col-lg-4"><div class="border rounded p-2 h-100"><div class="small text-muted">{{ $etiquetaInteres }}</div><strong>{{ $centavosIntereses($diagnosticoIntereses[$campoInteres]) }}</strong></div></div>
                @endforeach
            </div>
            @if($totalSimulado < 0)
                <div class="alert alert-success py-2 mb-3" role="status">
                    <strong>Saldo a favor: {{ $centavosIntereses(abs($totalSimulado)) }}</strong>.
                    El importe representa un crédito neto en esta simulación, no una deuda.
                </div>
            @elseif($totalSimulado > 0)
                <div class="alert alert-warning py-2 mb-3" role="status">
                    <strong>Saldo neto de la simulación: {{ $centavosIntereses($totalSimulado) }}</strong>.
                    Es un importe orientativo: no constituye deuda exigible.
                </div>
            @else
                <div class="alert alert-success py-2 mb-3" role="status">Sin saldo neto pendiente en esta simulación.</div>
            @endif
            <p class="small text-muted">Se supone que los movimientos pendientes al cierre siguieron sin pagarse hasta la fecha elegida. No se incluyen movimientos posteriores al cierre COBOL.</p>
            <details><summary class="fw-semibold small" style="cursor:pointer">Ver cálculo por movimiento ({{ count($diagnosticoIntereses['filas']) }})</summary>
                <div class="table-responsive mt-2"><table class="table table-sm">
                    <thead><tr><th>Concepto</th><th>Vencimiento</th><th class="text-end">Días</th><th class="text-end">Capital</th><th class="text-end">Interés</th><th class="text-end">IVA</th></tr></thead>
                    <tbody>@foreach($diagnosticoIntereses['filas'] as $filaInteres)
                        <tr><td>{{ $filaInteres['codigo'] }}/{{ $filaInteres['numero'] }} · {{ $filaInteres['concepto'] }}</td><td>{{ !empty($filaInteres['vence']) && $filaInteres['vence'] !== '-' ? \Carbon\CarbonImmutable::parse($filaInteres['vence'])->format('d/m/Y') : '—' }}</td><td class="text-end">{{ $filaInteres['dias'] }}</td><td class="text-end">{{ $centavosIntereses($filaInteres['capital']) }}</td><td class="text-end">{{ $centavosIntereses($filaInteres['interes']) }}</td><td class="text-end">{{ $centavosIntereses($filaInteres['iva']) }}</td></tr>
                    @endforeach</tbody>
                </table></div>
            </details>
        @endif
    </div>
</div>
@endif
