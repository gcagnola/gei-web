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
        content="#962aa8"
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
</head>

<body class="gei-login-body">
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
