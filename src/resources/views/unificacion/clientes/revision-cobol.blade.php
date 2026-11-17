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
    $origenesTabla = collect([[
        'conflicto'=>$conflicto, 'datosOrigen'=>$datosOrigen, 'inmueblesOrigen'=>$inmueblesOrigen,
        'contratosOrigen'=>$contratosOrigen, 'documentosOrigen'=>$documentosOrigen,
        'clienteVinculadoCanonicoId'=>$clienteVinculadoCanonicoId,
    ]])->concat($origenesComparacion ?? [])->sortBy(fn ($o) => $o['conflicto']->id)->values();
    $esGrupo = $origenesTabla->count() > 1;
    $etiquetaIdentidad = static fn ($id) => str_starts_with((string) $id, 'cobol:')
        ? 'ORIGEN COBOL — #'.substr((string) $id, 6) : 'Cliente #'.$id;

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
            <h1 class="h3 mb-1">{{ $esGrupo ? "Revisión COBOL agrupada — ".$origenesTabla->map(fn ($o) => "#".$o['conflicto']->id)->implode(", ") : "Revisión COBOL #".$conflicto->id }}</h1>
            <p class="text-muted mb-0">
                Compará todas las identidades. Compartir candidatos no significa que sean la misma persona.
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
        <span class="ms-2">{{ $esGrupo ? "Seleccioná únicamente las identidades que correspondan a la misma persona. Las demás seguirán sin unificar." : $motivoTexto }}</span>
        @if (! $estadoPendiente && $conflicto->cliente_resuelto_id)
            <span class="ms-2">Cliente resuelto: <strong>#{{ $conflicto->cliente_resuelto_id }}</strong>.</span>
        @endif
    </div>

    @if (! $esGrupo)
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
    @endif

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
            ['Código postal', 'codigo_postal'],
            ['Localidad', 'localidad'],
            ['Provincia', 'provincia'],
            ['Condición IVA', 'condicion_iva'],
        ];
        $origenCuenta = $normalizarCuentaCobol($conflicto->clave_origen);
        $unificacionDoble = $estadoPendiente && ($origenesTabla->count() + $candidatosUnicos->count() >= 2);
        $opcionesSeleccion = $origenesTabla->map(fn ($o) => 'cobol:'.$o['conflicto']->id)->concat($candidatosUnicos->map(fn ($c) => (string) $c->id_canonico));
    @endphp

    @if ($unificacionDoble)
        <form id="form-unificacion-cobol" method="GET" action="{{ route('archivo.unificacion.clientes.conflicto.revisar', $conflicto->id) }}"></form>
    @endif
    <div class="card mb-3">
        <div class="card-header fw-semibold">Comparación única — {{ $origenesTabla->count() }} origen(es) COBOL y {{ $candidatosUnicos->count() }} cliente(s)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-top mb-0" style="min-width:900px">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:160px">Dato</th>
                            @foreach ($origenesTabla as $o)
                                <th style="min-width:260px" data-identidad="cobol:{{ $o['conflicto']->id }}">
                                    ORIGEN COBOL — #{{ $o['conflicto']->id }}<br>
                                    <small>{{ $o['conflicto']->entidad_origen === 'PROPIETAR' ? 'PROPIETARIO' : $o['conflicto']->entidad_origen }}</small>
                                    <span class="badge {{ $o['conflicto']->estado_origen === 'ACTIVO' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $o['conflicto']->estado_origen }}</span>
                                </th>
                            @endforeach
                            @foreach ($candidatosUnicos as $cand)
                                <th style="min-width:260px" data-identidad="{{ $cand->id_canonico }}">
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
                        @if ($unificacionDoble)
                            @foreach (['principal'=>'Principal','absorber'=>'Absorber'] as $tipo=>$titulo)
                                <tr><th>{{ $titulo }}</th>
                                @foreach ($opcionesSeleccion as $op)
                                    <td class="text-center" data-identidad="{{ $op }}">
                                        <label class="d-inline-flex align-items-center gap-2 p-1 fw-semibold" style="cursor:pointer">
                                            <input class="form-check-input m-0" style="width:1.4rem;height:1.4rem" form="form-unificacion-cobol" type="checkbox" data-tipo="{{ $tipo }}" value="{{ $op }}" name="{{ $tipo === 'principal' ? 'seleccion_principal' : 'absorber[]' }}" @checked($tipo === 'principal' ? request('seleccion_principal') === $op : in_array($op, (array) request('absorber', []), true))>
                                            {{ $titulo }}
                                        </label>
                                    </td>
                                @endforeach
                                </tr>
                            @endforeach
                            <tr><td colspan="{{ 1 + $origenesTabla->count() + $candidatosUnicos->count() }}" class="p-2">
                                <button id="simular-seleccion" type="submit" form="form-unificacion-cobol" class="btn btn-primary" disabled>Simular unificación</button>
                                <span class="ms-2 text-muted">Un principal y una o más identidades a absorber. Las columnas no seleccionadas quedan excluidas.</span>
                            </td></tr>
                        @endif
                        <tr>
                            <th>Cuenta(s) COBOL</th>
                            @foreach ($origenesTabla as $o)
                                <td><strong>{{ $formatearCuentaCobol($o['conflicto']->clave_origen) }}</strong></td>
                            @endforeach
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
                                @foreach ($origenesTabla as $o)
                                    <td>{{ $valor($campo($o['datosOrigen'], $clave)) }}</td>
                                @endforeach
                                @foreach ($candidatosUnicos as $cand)
                                    <td>
                                        {{ $valor($campo($cand->cliente, $clave)) }}
                                        @if (! $esGrupo && in_array($clave, ['nombre','cuit','domicilio','telefono'], true))
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
                            @foreach ($origenesTabla as $o)
                                <td>{{ $valor(trim(($o['datosOrigen']['tipo_documento'] ?? '').' '.($o['datosOrigen']['numero_documento'] ?? ''))) }}</td>
                            @endforeach
                            @foreach ($candidatosUnicos as $cand)
                                @php $docCand = trim(($cand->cliente->tipo_documento ?? '').' '.($cand->cliente->numero_documento ?? '')); @endphp
                                <td>{{ $valor($docCand) }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <th>Rol</th>
                            @foreach ($origenesTabla as $o)
                                <td>{{ $o['conflicto']->entidad_origen === 'PROPIETAR' ? 'PROPIETARIO' : $o['conflicto']->entidad_origen }}</td>
                            @endforeach
                            @foreach ($candidatosUnicos as $cand)<td>{{ $cand->roles ?: '—' }}</td>@endforeach
                        </tr>
                        <tr>
                            <th>Cuenta propietario</th>
                            @foreach ($origenesTabla as $o)<td>{{ $formatearCuentaCobol($o['datosOrigen']['cuenta_propietario'] ?? '') }}</td>@endforeach
                            @foreach ($candidatosUnicos as $cand)<td>—</td>@endforeach
                        </tr>
                        <tr>
                            <th>Email</th>
                            @foreach ($origenesTabla as $o)<td>{{ $valor($o['datosOrigen']['email'] ?? null) }}</td>@endforeach
                            @foreach ($candidatosUnicos as $cand)<td>{{ $valor($cand->cliente->email ?? null) }}</td>@endforeach
                        </tr>
                        <tr>
                            <th>Estado</th>
                            @foreach ($origenesTabla as $o)<td>{{ $o['conflicto']->estado_origen ?: '—' }}</td>@endforeach
                            @foreach ($candidatosUnicos as $cand)<td>{{ $cand->cliente->activo === null ? 'SIN DEFINIR' : ($cand->cliente->activo ? 'ACTIVO' : 'INACTIVO') }}</td>@endforeach
                        </tr>
                        @if ($esGrupo)
                            <tr><th>Motivo de revisión</th>
                                @foreach ($origenesTabla as $o)<td class="small" style="overflow-wrap:anywhere">{{ $o['conflicto']->motivo }}</td>@endforeach
                                @foreach ($candidatosUnicos as $cand)<td>—</td>@endforeach
                            </tr>
                        @endif
                        <tr class="table-secondary"><th colspan="{{ 1 + $origenesTabla->count() + $candidatosUnicos->count() }}">Inmuebles</th></tr>
                        <tr>
                            <th>Inmuebles vinculados a las cuentas</th>
                            @foreach ($origenesTabla as $o)
                            <td>
                                @forelse ($o['inmueblesOrigen'] as $inmueble)
                                    <div class="border rounded p-2 mb-1 small">{{ $inmueble->domicilio }} <span class="text-muted">{{ $inmueble->relacion }}</span></div>
                                @empty
                                    <span class="text-muted">Sin inmuebles vinculados</span>
                                @endforelse
                            </td>
                            @endforeach
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
                        <tr class="table-secondary"><th colspan="{{ 1 + $origenesTabla->count() + $candidatosUnicos->count() }}">Contratos (por cuenta COBOL, como inquilino o propietario)</th></tr>
                        <tr>
                            <th>Contratos</th>
                            @foreach ($origenesTabla as $o)
                            <td>
                                @forelse ($o['contratosOrigen'] as $contrato)
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
                        <tr class="table-secondary"><th colspan="{{ 1 + $origenesTabla->count() + $candidatosUnicos->count() }}">Documentación reciente por cuenta y período</th></tr>
                        <tr>
                            <th>Liquidaciones / ARCA</th>
                            @foreach ($origenesTabla as $o)<td>@include('unificacion.clientes.partials.revision-documentos', ['filas' => $o['documentosOrigen']])</td>@endforeach
                            @foreach ($candidatosUnicos as $cand)
                                <td>@include('unificacion.clientes.partials.revision-documentos', ['filas' => $cand->documentos])</td>
                            @endforeach
                        </tr>
                        @if (! $esGrupo)
                        <tr class="table-light"><th>Decisión</th><td>Asociar sin unificar o mantener la identidad separada.</td>
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
                                            <button class="btn btn-sm btn-primary">{{ $esYaVinculado ? 'Validar sin unificar' : 'Asociar sin unificar a #'.$cand->id_canonico }}</button>
                                        </form>
                                    @elseif ($estadoPendiente)
                                        <span class="small text-warning">Ya vinculada a #{{ $clienteVinculadoCanonicoId }}</span>
                                    @else
                                        <span class="text-muted">Resuelto</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($unificacionDoble)
        <script>
        (() => {
            const controles = [...document.querySelectorAll('#form-unificacion-cobol ~ * input[data-tipo], input[form="form-unificacion-cobol"][data-tipo]')];
            const actualizar = () => {
                const p = controles.find(x => x.dataset.tipo === 'principal' && x.checked);
                document.querySelectorAll('[data-identidad]').forEach(td => {
                    const a = controles.find(x => x.dataset.tipo === 'absorber' && x.value === td.dataset.identidad && x.checked);
                    td.classList.toggle('table-success', !!p && p.value === td.dataset.identidad);
                    td.classList.toggle('table-danger', !!a);
                });
                document.getElementById('simular-seleccion').disabled = !p || !controles.some(x => x.dataset.tipo === 'absorber' && x.checked);
            };
            controles.forEach(x => x.addEventListener('change', () => {
                if (x.checked) controles.forEach(y => {
                    if (y !== x && (y.value === x.value || (x.dataset.tipo === 'principal' && y.dataset.tipo === 'principal'))) y.checked = false;
                });
                const panel = document.getElementById('simulacion-seleccion');
                if (panel) { panel.hidden = true; panel.querySelectorAll('button').forEach(b => b.disabled = true); }
                actualizar();
            }));
            actualizar();
        })();
        </script>
    @endif
    @isset($simulacionSeleccion)
        @php $sim = $simulacionSeleccion; @endphp
        <div id="simulacion-seleccion" class="card border-primary mb-3">
            <div class="card-header fw-semibold">Simulación de la selección</div>
            <div class="card-body">
                <p>Principal: <strong>{{ $etiquetaIdentidad($sim['principal']) }}</strong>.</p>
                <p>Absorber: <strong>{{ collect($sim['absorber'])->map($etiquetaIdentidad)->implode(', ') }}</strong>. {{ $sim['incluyeCobol'] ? 'Las asociaciones COBOL seleccionadas se registrarán para la próxima importación.' : 'Los orígenes COBOL seguirán pendientes.' }}</p>
                @php
                    $sinSeleccion = $opcionesSeleccion->reject(fn ($op) => $op === $sim['principal'] || in_array($op, $sim['absorber'], true));
                @endphp
                @if ($sinSeleccion->isNotEmpty())
                    <p class="text-muted">Sin unificar: <strong>{{ $sinSeleccion->map($etiquetaIdentidad)->implode(', ') }}</strong>.</p>
                @endif
                @if ($sim['destino'] === null)
                    <p class="text-info">Se creará un único cliente operativo nuevo, sin absorber ningún cliente existente.</p>
                @endif
                @foreach ($sim['bloqueos'] as $b)<div class="alert alert-danger">{{ $b }}</div>@endforeach
                <form method="POST" action="{{ route('archivo.unificacion.clientes.conflicto.resolver', $conflicto->id) }}" onsubmit="return confirm('¿Confirmar la selección y los datos maestros?')">
                    @csrf
                    <input type="hidden" name="decision" value="UNIFICAR_SELECCION">
                    <input type="hidden" name="seleccion_principal" value="{{ $sim['principal'] }}">
                    <input type="hidden" name="huella" value="{{ $sim['huella'] }}">
                    @foreach ($sim['absorber'] as $a)<input type="hidden" name="absorber[]" value="{{ $a }}">@endforeach
                    <p class="small text-muted mb-2">Elegí el valor definitivo de cada campo entre las identidades seleccionadas. Se priorizan los datos del principal y, si están vacíos, los datos disponibles de las identidades a absorber.</p>
                    <table class="table table-sm table-bordered"><thead><tr><th>Campo</th><th>Dato definitivo</th></tr></thead><tbody>
                    @php
                        $etiquetasMaestras = [
                            'nombre' => 'Nombre', 'cuit' => 'CUIT', 'documento' => 'Documento',
                            'domicilio' => 'Domicilio', 'codigo_postal' => 'Código postal',
                            'localidad' => 'Localidad', 'provincia' => 'Provincia',
                            'telefono' => 'Teléfono', 'telefono_alternativo' => 'Teléfono alternativo',
                            'condicion_iva' => 'Condición IVA', 'email' => 'Email',
                        ];
                    @endphp
                    @foreach ($sim['filas'] as $campo=>$opciones)
                        @php
                            $fuenteInicial = array_key_exists($sim['principal'], $opciones)
                                ? $sim['principal'] : array_key_first($opciones);
                        @endphp
                        <tr><th><label for="fuente-maestra-{{ $campo }}">{{ $etiquetasMaestras[$campo] ?? $campo }}</label></th><td>
                        <select id="fuente-maestra-{{ $campo }}" name="fuentes[{{ $campo }}]" class="form-select" required>
                        @foreach ($opciones as $f=>$valores)
                            <option value="{{ $f }}" @selected((string) $f === (string) $fuenteInicial)>{{ $etiquetaIdentidad($f) }} — {{ implode(' ', $valores) }}</option>
                        @endforeach
                        </select></td></tr>
                    @endforeach
                    </tbody></table>
                    <button class="btn btn-danger" @disabled($sim['bloqueos'] !== [])>Confirmar unificación</button>
                </form>
            </div>
        </div>
        <script>document.getElementById('simulacion-seleccion').scrollIntoView({block:'start'});</script>
    @endisset

    @if ($estadoPendiente && $esGrupo)
        <details class="card mb-3">
            <summary class="card-header fw-semibold">Resolver un origen por separado (sin unificar)</summary>
            <div class="card-body">
                @foreach ($origenesTabla as $o)
                    <div class="border-bottom pb-3 mb-3">
                        <strong>ORIGEN COBOL — #{{ $o['conflicto']->id }} · {{ $formatearCuentaCobol($o['conflicto']->clave_origen) }} · {{ $o['datosOrigen']['nombre'] ?? '—' }}</strong>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <form method="POST" action="{{ route('archivo.unificacion.clientes.conflicto.resolver', $o['conflicto']->id) }}" class="d-flex gap-2" onsubmit="return confirm('¿Asociar exclusivamente el origen #{{ $o['conflicto']->id }} sin unificar clientes?')">
                                @csrf
                                <input type="hidden" name="decision" value="ASOCIAR_EXISTENTE">
                                <input type="number" min="1" name="cliente_id" class="form-control form-control-sm" placeholder="ID cliente" required value="{{ $o['clienteVinculadoCanonicoId'] ?? '' }}" @readonly($o['clienteVinculadoCanonicoId'] !== null)>
                                <button class="btn btn-sm btn-outline-primary text-nowrap">Asociar #{{ $o['conflicto']->id }} sin unificar</button>
                            </form>
                            @if ($o['clienteVinculadoCanonicoId'] === null)
                                <form method="POST" action="{{ route('archivo.unificacion.clientes.conflicto.resolver', $o['conflicto']->id) }}" onsubmit="return confirm('¿Registrar el origen #{{ $o['conflicto']->id }} como persona distinta?')">
                                    @csrf
                                    <input type="hidden" name="decision" value="CREAR_SEPARADO">
                                    <button class="btn btn-sm btn-outline-secondary">Mantener separado #{{ $o['conflicto']->id }}</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </details>
    @endif
    @if ($estadoPendiente && ! $esGrupo)
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

    @if (! $esGrupo && $cantidadRevisionesRelacionadas > 1)
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
