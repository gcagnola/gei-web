<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Activar Autogestión</title></head>
<body style="font-family: Arial, sans-serif; color: #394041;">
    <h2>Guastavino e Imbert — Autogestión</h2>
    <p>Hola {{ $nombreCliente }}:</p>
    <p>Habilitamos tu acceso al portal de Autogestión.</p>
    <p>Tu usuario es: <strong>{{ $identificador }}</strong></p>
    <p>Usá el siguiente enlace para definir tu contraseña:</p>
    <p><a href="{{ $urlActivacion }}" style="display:inline-block;padding:12px 20px;background:#962aa8;color:#fff;text-decoration:none;border-radius:6px;">Activar mi acceso</a></p>
    <p>El enlace vence el {{ $venceAt->format('d/m/Y H:i') }}.</p>
    <p>Si no solicitaste este acceso, comunicate con Guastavino e Imbert.</p>
</body>
</html>
