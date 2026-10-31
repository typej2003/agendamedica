<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Te pagaron una cita</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    <p>Hola {{ $datos->medico }}:</p>

    <p>Te pagaron una cita. El pago ya está aprobado.</p>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-size: 14px;">
        <tr>
            <td style="color: #55555f;">Paciente</td>
            <td><strong>{{ $datos->paciente }}</strong></td>
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
            <td style="color: #55555f;">Monto cobrado</td>
            <td><strong>{{ $datos->monto }}</strong></td>
        </tr>
        @if (strtoupper((string) $pago->moneda) !== 'USD')
            <tr>
                <td style="color: #55555f;">Equivalente en dólares</td>
                <td>{{ $datos->montoUsd }}</td>
            </tr>
        @endif
        <tr>
            <td style="color: #55555f;">Canal</td>
            <td>{{ $datos->canal }}</td>
        </tr>
        <tr>
            <td style="color: #55555f;">Referencia</td>
            <td>{{ $datos->referencia }}</td>
        </tr>
    </table>

    <p style="margin-top: 24px;">Gracias por usar Doctorísimo.</p>
</body>
</html>
