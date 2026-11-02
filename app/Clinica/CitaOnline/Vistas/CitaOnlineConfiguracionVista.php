<?php

namespace App\Clinica\CitaOnline\Vistas;

use App\Clinica\CitaOnline\ConfiguracionDeCitaOnline;
use App\Models\MotivoCita;
use App\Pagos\Centavos;
use App\Pagos\Porcentaje;

/**
 * La pantalla **Cita Online** de Configuración (2026-10-30): el porcentaje que se cobra al reservar, la
 * casilla de publicación y un ejemplo con el precio de un motivo del médico.
 *
 * El ejemplo es **de referencia** y no inventa nada: sólo se muestra si el médico tiene un motivo de cita
 * con precio. Se usa el precio del **motivo** (lo que vale la cita en el consultorio) y no `cola.monto`,
 * que es lo que se cobró en una cita concreta.
 */
final class CitaOnlineConfiguracionVista
{
    public function __construct(
        private ConfiguracionDeCitaOnline $configuracion,
        private ?MotivoCita $motivo = null,
        // **El link público de la ficha del médico** y su QR (O-52): lo que comparte con sus pacientes.
        private ?string $urlPublica = null,
        private ?string $qr = null,
    ) {
    }

    /** ¿Hay ficha pública que compartir? (Sin médico en el contexto no se ofrece el bloque.) */
    public function hayLinkPublico(): bool
    {
        return $this->urlPublica !== null;
    }

    public function urlPublica(): string
    {
        return (string) $this->urlPublica;
    }

    /** El SVG del QR listo para incrustar, o `null`. */
    public function qr(): ?string
    {
        return $this->qr;
    }

    public function titulo(): string
    {
        return 'Cita Online';
    }

    public function subtitulo(): string
    {
        return 'Cuánto se cobra al reservar y si tu agenda aparece en el portal de reservas.';
    }

    /** El valor del campo: `"100.00"` cuando no hay nada configurado. */
    public function porcentaje(): string
    {
        return $this->configuracion->porcentajeParaElCampo();
    }

    public function publicaAgenda(): bool
    {
        return $this->configuracion->publicaAgenda();
    }

    /** Los minutos antes de la cita que el paciente lee en el portal (O-52), o `''` si no hay. */
    public function minutosAntes(): string
    {
        return (string) ($this->configuracion->minutosAntes() ?? '');
    }

    /** Los acompañantes que se admiten, o `''` si no hay configurado. */
    public function acompanantes(): string
    {
        return (string) ($this->configuracion->acompanantes() ?? '');
    }

    /** La línea de indicaciones para la cita, o `''`. */
    public function indicaciones(): string
    {
        return (string) ($this->configuracion->indicaciones() ?? '');
    }

    public function urlGuardar(): string
    {
        return route('clinica.configuracion.cita-online.guardar');
    }

    /**
     * El ejemplo con el primer motivo de cita con precio del médico, o `null` si no tiene ninguno.
     *
     * @return array{motivo:string, precio:string, alReservar:string, resto:string, resto_e2:int}|null
     */
    public function referencia(): ?array
    {
        $precio = Centavos::deDecimal((string) ($this->motivo?->precio ?? ''));

        if ($this->motivo === null || $precio <= 0) {
            return null;
        }

        $alReservar = Porcentaje::aplicar($precio, $this->configuracion->porcentajeBp());
        $resto = max(0, $precio - $alReservar);

        return [
            'motivo' => (string) $this->motivo->tipo_atencion,
            'precio' => Centavos::formatear($precio, 'USD'),
            'alReservar' => Centavos::formatear($alReservar, 'USD'),
            'resto' => Centavos::formatear($resto, 'USD'),
            // La vista calla la segunda oración cuando no queda nada por cobrar en el consultorio.
            'resto_e2' => $resto,
        ];
    }
}
