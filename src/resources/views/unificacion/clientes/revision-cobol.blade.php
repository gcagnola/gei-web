@extends('layouts.app')

@section('title', 'Revisión COBOL de cliente')
@section('page-title', 'Revisión COBOL')

@section('content')
@php
    $fechaHora = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y H:i') : '—';
    $estadoPendiente = $conflicto->estado === 'PENDIENTE';
    $motivoTexto = match($conflicto->motivo) {
        'CUIT_Y_DOCUMENTO_APUNTAN_A_CLIENTES_DISTINTOS' => 'El CUIT y el documento del origen COBOL apuntan a clientes diferentes. Requiere decidir cuál identidad es correcta.',
        'IDENTIFICACION_PARCIAL_CON_NOMBRE_INCOMPATIBLE' => 'Existe una coincidencia parcial (por ejemplo documento o CUIT), pero el nombre del origen COBOL no es compatible con el cliente encontrado.',
        default => 'La importación COBOL no pudo resolver esta identidad con seguridad.',
    };
@endphp

<div class="container-fluid py-3 pb-5">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1">Revisión COBOL #{{ $conflicto->id }}</h1>
            <p class="text-muted mb-0">Esta pantalla resuelve una identidad de origen. No fusiona clientes automáticamente.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('archivo.unificacion.clientes.index', ['vista' => 'activos_revision']) }}">Volver</a>
    </div>

    @if (session('estado'))<div class="alert alert-success">{{ session('estado') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif

    <div class="alert {{ $estadoPendiente ? 'alert-warning' : 'alert-success' }}">
        <strong>{{ $estadoPendiente ? 'PENDIENTE DE DECISIÓN' : 'RESUELTO' }}</strong><br>
        {{ $motivoTexto }}
        @if (! $estadoPendiente && $conflicto->cliente_resuelto_id)
            <div class="mt-1">Cliente resuelto: <strong>#{{ $conflicto->cliente_resuelto_id }}</strong>.</div>
        @endif
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header fw-semibold">Origen COBOL</div>
                <div class="card-body">
                    <div class="row g-3 small">
                        <div class="col-md-4"><span class="text-muted d-block">Sistema / entidad</span><strong>{{ $conflicto->sistema_origen }} / {{ $conflicto->entidad_origen }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Cuenta / clave</span><strong>{{ $conflicto->clave_origen }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Estado origen</span><strong>{{ $conflicto->estado_origen ?: '—' }}</strong></div>
                        <div class="col-md-4"><span class="text-muted d-block">Archivo / línea</span>{{ $conflicto->archivo_origen_id ?: '—' }} / {{ $conflicto->numero_linea ?: '—' }}</div>
                        <div class="col-md-4"><span class="text-muted d-block">Detectado</span>{{ $fechaHora($conflicto->detectado_at) }}</div>
                        <div class="col-md-4"><span class="text-muted d-block">Última detección</span>{{ $fechaHora($conflicto->ultima_deteccion_at) }}</div>
                    </div>

                    <hr>

                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <tbody>
                                <tr><th style="width:220px">Nombre COBOL</th><td>{{ $datosOrigen['nombre'] ?? '—' }}</td></tr>
                                <tr><th>CUIT</th><td>{{ $datosOrigen['cuit'] ?? '—' }}</td></tr>
                                <tr><th>Documento</th><td>{{ trim(($datosOrigen['tipo_documento'] ?? '').' '.($datosOrigen['numero_documento'] ?? '')) ?: '—' }}</td></tr>
                                <tr><th>Domicilio</th><td>{{ $datosOrigen['domicilio'] ?? '—' }}</td></tr>
                                <tr><th>Localidad / Provincia</th><td>{{ trim(($datosOrigen['localidad'] ?? '').' / '.($datosOrigen['provincia'] ?? ''), ' /') ?: '—' }}</td></tr>
                                <tr><th>Teléfono</th><td>{{ $datosOrigen['telefono'] ?? '—' }} @if(!empty($datosOrigen['telefono_alternativo'])) · {{ $datosOrigen['telefono_alternativo'] }} @endif</td></tr>
                                <tr><th>Cuenta propietario</th><td>{{ $datosOrigen['cuenta_propietario'] ?? '—' }}</td></tr>
                                <tr><th>Condición IVA</th><td>{{ $datosOrigen['condicion_iva'] ?? '—' }}</td></tr>
                            </tbody>
                        </table>
                    </div>

                    @if ($detalleConflicto)
                        <details class="mt-3">
                            <summary class="fw-semibold">Detalle técnico de la detección</summary>
                            <pre class="small bg-light border rounded p-2 mt-2 mb-0">{{ json_encode($detalleConflicto, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header fw-semibold">Qué debe decidir el usuario</div>
                <div class="card-body">
                    <ol class="mb-3">
                        <li>Si esta cuenta COBOL pertenece a uno de los clientes candidatos, asociarla a ese cliente.</li>
                        <li>Si pertenece a otro cliente ya existente, indicar su ID.</li>
                        <li>Si es otra persona, elegir <strong>Mantener separado</strong>.</li>
                        <li>Si dos filas de clientes representan a la misma persona, primero compararlas y luego usar la unificación de clientes.</li>
                    </ol>
                    <div class="alert alert-info mb-0">
                        La decisión queda guardada en <code>clientes_resoluciones_origen</code> y las próximas importaciones COBOL deben respetarla.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <h2 class="h5 mb-2">Clientes candidatos</h2>

    @forelse ($candidatos as $cand)
        @php $c = $cand->cliente; @endphp
        <div class="card mb-3 {{ $cand->fue_absorbido ? 'border-warning' : '' }}">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong>#{{ $cand->id_canonico }} — {{ $c->nombre }}</strong>
                    @if ($cand->fue_absorbido)
                        <span class="badge text-bg-warning ms-1">Candidato original #{{ $cand->id_original }} absorbido</span>
                    @endif
                </div>
                @if ($estadoPendiente)
                    <form method="POST" action="{{ route('archivo.unificacion.clientes.conflicto.resolver', $conflicto->id) }}" onsubmit="return confirm('¿Asociar definitivamente esta identidad COBOL al cliente #{{ $cand->id_canonico }}?');">
                        @csrf
                        <input type="hidden" name="decision" value="ASOCIAR_EXISTENTE">
                        <input type="hidden" name="cliente_id" value="{{ $cand->id_canonico }}">
                        <button class="btn btn-sm btn-primary">Asociar a #{{ $cand->id_canonico }}</button>
                    </form>
                @endif
            </div>

            <div class="card-body">
                <div class="row g-3 mb-3 small">
                    <div class="col-md-3"><span class="text-muted d-block">CUIT</span><strong>{{ $c->cuit ?: '—' }}</strong></div>
                    <div class="col-md-3"><span class="text-muted d-block">Documento</span><strong>{{ trim(($c->tipo_documento ?: '').' '.($c->numero_documento ?: '')) ?: '—' }}</strong></div>
                    <div class="col-md-3"><span class="text-muted d-block">Roles</span>{{ $cand->roles ?: '—' }}</div>
                    <div class="col-md-3"><span class="text-muted d-block">Cuentas COBOL</span>{{ $cand->cuentas ?: '—' }}</div>
                    <div class="col-md-3"><span class="text-muted d-block">Inmuebles propietario</span>{{ $cand->inmuebles_count }}</div>
                    <div class="col-md-3"><span class="text-muted d-block">Contratos inquilino</span>{{ $cand->contratos_count }}</div>
                    <div class="col-md-3"><span class="text-muted d-block">Liquidaciones propietario</span>{{ $cand->liquidaciones_count }}</div>
                    <div class="col-md-3"><span class="text-muted d-block">Email</span>{{ $c->email ?: '—' }}</div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Dato</th><th>Origen COBOL</th><th>Cliente #{{ $cand->id_canonico }}</th><th>Lectura</th></tr></thead>
                        <tbody>
                        @foreach ($cand->coincidencias as $cmp)
                            <tr>
                                <td>{{ $cmp['etiqueta'] }}</td>
                                <td>{{ $cmp['origen'] ?? '—' }}</td>
                                <td>{{ $cmp['cliente'] ?? '—' }}</td>
                                <td>
                                    @if ($cmp['coincide'])
                                        <span class="badge text-bg-success">COINCIDE</span>
                                    @elseif (($cmp['origen'] ?? null) && ($cmp['cliente'] ?? null))
                                        <span class="badge text-bg-warning">DIFIERE</span>
                                    @else
                                        <span class="badge text-bg-secondary">SIN COMPARAR</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @empty
        <div class="alert alert-warning">El conflicto no tiene clientes candidatos actualmente. Puede asociar manualmente por ID o mantener esta identidad separada.</div>
    @endforelse

    @if ($candidatos->count() >= 2)
        <div class="card mb-3">
            <div class="card-header fw-semibold">¿Los candidatos son en realidad la misma persona?</div>
            <div class="card-body d-flex flex-wrap gap-2">
                @for ($i = 0; $i < $candidatos->count(); $i++)
                    @for ($j = $i + 1; $j < $candidatos->count(); $j++)
                        <a class="btn btn-outline-primary" href="{{ route('archivo.unificacion.clientes.comparar', ['principal' => $candidatos[$i]->id_canonico, 'secundario' => $candidatos[$j]->id_canonico]) }}">
                            Comparar #{{ $candidatos[$i]->id_canonico }} con #{{ $candidatos[$j]->id_canonico }}
                        </a>
                    @endfor
                @endfor
            </div>
        </div>
    @endif

    @if ($estadoPendiente)
        <div class="row g-3 mb-3">
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header fw-semibold">Asociar a otro cliente existente</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('archivo.unificacion.clientes.conflicto.resolver', $conflicto->id) }}" class="row g-2 align-items-end" onsubmit="return confirm('¿Confirma asociar esta identidad COBOL al ID indicado?');">
                            @csrf
                            <input type="hidden" name="decision" value="ASOCIAR_EXISTENTE">
                            <div class="col-md-8">
                                <label class="form-label">ID del cliente canónico</label>
                                <input class="form-control" type="number" name="cliente_id" min="1" required>
                            </div>
                            <div class="col-md-4 d-grid"><button class="btn btn-outline-primary">Asociar por ID</button></div>
                        </form>
                        <small class="text-muted d-block mt-2">Use esta opción sólo si verificó que la cuenta/origen COBOL pertenece a otro cliente existente.</small>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card border-secondary h-100">
                    <div class="card-header fw-semibold">Es una persona distinta</div>
                    <div class="card-body">
                        <p>La identidad COBOL no corresponde a ninguno de los candidatos. Se conservará la decisión para que no vuelva a proponerse como la misma persona.</p>
                        <form method="POST" action="{{ route('archivo.unificacion.clientes.conflicto.resolver', $conflicto->id) }}" onsubmit="return confirm('¿Confirma que esta identidad COBOL debe mantenerse como persona separada?');">
                            @csrf
                            <input type="hidden" name="decision" value="CREAR_SEPARADO">
                            <button class="btn btn-outline-secondary">Mantener separado</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($otrosConflictos->isNotEmpty())
        <details class="card mb-3">
            <summary class="card-header fw-semibold">Otras revisiones pendientes relacionadas — {{ $otrosConflictos->count() }}</summary>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>#</th><th>Origen</th><th>Cuenta</th><th>Motivo</th><th>Acción</th></tr></thead>
                    <tbody>
                    @foreach ($otrosConflictos as $otro)
                        <tr>
                            <td>{{ $otro->id }}</td>
                            <td>{{ $otro->entidad_origen }}</td>
                            <td>{{ $otro->clave_origen }}</td>
                            <td>{{ $otro->motivo }}</td>
                            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('archivo.unificacion.clientes.conflicto.revisar', $otro->id) }}">Revisar</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</div>
@endsection
