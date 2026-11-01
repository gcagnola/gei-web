{{-- Indicador único para navegación y formularios con recarga completa. --}}
@once
<div id="gei-page-loading" class="gei-page-loading" hidden aria-hidden="true">
    <div class="gei-page-loading__dialog" role="status" aria-live="polite" aria-atomic="true">
        <span class="gei-page-loading__spinner" aria-hidden="true"></span>
        <div>
            <strong>Procesando solicitud…</strong>
            <div class="gei-page-loading__hint">Cargando la página</div>
        </div>
    </div>
</div>
<style>
    #gei-page-loading[hidden] { display: none !important; }
    #gei-page-loading {
        position: fixed;
        inset: 0;
        display: grid;
        place-items: center;
        z-index: 2147483000;
        background: rgba(22, 31, 45, .30);
        backdrop-filter: blur(2px);
        -webkit-backdrop-filter: blur(2px);
    }
    .gei-page-loading__dialog {
        display: flex;
        align-items: center;
        gap: 1rem;
        max-width: min(90vw, 430px);
        padding: 1.15rem 1.5rem;
        background: #fff;
        border: 1px solid #dfe4eb;
        border-radius: 12px;
        box-shadow: 0 20px 60px rgba(15, 23, 42, .23);
        font: 14px/1.4 system-ui, -apple-system, 'Segoe UI', sans-serif;
        color: #263445;
    }
    .gei-page-loading__spinner {
        flex: 0 0 32px;
        width: 32px;
        height: 32px;
        border: 3px solid #d9e2ef;
        border-top-color: #315b8e;
        border-radius: 50%;
        animation: gei-page-loading-spin .8s linear infinite;
    }
    .gei-page-loading__hint { margin-top: 2px; color: #617083; font-size: 12px; }
    @keyframes gei-page-loading-spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) {
        .gei-page-loading__spinner { animation-duration: 2s; }
    }
</style>
<script>
(function () {
    'use strict';
    // La vista se comparte entre layouts; no duplicar manejadores por página.
    if (window.__geiPageLoadingInstalled) return;
    window.__geiPageLoadingInstalled = true;
    const overlay = document.getElementById('gei-page-loading');
    if (!overlay) return;

    let delayTimer = null;
    let watchdogTimer = null;
    const DELAY_MS = 180;
    const WATCHDOG_MS = 30000;

    function hide() {
        window.clearTimeout(delayTimer);
        window.clearTimeout(watchdogTimer);
        delayTimer = null;
        watchdogTimer = null;
        overlay.hidden = true;
        overlay.setAttribute('aria-hidden', 'true');
    }

    function show() {
        if (document.visibilityState === 'hidden') return;
        window.clearTimeout(delayTimer);
        delayTimer = null;
        overlay.hidden = false;
        overlay.setAttribute('aria-hidden', 'false');
    }

    function schedule() {
        window.clearTimeout(delayTimer);
        window.clearTimeout(watchdogTimer);
        delayTimer = window.setTimeout(show, DELAY_MS);
        watchdogTimer = window.setTimeout(hide, WATCHDOG_MS);
    }

    function optedOut(element) {
        return !!element.closest('[data-gei-loading="off"]');
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 ||
            event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        if (!(event.target instanceof Element)) return;
        const a = event.target.closest('a[href]');
        if (!a || optedOut(a) || a.hasAttribute('download') ||
            a.getAttribute('aria-disabled') === 'true' || a.classList.contains('disabled')) return;
        const target = (a.getAttribute('target') || '_self').toLowerCase();
        if (target !== '_self') return;
        if (a.hasAttribute('data-bs-toggle') || a.hasAttribute('data-bs-target') ||
            a.hasAttribute('data-toggle') || a.hasAttribute('data-target')) return;
        const href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href)) return;
        let url;
        try { url = new URL(a.href, window.location.href); }
        catch (_) { return; }
        if (!/^https?:$/.test(url.protocol) || url.origin !== window.location.origin) return;
        // Un cambio de ancla en la misma página no recarga el documento.
        if (url.pathname === window.location.pathname && url.search === window.location.search) return;
        // Respetar interceptores AJAX y componentes JS que hacen preventDefault().
        queueMicrotask(function () {
            if (!event.defaultPrevented) schedule();
        });
    });

    document.addEventListener('submit', function (event) {
        if (event.defaultPrevented || !(event.target instanceof HTMLFormElement)) return;
        const form = event.target;
        if (optedOut(form)) return;
        const target = (form.getAttribute('target') || '_self').toLowerCase();
        if (target !== '_self' || (form.method || '').toLowerCase() === 'dialog') return;
        if (form.hasAttribute('data-remote') || form.hasAttribute('data-ajax')) return;
        // Si AJAX maneja el envío, nunca se muestra el indicador.
        queueMicrotask(function () {
            if (!event.defaultPrevented) schedule();
        });
    });

    // Incluye las navegaciones iniciadas mediante window.location desde JS.
    window.addEventListener('beforeunload', show);
    // Restauración desde el historial del navegador (bfcache).
    window.addEventListener('pageshow', hide);
    window.addEventListener('popstate', hide);

    // Disponible para casos excepcionales de navegación programática.
    window.geiPageLoading = Object.freeze({ show: show, hide: hide, schedule: schedule });
})();
</script>
@endonce
