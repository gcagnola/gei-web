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
            <div class="text-muted small">Movimientos canónicos de CTACTEPRO e INQCTACTE. Los snapshots mensuales no duplican movimientos.</div>
        </div>
        <span class="badge text-bg-success">GeI-Core</span>
    </div>

    <div class="btn-group mb-3">
        @foreach (['PROPIETARIO'=>'Propietarios','INQUILINO'=>'Inquilinos'] as $k=>$txt)
            <a href="{{ route('cuentas-corrientes.index',['tipo'=>$k,'estado'=>$estado,'buscar'=>$buscar]) }}"
               class="btn btn-sm {{ $tipo===$k ? 'btn-primary' : 'btn-outline-primary' }}">{{ $txt }}</a>
        @endforeach
    </div>

    <div class="row g-3 mb-3">
        @foreach ([['Cuentas',$resumen['cuentas']],['Activas',$resumen['activas']],['Movimientos canónicos',$resumen['movimientos']]] as [$t,$n])
        <div class="col-6 col-lg-4"><div class="card h-100 shadow-sm border-0"><div class="card-body py-3">
            <div class="text-muted small">{{ $t }}</div><div class="fs-4 fw-semibold">{{ number_format($n,0,',','.') }}</div>
        </div></div></div>
        @endforeach
    </div>

    <form method="GET" action="{{ route('cuentas-corrientes.index') }}" class="card card-body shadow-sm border-0 mb-3">
        <input type="hidden" name="tipo" value="{{ $tipo }}">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small">Estado</label>
                <select name="estado" class="form-select">
                    <option value="activas" @selected($estado==='activas')>Activas</option>
                    <option value="todas" @selected($estado==='todas')>Todas</option>
                </select>
            </div>
            <div class="col-md">
                <label class="form-label small">Buscar</label>
                <input name="buscar" value="{{ $buscar }}" class="form-control" placeholder="Cuenta, nombre, CUIT/IVA o documento">
            </div>
            <div class="col-md-auto"><button class="btn btn-primary w-100">Buscar</button></div>
            <div class="col-md-auto"><a href="{{ route('cuentas-corrientes.index',['tipo'=>$tipo]) }}" class="btn btn-outline-secondary w-100">Limpiar</a></div>
        </div>
    </form>

    <div class="row g-3">
        <div class="{{ $detalle ? 'col-xl-7' : 'col-12' }}">
            <div class="card shadow-sm border-0">
                <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Cuenta</th><th>Titular</th><th>Movimientos</th><th>Períodos</th><th>Estado</th></tr></thead>
                    <tbody>
                    @forelse ($cuentas as $c)
                        @php $url = route('cuentas-corrientes.index', array_merge(request()->query(), ['seleccionar'=>$c->id])); @endphp
                        <tr role="button" style="cursor:pointer" onclick="window.location.href=@js($url)">
                            <td class="fw-semibold">{{ $c->cuenta_cobol }}</td>
                            <td>
                                {{ $valor($c->nombre) }}
                                @if($c->nro_iva)<div class="small text-muted">{{ $c->nro_iva }}</div>@endif
                            </td>
                            <td>{{ number_format($c->movimientos,0,',','.') }}</td>
                            <td class="small">{{ $c->primer_periodo }} → {{ $c->ultimo_periodo }}</td>
                            <td><span class="badge {{ $c->activa ? 'text-bg-success':'text-bg-secondary' }}">{{ $c->activa ? 'Activa':'Histórica' }}</span></td>
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
                            <div class="text-muted">{{ $valor($detalle['cuenta']->nombre) }}</div>
                        </div>
                        <a class="btn-close" href="{{ route('cuentas-corrientes.index', array_diff_key(request()->query(), ['seleccionar'=>1])) }}"></a>
                    </div>
                    @if ($detalle['cuenta']->domicilio_actual)
                        <div class="alert alert-light border mt-3 mb-3 small"><strong>Inmueble:</strong> {{ $detalle['cuenta']->domicilio_actual }}</div>
                    @endif

                    <h3 class="h6 mt-3">Últimos movimientos</h3>
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
                                </td>
                                <td class="text-end text-nowrap">{{ $importe($m->importe) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">Sin movimientos.</td></tr>
                        @endforelse
                        </tbody>
                    </table></div>
                    <div class="small text-muted mt-2">Se muestran hasta 150 movimientos. No se calcula saldo hasta trasladar y validar la semántica contable COBOL.</div>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
