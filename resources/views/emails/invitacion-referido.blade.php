<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Una invitación a Doctorísimo</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    @include('emails._logo')
    <p>
        El <strong>{{ $invitador }}</strong> te ha invitado a <strong>Doctorísimo.App</strong>, la aplicación
        para atender el consultorio.
    </p>

    @if ($latidosReferente > 0 || $latidosReferido > 0)
        <p>
            Cuando te registres con este enlace y un administrador apruebe tu solicitud, el premio es para
            los dos:
            @if ($latidosReferido > 0)
                <strong>{{ \App\Latidos\Conversion::formatear($latidosReferido) }} Latidos</strong> para ti
            @endif
            @if ($latidosReferente > 0 && $latidosReferido > 0)
                y
            @endif
            @if ($latidosReferente > 0)
                <strong>{{ \App\Latidos\Conversion::formatear($latidosReferente) }} Latidos</strong> para él.
            @endif
        </p>
    @endif

    <p>
        <a href="{{ $url }}" style="display: inline-block; padding: 10px 18px; background: #0F6E5C; color: #ffffff; text-decoration: none; border-radius: 6px;">
            Ver la invitación
        </a>
    </p>

    <p style="font-size: 13px; color: #55555f;">
        El enlace te lleva a la página de Doctorísimo y deja guardado quién te invitó. Si no reconoces a
        {{ $invitador }} o no esperabas este correo, puedes ignorarlo.
    </p>
</body>
</html>
