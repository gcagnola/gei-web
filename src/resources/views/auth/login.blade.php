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

    <title>Ingreso | Guastavino e Imbert</title>

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
                        Bienvenido
                    </h1>

                    <p class="gei-login__description">
                        Ingresá tus credenciales para acceder al sistema.
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
                            <strong>No pudimos iniciar sesión.</strong>

                            <div>
                                Revisá el usuario y la contraseña ingresados.
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
                    action="{{ route('login.ingresar') }}"
                    class="gei-login__form"
                    novalidate
                >
                    @csrf

                    <div class="gei-field">
                        <label
                            for="nombre"
                            class="gei-field__label"
                        >
                            Usuario
                        </label>

                        <input
                            type="text"
                            id="nombre"
                            name="nombre"
                            value="{{ old('nombre') }}"
                            maxlength="25"
                            autocomplete="username"
                            class="form-control gei-field__control @error('nombre') is-invalid @enderror"
                            autofocus
                            required
                        >

                        @error('nombre')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    <div class="gei-field">
                        <div class="gei-field__label-row">
                            <label
                                for="clave"
                                class="gei-field__label"
                            >
                                Contraseña
                            </label>
                        </div>

                        <div class="gei-password">
                            <input
                                type="password"
                                id="clave"
                                name="clave"
                                autocomplete="current-password"
                                class="form-control gei-field__control gei-password__input @error('clave') is-invalid @enderror"
                                required
                            >

                            <button
                                type="button"
                                class="gei-password__toggle"
                                id="togglePassword"
                                aria-label="Mostrar contraseña"
                                aria-pressed="false"
                            >
                                <span class="gei-password__show">
                                    Mostrar
                                </span>

                                <span class="gei-password__hide">
                                    Ocultar
                                </span>
                            </button>

                            @error('clave')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                            @enderror
                        </div>
                    </div>

                    <div class="gei-login__options">
                        <div class="form-check">
                            <input
                                type="checkbox"
                                id="recordarme"
                                name="recordarme"
                                value="1"
                                class="form-check-input gei-check"
                                @checked(old('recordarme'))
                            >

                            <label
                                for="recordarme"
                                class="form-check-label gei-check-label"
                            >
                                Recordarme
                            </label>
                        </div>
                    </div>

                    <div class="text-end mb-3">
                        <a
                            href="{{ route('password.request') }}"
                            class="gei-login__forgot-link"
                        >
                            ¿Olvidaste tu contraseña?
                        </a>
                    </div>

                    <button
                        type="submit"
                        class="btn gei-button gei-button--primary"
                    >
                        Ingresar
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