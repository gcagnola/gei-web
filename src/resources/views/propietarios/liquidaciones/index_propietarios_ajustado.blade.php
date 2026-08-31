@extends('layouts.app')

@section('title', 'Liquidaciones de Propietarios')
@section('page-title', 'Liquidaciones de Propietarios')

@section('content')
    <header class="gei-page-heading">
        <h1>Liquidaciones de Propietarios</h1>
        <p>Importación estructurada en PostgreSQL y generación de PDF desde las tablas definitivas.</p>
    </header>

    @if (session('estado'))
        <div class="alert alert-success">{{ session('estado') }}</div>
    @endif

    @if (session('advertencia'))
        <div class="alert alert-warning">{{ session('advertencia') }}</div>
    @endif

    @if ($errors->has('liquidaciones'))
        <div class="alert alert-danger">{{ $errors->first('liquidaciones') }}</div>
    @endif

    @if ($errors->has('envios'))
        <div class="alert alert-danger">{{ $errors->first('envios') }}</div>
    @endif

    @if ($errors->has('arca'))
        <div class="alert alert-danger">{{ $errors->first('arca') }}</div>
    @endif

    @if ($liquidaciones === null)
        <div class="alert alert-warning mb-0">
            Falta crear las tablas de liquidaciones. Ejecutá las migraciones antes de procesar un período.
        </div>
    @else
        <section class="gei-card mb-4">
            <div class="row g-4 align-items-end">
                <div class="col-lg-7">
                    <h2 class="h5 mb-2">Procesar período</h2>
                    <p class="text-muted mb-0">
                        Interpreta las liquidaciones ya cargadas, guarda cabeceras e ítems y genera en un mismo proceso
                        los PDF de liquidación y de impuestos garantizados. La repetición no duplica datos ni números.
                    </p>
                </div>
                <div class="col-lg-5">
                    <form
                        id="form-procesar-liquidaciones"
                        method="POST"
                        action="{{ route('propietarios.liquidaciones.procesar') }}"
                        class="row g-2"
                    >
                        @csrf
                        <div class="col-sm-7">
                            <label for="periodo" class="form-label">Período</label>
                            <select id="periodo" name="periodo" class="form-select" required>
                                @foreach ($periodosDisponibles as $periodoDisponible)
                                    <option
                                        value="{{ $periodoDisponible }}"
                                        data-numero-inicial="{{ $numerosIniciales[$periodoDisponible] ?? $numeroInicialPredeterminado }}"
                                        @selected($periodoDisponible === $periodo)
                                    >
                                        {{ substr($periodoDisponible, 4, 2) }}/{{ substr($periodoDisponible, 0, 4) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-5">
                            <label for="numero_inicial" class="form-label">Primer número</label>
                            <input
                                id="numero_inicial"
                                name="numero_inicial"
                                type="number"
                                min="1"
                                value="{{ old('numero_inicial', $numeroSugerido) }}"
                                class="form-control"
                            >
                        </div>
                        <div class="col-12 d-grid">
                            <button
                                id="btn-procesar-liquidaciones"
                                type="submit"
                                class="btn btn-primary"
                                @disabled($periodosDisponibles->isEmpty())
                            >
                                <span class="texto-boton">Guardar datos y generar todos los PDF</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        @if ($ultimoProceso)
            <section class="gei-card mb-4 py-3">
                <div class="d-flex flex-wrap justify-content-between gap-3">
                    <div>
                        <span class="text-muted">Último proceso</span>
                        <strong class="ms-2">{{ $ultimoProceso->estado }}</strong>
                    </div>
                    <div class="small text-muted">
                        Detectadas {{ number_format($ultimoProceso->detectadas, 0, ',', '.') }} ·
                        insertadas {{ number_format($ultimoProceso->insertadas, 0, ',', '.') }} ·
                        actualizadas {{ number_format($ultimoProceso->actualizadas, 0, ',', '.') }} ·
                        PDF {{ number_format($ultimoProceso->pdf_generados, 0, ',', '.') }}
                    </div>
                </div>
            </section>
        @endif

        <section class="gei-card">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                <div>
                    <h2 class="h5 mb-1">Liquidaciones guardadas</h2>
                    <p class="text-muted mb-0">
                        Buscá por uno o varios datos dentro del período seleccionado. Los comprobantes ARCA se leen directamente del archivo histórico AAAA/MM.
                    </p>
                </div>

                <div class="d-flex flex-wrap gap-2 align-items-start justify-content-end">
                    <form
                        id="form-enviar-todas-liquidaciones"
                        method="POST"
                        action="{{ route('propietarios.liquidaciones.enviar-emails') }}"
                        data-periodo="{{ $periodo }}"
                    >
                    @csrf
                    <input type="hidden" name="periodo" value="{{ $periodo }}">
                    <input type="hidden" name="nombre" value="{{ $filtros['nombre'] }}">
                    <input type="hidden" name="cuenta" value="{{ $filtros['cuenta'] }}">
                    <input type="hidden" name="comprobante" value="{{ $filtros['comprobante'] }}">

                    <div class="input-group">
                        <select
                            id="documentos-envio-masivo"
                            name="documentos"
                            class="form-select"
                            @disabled(! $registroDocumentosDisponible)
                        >
                            <option value="TODOS" data-cantidad="{{ $cantidadEnviablesPorDocumento['TODOS'] }}">
                                Todo: liquidación + impuestos + ARCA ({{ number_format($cantidadEnviablesPorDocumento['TODOS'], 0, ',', '.') }})
                            </option>
                            <option value="AMBOS" data-cantidad="{{ $cantidadEnviablesPorDocumento['AMBOS'] }}">
                                Liquidación + impuestos ({{ number_format($cantidadEnviablesPorDocumento['AMBOS'], 0, ',', '.') }})
                            </option>
                            <option value="LIQUIDACION" data-cantidad="{{ $cantidadEnviablesPorDocumento['LIQUIDACION'] }}">
                                Liquidaciones ({{ number_format($cantidadEnviablesPorDocumento['LIQUIDACION'], 0, ',', '.') }})
                            </option>
                            <option value="IMPUESTOS" data-cantidad="{{ $cantidadEnviablesPorDocumento['IMPUESTOS'] }}">
                                Impuestos ({{ number_format($cantidadEnviablesPorDocumento['IMPUESTOS'], 0, ',', '.') }})
                            </option>
                            <option value="ARCA" data-cantidad="{{ $cantidadEnviablesPorDocumento['ARCA'] }}">
                                Comprobantes ARCA ({{ number_format($cantidadEnviablesPorDocumento['ARCA'], 0, ',', '.') }})
                            </option>
                        </select>
                        <button
                            id="btn-enviar-todas-liquidaciones"
                            type="submit"
                            class="btn btn-success"
                            @disabled(! $registroDocumentosDisponible || $cantidadEnviablesPorDocumento['TODOS'] === 0)
                        >
                            Enviar todos por email
                        </button>
                    </div>
                        <div class="small text-muted mt-1 text-end">
                            Se aplican el período, los filtros activos y el tipo de documento elegido.
                        </div>
                    </form>
                </div>
            </div>

            <form method="GET" action="{{ route('propietarios.liquidaciones.index') }}" class="row g-3 align-items-end mb-4">
                <div class="col-sm-6 col-lg-2">
                    <label for="filtro-periodo" class="form-label">Período</label>
                    <select id="filtro-periodo" name="periodo" class="form-select">
                        @foreach ($periodosDisponibles as $periodoDisponible)
                            <option value="{{ $periodoDisponible }}" @selected($periodoDisponible === $periodo)>
                                {{ substr($periodoDisponible, 4, 2) }}/{{ substr($periodoDisponible, 0, 4) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label for="filtro-nombre" class="form-label">Nombre</label>
                    <input
                        id="filtro-nombre"
                        name="nombre"
                        type="search"
                        maxlength="160"
                        value="{{ $filtros['nombre'] }}"
                        class="form-control"
                        placeholder="Apellido o nombre"
                    >
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label for="filtro-cuenta" class="form-label">Cuenta N°</label>
                    <input
                        id="filtro-cuenta"
                        name="cuenta"
                        type="search"
                        maxlength="30"
                        value="{{ $filtros['cuenta'] }}"
                        class="form-control"
                        placeholder="1202/04663/08"
                    >
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="filtro-comprobante" class="form-label">Compte. N°</label>
                    <input
                        id="filtro-comprobante"
                        name="comprobante"
                        type="search"
                        maxlength="20"
                        value="{{ $filtros['comprobante'] }}"
                        class="form-control"
                        placeholder="363836"
                    >
                </div>
                <div class="col-lg-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">Buscar</button>
                    @if ($hayFiltros)
                        <a
                            href="{{ route('propietarios.liquidaciones.index', ['periodo' => $periodo]) }}"
                            class="btn btn-outline-secondary"
                        >Limpiar</a>
                    @endif
                </div>
            </form>

            <div class="d-flex justify-content-between align-items-center mb-2 small text-muted">
                <span>
                    {{ number_format($liquidaciones->total(), 0, ',', '.') }}
                    {{ $liquidaciones->total() === 1 ? 'liquidación encontrada' : 'liquidaciones encontradas' }}
                </span>
                @if ($hayFiltros)
                    <span>Filtros activos</span>
                @endif
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Número</th>
                            <th>Cuenta</th>
                            <th>Propietario</th>
                            <th>Comprobante</th>
                            <th class="text-end">Total</th>
                            <th>Control</th>
                            <th>Documentos</th>
                            <th>Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($liquidaciones as $liquidacion)
                            @php
                                $emailValido = filter_var($liquidacion->email_destino, FILTER_VALIDATE_EMAIL) !== false;
                                $tienePdf = (bool) ($liquidacion->pdf_disponible ?? false);
                                $tienePdfImpuestos = (bool) ($liquidacion->impuestos_pdf_disponible ?? false);
                                $comprobantesArca = $liquidacion->comprobantes_arca ?? collect();
                                $cantidadArca = (int) ($liquidacion->comprobantes_arca_cantidad ?? 0);
                                $ultimoEstadoEnvio = $liquidacion->ultimo_envio_estado ?? null;
                                $ultimoDocumentosEnvio = $liquidacion->ultimo_envio_documentos ?? null;
                                $envioPendiente = in_array($ultimoEstadoEnvio, ['PENDIENTE', 'PROCESANDO'], true);
                                $claseEstadoEnvio = match ($ultimoEstadoEnvio) {
                                    'ENVIADO' => 'success',
                                    'ERROR' => 'danger',
                                    'PENDIENTE', 'PROCESANDO' => 'warning',
                                    default => 'secondary',
                                };
                                $etiquetaUltimoDocumento = match ($ultimoDocumentosEnvio) {
                                    'IMPUESTOS' => 'Impuestos',
                                    'AMBOS' => 'Liq. + impuestos',
                                    'ARCA' => 'ARCA',
                                    'TODOS' => 'Todos',
                                    'LIQUIDACION' => 'Liquidación',
                                    default => null,
                                };
                            @endphp
                            <tr>
                                <td>{{ sprintf('%04d-%08d', 0, $liquidacion->numero_interno) }}</td>
                                <td>{{ $liquidacion->cuenta_impresa }}</td>
                                <td>{{ $liquidacion->propietario }}</td>
                                <td>{{ $liquidacion->tipo }} {{ $liquidacion->comprobante }}</td>
                                <td class="text-end">$ {{ number_format($liquidacion->total, 2, ',', '.') }}</td>
                                <td>
                                    @php
                                        $controlEstadoVisible = $liquidacion->control_estado === 'AJUSTADO_DESDE_PLIQLOC'
                                            ? 'AJUSTADO'
                                            : $liquidacion->control_estado;
                                    @endphp
                                    <span
                                        class="badge gei-control-badge text-bg-{{ $liquidacion->control_estado === 'OK' ? 'success' : 'warning' }}"
                                        title="{{ $liquidacion->control_estado }}"
                                    >
                                        {{ $controlEstadoVisible }}
                                    </span>
                                </td>
                                <td style="min-width: 350px;">
                                    <div class="d-flex flex-wrap gap-1 align-items-start">
                                        @if ($tienePdf)
                                            <a
                                                class="btn btn-sm btn-outline-primary"
                                                href="{{ route('propietarios.liquidaciones.ver', $liquidacion->id) }}"
                                                target="_blank"
                                                rel="noopener"
                                            >Liquidación</a>
                                        @else
                                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Liquidación</button>
                                        @endif

                                        @if ($tienePdfImpuestos)
                                            <a
                                                class="btn btn-sm btn-outline-primary"
                                                href="{{ route('propietarios.liquidaciones.impuestos.ver', $liquidacion->id) }}"
                                                target="_blank"
                                                rel="noopener"
                                            >Imp. garantizados</a>
                                        @else
                                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Imp. garantizados</button>
                                        @endif

                                        @if ($cantidadArca === 1)
                                            @php
                                                $comprobanteArca = $comprobantesArca->first();
                                            @endphp
                                            <a
                                                class="btn btn-sm btn-outline-primary"
                                                href="{{ route('comprobantes-arca.ver', ['periodo' => $periodo, 'archivo' => $comprobanteArca->nombre_archivo]) }}"
                                                target="_blank"
                                                rel="noopener"
                                                title="{{ $comprobanteArca->nombre_archivo }}"
                                            >
                                                {{ $comprobanteArca->tipo_codigo }}-{{ $comprobanteArca->punto_venta }}-{{ $comprobanteArca->numero_comprobante }}
                                            </a>
                                        @elseif ($cantidadArca > 1)
                                            <div class="dropdown">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-primary dropdown-toggle"
                                                    data-bs-toggle="dropdown"
                                                    aria-expanded="false"
                                                >
                                                    ARCA ({{ $cantidadArca }})
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end" style="min-width: 300px;">
                                                    @foreach ($comprobantesArca as $comprobanteArca)
                                                        <li>
                                                            <a
                                                                class="dropdown-item d-flex justify-content-between gap-3"
                                                                href="{{ route('comprobantes-arca.ver', ['periodo' => $periodo, 'archivo' => $comprobanteArca->nombre_archivo]) }}"
                                                                target="_blank"
                                                                rel="noopener"
                                                                title="{{ $comprobanteArca->nombre_archivo }}"
                                                            >
                                                                <span>
                                                                    {{ $comprobanteArca->tipo_codigo }}
                                                                    {{ $comprobanteArca->punto_venta }}-{{ $comprobanteArca->numero_comprobante }}
                                                                </span>
                                                                <small class="text-muted">Ver</small>
                                                            </a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @else
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-secondary"
                                                disabled
                                                title="No hay comprobantes ARCA para esta cuenta y período."
                                            >
                                                ARCA
                                            </button>
                                        @endif
                                    </div>
                                </td>
                                <td style="min-width: 315px;">
                                    @if ($emailValido)
                                        <div class="small text-break mb-1">{{ $liquidacion->email_destino }}</div>

                                        <form
                                            method="POST"
                                            action="{{ route('propietarios.liquidaciones.enviar-email', $liquidacion->id) }}"
                                            class="form-enviar-liquidacion"
                                            data-email="{{ $liquidacion->email_destino }}"
                                            data-periodo="{{ $periodo }}"
                                            data-numero="{{ sprintf('%04d-%08d', 0, $liquidacion->numero_interno) }}"
                                        >
                                            @csrf
                                            <div class="d-flex flex-wrap gap-1">
                                                <button
                                                    type="submit"
                                                    name="documentos"
                                                    value="LIQUIDACION"
                                                    class="btn btn-sm btn-outline-success"
                                                    @disabled(! $registroDocumentosDisponible || ! $tienePdf || $envioPendiente)
                                                >Liquidación</button>
                                                <button
                                                    type="submit"
                                                    name="documentos"
                                                    value="IMPUESTOS"
                                                    class="btn btn-sm btn-outline-success"
                                                    @disabled(! $registroDocumentosDisponible || ! $tienePdfImpuestos || $envioPendiente)
                                                >Impuestos</button>
                                                <button
                                                    type="submit"
                                                    name="documentos"
                                                    value="AMBOS"
                                                    class="btn btn-sm btn-outline-success"
                                                    @disabled(! $registroDocumentosDisponible || ! $tienePdf || ! $tienePdfImpuestos || $envioPendiente)
                                                >Liq. + Imp.</button>
                                                <button
                                                    type="submit"
                                                    name="documentos"
                                                    value="ARCA"
                                                    class="btn btn-sm btn-outline-success"
                                                    @disabled(! $registroDocumentosDisponible || $cantidadArca === 0 || $envioPendiente)
                                                >ARCA</button>
                                                <button
                                                    type="submit"
                                                    name="documentos"
                                                    value="TODOS"
                                                    class="btn btn-sm btn-success"
                                                    @disabled(! $registroDocumentosDisponible || ! $tienePdf || ! $tienePdfImpuestos || $cantidadArca === 0 || $envioPendiente)
                                                >Todos</button>
                                            </div>
                                        </form>

                                        @if ($envioPendiente)
                                            <span class="badge text-bg-warning mt-1">En cola</span>
                                        @elseif ($ultimoEstadoEnvio)
                                            <span class="badge text-bg-{{ $claseEstadoEnvio }} mt-1">
                                                {{ $ultimoEstadoEnvio }}@if ($etiquetaUltimoDocumento) · {{ $etiquetaUltimoDocumento }}@endif
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-muted">Sin email asociado</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    @if ($hayFiltros)
                                        No se encontraron liquidaciones con los datos ingresados.
                                    @else
                                        Todavía no hay liquidaciones guardadas para este período.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($liquidaciones->hasPages())
                <div class="mt-3">{{ $liquidaciones->links() }}</div>
            @endif
        </section>
    @endif

    <div
        id="modal-procesando-liquidaciones"
        class="gei-processing-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="titulo-procesando-liquidaciones"
        aria-describedby="detalle-procesando-liquidaciones"
        aria-hidden="true"
    >
        <div class="gei-processing-modal__panel">
            <div class="spinner-border text-primary gei-processing-modal__spinner" role="status" aria-hidden="true"></div>
            <h2 id="titulo-procesando-liquidaciones" class="h4 mb-2">Procesando</h2>
            <p id="detalle-procesando-liquidaciones" class="text-muted mb-3">
                La operación está en curso.
            </p>

            <div class="gei-processing-modal__summary mb-3">
                <div>
                    <span>Período</span>
                    <strong id="modal-periodo-liquidaciones">—</strong>
                </div>
                <div>
                    <span id="modal-etiqueta-secundaria">Detalle</span>
                    <strong id="modal-numero-liquidaciones">—</strong>
                </div>
                <div>
                    <span>Tiempo transcurrido</span>
                    <strong id="modal-tiempo-liquidaciones">00:00</strong>
                </div>
            </div>

            <ul id="modal-tareas-liquidaciones" class="gei-processing-modal__tasks text-start mb-3">
                <li>Leer las liquidaciones almacenadas en PostgreSQL.</li>
                <li>Guardar cabeceras e ítems del período.</li>
                <li>Generar los PDF de liquidación de propietarios.</li>
                <li>Generar los PDF de impuestos garantizados.</li>
            </ul>

            <p class="small text-muted mb-0">
                Este proceso puede tardar varios minutos. No cierres ni recargues esta página.
            </p>
        </div>
    </div>

    <style>
        .gei-control-badge {
            font-size: .65rem;
            font-weight: 600;
            padding: .22rem .38rem;
            letter-spacing: .01em;
            white-space: nowrap;
        }

        .gei-processing-modal {
            position: fixed;
            inset: 0;
            z-index: 2050;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
            background: rgba(15, 23, 42, .58);
            backdrop-filter: blur(2px);
        }

        .gei-processing-modal.is-visible {
            display: flex;
        }

        .gei-processing-modal__panel {
            width: min(100%, 520px);
            padding: 2rem;
            border-radius: .8rem;
            background: #fff;
            box-shadow: 0 1.5rem 4rem rgba(15, 23, 42, .28);
            text-align: center;
        }

        .gei-processing-modal__spinner {
            width: 3rem;
            height: 3rem;
            margin-bottom: 1rem;
        }

        .gei-processing-modal__summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            overflow: hidden;
            border: 1px solid #dee2e6;
            border-radius: .55rem;
            background: #f8f9fa;
        }

        .gei-processing-modal__summary > div {
            padding: .75rem .6rem;
        }

        .gei-processing-modal__summary > div + div {
            border-left: 1px solid #dee2e6;
        }

        .gei-processing-modal__summary span,
        .gei-processing-modal__summary strong {
            display: block;
        }

        .gei-processing-modal__summary span {
            margin-bottom: .2rem;
            color: #6c757d;
            font-size: .78rem;
        }

        .gei-processing-modal__tasks {
            padding: .9rem 1rem .9rem 2.2rem;
            border-radius: .55rem;
            background: #f1f5f9;
            color: #334155;
        }

        .gei-processing-modal__tasks li + li {
            margin-top: .35rem;
        }

        @media (max-width: 575.98px) {
            .gei-processing-modal__panel {
                padding: 1.5rem 1rem;
            }

            .gei-processing-modal__summary {
                grid-template-columns: 1fr;
            }

            .gei-processing-modal__summary > div + div {
                border-top: 1px solid #dee2e6;
                border-left: 0;
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const periodo = document.getElementById('periodo');
            const numeroInicial = document.getElementById('numero_inicial');
            const formulario = document.getElementById('form-procesar-liquidaciones');
            const botonProcesar = document.getElementById('btn-procesar-liquidaciones');
            const modal = document.getElementById('modal-procesando-liquidaciones');
            const modalTitulo = document.getElementById('titulo-procesando-liquidaciones');
            const modalDetalle = document.getElementById('detalle-procesando-liquidaciones');
            const modalPeriodo = document.getElementById('modal-periodo-liquidaciones');
            const modalEtiquetaSecundaria = document.getElementById('modal-etiqueta-secundaria');
            const modalNumero = document.getElementById('modal-numero-liquidaciones');
            const modalTiempo = document.getElementById('modal-tiempo-liquidaciones');
            const modalTareas = document.getElementById('modal-tareas-liquidaciones');
            const formularioEnvioMasivo = document.getElementById('form-enviar-todas-liquidaciones');
            const botonEnvioMasivo = document.getElementById('btn-enviar-todas-liquidaciones');
            const documentosEnvioMasivo = document.getElementById('documentos-envio-masivo');
            let temporizadorModal = null;

            const etiquetaDocumentos = (documentos) => ({
                LIQUIDACION: 'liquidación de propietario',
                IMPUESTOS: 'impuestos garantizados',
                AMBOS: 'liquidación e impuestos garantizados',
                ARCA: 'comprobantes ARCA',
                TODOS: 'liquidación, impuestos garantizados y comprobantes ARCA',
            }[documentos] || 'documentos');

            const mostrarModal = ({titulo, detalle, periodoTexto, etiquetaSecundaria, valorSecundario, tareas}) => {
                if (!modal) {
                    return;
                }

                modalTitulo.textContent = titulo;
                modalDetalle.textContent = detalle;
                modalPeriodo.textContent = periodoTexto || '—';
                modalEtiquetaSecundaria.textContent = etiquetaSecundaria || 'Detalle';
                modalNumero.textContent = valorSecundario || '—';
                modalTiempo.textContent = '00:00';
                modalTareas.innerHTML = (tareas || [])
                    .map((tarea) => `<li>${tarea}</li>`)
                    .join('');

                modal.classList.add('is-visible');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';

                const inicio = Date.now();
                if (temporizadorModal !== null) {
                    window.clearInterval(temporizadorModal);
                }
                temporizadorModal = window.setInterval(() => {
                    const segundos = Math.floor((Date.now() - inicio) / 1000);
                    const horas = Math.floor(segundos / 3600);
                    const minutos = Math.floor((segundos % 3600) / 60);
                    const restoSegundos = segundos % 60;
                    modalTiempo.textContent = horas > 0
                        ? `${String(horas).padStart(2, '0')}:${String(minutos).padStart(2, '0')}:${String(restoSegundos).padStart(2, '0')}`
                        : `${String(minutos).padStart(2, '0')}:${String(restoSegundos).padStart(2, '0')}`;
                }, 1000);
            };

            const actualizarEnvioMasivo = () => {
                if (!documentosEnvioMasivo || !botonEnvioMasivo) {
                    return;
                }
                const opcion = documentosEnvioMasivo.options[documentosEnvioMasivo.selectedIndex];
                const cantidad = Number.parseInt(opcion?.dataset.cantidad || '0', 10);
                botonEnvioMasivo.disabled = documentosEnvioMasivo.disabled || cantidad <= 0;
            };

            documentosEnvioMasivo?.addEventListener('change', actualizarEnvioMasivo);
            actualizarEnvioMasivo();

            formularioEnvioMasivo?.addEventListener('submit', (evento) => {
                const opcion = documentosEnvioMasivo?.options[documentosEnvioMasivo.selectedIndex];
                const cantidad = Number.parseInt(opcion?.dataset.cantidad || '0', 10);
                const documentos = documentosEnvioMasivo?.value || 'TODOS';
                const mensaje = `Se programará el envío de ${etiquetaDocumentos(documentos)} a ${cantidad} destinatario${cantidad === 1 ? '' : 's'}. ¿Continuar?`;

                if (!window.confirm(mensaje)) {
                    evento.preventDefault();
                    return;
                }

                if (botonEnvioMasivo) {
                    botonEnvioMasivo.disabled = true;
                    botonEnvioMasivo.textContent = 'Programando envíos…';
                }

                mostrarModal({
                    titulo: 'Programando envíos por email',
                    detalle: `Se están agregando a la cola los envíos de ${etiquetaDocumentos(documentos)}.`,
                    periodoTexto: formularioEnvioMasivo.dataset.periodo || '—',
                    etiquetaSecundaria: 'Documentos',
                    valorSecundario: opcion?.textContent?.trim() || documentos,
                    tareas: [
                        'Validar email y archivos disponibles.',
                        'Registrar cada envío.',
                        'Agregar los trabajos a la cola de emails.',
                    ],
                });
            });

            document.querySelectorAll('.form-enviar-liquidacion').forEach((formularioEnvio) => {
                formularioEnvio.addEventListener('submit', (evento) => {
                    const email = formularioEnvio.dataset.email || 'el email asociado';
                    const submitter = evento.submitter;
                    const documentos = submitter?.value || 'LIQUIDACION';

                    if (!window.confirm(`¿Enviar ${etiquetaDocumentos(documentos)} a ${email}?`)) {
                        evento.preventDefault();
                        return;
                    }

                    let hidden = formularioEnvio.querySelector('input[type="hidden"][name="documentos"]');
                    if (!hidden) {
                        hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'documentos';
                        formularioEnvio.appendChild(hidden);
                    }
                    hidden.value = documentos;

                    formularioEnvio.querySelectorAll('button[type="submit"]').forEach((boton) => {
                        boton.disabled = true;
                    });

                    mostrarModal({
                        titulo: 'Programando email',
                        detalle: `Se está preparando el envío de ${etiquetaDocumentos(documentos)} a ${email}.`,
                        periodoTexto: formularioEnvio.dataset.periodo || '—',
                        etiquetaSecundaria: 'Liquidación',
                        valorSecundario: formularioEnvio.dataset.numero || '—',
                        tareas: [
                            'Validar los PDF seleccionados.',
                            'Registrar el envío.',
                            'Agregar el email a la cola.',
                        ],
                    });
                });
            });

            if (periodo && numeroInicial) {
                periodo.addEventListener('change', () => {
                    const opcion = periodo.options[periodo.selectedIndex];
                    numeroInicial.value = opcion?.dataset.numeroInicial ?? '';
                });
            }

            formulario?.addEventListener('submit', (evento) => {
                if (formulario.dataset.procesando === '1') {
                    evento.preventDefault();
                    return;
                }

                if (!formulario.checkValidity()) {
                    return;
                }

                formulario.dataset.procesando = '1';
                const opcion = periodo?.options[periodo.selectedIndex];

                if (botonProcesar) {
                    botonProcesar.disabled = true;
                    botonProcesar.querySelector('.texto-boton').textContent = 'Procesando…';
                }

                mostrarModal({
                    titulo: 'Procesando liquidaciones',
                    detalle: 'Se están guardando los datos y generando los dos tipos de PDF.',
                    periodoTexto: opcion?.textContent?.trim() || periodo?.value || '—',
                    etiquetaSecundaria: 'Primer número',
                    valorSecundario: numeroInicial?.value || 'Automático',
                    tareas: [
                        'Validar DAILOC antes de modificar el período.',
                        'Guardar cabeceras e ítems de liquidaciones.',
                        'Generar PDF de liquidaciones de propietarios.',
                        'Generar PDF de impuestos garantizados.',
                    ],
                });
            });
        });
    </script>
@endsection
