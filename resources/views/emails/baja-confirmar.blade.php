<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Confirma la baja de tu cuenta</title>
</head>
<body style="margin:0;padding:24px;background:#f4f2f7;font-family:Arial,Helvetica,sans-serif;color:#2b2436;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;">
        <tr>
            <td style="padding:28px 28px 8px;">
                <p style="margin:0 0 4px;color:#783080;font-size:13px;letter-spacing:.04em;text-transform:uppercase;">
                    {{ config('legal.app') }}
                </p>
                <h1 style="margin:0 0 16px;font-size:22px;">Confirma la baja de tu cuenta</h1>
            </td>
        </tr>
        <tr>
            <td style="padding:0 28px 8px;font-size:15px;line-height:1.6;">
                <p style="margin:0 0 12px;">Recibimos un pedido para eliminar esta cuenta y los datos asociados.</p>
                <p style="margin:0 0 12px;">
                    <strong>No se borra nada todavía.</strong> Si fuiste tú, confirma con este botón: la cuenta
                    queda programada para eliminarse dentro de <strong>{{ $dias }} días</strong>.
                </p>
                <p style="margin:0 0 20px;">
                    <a href="{{ $confirmar }}"
                       style="display:inline-block;background:#783080;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:bold;">
                        Confirmar la baja
                    </a>
                </p>
                <p style="margin:0 0 12px;">Si no fuiste tú, o cambiaste de idea, cancela:</p>
                <p style="margin:0 0 20px;">
                    <a href="{{ $cancelar }}" style="color:#783080;">Cancelar la baja</a>
                </p>
                <p style="margin:0 0 12px;">
                    Durante los {{ $dias }} días también se cancela sola si vuelves a iniciar sesión en la web o
                    en la app.
                </p>
                <p style="margin:0 0 4px;color:#6b6580;font-size:13px;">
                    Si el botón no funciona, copia este enlace en tu navegador:
                </p>
                <p style="margin:0 0 16px;color:#6b6580;font-size:12px;word-break:break-all;">{{ $confirmar }}</p>
            </td>
        </tr>
        <tr>
            <td style="padding:8px 28px 28px;border-top:1px solid #eee;font-size:12px;color:#6b6580;line-height:1.5;">
                {{ config('legal.responsable.razon_social') }} — RIF {{ config('legal.responsable.rif') }}<br>
                {{ config('legal.responsable.domicilio') }}<br>
                Dudas: <a href="mailto:{{ config('legal.contacto') }}" style="color:#783080;">{{ config('legal.contacto') }}</a>
            </td>
        </tr>
    </table>
</body>
</html>
