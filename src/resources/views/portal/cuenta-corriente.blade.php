@extends('layouts.portal')
@section('title', 'Cuenta corriente')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Cuenta corriente</h1>
        <p class="text-muted mb-0">Movimientos de la cuenta asociada a tu perfil.</p>
    </div>
    <a href="{{ route('portal.inicio') }}" class="btn btn-outline-secondary">Volver</a>
</div>

<div class="alert alert-info" role="status">
    <strong>Consulta en preparación.</strong>
    Esta vista está habilitada únicamente para pruebas. Los importes y el saldo definitivo
    deberán conciliarse con COBOL antes de su publicación para clientes.
</div>

@if($diagnostico !== null)
    @php($dinero = static fn (int $centavos) => number_format($centavos / 100, 2, ',', '.'))
    <div class="card border-warning shadow-sm mb-4">
        <div class="card-header bg-warning-subtle"><strong>SIMULACIÓN INTERNA · CIERRE COBOL EN PRUEBA</strong></div>
        <div class="card-body">
            <p class="text-danger fw-bold">Importes orientativos. No constituyen deuda exigible ni liquidación válida. La conciliación con GIMB38 está pendiente.</p>
            <p class="small">Cuenta {{ $diagnostico['cuenta'] }} · Datos al {{ \Carbon\Carbon::parse($diagnostico['cierre'])->format('d/m/Y') }} · TIPOIVA {{ $diagnostico['tipo_iva'] }}.</p>
            <p class="small text-muted">Se supone que los movimientos pendientes al cierre siguen impagos hasta la fecha elegida. No se incluyen movimientos posteriores al cierre.</p>
            <form method="GET" action="{{ route('portal.cuenta-corriente.index') }}" class="d-flex flex-wrap align-items-end gap-2 mb-3">
                <input type="hidden" name="seleccion" value="{{ $seleccionada['rol'].'|'.$seleccionada['cuenta'] }}">
                <input type="hidden" name="periodo" value="{{ $periodo }}">
                <div><label for="fecha_calculo" class="form-label">Simular al día</label>
                    <input type="date" id="fecha_calculo" name="fecha_calculo" class="form-control"
                        min="{{ $diagnostico['cierre'] }}" value="{{ $fechaCalculo }}" required>
                </div>
                <button class="btn btn-warning" type="submit">Simular intereses</button>
            </form>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Movimiento</th><th>Concepto</th><th>Vence</th><th class="text-end">Capital pendiente</th><th class="text-end">Días</th><th class="text-end">Interés</th><th class="text-end">IVA interés</th></tr></thead>
                    <tbody>
                    @foreach($diagnostico['filas'] as $f)
                        <tr><td>{{ $f['codigo'].'/'.$f['numero'] }}</td><td>{{ $f['concepto'] }}</td><td>{{ ($f['vence'] ?? '-') !== '-'
    ? \Carbon\Carbon::parse($f['vence'])->format('d/m/Y')
    : '-' }}</td>
                            <td class="text-end">{{ $dinero($f['capital']) }}</td><td class="text-end">{{ $f['dias'] }}</td>
                            <td class="text-end">{{ $dinero($f['interes']) }}</td><td class="text-end">{{ $dinero($f['iva']) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="d-flex flex-wrap gap-3 fw-bold">
                <span>Debitos pendientes: $ {{ $dinero($diagnostico['capital']) }}</span>
                <span>Creditos sin aplicar: $ {{ $dinero($diagnostico['creditos']) }}</span>
                <span>Intereses: $ {{ $dinero($diagnostico['interes']) }}</span>
                <span>IVA intereses: $ {{ $dinero($diagnostico['iva']) }}</span>
                <span>Sobretasa: $ {{ $dinero($diagnostico['sobretasa']) }}</span>
                <span>Saldo neto simulado: $ {{ $dinero($diagnostico['total']) }}</span>
                @if($diagnostico['total'] < 0)
                    <span class="text-success">
                        Saldo a favor: $ {{ $dinero(-$diagnostico['total']) }}
                    </span>
                @endif
            </div>
        </div>
    </div>
@endif

@if($cuentas->isEmpty())
    <div class="card shadow-sm border-0"><div class="card-body p-4">
        No tenés cuentas corrientes asociadas a tu usuario. Si considerás que es un error, contactá a GeI.
    </div></div>
@else
    <form method="GET" action="{{ route('portal.cuenta-corriente.index') }}" class="card shadow-sm border-0 mb-4">
        <div class="card-body d-flex flex-wrap align-items-end gap-3">
            <div>
                <label for="seleccion" class="form-label">Cuenta</label>
                <select id="seleccion" name="seleccion" class="form-select" required>
                    @foreach($cuentas as $item)
                        <option value="{{ $item['rol'].'|'.$item['cuenta'] }}" @selected($seleccionada['cuenta'] === $item['cuenta'] && $seleccionada['rol'] === $item['rol'])>
                            {{ $item['cuenta'] }} — {{ $item['rol'] === 'INQUILINO' ? 'Inquilino' : 'Propietario' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="periodo" class="form-label">Período (AAAAMM)</label>
                <input id="periodo" name="periodo" class="form-control" inputmode="numeric"
                    value="{{ $periodo }}" maxlength="6" placeholder="Todos">
            </div>
            <button class="btn btn-primary" type="submit">Consultar</button>
        </div>
    </form>

    <div class="mb-3 text-muted small">
        @if($snapshot['fuente'] === 'CIERRE_PRUEBA')
            <strong class="text-danger">CIERRE PRUEBA {{ \Carbon\Carbon::parse($snapshot['cierre'])->format('d/m/Y') }} — NO PUBLICADO.</strong>
        @elseif($snapshot['fuente'] === 'CIERRE_PUBLICADO')
            Datos al cierre {{ \Illuminate\Support\Carbon::parse($snapshot['cierre'])->format('d/m/Y') }}.
        @else
            <strong>Último período histórico: {{ substr($snapshot['periodo_base'], 4, 2) }}/{{ substr($snapshot['periodo_base'], 0, 4) }}.</strong>
            Los cierres diarios con estado PRUEBA no se muestran.
        @endif
        Sede {{ $snapshot['sede'] }}. Solo lectura.
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Fecha</th><th>Código</th><th>Concepto</th>
                        <th class="text-end">Debe</th><th class="text-end">Haber</th>@if($saldosCoccte !== null)<th class="text-end">Saldo</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @if($saldosCoccte !== null && $movimientos->currentPage() === 1)
                        <tr class="table-secondary fw-semibold">
                            <td colspan="3">SALDOS ANTERIORES</td>
                            <td class="text-end">
                                {{ number_format($saldosCoccte['debitos_anteriores'] / 100, 2, ',', '.') }}
                            </td>
                            <td class="text-end">
                                {{ number_format($saldosCoccte['creditos_anteriores'] / 100, 2, ',', '.') }}
                            </td>
                            <td class="text-end">
                                {{ number_format(abs($saldosCoccte['saldo_anterior']) / 100, 2, ',', '.') }}{{ $saldosCoccte['saldo_anterior'] < 0 ? ' CR' : '' }}
                            </td>
                        </tr>
                    @endif
                    @forelse($movimientos as $movimiento)
                        <tr>
                            <td>{{ !empty($movimiento['fecha']) ? \Illuminate\Support\Carbon::parse($movimiento['fecha'])->format('d/m/Y') : '—' }}</td>
                            <td>{{ $movimiento['codigo'] }}</td>
                            <td>{{ $movimiento['descripcion'] ?: ('Movimiento '.$movimiento['numero']) }}</td>
                            <td class="text-end">{{ number_format((float) $movimiento['debe'], 2, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float) $movimiento['haber'], 2, ',', '.') }}</td>
                            @if($saldosCoccte !== null)
                                <td class="text-end fw-semibold">
                                    {{ number_format(abs($movimiento['saldo_centavos']) / 100, 2, ',', '.') }}{{ $movimiento['saldo_centavos'] < 0 ? ' CR' : '' }}
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $saldosCoccte !== null ? 6 : 5 }}" class="text-center text-muted py-4">No hay movimientos para la selección.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($saldosCoccte !== null)
            <div class="px-3 py-3 border-top text-end">
                <strong>Saldo contable del período:
                    {{ number_format(abs($saldosCoccte['saldo_actual']) / 100, 2, ',', '.') }}{{ $saldosCoccte['saldo_actual'] < 0 ? ' CR' : '' }}
                </strong>
                <div class="small text-muted">
                    Conciliación GIMB68 en prueba. No equivale a deuda exigible.
                </div>
            </div>
        @endif
        @if($movimientos->hasPages())
            <div class="card-footer bg-white">{{ $movimientos->links() }}</div>
        @endif
    </div>
    <p class="text-muted small mt-3">
        El saldo adeudado todavía no se publica: requiere validar aplicaciones de pagos,
        penalidades y reglas particulares del código COBOL.
    </p>
@endif
@endsection
