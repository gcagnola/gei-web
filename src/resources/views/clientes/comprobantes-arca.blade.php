@extends('layouts.app')

@section('title', 'Comprobantes ARCA de Clientes')
@section('page-title', 'Comprobantes ARCA de Clientes')

@section('content')
    <header class="gei-page-heading">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1>Comprobantes ARCA de Clientes</h1>
                <p class="mb-0">
                    Consulta y envío de comprobantes ARCA asociados a las cuentas COBOL de cada cliente.
                    Por defecto se muestran clientes activos.
                </p>
            </div>
            <a href="{{ route('clientes.index', ['actividad' => 'activos']) }}" class="btn btn-outline-secondary">
                Volver a Clientes
            </a>
        </div>
    </header>

    @if (session('estado'))
        <div class="alert alert-success">{{ session('estado') }}</div>
    @endif

    @if ($errors->has('envios'))
        <div class="alert alert-danger">{{ $errors->first('envios') }}</div>
    @endif

    <section class="gei-card mb-4">
        <form method="GET" action="{{ route('clientes.comprobantes-arca.index') }}" class="row g-3 align-items-end">
            <div class="col-sm-6 col-lg-2">
                <label for="periodo" class="form-label">Período</label>
                <select id="periodo" name="periodo" class="form-select" onchange="this.form.submit()">
                    @foreach ($periodosDisponibles as $periodoDisponible)
                        <option value="{{ $periodoDisponible }}" @selected($periodoDisponible === $periodo)>
                            {{ substr($periodoDisponible, 4, 2) }}/{{ substr($periodoDisponible, 0, 4) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-sm-6 col-lg-2">
                <label for="actividad" class="form-label">Estado</label>
                <select id="actividad" name="actividad" class="form-select" onchange="this.form.submit()">
                    <option value="activos" @selected($filtros['actividad'] === 'activos')>Activos</option>
                    <option value="pasivos" @selected($filtros['actividad'] === 'pasivos')>Pasivos</option>
                    <option value="todos" @selected($filtros['actividad'] === 'todos')>Todos</option>
                </select>
            </div>

            <div class="col-sm-6 col-lg-3">
                <label for="buscar" class="form-label">Cliente</label>
                <input
                    id="buscar"
                    name="buscar"
                    type="search"
                    maxlength="180"
                    value="{{ $filtros['buscar'] }}"
                    class="form-control"
                    placeholder="Nombre, documento, CUIT o cuenta"
                >
            </div>

            <div class="col-sm-6 col-lg-2">
                <label for="cuenta" class="form-label">Cuenta COBOL</label>
                <input
                    id="cuenta"
                    name="cuenta"
                    type="search"
                    maxlength="30"
                    value="{{ $filtros['cuenta'] }}"
                    class="form-control"
                    placeholder="12020466308"
                >
            </div>

            <div class="col-sm-6 col-lg-2">
                <label for="comprobante" class="form-label">Comprobante</label>
                <input
                    id="comprobante"
                    name="comprobante"
                    type="search"
                    maxlength="40"
                    value="{{ $filtros['comprobante'] }}"
                    class="form-control"
                    placeholder="00275195"
                >
            </div>

            <div class="col-sm-6 col-lg-1 d-grid">
                <button type="submit" class="btn btn-primary">Buscar</button>
            </div>
        </form>

        @if ($hayFiltros)
            <div class="mt-2 text-end">
                <a
                    href="{{ route('clientes.comprobantes-arca.index', ['periodo' => $periodo, 'actividad' => 'activos']) }}"
                    class="small"
                >Limpiar filtros</a>
            </div>
        @endif
    </section>

    <section class="gei-card">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div>
                <h2 class="h5 mb-1">Clientes con comprobantes</h2>
                <p class="text-muted mb-0">
                    {{ number_format($clientes->total(), 0, ',', '.') }}
                    {{ $clientes->total() === 1 ? 'cliente encontrado' : 'clientes encontrados' }}
                    para {{ $periodo ? substr($periodo, 4, 2).'/'.substr($periodo, 0, 4) : 'el período seleccionado' }}.
                </p>
            </div>

            <form
                method="POST"
                action="{{ route('clientes.comprobantes-arca.enviar-emails') }}"
                data-gei-process
                data-gei-process-title="Programando emails ARCA"
                data-gei-process-message="Se están agregando los envíos a la cola."
                onsubmit="return confirm('Se programará el envío de comprobantes ARCA a {{ $cantidadEnviables }} cliente(s). ¿Continuar?');"
            >
                @csrf
                <input type="hidden" name="periodo" value="{{ $periodo }}">
                <input type="hidden" name="actividad" value="{{ $filtros['actividad'] }}">
                <input type="hidden" name="buscar" value="{{ $filtros['buscar'] }}">
                <input type="hidden" name="cuenta" value="{{ $filtros['cuenta'] }}">
                <input type="hidden" name="comprobante" value="{{ $filtros['comprobante'] }}">
                <button
                    type="submit"
                    class="btn btn-success"
                    @disabled($cantidadEnviables === 0)
                >
                    Enviar todos por email ({{ number_format($cantidadEnviables, 0, ',', '.') }})
                </button>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Documento / CUIT</th>
                        <th>Cuentas COBOL</th>
                        <th>ARCA</th>
                        <th>Email</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($clientes as $cliente)
                        @php
                            $emailValido = filter_var($cliente->email, FILTER_VALIDATE_EMAIL) !== false;
                            $cantidadArca = (int) $cliente->comprobantes_arca_cantidad;
                            $comprobantesArca = $cliente->comprobantes_arca;
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('clientes.show', $cliente->id) }}" class="fw-semibold text-decoration-none">
                                    {{ $cliente->nombre }}
                                </a>
                                <span class="badge {{ $cliente->activo ? 'text-bg-success' : 'text-bg-secondary' }} ms-1">
                                    {{ $cliente->activo ? 'Activo' : 'Pasivo' }}
                                </span>
                            </td>
                            <td>
                                @if ($cliente->tipo_documento || $cliente->numero_documento)
                                    <div>{{ trim(($cliente->tipo_documento ?? '').' '.($cliente->numero_documento ?? '')) }}</div>
                                @endif
                                <div class="text-muted small">{{ $cliente->cuit ?: '—' }}</div>
                            </td>
                            <td>
                                <span class="small">{{ $cliente->cuentas->implode(' · ') }}</span>
                            </td>
                            <td style="min-width: 185px;">
                                @if ($cantidadArca === 1)
                                    @php $comprobanteArca = $comprobantesArca->first(); @endphp
                                    <a
                                        class="btn btn-sm btn-outline-primary"
                                        href="{{ route('comprobantes-arca.ver', ['periodo' => $periodo, 'archivo' => $comprobanteArca->nombre_archivo]) }}"
                                        target="_blank"
                                        rel="noopener"
                                        title="{{ $comprobanteArca->nombre_archivo }}"
                                    >
                                        {{ pathinfo($comprobanteArca->nombre_archivo, PATHINFO_FILENAME) }}
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
                                        <ul class="dropdown-menu dropdown-menu-end" style="min-width: 330px;">
                                            @foreach ($comprobantesArca as $comprobanteArca)
                                                <li>
                                                    <a
                                                        class="dropdown-item d-flex justify-content-between align-items-center gap-3"
                                                        href="{{ route('comprobantes-arca.ver', ['periodo' => $periodo, 'archivo' => $comprobanteArca->nombre_archivo]) }}"
                                                        target="_blank"
                                                        rel="noopener"
                                                        title="{{ $comprobanteArca->nombre_archivo }}"
                                                    >
                                                        <span>
                                                            {{ $comprobanteArca->tipo_codigo }}-{{ $comprobanteArca->punto_venta }}-{{ $comprobanteArca->numero_comprobante }}
                                                            <small class="d-block text-muted">Cuenta {{ $comprobanteArca->cuenta_cobol }}</small>
                                                        </span>
                                                        <small class="text-muted">Ver</small>
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </td>
                            <td style="min-width: 260px;">
                                @if ($emailValido)
                                    <div class="small text-break mb-1">{{ $cliente->email }}</div>
                                    <form
                                        method="POST"
                                        action="{{ route('clientes.comprobantes-arca.enviar-email', $cliente->id) }}"
                                        data-gei-process
                                        data-gei-process-title="Programando email ARCA"
                                        data-gei-process-message="Se está agregando el envío a la cola."
                                        onsubmit="return confirm('¿Enviar {{ $cantidadArca }} comprobante(s) ARCA a {{ addslashes($cliente->email) }}?');"
                                    >
                                        @csrf
                                        <input type="hidden" name="periodo" value="{{ $periodo }}">
                                        <button type="submit" class="btn btn-sm btn-outline-success">
                                            Enviar ARCA
                                        </button>
                                    </form>
                                @else
                                    <span class="text-muted">Sin email asociado</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                No se encontraron clientes con comprobantes ARCA para los filtros seleccionados.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($clientes->hasPages())
            <div class="mt-3">{{ $clientes->onEachSide(1)->links() }}</div>
        @endif
    </section>
@endsection
