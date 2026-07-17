<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recuperación de contraseña</title>
</head>
<body style="font-family: Arial, sans-serif; color: #394041;">
    <h2>Guastavino e Imbert — Administración</h2>

    <p>Hola {{ $nombreUsuario }}:</p>

    <p>
        Recibimos una solicitud para restablecer tu contraseña de acceso
        al sistema administrativo.
    </p>

    <p>
        <a
            href="{{ $urlRecuperacion }}"
            style="
                display: inline-block;
                padding: 12px 20px;
                background: #962aa8;
                color: #ffffff;
                text-decoration: none;
                border-radius: 6px;
            "
        >
            Restablecer contraseña
        </a>
    </p>

    <p>
        Este enlace tendrá una validez limitada. Si no solicitaste el cambio,
        podés ignorar este correo.
    </p>

    <p>
        La tranquilidad de un siglo de experiencia
    </p>
</body>
</html>