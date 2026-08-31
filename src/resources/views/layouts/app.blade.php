<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#6f1d7a">

    <title>@yield('title', 'Inicio') | Guastavino e Imbert</title>

    <link
        rel="icon"
        type="image/png"
        href="{{ asset('images/gei/favicon.png') }}"
    >

    @vite([
        'resources/css/app.css',
        'resources/js/app.js',
    ])

    <style>
        :root {
            --gei-primary: #962aa8;
            --gei-primary-dark: #70217e;
            --gei-primary-soft: #f7edf9;
            --gei-accent: #aa54b8;
            --gei-bg: #f5f5f7;
            --gei-border: #dedede;
            --gei-text: #394041;
            --gei-muted: #747a7c;
        }

        body.gei-app-body {
            min-height: 100vh;
            margin: 0;
            overflow-x: hidden;
            color: var(--gei-text);
            background: var(--gei-bg);
        }

        .gei-app {
            min-height: 100vh;
        }

        .gei-menu,
        .gei-submenu {
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .gei-menu {
            display: flex;
            align-items: stretch;
            gap: 3px;
        }

        .gei-menu__link,
        .gei-menu__summary {
            display: flex;
            width: 100%;
            min-height: 42px;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border: 0;
            border-radius: 7px;
            color: var(--gei-text);
            background: transparent;
            font-size: .88rem;
            font-weight: 600;
            line-height: 1.25;
            text-align: left;
            text-decoration: none;
            cursor: pointer;
            transition: background-color .18s ease, color .18s ease;
        }

        .gei-menu__link:hover,
        .gei-menu__summary:hover,
        .gei-menu__link:focus-visible,
        .gei-menu__summary:focus-visible {
            color: var(--gei-primary-dark);
            background: var(--gei-primary-soft);
            outline: none;
        }

        .gei-menu__link.is-active,
        .gei-menu__group.is-active > .gei-menu__summary {
            color: var(--gei-primary-dark);
            background: var(--gei-primary-soft);
        }

        .gei-menu__icon {
            display: inline-flex;
            width: 18px;
            flex: 0 0 18px;
            justify-content: center;
            color: var(--gei-primary);
            font-size: .95rem;
        }

        .gei-menu__label {
            flex: 1;
        }

        details.gei-menu__group > summary {
            list-style: none;
        }

        details.gei-menu__group > summary::-webkit-details-marker {
            display: none;
        }

        .gei-menu__chevron {
            width: 7px;
            height: 7px;
            flex: 0 0 7px;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(45deg) translateY(-2px);
            transition: transform .18s ease;
        }

        details[open] > .gei-menu__summary .gei-menu__chevron {
            transform: rotate(225deg) translate(-1px, -1px);
        }

        .gei-menu > li {
            position: relative;
        }

        .gei-menu > li > .gei-menu__group > .gei-submenu {
            position: absolute;
            top: calc(100% + 7px);
            left: 0;
            z-index: 1060;
            display: none;
            width: max-content;
            min-width: 240px;
            max-width: 320px;
            padding: 7px;
            border: 1px solid var(--gei-border);
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 14px 35px rgba(48, 36, 51, .14);
        }

        .gei-menu > li > .gei-menu__group[open] > .gei-submenu {
            display: block;
        }

        .gei-submenu .gei-menu__link,
        .gei-submenu .gei-menu__summary {
            min-height: 39px;
            padding: 8px 10px;
            font-size: .87rem;
        }

        .gei-submenu .gei-menu__link.is-active,
        .gei-submenu .gei-menu__group.is-active > .gei-menu__summary {
            box-shadow: inset 3px 0 0 var(--gei-accent);
        }

        .gei-submenu .gei-submenu {
            display: none;
            margin: 2px 0 5px 12px;
            padding-left: 8px;
            border-left: 1px solid var(--gei-border);
        }

        .gei-submenu .gei-menu__group[open] > .gei-submenu {
            display: block;
        }

        .gei-header {
            position: sticky;
            top: 0;
            z-index: 1050;
            border-bottom: 1px solid var(--gei-border);
            background: rgba(255, 255, 255, .97);
            box-shadow: 0 5px 18px rgba(48, 36, 51, .06);
            backdrop-filter: blur(8px);
        }

        .gei-topbar {
            display: flex;
            min-height: 68px;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 10px 28px;
        }

        .gei-brand {
            display: flex;
            min-width: 0;
            align-items: center;
            gap: 15px;
            color: var(--gei-text);
            text-decoration: none;
        }

        .gei-brand img {
            display: block;
            width: 48px;
            height: 48px;
            padding: 6px;
            border-radius: 10px;
            background: var(--gei-primary);
            box-shadow: 0 8px 18px rgba(150, 42, 168, .18);
            object-fit: contain;
        }

        .gei-topbar__user {
            display: flex;
            align-items: center;
        }

        .gei-topbar__title {
            overflow: hidden;
            margin: 0;
            font-size: 1.15rem;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .gei-topbar__user {
            gap: 12px;
        }

        .gei-user-avatar {
            display: grid;
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            place-items: center;
            border-radius: 50%;
            color: var(--gei-primary-dark);
            background: var(--gei-primary-soft);
            font-weight: 800;
        }

        .gei-user-data {
            min-width: 0;
            line-height: 1.2;
        }

        .gei-user-data strong,
        .gei-user-data small {
            display: block;
            max-width: 230px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .gei-user-data small {
            margin-top: 3px;
            color: var(--gei-muted);
        }

        .gei-logout {
            display: inline-flex;
            min-height: 38px;
            align-items: center;
            padding: 7px 13px;
            border: 1px solid var(--gei-border);
            border-radius: 8px;
            color: var(--gei-text);
            background: #fff;
            font-weight: 600;
            transition: border-color .18s ease, color .18s ease, background .18s ease;
        }

        .gei-logout:hover {
            border-color: #cbbdce;
            color: var(--gei-primary-dark);
            background: var(--gei-primary-soft);
        }

        .gei-menu-toggle {
            display: none;
            width: 42px;
            height: 42px;
            place-items: center;
            border: 1px solid var(--gei-border);
            border-radius: 8px;
            color: var(--gei-primary-dark);
            background: #fff;
            font-size: 1.3rem;
        }

        .gei-navigation {
            padding: 0 22px 9px;
        }

        .gei-content {
            width: 100%;
            padding: 28px;
        }

        .gei-page-heading {
            margin-bottom: 24px;
        }

        .gei-page-heading h1 {
            margin: 0 0 7px;
            font-size: clamp(1.55rem, 2.3vw, 2.1rem);
            font-weight: 750;
        }

        .gei-page-heading p {
            margin: 0;
            color: var(--gei-muted);
        }

        .gei-card {
            border: 1px solid var(--gei-border);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 10px 30px rgba(48, 36, 51, .05);
        }

        .gei-welcome {
            position: relative;
            overflow: hidden;
            padding: clamp(24px, 4vw, 42px);
            color: #fff;
            background: linear-gradient(120deg, var(--gei-primary-dark), var(--gei-primary));
        }

        .gei-welcome::after {
            position: absolute;
            right: -70px;
            bottom: -110px;
            width: 270px;
            height: 270px;
            border: 45px solid rgba(255, 255, 255, .08);
            border-radius: 50%;
            content: '';
        }

        .gei-welcome__content {
            position: relative;
            z-index: 1;
            max-width: 680px;
        }

        .gei-welcome h1 {
            margin: 0 0 12px;
            font-size: clamp(1.7rem, 3vw, 2.45rem);
            font-weight: 750;
        }

        .gei-welcome p {
            max-width: 610px;
            margin: 0;
            color: rgba(255, 255, 255, .82);
            font-size: 1.02rem;
        }

        .gei-module-placeholder {
            padding: clamp(28px, 5vw, 56px);
            text-align: center;
        }

        .gei-module-placeholder__icon {
            display: grid;
            width: 72px;
            height: 72px;
            margin: 0 auto 20px;
            place-items: center;
            border-radius: 20px;
            color: var(--gei-primary);
            background: var(--gei-primary-soft);
            font-size: 2rem;
        }

        .gei-module-placeholder h2 {
            margin-bottom: 10px;
            font-size: 1.45rem;
        }

        .gei-module-placeholder p {
            margin: 0;
            color: var(--gei-muted);
        }


        .gei-process-overlay {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(28, 24, 30, .58);
            backdrop-filter: blur(2px);
        }

        .gei-process-overlay.is-visible {
            display: flex;
        }

        .gei-process-dialog {
            width: min(520px, 100%);
            padding: 28px;
            border: 1px solid rgba(255, 255, 255, .35);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 24px 70px rgba(24, 17, 26, .28);
            text-align: center;
        }

        .gei-process-spinner {
            width: 54px;
            height: 54px;
            margin: 0 auto 18px;
            border: 5px solid var(--gei-primary-soft);
            border-top-color: var(--gei-primary);
            border-radius: 50%;
            animation: gei-process-spin .85s linear infinite;
        }

        .gei-process-title {
            margin: 0 0 8px;
            color: var(--gei-primary-dark);
            font-size: 1.2rem;
            font-weight: 750;
        }

        .gei-process-message {
            margin: 0;
            color: var(--gei-muted);
            line-height: 1.45;
        }

        .gei-process-time {
            margin-top: 18px;
            padding: 12px 14px;
            border-radius: 10px;
            background: var(--gei-primary-soft);
            color: var(--gei-primary-dark);
            font-variant-numeric: tabular-nums;
            font-size: 1.05rem;
            font-weight: 700;
        }

        .gei-process-note {
            margin-top: 12px;
            color: var(--gei-muted);
            font-size: .85rem;
        }

        @keyframes gei-process-spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 991.98px) {
            .gei-menu-toggle {
                display: grid;
            }

            .gei-navigation {
                display: none;
                max-height: calc(100vh - 68px);
                overflow-y: auto;
                padding: 8px 15px 16px;
                border-top: 1px solid var(--gei-border);
            }

            body.gei-menu-open .gei-navigation {
                display: block;
            }

            .gei-menu {
                display: block;
            }

            .gei-menu > li + li {
                margin-top: 3px;
            }

            .gei-menu > li > .gei-menu__group > .gei-submenu {
                position: static;
                width: auto;
                min-width: 0;
                max-width: none;
                margin: 2px 0 6px 16px;
                padding: 3px 0 3px 9px;
                border: 0;
                border-left: 1px solid var(--gei-border);
                border-radius: 0;
                box-shadow: none;
            }
        }

        @media (max-width: 767.98px) {
            .gei-topbar {
                min-height: 64px;
                padding: 10px 15px;
            }

            .gei-brand .gei-topbar__title,
            .gei-user-data,
            .gei-logout__text {
                display: none;
            }

            .gei-logout {
                width: 40px;
                height: 40px;
                justify-content: center;
                padding: 0;
                font-size: 1.1rem;
            }

            .gei-content {
                padding: 20px 15px;
            }
        }
    </style>

    @stack('styles')
</head>
<body class="gei-app-body">
    <div class="gei-app">
        <header class="gei-header">
            <div class="gei-topbar">
                <a
                    href="{{ route('inicio') }}"
                    class="gei-brand"
                    aria-label="Ir al inicio"
                >
                    <img
                        src="{{ asset('images/gei/logo-compacto.webp') }}"
                        alt=""
                        aria-hidden="true"
                    >

                    <h2 class="gei-topbar__title">
                        @yield('page-title', 'Inicio')
                    </h2>
                </a>

                <div class="gei-topbar__user">
                    <button
                        type="button"
                        class="gei-menu-toggle"
                        id="geiMenuToggle"
                        aria-controls="geiNavigation"
                        aria-expanded="false"
                        aria-label="Abrir menú"
                    >
                        ☰
                    </button>
                    <div class="gei-user-avatar" aria-hidden="true">
                        {{ mb_strtoupper(mb_substr(auth()->user()->nombre_limpio ?? 'U', 0, 1)) }}
                    </div>

                    <div class="gei-user-data">
                        <strong>{{ auth()->user()->nombre_limpio }}</strong>
                        <small>{{ auth()->user()->tipo_usuario_limpio }}</small>
                    </div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf

                        <button
                            type="submit"
                            class="gei-logout"
                            title="Cerrar sesión"
                        >
                            <span aria-hidden="true">↪</span>
                            <span class="gei-logout__text ms-2">Salir</span>
                        </button>
                    </form>
                </div>
            </div>

            <nav
                class="gei-navigation"
                id="geiNavigation"
                aria-label="Menú principal"
            >
                @include('components.menu-principal')
            </nav>
        </header>

        <main class="gei-content">
            @yield('content')
        </main>
    </div>


    <div
        class="gei-process-overlay"
        id="geiProcessOverlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="geiProcessTitle"
        aria-describedby="geiProcessMessage"
        aria-hidden="true"
    >
        <div class="gei-process-dialog">
            <div class="gei-process-spinner" aria-hidden="true"></div>
            <h2 class="gei-process-title" id="geiProcessTitle">Procesando</h2>
            <p class="gei-process-message" id="geiProcessMessage">La operación está en ejecución.</p>
            <div class="gei-process-time">
                Tiempo transcurrido: <span id="geiProcessElapsed">00:00:00</span>
            </div>
            <div class="gei-process-note">No cierre ni recargue esta ventana hasta que finalice el proceso.</div>
        </div>
    </div>

    <script>
        (() => {
            const body = document.body;
            const toggle = document.getElementById('geiMenuToggle');
            const navigation = document.getElementById('geiNavigation');
            const menuGroups = navigation?.querySelectorAll('details');

            const closeMenu = () => {
                body.classList.remove('gei-menu-open');
                toggle?.setAttribute('aria-expanded', 'false');
            };

            toggle?.addEventListener('click', () => {
                const isOpen = body.classList.toggle('gei-menu-open');
                toggle.setAttribute('aria-expanded', String(isOpen));
            });

            menuGroups?.forEach((group) => {
                group.addEventListener('toggle', () => {
                    if (!group.open) {
                        return;
                    }

                    const siblings = group.parentElement
                        ?.parentElement
                        ?.querySelectorAll(':scope > li > details[open]');

                    siblings?.forEach((sibling) => {
                        if (sibling !== group) {
                            sibling.removeAttribute('open');
                        }
                    });
                });
            });

            document.addEventListener('click', (event) => {
                if (
                    window.innerWidth >= 992
                    && navigation
                    && !navigation.contains(event.target)
                ) {
                    menuGroups?.forEach((group) => {
                        group.removeAttribute('open');
                    });
                }
            });

            navigation?.querySelectorAll('a').forEach((link) => {
                link.addEventListener('click', closeMenu);
            });

            window.addEventListener('resize', () => {
                if (window.innerWidth >= 992) {
                    closeMenu();
                }
            });
        })();
    </script>


    <script>
        (() => {
            const overlay = document.getElementById('geiProcessOverlay');
            const title = document.getElementById('geiProcessTitle');
            const message = document.getElementById('geiProcessMessage');
            const elapsed = document.getElementById('geiProcessElapsed');
            let timer = null;
            let startedAt = null;

            const formatElapsed = (totalSeconds) => {
                const seconds = Math.max(0, Math.floor(totalSeconds));
                const h = String(Math.floor(seconds / 3600)).padStart(2, '0');
                const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
                const s = String(seconds % 60).padStart(2, '0');
                return `${h}:${m}:${s}`;
            };

            const updateElapsed = () => {
                if (!startedAt || !elapsed) return;
                elapsed.textContent = formatElapsed((Date.now() - startedAt) / 1000);
            };

            const abrir = (titulo = 'Procesando', mensaje = 'La operación está en ejecución.') => {
                if (!overlay) return;
                title.textContent = titulo;
                message.textContent = mensaje;
                startedAt = Date.now();
                updateElapsed();
                if (timer) clearInterval(timer);
                timer = window.setInterval(updateElapsed, 1000);
                overlay.classList.add('is-visible');
                overlay.setAttribute('aria-hidden', 'false');
                document.body.setAttribute('aria-busy', 'true');
            };

            const cerrar = () => {
                if (timer) clearInterval(timer);
                timer = null;
                startedAt = null;
                if (elapsed) elapsed.textContent = '00:00:00';
                overlay?.classList.remove('is-visible');
                overlay?.setAttribute('aria-hidden', 'true');
                document.body.removeAttribute('aria-busy');
            };

            window.GeIProceso = { abrir, cerrar };

            document.addEventListener('submit', (event) => {
                const form = event.target instanceof HTMLFormElement ? event.target : null;
                if (!form?.matches('[data-gei-process]')) return;

                // El listener está en document (fase bubble): los onsubmit y
                // confirm del formulario ya se ejecutaron. Si cancelaron, no abrir.
                if (event.defaultPrevented) return;

                abrir(
                    form.dataset.geiProcessTitle || 'Procesando',
                    form.dataset.geiProcessMessage || 'La operación está en ejecución.'
                );

                form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((control) => {
                    control.disabled = true;
                });
            });

            window.addEventListener('pageshow', cerrar);
        })();
    </script>

    @stack('scripts')
</body>
</html>
