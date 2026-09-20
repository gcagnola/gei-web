@extends('layouts.app')

@section('title', 'Importar')
@section('page-title', 'Importar')

@section('content')
    @if (session('estado'))
        <div class="alert alert-success" role="alert">
            {{ session('estado') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <header class="gei-page-heading">
        <h1>Importar y procesar período</h1>
        <p>Subí los archivos y procesá todo el período desde un único lugar: COBOL, GeI-Web, GeI-Core, liquidaciones e impuestos garantizados.</p>
    </header>

    <section class="gei-card p-3 mb-3">
        <form
            method="POST"
            action="{{ route('archivo.importar.store') }}"
            enctype="multipart/form-data"
            class="row g-2 align-items-end"
            data-import-form
        >
            @csrf

            <div class="col-12 col-lg-5">
                <label for="archivos" class="form-label fw-semibold mb-1">Archivos</label>
                <input
                    type="file"
                    id="archivos"
                    name="archivos[]"
                    class="form-control"
                    accept=".txt,.zip"
                    multiple
                    required
                >
            </div>

            <div class="col-6 col-lg-2">
                <label for="periodo_mes" class="form-label fw-semibold mb-1">Mes</label>
                <select id="periodo_mes" name="periodo_mes" class="form-select" required>
                    <option value="">Seleccionar mes...</option>
                    @foreach ($meses as $numero => $nombre)
                        <option value="{{ $numero }}" @selected((int) old('periodo_mes') === $numero)>
                            {{ $nombre }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-lg-2">
                <label for="periodo_anio" class="form-label fw-semibold mb-1">Año</label>
                <input
                    type="number"
                    id="periodo_anio"
                    name="periodo_anio"
                    min="2000"
                    max="2100"
                    value="{{ old('periodo_anio', now()->year) }}"
                    class="form-control"
                    required
                >
            </div>

            <div class="col-12 col-lg-3 d-grid">
                <button type="submit" class="btn gei-button gei-button--primary" data-import-submit>
                    Subir archivos
                </button>
            </div>
        </form>

        <p class="small text-muted mb-0 mt-2">
            Seleccioná el período al que corresponden los archivos.
            Antes de importarlos se verificará si contienen registros con fechas posteriores.
        </p>
    </section>

    <section class="gei-card p-4">
        <div class="gei-section-title mb-3">
            <div>
                <h2>Archivos por período</h2>
                <p>
                    Cada período conserva sus 4 archivos COBOL y 7 archivos obligatorios de
                    liquidación. dailoc2.SF.txt es opcional porque continúa dailoc.SF.txt.
                </p>
            </div>
        </div>

        @forelse ($periodos as $periodo)
            @php
                $estadoMigracion = array_merge([
                    'estado' => 'NO_DISPONIBLE',
                    'disponible' => false,
                    'mensaje' => 'La información de migración no está disponible.',
                ], $periodo['migracion'] ?? []);
                $estadoTablas = array_merge([
                    'estado' => 'PENDIENTE',
                    'mensaje' => 'Las tablas definitivas todavía no fueron actualizadas.',
                ], $periodo['tablas'] ?? []);
                $progresoPeriodo = $periodo['progreso'] ?? [];
                $incidenciasPeriodo = $periodo['incidencias'] ?? [];
                $fechasFuturas = $incidenciasPeriodo['fechas_futuras_inqctacte'] ?? [];
                $tieneResumen = ($progresoPeriodo['estado'] ?? null) === 'FINALIZADO';
                $tieneIncidencias = count($fechasFuturas) > 0;
            @endphp
            <div class="gei-periodo">
                <div class="gei-periodo__encabezado">
                    <div>
                        <strong>{{ $periodo['etiqueta'] }}</strong>
                        <span class="text-muted">({{ $periodo['periodo'] }})</span>
                    </div>
                    <div class="d-flex flex-wrap align-items-center justify-content-end gap-2">
                        @if ($periodo['completo'])
                            <span class="badge text-bg-success">Completo</span>
                        @else
                            <span class="badge text-bg-warning">
                                {{ $periodo['cantidad_obligatorios'] }}/{{ $periodo['total_obligatorios'] }}
                            </span>
                        @endif

                        @if ($periodo['cantidad_opcionales'] > 0)
                            <span class="text-muted small">
                                +{{ $periodo['cantidad_opcionales'] }} opcional(es)
                            </span>
                        @endif

                        @switch($estadoMigracion['estado'])
                            @case('OK')
                                <span class="badge text-bg-success">Crudos migrados</span>
                                @break
                            @case('MODIFICADO')
                                <span class="badge text-bg-warning">Archivos modificados</span>
                                @break
                            @case('ERROR')
                                <span class="badge text-bg-danger">Error al migrar</span>
                                @break
                            @default
                                <span class="badge text-bg-secondary">Pendiente de migrar</span>
                        @endswitch

                        @switch($estadoTablas['estado'])
                            @case('OK')
                                <span class="badge text-bg-success">Tablas actualizadas</span>
                                @break
                            @case('MODIFICADO')
                                <span class="badge text-bg-warning">Tablas desactualizadas</span>
                                @break
                            @case('ERROR')
                                <span class="badge text-bg-danger">Error en tablas</span>
                                @break
                            @case('PROCESANDO')
                                <span class="badge text-bg-info">Actualizando tablas</span>
                                @break
                            @default
                                <span class="badge text-bg-secondary">Tablas pendientes</span>
                        @endswitch

                        <form
                            method="POST"
                            action="{{ route('archivo.importar.migrar', $periodo['periodo']) }}"
                            class="d-inline"
                            data-migration-ui="v7"
                            data-periodo="{{ $periodo['periodo'] }}"
                            data-etiqueta="{{ $periodo['etiqueta'] }}"
                            data-progress-url="{{ \Illuminate\Support\Facades\URL::signedRoute('archivo.importar.progreso', ['periodo' => $periodo['periodo']]) }}"
                            onsubmit="return window.geiIniciarMigracion(this);"
                        >
                            @csrf
                            <button
                                type="submit"
                                class="btn btn-sm gei-button gei-button--primary"
                                data-migration-submit
@disabled(
                                    ! $estadoMigracion['disponible']
                                    || (($estadoTablas['estado'] ?? null) === 'PROCESANDO')
                                )
                                title="{{ $estadoMigracion['disponible']
                                    ? $estadoMigracion['mensaje']
                                    : 'El período debe tener los 11 archivos obligatorios para poder migrarse.' }}"
                            >
                                @if ($estadoMigracion['estado'] === 'OK' && $estadoTablas['estado'] === 'OK')
                                    Procesar nuevamente todo
                                @elseif (($estadoMigracion['estado'] ?? null) === 'MODIFICADO' || ($estadoTablas['estado'] ?? null) === 'MODIFICADO')
                                    Procesar nuevamente todo
                                @elseif ($estadoMigracion['estado'] === 'ERROR' || $estadoTablas['estado'] === 'ERROR')
                                    Reintentar
                                @else
                                    Procesar período completo
                                @endif
                            </button>
                        </form>

                    </div>
                </div>

                <details class="gei-periodo__detalle">
                    <summary class="gei-periodo__summary">
                        Ver archivos del período
                    </summary>

                    <div class="row g-4 gei-periodo__archivos">
                        <div class="col-xl-5">
                            <h3 class="h6 mb-2">COBOL</h3>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <tbody>
                                        @foreach ($periodo['archivos_cobol'] as $archivo)
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
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="col-xl-7">
                            <h3 class="h6 mb-2">Liquidaciones</h3>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <tbody>
                                        @foreach ($periodo['archivos_liquidaciones'] as $archivo)
                                            <tr>
                                                <td class="fw-semibold">
                                                    {{ $archivo['nombre'] }}
                                                    @if ($archivo['opcional'] ?? false)
                                                        <span class="text-muted fw-normal">(opcional)</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if ($archivo['existe'])
                                                        <span class="badge text-bg-success">Cargado</span>
                                                    @else
                                                        <span class="badge text-bg-secondary">Faltante</span>
                                                    @endif
                                                </td>
                                                <td>{{ $archivo['fecha'] ?? '—' }}</td>
                                                <td class="text-end">{{ $archivo['tamano'] ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </details>

                @if ($tieneResumen || $tieneIncidencias)
                    <details class="gei-periodo__detalle gei-periodo__resultado">
                        <summary class="gei-periodo__summary">
                            Ver resumen e incidencias
                            @if ($tieneIncidencias)
                                <span class="badge text-bg-warning">{{ count($fechasFuturas) }}</span>
                            @endif
                        </summary>

                        <div class="pb-3">
                            @if ($tieneResumen)
                                <h3 class="h6 mb-2">Último procesamiento</h3>
                                <p class="small mb-2">
                                    {{ $progresoPeriodo['resumen_mensaje'] ?? 'Proceso finalizado correctamente.' }}
                                </p>

                                @php
                                    $liq = $progresoPeriodo['resumen']['liquidaciones'] ?? [];
                                @endphp

                                @if ($liq !== [])
                                    <div class="small mb-3">
                                        <strong>Liquidaciones:</strong>
                                        {{ number_format((int) ($liq['detectadas'] ?? 0), 0, ',', '.') }}
                                        · <strong>PDF propietarios:</strong>
                                        {{ number_format((int) ($liq['pdf_propietarios'] ?? 0), 0, ',', '.') }}
                                        · <strong>PDF impuestos:</strong>
                                        {{ number_format((int) ($liq['pdf_impuestos'] ?? 0), 0, ',', '.') }}
                                    </div>
                                @endif
                            @endif

                            @if ($tieneIncidencias)
                                <h3 class="h6 mb-2">
                                    Incidencias informativas
                                    <span class="badge text-bg-warning">{{ count($fechasFuturas) }}</span>
                                </h3>
                                <p class="small text-muted mb-2">
                                    No bloquearon la importación. Se muestran para revisión.
                                </p>

                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Línea</th>
                                                <th>Cuenta COBOL</th>
                                                <th>Nº COBOL</th>
                                                <th>Fecha mov.</th>
                                                <th>Vencimiento futuro</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($fechasFuturas as $incidencia)
                                                @php
                                                    $fm = $incidencia['fecha_movimiento'] ?? null;
                                                    $fv = $incidencia['fecha_vencimiento'] ?? null;
                                                @endphp
                                                <tr>
                                                    <td>{{ $incidencia['linea'] ?? '—' }}</td>
                                                    <td>{{ $incidencia['cuenta_cobol'] ?? '—' }}</td>
                                                    <td>{{ $incidencia['numero_cobol'] ?? '—' }}</td>
                                                    <td>{{ $fm && strlen($fm) === 8 ? substr($fm, 6, 2).'/'.substr($fm, 4, 2).'/'.substr($fm, 0, 4) : ($fm ?? '—') }}</td>
                                                    <td>{{ $fv && strlen($fv) === 8 ? substr($fv, 6, 2).'/'.substr($fv, 4, 2).'/'.substr($fv, 0, 4) : ($fv ?? '—') }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @elseif ($tieneResumen)
                                <div class="small text-success">Sin incidencias informativas registradas.</div>
                            @endif
                        </div>
                    </details>
                @endif
            </div>
        @empty
            <div class="gei-empty-state gei-empty-state--large">
                Todavía no hay períodos cargados.
            </div>
        @endforelse
    </section>

    <div
        class="modal fade"
        id="importProgressModal"
        tabindex="-1"
        aria-labelledby="importProgressTitle"
        aria-hidden="true"
        data-bs-backdrop="static"
        data-bs-keyboard="false"
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="importProgressTitle">
                        Importando archivos
                    </h2>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
                        <div>
                            <strong data-import-progress-count>Preparando carga...</strong>
                            <p class="mb-0 text-muted" data-import-progress-file>
                                El navegador está enviando los archivos. La pantalla se actualizará al finalizar.
                            </p>
                        </div>
                    </div>

                    <div class="progress mt-4" role="progressbar" aria-label="Carga en curso">
                        <div
                            class="progress-bar progress-bar-striped progress-bar-animated"
                            style="width: 0%"
                            data-import-progress-bar
                        >
                            0%
                        ></div>
                    </div>

                    <div class="small text-muted mt-2" data-import-progress-detail>
                        Esperando inicio de carga.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <style>
        @keyframes gei-migration-progress {
            from { transform: translateX(-100%); }
            to { transform: translateX(250%); }
        }

        #migrationProgressOverlay {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15, 23, 42, .62);
        }

        #migrationProgressOverlay.gei-visible {
            display: flex;
        }

        .gei-migration-panel {
            width: min(100%, 520px);
            padding: 26px;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .28);
        }

        .gei-migration-track {
            height: 14px;
            margin-top: 22px;
            overflow: hidden;
            border-radius: 999px;
            background: #eadced;
        }

        .gei-migration-bar {
            width: 42%;
            height: 100%;
            border-radius: inherit;
            background: var(--gei-primary, #962aa8);
            animation: gei-migration-progress 1.35s ease-in-out infinite;
        }

        .gei-migration-status {
            min-height: 66px;
            padding: 12px 14px;
            border: 1px solid var(--gei-border);
            border-radius: 10px;
            background: #f8f9fa;
        }
    </style>

    <div
        id="migrationProgressOverlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="migrationProgressTitle"
        aria-live="polite"
    >
        <div class="gei-migration-panel">
            <div class="d-flex align-items-center gap-3">
                <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
                <div class="flex-grow-1">
                    <h2 class="h5 mb-1" id="migrationProgressTitle">Migrando y actualizando PostgreSQL</h2>
                    <p class="mb-0 text-muted" data-migration-period>Preparando el período...</p>
                </div>
                <strong class="text-nowrap small" data-migration-elapsed>0 s</strong>
            </div>

            <div class="mt-4">
                <div class="d-flex justify-content-between gap-3 mb-1">
                    <strong data-migration-stage>Preparando...</strong>
                    <span class="text-muted small" data-migration-percent>0%</span>
                </div>
                <div class="progress" role="progressbar" aria-label="Progreso de migración">
                    <div
                        class="progress-bar progress-bar-striped progress-bar-animated"
                        style="width: 2%"
                        data-migration-real-bar
                    ></div>
                </div>
            </div>

            <div class="gei-migration-status mt-3">
                <div data-migration-detail>Preparando la migración...</div>
                <div class="small text-muted mt-2" data-migration-file style="display:none;"></div>
                <div class="small text-muted" data-migration-records style="display:none;"></div>
            </div>

            <div class="small text-muted mt-3">
                Se ejecuta todo el período automáticamente. Los conflictos de clientes/inmuebles quedan para revisión posterior y no requieren intervención en este paso.
            </div>

            <div class="d-flex justify-content-end mt-3" data-migration-finished-actions style="display:none !important;">
                <button type="button" class="btn btn-primary" data-migration-close>
                    Cerrar y actualizar
                </button>
            </div>
        </div>
    </div>

@endsection

@push('styles')
    <style>
        .gei-periodo {
            border-top: 1px solid var(--gei-border);
        }

        .gei-periodo__encabezado {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 14px 0;
        }

        .gei-periodo__summary {
            display: flex;
            align-items: center;
            gap: 10px;
            width: max-content;
            padding: 0 0 12px;
            color: var(--gei-primary);
            cursor: pointer;
            list-style: none;
        }

        .gei-periodo__summary::-webkit-details-marker {
            display: none;
        }

        .gei-periodo__summary::before {
            width: 8px;
            height: 8px;
            flex: 0 0 8px;
            border-right: 2px solid var(--gei-primary);
            border-bottom: 2px solid var(--gei-primary);
            content: '';
            transform: rotate(45deg) translateY(-2px);
            transition: transform .18s ease;
        }

        .gei-periodo__detalle[open] .gei-periodo__summary::before {
            transform: rotate(225deg) translate(-1px, -1px);
        }

        .gei-periodo__archivos {
            padding-bottom: 12px;
        }

        @media (max-width: 767.98px) {
            .gei-periodo__encabezado {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
@endpush


@push('scripts')
<script>
    window.geiIniciarMigracion = function (form) {
        if (form.dataset.enviando === '1') {
            return false;
        }

        form.dataset.enviando = '1';

        const overlay = document.getElementById('migrationProgressOverlay');
        const submit = form.querySelector('[data-migration-submit]');
        const periodo = form.dataset.periodo || '';
        const etiqueta = form.dataset.etiqueta || periodo;
        const startedAt = Date.now();

        const elPeriodo = overlay?.querySelector('[data-migration-period]');
        const elElapsed = overlay?.querySelector('[data-migration-elapsed]');
        const elStage = overlay?.querySelector('[data-migration-stage]');
        const elDetail = overlay?.querySelector('[data-migration-detail]');
        const elFile = overlay?.querySelector('[data-migration-file]');
        const elRecords = overlay?.querySelector('[data-migration-records]');
        const elPercent = overlay?.querySelector('[data-migration-percent]');
        const elBar = overlay?.querySelector('[data-migration-real-bar]');
        const elFinishedActions = overlay?.querySelector('[data-migration-finished-actions]');
        const elClose = overlay?.querySelector('[data-migration-close]');

        if (elPeriodo) elPeriodo.textContent = 'Período: ' + etiqueta;
        if (elElapsed) elElapsed.textContent = '0 s';
        if (elStage) elStage.textContent = 'Preparando...';
        if (elDetail) elDetail.textContent = 'Iniciando proceso...';
        if (elPercent) elPercent.textContent = '0%';
        if (elBar) {
            elBar.style.width = '2%';
            elBar.classList.add('progress-bar-animated');
        }
        if (elFinishedActions) {
            elFinishedActions.style.setProperty('display', 'none', 'important');
        }

        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Procesando...';
        }

        if (overlay) {
            overlay.classList.add('gei-visible');
            overlay.style.display = 'flex';
        }

        document.body.style.overflow = 'hidden';

        const etiquetasEtapa = {
            PREPARANDO: 'Preparando',
            MIGRACION_CRUDOS: 'Migrando archivos a PostgreSQL',
            TABLAS_GEI_WEB: 'Actualizando GeI-Web',
            CLIENTES: 'Actualizando clientes',
            INMUEBLES: 'Actualizando inmuebles',
            CONTRATOS: 'Actualizando contratos',
            CUENTAS_CORRIENTES: 'Actualizando cuentas corrientes',
            GEI_CORE: 'Actualizando GeI-Core',
            GEI_CORE_PREPARAR: 'Preparando GeI-Core',
            GEI_CORE_LIMPIAR: 'Preparando período en GeI-Core',
            GEI_CORE_PERSONAS_FUENTE: 'Leyendo personas desde PostgreSQL',
            GEI_CORE_PERSONAS: 'Procesando personas',
            GEI_CORE_CONTRATOS: 'Procesando inmuebles y contratos',
            GEI_CORE_CUENTAS: 'Procesando cuentas corrientes',
            GEI_CORE_CONFLICTOS: 'Generando controles',
            GEI_CORE_COMPLETO: 'GeI-Core completado',
            LIQUIDACIONES_PROPIETARIOS: 'Liquidaciones e impuestos',
            VALIDAR_DAILOC: 'Validando impuestos garantizados',
            LIQUIDACIONES_IMPORTAR: 'Importando liquidaciones',
            LIQUIDACIONES_REPARTOS: 'Sincronizando repartos',
            LIQUIDACIONES_PDF: 'Generando PDF de propietarios',
            IMPUESTOS_GARANTIZADOS: 'Generando impuestos garantizados',
            LIQUIDACIONES_COMPLETO: 'Liquidaciones completadas',
            COMPLETO: 'Finalizado'
        };

        const actualizarPantalla = function (datos) {
            if (!datos || typeof datos !== 'object') return;

            const etapa = datos.etapa || 'PROCESANDO';
            const porcentaje = Number.isFinite(Number(datos.porcentaje))
                ? Math.max(0, Math.min(100, Number(datos.porcentaje)))
                : null;

            if (elStage) {
                elStage.textContent = etiquetasEtapa[etapa] || etapa.replaceAll('_', ' ');
            }

            if (elDetail && datos.detalle) {
                elDetail.textContent = datos.detalle;
            }

            if (porcentaje !== null) {
                if (elPercent) elPercent.textContent = Math.round(porcentaje) + '%';
                if (elBar) elBar.style.width = Math.max(2, porcentaje) + '%';
            }

            if (elFile) {
                if (datos.archivo) {
                    elFile.textContent = 'Archivo: ' + datos.archivo;
                    elFile.style.display = '';
                } else {
                    elFile.style.display = 'none';
                }
            }

            if (elRecords) {
                if (datos.procesados !== null && datos.procesados !== undefined &&
                    datos.total !== null && datos.total !== undefined) {
                    elRecords.textContent =
                        'Registros: ' + Number(datos.procesados).toLocaleString('es-AR') +
                        ' / ' + Number(datos.total).toLocaleString('es-AR');
                    elRecords.style.display = '';
                } else if (datos.total !== null && datos.total !== undefined) {
                    elRecords.textContent =
                        'Registros fuente: ' + Number(datos.total).toLocaleString('es-AR');
                    elRecords.style.display = '';
                } else {
                    elRecords.style.display = 'none';
                }
            }
        };

        const elapsedTimer = window.setInterval(function () {
            if (elElapsed) {
                elElapsed.textContent = Math.floor((Date.now() - startedAt) / 1000) + ' s';
            }
        }, 1000);

        let pollingActivo = true;
        let procesoTerminado = false;

        const consultarProgreso = async function () {
            while (pollingActivo) {
                try {
                    const progressUrl = form.dataset.progressUrl;

                    if (!progressUrl) {
                        throw new Error('No está configurada la URL de progreso.');
                    }

                    const respuesta = await fetch(progressUrl, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        cache: 'no-store'
                    });

                    if (respuesta.ok) {
                        const progreso = await respuesta.json();

                        // El POST principal puede haber terminado mientras este GET
                        // estaba en vuelo. No permitir que una respuesta vieja tape
                        // el mensaje final (especialmente un error).
                        if (!pollingActivo || procesoTerminado) {
                            break;
                        }

                        if ((progreso.estado || '').toUpperCase() === 'ERROR') {
                            procesoTerminado = true;
                            pollingActivo = false;
                            window.clearInterval(elapsedTimer);

                            if (elStage) elStage.textContent = 'Error';
                            if (elDetail) {
                                elDetail.textContent = progreso.detalle || 'El proceso terminó con error.';
                                elDetail.style.whiteSpace = 'pre-wrap';
                            }
                            if (elPercent) elPercent.textContent = 'Error';
                            if (elBar) {
                                elBar.classList.remove('progress-bar-animated');
                                elBar.style.width = '100%';
                            }
                            if (submit) {
                                submit.disabled = false;
                                submit.textContent = 'Reintentar';
                            }
                            form.dataset.enviando = '0';
                            break;
                        }

                        actualizarPantalla(progreso);
                    }
                } catch (_) {
                    // El POST principal sigue siendo la fuente de verdad.
                }

                await new Promise(resolve => window.setTimeout(resolve, 1200));
            }
        };

        consultarProgreso();

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(async response => {
            const datos = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(datos.message || 'No se pudo completar la migración.');
            }

            procesoTerminado = true;
            pollingActivo = false;
            window.clearInterval(elapsedTimer);
            actualizarPantalla({
                etapa: 'COMPLETO',
                detalle: datos.message || 'Proceso finalizado.',
                porcentaje: 100
            });

            if (elBar) {
                elBar.classList.remove('progress-bar-animated');
            }

            if (elFinishedActions) {
                elFinishedActions.style.setProperty('display', 'flex', 'important');
            }

            if (elClose) {
                elClose.onclick = function () {
                    window.location.href = datos.redirect || window.location.pathname;
                };
            }

            if (submit) {
                submit.disabled = false;
                submit.textContent = 'Procesar nuevamente todo';
            }

            form.dataset.enviando = '0';
        })
        .catch(error => {
            procesoTerminado = true;
            pollingActivo = false;
            window.clearInterval(elapsedTimer);

            if (elStage) elStage.textContent = 'Error';
            if (elDetail) {
                elDetail.textContent = error.message;
                elDetail.style.whiteSpace = 'pre-wrap';
            }
            if (elPercent) elPercent.textContent = 'Error';
            if (elBar) {
                elBar.classList.remove('progress-bar-animated');
                elBar.style.width = '100%';
            }

            if (submit) {
                submit.disabled = false;
                submit.textContent = 'Reintentar';
            }

            form.dataset.enviando = '0';
        });

        return false;
    };
</script>
@endpush
