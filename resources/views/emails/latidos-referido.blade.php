<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ganaste Latidos</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    <p>Hola {{ $nombre ?: 'doctor' }}:</p>

    <p>
        @if ($esReferente)
            Un colega que invitaste ya tiene su cuenta aprobada.
        @else
            Llegaste a Doctorísimo con la invitación de un colega y tu cuenta ya está aprobada.
        @endif
        Ganaste <strong>{{ \App\Latidos\Conversion::formatear($latidos) }}</strong>.
    </p>

    <p>
        Los Latidos se usan como descuento en los planes de Doctorísimo:
        <strong>100 Latidos = USD 1 de descuento</strong>. No son dinero, no se retiran y no se transfieren.
    </p>

    <p style="margin-top: 24px;">Gracias por seguir con Doctorisimo.</p>
</body>
</html>
