<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Recuperar contraseña</title></head>
<body style="font-family: Arial, sans-serif; color: #394041;">
    <h2>Guastavino e Imbert — Autogestión</h2>
    <p>Hola {{ $nombreCliente }}:</p>
    <p>Recibimos una solicitud para restablecer la contraseña de tu acceso a Autogestión.</p>
    <p>Usuario: <strong>{{ $identificador }}</strong></p>
    <p><a href="{{ $urlRecuperacion }}" style="display:inline-block;padding:12px 20px;background:#962aa8;color:#fff;text-decoration:none;border-radius:6px;">Restablecer contraseña</a></p>
    <p>El enlace vence el {{ $venceAt->format('d/m/Y H:i') }}.</p>
    <p>Si no solicitaste el cambio, podés ignorar este correo.</p>
</body>
</html>
