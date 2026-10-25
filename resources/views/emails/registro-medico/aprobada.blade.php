<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $clave ? 'Tu cuenta está lista' : 'Tu área médica está habilitada' }}</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    <p>Hola {{ $solicitud->nombreCompleto() }}:</p>

    @if ($clave)
        <p>Aprobamos tu solicitud. Ya puedes entrar a Doctorisimo con estos datos:</p>

        <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-size: 14px;">
            <tr><td style="color: #55555f;">Correo</td><td><strong>{{ $solicitud->email }}</strong></td></tr>
            <tr><td style="color: #55555f;">Clave temporal</td><td><strong style="font-family: monospace; font-size: 16px;">{{ $clave }}</strong></td></tr>
        </table>

        <p>Al entrar por primera vez te vamos a pedir que elijas tu propia clave.</p>
    @else
        <p>Aprobamos tu solicitud. Tu cuenta <strong>ya existía</strong>, así que no cambiamos tu contraseña: entra con la de siempre.</p>

        <p>Ya puedes atender. Al iniciar sesión te vamos a preguntar en qué área quieres trabajar: administración, consultorio o tus citas como paciente.</p>

        <p style="color: #55555f; font-size: 14px;">Correo de tu cuenta: <strong>{{ $solicitud->email }}</strong></p>
    @endif

    <p style="margin-top: 24px;">
        <a href="{{ route('login') }}">Iniciar sesión</a>
    </p>
</body>
</html>
