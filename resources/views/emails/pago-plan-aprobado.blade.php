<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Tu plan está renovado</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #201048; font-size: 15px; line-height: 1.5;">
    <p>Hola {{ $nombre ?: 'doctor' }}:</p>

    <p>
        Recibimos tu pago y tu plan
        <strong>{{ $pago->plan()?->nombre ?? 'contratado' }}</strong>
        quedó renovado.
    </p>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-size: 14px;">
        @if ($pago->usaLatidos())
            <tr>
                <td style="color: #55555f;">Total del plan</td>
                <td><strong>{{ $pago->montoUsdTexto() }}</strong></td>
            </tr>
            <tr>
                <td style="color: #55555f;">Descuento con Latidos</td>
                <td>{{ $pago->latidosTexto() }} ({{ $pago->descuentoUsdTexto() }})</td>
            </tr>
            <tr>
                <td style="color: #55555f;">Pagado con SyPago</td>
                <td>
                    @if ($pago->cubiertoConLatidos())
                        <strong>Sin cobro</strong>
                    @else
                        <strong>{{ $pago->montoTexto() }}</strong>
                    @endif
                </td>
            </tr>
        @else
            <tr>
                <td style="color: #55555f;">Monto cobrado</td>
                <td><strong>{{ $pago->montoTexto() }}</strong></td>
            </tr>
        @endif
        @if (strtoupper((string) $pago->moneda) !== 'USD' && ! $pago->cubiertoConLatidos())
            <tr>
                <td style="color: #55555f;">Equivalente en dólares</td>
                <td>{{ $pago->montoUsdTexto() }}</td>
            </tr>
        @endif
        @if ($pago->tasa !== null)
            <tr>
                <td style="color: #55555f;">Tasa</td>
                <td>1 USD = {{ number_format((float) $pago->tasa, 4, ',', '.') }} VES</td>
            </tr>
        @endif
        <tr>
            <td style="color: #55555f;">Referencia</td>
            <td>{{ $pago->referencia }}</td>
        </tr>
        <tr>
            <td style="color: #55555f;">Fecha</td>
            <td>{{ \App\Support\HoraLocal::fechaHora($pago->pagado_el, '', \App\Support\Regionalizacion::porDefecto()) }}</td>
        </tr>
    </table>

    <p style="margin-top: 24px;">Gracias por seguir con Doctorisimo.</p>
</body>
</html>
