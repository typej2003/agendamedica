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
 *  - **sin porcentaje = 100 %**: el cobro al reservar existe (porcentaje configurable: 100, 30 o 0),
 *    y el consultorio que no eligió nada arranca cobrando la consulta entera. `10000` puntos base es el
 *    100 %, como todo el dinero de la plataforma (`ReglaDeComision::porcentaje_bp`).
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

    // ---------------------------------------------------------------- Lo que el paciente lee antes de venir (O-52)

    /** Cuántos minutos antes de la cita conviene llegar, o `null` si el médico no lo dijo. */
    public function minutosAntes(): ?int
    {
        $minutos = $this->evolucion?->cita_linea_minutos_antes;

        return $minutos === null ? null : max(0, (int) $minutos);
    }

    /** Cuántos acompañantes se admiten, o `null` si el médico no lo dijo (no es lo mismo que cero). */
    public function acompanantes(): ?int
    {
        $acompanantes = $this->evolucion?->cita_linea_acompanantes;

        return $acompanantes === null ? null : max(0, (int) $acompanantes);
    }

    /** Una línea libre de indicaciones para la cita («trae tus exámenes»), o `null`. */
    public function indicaciones(): ?string
    {
        $texto = trim((string) ($this->evolucion?->cita_linea_indicaciones ?? ''));

        return $texto === '' ? null : $texto;
    }

    /** ¿Hay algo de esto que contarle al paciente? */
    public function tieneIndicacionesParaLaCita(): bool
    {
        return $this->minutosAntes() !== null || $this->acompanantes() !== null || $this->indicaciones() !== null;
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
