<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Tu cuenta está lista</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    <p>Hola {{ $solicitud->nombreCompleto() }}:</p>

    <p>Aprobamos tu solicitud. Ya puedes entrar a Doctorisimo con estos datos:</p>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-size: 14px;">
        <tr><td style="color: #55555f;">Correo</td><td><strong>{{ $solicitud->email }}</strong></td></tr>
        <tr><td style="color: #55555f;">Clave temporal</td><td><strong style="font-family: monospace; font-size: 16px;">{{ $clave }}</strong></td></tr>
    </table>

    <p>Al entrar por primera vez te vamos a pedir que elijas tu propia clave.</p>

    <p style="margin-top: 24px;">
        <a href="{{ route('login') }}">Iniciar sesión</a>
    </p>
</body>
</html>
