<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Un médico se registró sin carnet verificado</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    @include('emails._logo')
    <p>
        <strong>{{ $solicitud->nombreCompleto() }}</strong> se registró y <strong>ya tiene acceso</strong> a
        Doctorisimo: la verificación del carnet está apagada, así que no esperó la aprobación.
    </p>

    <p>Su carnet quedó <strong>pendiente de revisión</strong>. Cuando puedas, valídalo desde la bandeja.</p>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-size: 14px;">
        <tr><td style="color: #55555f;">Médico</td><td><strong>{{ $solicitud->nombreCompleto() }}</strong></td></tr>
        <tr><td style="color: #55555f;">Cédula</td><td>{{ $solicitud->cedula }}</td></tr>
        <tr><td style="color: #55555f;">MPPS</td><td>{{ $solicitud->mpps }}</td></tr>
        <tr><td style="color: #55555f;">Especialidad</td><td>{{ $solicitud->especialidad?->name ?? '—' }}</td></tr>
        <tr><td style="color: #55555f;">Correo</td><td>{{ $solicitud->email }}</td></tr>
        <tr><td style="color: #55555f;">reg_medico</td><td>{{ $solicitud->reg_medico ?: '—' }}</td></tr>
    </table>

    <p style="margin-top: 24px;">
        <a href="{{ route('admin.solicitudes-medicos') }}">Revisar el carnet en el panel</a>
    </p>
</body>
</html>
