@extends('layouts.app')

@section('title', 'Inmuebles · Maestro operativo')
@section('page-title', 'Inmuebles')

@section('content')
@php
    $cuentaCobol = static function ($cuenta): string {
        $digitos = preg_replace('/\D/', '', (string) $cuenta) ?: '';
        return strlen($digitos) === 11
            ? substr($digitos, 0, 4).'/'.substr($digitos, 4, 5).'/'.substr($digitos, 9, 2)
            : ((string) $cuenta ?: '—');
    };
    $fecha = static fn ($valor): string => $valor ? date('d/m/Y', strtotime((string) $valor)) : '—';
    $cuentasTexto = static function ($cuentas) use ($cuentaCobol): string {
        $filtradas = array_filter((array) $cuentas);
        return $filtradas === [] ? '—' : implode(', ', array_map($cuentaCobol, $filtradas));
    };
    $base = ['sede' => $sede, 'estado' => $estado, 'buscar' => $buscar];
@endphp

<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">Inmuebles · Maestro operativo</h1>
            <div class="text-muted small">Maestro operativo: public.inmuebles · Sin filtro por período · Solo lectura</div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge text-bg-primary">Maestro público · GeI-Web</span>
        </div>
    </div>

    @if($administradorGlobal && $sinSede > 0)
        <div class="alert alert-warning py-2">
            {{ number_format($sinSede, 0, ',', '.') }} inmuebles canónicos sin una sede inequívoca.
            Por seguridad, no se muestran a operadores hasta que se revise su sede.
            <a href="{{ route('inmuebles.maestro.index', [...$base, 'sede' => 'SIN_SEDE']) }}">Ver para revisar</a>.
        </div>
    @endif

    <div class="row g-2 mb-3">
        @foreach ([['Inmuebles', $resumen->total ?? 0], ['Activos', $resumen->activos ?? 0],
                   ['Inactivos', $resumen->inactivos ?? 0], ['Desconocidos', $resumen->desconocidos ?? 0]] as [$titulo, $numero])
            <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body py-2">
                <div class="small text-muted">{{ $titulo }}</div><div class="fs-5 fw-semibold">{{ number_format($numero, 0, ',', '.') }}</div>
            </div></div></div>
        @endforeach
    </div>

    <form action="{{ route('inmuebles.maestro.index') }}" method="GET" class="card border-0 shadow-sm card-body mb-3">
        @if($revision === 'duplicados')<input type="hidden" name="revision" value="duplicados">@endif
        <div class="row g-2 align-items-end">
            <div class="col-md-2"><label for="estado" class="form-label small">Estado</label>
                <select name="estado" id="estado" class="form-select">
                    @foreach (['ACTIVO'=>'Activos','INACTIVO'=>'Inactivos','DESCONOCIDO'=>'Desconocidos','TODOS'=>'Todos'] as $k=>$label)
                        <option value="{{ $k }}" @selected($estado===$k)>{{ $label }}</option>
                    @endforeach
                </select></div>
            <div class="col-md-2"><label for="sede" class="form-label small">Sede</label>
                @if(count($sedesPermitidas) === 1)
                    <input name="sede" type="hidden" value="{{ $sedesPermitidas[0] }}">
                    <div class="form-control bg-light">{{ $sedesPermitidas[0] }}</div>
                @else
                    <select name="sede" id="sede" class="form-select">
                        <option value="TODAS" @selected($sede==='TODAS')>Ambas</option>
                        @foreach($sedesPermitidas as $codigo)
                            <option value="{{ $codigo }}" @selected($sede===$codigo)>{{ $codigo }}</option>
                        @endforeach
                        @if($administradorGlobal)
                            <option value="SIN_SEDE" @selected($sede==='SIN_SEDE')>Sin sede · revisar</option>
                        @endif
                    </select>
                @endif
            </div>
            <div class="col-md"><label for="buscar" class="form-label small">Buscar</label>
                <input name="buscar" id="buscar" class="form-control" value="{{ $buscar }}" placeholder="Domicilio, partida, cuenta de propietario o inquilino">
            </div>
            <div class="col-md-auto"><button type="submit" class="btn btn-primary w-100">Buscar</button></div>
            <div class="col-md-auto"><a class="btn btn-outline-secondary w-100" href="{{ route('inmuebles.maestro.index') }}">Limpiar</a></div>
        </div>
    </form>

    <div class="row g-3">
        <div class="{{ $detalle ? 'col-xl-8' : 'col-12' }}">
            @if($revision === 'duplicados')
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <strong>Candidatos para consolidación de inmuebles</strong>
                        <div class="text-muted small">Solo se muestran domicilios coincidentes en la misma sede que no comparten una cuenta COBOL de propietario verificable en todas las fichas. Los registros con igual domicilio y cuenta se excluyen. No se realizan unificaciones.</div>
                    </div>
                    <div class="card-body">
                        @forelse($coincidencias as $grupo)
                            <div class="border rounded mb-2 p-2">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <div class="fw-semibold">{{ $grupo['domicilio'] }} <span class="text-muted small">· {{ $grupo['sede'] ?: 'Sede pendiente' }}</span></div>
                                    <span class="badge text-bg-light border">
                                        {{ $grupo['items']->count() }} registros ·
                                        {{ $grupo['items']->where('estado', 'ACTIVO')->count() }} activos ·
                                        {{ $grupo['items']->where('estado', 'INACTIVO')->count() }} inactivos
                                    </span>
                                </div>

                                @foreach($grupo['items'] as $candidato)
                                    <div class="d-flex justify-content-between gap-2 align-items-center border-top py-1 small">
                                        <div>#{{ $candidato->id }} · {{ $candidato->domicilio }}
                                            <span class="text-muted">({{ $candidato->sede_codigo ?: 'Revisar sede' }} · {{ $candidato->estado }})</span>
                                            <div class="text-muted">Cuenta(s) propietario: {{ $cuentasTexto($candidato->cuentas_propietario_revision ?? []) }}</div>
                                        </div>
                                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('inmuebles.maestro.index', [...$base, 'estado' => 'TODOS', 'revision' => 'duplicados', 'seleccionar' => $candidato->id]) }}">Ver ficha</a>
                                    </div>
                                @endforeach
                            </div>
                        @empty
                            <p class="text-muted mb-0">No hay domicilios con titularidad por cuenta pendiente de revisión en las sedes seleccionadas.</p>
                        @endforelse
                    </div>
                </div>
            @else
            <div class="card border-0 shadow-sm">
                <div class="table-responsive"><table class="table table-hover table-sm align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th>Finca</th><th>Sede</th><th>Cuenta propietario</th>
                        <th>Cuenta inquilino</th><th>Contratos</th><th>Estado</th><th></th>
                    </tr></thead>
                    <tbody>
                        @forelse($inmuebles as $i)
                            <tr @class(['table-secondary' => $detalle && $detalle->id === $i->id])>
                                <td><a class="text-decoration-none fw-semibold" href="{{ route('inmuebles.maestro.index', [...$base, 'seleccionar' => $i->id, 'page' => $inmuebles->currentPage()]) }}">{{ $i->domicilio }}</a>
                                    <span class="text-muted small">#{{ $i->id }}</span></td>
                                <td><span class="badge {{ $i->sede_codigo === 'SF' ? 'text-bg-primary' : 'text-bg-secondary' }}">{{ $i->sede_codigo ?: 'Revisar' }}</span></td>
                                <td class="small" style="overflow-wrap:anywhere">{{ $cuentasTexto($i->cuentas_propietario ?? []) }}</td>
                                <td class="small" style="overflow-wrap:anywhere">{{ $cuentasTexto($i->cuentas_inquilino ?? []) }}</td>
                                <td class="small">{{ $i->contratos_activos ?? 0 }} activos / {{ $i->contratos_totales ?? 0 }} presentes</td>
                                <td><span class="badge {{ $i->estado === 'ACTIVO' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $i->estado }}</span></td>
                                <td><a class="btn btn-sm btn-outline-secondary" href="{{ route('inmuebles.maestro.index', [...$base, 'seleccionar' => $i->id, 'page' => $inmuebles->currentPage()]) }}">Ver</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No hay inmuebles con estos filtros.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
                @if($inmuebles->hasPages())
                    <div class="card-footer bg-white">{{ $inmuebles->onEachSide(1)->links() }}</div>
                @endif
            </div>
            @endif
        </div>

        @if($detalle)
            <div class="col-xl-4">
                <div class="card border-0 shadow-sm" id="detalle-inmueble">
                    <div class="card-header bg-white d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <h2 class="h5 mb-1">{{ $detalle->domicilio }}</h2>
                            <span class="badge {{ $detalle->estado === 'ACTIVO' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $detalle->estado }}</span>
                        </div>
                        <a href="{{ route('inmuebles.maestro.index', [...$base, 'page' => $inmuebles->currentPage()]) }}" class="btn-close" aria-label="Cerrar detalle"></a>
                    </div>
                    <div class="card-body">
                        <div class="border rounded p-2 mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1"><strong>Sede</strong><span class="badge text-bg-primary">{{ $detalle->sede_codigo ?: 'Revisar' }}</span></div>
                            <div class="small text-muted">Determinada con datos de inmuebles, orígenes y contratos operativos.</div>
                            <div class="small mt-2">Código de origen: {{ $detalle->codigo_origen ?: '—' }}</div>
                            <div class="small text-muted">El cambio de sede permanece protegido; no se habilita edición en esta ficha.</div>
                        </div>

                        <h3 class="h6">Propietarios</h3>
                        @forelse($propietarios as $p)
                            <div class="border-bottom py-2 small">
                                <div class="fw-semibold">{{ $p->nombre }}</div>
                                <div>{{ $cuentaCobol($p->cuenta) }}</div>
                                @if($p->porcentaje !== null) <div>Participación {{ number_format((float) $p->porcentaje, 2, ',', '.') }} %</div> @endif
                                <div class="text-muted">{{ $p->fuente }}{{ $p->activo === null ? '' : ($p->activo ? ' · Activo' : ' · Histórico') }}</div>
                            </div>
                        @empty
                            <p class="small text-muted">Sin cuentas de propietario asociadas a esta sede.</p>
                        @endforelse

                        <h3 class="h6 mt-3">Partidas</h3>
                        @forelse($partidas as $p)
                            <div class="small">{{ $p->partida }} · {{ $p->activo ? 'Activa' : 'Histórica' }}</div>
                        @empty
                            <p class="small text-muted">Sin partidas registradas.</p>
                        @endforelse

                        <h3 class="h6 mt-3">Contratos del inmueble</h3>
                        @forelse($contratos as $c)
                            <div class="border-bottom py-2 small">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong>Contrato #{{ $c->id }}</strong>
                                    <span class="badge {{ $c->estado === 'ACTIVO' && $c->relacion_activa ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $c->estado }}</span>
                                </div>
                                <div class="mt-1"><strong>Inquilino:</strong> {{ $c->inquilino_nombre }}<br><span class="text-muted">{{ $cuentaCobol($c->cuenta_inquilino) }}</span></div>
                                <div class="mt-1"><strong>Propietario:</strong> {{ $c->propietario_nombre }}<br><span class="text-muted">{{ $cuentaCobol($c->cuenta_propietario) }}</span></div>
                                <div class="text-muted mt-1">{{ $fecha($c->fecha_inicio) }} – {{ $fecha($c->fecha_fin) }}</div>
                            </div>
                        @empty
                            <p class="small text-muted">Sin contratos asociados a esta sede.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
