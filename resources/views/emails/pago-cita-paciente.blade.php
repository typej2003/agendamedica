<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Pago recibido</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    <p>Hola {{ $datos->paciente }}:</p>

    <p>
        Recibimos tu pago y tu cita con
        <strong>{{ $datos->medico }}</strong>
        quedó agendada.
    </p>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-size: 14px;">
        <tr>
            <td style="color: #55555f;">Médico</td>
            <td>{{ $datos->medico }}</td>
        </tr>
        <tr>
            <td style="color: #55555f;">Especialidad</td>
            <td>{{ $datos->especialidades }}</td>
        </tr>
        <tr>
            <td style="color: #55555f;">Fecha</td>
            <td>{{ $datos->fecha }}</td>
        </tr>
        <tr>
            <td style="color: #55555f;">Hora</td>
            <td>{{ $datos->hora }}</td>
        </tr>
        <tr>
            <td style="color: #55555f;">Lugar</td>
            <td>{{ $datos->sede }}@if ($datos->direccion !== '') — {{ $datos->direccion }}@endif</td>
        </tr>
        <tr>
            <td style="color: #55555f;">Monto pagado</td>
            <td><strong>{{ $datos->monto }}</strong></td>
        </tr>
        @if (strtoupper((string) $pago->moneda) !== 'USD')
            <tr>
                <td style="color: #55555f;">Equivalente en dólares</td>
                <td>{{ $datos->montoUsd }}</td>
            </tr>
        @endif
        <tr>
            <td style="color: #55555f;">Referencia del pago</td>
            <td>{{ $datos->referencia }}</td>
        </tr>
        @if ($pago->referencia && $pago->referencia !== $datos->referencia)
            <tr>
                <td style="color: #55555f;">Referencia interna</td>
                <td>{{ $pago->referencia }}</td>
            </tr>
        @endif
    </table>

    <p style="margin-top: 24px;">Te esperamos. Llega unos 15 minutos antes y lleva tu cédula.</p>
    <p>Gracias por reservar con Doctorísimo.</p>
</body>
</html>
