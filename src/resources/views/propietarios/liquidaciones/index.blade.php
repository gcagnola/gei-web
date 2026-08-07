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
                        Interpreta las liquidaciones ya cargadas, guarda cabeceras e ítems y genera nuevamente
                        todos los PDF leyendo esos registros de PostgreSQL. La repetición no duplica datos ni números.
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
                                <span class="texto-boton">Guardar datos y generar PDF</span>
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
                        Buscá por uno o varios datos dentro del período seleccionado.
                    </p>
                </div>

                <form
                    id="form-enviar-todas-liquidaciones"
                    method="POST"
                    action="{{ route('propietarios.liquidaciones.enviar-emails') }}"
                    data-cantidad="{{ $cantidadEnviables }}"
                >
                    @csrf
                    <input type="hidden" name="periodo" value="{{ $periodo }}">
                    <input type="hidden" name="nombre" value="{{ $filtros['nombre'] }}">
                    <input type="hidden" name="cuenta" value="{{ $filtros['cuenta'] }}">
                    <input type="hidden" name="comprobante" value="{{ $filtros['comprobante'] }}">
                    <button
                        id="btn-enviar-todas-liquidaciones"
                        type="submit"
                        class="btn btn-success"
                        @disabled(! $registroEnviosDisponible || $cantidadEnviables === 0)
                    >
                        Enviar todos por email
                        @if ($cantidadEnviables > 0)
                            ({{ number_format($cantidadEnviables, 0, ',', '.') }})
                        @endif
                    </button>
                    <div class="small text-muted mt-1 text-end">
                        Se aplican el período y los filtros activos.
                    </div>
                </form>
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
                            <th>PDF</th>
                            <th>Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($liquidaciones as $liquidacion)
                            @php
                                $emailValido = filter_var($liquidacion->email_destino, FILTER_VALIDATE_EMAIL) !== false;
                                $tienePdf = $liquidacion->estado === 'PDF_GENERADO' && $liquidacion->pdf_ruta;
                                $ultimoEstadoEnvio = $liquidacion->ultimo_envio_estado ?? null;
                                $envioPendiente = in_array($ultimoEstadoEnvio, ['PENDIENTE', 'PROCESANDO'], true);
                                $claseEstadoEnvio = match ($ultimoEstadoEnvio) {
                                    'ENVIADO' => 'success',
                                    'ERROR' => 'danger',
                                    'PENDIENTE', 'PROCESANDO' => 'warning',
                                    default => 'secondary',
                                };
                            @endphp
                            <tr>
                                <td>{{ sprintf('%04d-%08d', 0, $liquidacion->numero_interno) }}</td>
                                <td>{{ $liquidacion->cuenta_impresa }}</td>
                                <td>{{ $liquidacion->propietario }}</td>
                                <td>{{ $liquidacion->tipo }} {{ $liquidacion->comprobante }}</td>
                                <td class="text-end">$ {{ number_format($liquidacion->total, 2, ',', '.') }}</td>
                                <td>
                                    <span class="badge text-bg-{{ $liquidacion->control_estado === 'OK' ? 'success' : 'warning' }}">
                                        {{ $liquidacion->control_estado }}
                                    </span>
                                </td>
                                <td>
                                    @if ($tienePdf)
                                        <div class="btn-group btn-group-sm">
                                            <a
                                                href="{{ route('propietarios.liquidaciones.ver', $liquidacion->id) }}"
                                                class="btn btn-outline-primary"
                                                target="_blank"
                                            >Ver</a>
                                            <a
                                                href="{{ route('propietarios.liquidaciones.descargar', $liquidacion->id) }}"
                                                class="btn btn-outline-secondary"
                                            >Descargar</a>
                                        </div>
                                    @else
                                        <span class="text-muted">Pendiente</span>
                                    @endif
                                </td>
                                <td style="min-width: 220px;">
                                    @if ($emailValido)
                                        <div class="small text-break mb-1">{{ $liquidacion->email_destino }}</div>

                                        <form
                                            method="POST"
                                            action="{{ route('propietarios.liquidaciones.enviar-email', $liquidacion->id) }}"
                                            class="form-enviar-liquidacion"
                                            data-email="{{ $liquidacion->email_destino }}"
                                        >
                                            @csrf
                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-success"
                                                @disabled(! $registroEnviosDisponible || ! $tienePdf || $envioPendiente)
                                            >
                                                {{ $envioPendiente ? 'En cola' : 'Enviar PDF' }}
                                            </button>
                                        </form>

                                        @if ($ultimoEstadoEnvio)
                                            <span class="badge text-bg-{{ $claseEstadoEnvio }} mt-1">
                                                {{ $ultimoEstadoEnvio }}
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
            <h2 id="titulo-procesando-liquidaciones" class="h4 mb-2">Procesando liquidaciones</h2>
            <p id="detalle-procesando-liquidaciones" class="text-muted mb-3">
                Se están guardando los datos y generando los archivos PDF.
            </p>

            <div class="gei-processing-modal__summary mb-3">
                <div>
                    <span>Período</span>
                    <strong id="modal-periodo-liquidaciones">—</strong>
                </div>
                <div>
                    <span>Primer número</span>
                    <strong id="modal-numero-liquidaciones">—</strong>
                </div>
                <div>
                    <span>Tiempo transcurrido</span>
                    <strong id="modal-tiempo-liquidaciones">00:00</strong>
                </div>
            </div>

            <ul class="gei-processing-modal__tasks text-start mb-3">
                <li>Leer las liquidaciones almacenadas en PostgreSQL.</li>
                <li>Guardar cabeceras e ítems del período.</li>
                <li>Generar y registrar todos los archivos PDF.</li>
            </ul>

            <p class="small text-muted mb-0">
                Este proceso puede tardar varios minutos. No cierres ni recargues esta página.
            </p>
        </div>
    </div>

    <style>
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
            const modalPeriodo = document.getElementById('modal-periodo-liquidaciones');
            const modalNumero = document.getElementById('modal-numero-liquidaciones');
            const modalTiempo = document.getElementById('modal-tiempo-liquidaciones');
            const formularioEnvioMasivo = document.getElementById('form-enviar-todas-liquidaciones');
            const botonEnvioMasivo = document.getElementById('btn-enviar-todas-liquidaciones');

            formularioEnvioMasivo?.addEventListener('submit', (evento) => {
                const cantidad = Number.parseInt(formularioEnvioMasivo.dataset.cantidad || '0', 10);
                const mensaje = `Se programará el envío de ${cantidad} liquidación${cantidad === 1 ? '' : 'es'} por email. ¿Continuar?`;

                if (!window.confirm(mensaje)) {
                    evento.preventDefault();
                    return;
                }

                if (botonEnvioMasivo) {
                    botonEnvioMasivo.disabled = true;
                    botonEnvioMasivo.textContent = 'Programando envíos…';
                }
            });

            document.querySelectorAll('.form-enviar-liquidacion').forEach((formularioEnvio) => {
                formularioEnvio.addEventListener('submit', (evento) => {
                    const email = formularioEnvio.dataset.email || 'el email asociado';

                    if (!window.confirm(`¿Enviar el PDF de esta liquidación a ${email}?`)) {
                        evento.preventDefault();
                        return;
                    }

                    const boton = formularioEnvio.querySelector('button[type="submit"]');
                    if (boton) {
                        boton.disabled = true;
                        boton.textContent = 'Programando…';
                    }
                });
            });

            if (!periodo || !numeroInicial) {
                return;
            }

            periodo.addEventListener('change', () => {
                const opcion = periodo.options[periodo.selectedIndex];
                numeroInicial.value = opcion?.dataset.numeroInicial ?? '';
            });

            formulario?.addEventListener('submit', (evento) => {
                if (formulario.dataset.procesando === '1') {
                    evento.preventDefault();
                    return;
                }

                if (!formulario.checkValidity()) {
                    return;
                }

                formulario.dataset.procesando = '1';
                const opcion = periodo.options[periodo.selectedIndex];
                modalPeriodo.textContent = opcion?.textContent?.trim() || periodo.value;
                modalNumero.textContent = numeroInicial.value || 'Automático';
                modalTiempo.textContent = '00:00';
                modal.classList.add('is-visible');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';

                if (botonProcesar) {
                    botonProcesar.disabled = true;
                    botonProcesar.querySelector('.texto-boton').textContent = 'Procesando…';
                }

                const inicio = Date.now();
                window.setInterval(() => {
                    const segundos = Math.floor((Date.now() - inicio) / 1000);
                    const minutos = Math.floor(segundos / 60);
                    const restoSegundos = segundos % 60;
                    modalTiempo.textContent = `${String(minutos).padStart(2, '0')}:${String(restoSegundos).padStart(2, '0')}`;
                }, 1000);
            });
        });
    </script>
@endsection
