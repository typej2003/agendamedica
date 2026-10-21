<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibimos tu solicitud</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    <p>Hola {{ $solicitud->nombreCompleto() }}:</p>

    <p>
        Recibimos tu solicitud de registro en Doctorisimo. La revisamos para confirmar tus datos profesionales
        (cédula y registro del MPPS) y te escribimos a este correo cuando esté lista.
    </p>

    <p style="color: #55555f;">Mientras tanto no hace falta que hagas nada más.</p>
</body>
</html>
