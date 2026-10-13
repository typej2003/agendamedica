<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recordatorio de cita</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    <p>Estimado(a) {{ $paciente }}:</p>

    <p>{!! nl2br(e($cuerpo)) !!}</p>

    @if ($doctor || $centro)
        <p style="margin-top: 24px; color: #55555f; font-size: 13px;">
            {{ $doctor }}@if ($doctor && $centro) — @endif{{ $centro }}
        </p>
    @endif
</body>
</html>
