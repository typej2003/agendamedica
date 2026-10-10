<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>¡Feliz cumpleaños!</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    <p>Estimado(a) {{ $paciente }}:</p>

    <p>{!! nl2br(e($cuerpo)) !!}</p>

    @if ($doctor)
        {{-- El saludo es del médico, no del sistema: lo firma él para que el paciente sepa de quién viene. --}}
        <p style="margin-top: 24px; color: #55555f; font-size: 13px;">{{ $doctor }}</p>
    @endif
</body>
</html>
