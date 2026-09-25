@extends('layouts.app')

@section('title', 'Impresiones COBOL')
@section('page-title', 'Impresiones COBOL')

@section('content')
    <header class="gei-page-heading">
        <h1>Impresiones COBOL</h1>
        <p>
            Impresiones recibidas automáticamente desde COBOL.
            Por defecto se muestra el día de hoy y se puede consultar cualquier otra fecha.
        </p>
    </header>

    <section class="gei-card">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
            <div class="d-flex flex-wrap align-items-end gap-3">
                <div>
                    <label for="fecha-consulta" class="form-label mb-1">Fecha</label>
                    <input type="date" class="form-control" id="fecha-consulta" value="{{ $fechaInput }}" max="{{ now()->format('Y-m-d') }}">
                </div>
                <button type="button" class="btn btn-outline-secondary" id="btn-hoy" @disabled($esHoy)>Hoy</button>
                <div>
                    <h2 class="h5 mb-1">
                        <span id="titulo-fecha">{{ $esHoy ? 'Hoy, ' : '' }}</span><span id="fecha-impresiones">{{ $fecha }}</span>
                    </h2>
                    <div class="text-muted small">
                        <span id="leyenda-actualizacion">{{ $esHoy ? 'Actualización automática cada 2 segundos · ' : '' }}</span>
                        <span id="estado-actualizacion">{{ $esHoy ? 'Conectado' : '' }}</span>
                    </div>
                </div>
            </div>
            <div class="text-end">
                <div class="small text-muted">Impresiones recibidas</div>
                <div class="fs-4 fw-semibold" id="total-impresiones">{{ $impresiones->count() }}</div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Hora</th><th>Tipo</th><th>Cuenta</th><th>Cliente</th><th>Archivo RAW</th><th>Estado</th><th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody id="tabla-impresiones">
                    @forelse ($impresiones as $impresion)
                        <tr data-id="{{ $impresion->id }}">
                            <td class="text-nowrap">{{ optional($impresion->recibido_en)->format('H:i:s') }}</td>
                            <td>
                                @if ($impresion->tipo_documento === 'LIQUIDACION_DEUDA') Liquidación de deuda
                                @elseif ($impresion->tipo_documento === 'RECIBO_LIQUIDACION') Recibo de liquidación
                                @else Desconocido @endif
                            </td>
                            <td class="text-nowrap">{{ $impresion->cuenta ?: '—' }}</td>
                            <td>{{ $impresion->nombre_cliente ?: '—' }}</td>
                            <td class="text-nowrap"><code>{{ $impresion->archivo_origen }}</code></td>
                            <td><span class="badge text-bg-{{ $impresion->estado === 'PARSEADO' ? 'success' : 'secondary' }}">{{ $impresion->estado }}</span></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('archivo.impresiones-cobol.raw', $impresion) }}" target="_blank" rel="noopener">Ver RAW</a>
                                @if (in_array($impresion->tipo_documento, ['LIQUIDACION_DEUDA', 'RECIBO_LIQUIDACION'], true))
                                    <button class="btn btn-sm btn-primary js-generar-pdf" type="button" data-url="{{ route('archivo.impresiones-cobol.pdf.generar', $impresion) }}">Generar PDF</button>
                                    @if ($impresion->pdf_path)
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('archivo.impresiones-cobol.pdf', $impresion) }}" target="_blank" rel="noopener">Ver PDF</a>
                                    @endif
                                @else
                                    <button class="btn btn-sm btn-primary" type="button" disabled>Generar PDF</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr id="sin-impresiones"><td colspan="7" class="text-center text-muted py-5">No se recibieron impresiones COBOL en esta fecha.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <script>
        (() => {
            const tbody = document.getElementById('tabla-impresiones');
            const total = document.getElementById('total-impresiones');
            const fecha = document.getElementById('fecha-impresiones');
            const tituloFecha = document.getElementById('titulo-fecha');
            const estado = document.getElementById('estado-actualizacion');
            const leyenda = document.getElementById('leyenda-actualizacion');
            const fechaConsulta = document.getElementById('fecha-consulta');
            const btnHoy = document.getElementById('btn-hoy');
            const urlBase = @json(route('archivo.impresiones-cobol.datos'));
            const hoy = @json(now()->format('Y-m-d'));
            const csrf = @json(csrf_token());

            const escapeHtml = (value) => String(value ?? '')
                .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;').replaceAll("'", '&#039;');

            const render = (items) => {
                if (!Array.isArray(items) || items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-5">No se recibieron impresiones COBOL en esta fecha.</td></tr>';
                    return;
                }

                tbody.innerHTML = items.map((item) => {
                    const badge = item.estado === 'PARSEADO' ? 'success' : 'secondary';
                    let pdf = '<button class="btn btn-sm btn-primary" type="button" disabled>Generar PDF</button>';
                    if (item.puede_generar_pdf) {
                        pdf = `<button class="btn btn-sm btn-primary js-generar-pdf" type="button" data-url="${escapeHtml(item.generar_pdf_url)}">Generar PDF</button>`;
                        if (item.pdf_url) {
                            pdf += ` <a class="btn btn-sm btn-outline-primary" href="${escapeHtml(item.pdf_url)}" target="_blank" rel="noopener">Ver PDF</a>`;
                        }
                    }

                    return `<tr data-id="${escapeHtml(item.id)}">
                        <td class="text-nowrap">${escapeHtml(item.hora || '—')}</td>
                        <td>${escapeHtml(item.tipo || 'Desconocido')}</td>
                        <td class="text-nowrap">${escapeHtml(item.cuenta || '—')}</td>
                        <td>${escapeHtml(item.cliente || '—')}</td>
                        <td class="text-nowrap"><code>${escapeHtml(item.archivo)}</code></td>
                        <td><span class="badge text-bg-${badge}">${escapeHtml(item.estado)}</span></td>
                        <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="${escapeHtml(item.raw_url)}" target="_blank" rel="noopener">Ver RAW</a> ${pdf}</td>
                    </tr>`;
                }).join('');
            };

            const actualizarUrlPagina = () => {
                const url = new URL(window.location.href);
                if (fechaConsulta.value === hoy) url.searchParams.delete('fecha');
                else url.searchParams.set('fecha', fechaConsulta.value);
                window.history.replaceState({}, '', url);
            };

            const actualizar = async () => {
                if (!fechaConsulta.value) return;
                try {
                    const url = new URL(urlBase, window.location.origin);
                    url.searchParams.set('fecha', fechaConsulta.value);
                    const response = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    const data = await response.json();
                    render(data.impresiones);
                    total.textContent = data.total ?? 0;
                    fecha.textContent = data.fecha ?? fecha.textContent;
                    tituloFecha.textContent = data.es_hoy ? 'Hoy, ' : '';
                    btnHoy.disabled = Boolean(data.es_hoy);
                    leyenda.textContent = data.es_hoy ? 'Actualización automática cada 2 segundos · ' : '';
                    estado.textContent = data.es_hoy ? 'Conectado' : '';
                    actualizarUrlPagina();
                } catch (error) {
                    estado.textContent = 'Sin actualizar';
                }
            };

            tbody.addEventListener('click', async (event) => {
                const button = event.target.closest('.js-generar-pdf');
                if (!button) return;
                const textoOriginal = button.textContent;
                button.disabled = true;
                button.textContent = 'Generando...';
                try {
                    const response = await fetch(button.dataset.url, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    });
                    const data = await response.json();
                    if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
                    window.open(data.pdf_url, '_blank', 'noopener');
                    await actualizar();
                } catch (error) {
                    alert(`No se pudo generar el PDF: ${error.message}`);
                } finally {
                    button.disabled = false;
                    button.textContent = textoOriginal;
                }
            });

            fechaConsulta.addEventListener('change', actualizar);
            btnHoy.addEventListener('click', () => { fechaConsulta.value = hoy; actualizar(); });

            const timer = window.setInterval(() => {
                if (fechaConsulta.value === hoy) actualizar();
            }, 2000);
            window.addEventListener('beforeunload', () => window.clearInterval(timer), { once: true });
        })();
    </script>
@endsection
