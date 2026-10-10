<?php

namespace App\Cumpleanos;

use App\Models\Evolucion;
use App\Notificaciones\Canal;
use Carbon\CarbonInterface;

/**
 * La configuración del **saludo de cumpleaños automático** de un médico (WEB-2.9): si se manda, por
 * dónde y a qué hora. Los valores por defecto viven **acá** y no en la base, porque las tres columnas de
 * `evolucion` son nullable: los consultorios que ya existen no tienen nada escrito y "no configurado"
 * tiene que significar algo razonable y **sin sorpresas**.
 *
 * **El canal por defecto es el correo** (decisión del 2026-10-10), y es la diferencia importante con el
 * recordatorio de cita, que arranca en SMS:
 *
 *  - El recordatorio de cita lo manda **una persona**, que ve el resultado en el momento y decide; el
 *    saludo de cumpleaños lo manda **el servidor solo, todos los días, sin que nadie mire**. Un envío
 *    desatendido no puede arrancar por el canal más caro.
 *  - El **SMS se cobra por mensaje** y sale por Twilio con la credencial del servidor: el gasto lo paga
 *    la plataforma, no el médico. Prender el automático no puede empezar a facturar sin que nadie lo
 *    haya elegido.
 *  - **WhatsApp** solo deja mandar **plantillas aprobadas por Meta** fuera de la ventana de 24 h, y la
 *    única aprobada es `notificacion_paciente` (el recordatorio de cita). El saludo de cumpleaños es
 *    **texto libre del médico** (`plantilla_cumple`), así que por WhatsApp no viajaría lo que el médico
 *    escribió: se registraría un texto y se mandaría otro. Queda disponible como opción explícita —el
 *    médico que lo elija lo hace a sabiendas— pero no puede ser el default.
 *  - El **correo** usa el SMTP del servidor (costo marginal cero) y admite el texto del médico tal
 *    cual. Es el único de los tres que cumple las dos condiciones para un envío que nadie mira.
 *
 * La **hora por defecto son las 09:00** del consultorio: a esa hora el saludo ya no despierta a nadie y
 * todavía es el día del cumpleaños. Está en `config/clinica.php` la zona horaria con la que se
 * interpreta.
 */
final class ConfiguracionDeCumpleanos
{
    public const CANAL_POR_DEFECTO = Canal::CORREO;

    public const HORA_POR_DEFECTO = '09:00';

    public function __construct(
        private bool $activo,
        private string $canal,
        private string $hora,
        private ?Evolucion $evolucion = null,
    ) {
    }

    /** La configuración de un médico, con los valores por defecto aplicados a lo que esté en `null`. */
    public static function de(?Evolucion $evolucion): self
    {
        if ($evolucion === null) {
            return self::porDefecto();
        }

        $canal = (string) $evolucion->canal_cumpleanos;
        $hora = substr((string) $evolucion->hora_cumpleanos, 0, 5);

        return new self(
            (bool) $evolucion->enviar_cumpleanos,            Canal::existe($canal) ? $canal : self::CANAL_POR_DEFECTO,
            self::horaValida($hora) ? $hora : self::HORA_POR_DEFECTO,
            $evolucion,
        );
    }

    public static function porDefecto(): self
    {
        return new self(false, self::CANAL_POR_DEFECTO, self::HORA_POR_DEFECTO);
    }

    public function activo(): bool
    {
        return $this->activo;
    }

    public function canal(): string
    {
        return $this->canal;
    }

    /** `HH:MM`, como se muestra y como se compara. */
    public function hora(): string
    {
        return $this->hora;
    }

    public function etiquetaDelCanal(): string
    {
        return Canal::etiqueta($this->canal);
    }

    /** La fila de `evolucion` si ya existía; `null` cuando el médico todavía no tiene configuración. */
    public function evolucion(): ?Evolucion
    {
        return $this->evolucion;
    }

    /**
     * ¿Ya pasó la hora del envío en este momento? El comando corre cada pocos minutos y decide por
     * médico (`>=`, no `==`): así un servidor que estuvo caído a la hora exacta manda el saludo tarde
     * en vez de no mandarlo nunca. Que no se repita lo garantiza el registro del envío, no la hora.
     */
    public function yaEsLaHora(CarbonInterface $ahora): bool
    {
        return $ahora->format('H:i') >= $this->hora;
    }

    /** Un texto con forma de hora `HH:MM` (0–23 y 0–59). */
    public static function horaValida(?string $hora): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $hora);
    }

    /**
     * La hora tal como se guarda: `HH:MM` del formulario, normalizada a `HH:MM:00` para la columna
     * `time`. Devuelve `null` si no tiene forma de hora, que es lo que la validación tiene que rechazar
     * antes.
     */
    public static function horaParaGuardar(?string $hora): ?string
    {
        return self::horaValida($hora) ? $hora . ':00' : null;
    }
}
