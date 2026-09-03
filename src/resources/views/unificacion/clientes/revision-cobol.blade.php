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
           href="{{ route('archivo.unificacion.clientes.index', ['vista' => 'activos_revision']) }}">
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

    @forelse ($candidatos as $cand)
        @php
            $c = $cand->cliente;
            $cmp = $comparacionPorCampo($cand);

            $documentoOrigen = trim(($datosOrigen['tipo_documento'] ?? '').' '.($datosOrigen['numero_documento'] ?? '')) ?: '—';
            $documentoCliente = trim(($c->tipo_documento ?? '').' '.($c->numero_documento ?? '')) ?: '—';

            $cuentaRevisionNormalizada = $normalizarCuentaCobol($conflicto->clave_origen);
            $cuentasCliente = collect(explode(',', (string) ($cand->cuentas ?? '')))
                ->map(fn ($cuenta) => trim((string) $cuenta))
                ->filter()
                ->values();

            $cuentasClienteNormalizadas = $cuentasCliente
                ->map(fn ($cuenta) => $normalizarCuentaCobol($cuenta))
                ->filter()
                ->values();

            $cuentaCoincide = $cuentaRevisionNormalizada !== ''
                && $cuentasClienteNormalizadas->contains($cuentaRevisionNormalizada);

            $camposComparables = [
                'nombre' => ['Nombre', $datosOrigen['nombre'] ?? null, $c->nombre ?? null],
                'cuit' => ['CUIT', $datosOrigen['cuit'] ?? null, $c->cuit ?? null],
                'numero_documento' => ['Documento', $documentoOrigen, $documentoCliente],
                'domicilio' => ['Domicilio', $datosOrigen['domicilio'] ?? null, $c->domicilio ?? null],
                'telefono' => ['Teléfono', $datosOrigen['telefono'] ?? null, $c->telefono ?? null],
            ];
        @endphp

        <div class="card mb-4 {{ $cand->fue_absorbido ? 'border-warning' : 'border-primary' }}">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong>Revisión #{{ $conflicto->id }}: origen COBOL → cliente #{{ $cand->id_canonico }} — {{ $c->nombre }}</strong>
                    @if ($cand->fue_absorbido)
                        <span class="badge text-bg-warning ms-1">
                            Candidato original #{{ $cand->id_original }} absorbido
                        </span>
                    @endif
                </div>

                @if ($estadoPendiente)
                    <form method="POST"
                          action="{{ route('archivo.unificacion.clientes.conflicto.resolver', $conflicto->id) }}"
                          onsubmit="return confirm('¿Confirma asociar el origen COBOL de la revisión #{{ $conflicto->id }} al cliente #{{ $cand->id_canonico }}? El cliente #{{ $cand->id_canonico }} quedará como canónico.');">
                        @csrf
                        <input type="hidden" name="decision" value="ASOCIAR_EXISTENTE">
                        <input type="hidden" name="cliente_id" value="{{ $cand->id_canonico }}">
                        <button class="btn btn-primary btn-sm">
                            Asociar revisión #{{ $conflicto->id }} a cliente #{{ $cand->id_canonico }}
                        </button>
                    </form>
                @endif
            </div>

            <div class="card-body">
                <div class="row g-3">
                    <div class="col-xl-6">
                        <div class="border rounded h-100">
                            <div class="px-3 py-2 border-bottom bg-light d-flex justify-content-between align-items-center">
                                <span class="fw-semibold">ORIGEN COBOL — REVISIÓN #{{ $conflicto->id }}</span>
                                <span class="badge text-bg-light border">{{ $rolOrigen }}</span>
                            </div>
                            <div class="p-3">
                                <table class="table table-sm mb-0 align-middle">
                                    <tbody>
                                        <tr>
                                            <th style="width: 170px;">Cuenta COBOL</th>
                                            <td><strong>{{ $formatearCuentaCobol($conflicto->clave_origen) }}</strong></td>
                                        </tr>
                                        @foreach ($camposComparables as $campo => [$etiqueta, $origenValor, $clienteValor])
                                            @php
                                                $lectura = $cmp[$campo] ?? null;
                                            @endphp
                                            <tr>
                                                <th style="width: 170px;">{{ $etiqueta }}</th>
                                                <td>{{ $valor($origenValor) }}</td>
                                            </tr>
                                        @endforeach
                                        <tr>
                                            <th>Localidad / Provincia</th>
                                            <td>{{ trim(($datosOrigen['localidad'] ?? '').' / '.($datosOrigen['provincia'] ?? ''), ' /') ?: '—' }}</td>
                                        </tr>
                                        <tr>
                                            <th>Teléfono alternativo</th>
                                            <td>{{ $valor($datosOrigen['telefono_alternativo'] ?? null) }}</td>
                                        </tr>
                                        <tr>
                                            <th>Cuenta propietario</th>
                                            <td>{{ $valor($datosOrigen['cuenta_propietario'] ?? null) }}</td>
                                        </tr>
                                        <tr>
                                            <th>Condición IVA</th>
                                            <td>{{ $valor($datosOrigen['condicion_iva'] ?? null) }}</td>
                                        </tr>
                                    </tbody>
                                </table>

                                <hr>

                                <h3 class="h6">Documentación del origen</h3>
                                @if (($documentosOrigen ?? collect())->isEmpty())
                                    <div class="small text-muted">No se encontraron documentos recientes para esta cuenta COBOL.</div>
                                @else
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Período</th>
                                                    <th>Cuenta</th>
                                                    <th>Inmueble(s)</th>
                                                    <th>Documentos</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            @foreach ($documentosOrigen as $documentoFila)
                                                @php
                                                    $cantidadArca = $documentoFila->comprobantes_arca->count();
                                                    $cantidadLiq = $documentoFila->liquidaciones->count();
                                                @endphp
                                                <tr>
                                                    <td class="text-nowrap">
                                                        {{ substr($documentoFila->periodo, 4, 2) }}/{{ substr($documentoFila->periodo, 0, 4) }}
                                                    </td>
                                                    <td class="text-nowrap"><strong>{{ $formatearCuentaCobol($documentoFila->cuenta) }}</strong></td>
                                                    <td>
                                                        @forelse ($documentoFila->inmuebles as $inmuebleDoc)
                                                            <div>
                                                                {{ $inmuebleDoc->domicilio }}
                                                                <span class="badge text-bg-light border">{{ $inmuebleDoc->relacion }}</span>
                                                            </div>
                                                        @empty
                                                            <span class="text-muted">—</span>
                                                        @endforelse
                                                    </td>
                                                    <td>
                                                        <div class="d-flex flex-wrap gap-1">
                                                            @foreach ($documentoFila->liquidaciones as $liquidacion)
                                                                @if ($liquidacion->pdf_disponible ?? false)
                                                                    <a class="btn btn-sm btn-outline-primary"
                                                                       href="{{ route('propietarios.liquidaciones.ver', $liquidacion->id) }}"
                                                                       target="_blank" rel="noopener">
                                                                        Liquidación{{ $cantidadLiq > 1 ? ' '.$liquidacion->numero_interno : '' }}
                                                                    </a>
                                                                @endif

                                                                @if ($liquidacion->impuestos_pdf_disponible ?? false)
                                                                    <a class="btn btn-sm btn-outline-primary"
                                                                       href="{{ route('propietarios.liquidaciones.impuestos.ver', $liquidacion->id) }}"
                                                                       target="_blank" rel="noopener">
                                                                        Imp. garantizados{{ $cantidadLiq > 1 ? ' '.$liquidacion->numero_interno : '' }}
                                                                    </a>
                                                                @endif
                                                            @endforeach

                                                            @if ($cantidadArca === 1)
                                                                @php $arca = $documentoFila->comprobantes_arca->first(); @endphp
                                                                <a class="btn btn-sm btn-outline-primary"
                                                                   href="{{ route('comprobantes-arca.ver', ['periodo' => $documentoFila->periodo, 'archivo' => $arca->nombre_archivo]) }}"
                                                                   target="_blank" rel="noopener">
                                                                    ARCA
                                                                </a>
                                                            @elseif ($cantidadArca > 1)
                                                                <div class="dropdown">
                                                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle"
                                                                            type="button"
                                                                            data-bs-toggle="dropdown">
                                                                        ARCA ({{ $cantidadArca }})
                                                                    </button>
                                                                    <ul class="dropdown-menu">
                                                                        @foreach ($documentoFila->comprobantes_arca as $arca)
                                                                            <li>
                                                                                <a class="dropdown-item"
                                                                                   href="{{ route('comprobantes-arca.ver', ['periodo' => $documentoFila->periodo, 'archivo' => $arca->nombre_archivo]) }}"
                                                                                   target="_blank" rel="noopener">
                                                                                    {{ pathinfo($arca->nombre_archivo, PATHINFO_FILENAME) }}
                                                                                </a>
                                                                            </li>
                                                                        @endforeach
                                                                    </ul>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="border rounded h-100">
                            <div class="px-3 py-2 border-bottom bg-light d-flex justify-content-between align-items-center">
                                <span class="fw-semibold">CLIENTE #{{ $cand->id_canonico }}</span>
                                <span class="badge text-bg-light border">{{ $cand->roles ?: 'SIN ROL' }}</span>
                            </div>

                            <div class="p-3">
                                <table class="table table-sm mb-0 align-middle">
                                    <tbody>
                                        <tr>
                                            <th style="width: 170px;">Cuenta COBOL</th>
                                            <td>
                                                @forelse ($cuentasCliente as $cuentaCliente)
                                                    <div><strong>{{ $formatearCuentaCobol($cuentaCliente) }}</strong></div>
                                                @empty
                                                    <span class="text-muted">—</span>
                                                @endforelse
                                            </td>
                                            <td class="text-end" style="width: 110px;">
                                                @if ($cuentaCoincide)
                                                    <span class="badge text-bg-success">COINCIDE</span>
                                                @elseif ($cuentasCliente->isNotEmpty())
                                                    <span class="badge text-bg-warning">DIFIERE</span>
                                                @else
                                                    <span class="badge text-bg-secondary">SIN DATOS</span>
                                                @endif
                                            </td>
                                        </tr>
                                        @foreach ($camposComparables as $campo => [$etiqueta, $origenValor, $clienteValor])
                                            @php
                                                $lectura = $cmp[$campo] ?? null;
                                                $hayOrigen = $origenValor !== null && trim((string) $origenValor) !== '' && $origenValor !== '—';
                                                $hayCliente = $clienteValor !== null && trim((string) $clienteValor) !== '' && $clienteValor !== '—';
                                            @endphp
                                            <tr>
                                                <th style="width: 170px;">{{ $etiqueta }}</th>
                                                <td>{{ $valor($clienteValor) }}</td>
                                                <td class="text-end" style="width: 110px;">
                                                    @if ($lectura && $lectura['coincide'])
                                                        <span class="badge text-bg-success">COINCIDE</span>
                                                    @elseif ($hayOrigen && $hayCliente)
                                                        <span class="badge text-bg-warning">DIFIERE</span>
                                                    @else
                                                        <span class="badge text-bg-secondary">SIN DATOS</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                        <tr>
                                            <th>Localidad / Provincia</th>
                                            <td>{{ trim(($c->localidad ?? '').' / '.($c->provincia ?? ''), ' /') ?: '—' }}</td>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <th>Teléfono alternativo</th>
                                            <td>{{ $valor($c->telefono_alternativo ?? null) }}</td>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <th>Email</th>
                                            <td>{{ $valor($c->email ?? null) }}</td>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <th>Condición IVA</th>
                                            <td>{{ $valor($c->condicion_iva ?? null) }}</td>
                                            <td></td>
                                        </tr>
                                    </tbody>
                                </table>

                                <hr>

                                <div class="alert alert-info py-2 small">
                                    <strong>Decisión:</strong>
                                    si corresponde a la misma persona, el cliente
                                    <strong>#{{ $cand->id_canonico }}</strong> queda como canónico y el
                                    origen COBOL de la revisión <strong>#{{ $conflicto->id }}</strong>
                                    queda asociado a ese cliente.
                                </div>

                                <h3 class="h6 mb-2">Inmuebles</h3>
                                @forelse ($cand->inmuebles as $inmueble)
                                    <div class="border rounded p-2 mb-2 small">
                                        <div class="fw-semibold">
                                            #{{ $inmueble->inmueble_id }} — {{ $inmueble->domicilio }}
                                        </div>
                                        <div class="text-muted">
                                            Cuenta: {{ $formatearCuentaCobol($inmueble->cuenta ?? '') }}
                                            @if (isset($inmueble->porcentaje) && $inmueble->porcentaje !== null)
                                                · Porcentaje: {{ $inmueble->porcentaje }}%
                                            @endif
                                            · {{ $inmueble->activo ? 'Activo' : 'Histórico' }}
                                        </div>
                                    </div>
                                @empty
                                    <div class="small text-muted mb-3">No tiene inmuebles como propietario.</div>
                                @endforelse

                                <h3 class="h6 mt-3 mb-2">Contratos</h3>
                                @forelse ($cand->contratos as $contrato)
                                    <div class="border rounded p-2 mb-2 small">
                                        <div>
                                            <strong>Contrato #{{ $contrato->contrato_id }}</strong>
                                            · Cuenta {{ $formatearCuentaCobol($contrato->cuenta ?? $contrato->cuenta_inquilino ?? '') }}
                                        </div>
                                        <div class="text-muted">
                                            {{ $fecha($contrato->fecha_inicio ?? null) }}
                                            →
                                            {{ $fecha($contrato->fecha_fin ?? null) }}
                                            · {{ $contrato->contrato_estado ?? ($contrato->activo ? 'ACTIVO' : 'INACTIVO') }}
                                        </div>
                                    </div>
                                @empty
                                    <div class="small text-muted mb-3">No tiene contratos como inquilino.</div>
                                @endforelse

                                <h3 class="h6 mt-3 mb-2">Liquidaciones / documentación</h3>
                                @if (($cand->documentos ?? collect())->isEmpty())
                                    <div class="small text-muted">
                                        No se encontraron documentos recientes para las cuentas de este cliente.
                                    </div>
                                @else
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Período</th>
                                                    <th>Cuenta</th>
                                                    <th>Inmueble(s)</th>
                                                    <th>Documentos</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            @foreach ($cand->documentos as $documentoFila)
                                                @php
                                                    $cantidadArca = $documentoFila->comprobantes_arca->count();
                                                    $cantidadLiq = $documentoFila->liquidaciones->count();
                                                @endphp
                                                <tr>
                                                    <td class="text-nowrap">
                                                        {{ substr($documentoFila->periodo, 4, 2) }}/{{ substr($documentoFila->periodo, 0, 4) }}
                                                    </td>
                                                    <td class="text-nowrap"><strong>{{ $formatearCuentaCobol($documentoFila->cuenta) }}</strong></td>
                                                    <td>
                                                        @forelse ($documentoFila->inmuebles as $inmuebleDoc)
                                                            <div>
                                                                {{ $inmuebleDoc->domicilio }}
                                                                <span class="badge text-bg-light border">{{ $inmuebleDoc->relacion }}</span>
                                                            </div>
                                                        @empty
                                                            <span class="text-muted">—</span>
                                                        @endforelse
                                                    </td>
                                                    <td>
                                                        <div class="d-flex flex-wrap gap-1">
                                                            @foreach ($documentoFila->liquidaciones as $liquidacion)
                                                                @if ($liquidacion->pdf_disponible ?? false)
                                                                    <a class="btn btn-sm btn-outline-primary"
                                                                       href="{{ route('propietarios.liquidaciones.ver', $liquidacion->id) }}"
                                                                       target="_blank" rel="noopener">
                                                                        Liquidación{{ $cantidadLiq > 1 ? ' '.$liquidacion->numero_interno : '' }}
                                                                    </a>
                                                                @endif

                                                                @if ($liquidacion->impuestos_pdf_disponible ?? false)
                                                                    <a class="btn btn-sm btn-outline-primary"
                                                                       href="{{ route('propietarios.liquidaciones.impuestos.ver', $liquidacion->id) }}"
                                                                       target="_blank" rel="noopener">
                                                                        Imp. garantizados{{ $cantidadLiq > 1 ? ' '.$liquidacion->numero_interno : '' }}
                                                                    </a>
                                                                @endif
                                                            @endforeach

                                                            @if ($cantidadArca === 1)
                                                                @php $arca = $documentoFila->comprobantes_arca->first(); @endphp
                                                                <a class="btn btn-sm btn-outline-primary"
                                                                   href="{{ route('comprobantes-arca.ver', ['periodo' => $documentoFila->periodo, 'archivo' => $arca->nombre_archivo]) }}"
                                                                   target="_blank" rel="noopener">
                                                                    ARCA
                                                                </a>
                                                            @elseif ($cantidadArca > 1)
                                                                <div class="dropdown">
                                                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle"
                                                                            type="button"
                                                                            data-bs-toggle="dropdown">
                                                                        ARCA ({{ $cantidadArca }})
                                                                    </button>
                                                                    <ul class="dropdown-menu">
                                                                        @foreach ($documentoFila->comprobantes_arca as $arca)
                                                                            <li>
                                                                                <a class="dropdown-item"
                                                                                   href="{{ route('comprobantes-arca.ver', ['periodo' => $documentoFila->periodo, 'archivo' => $arca->nombre_archivo]) }}"
                                                                                   target="_blank" rel="noopener">
                                                                                    {{ pathinfo($arca->nombre_archivo, PATHINFO_FILENAME) }}
                                                                                </a>
                                                                            </li>
                                                                        @endforeach
                                                                    </ul>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="alert alert-warning">
            El conflicto no tiene clientes candidatos actualmente. Puede asociarlo manualmente por ID o mantener esta identidad separada.
        </div>
    @endforelse

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
