@extends('layouts.app')

@section('title', 'Cuenta corriente')
@section('page-title', 'Cuenta corriente')

@section('content')
@php
    $valor = static fn ($v): string => ($v === null || trim((string)$v) === '') ? '—' : (string)$v;
    $importe = static fn ($v): string => $v === null ? '—' : number_format((float)$v, 2, ',', '.');
    $fecha = static function ($v): string {
        $v = trim((string) ($v ?? ''));
        if ($v === '') return '—';

        // Fecha operativa ISO, con o sin hora.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T].*)?$/', $v, $m)) {
            return $m[3].'/'.$m[2].'/'.$m[1];
        }

        // AAAAMMDD
        if (preg_match('/^(19|20)\\d{6}$/', $v)) {
            return substr($v, 6, 2).'/'.substr($v, 4, 2).'/'.substr($v, 0, 4);
        }

        // DDMMAAAA (compatibilidad histórica)
        if (preg_match('/^\\d{4}(19|20)\\d{2}$/', $v)) {
            return substr($v, 0, 2).'/'.substr($v, 2, 2).'/'.substr($v, 4, 4);
        }

        return $v;
    };
@endphp

<div class="container-fluid py-3">
    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-1">Cuenta corriente</h1>
            <div class="text-muted small">Movimientos operativos de CTACTEPRO e INQCTACTE registrados en gei_db. La actividad mensual no altera el estado administrativo ni constituye una deuda exigible.</div>
        </div>
        <span class="badge text-bg-success">GeI-Web · Operativo</span>
    </div>

    <div class="btn-group mb-3">
        @foreach (['PROPIETARIO'=>'Propietarios','INQUILINO'=>'Inquilinos'] as $k=>$txt)
            <a href="{{ route('cuentas-corrientes.index',['tipo'=>$k,'estado'=>$estado,'buscar'=>$buscar,'periodo'=>$periodo]) }}"
               class="btn btn-sm {{ $tipo===$k ? 'btn-primary' : 'btn-outline-primary' }}">{{ $txt }}</a>
        @endforeach
    </div>

    <div class="row g-3 mb-3">
        @foreach ([['Cuentas',$resumen['cuentas']],['Con actividad en el período',$resumen['activas']],['Movimientos operativos',$resumen['movimientos']]] as [$t,$n])
        <div class="col-6 col-lg-4"><div class="card h-100 shadow-sm border-0"><div class="card-body py-3">
            <div class="text-muted small">{{ $t }}</div><div class="fs-4 fw-semibold">{{ number_format($n,0,',','.') }}</div>
        </div></div></div>
        @endforeach
    </div>

    <form method="GET" action="{{ route('cuentas-corrientes.index') }}" class="card card-body shadow-sm border-0 mb-3">
        <input type="hidden" name="tipo" value="{{ $tipo }}">
        {{-- GEI_ACTIVIDAD_CUENTAS_POR_PERIODO_20261030 --}}
        <div class="small text-muted mb-2">Actividad operativa en el período seleccionado: COBOL, ARCA, liquidaciones, impuestos o movimientos. No cambia la baja administrativa.</div>
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small">Período</label>
                <select name="periodo" class="form-select">
                    @foreach ($periodos as $p)
                        <option value="{{ $p }}" @selected($periodo === $p)>{{ substr($p, 4, 2) }}/{{ substr($p, 0, 4) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Estado</label>
                <select name="estado" class="form-select">
                    <option value="activas" @selected($estado==='activas')>Con actividad</option>
                    <option value="todas" @selected($estado==='todas')>Todas</option>
                </select>
            </div>
            <div class="col-md">
                <label class="form-label small">Buscar</label>
                <input name="buscar" value="{{ $buscar }}" class="form-control" placeholder="Cuenta, nombre, CUIT/IVA o documento">
            </div>
            <div class="col-md-auto"><button class="btn btn-primary w-100">Buscar</button></div>
            <div class="col-md-auto"><a href="{{ route('cuentas-corrientes.index',['tipo'=>$tipo,'periodo'=>$periodo]) }}" class="btn btn-outline-secondary w-100">Limpiar</a></div>
        </div>
    </form>

    <div class="row g-3">
        <div class="{{ $detalle ? 'col-xl-7' : 'col-12' }}">
            <div class="card shadow-sm border-0">
                <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Cuenta</th><th>Titular</th><th>Movimientos</th><th>Períodos</th><th>Actividad del período / estado administrativo</th></tr></thead>
                    <tbody>
                    @forelse ($cuentas as $c)
                        @php $url = route('cuentas-corrientes.index', array_merge(request()->query(), ['seleccionar'=>$c->id])); @endphp
                        <tr role="button" style="cursor:pointer" onclick="window.location.href=@js($url)">
                            <td class="fw-semibold">{{ $c->cuenta_cobol }}</td>
                            <td>
                                @if($c->cliente_canonico_id)
                                    <a onclick="event.stopPropagation()" class="text-decoration-none"
                                       href="{{ route('clientes-maestro.show', ['cliente'=>$c->cliente_canonico_id]) }}">{{ $valor($c->nombre) }}</a>
                                    <span class="text-muted small">#{{ $c->cliente_canonico_id }}</span>
                                    @if($c->nro_iva)<div class="small text-muted">{{ $c->nro_iva }}</div>@endif
                                @elseif((int) $c->cantidad_canonicos > 1)
                                    <span class="badge text-bg-warning">Vínculo ambiguo</span>
                                    <div class="small text-muted">Varios clientes canónicos: revisar (rol y cuenta)</div>
                                @else
                                    <span class="text-muted">Sin cliente canónico vinculado</span>
                                @endif
                            </td>
                            <td>{{ number_format($c->movimientos,0,',','.') }}</td>
                            <td class="small">{{ $c->primer_periodo ?: "—" }} → {{ $c->ultimo_periodo ?: "—" }}</td>
                            <td>
                                <span class="badge {{ $c->activa_periodo ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ $c->activa_periodo ? 'Con actividad' : 'Sin actividad' }} · {{ substr($periodo,4,2) }}/{{ substr($periodo,0,4) }}
                                </span>
                                <div class="text-muted small mt-1">Estado administrativo: {{ $c->activa === null ? 'Sin determinar' : ($c->activa ? 'Activa' : 'Inactiva') }}</div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No se encontraron cuentas.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
                @if ($cuentas->hasPages())<div class="card-footer bg-white">{{ $cuentas->onEachSide(1)->links() }}</div>@endif
            </div>
        </div>

        @if ($detalle)
        <div class="col-xl-5">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between gap-2">
                        <div>
                            <h2 class="h5 mb-1">Cuenta {{ $detalle['cuenta']->cuenta_cobol }}</h2>
                            <div class="text-muted">
                                @if($detalle['cuenta']->cliente_canonico_id)
                                    <a href="{{ route('clientes-maestro.show', ['cliente'=>$detalle['cuenta']->cliente_canonico_id]) }}">
                                        {{ $valor($detalle['cuenta']->nombre) }} · Cliente #{{ $detalle['cuenta']->cliente_canonico_id }}
                                    </a>
                                @elseif((int)$detalle['cuenta']->cantidad_canonicos > 1)
                                    <span class="badge text-bg-warning">Vínculo ambiguo: no se asigna titular</span>
                                @else
                                    <span>Sin cliente canónico vinculado</span>
                                @endif
                            </div>
                        </div>
                        <a class="btn-close" href="{{ route('cuentas-corrientes.index', array_diff_key(request()->query(), ['seleccionar'=>1])) }}"></a>
                    </div>
                    @if ($detalle['cuenta']->domicilio_actual)
                        <div class="alert alert-light border mt-3 mb-3 small"><strong>Inmueble:</strong> {{ $detalle['cuenta']->domicilio_actual }}</div>
                    @endif

                    <h3 class="h6 mt-3">Últimos movimientos operativos</h3>
                    <div class="table-responsive" style="max-height:65vh"><table class="table table-sm table-hover align-middle">
                        <thead class="table-light sticky-top"><tr>
                            <th>
                                @php
                                    $nuevoOrden = $orden === 'desc' ? 'asc' : 'desc';
                                    $urlOrden = route('cuentas-corrientes.index', array_merge(request()->query(), [
                                        'seleccionar' => $detalle['cuenta']->id,
                                        'orden' => $nuevoOrden,
                                    ]));
                                @endphp
                                <a href="{{ $urlOrden }}" class="text-decoration-none text-reset" title="Cambiar orden de fecha">
                                    Fecha
                                    <span class="ms-1">{{ $orden === 'desc' ? '↓' : '↑' }}</span>
                                </a>
                            </th>
                            <th>Cód.</th><th>Descripción</th><th class="text-end">Importe</th>
                        </tr></thead>
                        <tbody>
                        @forelse ($detalle['movimientos'] as $m)
                            <tr>
                                <td class="text-nowrap">{{ $fecha($m->fecha_original) }}</td>
                                <td>{{ $m->codigo }}<div class="small text-muted">{{ $m->numero }}</div></td>
                                <td>
                                    {{ $valor($m->descripcion) }}
                                    @if($m->liquidado)<div class="small text-muted">Liquidado: {{ $m->liquidado }}</div>@endif
                                    @if($m->fecha_vencimiento)<div class="small text-muted">Vence: {{ $fecha($m->fecha_vencimiento) }}</div>@endif
                                    @if($m->afecta_saldo === false)<div class="small text-muted">No afecta saldo</div>@endif
                                </td>
                                <td class="text-end text-nowrap">
                                    {{ $importe($m->importe) }}
                                    <div class="small text-muted">Debe {{ $importe($m->debe) }} · Haber {{ $importe($m->haber) }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">Sin movimientos.</td></tr>
                        @endforelse
                        </tbody>
                    </table></div>
                    <div class="small text-muted mt-2">Se muestran hasta 150 movimientos. El detalle proviene de gei_db; no recalcula saldos ni intereses COBOL. Para saldos conciliados usá la ficha de Clientes.</div>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
