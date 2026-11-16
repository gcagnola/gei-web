@extends('layouts.app')

@section('title', 'Revisión COBOL de cliente')
@section('page-title', 'Revisión COBOL')

@section('content')
@php
    $fechaHora = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y H:i') : '—';

    $fecha = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '—';

    $normalizarCuentaCobol = static function ($cuenta): string {
        return preg_replace('/\D+/', '', trim((string) $cuenta)) ?? '';
    };

    $formatearCuentaCobol = static function ($cuenta): string {
        $original = trim((string) $cuenta);
        $digitos = preg_replace('/\D+/', '', $original) ?? '';

        if (strlen($digitos) === 11) {
            return substr($digitos, 0, 4).'/'.substr($digitos, 4, 5).'/'.substr($digitos, 9, 2);
        }

        return $original !== '' ? $original : '—';
    };

    $rolOrigen = match(strtoupper(trim((string) $conflicto->entidad_origen))) {
        'PROPIETAR' => 'PROPIETARIO',
        'INQUILINO' => 'INQUILINO',
        default => strtoupper(trim((string) $conflicto->entidad_origen)) ?: 'ORIGEN',
    };

    $valor = static fn ($v) => ($v === null || trim((string) $v) === '') ? '—' : $v;

    $estadoPendiente = $conflicto->estado === 'PENDIENTE';

    $motivoTexto = match($conflicto->motivo) {
        'CUIT_Y_DOCUMENTO_APUNTAN_A_CLIENTES_DISTINTOS' => 'El CUIT y el documento del origen COBOL apuntan a clientes diferentes. Requiere decidir cuál identidad es correcta.',
        'IDENTIFICACION_PARCIAL_CON_NOMBRE_INCOMPATIBLE' => 'Existe una coincidencia parcial, pero algunos datos relevantes del origen COBOL no son compatibles con el cliente encontrado.',
        'IDENTIDAD_CAMBIO_EN_CUENTA_EXISTENTE' => 'La cuenta COBOL ya estaba asociada, pero cambió algún dato de identidad en los archivos históricos. La revisión no debe crear ni trasladar cuentas.',
        default => 'La importación COBOL no pudo resolver esta identidad con seguridad.',
    };

    $comparacionPorCampo = static function ($cand): array {
        $salida = [];
        foreach ($cand->coincidencias as $cmp) {
            $salida[$cmp['campo']] = $cmp;
        }
        return $salida;
    };
@endphp

<div class="container-fluid py-3 pb-5">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1">Revisión COBOL #{{ $conflicto->id }}</h1>
            <p class="text-muted mb-0">
                Compará el origen COBOL con cada cliente candidato y decidí si corresponden a la misma persona.
            </p>
        </div>
        <a class="btn btn-outline-secondary"
           href="{{ route('archivo.unificacion.clientes.revisiones') }}">
            Volver
        </a>
    </div>

    @if (session('estado'))
        <div class="alert alert-success">{{ session('estado') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div class="alert {{ $estadoPendiente ? 'alert-warning' : 'alert-success' }} py-2">
        <strong>{{ $estadoPendiente ? 'PENDIENTE DE DECISIÓN' : 'RESUELTO' }}</strong>
        <span class="ms-2">{{ $motivoTexto }}</span>
        @if (! $estadoPendiente && $conflicto->cliente_resuelto_id)
            <span class="ms-2">Cliente resuelto: <strong>#{{ $conflicto->cliente_resuelto_id }}</strong>.</span>
        @endif
    </div>

    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 small">
                <div class="col-md-2">
                    <span class="text-muted d-block">Sistema / entidad</span>
                    <strong>{{ $conflicto->sistema_origen }} / {{ $conflicto->entidad_origen }}</strong>
                </div>
                <div class="col-md-2">
                    <span class="text-muted d-block">Cuenta COBOL</span>
                    <strong>{{ $formatearCuentaCobol($conflicto->clave_origen) }}</strong>
                </div>
                <div class="col-md-2">
                    <span class="text-muted d-block">Estado origen</span>
                    <strong>{{ $conflicto->estado_origen ?: '—' }}</strong>
                </div>
                <div class="col-md-2">
                    <span class="text-muted d-block">Archivo / línea</span>
                    {{ $conflicto->archivo_origen_id ?: '—' }} / {{ $conflicto->numero_linea ?: '—' }}
                </div>
                <div class="col-md-2">
                    <span class="text-muted d-block">Detectado</span>
                    {{ $fechaHora($conflicto->detectado_at) }}
                </div>
                <div class="col-md-2">
                    <span class="text-muted d-block">Última detección</span>
                    {{ $fechaHora($conflicto->ultima_deteccion_at) }}
                </div>
            </div>
        </div>
    </div>

    @php
        $campo = static fn ($dato, $nombre) => is_array($dato) ? ($dato[$nombre] ?? null) : ($dato->$nombre ?? null);
        $origenDocumento = trim(($datosOrigen['tipo_documento'] ?? '').' '.($datosOrigen['numero_documento'] ?? ''));
        $candidatosUnicos = $candidatos->unique('id_canonico')->values();
        $filasIdentidad = [
            ['Nombre', 'nombre'],
            ['CUIT', 'cuit'],
            ['Domicilio', 'domicilio'],
            ['Teléfono', 'telefono'],
            ['Teléfono alternativo', 'telefono_alternativo'],
            ['Localidad', 'localidad'],
            ['Provincia', 'provincia'],
            ['Condición IVA', 'condicion_iva'],
        ];
        $origenCuenta = $normalizarCuentaCobol($conflicto->clave_origen);
    @endphp

    <div class="card mb-3">
        <div class="card-header fw-semibold">Comparación única — origen COBOL y {{ $candidatosUnicos->count() }} candidato(s)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-top mb-0" style="min-width:900px">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:160px">Dato</th>
                            <th style="min-width:260px">ORIGEN COBOL — #{{ $conflicto->id }}<br><small>{{ $rolOrigen }}</small></th>
                            @foreach ($candidatosUnicos as $cand)
                                <th style="min-width:260px">
                                    Cliente #{{ $cand->id_canonico }}<br>
                                    <small>{{ $cand->roles ?: 'SIN ROL' }}</small>
                                    @if ($cand->fue_absorbido)
                                        <span class="badge text-bg-warning">Candidato original absorbido: #{{ $cand->id_original }}</span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th>Cuenta(s) COBOL</th>
                            <td><strong>{{ $formatearCuentaCobol($conflicto->clave_origen) }}</strong></td>
                            @foreach ($candidatosUnicos as $cand)
                                <td>
                                    @forelse (array_filter(array_map('trim', explode(',', (string) $cand->cuentas))) as $cta)
                                        <div>{{ $formatearCuentaCobol($cta) }}</div>
                                    @empty
                                        <span class="text-muted">—</span>
                                    @endforelse
                                </td>
                            @endforeach
                        </tr>
                        @foreach ($filasIdentidad as [$etiqueta, $clave])
                            <tr>
                                <th>{{ $etiqueta }}</th>
                                <td>{{ $valor($campo($datosOrigen, $clave)) }}</td>
                                @foreach ($candidatosUnicos as $cand)
                                    <td>
                                        {{ $valor($campo($cand->cliente, $clave)) }}
                                        @if (in_array($clave, ['nombre','cuit','domicilio','telefono'], true))
                                            @php
                                                $datosComp = $comparacionPorCampo($cand);
                                                $cmp = $datosComp[$clave] ?? null;
                                            @endphp
                                            @if ($cmp && $cmp['coincide'])
                                                <span class="badge text-bg-success">COINCIDE</span>
                                            @elseif ($campo($datosOrigen, $clave) && $campo($cand->cliente, $clave))
                                                <span class="badge text-bg-warning">DIFIERE</span>
                                            @endif
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        <tr>
                            <th>Documento</th>
                            <td>{{ $valor($origenDocumento) }}</td>
                            @foreach ($candidatosUnicos as $cand)
                                @php $docCand = trim(($cand->cliente->tipo_documento ?? '').' '.($cand->cliente->numero_documento ?? '')); @endphp
                                <td>{{ $valor($docCand) }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <th>Rol</th>
                            <td>{{ $rolOrigen }}</td>
                            @foreach ($candidatosUnicos as $cand)<td>{{ $cand->roles ?: '—' }}</td>@endforeach
                        </tr>
                        <tr>
                            <th>Cuenta propietario</th>
                            <td>{{ $valor($datosOrigen['cuenta_propietario'] ?? null) }}</td>
                            @foreach ($candidatosUnicos as $cand)<td>—</td>@endforeach
                        </tr>
                        <tr>
                            <th>Email</th>
                            <td>—</td>
                            @foreach ($candidatosUnicos as $cand)<td>{{ $valor($cand->cliente->email ?? null) }}</td>@endforeach
                        </tr>
                        <tr class="table-secondary"><th colspan="{{ 2 + $candidatosUnicos->count() }}">Inmuebles</th></tr>
                        <tr>
                            <th>Inmuebles vinculados a las cuentas</th>
                            <td>
                                @forelse ($inmueblesOrigen as $inmueble)
                                    <div class="border rounded p-2 mb-1 small">{{ $inmueble->domicilio }} <span class="text-muted">{{ $inmueble->relacion }}</span></div>
                                @empty
                                    <span class="text-muted">Sin inmuebles vinculados</span>
                                @endforelse
                            </td>
                            @foreach ($candidatosUnicos as $cand)
                                <td>
                                    @forelse ($cand->inmuebles as $inmueble)
                                        <div class="border rounded p-2 mb-1 small">#{{ $inmueble->inmueble_id }} — {{ $inmueble->domicilio }}<br><span class="text-muted">{{ $formatearCuentaCobol($inmueble->cuenta ?? '') }}</span></div>
                                    @empty
                                        <span class="text-muted">Sin inmuebles vinculados</span>
                                    @endforelse
                                </td>
                            @endforeach
                        </tr>
                        <tr class="table-secondary"><th colspan="{{ 2 + $candidatosUnicos->count() }}">Contratos (por cuenta COBOL, como inquilino o propietario)</th></tr>
                        <tr>
                            <th>Contratos</th>
                            <td>
                                @forelse ($contratosOrigen as $contrato)
                                    <div class="border rounded p-2 mb-1 small">
                                        <strong>#{{ $contrato->id }}</strong> · {{ $contrato->estado }}<br>
                                        Inq.: {{ $formatearCuentaCobol($contrato->cuenta_inquilino) }} · Prop.: {{ $formatearCuentaCobol($contrato->cuenta_propietario) }}<br>
                                        {{ $fecha($contrato->fecha_inicio) }} → {{ $fecha($contrato->fecha_fin) }}
                                    </div>
                                @empty
                                    <span class="text-muted">Sin contratos vinculados</span>
                                @endforelse
                            </td>
                            @foreach ($candidatosUnicos as $cand)
                                <td>
                                    @forelse ($cand->contratosComparacion as $contrato)
                                        <div class="border rounded p-2 mb-1 small">
                                            <strong>#{{ $contrato->id }}</strong> · {{ $contrato->estado }}<br>
                                            Inq.: {{ $formatearCuentaCobol($contrato->cuenta_inquilino) }} · Prop.: {{ $formatearCuentaCobol($contrato->cuenta_propietario) }}<br>
                                            {{ $fecha($contrato->fecha_inicio) }} → {{ $fecha($contrato->fecha_fin) }}
                                        </div>
                                    @empty
                                        <span class="text-muted">Sin contratos vinculados</span>
                                    @endforelse
                                </td>
                            @endforeach
                        </tr>
                        <tr class="table-secondary"><th colspan="{{ 2 + $candidatosUnicos->count() }}">Documentación reciente por cuenta y período</th></tr>
                        <tr>
                            <th>Liquidaciones / ARCA</th>
                            <td>@include('unificacion.clientes.partials.revision-documentos', ['filas' => $documentosOrigen])</td>
                            @foreach ($candidatosUnicos as $cand)
                                <td>@include('unificacion.clientes.partials.revision-documentos', ['filas' => $cand->documentos])</td>
                            @endforeach
                        </tr>
                        <tr class="table-light"><th>Decisión</th><td>Elegí un candidato o mantené la identidad separada.</td>
                            @foreach ($candidatosUnicos as $cand)
                                @php
                                    $esYaVinculado = $clienteVinculadoCanonicoId !== null
                                        && (int) $cand->id_canonico === $clienteVinculadoCanonicoId
                                        && collect(explode(',', (string) $cand->cuentas))->contains(fn ($cta) => $normalizarCuentaCobol($cta) === $origenCuenta);
                                @endphp
                                <td>
                                    @if ($estadoPendiente && ($clienteVinculadoCanonicoId === null || $esYaVinculado))
                                        <form method="POST" action="{{ route('archivo.unificacion.clientes.conflicto.resolver', $conflicto->id) }}"
                                              onsubmit="return confirm('¿Confirmar asociación con cliente #{{ $cand->id_canonico }}?');">
                                            @csrf
                                            <input type="hidden" name="decision" value="ASOCIAR_EXISTENTE">
                                            <input type="hidden" name="cliente_id" value="{{ $cand->id_canonico }}">
                                            <button class="btn btn-sm btn-primary">{{ $esYaVinculado ? 'Validar identidad existente' : 'Asociar a cliente #'.$cand->id_canonico }}</button>
                                        </form>
                                    @elseif ($estadoPendiente)
                                        <span class="small text-warning">Ya vinculada a #{{ $clienteVinculadoCanonicoId }}</span>
                                    @else
                                        <span class="text-muted">Resuelto</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($candidatos->count() >= 2)
        <div class="card mb-3">
            <div class="card-header fw-semibold">¿Los candidatos son en realidad la misma persona?</div>
            <div class="card-body d-flex flex-wrap gap-2">
                @for ($i = 0; $i < $candidatos->count(); $i++)
                    @for ($j = $i + 1; $j < $candidatos->count(); $j++)
                        <a class="btn btn-outline-primary"
                           href="{{ route('archivo.unificacion.clientes.comparar', ['principal' => $candidatos[$i]->id_canonico, 'secundario' => $candidatos[$j]->id_canonico]) }}">
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
                        <form method="POST"
                              action="{{ route('archivo.unificacion.clientes.conflicto.resolver', $conflicto->id) }}"
                              class="row g-2 align-items-end"
                              onsubmit="return confirm('¿Confirma asociar esta identidad COBOL al ID indicado?');">
                            @csrf
                            <input type="hidden" name="decision" value="ASOCIAR_EXISTENTE">

                            <div class="col-md-8">
                                <label class="form-label">ID del cliente canónico</label>
                                <input class="form-control" type="number" name="cliente_id" min="1" required>
                            </div>

                            <div class="col-md-4 d-grid">
                                <button class="btn btn-outline-primary">Asociar por ID</button>
                            </div>
                        </form>

                        <small class="text-muted d-block mt-2">
                            Use esta opción sólo si verificó que esta cuenta/origen COBOL pertenece a otro cliente existente.
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card border-secondary h-100">
                    <div class="card-header fw-semibold">Es una persona distinta</div>
                    <div class="card-body">
                        <p>
                            La identidad COBOL no corresponde a ninguno de los candidatos.
                            Se guardará la decisión para que no vuelva a proponerse como la misma persona.
                        </p>

                        <form method="POST"
                              action="{{ route('archivo.unificacion.clientes.conflicto.resolver', $conflicto->id) }}"
                              onsubmit="return confirm('¿Confirma que esta identidad COBOL debe mantenerse como persona separada?');">
                            @csrf
                            <input type="hidden" name="decision" value="CREAR_SEPARADO">
                            <button class="btn btn-outline-secondary">Mantener separado</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @php $cantidadRevisionesRelacionadas = 1 + $otrosConflictos->count(); @endphp

    @if ($cantidadRevisionesRelacionadas > 1)
        <details class="card mb-3">
            <summary class="card-header fw-semibold" style="cursor:pointer;">
                Revisiones COBOL relacionadas — {{ $cantidadRevisionesRelacionadas }}
            </summary>
            <div class="card-body py-2">
                <p class="small text-muted mb-2">
                    Son otras cuentas/orígenes COBOL pendientes vinculados a los mismos clientes candidatos.
                    Cada cuenta se resuelve por separado.
                </p>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Origen</th>
                            <th>Cuenta COBOL</th>
                            <th>Estado</th>
                            <th>Motivo</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-primary">
                            <td>{{ $conflicto->id }}</td>
                            <td>{{ $conflicto->entidad_origen }}</td>
                            <td><strong>{{ $formatearCuentaCobol($conflicto->clave_origen) }}</strong></td>
                            <td><span class="badge text-bg-success">ACTUAL</span></td>
                            <td>{{ $conflicto->motivo }}</td>
                            <td><span class="text-muted">En revisión</span></td>
                        </tr>

                        @foreach ($otrosConflictos as $otro)
                            <tr>
                                <td>{{ $otro->id }}</td>
                                <td>{{ $otro->entidad_origen }}</td>
                                <td><strong>{{ $formatearCuentaCobol($otro->clave_origen) }}</strong></td>
                                <td><span class="badge text-bg-danger">PENDIENTE</span></td>
                                <td>{{ $otro->motivo }}</td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="{{ route('archivo.unificacion.clientes.conflicto.revisar', $otro->id) }}">
                                        Revisar esta cuenta
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif

    @if ($detalleConflicto)
        <details class="card mb-3">
            <summary class="card-header fw-semibold" style="cursor:pointer;">
                Detalle técnico de la detección
            </summary>
            <div class="card-body">
                <pre class="small bg-light border rounded p-2 mb-0">{{ json_encode($detalleConflicto, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </details>
    @endif
</div>
@endsection
