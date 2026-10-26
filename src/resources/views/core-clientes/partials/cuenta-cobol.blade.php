@php
    $monedaCobol = static fn ($v) =>
        number_format((float) $v, 2, ',', '.');

    $saldoCobol = static fn ($c) =>
        $c === null
            ? 'PENDIENTE'
            : number_format(abs($c) / 100, 2, ',', '.')
                .($c < 0 ? ' CR' : '');

    $fechaCobol = static function ($m): string {
        $x = (string) ($m['fecha_origen'] ?? '');

        if (preg_match('/^\d{8}$/D', $x)) {
            return substr($x, 6, 2).'/'
                .substr($x, 4, 2).'/'
                .substr($x, 0, 4);
        }

        return (string) ($m['fecha'] ?? '—');
    };
@endphp

@if(empty($cuentas))
    <div class="alert alert-warning m-3">
        No hay cuentas COBOL disponibles para este cliente
        en las sucursales autorizadas.
    </div>
@else
    @foreach($cuentas as $c)
        @php $s = $c['saldos']; @endphp

        <section class="border rounded mb-3">
            <div class="bg-light border-bottom p-2
                        d-flex justify-content-between flex-wrap gap-2">

                <strong>
                    {{ $c['rol'] }} ·
                    Cuenta {{ $c['cuenta'] }} ·
                    {{ $c['sede'] }}
                </strong>

                <span class="small">
                    Origen: {{ $c['fuente'] ?? 'Sin datos' }} ·
                    Cierre:
                    {{ $c['cierre']
                        ? \Carbon\Carbon::parse($c['cierre'])->format('d/m/Y')
                        : 'histórico' }}
                    · Período {{ substr($mes,4,2) }}/{{ substr($mes,0,4) }}
                </span>
            </div>

            @if($c['error'])
                <div class="alert alert-warning m-2">
                    {{ $c['error'] }}
                </div>
            @endif

            @if($c['rol'] === 'PROPIETARIO' || $c['sede'] === 'ST')
                <div class="small text-danger p-2">
                    Saldos provisorios: pendientes de comparación
                    con el programa COBOL de esta cuenta y sede.
                </div>
            @endif

            @if($s !== null)
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Fecha</th>
                                <th>Código</th>
                                <th>Comprobante</th>
                                <th>Concepto</th>
                                <th class="text-end">Debe</th>
                                <th class="text-end">Haber</th>
                                <th class="text-end">Saldo</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr class="table-secondary fw-semibold">
                                <td colspan="4">SALDOS ANTERIORES</td>

                                <td class="text-end">
                                    {{ $s['debitos_anteriores'] === null
                                        ? '—'
                                        : $monedaCobol($s['debitos_anteriores']/100) }}
                                </td>

                                <td class="text-end">
                                    {{ $s['creditos_anteriores'] === null
                                        ? '—'
                                        : $monedaCobol($s['creditos_anteriores']/100) }}
                                </td>

                                <td class="text-end">
                                    {{ $saldoCobol($s['saldo_anterior']) }}
                                </td>
                            </tr>

                            @forelse($s['movimientos'] as $m)
                                <tr>
                                    <td>{{ $fechaCobol($m) }}</td>

                                    <td>{{ $m['codigo'] ?? '—' }}</td>

                                    <td>{{ $m['numero'] ?? '—' }}</td>

                                    <td>{{ $m['descripcion'] ?? '—' }}</td>

                                    <td class="text-end">
                                        {{ $monedaCobol($m['debe'] ?? 0) }}
                                    </td>

                                    <td class="text-end">
                                        {{ $monedaCobol($m['haber'] ?? 0) }}
                                    </td>

                                    <td class="text-end fw-semibold">
                                        {{ $saldoCobol($m['saldo_centavos'] ?? null) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7"
                                        class="text-center text-muted">
                                        Sin movimientos en este mes.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>

                        <tfoot class="table-light fw-semibold">
                            <tr>
                                <td colspan="6" class="text-end">
                                    SALDO ACTUAL
                                </td>

                                <td class="text-end">
                                    {{ $saldoCobol($s['saldo_actual']) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>
    @endforeach

    <div class="text-muted small px-2 pb-2">
        Consulta administrativa de verificación.
        Los saldos contables no equivalen a deuda exigible.
        Los cierres en PRUEBA no están publicados para clientes.
    </div>
@endif
