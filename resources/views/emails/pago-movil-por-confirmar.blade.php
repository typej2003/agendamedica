<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Tienes un pago por confirmar</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    @include('emails._logo')
    <p>Hola:</p>

    <p>
        Un paciente avisó que pagó por <strong>pago móvil o transferencia</strong> y el pago quedó
        <strong>pendiente de confirmación</strong>. Revisa tu banco y confírmalo desde la pantalla de Cobros.
    </p>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-size: 14px;">
        <tr>
            <td style="color: #55555f;">Concepto</td>
            <td><strong>{{ $pago->concepto }}</strong></td>
        </tr>
        <tr>
            <td style="color: #55555f;">Monto</td>
            <td><strong>{{ $pago->montoTexto() }}</strong></td>
        </tr>
        @if (! empty($pago->datos_pagador['nombre']))
            <tr>
                <td style="color: #55555f;">Pagador</td>
                <td>
                    {{ $pago->datos_pagador['nombre'] }}
                    @if (! empty($pago->datos_pagador['documento_numero']))
                        · {{ $pago->datos_pagador['documento_tipo'] ?? '' }}{{ $pago->datos_pagador['documento_numero'] }}
                    @endif
                    @if (! empty($pago->datos_pagador['telefono']))
                        · {{ $pago->datos_pagador['telefono'] }}
                    @endif
                </td>
            </tr>
        @endif
        @php($transaccion = $pago->transacciones->last())
        @if ($transaccion)
            <tr>
                <td style="color: #55555f;">Referencia</td>
                <td>{{ $transaccion->referencia }}</td>
            </tr>
        @endif
        <tr>
            <td style="color: #55555f;">Cuenta</td>
            <td>{{ $pago->cuentaBancaria?->bancoNombre() }} · {{ $pago->cuentaBancaria?->descripcion() }}</td>
        </tr>
    </table>

    <p style="margin-top: 24px;">
        <a href="{{ route('clinica.cobros') }}" style="background: #6f42c1; color: #fff; padding: 10px 18px; border-radius: 6px; text-decoration: none;">
            Ir a Cobros
        </a>
    </p>

    <p style="color: #55555f; font-size: 13px;">
        Si ya lo confirmaste o lo rechazaste, ignora este correo.
    </p>
</body>
</html>
