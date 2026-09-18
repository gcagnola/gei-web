@extends('layouts.app')

@section('title', 'Clientes')
@section('page-title', 'Clientes')

@section('content')
@php
    $pv = static fn (?string $p): string => $p && strlen($p) === 6
        ? substr($p,4,2).'/'.substr($p,0,4)
        : ($p ?: '—');
    $v = static fn ($x): string => ($x === null || trim((string)$x) === '') ? '—' : (string)$x;

    $tipoGrupo = static function (string $tipo): array {
        return match ($tipo) {
            'EXACTA' => ['Coincidencia fuerte', 'text-bg-success', 'Mismo CUIT y mismo nombre normalizado.'],
            'CUIT' => ['CUIT coincidente', 'text-bg-warning', 'Comparten CUIT, pero los nombres no son idénticos. Revisar antes de asociar.'],
            'NOMBRE_INCOMPLETO' => ['Nombre coincidente', 'text-bg-info', 'Mismo nombre; al menos un registro tiene CUIT y otro no.'],
            'CONFLICTO' => ['Conflicto', 'text-bg-danger', 'Mismo nombre con CUIT diferentes. No asociar automáticamente.'],
            default => ['Nombre coincidente', 'text-bg-secondary', 'Mismo nombre sin evidencia fiscal suficiente.'],
        };
    };
@endphp

<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">Clientes</h1>
            <div class="text-muted small">
                Vista operativa de personas de GeI-Core. Propietarios e inquilinos comparten una única identidad.
            </div>
        </div>
        <span class="badge text-bg-success">GeI-Core</span>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <div class="btn-group">
            @foreach (['TODOS'=>'Todos','PROPIETARIO'=>'Propietarios','INQUILINO'=>'Inquilinos'] as $k=>$txt)
                <a class="btn btn-sm {{ $modo === 'clientes' && $rol === $k ? 'btn-primary' : 'btn-outline-primary' }}"
                   href="{{ route('core-clientes.index',['periodo'=>$periodo,'rol'=>$k,'estado'=>$estado,'buscar'=>$buscar,'modo'=>'clientes']) }}">{{ $txt }}</a>
            @endforeach
        </div>

        <a class="btn btn-sm {{ $modo === 'duplicados' ? 'btn-warning' : 'btn-outline-warning' }}"
           href="{{ route('core-clientes.index',['periodo'=>$periodo,'modo'=>'duplicados']) }}">
            Posibles duplicados
            <span class="badge {{ $modo === 'duplicados' ? 'text-bg-dark' : 'text-bg-warning' }} ms-1">
                {{ $duplicados['total_grupos'] }}
            </span>
        </a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body">
            <div class="small text-muted">Clientes del período</div>
            <div class="fs-4 fw-semibold">{{ number_format($resumen['personas'],0,',','.') }}</div>
        </div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body">
            <div class="small text-muted">Activos</div>
            <div class="fs-4 fw-semibold">{{ number_format($resumen['activas'],0,',','.') }}</div>
        </div></div></div>
        @if($modo === 'duplicados')
            <div class="col-sm-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body">
                <div class="small text-muted">Grupos candidatos</div>
                <div class="fs-4 fw-semibold">{{ number_format($duplicados['total_grupos'],0,',','.') }}</div>
            </div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body">
                <div class="small text-muted">Personas involucradas</div>
                <div class="fs-4 fw-semibold">{{ number_format($duplicados['total_personas'],0,',','.') }}</div>
            </div></div></div>
        @endif
    </div>

    <form class="card card-body border-0 shadow-sm mb-3" method="GET">
        <input type="hidden" name="rol" value="{{ $rol }}">
        <input type="hidden" name="modo" value="{{ $modo }}">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small">Período</label>
                <select name="periodo" class="form-select">
                    @foreach($periodos as $p)
                        <option value="{{ $p['periodo'] }}" @selected($periodo===$p['periodo'])>{{ $pv($p['periodo']) }} · {{ $p['estado'] }}</option>
                    @endforeach
                </select>
            </div>
            @if($modo === 'clientes')
                <div class="col-md-2">
                    <label class="form-label small">Estado</label>
                    <select name="estado" class="form-select">
                        <option value="activos" @selected($estado==='activos')>Activos</option>
                        <option value="todos" @selected($estado==='todos')>Todos</option>
                    </select>
                </div>
            @else
                <input type="hidden" name="estado" value="activos">
                <div class="col-md-2">
                    <label class="form-label small">Estado</label>
                    <input class="form-control" value="Sólo activos" disabled>
                </div>
            @endif
            <div class="col-md">
                <label class="form-label small">Buscar</label>
                <input class="form-control" name="buscar" value="{{ $buscar }}"
                       placeholder="{{ $modo === 'duplicados' ? 'Nombre, CUIT, documento, domicilio o cuenta COBOL dentro de candidatos' : 'Nombre, CUIT, documento, domicilio o cuenta COBOL' }}">
            </div>
            <div class="col-md-auto"><button class="btn btn-primary w-100">Buscar</button></div>
            <div class="col-md-auto">
                <a class="btn btn-outline-secondary w-100"
                   href="{{ route('core-clientes.index',['periodo'=>$periodo,'rol'=>$rol,'modo'=>$modo]) }}">Limpiar</a>
            </div>
        </div>
    </form>

    @if($modo === 'duplicados')
        <div class="alert alert-light border shadow-sm d-flex align-items-start gap-2 mb-3">
            <div class="fs-5">ℹ️</div>
            <div>
                <div class="fw-semibold">Revisión de posibles duplicados activos · {{ $pv($periodo) }}</div>
                <div class="small text-muted">
                    Esta vista es sólo de consulta. No modifica ni fusiona personas. Los grupos se detectan por CUIT repetido o por nombre normalizado repetido entre personas activas del período.
                </div>
            </div>
        </div>

        @forelse($duplicados['grupos'] as $i => $grupo)
            @php
                $presentacion = $tipoGrupo($grupo['tipo']);
            @endphp
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span class="fw-semibold">Grupo {{ $i + 1 }}</span>
                                <span class="badge {{ $presentacion[1] }}">{{ $presentacion[0] }}</span>
                                <span class="badge text-bg-light border">{{ count($grupo['personas']) }} personas</span>
                            </div>
                            <div class="small text-muted mt-1">{{ $presentacion[2] }}</div>
                        </div>
                        <div class="text-end small">
                            @if(isset($grupo['valores']['CUIT']))
                                <div><span class="text-muted">CUIT:</span> <strong>{{ $grupo['valores']['CUIT'] }}</strong></div>
                            @endif
                            @if(isset($grupo['valores']['NOMBRE']))
                                <div><span class="text-muted">Nombre normalizado:</span> {{ $grupo['valores']['NOMBRE'] }}</div>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th style="width:90px">ID</th>
                            <th>Cliente</th>
                            <th>CUIT / IVA</th>
                            <th>Documento</th>
                            <th>Rol(es)</th>
                            <th>Cuentas COBOL</th>
                            <th style="width:90px"></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($grupo['personas'] as $c)
                            <tr>
                                <td class="text-muted">#{{ $c->id }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $v($c->nombre) }}</div>
                                    @if($c->domicilio)
                                        <div class="small text-muted">
                                            {{ $c->domicilio }}{{ $c->localidad ? ' · '.$c->localidad : '' }}{{ $c->provincia ? ' · '.$c->provincia : '' }}
                                        </div>
                                    @endif
                                </td>
                                <td>{{ $v($c->nro_iva) }}</td>
                                <td>{{ $v($c->nro_documento) }}</td>
                                <td>{{ $v($c->roles) }}</td>
                                <td class="small">{{ $v($c->cuentas) }}</td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="{{ route('core-clientes.show',['persona'=>$c->id,'periodo'=>$periodo]) }}">Ver</a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center text-muted py-5">
                    No se encontraron posibles duplicados activos para este período{{ $buscar ? ' con el filtro indicado' : '' }}.
                </div>
            </div>
        @endforelse
    @else
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr><th>Cliente</th><th>CUIT / IVA</th><th>Documento</th><th>Rol(es)</th><th>Cuentas COBOL</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                    @forelse($clientes as $c)
                        <tr style="cursor:pointer" onclick="window.location.href=@js(route('core-clientes.show',['persona'=>$c->id,'periodo'=>$periodo]))">
                            <td>
                                <div class="fw-semibold">{{ $v($c->nombre) }}</div>
                                @if($c->domicilio)<div class="small text-muted">{{ $c->domicilio }}{{ $c->localidad ? ' · '.$c->localidad : '' }}</div>@endif
                            </td>
                            <td>{{ $v($c->nro_iva) }}</td>
                            <td>{{ $v($c->nro_documento) }}</td>
                            <td>{{ $v($c->roles) }}</td>
                            <td class="small">{{ $v($c->cuentas) }}</td>
                            <td><span class="badge {{ $c->activo ? 'text-bg-success':'text-bg-secondary' }}">{{ $c->activo ? 'Activo':'Histórico' }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No se encontraron clientes.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($clientes && $clientes->hasPages())<div class="card-footer bg-white">{{ $clientes->onEachSide(1)->links() }}</div>@endif
        </div>
    @endif
</div>
@endsection
