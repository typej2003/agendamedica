<?php

namespace App\Clinica\CitaOnline;

use App\Models\Evolucion;
use App\Pagos\Centavos;
use App\Pagos\Porcentaje;

/**
 * **La configuración de la cita online de un médico** (2026-10-30): cuánto se cobra al reservar y si su
 * agenda se publica en el portal y en el bot.
 *
 * Los valores por defecto viven **acá** y no en la base, porque las dos columnas de `evolucion` son
 * nullable y los consultorios que ya existen no tienen nada escrito:
 *
 *  - **sin porcentaje = 100 %**: hoy no se cobra al reservar, así que la cita online no puede arrancar
 *    cobrando una parte sin que el médico lo haya elegido. `10000` puntos base es el 100 %, como todo el
 *    dinero de la plataforma (`ReglaDeComision::porcentaje_bp`).
 *  - **sin marca = publicada**: el portal ya muestra a todo médico con horarios y no se puede vaciar de
 *    golpe el día que se despliega esta columna. El médico la apaga desde la pantalla y ahí sí queda
 *    `false`.
 *
 * El porcentaje se guarda en **puntos base** (`10000` = 100 %) y se muestra con la coma decimal.
 */
final class ConfiguracionDeCitaOnline
{
    /** El 100 % en puntos base: lo que se cobra al reservar cuando el médico no eligió otra cosa. */
    public const PORCENTAJE_POR_DEFECTO = Porcentaje::TOTAL;

    /** Sin marca en la base, la agenda se publica. */
    public const PUBLICAR_POR_DEFECTO = true;

    public function __construct(
        private int $porcentajeBp,
        private bool $publicaAgenda,
        private ?Evolucion $evolucion = null,
    ) {
    }

    /** La configuración del médico, con los valores por defecto aplicados a lo que esté en `null`. */
    public static function de(?Evolucion $evolucion): self
    {
        if ($evolucion === null) {
            return self::porDefecto();
        }

        $bp = $evolucion->porcentaje_cita_online_bp;

        return new self(
            $bp === null || $bp < 0 || $bp > Porcentaje::TOTAL
                ? self::PORCENTAJE_POR_DEFECTO
                : (int) $bp,
            $evolucion->publicar_agenda === null
                ? self::PUBLICAR_POR_DEFECTO
                : (bool) $evolucion->publicar_agenda,
            $evolucion,
        );
    }

    public static function porDefecto(): self
    {
        return new self(self::PORCENTAJE_POR_DEFECTO, self::PUBLICAR_POR_DEFECTO);
    }

    public function porcentajeBp(): int
    {
        return $this->porcentajeBp;
    }

    /** `3000` → `"30,00%"`. */
    public function porcentajeTexto(): string
    {
        return Porcentaje::aTexto($this->porcentajeBp);
    }

    /** El porcentaje como se escribe en el campo del formulario: `"30.00"`. */
    public function porcentajeParaElCampo(): string
    {
        return Centavos::aDecimal($this->porcentajeBp);
    }

    public function publicaAgenda(): bool
    {
        return $this->publicaAgenda;
    }

    /** La fila de `evolucion` si ya existía; `null` cuando el médico todavía no tiene configuración. */
    public function evolucion(): ?Evolucion
    {
        return $this->evolucion;
    }

    /**
     * El porcentaje que escribe el médico (0–100, con coma o punto) en puntos base: `30` → `3000`,
     * `12,5` → `1250`. La validación de rango la hace el formulario antes; acá sólo se convierte sin pasar
     * por coma flotante.
     */
    public static function puntosBaseDe(string|int|float|null $porcentaje): int
    {
        return Centavos::deEscala($porcentaje, 2);
    }
}
