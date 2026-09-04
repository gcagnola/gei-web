@php
    $geiEntorno = mb_strtoupper(trim((string) env('GEI_ENTORNO', '')));
    $geiEsTest = $geiEntorno === 'TEST';
    $geiEsDesarrollo = ! $geiEsTest && app()->environment(['local', 'development', 'testing']);
@endphp

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="theme-color"
        content="{{ $geiEsTest ? '#3f6f86' : ($geiEsDesarrollo ? '#555b63' : '#962aa8') }}"
    >

    <title>Recuperar contraseña | Guastavino e Imbert</title>

    <link
        rel="icon"
        type="image/png"
        href="{{ asset('images/gei/favicon.png') }}"
    >

    @vite([
        'resources/css/app.css',
        'resources/js/app.js',
    ])

    @if ($geiEsTest)
        <style>
            :root {
                --gei-primary: #3f6f86;
                --gei-primary-hover: #355f73;
                --gei-primary-active: #2f5567;
                --gei-primary-light: #edf4f7;
                --gei-primary-soft: #e1edf2;
                --gei-secondary: #5c879a;
                --gei-secondary-dark: #355f73;
                --gei-border-focus: #5c879a;
                --gei-background: #f3f7f9;
            }

            body.gei-login-body {
                background: #f3f7f9;
            }

            .gei-login__brand {
                background:
                    linear-gradient(145deg, #294a59 0%, #3f6f86 58%, #668da0 100%) !important;
            }

            .gei-login__brand::before,
            .gei-login__brand::after {
                opacity: .7;
            }

            .gei-login__compact-logo {
                background: #3f6f86 !important;
                box-shadow: 0 8px 18px rgba(63, 111, 134, .22) !important;
            }

            .gei-login__eyebrow,
            .gei-login__forgot-link,
            .gei-login__back-link {
                color: #355f73 !important;
            }

            .gei-login__forgot-link:hover,
            .gei-login__forgot-link:focus-visible,
            .gei-login__back-link:hover,
            .gei-login__back-link:focus-visible {
                color: #2f5567 !important;
            }

            .gei-field__control:focus,
            .gei-check:focus {
                border-color: #5c879a !important;
                box-shadow: 0 0 0 .2rem rgba(63, 111, 134, .15) !important;
            }

            .gei-check:checked {
                border-color: #3f6f86 !important;
                background-color: #3f6f86 !important;
            }

            .gei-button--primary.btn {
                border-color: #3f6f86 !important;
                background-color: #3f6f86 !important;
            }

            .gei-button--primary.btn:hover,
            .gei-button--primary.btn:focus {
                border-color: #355f73 !important;
                background-color: #355f73 !important;
            }

            .gei-button--primary.btn:active {
                border-color: #2f5567 !important;
                background-color: #2f5567 !important;
            }

            .gei-login__environment {
                display: inline-flex;
                align-items: center;
                gap: 7px;
                margin-bottom: 14px;
                padding: 6px 10px;
                border: 1px solid #6f98aa;
                border-radius: 999px;
                color: #294a59;
                background: #dceaf0;
                font-size: .72rem;
                font-weight: 800;
                letter-spacing: .06em;
                line-height: 1;
                text-transform: uppercase;
            }

            .gei-login__environment::before {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                background: #3f6f86;
                content: '';
                box-shadow: 0 0 0 3px rgba(63, 111, 134, .16);
            }
        </style>
    @elseif ($geiEsDesarrollo)
        <style>
            :root {
                --gei-primary: #666b73;
                --gei-primary-hover: #565b62;
                --gei-primary-active: #454a52;
                --gei-primary-light: #f0f1f3;
                --gei-primary-soft: #e3e5e8;
                --gei-secondary: #7b8088;
                --gei-secondary-dark: #565b62;
                --gei-border-focus: #7b8088;
                --gei-background: #f1f2f4;
            }

            body.gei-login-body {
                background: #f1f2f4;
            }

            .gei-login__brand {
                background:
                    linear-gradient(145deg, #3f444b 0%, #555b63 58%, #696f77 100%) !important;
            }

            .gei-login__brand::before,
            .gei-login__brand::after {
                opacity: .7;
            }

            .gei-login__compact-logo {
                background: #666b73 !important;
                box-shadow: 0 8px 18px rgba(69, 74, 82, .20) !important;
            }

            .gei-login__eyebrow,
            .gei-login__forgot-link,
            .gei-login__back-link {
                color: #565b62 !important;
            }

            .gei-login__forgot-link:hover,
            .gei-login__forgot-link:focus-visible,
            .gei-login__back-link:hover,
            .gei-login__back-link:focus-visible {
                color: #454a52 !important;
            }

            .gei-field__control:focus {
                border-color: #7b8088 !important;
                box-shadow: 0 0 0 .2rem rgba(102, 107, 115, .15) !important;
            }

            .gei-button--primary.btn {
                border-color: #666b73 !important;
                background-color: #666b73 !important;
            }

            .gei-button--primary.btn:hover,
            .gei-button--primary.btn:focus {
                border-color: #565b62 !important;
                background-color: #565b62 !important;
            }

            .gei-button--primary.btn:active {
                border-color: #454a52 !important;
                background-color: #454a52 !important;
            }

            .gei-login__environment {
                display: inline-flex;
                align-items: center;
                gap: 7px;
                margin-bottom: 14px;
                padding: 6px 10px;
                border: 1px solid #92979e;
                border-radius: 999px;
                color: #363b42;
                background: #e1e3e6;
                font-size: .72rem;
                font-weight: 800;
                letter-spacing: .06em;
                line-height: 1;
                text-transform: uppercase;
            }

            .gei-login__environment::before {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                background: #666b73;
                content: '';
                box-shadow: 0 0 0 3px rgba(102, 107, 115, .16);
            }
        </style>
    @endif
</head>

<body class="gei-login-body{{ $geiEsTest ? ' gei-is-test' : ($geiEsDesarrollo ? ' gei-is-development' : '') }}">
    <main class="gei-login">
        <section class="gei-login__brand">
            <div class="gei-login__decoration gei-login__decoration--one"></div>
            <div class="gei-login__decoration gei-login__decoration--two"></div>

            <div class="gei-login__brand-content">
                <img
                    src="{{ asset('images/gei/logo-horizontal-blanco.webp') }}"
                    alt="Guastavino e Imbert Inmobiliaria"
                    class="gei-login__logo"
                >

                <div class="gei-login__separator"></div>

                <p class="gei-login__claim">
                    La tranquilidad de un siglo de experiencia
                </p>
            </div>

            <p class="gei-login__brand-footer">
                Guastavino e Imbert — Administración
            </p>
        </section>

        <section class="gei-login__form-panel">
            <div class="gei-login__form-container">
                <a
                    href="{{ route('login') }}"
                    class="gei-login__back-link"
                >
                    ← Volver al inicio de sesión
                </a>

                <header class="gei-login__header">
                    @if ($geiEsTest)
                        <div class="gei-login__environment">
                            Test / Preproducción
                        </div>
                    @elseif ($geiEsDesarrollo)
                        <div class="gei-login__environment">
                            Desarrollo / Pruebas
                        </div>
                    @endif

                    <img
                        src="{{ asset('images/gei/logo-compacto.webp') }}"
                        alt=""
                        class="gei-login__compact-logo"
                        aria-hidden="true"
                    >

                    <p class="gei-login__eyebrow">
                        Sistema administrativo
                    </p>

                    <h1 class="gei-login__title">
                        Recuperar contraseña
                    </h1>

                    <p class="gei-login__description">
                        Ingresá el correo asociado a tu cuenta. Te enviaremos
                        un enlace para crear una nueva contraseña web.
                    </p>
                </header>

                @if ($errors->any())
                    <div
                        class="alert gei-alert gei-alert--error"
                        role="alert"
                    >
                        <div class="gei-alert__icon" aria-hidden="true">
                            !
                        </div>

                        <div>
                            <strong>No pudimos procesar la solicitud.</strong>

                            <div>
                                Revisá el correo electrónico ingresado.
                            </div>
                        </div>
                    </div>
                @endif

                @if (session('estado'))
                    <div
                        class="alert alert-success"
                        role="alert"
                    >
                        {{ session('estado') }}
                    </div>
                @endif

                <form
                    method="POST"
                    action="{{ route('password.email') }}"
                    class="gei-login__form"
                    novalidate
                >
                    @csrf

                    <div class="gei-field">
                        <label
                            for="email"
                            class="gei-field__label"
                        >
                            Correo electrónico
                        </label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="{{ old('email') }}"
                            maxlength="255"
                            autocomplete="email"
                            class="form-control gei-field__control @error('email') is-invalid @enderror"
                            autofocus
                            required
                        >

                        @error('email')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    <button
                        type="submit"
                        class="btn gei-button gei-button--primary"
                    >
                        Enviar enlace de recuperación
                    </button>
                </form>

                <footer class="gei-login__footer">
                    <span>Guastavino e Imbert</span>
                    <span aria-hidden="true">·</span>
                    <span>Administración</span>
                </footer>
            </div>
        </section>
    </main>
</body>
</html>
