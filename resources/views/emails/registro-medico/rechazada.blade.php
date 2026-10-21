<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Sobre tu solicitud</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    <p>Hola {{ $solicitud->nombreCompleto() }}:</p>

    <p>Revisamos tu solicitud de registro en Doctorisimo y por ahora no pudimos aprobarla. El motivo:</p>

    <p style="border-left: 3px solid #d0cde0; padding-left: 12px; white-space: pre-line;">{{ $solicitud->motivo_rechazo }}</p>

    <p>
        Si lo puedes corregir, vuelve a enviarla desde
        <a href="{{ route('medico.registro') }}">el registro de médicos</a>.
    </p>
</body>
</html>
