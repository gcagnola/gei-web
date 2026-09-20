@php
    $moneyActividad = static fn ($x): string => $x === null ? '—' : '$ '.number_format((float)$x, 2, ',', '.');
    $fechaCobolActividad = static function ($x): string {
        $x = trim((string)($x ?? ''));
        if (preg_match('/^(19|20)\d{6}$/', $x)) return substr($x,6,2).'/'.substr($x,4,2).'/'.substr($x,0,4);
        if (preg_match('/^\d{4}(19|20)\d{2}$/', $x)) return substr($x,0,2).'/'.substr($x,2,2).'/'.substr($x,4,4);
        return $x ?: '—';
    };
@endphp

@if($tipo === 'cuenta-corriente')
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Fecha</th><th>Tipo</th><th>Cuenta</th><th>Cód.</th><th>Descripción</th><th class="text-end">Importe</th></tr></thead>
            <tbody>
            @forelse($data as $m)
                <tr>
                    <td>{{ $fechaCobolActividad($m->fecha_original) }}</td>
                    <td>{{ $m->tipo }}</td>
                    <td>{{ $m->cuenta_cobol }}</td>
                    <td>{{ $m->codigo }}<div class="small text-muted">{{ $m->numero }}</div></td>
                    <td>{{ ($m->descripcion === null || trim((string)$m->descripcion) === '') ? '—' : $m->descripcion }}</td>
                    <td class="text-end">{{ $moneyActividad($m->importe) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">Sin movimientos en este mes.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @php
        $incidenciasActividad = collect($incidencias ?? []);
        $incidenciasPorCuenta = $incidenciasActividad->groupBy('cuenta_cobol');
    @endphp

    @if($incidenciasActividad->isNotEmpty())
        <div class="border-top border-danger-subtle bg-danger-subtle p-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <div>
                    <div class="fw-semibold text-danger">
                        Fechas posteriores al período mostrado
                    </div>
                    <div class="small text-danger-emphasis">
                        Estas incidencias pertenecen a {{ substr($mes,4,2) }}/{{ substr($mes,0,4) }}
                        pero contienen fecha de movimiento o vencimiento en un mes posterior.
                        Son informativas y no bloquean la importación.
                    </div>
                </div>
                <span class="badge text-bg-danger">
                    {{ number_format($incidenciasActividad->count(), 0, ',', '.') }}
                </span>
            </div>

            @foreach($incidenciasPorCuenta as $cuentaCobol => $incidenciasCuenta)
                <details class="bg-white border rounded mb-2" @if($incidenciasPorCuenta->count() === 1) open @endif>
                    <summary class="px-3 py-2 fw-semibold" style="cursor:pointer">
                        Cuenta COBOL {{ $cuentaCobol }}
                        <span class="badge text-bg-danger ms-1">{{ $incidenciasCuenta->count() }}</span>
                    </summary>

                    <div class="table-responsive border-top">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Línea</th>
                                    <th>Fecha mov.</th>
                                    <th>Vencimiento</th>
                                    <th>Cód.</th>
                                    <th>Nº COBOL</th>
                                    <th>Incidencia</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($incidenciasCuenta as $i)
                                    <tr>
                                        <td>{{ number_format((int) $i->linea, 0, ',', '.') }}</td>
                                        <td>{{ $fechaCobolActividad($i->fecha_movimiento) }}</td>
                                        <td class="fw-semibold text-danger">
                                            {{ $fechaCobolActividad($i->fecha_vencimiento) }}
                                        </td>
                                        <td>{{ $i->codigo ?: '—' }}</td>
                                        <td>{{ $i->numero_cobol ?: '—' }}</td>
                                        <td>
                                            Vencimiento posterior al período
                                            ({{ substr($i->periodo_vencimiento,4,2) }}/{{ substr($i->periodo_vencimiento,0,4) }})
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endforeach
        </div>
    @endif
@elseif($tipo === 'liquidaciones')
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Período</th><th>Fecha</th><th>Cuenta</th><th>Nº</th><th>Comprobante</th><th class="text-end">Total</th><th>PDF</th></tr></thead>
            <tbody>
            @forelse($data as $l)
                <tr>
                    <td>{{ substr($l->periodo,4,2) }}/{{ substr($l->periodo,0,4) }}</td>
                    <td>{{ $l->fecha ? \Illuminate\Support\Carbon::parse($l->fecha)->format('d/m/Y') : '—' }}</td>
                    <td>{{ $l->cuenta_impresa ?: $l->cuenta }}</td>
                    <td>{{ $l->numero_interno }}</td>
                    <td>{{ $l->comprobante }}</td>
                    <td class="text-end">{{ $moneyActividad($l->total_final) }}</td>
                    <td>@if($l->pdf_disponible)<a target="_blank" href="{{ route('propietarios.liquidaciones.ver',$l->id) }}" class="btn btn-sm btn-outline-primary">Ver</a>@else—@endif</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">Sin liquidaciones en este mes.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@elseif($tipo === 'impuestos')
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Período</th><th>Cuenta</th><th>Liquidación</th><th>Propietario</th><th>PDF</th></tr></thead>
            <tbody>
            @forelse($data as $l)
                <tr>
                    <td>{{ substr($l->periodo,4,2) }}/{{ substr($l->periodo,0,4) }}</td>
                    <td>{{ $l->cuenta_impresa ?: $l->cuenta }}</td>
                    <td>{{ $l->numero_interno }}</td>
                    <td>{{ $l->propietario }}</td>
                    <td><a target="_blank" href="{{ route('propietarios.liquidaciones.impuestos.ver',$l->id) }}" class="btn btn-sm btn-outline-primary">Ver impuestos</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">Sin impuestos garantizados en este mes.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@elseif($tipo === 'facturas')
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Lote</th><th>Fecha</th><th>Comprobante</th><th>Cuenta COBOL</th><th>Sucursal</th><th class="text-end">Total</th><th>CAE</th><th>Vto. CAE</th></tr></thead>
            <tbody>
            @forelse($data as $f)
                <tr>
                    <td class="fw-semibold">{{ $f->lote }}</td>
                    <td>{{ $f->fecha ? \Illuminate\Support\Carbon::parse($f->fecha)->format('d/m/Y') : '—' }}</td>
                    <td>
                        @if($f->comprobante && $f->archivo_pdf)
                            <a target="_blank" href="{{ route('kng.facturas.pdf', ['lote' => $f->lote, 'archivo' => $f->archivo_pdf]) }}" class="text-decoration-none fw-semibold">{{ $f->comprobante }}</a>
                        @elseif($f->comprobante)
                            <span class="fw-semibold">{{ $f->comprobante }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>{{ $f->id_inq ?: $f->cta_orig ?: '—' }}</td>
                    <td>{{ ($f->detalle_lote === null || trim((string)$f->detalle_lote) === '') ? '—' : $f->detalle_lote }}</td>
                    <td class="text-end">{{ $moneyActividad($f->total) }}</td>
                    <td>{{ $f->cae ?: '—' }}</td>
                    <td>{{ $f->vto_cae ? \Illuminate\Support\Carbon::parse($f->vto_cae)->format('d/m/Y') : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">Sin facturas ARCA en este mes.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endif
