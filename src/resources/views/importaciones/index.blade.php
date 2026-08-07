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
        <h1>Importar archivos</h1>
        <p>Archivos COBOL y liquidaciones conservados juntos en cada período.</p>
    </header>

    <section class="gei-card p-4 mb-4">
        <form
            method="POST"
            action="{{ route('archivo.importar.store') }}"
            enctype="multipart/form-data"
            class="row g-3 align-items-end"
            data-import-form
        >
            @csrf

            <div class="col-lg-5">
                <label for="archivos" class="form-label fw-semibold">Archivos</label>
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

            <div class="col-sm-4 col-lg-2">
                <label for="periodo_mes" class="form-label fw-semibold">Mes</label>
                <select id="periodo_mes" name="periodo_mes" class="form-select">
                    <option value="">Detectar desde los archivos</option>
                    @foreach ($meses as $numero => $nombre)
                        <option value="{{ $numero }}" @selected((int) old('periodo_mes') === $numero)>
                            {{ $nombre }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-sm-4 col-lg-2">
                <label for="periodo_anio" class="form-label fw-semibold">Año</label>
                <input
                    type="number"
                    id="periodo_anio"
                    name="periodo_anio"
                    min="2000"
                    max="2100"
                    value="{{ old('periodo_anio') }}"
                    class="form-control"
                    placeholder="{{ now()->year }}"
                >
            </div>

            <div class="col-sm-4 col-lg-3 d-grid">
                <button type="submit" class="btn gei-button gei-button--primary" data-import-submit>
                    Subir archivos
                </button>
            </div>
        </form>

        <p class="small text-muted mb-0 mt-3">
            El período se aplica a todos los archivos de la carga. En COBOL se obtiene de la última
            fecha válida de CTACTEPRO, INQCTACTE o PROPIETAR. INQUILINO no permite detectarlo por sí solo.
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
                            onsubmit="
                                if (this.dataset.enviando === '1') {
                                    return false;
                                }

                                this.dataset.enviando = '1';

                                const overlay = document.getElementById('migrationProgressOverlay');
                                const periodo = overlay
                                    ? overlay.querySelector('[data-migration-period]')
                                    : null;
                                const elapsed = overlay
                                    ? overlay.querySelector('[data-migration-elapsed]')
                                    : null;
                                const submit = this.querySelector('[data-migration-submit]');
                                const startedAt = Date.now();
                                const form = this;

                                if (periodo) {
                                    periodo.textContent = 'Período: ' + (this.dataset.etiqueta || this.dataset.periodo || '');
                                }

                                if (elapsed) {
                                    elapsed.textContent = '0 s';
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

                                window.geiMigrationElapsedTimer = window.setInterval(function () {
                                    if (elapsed) {
                                        elapsed.textContent = Math.floor((Date.now() - startedAt) / 1000) + ' s';
                                    }
                                }, 1000);

                                window.setTimeout(function () {
                                    HTMLFormElement.prototype.submit.call(form);
                                }, 150);

                                return false;
                            "
                        >
                            @csrf
                            <button
                                type="submit"
                                class="btn btn-sm gei-button gei-button--primary"
                                data-migration-submit
                                @disabled(! $estadoMigracion['disponible'])
                                title="{{ $estadoMigracion['disponible']
                                    ? $estadoMigracion['mensaje']
                                    : 'El período debe tener los 11 archivos obligatorios para poder migrarse.' }}"
                            >
                                @if ($estadoMigracion['estado'] === 'OK' && $estadoTablas['estado'] === 'OK')
                                    Procesar nuevamente
                                @elseif ($estadoMigracion['estado'] === 'ERROR' || $estadoTablas['estado'] === 'ERROR')
                                    Reintentar
                                @else
                                    Migrar y actualizar tablas
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
                <div>
                    <h2 class="h5 mb-1" id="migrationProgressTitle">Migrando y actualizando PostgreSQL</h2>
                    <p class="mb-0 text-muted" data-migration-period>
                        Preparando el período...
                    </p>
                </div>
            </div>

            <div class="gei-migration-track" aria-label="Migración en curso">
                <div class="gei-migration-bar"></div>
            </div>

            <div class="d-flex justify-content-between gap-3 small text-muted mt-3">
                <span>Cargando datos crudos y actualizando clientes, inmuebles, contratos y cuentas corrientes. No cierres esta ventana.</span>
                <strong class="text-nowrap" data-migration-elapsed>0 s</strong>
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
