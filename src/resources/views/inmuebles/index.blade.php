@extends('layouts.app')

@section('title', 'Inmuebles')
@section('page-title', 'Inmuebles')

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
            <h1 class="h4 mb-1">Inmuebles</h1>
            <div class="text-muted small">Fincas de GeI-Core reconstruidas desde contratos, dirección de finca y partidas. No desde el domicilio del propietario.</div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('inmuebles.index', ['periodo'=>$periodo, 'estado'=>'duplicados']) }}"
               class="btn btn-sm {{ ($duplicadosResumen['inmuebles_pendientes'] ?? 0) > 0 ? 'btn-warning' : 'btn-outline-secondary' }}">
                Posibles duplicados
                <span class="badge text-bg-dark ms-1">{{ $duplicadosResumen['inmuebles_pendientes'] ?? 0 }}</span>
            </a>
            <span class="badge text-bg-success">GeI-Core</span>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger py-2">{{ session('error') }}</div>
    @endif

    <div class="row g-3 mb-3">
        @foreach ([
            ['Inmuebles', $resumen['inmuebles']],
            ['Activos', $resumen['activos']],
            ['Contratos relacionados', $resumen['contratos']],
            ['Con partidas', $resumen['con_partidas']],
        ] as [$t,$n])
            <div class="col-6 col-lg-3">
                <div class="card h-100 shadow-sm border-0"><div class="card-body py-3">
                    <div class="text-muted small">{{ $t }}</div>
                    <div class="fs-4 fw-semibold">{{ number_format($n,0,',','.') }}</div>
                </div></div>
            </div>
        @endforeach
    </div>

    <form method="GET" action="{{ route('inmuebles.index') }}" class="card card-body shadow-sm border-0 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small">Período</label>
                <select name="periodo" class="form-select" onchange="this.form.submit()">
                    @foreach ($periodos as $p)
                        <option value="{{ $p['periodo'] }}" @selected($periodo === $p['periodo'])>{{ $pv($p['periodo']) }} · {{ $p['estado'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Estado</label>
                <select name="estado" class="form-select" onchange="this.form.submit()">
                    <option value="activos" @selected($estado==='activos')>Activos</option>
                    <option value="todos" @selected($estado==='todos')>Todos</option>
                    <option value="duplicados" @selected($estado==='duplicados')>Posibles duplicados</option>
                </select>
            </div>
            <div class="col-md">
                <label class="form-label small">Buscar</label>
                <input name="buscar" value="{{ $buscar }}" class="form-control" placeholder="Dirección, partida, cuenta de propietario o inquilino">
            </div>
            <div class="col-md-auto"><button class="btn btn-primary w-100">Buscar</button></div>
            <div class="col-md-auto"><a href="{{ route('inmuebles.index',['periodo'=>$periodo]) }}" class="btn btn-outline-secondary w-100">Limpiar</a></div>
        </div>
    </form>

    <div class="row g-3">
        <div class="{{ $detalle ? 'col-xl-8' : 'col-12' }}">
            @if ($modoDuplicados)
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <div>
                        <h2 class="h6 mb-1">Posibles inmuebles duplicados activos</h2>
                        <div class="small text-muted">Período {{ $pv($periodo) }} · coincidencias por domicilio comparable. Es una sugerencia de revisión: no modifica ni unifica datos.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <span class="badge text-bg-warning">{{ $duplicadosResumen['inmuebles_pendientes'] ?? 0 }} inmueble(s) pendiente(s)</span>
                        <span class="badge text-bg-secondary">{{ count($duplicados) }} grupo(s)</span>
                    </div>
                </div>

                @forelse ($duplicados as $grupo)
                    <div class="card shadow-sm border-0 mb-3">
                        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-semibold">{{ $grupo['firma'] }}</div>
                                <div class="small text-muted">{{ $grupo['motivo'] }}</div>
                            </div>
                            <div class="d-flex gap-2">
                                <span class="badge {{ $grupo['confianza'] === 'ALTA' ? 'text-bg-success' : 'text-bg-warning' }}">
                                    {{ $grupo['confianza'] === 'ALTA' ? 'Coincidencia fuerte' : 'Revisar' }}
                                </span>
                                @if($grupo['resuelto'])
                                    <span class="badge text-bg-success">Grupo revisado</span>
                                @else
                                    <span class="badge text-bg-danger">{{ $grupo['pendientes'] }} pendiente(s)</span>
                                @endif
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:90px">ID</th>
                                        <th>Finca COBOL</th>
                                        <th>Cuenta propietario</th>
                                        <th>Cuenta inquilino</th>
                                        <th>Partidas</th>
                                        <th>Contratos</th>
                                        <th style="width:250px">Revisión</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach ($grupo['items'] as $i)
                                    @php
                                        $url = route('inmuebles.index', array_merge(request()->query(), ['seleccionar'=>$i->id]));
                                    @endphp
                                    <tr>
                                        <td class="text-muted">#{{ $i->id }}</td>
                                        <td class="fw-semibold">{{ $valor($i->domicilio_actual) }}</td>
                                        <td class="small">{{ $valor($i->cuentas_propietario) }}</td>
                                        <td class="small">{{ $valor($i->cuentas_inquilino) }}</td>
                                        <td class="small">{{ $valor($i->partidas) }}</td>
                                        <td>{{ $i->contratos_activos }} activos / {{ $i->cantidad_contratos }} presentes</td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1 align-items-center">
                                                <a href="{{ $url }}" class="btn btn-sm btn-outline-primary">Ver</a>
                                                @if($i->revision_validado)
                                                    <span class="badge text-bg-success">✓ Único validado</span>
                                                    <form method="POST" action="{{ route('inmuebles.validacion-unico.destroy', ['inmueble'=>$i->id]) }}" class="d-inline">
                                                        @csrf
                                                        @method('DELETE')
                                                        <input type="hidden" name="periodo" value="{{ $periodo }}">
                                                        <input type="hidden" name="buscar" value="{{ $buscar }}">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Deshacer</button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route('inmuebles.validar-unico', ['inmueble'=>$i->id]) }}" class="d-inline">
                                                        @csrf
                                                        <input type="hidden" name="periodo" value="{{ $periodo }}">
                                                        <input type="hidden" name="buscar" value="{{ $buscar }}">
                                                        <button type="submit" class="btn btn-sm btn-outline-success"
                                                                onclick="return confirm('¿Validar el inmueble #{{ $i->id }} como inmueble único frente a los candidatos actuales?')">
                                                            Validar único
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @empty
                    <div class="card shadow-sm border-0">
                        <div class="card-body text-center text-muted py-5">
                            No se encontraron posibles duplicados entre los inmuebles activos del período.
                        </div>
                    </div>
                @endforelse
            @else
                <div class="card shadow-sm border-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr>
                                <th>Finca</th><th>Cuenta propietario</th><th>Cuenta inquilino</th><th>Contratos</th><th>Estado</th>
                            </tr></thead>
                            <tbody>
                            @forelse ($inmuebles as $i)
                                @php $url = route('inmuebles.index', array_merge(request()->query(), ['seleccionar'=>$i->id])); @endphp
                                <tr role="button" style="cursor:pointer" onclick="window.location.href=@js($url)">
                                    <td class="fw-semibold">{{ $valor($i->domicilio_actual) }}</td>
                                    <td class="small">{{ $valor($i->cuentas_propietario) }}</td>
                                    <td class="small">{{ $valor($i->cuentas_inquilino) }}</td>
                                    <td>{{ $i->contratos_activos }} activos / {{ $i->cantidad_contratos }} presentes</td>
                                    <td><span class="badge {{ $i->activo ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $i->activo ? 'Activo' : 'Histórico' }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-4">No se encontraron inmuebles.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if ($inmuebles->hasPages())<div class="card-footer bg-white">{{ $inmuebles->onEachSide(1)->links() }}</div>@endif
                </div>
            @endif
        </div>

        @if ($detalle)
        <div class="col-xl-4">
            <div class="card shadow-sm border-0 sticky-xl-top" style="top:1rem">
                <div class="card-body">
                    <div class="d-flex justify-content-between gap-2">
                        <h2 class="h5">{{ $valor($detalle['inmueble']->domicilio_actual) }}</h2>
                        <a class="btn-close" href="{{ route('inmuebles.index', array_diff_key(request()->query(), ['seleccionar'=>1])) }}"></a>
                    </div>

                    <div class="mb-3">
                        <span class="badge {{ $detalle['inmueble']->activo_periodo ? 'text-bg-success' : 'text-bg-secondary' }}">
                            {{ $detalle['inmueble']->activo_periodo ? 'Activo' : 'Histórico' }}
                        </span>
                    </div>

                    <h3 class="h6">Partidas</h3>
                    @if ($detalle['partidas'])
                        <div class="d-flex flex-wrap gap-1 mb-3">
                            @foreach ($detalle['partidas'] as $p)<span class="badge text-bg-light border">{{ $p->partida }}</span>@endforeach
                        </div>
                    @else
                        <p class="small text-muted">Sin partidas informadas.</p>
                    @endif

                    <h3 class="h6">Contratos del período</h3>
                    <div class="table-responsive"><table class="table table-sm align-middle">
                        <thead><tr><th>Inquilino</th><th>Propietario</th><th>Estado</th></tr></thead>
                        <tbody>
                        @forelse ($detalle['contratos'] as $c)
                            <tr>
                                <td>
                                    {{ $valor($c->inquilino_nombre) }}
                                    <div class="small text-muted">{{ $c->cuenta_inquilino_cobol }}</div>
                                </td>
                                <td>
                                    {{ $valor($c->propietario_nombre) }}
                                    <div class="small text-muted">{{ $valor($c->cuenta_propietario_cobol) }}</div>
                                </td>
                                <td>{{ $c->activo ? 'Activo' : 'Histórico' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-muted">Sin contratos.</td></tr>
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
