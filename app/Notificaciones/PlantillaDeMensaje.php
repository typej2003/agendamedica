<?php

namespace App\Notificaciones;

use App\Actions\Configuracion\LimitesDelMembrete;
use App\Cumpleanos\MensajeDeCumpleanos;

/**
 * **Una plantilla de mensaje del médico** (WEB-8.4): la de **citas** (el recordatorio) o la de **cumpleaños** (el saludo). Dice
 * en qué columna de `evolucion` vive, cuáles son sus **etiquetas** (las que el servidor realmente sustituye), cuál es el mensaje
 * por defecto y qué clase lo arma — **la misma que arma el mensaje real** (`MensajeDeRecordatorio` o `MensajeDeCumpleanos`), de modo
 * que la vista previa del editor no pueda decir algo distinto de lo que sale.
 *
 * Es de sólo lectura y no sabe de HTTP ni de la base. El catálogo de las dos plantillas está en `PlantillasDeMensaje`.
 */
final class PlantillaDeMensaje
{
    public const CITA = 'cita';

    public const CUMPLE = 'cumple';

    /**
     * @param  array<string,array{texto:string, ayuda:string}>  $etiquetas  `{etiqueta} => [texto del botón, ayuda]`
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $columna,
        public readonly string $titulo,
        public readonly string $paraQueSirve,
        public readonly string $porDefecto,
        public readonly array $etiquetas,
        private readonly string $armadora,
    ) {
    }

    /** El largo máximo de la columna (el del esquema, el mismo que valida el API del móvil). */
    public function maximo(): int
    {
        return (int) LimitesDelMembrete::de($this->columna);
    }

    /** Las etiquetas que el servidor sustituye, en el orden del editor. @return array<int,string> */
    public function etiquetasValidas(): array
    {
        return array_keys($this->etiquetas);
    }

    /**
     * El mensaje que sale con esta plantilla y estos datos: lo arma la **misma clase** que arma el real. Una plantilla vacía vale
     * la de por defecto.
     *
     * @param  array<string,?string>  $datos  `paciente`, `doctor` y —sólo en la de citas— `fecha`, `hora` y `centro`
     */
    public function armar(?string $texto, array $datos): string
    {
        /** @var MensajeDeRecordatorio|MensajeDeCumpleanos $armadora */
        $armadora = new ($this->armadora)();

        return $armadora->armar($texto, $datos);
    }

    /**
     * Las etiquetas del texto que **no existen** (`{algo}`): el servidor no las sustituye y el paciente las leería tal cual.
     * La sustitución distingue mayúsculas (`{Paciente}` no es `{paciente}`), así que también cuenta como desconocida.
     *
     * @return array<int,string> sin repetir, en el orden en que aparecen
     */
    public function etiquetasDesconocidas(string $texto): array
    {
        preg_match_all('/\{[^{}\r\n]{1,40}\}/u', $texto, $encontradas);

        return array_values(array_unique(array_filter(
            $encontradas[0],
            fn (string $etiqueta) => ! in_array($etiqueta, $this->etiquetasValidas(), true),
        )));
    }

    /**
     * Los datos de ejemplo con que se arma la vista previa. El paciente es uno inventado (se ve a simple vista que no es real) y el
     * resto sale del contexto del médico.
     *
     * @param  array{doctor:string, centro:string, fecha:string}  $contexto  el médico, el consultorio y un día cercano, ya formateados
     * @return array<string,?string>
     */
    public function datosDeEjemplo(array $contexto): array
    {
        $datos = ['paciente' => 'Ana Ejemplo', 'doctor' => $contexto['doctor']];

        return $this->slug === self::CITA
            ? $datos + ['fecha' => $contexto['fecha'], 'hora' => '09:30', 'centro' => $contexto['centro']]
            : $datos;
    }

    /** ¿El texto usa la etiqueta (la sustituye el servidor)? */
    public function usa(string $texto, string $etiqueta): bool
    {
        return str_contains($texto, $etiqueta);
    }

    /** Si una etiqueta desconocida es una válida mal escrita (`{Paciente}` por `{paciente}`), cuál era; si no, `null`. */
    public function sugerencia(string $etiqueta): ?string
    {
        $minuscula = mb_strtolower($etiqueta);

        return in_array($minuscula, $this->etiquetasValidas(), true) ? $minuscula : null;
    }
}
