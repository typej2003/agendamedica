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
    ) {
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

    public function urlGuardar(): string
    {
        return route('clinica.configuracion.cita-online.guardar');
    }

    /**
     * El ejemplo con el primer motivo de cita con precio del médico, o `null` si no tiene ninguno.
     *
     * @return array{motivo:string, precio:string, alReservar:string, resto:string}|null
     */
    public function referencia(): ?array
    {
        $precio = Centavos::deDecimal((string) ($this->motivo?->precio ?? ''));

        if ($this->motivo === null || $precio <= 0) {
            return null;
        }

        $alReservar = Porcentaje::aplicar($precio, $this->configuracion->porcentajeBp());

        return [
            'motivo' => (string) $this->motivo->tipo_atencion,
            'precio' => Centavos::formatear($precio, 'USD'),
            'alReservar' => Centavos::formatear($alReservar, 'USD'),
            'resto' => Centavos::formatear(max(0, $precio - $alReservar), 'USD'),
        ];
    }
}
