@extends('layouts.app')

@section('title', 'Personas')
@section('page-title', 'Personas')

@section('content')
@php
    $pv = static function (?string $p): string {
        if (!$p || strlen($p) !== 6) return $p ?: '—';
        return substr($p, 4, 2).'/'.substr($p, 0, 4);
    };
    $valor = static fn ($v): string => ($v === null || trim((string)$v) === '') ? '—' : (string)$v;
@endphp

<div class="container-fluid py-3">
    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-1">Personas</h1>
            <div class="text-muted small">Padrón canónico de GeI-Core. Las cuentas COBOL son relaciones históricas, no identidades.</div>
        </div>
        <span class="badge text-bg-success">GeI-Core</span>
    </div>

    <div class="btn-group mb-3" role="group">
        @foreach (['PROPIETARIO' => 'Propietarios', 'INQUILINO' => 'Inquilinos', 'TODOS' => 'Todos'] as $k => $txt)
            <a class="btn btn-sm {{ $rol === $k ? 'btn-primary' : 'btn-outline-primary' }}"
               href="{{ route('personas.index', ['periodo'=>$periodo,'rol'=>$k,'estado'=>$estado,'buscar'=>$buscar]) }}">{{ $txt }}</a>
        @endforeach
    </div>

    <div class="row g-3 mb-3">
        @foreach ([
            ['Personas', $resumen['personas']],
            ['Activas', $resumen['activas']],
            ['Cuentas COBOL', $resumen['cuentas']],
            ['Cuentas activas', $resumen['cuentas_activas']],
            ['Con varias cuentas', $resumen['multiples_cuentas']],
        ] as [$t,$n])
            <div class="col-6 col-lg">
                <div class="card h-100 shadow-sm border-0"><div class="card-body py-3">
                    <div class="text-muted small">{{ $t }}</div>
                    <div class="fs-4 fw-semibold">{{ number_format($n,0,',','.') }}</div>
                </div></div>
            </div>
        @endforeach
    </div>

    <form method="GET" action="{{ route('personas.index') }}" class="card card-body shadow-sm border-0 mb-3">
        <input type="hidden" name="rol" value="{{ $rol }}">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small">Período</label>
                <select name="periodo" class="form-select">
                    @foreach ($periodos as $p)
                        <option value="{{ $p['periodo'] }}" @selected($periodo === $p['periodo'])>
                            {{ $pv($p['periodo']) }} · {{ $p['estado'] }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Estado</label>
                <select name="estado" class="form-select">
                    <option value="activos" @selected($estado==='activos')>Activos</option>
                    <option value="todos" @selected($estado==='todos')>Todos</option>
                </select>
            </div>
            <div class="col-md">
                <label class="form-label small">Buscar</label>
                <input name="buscar" value="{{ $buscar }}" class="form-control" placeholder="Nombre, CUIT/IVA, documento, domicilio o cuenta COBOL">
            </div>
            <div class="col-md-auto"><button class="btn btn-primary w-100">Buscar</button></div>
            <div class="col-md-auto"><a href="{{ route('personas.index',['rol'=>$rol,'periodo'=>$periodo]) }}" class="btn btn-outline-secondary w-100">Limpiar</a></div>
        </div>
    </form>

    <div class="row g-3">
        <div class="{{ $detalle ? 'col-xl-8' : 'col-12' }}">
            <div class="card shadow-sm border-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Nombre</th><th>CUIT / IVA</th><th>Documento</th><th>Domicilio</th>
                            <th>Cuentas COBOL</th><th>Estado</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($personas as $p)
                            @php
                                $url = route('personas.index', array_merge(request()->query(), ['seleccionar'=>$p->id]));
                            @endphp
                            <tr role="button" style="cursor:pointer" onclick="window.location.href=@js($url)">
                                <td class="fw-semibold">{{ $valor($p->nombre) }}</td>
                                <td>{{ $valor($p->nro_iva) }}</td>
                                <td>{{ $valor($p->nro_documento) }}</td>
                                <td>
                                    {{ $valor($p->domicilio) }}
                                    @if ($p->localidad)<div class="small text-muted">{{ $p->localidad }}</div>@endif
                                </td>
                                <td class="small">{{ $valor($p->cuentas_cobol) }}</td>
                                <td><span class="badge {{ $p->activo ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $p->activo ? 'Activo' : 'Histórico' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No se encontraron personas.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($personas->hasPages())<div class="card-footer bg-white">{{ $personas->onEachSide(1)->links() }}</div>@endif
            </div>
        </div>

        @if ($detalle)
        <div class="col-xl-4">
            <div class="card shadow-sm border-0 sticky-xl-top" style="top:1rem">
                <div class="card-body">
                    <div class="d-flex justify-content-between gap-2">
                        <h2 class="h5">{{ $valor($detalle['persona']->nombre) }}</h2>
                        <a class="btn-close" href="{{ route('personas.index', array_diff_key(request()->query(), ['seleccionar'=>1])) }}"></a>
                    </div>
                    <dl class="row small mb-3">
                        <dt class="col-4">CUIT/IVA</dt><dd class="col-8">{{ $valor($detalle['persona']->nro_iva) }}</dd>
                        <dt class="col-4">Documento</dt><dd class="col-8">{{ $valor($detalle['persona']->nro_documento) }}</dd>
                        <dt class="col-4">Domicilio</dt><dd class="col-8">{{ $valor($detalle['persona']->domicilio) }}</dd>
                        <dt class="col-4">Localidad</dt><dd class="col-8">{{ $valor($detalle['persona']->localidad) }}</dd>
                    </dl>

                    <h3 class="h6">Cuentas COBOL</h3>
                    <div class="table-responsive mb-3"><table class="table table-sm">
                        <thead><tr><th>Rol</th><th>Cuenta</th><th>Estado</th></tr></thead>
                        <tbody>
                        @foreach ($detalle['cuentas'] as $c)
                            <tr><td>{{ $c->rol }}</td><td>{{ $c->cuenta_cobol }}</td><td>{{ $c->activa ? 'Activa' : 'Histórica' }}</td></tr>
                        @endforeach
                        </tbody>
                    </table></div>

                    <h3 class="h6">Relaciones del período {{ $pv($periodo) }}</h3>
                    <div class="table-responsive"><table class="table table-sm">
                        <thead><tr><th>Rol</th><th>Inmueble</th><th>Cuenta inq.</th></tr></thead>
                        <tbody>
                        @forelse ($detalle['contratos'] as $c)
                            <tr>
                                <td>{{ $c->rol_contrato }}</td>
                                <td>{{ $valor($c->domicilio_actual) }}</td>
                                <td>{{ $c->cuenta_inquilino_cobol }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-muted">Sin contratos relacionados en este período.</td></tr>
                        @endforelse
                        </tbody>
                    </table></div>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
