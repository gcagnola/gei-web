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
        <p>Archivos COBOL vigentes y liquidaciones agrupadas por período.</p>
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
                    <option value="">Detectar</option>
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
    </section>

    <div class="row g-4">
        <div class="col-xl-5">
            <section class="gei-card p-4 h-100">
                <div class="gei-section-title mb-3">
                    <div>
                        <h2>Archivos COBOL</h2>
                        <p>Estos cuatro archivos se reemplazan en cada carga.</p>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Archivo</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                                <th class="text-end">Tamaño</th>
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
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="col-xl-7">
            <section class="gei-card p-4 h-100">
                <div class="gei-section-title mb-3">
                    <div>
                        <h2>Liquidaciones por período</h2>
                        <p>Los archivos mensuales se agrupan por mes y año.</p>
                    </div>
                </div>

                @forelse ($periodos as $periodo)
                    <details class="gei-periodo">
                        <summary class="gei-periodo__summary">
                            <div>
                                <strong>{{ $periodo['etiqueta'] }}</strong>
                                <span class="text-muted">({{ $periodo['periodo'] }})</span>
                            </div>
                            <span class="text-muted">
                                {{ $periodo['cantidad'] }} archivos
                            </span>
                        </summary>

                        <div class="table-responsive gei-periodo__archivos">
                            <table class="table table-sm align-middle mb-0">
                                <tbody>
                                    @foreach ($periodo['archivos'] as $archivo)
                                        <tr>
                                            <td>{{ $archivo['nombre'] }}</td>
                                            <td>{{ $archivo['fecha'] ?? '—' }}</td>
                                            <td class="text-end">{{ $archivo['tamano'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @empty
                    <div class="gei-empty-state gei-empty-state--large">
                        Todavía no hay liquidaciones cargadas.
                    </div>
                @endforelse
            </section>
        </div>
    </div>

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
@endsection

@push('styles')
    <style>
        .gei-periodo {
            border-top: 1px solid var(--gei-border);
        }

        .gei-periodo__summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 14px 0;
            cursor: pointer;
            list-style: none;
        }

        .gei-periodo__summary::-webkit-details-marker {
            display: none;
        }

        .gei-periodo__summary::after {
            width: 8px;
            height: 8px;
            flex: 0 0 8px;
            border-right: 2px solid var(--gei-primary);
            border-bottom: 2px solid var(--gei-primary);
            content: '';
            transform: rotate(45deg) translateY(-2px);
            transition: transform .18s ease;
        }

        .gei-periodo[open] .gei-periodo__summary::after {
            transform: rotate(225deg) translate(-1px, -1px);
        }

        .gei-periodo__archivos {
            padding-bottom: 12px;
        }
    </style>
@endpush
