<?php

namespace App\Clinica\Agenda\Vistas;

use App\Models\Cola;
use App\Models\Pago;
use App\Models\TasaDeCambio;
use App\Pagos\Centavos;
use App\Pagos\ResultadoDeLinkDePago;
use App\Support\FechaClinica;
use App\Support\HoraLocal;

/**
 * **La pantalla del link de pago de una cita** (punto 3 del cobro de citas): el link público, el monto
 * que se va a cobrar y lo que hace falta para compartirlo por WhatsApp.
 *
 * No decide nada del cobro: `App\Actions\Pagos\GenerarLinkDePagoDeCita` ya creó o reusó el pago y dice si
 * hubo un motivo para no hacerlo. Acá sólo se traduce a texto y URLs.
 *
 * El **monto que se muestra es el del día**: un pago de cita no congela la tasa, así que los bolívares se
 * calculan con `TasaDeCambio::actual()` y se avisa que el débito se hace con la tasa del momento.
 */
final class LinkDePagoVista
{
    public function __construct(
        private readonly Cola $cita,
        private readonly ResultadoDeLinkDePago $resultado,
        private readonly ?string $medicoNombre = null,
    ) {
    }

    public function titulo(): string
    {
        return 'Link de pago';
    }

    public function subtitulo(): string
    {
        $partes = array_filter([
            $this->paciente(),
            'Cita del ' . $this->fecha() . ($this->hora() !== '' ? ' a las ' . $this->hora() : ''),
            $this->medicoNombre,
        ]);

        return implode(' · ', $partes);
    }

    public function paciente(): string
    {
        $paciente = $this->cita->paciente;

        return trim((string) ($paciente?->nombres ?? '') . ' ' . (string) ($paciente?->apellidos ?? ''));
    }

    public function fecha(): string
    {
        return FechaClinica::formato($this->cita->fecha, 'd/m/Y');
    }

    public function hora(): string
    {
        return HoraLocal::reloj($this->cita->hora_ini, '');
    }

    public function medico(): string
    {
        return (string) ($this->medicoNombre ?? '');
    }

    public function estado(): string
    {
        return $this->resultado->estado;
    }

    public function hayLink(): bool
    {
        return $this->resultado->hayLink();
    }

    /** El aviso del motivo por el que no se generó el link, o cadena vacía si salió bien. */
    public function mensaje(): string
    {
        return $this->resultado->mensaje;
    }

    public function esSinPrecio(): bool
    {
        return $this->estado() === ResultadoDeLinkDePago::SIN_PRECIO;
    }

    /** El link público completo, el que se copia y se manda. */
    public function link(): string
    {
        $pago = $this->pago();

        return $pago?->token_publico
            ? route('pagar.mostrar', $pago->token_publico)
            : '';
    }

    /** El saldo de la cita en dólares: es lo que se cobra (el precio completo si no hay abonos). */
    public function montoUsd(): string
    {
        return Centavos::formatear($this->montoUsdE2(), 'USD');
    }

    /**
     * El equivalente en bolívares **con la tasa del día**, sólo si el método cobra en VES. Es una
     * referencia: el monto se confirma al pagar y no queda congelado.
     */
    public function montoVes(): ?string
    {
        $pago = $this->pago();

        if ($pago === null || strtoupper((string) $pago->moneda) !== 'VES') {
            return null;
        }

        $tasa = TasaDeCambio::actual();
        if ($tasa === null) {
            return null;
        }

        $monto = $tasa->convertirUsdAVes($this->montoUsdE2());

        return $monto > 0 ? Centavos::formatear($monto, 'VES') : null;
    }

    public function avisoDeLaTasa(): string
    {
        return 'El monto en bolívares se calcula con la tasa del día al momento de pagar: no queda fijo.';
    }

    /** Hasta cuándo vale el link, si el pago tiene vencimiento. */
    public function vigencia(): string
    {
        $pago = $this->pago();

        if ($pago?->expira_el === null) {
            return '';
        }

        return 'El link vale hasta el ' . HoraLocal::fecha($pago->expira_el) . '.';
    }

    /** El mensaje corto que va al WhatsApp: médico, fecha y monto, más el link. */
    public function mensajeWhatsapp(): string
    {
        $medico = $this->medico();
        $texto = $medico !== ''
            ? 'Cita con ' . $medico
            : 'Cita';
        $texto .= ' el ' . $this->fecha();
        if ($this->hora() !== '') {
            $texto .= ' a las ' . $this->hora();
        }

        return $texto . '. Monto: ' . $this->montoUsd() . '. Link para pagar: ' . $this->link();
    }

    public function whatsappUrl(): string
    {
        return 'https://wa.me/?text=' . rawurlencode($this->mensajeWhatsapp());
    }

    public function urlEditar(): string
    {
        return route('clinica.agenda.editar', $this->cita->id);
    }

    public function urlVolver(): string
    {
        $fecha = $this->cita->fecha?->format('Y-m-d');

        return $fecha !== null
            ? route('clinica.agenda', ['fecha' => $fecha])
            : route('clinica.agenda');
    }

    private function pago(): ?Pago
    {
        return $this->resultado->pago;
    }

    private function montoUsdE2(): int
    {
        return (int) ($this->pago()?->monto_usd_e2 ?? Centavos::deDecimal($this->cita->montoPendiente()));
    }
}
