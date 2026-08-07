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

    <title>Nueva contraseña | Guastavino e Imbert</title>

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
            <div
                class="gei-login__decoration
                       gei-login__decoration--one"
            ></div>

            <div
                class="gei-login__decoration
                       gei-login__decoration--two"
            ></div>

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
                        Nueva contraseña
                    </h1>

                    <p class="gei-login__description">
                        Elegí una nueva contraseña para ingresar al sistema.
                    </p>
                </header>

                @if ($errors->any())
                    <div
                        class="alert gei-alert gei-alert--error"
                        role="alert"
                    >
                        <div
                            class="gei-alert__icon"
                            aria-hidden="true"
                        >
                            !
                        </div>

                        <div>
                            <strong>
                                No pudimos actualizar la contraseña.
                            </strong>

                            <div>
                                Revisá los datos ingresados.
                            </div>
                        </div>
                    </div>
                @endif

                <form
                    method="POST"
                    action="{{ route('password.update') }}"
                    class="gei-login__form"
                    novalidate
                >
                    @csrf

                    <input
                        type="hidden"
                        name="token"
                        value="{{ $token }}"
                    >

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
                            value="{{ old('email', $email) }}"
                            maxlength="255"
                            autocomplete="email"
                            class="form-control gei-field__control
                                   @error('email') is-invalid @enderror"
                            required
                            readonly
                        >

                        @error('email')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    <div class="gei-field">
                        <label
                            for="password"
                            class="gei-field__label"
                        >
                            Nueva contraseña
                        </label>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            minlength="8"
                            autocomplete="new-password"
                            class="form-control gei-field__control
                                   @error('password') is-invalid @enderror"
                            autofocus
                            required
                        >

                        @error('password')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    <div class="gei-field">
                        <label
                            for="password_confirmation"
                            class="gei-field__label"
                        >
                            Repetir nueva contraseña
                        </label>

                        <input
                            type="password"
                            id="password_confirmation"
                            name="password_confirmation"
                            minlength="8"
                            autocomplete="new-password"
                            class="form-control gei-field__control"
                            required
                        >
                    </div>

                    <button
                        type="submit"
                        class="btn gei-button gei-button--primary"
                    >
                        Guardar nueva contraseña
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
