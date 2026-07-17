@extends('layouts.app')

@section('title', 'Actualizar DB')
@section('page-title', 'Actualizar DB')

@section('content')
    @if (session('estado'))
        <div class="alert alert-success" role="alert">
            {{ session('estado') }}
        </div>
    @endif

    @if ($errorEjecucion)
        <div class="alert alert-danger" role="alert">
            <strong>No se pudo ejecutar la validación.</strong>
            <div>{{ $errorEjecucion['mensaje'] ?? 'Error desconocido.' }}</div>
        </div>
    @endif

    <header class="gei-page-heading">
        <h1>Actualizar DB</h1>
        <p>Validación histórica de archivos KNG/GeI contra el resultado generado por Visual FoxPro.</p>
    </header>

    <div class="alert alert-info" role="alert">
        <strong>Esta operación no modifica clientes, movimientos ni liquidaciones.</strong>
        La validación compara el staging reconstruido contra la importación histórica de Fox y registra
        sólo auditoría en tablas <span class="font-monospace">web_</span>.
    </div>

    <div class="row g-4">
        <div class="col-xl-6">
            <section class="gei-card p-4 h-100">
                <div class="gei-section-title mb-3">
                    <div>
                        <h2>Archivos COBOL almacenados</h2>
                        <p>En esta etapa solo se validan PROPIETAR.TXT e INQUILINO.TXT.</p>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Archivo</th>
                                <th>Existencia</th>
                                <th>Fecha</th>
                                <th class="text-end">Tamaño</th>
                                <th>SHA-256</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($archivosCobol as $archivo)
                                <tr>
                                    <td class="fw-semibold">{{ $archivo['nombre'] }}</td>
                                    <td>
                                        @if ($archivo['existe'])
                                            <span class="badge text-bg-success">Cargado</span>
                                        @else
                                            <span class="badge text-bg-secondary">Faltante</span>
                                        @endif
                                    </td>
                                    <td>{{ $archivo['fecha'] ?? '—' }}</td>
                                    <td class="text-end">{{ $archivo['tamano'] ?? '—' }}</td>
                                    <td class="font-monospace small">
                                        @if ($archivo['sha256'])
                                            <span title="{{ $archivo['sha256'] }}">{{ substr($archivo['sha256'], 0, 12) }}…</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge {{ $archivo['estado_clase'] }}">
                                            {{ $archivo['estado'] }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <form method="POST" action="{{ route('archivo.actualizar-db.validar-lote') }}">
                        @csrf
                        <button type="submit" class="btn gei-button gei-button--primary">
                            Validar lote completo
                        </button>
                    </form>
                    <form method="POST" action="{{ route('archivo.actualizar-db.importar-lote') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary">
                            Importar staging web_
                        </button>
                    </form>
                    <form method="POST" action="{{ route('archivo.actualizar-db.reconciliar-lote') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary">
                            Reconciliar
                        </button>
                    </form>
                    <form method="POST" action="{{ route('archivo.actualizar-db.simular-persistencia-postgresql') }}" class="d-flex flex-wrap gap-2 align-items-center">
                        @csrf
                        <select name="componente" class="form-select form-select-sm w-auto" aria-label="Componente de validación">
                            <option value="completo">Completo</option>
                            <option value="clientes">Clientes</option>
                            <option value="kng">KNG / DBF</option>
                            <option value="cuentas">Cuentas</option>
                            <option value="liquidaciones">Liquidaciones</option>
                            <option value="items">Ítems</option>
                            <option value="dailoc">Dailoc</option>
                        </select>
                        <button type="submit" class="btn btn-outline-dark">
                            Validar archivos contra importación Fox
                        </button>
                    </form>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-2">
                    <form method="POST" action="{{ route('archivo.actualizar-db.validar-cobol') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            Validar PROPIETAR/INQUILINO
                        </button>
                    </form>
                    <form method="POST" action="{{ route('archivo.actualizar-db.comparar-cobol') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            Comparar PROPIETAR/INQUILINO
                        </button>
                    </form>
                </div>
            </section>
        </div>

        <div class="col-xl-6">
            <section class="gei-card p-4 h-100">
                <div class="gei-section-title mb-3">
                    <div>
                        <h2>Configuración de ejecución</h2>
                        <p>Rutas internas del contenedor usadas para invocar el importador Python.</p>
                    </div>
                </div>

                <dl class="gei-detail-list mt-0">
                    <div><dt>Python</dt><dd>{{ $configuracion['python'] }}</dd></div>
                    <div><dt>Importador</dt><dd>{{ $configuracion['importador'] }}</dd></div>
                    <div><dt>Base entrada</dt><dd>{{ $configuracion['base_dir'] }}</dd></div>
                    <div><dt>COBOL</dt><dd>{{ $configuracion['cobol'] }}</dd></div>
                    <div><dt>Repositorio</dt><dd>{{ $configuracion['repositorio_id'] }}</dd></div>
                    <div><dt>Timeout</dt><dd>{{ $configuracion['timeout'] }} segundos</dd></div>
                </dl>
            </section>
        </div>
    </div>

    @if ($resultado)
        @php
            $json = $resultado['json'] ?? [];
            $resumenArchivos = $json['resumen_archivos'] ?? $json['archivos'] ?? [];
            $resumenArchivos = is_array($resumenArchivos) ? $resumenArchivos : [];
            $modo = $json['modo'] ?? $json['estado'] ?? 'solo-validar';
            $tituloResultado = $modo === 'comparar' || $modo === 'reconciliado'
                ? 'Resultado de comparación'
                : 'Resultado de validación';
            $erroresFormato = collect($resumenArchivos)
                ->flatMap(fn ($archivo) => $archivo['errores_detalle'] ?? [])
                ->values();
            $advertenciasDetalle = collect($json['advertencias_detalle'] ?? $json['advertencias'] ?? []);
            $persistencia = $json['resultado_persistencia'] ?? null;
            $validacionFox = $json['resultado_validacion_fox'] ?? null;
        @endphp

        <section class="gei-card p-4 mt-4">
            <div class="gei-section-title mb-3">
                <div>
                    <h2>{{ $tituloResultado }}</h2>
                    <p>
                        Modo: {{ $modo }}.
                        @if (! empty($json['importacion_id']))
                            Importación #{{ $json['importacion_id'] }}.
                        @endif
                        Código de salida: {{ $resultado['exit_code'] ?? '—' }}.
                        Escritura PostgreSQL: {{ ($json['escritura_postgresql'] ?? false) ? 'sí' : 'no' }}.
                    </p>
                </div>
            </div>

            @if (! ($json['escritura_postgresql'] ?? false))
                <div class="alert alert-info" role="alert">
                    La operación se ejecutó en modo {{ $modo === 'comparar' ? 'comparación' : 'validación' }}.
                    No se realizaron cambios en PostgreSQL.
                </div>
            @else
                <div class="alert alert-success" role="alert">
                    La operación escribió únicamente en tablas <span class="font-monospace">web_</span> de control y staging.
                    No se modificaron tablas heredadas de negocio.
                </div>
            @endif

            @if ($advertenciasDetalle->isNotEmpty())
                <div class="alert alert-warning" role="alert">
                    <strong>Advertencias</strong>
                    <ul class="mb-0 mt-2">
                        @foreach ($advertenciasDetalle as $advertencia)
                            <li>
                                @if (is_array($advertencia))
                                    {{ $advertencia['mensaje'] ?? json_encode($advertencia, JSON_UNESCAPED_UNICODE) }}
                                @else
                                    {{ $advertencia }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="row g-3 mb-4">
                @foreach ([
                    'archivos' => 'Archivos',
                    'registros_leidos' => 'Leídos',
                    'registros_validos' => 'Válidos',
                    'registros_interpretados' => 'Interpretados',
                    'advertencias' => 'Advertencias',
                    'errores' => 'Errores',
                ] as $clave => $etiqueta)
                    <div class="col-6 col-md-4 col-xl-2">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-muted">{{ $etiqueta }}</div>
                            <div class="fs-5 fw-semibold">
                                @if (isset($json[$clave]) && is_array($json[$clave]))
                                    {{ count($json[$clave]) }}
                                @else
                                    {{ $json[$clave] ?? '—' }}
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Archivo</th>
                            <th class="text-end">Registros</th>
                            <th class="text-end">Válidos</th>
                            <th class="text-end">Errores</th>
                            <th>Encoding</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($resumenArchivos as $nombre => $detalle)
                            <tr>
                                <td class="fw-semibold">{{ $nombre }}</td>
                                <td class="text-end">{{ $detalle['registros'] ?? '—' }}</td>
                                <td class="text-end">{{ $detalle['validos'] ?? '—' }}</td>
                                <td class="text-end">{{ $detalle['errores'] ?? '—' }}</td>
                                <td>{{ $detalle['encoding'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($erroresFormato->isNotEmpty())
                <div class="mt-4">
                    <h3 class="fs-6">Errores de formato detectados</h3>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Archivo</th>
                                    <th class="text-end">Línea</th>
                                    <th>Error</th>
                                    <th>Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($erroresFormato as $error)
                                    <tr>
                                        <td>{{ $error['archivo'] ?? '—' }}</td>
                                        <td class="text-end">{{ $error['linea'] ?? '—' }}</td>
                                        <td>{{ $error['mensaje'] ?? '—' }}</td>
                                        <td class="font-monospace small text-break">{{ $error['valor'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if (is_array($persistencia))
                <div class="mt-4">
                    <h3 class="fs-6">Persistencia PostgreSQL simulada</h3>
                    <div class="alert alert-info" role="alert">
                        La simulación ejecutó la persistencia dentro de una transacción y la revirtió al finalizar.
                        Para confirmar escritura definitiva usá el comando Artisan con <span class="font-monospace">--confirmar</span>.
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Destino</th>
                                    <th class="text-end">Insertados</th>
                                    <th class="text-end">Actualizados</th>
                                    <th class="text-end">Omitidos</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ([
                                    'propietarios' => 'Propietarios',
                                    'inquilinos' => 'Inquilinos',
                                    'movimientos_propietarios' => 'Mov. propietarios',
                                    'movimientos_inquilinos' => 'Mov. inquilinos',
                                    'liquidaciones' => 'Liquidaciones',
                                    'items_liquidaciones' => 'Ítems liquidaciones',
                                ] as $clave => $etiqueta)
                                    @php($fila = $persistencia[$clave] ?? [])
                                    <tr>
                                        <td>{{ $etiqueta }}</td>
                                        <td class="text-end">{{ $fila['insertados'] ?? $fila['insertadas'] ?? 0 }}</td>
                                        <td class="text-end">{{ $fila['actualizados'] ?? $fila['actualizadas'] ?? 0 }}</td>
                                        <td class="text-end">{{ $fila['omitidos'] ?? $fila['omitidas'] ?? 0 }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if (is_array($validacionFox))
                <div class="mt-4">
                    <h3 class="fs-6">Validación contra importación Fox</h3>
                    <div class="alert alert-info" role="alert">
                        Esta validación no modifica tablas heredadas. Los resultados se registran en
                        <span class="font-monospace">web_validaciones_kng_gei</span> y
                        <span class="font-monospace">web_validaciones_kng_gei_detalles</span>.
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Componente</th>
                                    <th class="text-end">Fuente</th>
                                    <th class="text-end">Exactas</th>
                                    <th class="text-end">Diferencias</th>
                                    <th class="text-end">No encontrados</th>
                                    <th class="text-end">Ambiguos</th>
                                    <th class="text-end">Errores</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (($validacionFox['componentes'] ?? []) as $componente => $fila)
                                    <tr>
                                        <td class="fw-semibold">{{ str_replace('_', ' ', $componente) }}</td>
                                        <td class="text-end">{{ $fila['registros_fuente'] ?? 0 }}</td>
                                        <td class="text-end">{{ $fila['coincidencias_exactas'] ?? 0 }}</td>
                                        <td class="text-end">{{ $fila['coincidencias_con_diferencias'] ?? 0 }}</td>
                                        <td class="text-end">{{ $fila['no_encontrados'] ?? 0 }}</td>
                                        <td class="text-end">{{ $fila['ambiguos'] ?? 0 }}</td>
                                        <td class="text-end">{{ $fila['errores_de_interpretacion'] ?? 0 }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <details class="mt-3">
                <summary class="fw-semibold">Salida técnica JSON</summary>
                <pre class="bg-light border rounded p-3 mt-3 mb-0 small"><code>{{ json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</code></pre>
            </details>
        </section>

        @if (isset($json['comparacion_postgresql']))
            @php($comparacion = $json['comparacion_postgresql'])

            <section class="gei-card p-4 mt-4">
                <div class="gei-section-title mb-3">
                    <div>
                        <h2>Comparación con PostgreSQL</h2>
                        <p>La operación se ejecutó en modo comparación. No se realizaron cambios en PostgreSQL.</p>
                    </div>
                </div>

                <div class="row g-3">
                    @foreach ([
                        'existentes_sin_cambios' => 'Sin cambios',
                        'existentes_con_diferencias' => 'Con diferencias',
                        'nuevos' => 'Nuevos',
                        'ambiguos' => 'Ambiguos',
                        'errores' => 'Errores',
                        'omitidos_por_baja_antigua' => 'Omitidos por baja',
                    ] as $clave => $etiqueta)
                        <div class="col-sm-6 col-lg-2">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-muted">{{ $etiqueta }}</div>
                                <div class="fs-4 fw-semibold">{{ $comparacion[$clave] ?? 0 }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if (($comparacion['ambiguos_resueltos_por_id_inq'] ?? 0) > 0)
                    <div class="alert alert-success mt-4 mb-0" role="alert">
                        {{ $comparacion['ambiguos_resueltos_por_id_inq'] }}
                        coincidencias múltiples por documento fueron resueltas usando el
                        <span class="font-monospace">id_inq</span> heredado ya registrado en PostgreSQL.
                    </div>
                @endif

                @if (! empty($comparacion['motivos_resumen']))
                    <div class="mt-4">
                        <h3 class="fs-6">Resumen de diferencias y ambiguos</h3>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Motivo</th>
                                        <th class="text-end">Cantidad</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($comparacion['motivos_resumen'] as $motivo => $cantidad)
                                        <tr>
                                            <td>{{ $motivo }}</td>
                                            <td class="text-end">{{ $cantidad }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @if (! empty($comparacion['cruces_resumen']))
                    <div class="mt-4">
                        <h3 class="fs-6">Cruce con otros archivos COBOL y liquidaciones</h3>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Motivo</th>
                                        <th>Cuenta corriente / liquidaciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($comparacion['cruces_resumen'] as $motivo => $cruces)
                                        <tr>
                                            <td>{{ $motivo }}</td>
                                            <td>
                                                @foreach ($cruces as $cruce => $cantidad)
                                                    <span class="badge text-bg-light border me-1 mb-1">
                                                        {{ $cruce }}: {{ $cantidad }}
                                                    </span>
                                                @endforeach
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @if (! empty($comparacion['muestras']))
                    <div class="table-responsive mt-4">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Estado</th>
                                    <th>ID Inq.</th>
                                    <th>ID Prop.</th>
                                    <th>Nombre</th>
                                    <th>Detalle</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($comparacion['muestras'] as $muestra)
                                    <tr>
                                        <td><span class="badge text-bg-light">{{ $muestra['estado'] ?? '—' }}</span></td>
                                        <td>{{ $muestra['id_inq'] ?? '—' }}</td>
                                        <td>{{ $muestra['id_prop'] ?? '—' }}</td>
                                        <td>{{ $muestra['nombre'] ?? '—' }}</td>
                                        <td class="small">
                                            @if (! empty($muestra['diferencias']))
                                                {{ implode(' / ', $muestra['diferencias']) }}
                                            @else
                                                {{ $muestra['motivo'] ?? '—' }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    @endif

    @if ($errorEjecucion && (($errorEjecucion['stderr'] ?? '') !== '' || ($errorEjecucion['stdout'] ?? '') !== ''))
        <section class="gei-card p-4 mt-4">
            <div class="gei-section-title mb-3">
                <div>
                    <h2>Detalle técnico del error</h2>
                    <p>Código de salida: {{ $errorEjecucion['exit_code'] ?? '—' }}</p>
                </div>
            </div>

            @if (($errorEjecucion['stderr'] ?? '') !== '')
                <h3 class="fs-6">stderr</h3>
                <pre class="bg-light border rounded p-3 small"><code>{{ $errorEjecucion['stderr'] }}</code></pre>
            @endif

            @if (($errorEjecucion['stdout'] ?? '') !== '')
                <h3 class="fs-6">stdout</h3>
                <pre class="bg-light border rounded p-3 small mb-0"><code>{{ $errorEjecucion['stdout'] }}</code></pre>
            @endif
        </section>
    @endif
@endsection
