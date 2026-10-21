<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Tu código de registro</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    <p>Hola {{ $nombre }}:</p>

    <p>Este es tu código para confirmar el correo de tu solicitud de registro en Doctorisimo:</p>

    <p style="font-size: 28px; font-weight: bold; letter-spacing: 6px; margin: 16px 0;">{{ $codigo }}</p>

    <p style="color: #55555f;">Vence en 15 minutos. Si no fuiste tú, ignora este correo.</p>
</body>
</html>
