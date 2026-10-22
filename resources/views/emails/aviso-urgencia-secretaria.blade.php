<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Paciente con posible urgencia</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    <p>
        Una paciente escribió por WhatsApp pidiendo cita, el asistente detectó una <strong>posible urgencia</strong>
        y hoy no hay horario libre.
    </p>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-size: 14px;">
        <tr>
            <td style="color: #55555f;">Paciente</td>
            <td><strong>{{ $paciente ?: 'Paciente sin ficha' }}</strong></td>
        </tr>
        <tr>
            <td style="color: #55555f;">Teléfono (WhatsApp)</td>
            <td>{{ $telefono }}</td>
        </tr>
        <tr>
            <td style="color: #55555f;">Hora del mensaje</td>
            <td>{{ $hora }}</td>
        </tr>
        @if ($medico)
            <tr>
                <td style="color: #55555f;">Médico</td>
                <td>{{ $medico }}</td>
            </tr>
        @endif
    </table>

    <p>
        Se le dio el teléfono del consultorio. Comunícate con ella para ver si hay lugar que el sistema no
        refleja (alguien que no llegó, consultas más cortas de lo previsto).
    </p>
</body>
</html>
