@extends('layouts.app')

@section('title', 'Cliente')
@section('page-title', 'Cliente')

@section('content')
@php
    $p = $detalle['persona'];
    $v = static fn ($x): string => ($x === null || trim((string)$x) === '') ? '—' : (string)$x;
    $money = static fn ($x): string => $x === null ? '—' : '$ '.number_format((float)$x,2,',','.');
    $campo = static fn ($obj, string $nombre) => data_get($obj, $nombre);
    $destinos = [
        '001' => 'Viviendas',
        '002' => 'Cocheras',
        '006' => 'Oficinas públicas',
        '007' => 'Entidades de bien público',
        '011' => 'Oficinas profesionales / inmobiliarias / comisionistas',
        '012' => 'Oficinas administrativas',
        '031' => 'Oficios / talleres en general',
        '040' => 'Comercios pequeños',
        '041' => 'Comercio gastronómico',
        '042' => 'Bares y confiterías',
        '043' => 'Roperías',
        '044' => 'Electrodomésticos',
        '045' => 'Kioscos / juegos',
        '046' => 'Turismo / agencias minoristas',
        '047' => 'Objetos suntuarios',
        '048' => 'Artículos deportivos / óptica',
        '049' => 'Comestibles',
        '050' => 'Regalos / flores / juguetes',
        '051' => 'Perfumerías / farmacias',
        '052' => 'Artículos de cuero',
        '053' => 'Mueblerías y afines',
        '054' => 'Imprentas',
        '055' => 'Actividades recreativas',
        '056' => 'Otros comercios pequeños',
        '060' => 'Comercios medianos',
        '061' => 'Clínicas y sanatorios',
        '062' => 'Alojamientos',
        '063' => 'Materiales de construcción',
        '064' => 'Tiendas',
        '065' => 'Empresas de transporte',
        '066' => 'Finanzas / seguros / valores / turismo mayorista',
        '067' => 'Automotores',
        '068' => 'Otros comercios medianos',
        '071' => 'Depósitos sin fines de lucro',
        '072' => 'Depósitos con fines de lucro',
        '075' => 'Terrenos baldíos',
        '081' => 'Locación mixta, predominio vivienda',
        '082' => 'Locación mixta, predominio otros destinos',
        '091' => 'Quintas / explotación',
        '092' => 'Casa-quinta / fines de semana',
    ];
    $destinoDescripcion = static function ($codigo) use ($destinos): string {
        $raw = trim((string)($codigo ?? ''));
        if ($raw === '') return '—';
        $normalizado = str_pad((string)((int)$raw), 3, '0', STR_PAD_LEFT);
        return isset($destinos[$normalizado])
            ? $normalizado.' · '.$destinos[$normalizado]
            : $normalizado.' · Destino no catalogado';
    };

    $camposComparacionContrato = [
        'activo','marca_baja',
        'fecha_contrato_original','fecha_vencimiento_original',
        'fecha_primer_ajuste_original','fecha_inicio_locacion_original',
        'fecha_celebracion_original','fecha_baja_original',
        'nro_liquidacion_original','marca_intimacion_original',
        'plazo_meses','plazo_dias','indice','tipo_ajuste',
        'cuota_1','cuota_2','alquiler_inicial','cuota_2_dolar',
        'destino','administracion_responsable',
        'penal_porcentaje','penal_importe',
        'comision_anterior','comision_importe',
        'reparacion','dias_reparacion','acumulado_penalidad',
        'fecha_juicio_original','abogado',
        'ajuste_1_fecha','ajuste_1_porcentaje',
        'ajuste_2_fecha','ajuste_2_porcentaje',
        'ajuste_3_fecha','ajuste_3_porcentaje',
        'ajuste_4_fecha','ajuste_4_porcentaje',
        'ajuste_5_fecha','ajuste_5_porcentaje',
        'ajuste_6_fecha','ajuste_6_porcentaje',
        'ajuste_7_fecha','ajuste_7_porcentaje',
        'ajuste_8_fecha','ajuste_8_porcentaje',
    ];
    $fechaCobol = static function ($x): string {
        $x = trim((string)($x ?? ''));
        if (preg_match('/^(19|20)\d{6}$/',$x)) return substr($x,6,2).'/'.substr($x,4,2).'/'.substr($x,0,4);
        if (preg_match('/^\d{4}(19|20)\d{2}$/',$x)) return substr($x,0,2).'/'.substr($x,2,2).'/'.substr($x,4,4);
        return $x ?: '—';
    };
    $tabs = [
        'datos' => 'Datos',
        'inmuebles' => 'Inmuebles / Contratos',
        'cuenta-corriente' => 'Cuenta corriente',
        'liquidaciones' => 'Liquidaciones',
        'impuestos' => 'Impuestos garantizados',
        'facturas' => 'Facturas ARCA',
    ];
@endphp

<div class="container-fluid py-3">
    @if(session('estado'))
        <div class="alert alert-success py-2">{{ session('estado') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger py-2">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <a href="{{ route('core-clientes.index',['periodo'=>$periodo]) }}" class="small text-decoration-none">&larr; Volver a Clientes</a>
            <h1 class="h4 mb-1 mt-1">{{ $v($p->nombre) }}</h1>
            <div class="text-muted">
                @foreach($detalle['roles'] as $r)
                    <span class="badge {{ $r->activo ? 'text-bg-success':'text-bg-secondary' }}">{{ $r->rol }}</span>
                @endforeach
            </div>
        </div>
        <form method="GET">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <select class="form-select" name="periodo" onchange="this.form.submit()">
                @foreach($periodos as $per)
                    <option value="{{ $per['periodo'] }}" @selected($periodo===$per['periodo'])>{{ substr($per['periodo'],4,2) }}/{{ substr($per['periodo'],0,4) }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <ul class="nav nav-tabs flex-nowrap overflow-auto mb-3">
        @foreach($tabs as $k=>$txt)
            <li class="nav-item">
                <a class="nav-link text-nowrap {{ $tab===$k ? 'active':'' }}"
                   href="{{ route('core-clientes.show',['persona'=>$p->id,'periodo'=>$periodo,'tab'=>$k]) }}">{{ $txt }}</a>
            </li>
        @endforeach
    </ul>

    @if($tab === 'datos')
        <div class="row g-3">
            <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
                <h2 class="h6">Identidad</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Nombre</dt><dd class="col-sm-8">{{ $v($p->nombre) }}</dd>
                    <dt class="col-sm-4">CUIT / IVA</dt><dd class="col-sm-8">{{ $v($p->nro_iva) }}</dd>
                    <dt class="col-sm-4">Documento</dt><dd class="col-sm-8">{{ $v($p->nro_documento) }}</dd>
                    <dt class="col-sm-4">Domicilio</dt><dd class="col-sm-8">{{ $v($p->domicilio) }}</dd>
                    <dt class="col-sm-4">Localidad</dt><dd class="col-sm-8">{{ $v($p->localidad) }}</dd>
                    <dt class="col-sm-4">Provincia</dt><dd class="col-sm-8">{{ $v($p->provincia) }}</dd>
                    <dt class="col-sm-4">Teléfono</dt><dd class="col-sm-8">{{ $v($p->telefono_1) }}</dd>
                    <dt class="col-sm-4">Email</dt>
                    <dd class="col-sm-8">
                        <form method="POST" action="{{ route('core-clientes.email.update', ['persona' => $p->id]) }}" class="d-flex flex-wrap gap-2 align-items-start">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="periodo" value="{{ $periodo }}">
                            <input
                                type="email"
                                name="email"
                                value="{{ old('email', $p->email ?? '') }}"
                                class="form-control form-control-sm @error('email') is-invalid @enderror"
                                maxlength="180"
                                placeholder="cliente@ejemplo.com"
                                autocomplete="email"
                                style="max-width: 360px"
                            >
                            <button type="submit" class="btn btn-sm btn-outline-primary">Guardar email</button>
                        </form>
                        <div class="form-text">Es el único dato del cliente habilitado para modificación por ahora.</div>
                    </dd>
                </dl>
            </div></div></div>

            <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
                <h2 class="h6">Cuentas COBOL</h2>
                <div class="table-responsive"><table class="table table-sm mb-0">
                    <thead><tr><th>Rol</th><th>Cuenta</th><th>Períodos</th><th>Estado</th></tr></thead>
                    <tbody>
                    @foreach($detalle['cuentas'] as $c)
                        <tr>
                            <td>{{ $c->rol }}</td><td class="fw-semibold">{{ $c->cuenta_cobol }}</td>
                            <td>{{ $c->primer_periodo }} → {{ $c->ultimo_periodo }}</td>
                            <td>{{ $c->activa ? 'Activa':'Histórica' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            </div></div></div>
        </div>

    @elseif($tab === 'inmuebles')
        <div class="card border-0 shadow-sm">
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Inmueble</th><th>Partidas</th><th>Inquilino</th><th>Propietario</th><th>Contrato</th><th>Estado</th></tr></thead>
                <tbody>
                @forelse($detalle['contratos'] as $c)
                    <tr>
                        <td class="fw-semibold">{{ $v($c->domicilio_actual) }}</td>
                        <td class="small">
                            {{ collect($detalle['partidasPorInmueble']->get($c->inmueble_id, collect()))->pluck('partida')->implode(' · ') ?: '—' }}
                        </td>
                        <td>{{ $v($c->inquilino_nombre) }}<div class="small text-muted">{{ $v($c->cuenta_inquilino_cobol) }}</div></td>
                        <td>{{ $v($c->propietario_nombre) }}<div class="small text-muted">{{ $v($c->cuenta_propietario_cobol) }}</div></td>
                        <td class="small" style="min-width:260px">
                            <div><strong>Contrato:</strong> {{ $fechaCobol($c->fecha_contrato_original) }} → {{ $fechaCobol($c->fecha_vencimiento_original) }}</div>
                            <div><strong>Plazo:</strong> {{ $v($c->plazo_meses) }} mes(es)@if($c->plazo_dias) + {{ $c->plazo_dias }} día(s)@endif</div>
                            <details class="mt-2">
                                <summary class="text-primary fw-semibold" style="cursor:pointer">Ver ficha completa del contrato</summary>
                                <div class="border rounded p-3 mt-2 bg-white shadow-sm">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                        <div>
                                            <div class="text-muted small">Contrato de locación</div>
                                            <div class="fw-semibold">{{ $v($c->inquilino_nombre) }} · Cuenta {{ $v($c->cuenta_inquilino_cobol) }}</div>
                                        </div>
                                        <span class="badge {{ $c->activo ? 'text-bg-success':'text-bg-secondary' }}">
                                            {{ $c->activo ? 'ACTIVO':'HISTÓRICO' }}
                                        </span>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-12">
                                            <div class="card border shadow-none">
                                                <div class="card-header bg-light fw-semibold">Datos del contrato</div>
                                                <div class="card-body">
                                                    <div class="row g-3 small">
                                                        <div class="col-md-3"><div class="text-muted">Fecha contrato</div><div class="fw-semibold">{{ $fechaCobol($campo($c,'fecha_contrato_original')) }}</div></div>
                                                        <div class="col-md-3"><div class="text-muted">Vencimiento</div><div class="fw-semibold">{{ $fechaCobol($campo($c,'fecha_vencimiento_original')) }}</div></div>
                                                        <div class="col-md-3"><div class="text-muted">Fecha celebración</div><div class="fw-semibold">{{ $fechaCobol($campo($c,'fecha_celebracion_original')) }}</div></div>
                                                        <div class="col-md-3"><div class="text-muted">Inicio de la locación</div><div class="fw-semibold">{{ $fechaCobol($campo($c,'fecha_inicio_locacion_original')) }}</div></div>

                                                        <div class="col-md-3"><div class="text-muted">Plazo</div><div class="fw-semibold">{{ $v($campo($c,'plazo_meses')) }} mes(es)@if($campo($c,'plazo_dias')) + {{ $campo($c,'plazo_dias') }} día(s)@endif</div></div>
                                                        <div class="col-md-3"><div class="text-muted">Destino</div><div class="fw-semibold">{{ $destinoDescripcion($campo($c,'destino')) }}</div></div>
                                                        <div class="col-md-3"><div class="text-muted">Nro. liquidación</div><div class="fw-semibold">{{ $v($campo($c,'nro_liquidacion_original')) }}</div></div>
                                                        <div class="col-md-3"><div class="text-muted">Intimación</div><div class="fw-semibold">{{ $v($campo($c,'marca_intimacion_original')) }}</div></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-lg-6">
                                            <div class="card border shadow-none h-100">
                                                <div class="card-header bg-light fw-semibold">Alquiler y ajustes</div>
                                                <div class="card-body">
                                                    <div class="row g-3 small">
                                                        <div class="col-6"><div class="text-muted">Primer ajuste</div><div class="fw-semibold">{{ $fechaCobol($campo($c,'fecha_primer_ajuste_original')) }}</div></div>
                                                        <div class="col-6"><div class="text-muted">Índice / tipo</div><div class="fw-semibold">{{ $v($campo($c,'indice')) }} / {{ $v($campo($c,'tipo_ajuste')) }}</div></div>
                                                        <div class="col-6"><div class="text-muted">Cuota 1</div><div class="fw-semibold">{{ $money($campo($c,'cuota_1')) }}</div></div>
                                                        <div class="col-6"><div class="text-muted">Cuota 2</div><div class="fw-semibold">{{ $money($campo($c,'cuota_2')) }}</div></div>
                                                        <div class="col-6"><div class="text-muted">Alquiler inicial</div><div class="fw-semibold">{{ $money($campo($c,'alquiler_inicial')) }}</div></div>
                                                        <div class="col-6"><div class="text-muted">Cuota 2 dólar</div><div class="fw-semibold">{{ $money($campo($c,'cuota_2_dolar')) }}</div></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-lg-6">
                                            <div class="card border shadow-none h-100">
                                                <div class="card-header bg-light fw-semibold">Penalidades y administración</div>
                                                <div class="card-body">
                                                    <div class="row g-3 small">
                                                        <div class="col-6"><div class="text-muted">Cláusula penal</div><div class="fw-semibold">{{ $v($campo($c,'penal_porcentaje')) }} %</div></div>
                                                        <div class="col-6"><div class="text-muted">Importe penal</div><div class="fw-semibold">{{ $money($campo($c,'penal_importe')) }}</div></div>
                                                        <div class="col-6"><div class="text-muted">Comisión impuestos</div><div class="fw-semibold">{{ $money($campo($c,'comision_importe')) }}</div></div>
                                                        <div class="col-6"><div class="text-muted">Acumulado penalidad</div><div class="fw-semibold">{{ $money($campo($c,'acumulado_penalidad')) }}</div></div>
                                                        <div class="col-12"><div class="text-muted">Administración responsable</div><div class="fw-semibold">{{ $v($campo($c,'administracion_responsable')) }}</div></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        @php
                                            $reparacionValor = strtoupper(trim((string) $campo($c,'reparacion')));
                                            $tieneDatosReparaciones =
                                                ($reparacionValor !== '' && $reparacionValor !== 'N')
                                                || filled($campo($c,'dias_reparacion'))
                                                || filled($campo($c,'fecha_juicio_original'))
                                                || filled($campo($c,'abogado'));
                                        @endphp
                                        <div class="col-lg-6">
                                            <details class="card border shadow-none h-100" @if($reparacionValor !== 'N') open @endif>
                                                <summary class="card-header bg-light fw-semibold" style="cursor:pointer">
                                                    Reparaciones y legales
                                                </summary>
                                                <div class="card-body">
                                                    <div class="row g-3 small">
                                                        <div class="col-6"><div class="text-muted">Reparación</div><div class="fw-semibold">{{ $v($campo($c,'reparacion')) }}</div></div>
                                                        <div class="col-6"><div class="text-muted">Días reparación</div><div class="fw-semibold">{{ $v($campo($c,'dias_reparacion')) }}</div></div>
                                                        <div class="col-6"><div class="text-muted">Fecha juicio</div><div class="fw-semibold">{{ $fechaCobol($campo($c,'fecha_juicio_original')) }}</div></div>
                                                        <div class="col-6"><div class="text-muted">Abogado</div><div class="fw-semibold">{{ $v($campo($c,'abogado')) }}</div></div>
                                                    </div>
                                                </div>
                                            </details>
                                        </div>

                                        @php
                                            $tieneEstadoDetalle =
                                                filled($c->marca_baja)
                                                || filled($campo($c,'fecha_baja_original'))
                                                || filled($campo($c,'marca_intimacion_original'));
                                        @endphp
                                        @if($tieneEstadoDetalle)
                                            <div class="col-lg-6">
                                                <div class="card border shadow-none h-100">
                                                    <div class="card-header bg-light fw-semibold">Estado / baja</div>
                                                    <div class="card-body">
                                                        <div class="row g-3 small">
                                                            <div class="col-4"><div class="text-muted">Marca baja</div><div class="fw-semibold">{{ $v($c->marca_baja) }}</div></div>
                                                            <div class="col-4"><div class="text-muted">Fecha baja</div><div class="fw-semibold">{{ $fechaCobol($campo($c,'fecha_baja_original')) }}</div></div>
                                                            <div class="col-4"><div class="text-muted">Intimación</div><div class="fw-semibold">{{ $v($campo($c,'marca_intimacion_original')) }}</div></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </div>

                                    @php
                                        $ajustes = collect();
                                        for ($n=1; $n<=8; $n++) {
                                            $f = $campo($c,'ajuste_'.$n.'_fecha');
                                            $pAjuste = $campo($c,'ajuste_'.$n.'_porcentaje');
                                            if (filled($f) || filled($pAjuste)) {
                                                $ajustes->push((object)[
                                                    'n' => $n,
                                                    'fecha' => $f,
                                                    'porcentaje' => $pAjuste,
                                                ]);
                                            }
                                        }
                                    @endphp

                                    @if($ajustes->isNotEmpty())
                                        <hr class="my-4">
                                        <details>
                                            <summary class="fw-semibold text-primary" style="cursor:pointer">
                                                Ajustes adicionales
                                            </summary>
                                            <div class="table-responsive mt-2">
                                                <table class="table table-sm align-middle mb-0">
                                                    <thead class="table-light"><tr><th>#</th><th>Fecha</th><th>Porcentaje</th></tr></thead>
                                                    <tbody>
                                                    @foreach($ajustes as $a)
                                                        <tr>
                                                            <td>{{ $a->n }}</td>
                                                            <td>{{ $fechaCobol($a->fecha) }}</td>
                                                            <td>{{ $v($a->porcentaje) }} %</td>
                                                        </tr>
                                                    @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </details>
                                    @endif

                                    @php
                                        $historial = collect($detalle['historialContratos']->get($c->contrato_id, collect()));
                                        $firmas = $historial->map(function ($h) use ($campo, $camposComparacionContrato) {
                                            $datos = [];
                                            foreach ($camposComparacionContrato as $nombre) {
                                                $datos[$nombre] = $campo($h, $nombre);
                                            }
                                            return md5(json_encode($datos));
                                        })->unique();

                                        $mostrarHistorial = $historial->count() > 1 && $firmas->count() > 1;
                                    @endphp

                                    @if($mostrarHistorial)
                                        <hr class="my-4">
                                        <details>
                                            <summary class="fw-semibold text-primary" style="cursor:pointer">
                                                Historial del contrato: hubo cambios entre períodos
                                            </summary>
                                            <div class="table-responsive mt-2">
                                                <table class="table table-sm table-hover align-middle mb-0">
                                                    <thead class="table-light"><tr><th>Período</th><th>Contrato</th><th>Vto.</th><th>Cuota 1</th><th>Cuota 2</th><th>Índice</th><th>Estado</th></tr></thead>
                                                    <tbody>
                                                    @foreach($historial as $h)
                                                        <tr>
                                                            <td>{{ substr($h->periodo,4,2) }}/{{ substr($h->periodo,0,4) }}</td>
                                                            <td>{{ $fechaCobol($campo($h,'fecha_contrato_original')) }}</td>
                                                            <td>{{ $fechaCobol($campo($h,'fecha_vencimiento_original')) }}</td>
                                                            <td>{{ $money($campo($h,'cuota_1')) }}</td>
                                                            <td>{{ $money($campo($h,'cuota_2')) }}</td>
                                                            <td>{{ $v($campo($h,'indice')) }}</td>
                                                            <td>{{ $h->activo ? 'Activo':'Histórico' }}</td>
                                                        </tr>
                                                    @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </details>
                                    @endif
                                </div>
                            </details>
                        </td>
                        <td><span class="badge {{ $c->activo ? 'text-bg-success':'text-bg-secondary' }}">{{ $c->activo ? 'Activo':'Histórico' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">Sin contratos relacionados en este período.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>

    @elseif(in_array($tab, ['cuenta-corriente','liquidaciones','impuestos','facturas'], true))
        @php
            $actividadInicial = match($tab) {
                'cuenta-corriente' => $detalle['ultimosMovimientos'],
                'liquidaciones' => $detalle['liquidaciones'],
                'impuestos' => $detalle['impuestos'],
                'facturas' => $detalle['facturas'],
            };
            $tituloActividad = match($tab) {
                'cuenta-corriente' => 'Movimientos de cuenta corriente',
                'liquidaciones' => 'Liquidaciones',
                'impuestos' => 'Impuestos garantizados',
                'facturas' => 'Facturas ARCA',
            };
        @endphp

        @if($tab === 'cuenta-corriente')
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Cuentas</div>
                <div class="table-responsive"><table class="table table-sm mb-0">
                    <thead><tr><th>Tipo</th><th>Cuenta</th><th>Movimientos históricos</th></tr></thead>
                    <tbody>
                    @forelse($detalle['cuentasCorrientes'] as $c)
                        <tr><td>{{ $c->tipo }}</td><td>{{ $c->cuenta_cobol }}</td><td>{{ number_format($c->movimientos,0,',','.') }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-3">Sin cuentas corrientes.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </div>
        @endif

        <div class="card border-0 shadow-sm"
             id="actividad-cliente"
             data-url="{{ route('core-clientes.actividad', ['persona' => $p->id]) }}"
             data-tipo="{{ $tab }}">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <div class="fw-semibold">{{ $tituloActividad }}</div>
                    <div class="small text-muted">Se muestra el mes actual si tiene actividad; si no, el último mes disponible. Podés pedir cualquier otro mes.</div>
                </div>
                <div class="d-flex align-items-center gap-2 position-relative">
                    <div class="input-group input-group-sm" style="width: 180px;">
                        <input
                            type="text"
                            class="form-control"
                            id="actividad-mes-display"
                            value="{{ substr($mesActividad,4,2) }}/{{ substr($mesActividad,0,4) }}"
                            readonly
                            aria-label="Mes seleccionado"
                        >
                        <input
                            type="hidden"
                            id="actividad-mes"
                            value="{{ substr($mesActividad,0,4) }}-{{ substr($mesActividad,4,2) }}"
                        >
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            id="actividad-mes-picker"
                            aria-label="Elegir mes"
                            title="Elegir mes"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                 viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                <path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h.5A1.5 1.5 0 0 1 15 2.5v11A1.5 1.5 0 0 1 13.5 15h-11A1.5 1.5 0 0 1 1 13.5v-11A1.5 1.5 0 0 1 2.5 1H3V.5a.5.5 0 0 1 .5-.5ZM2 4v9.5a.5.5 0 0 0 .5.5h11a.5.5 0 0 0 .5-.5V4H2Z"/>
                            </svg>
                        </button>
                    </div>

                    <div
                        id="actividad-mes-panel"
                        class="card shadow position-absolute d-none"
                        style="z-index: 1080; top: 100%; right: 0; width: 260px; margin-top: 4px;"
                    >
                        <div class="card-body p-2">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <button type="button" class="btn btn-sm btn-light" id="actividad-anio-anterior">&lsaquo;</button>
                                <strong id="actividad-anio"></strong>
                                <button type="button" class="btn btn-sm btn-light" id="actividad-anio-siguiente">&rsaquo;</button>
                            </div>
                            <div class="row g-1" id="actividad-meses"></div>
                        </div>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-primary" id="actividad-cargar">Cargar mes</button>
                </div>
            </div>
            <div id="actividad-contenido">
                @include('core-clientes.partials.actividad', [
                    'tipo' => $tab,
                    'mes' => $mesActividad,
                    'data' => $actividadInicial,
                    'incidencias' => $tab === 'cuenta-corriente'
                        ? ($detalle['incidenciasFechasFuturas'] ?? collect())
                        : collect(),
                ])
            </div>
            <div class="card-footer bg-white small text-muted" id="actividad-estado">
                Mes mostrado: {{ substr($mesActividad,4,2) }}/{{ substr($mesActividad,0,4) }}
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', () => {
            const card = document.getElementById('actividad-cliente');
            const input = document.getElementById('actividad-mes');
            const display = document.getElementById('actividad-mes-display');
            const picker = document.getElementById('actividad-mes-picker');
            const panel = document.getElementById('actividad-mes-panel');
            const anioLabel = document.getElementById('actividad-anio');
            const mesesContenedor = document.getElementById('actividad-meses');
            const anioAnterior = document.getElementById('actividad-anio-anterior');
            const anioSiguiente = document.getElementById('actividad-anio-siguiente');
            const button = document.getElementById('actividad-cargar');
            const contenido = document.getElementById('actividad-contenido');
            const estado = document.getElementById('actividad-estado');

            if (!card || !input || !display || !picker || !panel || !anioLabel || !mesesContenedor || !button || !contenido || !estado) return;

            const nombresMeses = [
                'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun',
                'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'
            ];

            const ahora = new Date();
            const maxAnio = ahora.getFullYear();
            const maxMes = ahora.getMonth() + 1;

            let [anioSeleccionado, mesSeleccionado] = (input.value || '').split('-').map(Number);
            if (!anioSeleccionado || !mesSeleccionado) {
                anioSeleccionado = maxAnio;
                mesSeleccionado = maxMes;
            }
            let anioVisible = anioSeleccionado;

            const renderMeses = () => {
                anioLabel.textContent = anioVisible;
                mesesContenedor.innerHTML = '';

                nombresMeses.forEach((nombre, indice) => {
                    const mes = indice + 1;
                    const futuro = anioVisible > maxAnio || (anioVisible === maxAnio && mes > maxMes);
                    const activo = anioVisible === anioSeleccionado && mes === mesSeleccionado;

                    const col = document.createElement('div');
                    col.className = 'col-3';

                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-sm w-100 ' + (activo ? 'btn-primary' : 'btn-outline-secondary');
                    btn.textContent = nombre;
                    btn.disabled = futuro;

                    btn.addEventListener('click', () => {
                        anioSeleccionado = anioVisible;
                        mesSeleccionado = mes;

                        const mm = String(mes).padStart(2, '0');
                        input.value = `${anioSeleccionado}-${mm}`;
                        display.value = `${mm}/${anioSeleccionado}`;
                        panel.classList.add('d-none');
                        renderMeses();
                    });

                    col.appendChild(btn);
                    mesesContenedor.appendChild(col);
                });

                anioSiguiente.disabled = anioVisible >= maxAnio;
            };

            picker.addEventListener('click', (event) => {
                event.stopPropagation();
                panel.classList.toggle('d-none');
                if (!panel.classList.contains('d-none')) {
                    anioVisible = anioSeleccionado;
                    renderMeses();
                }
            });

            anioAnterior.addEventListener('click', () => {
                anioVisible--;
                renderMeses();
            });

            anioSiguiente.addEventListener('click', () => {
                if (anioVisible < maxAnio) {
                    anioVisible++;
                    renderMeses();
                }
            });

            document.addEventListener('click', (event) => {
                if (!panel.contains(event.target) && event.target !== picker) {
                    panel.classList.add('d-none');
                }
            });

            const cargar = async () => {
                const mes = (input.value || '').replace('-', '');
                if (!/^\d{6}$/.test(mes)) return;

                button.disabled = true;
                const textoOriginal = button.textContent;
                button.textContent = 'Cargando…';
                estado.textContent = 'Consultando información…';

                try {
                    const url = new URL(card.dataset.url, window.location.origin);
                    url.searchParams.set('tipo', card.dataset.tipo);
                    url.searchParams.set('mes', mes);
                    const response = await fetch(url, {
                        headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html'}
                    });
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    contenido.innerHTML = await response.text();
                    estado.textContent = `Mes mostrado: ${mes.slice(4,6)}/${mes.slice(0,4)}`;
                } catch (e) {
                    estado.textContent = 'No se pudo cargar el mes solicitado.';
                } finally {
                    button.disabled = false;
                    button.textContent = textoOriginal;
                }
            };

            button.addEventListener('click', cargar);
        });
        </script>
    @endif
</div>
@endsection
