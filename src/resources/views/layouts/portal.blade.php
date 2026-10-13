@php
    $geiEntorno = mb_strtoupper(trim((string) env('GEI_ENTORNO', '')));
    $geiEsTest = $geiEntorno === 'TEST';
    $geiEsDesarrollo = ! $geiEsTest && app()->environment(['local', 'development', 'testing']);

    $portalUsuarioCabecera = auth('portal')->user();
    $portalClienteCabecera = $portalUsuarioCabecera?->cliente;

    $portalNombreCabecera = trim((string) (
        $portalClienteCabecera?->nombre_visible
        ?? $portalClienteCabecera?->nombre
        ?? 'Cliente'
    ));

    $portalInicialCabecera = mb_strtoupper(
        mb_substr($portalNombreCabecera !== '' ? $portalNombreCabecera : 'C', 0, 1)
    );
@endphp

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta
        name="theme-color"
        content="{{ $geiEsTest ? '#3f6f86' : ($geiEsDesarrollo ? '#555b63' : '#6f1d7a') }}"
    >

    <title>@yield('title', 'Autogestión') | Guastavino e Imbert</title>

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
            @if ($geiEsTest)
                --gei-primary: #3f6f86;
                --gei-primary-dark: #2f5567;
                --gei-primary-soft: #e7f0f4;
                --gei-accent: #5c879a;
                --gei-bg: #f3f7f9;
                --gei-border: #c8d6dd;
                --gei-text: #334047;
                --gei-muted: #687981;
            @elseif ($geiEsDesarrollo)
                --gei-primary: #666b73;
                --gei-primary-dark: #454a52;
                --gei-primary-soft: #eceef1;
                --gei-accent: #7b8088;
                --gei-bg: #f1f2f4;
                --gei-border: #cfd3d8;
                --gei-text: #34383d;
                --gei-muted: #6d737a;
            @else
                --gei-primary: #962aa8;
                --gei-primary-dark: #70217e;
                --gei-primary-soft: #f7edf9;
                --gei-accent: #aa54b8;
                --gei-bg: #f5f5f7;
                --gei-border: #dedede;
                --gei-text: #394041;
                --gei-muted: #747a7c;
            @endif
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

        .gei-brand:hover {
            color: var(--gei-text);
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

        .gei-topbar__title {
            overflow: hidden;
            margin: 0;
            font-size: 1.15rem;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .gei-topbar__user {
            display: flex;
            align-items: center;
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
            max-width: 260px;
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

        .gei-content {
            width: 100%;
            padding: 28px;
        }

        .gei-portal-content {
            width: min(100%, 980px);
            margin: 0 auto;
        }

        .gei-environment-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-left: 10px;
            padding: 5px 10px;
            border: 1px solid #858b93;
            border-radius: 999px;
            color: #363b42;
            background: #dfe2e6;
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .06em;
            line-height: 1;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .gei-environment-badge::before {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #666b73;
            content: '';
            box-shadow: 0 0 0 3px rgba(102, 107, 115, .16);
        }

        body.gei-is-development .gei-header {
            border-bottom-color: #aeb3ba;
            box-shadow: 0 5px 18px rgba(64, 69, 76, .10);
        }

        body.gei-is-development .gei-brand img {
            box-shadow: 0 8px 18px rgba(69, 74, 82, .20);
        }

        @if ($geiEsTest)
            :root {
                --bs-primary: #3f6f86;
                --bs-primary-rgb: 63, 111, 134;
                --bs-link-color: #355f73;
                --bs-link-hover-color: #2f5567;
            }

            body.gei-is-test .btn-primary {
                --bs-btn-bg: #3f6f86;
                --bs-btn-border-color: #3f6f86;
                --bs-btn-hover-bg: #355f73;
                --bs-btn-hover-border-color: #355f73;
                --bs-btn-active-bg: #2f5567;
                --bs-btn-active-border-color: #2f5567;
            }

            body.gei-is-test .btn-outline-primary {
                --bs-btn-color: #355f73;
                --bs-btn-border-color: #5c879a;
                --bs-btn-hover-color: #fff;
                --bs-btn-hover-bg: #3f6f86;
                --bs-btn-hover-border-color: #3f6f86;
            }

            body.gei-is-test .gei-environment-badge {
                border-color: #6f98aa;
                color: #294c5d;
                background: #dceaf0;
            }

            body.gei-is-test .gei-environment-badge::before {
                background: #3f6f86;
                box-shadow: 0 0 0 3px rgba(63, 111, 134, .16);
            }
        @elseif ($geiEsDesarrollo)
            :root {
                --bs-primary: #666b73;
                --bs-primary-rgb: 102, 107, 115;
                --bs-link-color: #555b63;
                --bs-link-hover-color: #454a52;
            }

            body.gei-is-development .btn-primary {
                --bs-btn-bg: #666b73;
                --bs-btn-border-color: #666b73;
                --bs-btn-hover-bg: #555b63;
                --bs-btn-hover-border-color: #555b63;
                --bs-btn-active-bg: #454a52;
                --bs-btn-active-border-color: #454a52;
            }

            body.gei-is-development .btn-outline-primary {
                --bs-btn-color: #555b63;
                --bs-btn-border-color: #7b8088;
                --bs-btn-hover-color: #fff;
                --bs-btn-hover-bg: #666b73;
                --bs-btn-hover-border-color: #666b73;
            }
        @endif

        @media (max-width: 767.98px) {
            .gei-topbar {
                padding: 9px 14px;
            }

            .gei-brand .gei-topbar__title,
            .gei-user-data,
            .gei-logout__text {
                display: none;
            }

            .gei-environment-badge {
                margin-left: 0;
                padding: 5px 8px;
                font-size: .64rem;
            }

            .gei-content {
                padding: 20px 15px;
            }

            .gei-logout {
                width: 40px;
                height: 40px;
                justify-content: center;
                padding: 0;
                font-size: 1.1rem;
            }
        }
    </style>

    @stack('styles')
</head>

<body class="gei-app-body{{ $geiEsTest ? ' gei-is-test' : ($geiEsDesarrollo ? ' gei-is-development' : '') }}">
    <div class="gei-app">
        <header class="gei-header">
            <div class="gei-topbar">
                <a
                    href="{{ route('portal.inicio') }}"
                    class="gei-brand"
                    aria-label="Ir al inicio de Autogestión"
                >
                    <img
                        src="{{ asset('images/gei/logo-compacto.webp') }}"
                        alt=""
                        aria-hidden="true"
                    >

                    <h2 class="gei-topbar__title">
                        @yield('page-title', 'Autogestión')
                    </h2>

                    @if ($geiEsTest)
                        <span
                            class="gei-environment-badge"
                            title="Entorno de test / preproducción"
                        >
                            Test / Preproducción
                        </span>
                    @elseif ($geiEsDesarrollo)
                        <span
                            class="gei-environment-badge"
                            title="Entorno no productivo"
                        >
                            Desarrollo / Pruebas
                        </span>
                    @endif
                </a>

                @auth('portal')
                    <div class="gei-topbar__user">
                        <div class="gei-user-avatar" aria-hidden="true">
                            {{ $portalInicialCabecera }}
                        </div>

                        <div class="gei-user-data">
                            <strong>{{ $portalNombreCabecera }}</strong>
                            <small>Cliente</small>
                        </div>

                        <form method="POST" action="{{ route('portal.logout') }}">
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
                @endauth
            </div>
        </header>

        <main class="gei-content">
            <div class="gei-portal-content">
                @if (session('estado'))
                    <div class="alert alert-success">
                        {{ session('estado') }}
                    </div>
                @endif

                @yield('content')
            </div>
        </main>
    </div>

    @stack('scripts')
</body>
</html>
