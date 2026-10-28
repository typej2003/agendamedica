<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Te invitaron a un equipo</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    <p>
        <strong>{{ $invitador }}</strong> te invita a unirte al equipo de
        <strong>{{ $equipo }}</strong> en Doctorísimo.
    </p>

    <p>
        Al aceptar podrás compartir la agenda, los pacientes y la secretaría de ese consultorio.
        Tu ficha, tu registro y tus propios datos no se mueven.
    </p>

    <p>
        <a href="{{ $url }}" style="display: inline-block; padding: 10px 18px; background: #0F6E5C; color: #ffffff; text-decoration: none; border-radius: 6px;">
            Abrir la invitación
        </a>
    </p>

    <p style="font-size: 13px; color: #55555f;">
        La invitación vence el {{ \Carbon\Carbon::parse($invitacion->expira_el)->format('d/m/Y') }}.
        Si no tienes cuenta, regístrate como médico con este mismo correo y después abre el enlace.
    </p>
</body>
</html>
