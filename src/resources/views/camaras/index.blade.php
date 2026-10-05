@extends('layouts.app')

@section('title', 'Cámaras')

@section('content')
    <header class="gei-page-heading d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1">Cámaras</h1>
            <p class="text-muted mb-0">Visualización en vivo de las cámaras de GeI.</p>
        </div>
        <div class="small text-muted text-end">
            <div>Transmisión en vivo</div>
            <div>Substream en grilla · stream principal al ampliar</div>
        </div>
    </header>

    @if (empty($sitios))
        <div class="alert alert-warning mb-0">
            No hay sedes de cámaras habilitadas en la configuración.
        </div>
    @else
        <section class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="fw-semibold me-1">Sede</span>
                    @foreach ($sitios as $indice => $sitio)
                        <button
                            type="button"
                            class="btn btn-sm {{ $indice === 0 ? 'btn-primary' : 'btn-outline-secondary' }} js-sede"
                            data-sede="{{ $sitio['codigo'] }}"
                        >
                            {{ $sitio['nombre'] }}
                        </button>
                    @endforeach
                </div>
            </div>
        </section>

        <div id="gei-camaras-contenedor"></div>

        <div class="modal fade" id="modalCamara" tabindex="-1" aria-labelledby="modalCamaraTitulo" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content border-0">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="modalCamaraTitulo">Cámara</h5>
                            <div class="small text-muted" id="modalCamaraSede"></div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body p-0 bg-black">
                        <div class="gei-camara-modal-player">
                            <iframe
                                id="modalCamaraFrame"
                                title="Cámara ampliada"
                                allow="autoplay; fullscreen"
                                allowfullscreen
                            ></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <style>
        .gei-camaras-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .gei-camara-card {
            overflow: hidden;
            border: 1px solid var(--gei-border);
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 4px 16px rgba(48, 36, 51, .05);
        }

        .gei-camara-card__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 13px;
            border-bottom: 1px solid var(--gei-border);
        }

        .gei-camara-card__player,
        .gei-camara-modal-player {
            position: relative;
            background: #000;
        }

        .gei-camara-card__player {
            aspect-ratio: 16 / 9;
        }

        .gei-camara-modal-player {
            min-height: min(72vh, 760px);
        }

        .gei-camara-card iframe,
        .gei-camara-modal-player iframe {
            display: block;
            width: 100%;
            height: 100%;
            border: 0;
            background: #000;
        }

        .gei-camara-card__button {
            border: 0;
            padding: 0;
            color: var(--gei-primary-dark);
            background: transparent;
            font-size: .82rem;
            font-weight: 600;
            text-decoration: none;
        }

        .gei-camara-card__button:hover,
        .gei-camara-card__button:focus-visible {
            color: var(--gei-primary);
            text-decoration: underline;
        }

        @media (max-width: 991.98px) {
            .gei-camaras-grid {
                grid-template-columns: 1fr;
            }

            .gei-camara-modal-player {
                min-height: 55vh;
            }
        }
    </style>

    @if (! empty($sitios))
        <script>
            (() => {
                const sitios = @json($sitios);
                const go2rtcUrl = @json($go2rtcUrl);
                const contenedor = document.getElementById('gei-camaras-contenedor');
                const botonesSede = document.querySelectorAll('.js-sede');
                const modalElement = document.getElementById('modalCamara');
                const modalFrame = document.getElementById('modalCamaraFrame');
                const modalTitulo = document.getElementById('modalCamaraTitulo');
                const modalSede = document.getElementById('modalCamaraSede');
                const bootstrapModalDisponible =
                    modalElement &&
                    typeof window.bootstrap !== 'undefined' &&
                    window.bootstrap?.Modal;

                const modal = bootstrapModalDisponible
                    ? window.bootstrap.Modal.getOrCreateInstance(modalElement)
                    : null;

                const escapeHtml = (value) => String(value ?? '')
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');

                const streamUrl = (stream) => {
                    const url = new URL(`${go2rtcUrl}/stream.html`);
                    url.searchParams.set('src', stream);
                    url.searchParams.set('background', 'false');
                    return url.toString();
                };

                const buscarSitio = (codigo) => sitios.find((sitio) => sitio.codigo === codigo);

                const renderSitio = (codigo) => {
                    const sitio = buscarSitio(codigo);
                    if (!sitio) return;

                    botonesSede.forEach((boton) => {
                        const activo = boton.dataset.sede === codigo;
                        boton.classList.toggle('btn-primary', activo);
                        boton.classList.toggle('btn-outline-secondary', !activo);
                    });

                    if (!Array.isArray(sitio.camaras) || sitio.camaras.length === 0) {
                        contenedor.innerHTML = '<div class="alert alert-secondary">Esta sede todavía no tiene cámaras configuradas.</div>';
                        return;
                    }

                    contenedor.innerHTML = `
                        <div class="gei-camaras-grid">
                            ${sitio.camaras.map((camara) => `
                                <article class="gei-camara-card">
                                    <div class="gei-camara-card__header">
                                        <div>
                                            <div class="fw-semibold">${escapeHtml(camara.nombre)}</div>
                                            <div class="small text-muted">${escapeHtml(sitio.nombre)}</div>
                                        </div>
                                        <button
                                            type="button"
                                            class="gei-camara-card__button js-ampliar-camara"
                                            data-sede="${escapeHtml(sitio.codigo)}"
                                            data-camara="${escapeHtml(camara.id)}"
                                        >Ampliar</button>
                                    </div>
                                    <div class="gei-camara-card__player">
                                        <iframe
                                            src="${escapeHtml(streamUrl(camara.sub))}"
                                            title="${escapeHtml(camara.nombre)}"
                                            loading="lazy"
                                            allow="autoplay; fullscreen"
                                            allowfullscreen
                                        ></iframe>
                                    </div>
                                </article>
                            `).join('')}
                        </div>`;
                };

                botonesSede.forEach((boton) => {
                    boton.addEventListener('click', () => renderSitio(boton.dataset.sede));
                });

                contenedor.addEventListener('click', (event) => {
                    const boton = event.target.closest('.js-ampliar-camara');
                    if (!boton) return;

                    const sitio = buscarSitio(boton.dataset.sede);
                    const camara = sitio?.camaras?.find((item) => item.id === boton.dataset.camara);
                    if (!sitio || !camara) return;

                    const url = streamUrl(camara.main || camara.sub);

                    if (!modal) {
                        window.open(url, '_blank', 'noopener,noreferrer');
                        return;
                    }

                    modalTitulo.textContent = camara.nombre;
                    modalSede.textContent = sitio.nombre;
                    modalFrame.src = url;
                    modal.show();
                });

                modalElement?.addEventListener('hidden.bs.modal', () => {
                    modalFrame.src = 'about:blank';
                });

                renderSitio(sitios[0].codigo);
            })();
        </script>
    @endif
@endsection
