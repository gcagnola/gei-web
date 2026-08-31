@extends('layouts.app')

@section('title', 'Actualizar GeI-Web')
@section('page-title', 'Actualizar GeI-Web')

@section('content')
    @php
        $staging = $actualizacion['staging'] ?? [];
        $analisis = $actualizacion['ultimo_analisis'] ?? null;
        $aplicacion = $actualizacion['ultima_aplicacion'] ?? null;
        $liquidaciones = $liquidacionesPropietarios ?? [];
        $analisisLiq = $liquidaciones['ultimo_analisis'] ?? null;
        $resultadoLiq = is_array($analisisLiq) ? ($analisisLiq['resultado'] ?? []) : [];
        $impuestosLiq = is_array($resultadoLiq['impuestos_garantizados'] ?? null)
            ? $resultadoLiq['impuestos_garantizados']
            : [];
        $aplicacionLiq = $liquidaciones['ultima_aplicacion'] ?? null;
        $resultadoAplicacionLiq = is_array($aplicacionLiq) ? ($aplicacionLiq['resultado'] ?? []) : [];
        $impuestosAplicacionLiq = is_array($resultadoAplicacionLiq['impuestos_garantizados'] ?? null)
            ? $resultadoAplicacionLiq['impuestos_garantizados']
            : [];
        $etapas = [
            'clientes' => 'Clientes',
            'inmuebles' => 'Inmuebles',
            'contratos' => 'Contratos',
            'cuentas_corrientes' => 'Cuentas corrientes',
        ];
        $badgeEstado = static fn (string $estado): string => match ($estado) {
            'ANALIZADO' => 'text-bg-primary',
            'APLICADO', 'VERIFICADO' => 'text-bg-success',
            'APLICANDO' => 'text-bg-warning',
            'ERROR_ANALISIS', 'ERROR_APLICACION' => 'text-bg-danger',
            'DESACTUALIZADO', 'REVISION_REQUERIDA' => 'text-bg-warning',
            default => 'text-bg-secondary',
        };
        $formatearFechaHora = static function ($valor): string {
            if (! $valor) return '—';
            try { return \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y H:i'); }
            catch (\Throwable) { return (string) $valor; }
        };
        $n = static fn ($valor): string => number_format((int) ($valor ?? 0), 0, ',', '.');
        $resumenEtapa = static function (array $resultado): array {
            $cambios = 0;
            foreach ($resultado as $clave => $valor) {
                if (! is_int($valor)) continue;
                if (str_ends_with((string) $clave, '_creados')
                    || str_ends_with((string) $clave, '_actualizados')
                    || str_ends_with((string) $clave, '_unificados')
                    || str_ends_with((string) $clave, '_asignados')
                    || str_ends_with((string) $clave, '_resueltos')) {
                    $cambios += $valor;
                }
            }
            return [
                'cambios' => $cambios,
                'conflictos' => (int) ($resultado['conflictos_pendientes'] ?? 0),
                'advertencias' => (int) ($resultado['advertencias_pendientes'] ?? 0),
                'identidad' => (int) ($resultado['requieren_revision_identidad'] ?? 0),
                'omitidos' => (int) ($resultado['omitidos'] ?? 0),
            ];
        };
    @endphp

    @if (session('estado'))
        <div class="alert alert-success py-2" role="alert">{{ session('estado') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger py-2" role="alert">{{ $errors->first() }}</div>
    @endif

    <header class="gei-page-heading d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div>
            <h1>Actualizar GeI-Web</h1>
            <p class="mb-0">Período <strong>{{ $etiquetaPeriodo }}</strong> <span class="text-muted">({{ $periodo }})</span>.</p>
        </div>
        <a href="{{ route('archivo.importar') }}" class="btn btn-outline-secondary">Volver a Importar</a>
    </header>

    <section class="gei-card p-3 mb-3">
        <div class="row g-3 align-items-center">
            <div class="col-lg-4">
                <div class="text-muted small">Staging</div>
                <span class="badge {{ ($staging['estado'] ?? null) === 'OK' ? 'text-bg-success' : 'text-bg-warning' }}">
                    {{ $staging['etiqueta'] ?? ($staging['estado'] ?? '—') }}
                </span>
                <span class="small ms-1">{{ $staging['mensaje'] ?? '—' }}</span>
            </div>
            <div class="col-lg-4">
                <div class="text-muted small">Base GeI-Web</div>
                <span class="badge {{ $badgeEstado((string) ($actualizacion['estado'] ?? '')) }}">{{ $actualizacion['etiqueta'] ?? '—' }}</span>
                <span class="small ms-1">{{ $actualizacion['mensaje'] ?? '—' }}</span>
            </div>
            <div class="col-lg-4">
                <div class="text-muted small">Liquidaciones propietarios</div>
                <span class="badge {{ $badgeEstado((string) ($liquidaciones['estado'] ?? '')) }}">{{ $liquidaciones['etiqueta'] ?? '—' }}</span>
                <span class="small ms-1">{{ $liquidaciones['mensaje'] ?? '—' }}</span>
            </div>
        </div>
    </section>

    <section class="gei-card p-3 mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <h2 class="h5 mb-1">1. Base operativa</h2>
                <p class="text-muted small mb-0">Clientes → Inmuebles → Contratos → Cuentas corrientes.</p>
            </div>
            <form
                method="POST"
                action="{{ route('archivo.importar.actualizar-gei.analizar', $periodo) }}"
                data-gei-process
                data-gei-process-title="Analizando base operativa"
                data-gei-process-message="Clientes, inmuebles, contratos y cuentas corrientes."
            >
                @csrf
                <button type="submit" class="btn gei-button gei-button--primary" @disabled(! ($actualizacion['puede_analizar'] ?? false))>
                    {{ $aplicacion ? 'Verificar / analizar nuevamente' : 'Analizar actualización' }}
                </button>
            </form>
        </div>

        @if (is_array($analisis))
            <div class="table-responsive mt-3">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr><th>Módulo</th><th class="text-end">Cambios</th><th class="text-end">Conflictos</th><th class="text-end">Advertencias</th><th class="text-end">Revisión identidad</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($etapas as $claveEtapa => $tituloEtapa)
                            @php $r = $resumenEtapa($analisis['etapas'][$claveEtapa]['resultado'] ?? []); @endphp
                            <tr>
                                <td class="fw-semibold">{{ $tituloEtapa }}</td>
                                <td class="text-end">{{ $n($r['cambios']) }}</td>
                                <td class="text-end">{{ $n($r['conflictos']) }}</td>
                                <td class="text-end">{{ $n($r['advertencias']) }}</td>
                                <td class="text-end">{{ $n($r['identidad']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="small text-muted mt-2">
                Analizado {{ $formatearFechaHora($analisis['fecha'] ?? null) }} · {{ (int) ($analisis['duracion_segundos'] ?? 0) }} s ·
                Cambios persistibles: <strong>{{ ($analisis['hay_cambios_persistibles'] ?? false) ? 'Sí' : 'No' }}</strong>
            </div>

            <details class="mt-2">
                <summary class="text-primary" style="cursor:pointer">Ver detalle técnico de la base operativa</summary>
                <div class="row g-3 mt-1">
                    @foreach ($etapas as $claveEtapa => $tituloEtapa)
                        @php
                            $etapa = $analisis['etapas'][$claveEtapa] ?? [];
                            $resultado = $etapa['resultado'] ?? [];
                        @endphp
                        <div class="col-xl-6">
                            <div class="border rounded p-3 h-100">
                                <h3 class="h6">{{ $tituloEtapa }}</h3>
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <tbody>
                                            @foreach ($resultado as $clave => $valor)
                                                @continue($clave === 'confirmado')
                                                <tr><td>{{ ucfirst(str_replace('_', ' ', $clave)) }}</td><td class="text-end fw-semibold">{{ is_numeric($valor) ? $n($valor) : (string) $valor }}</td></tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                @if (! empty($etapa['incidencias_muestra']))
                                    <details class="mt-2"><summary class="small text-primary" style="cursor:pointer">Muestra de incidencias</summary><pre class="small bg-light border rounded p-2 mt-2 mb-0" style="white-space:pre-wrap">{{ json_encode($etapa['incidencias_muestra'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </section>

    <section class="gei-card p-3 mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <h2 class="h5 mb-1">2. Aplicar / verificar base GeI-Web</h2>
                <p class="small text-muted mb-0">Los conflictos quedan pendientes para revisión humana; no se resuelven automáticamente.</p>
            </div>
            @if (($actualizacion['estado'] ?? null) === 'VERIFICADO')
                <span class="badge text-bg-success fs-6">Base verificada</span>
            @else
                <form
                    method="POST"
                    action="{{ route('archivo.importar.actualizar-gei.aplicar', $periodo) }}"
                    onsubmit="return confirm('¿Confirma actualizar GeI-Web con el período {{ $etiquetaPeriodo }}?');"
                    data-gei-process
                    data-gei-process-title="Aplicando actualización GeI-Web"
                    data-gei-process-message="Clientes, inmuebles, contratos y cuentas corrientes. No cierre esta ventana."
                >
                    @csrf
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" value="1" id="confirmar-actualizacion-gei" name="confirmar" required @disabled(! ($actualizacion['puede_aplicar'] ?? false))>
                        <label class="form-check-label small" for="confirmar-actualizacion-gei">Confirmo la actualización.</label>
                    </div>
                    <button type="submit" class="btn btn-success" @disabled(! ($actualizacion['puede_aplicar'] ?? false))>Aplicar actualización GeI-Web</button>
                </form>
            @endif
        </div>
        @if (is_array($aplicacion))
            <details class="mt-2"><summary class="small text-primary" style="cursor:pointer">Ver última aplicación</summary><pre class="small bg-light border rounded p-2 mt-2 mb-0" style="white-space:pre-wrap">{{ json_encode($aplicacion, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>
        @endif
    </section>

    <section class="gei-card p-3 mb-3">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div>
                <h2 class="h5 mb-1">3. Liquidaciones de propietarios</h2>
                <p class="small text-muted mb-0">Analiza LIQUIDA/LIQUIDB/PLIQLOC y DAILOC del mismo período. El análisis no graba liquidaciones ni genera PDF.</p>
            </div>
            <span class="badge {{ $badgeEstado((string) ($liquidaciones['estado'] ?? '')) }} fs-6">{{ $liquidaciones['etiqueta'] ?? '—' }}</span>
        </div>

        @if ($liquidaciones['puede_analizar'] ?? false)
            <form
                method="POST"
                action="{{ route('archivo.importar.actualizar-gei.liquidaciones.analizar', $periodo) }}"
                class="row g-2 align-items-end mt-2"
                data-gei-process
                data-gei-process-title="Analizando liquidaciones"
                data-gei-process-message="Liquidaciones de propietarios y detalle de impuestos garantizados (DAILOC)."
            >
                @csrf
                <div class="col-sm-4 col-lg-3">
                    <label for="numero_inicial_liq" class="form-label small fw-semibold mb-1">Primer número interno</label>
                    <input type="number" min="1" id="numero_inicial_liq" name="numero_inicial" class="form-control" value="{{ old('numero_inicial', $analisisLiq['numero_inicial_solicitado'] ?? $liquidaciones['numero_inicial_sugerido'] ?? '') }}">
                </div>
                <div class="col-sm-auto">
                    <button type="submit" class="btn gei-button gei-button--primary">
                        {{ $aplicacionLiq ? 'Verificar liquidaciones' : 'Analizar liquidaciones' }}
                    </button>
                </div>
                <div class="col-12"><div class="form-text">Se conserva el mismo criterio de numeración del módulo actual de liquidaciones.</div></div>
            </form>
        @else
            <div class="alert alert-secondary py-2 mt-3 mb-0">{{ $liquidaciones['mensaje'] ?? 'Liquidaciones no disponibles.' }}</div>
        @endif

        @if (is_array($analisisLiq))
            <div class="row g-2 mt-3">
                <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Detectadas</div><div class="h5 mb-0">{{ $n($resultadoLiq['detectadas'] ?? 0) }}</div></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">A insertar</div><div class="h5 mb-0">{{ $n($resultadoLiq['insertadas'] ?? 0) }}</div></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">A actualizar</div><div class="h5 mb-0">{{ $n($resultadoLiq['actualizadas'] ?? 0) }}</div></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Sin cambios</div><div class="h5 mb-0">{{ $n($resultadoLiq['omitidas'] ?? 0) }}</div></div></div>
            </div>
            <div class="row g-2 mt-1 small">
                <div class="col-md-4"><strong>Cuentas no resueltas:</strong> {{ $n($resultadoLiq['cuentas_propietario_no_resueltas'] ?? 0) }}</div>
                <div class="col-md-4"><strong>Clientes no resueltos:</strong> {{ $n($resultadoLiq['clientes_no_resueltos'] ?? 0) }}</div>
                <div class="col-md-4"><strong>Numeración estimada:</strong> {{ $resultadoLiq['numero_inicial_periodo'] ?? '—' }} → {{ $resultadoLiq['numero_final_estimado'] ?? '—' }}</div>
            </div>

            <div class="border rounded p-3 mt-3">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <div>
                        <div class="fw-semibold">Detalle de impuestos garantizados (DAILOC)</div>
                        <div class="small text-muted">Se generará automáticamente junto con los PDF de propietarios.</div>
                    </div>
                    <span class="badge {{ ($impuestosLiq['validacion_ok'] ?? false) ? 'text-bg-success' : 'text-bg-warning' }}">
                        {{ ($impuestosLiq['validacion_ok'] ?? false) ? 'Validación OK' : 'Revisar' }}
                    </span>
                </div>
                <div class="row g-2 small">
                    <div class="col-6 col-lg-2"><strong>Detalles:</strong> {{ $n($impuestosLiq['detalles_detectados'] ?? 0) }}</div>
                    <div class="col-6 col-lg-2"><strong>Hojas COBOL:</strong> {{ $n($impuestosLiq['paginas_cobol'] ?? 0) }}</div>
                    <div class="col-6 col-lg-2"><strong>PDF esperados:</strong> {{ $n($impuestosLiq['pdf_esperados'] ?? 0) }}</div>
                    <div class="col-6 col-lg-2"><strong>PDF existentes:</strong> {{ $n($impuestosLiq['pdf_existentes'] ?? 0) }}</div>
                    <div class="col-6 col-lg-2"><strong>Diferencias:</strong> {{ $n($impuestosLiq['validaciones_con_diferencia'] ?? 0) }}</div>
                    <div class="col-6 col-lg-2"><strong>Errores:</strong> {{ $n($impuestosLiq['errores'] ?? 0) }}</div>
                </div>
            </div>

            @if ($resultadoLiq['renumeraria_periodo'] ?? false)
                <div class="alert alert-warning py-2 mt-2 mb-0"><strong>Atención:</strong> el número ingresado renumeraría liquidaciones ya existentes del período. Revisalo antes de aplicar.</div>
            @endif

            <details class="mt-2">
                <summary class="small text-primary" style="cursor:pointer">Ver controles PLIQLOC y detalle técnico</summary>
                <div class="row g-3 mt-1">
                    <div class="col-lg-5">
                        <table class="table table-sm mb-0"><tbody>
                            @foreach (($resultadoLiq['control_pliqloc_estados'] ?? []) as $estadoControl => $cantidad)
                                <tr><td>{{ $estadoControl }}</td><td class="text-end fw-semibold">{{ $n($cantidad) }}</td></tr>
                            @endforeach
                        </tbody></table>
                    </div>
                    <div class="col-lg-7">
                        @if (! empty($resultadoLiq['muestra_no_resueltas']))
                            <pre class="small bg-light border rounded p-2 mb-0" style="white-space:pre-wrap">{{ json_encode($resultadoLiq['muestra_no_resueltas'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        @else
                            <div class="text-muted small">No hay cuentas/clientes sin resolver en la muestra.</div>
                        @endif
                    </div>
                </div>
            </details>

            @if ($liquidaciones['puede_aplicar'] ?? false)
                <form
                    method="POST"
                    action="{{ route('archivo.importar.actualizar-gei.liquidaciones.aplicar', $periodo) }}"
                    class="mt-3"
                    onsubmit="return confirm('¿Confirma procesar las liquidaciones de propietarios de {{ $etiquetaPeriodo }} y generar también el detalle de impuestos garantizados?');"
                    data-gei-process
                    data-gei-process-title="Procesando liquidaciones"
                    data-gei-process-message="Importando liquidaciones y generando PDF de propietarios + detalle de impuestos garantizados."
                >
                    @csrf
                    @if (($analisisLiq['numero_inicial_solicitado'] ?? null) !== null)
                        <input type="hidden" name="numero_inicial" value="{{ (int) $analisisLiq['numero_inicial_solicitado'] }}">
                    @endif
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" value="1" id="confirmar-liquidaciones" name="confirmar_liquidaciones" required>
                        <label class="form-check-label small" for="confirmar-liquidaciones">Confirmo importar las liquidaciones y generar los PDF de propietarios y de impuestos garantizados. No se enviarán emails.</label>
                    </div>
                    <button type="submit" class="btn btn-success">Procesar liquidaciones y generar todos los PDF</button>
                </form>
            @endif
        @endif

        @if (is_array($aplicacionLiq))
            <div class="d-flex flex-wrap gap-3 small mt-3 pt-2 border-top">
                <span><strong>Última aplicación:</strong> {{ $formatearFechaHora($aplicacionLiq['fecha'] ?? null) }}</span>
                <span><strong>Insertadas:</strong> {{ $n($resultadoAplicacionLiq['insertadas'] ?? 0) }}</span>
                <span><strong>Actualizadas:</strong> {{ $n($resultadoAplicacionLiq['actualizadas'] ?? 0) }}</span>
                <span><strong>PDF propietarios:</strong> {{ $n($resultadoAplicacionLiq['pdf_generados'] ?? 0) }}</span>
                <span><strong>PDF impuestos garantizados:</strong> {{ $n($impuestosAplicacionLiq['pdf_generados'] ?? 0) }}</span>
                <a href="{{ route('propietarios.liquidaciones.index', ['periodo' => $periodo]) }}">Abrir liquidaciones</a>
            </div>
        @endif
    </section>

    <details class="gei-card p-3 mb-3">
        <summary class="fw-semibold" style="cursor:pointer">Historial técnico del período</summary>
        <div class="row g-3 mt-1">
            <div class="col-lg-6">
                <h3 class="h6">Base GeI-Web</h3>
                <pre class="small bg-light border rounded p-2 mb-0" style="white-space:pre-wrap">{{ json_encode($actualizacion['historial'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <div class="col-lg-6">
                <h3 class="h6">Liquidaciones</h3>
                <pre class="small bg-light border rounded p-2 mb-0" style="white-space:pre-wrap">{{ json_encode($liquidaciones['historial'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </div>
    </details>
@endsection
