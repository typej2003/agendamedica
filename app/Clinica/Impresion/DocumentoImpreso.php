<?php

namespace App\Clinica\Impresion;

/**
 * **Un documento de la consulta listo para imprimir en media hoja** (WEB-5.3a, WEB-5.3b): constancia, reposo, referencia,
 * informe, orden de hospitalización o dieta / recomendaciones. Lo arma `SolicitudesParaImprimir` y lo reparte en mitades `DistribuidorDeDocumento`.
 *
 * Es un objeto de valores ya resuelto, sin base de datos: de quién es (`persona`, la cabecera de
 * `PacienteImpreso`), de qué fecha y qué lleva el cuerpo:
 *
 *  - `fijos`: los datos cortos de arriba, uno por línea, con su rótulo en negrita (`REPOSO DURANTE: 8 día(s)`). **No se
 *    parten** entre mitades: van siempre en la primera.
 *  - `parrafos`: el texto, que **sí se parte por palabra** si no entra. Cada uno puede llevar un rótulo en negrita
 *    (`IDX:`, inline, o `MOTIVO DE LA REFERENCIA:`, en su propia línea).
 *
 * `clave` es con lo que se pide el documento (`constancia`, `reposo`, `referencia-3`, `informe`, `hospitalizacion`, `dieta`): es la misma que usan la
 * historia del paciente, la lista de la cita de la agenda y las rutas de impresión.
 */
final class DocumentoImpreso
{
    public const CONSTANCIA = 'constancia';

    public const REPOSO = 'reposo';

    public const REFERENCIA = 'referencia';

    public const INFORME = 'informe';

    public const HOSPITALIZACION = 'hospitalizacion';

    public const DIETA = 'dieta';

    /**
     * @param  string  $titulo  lo que se imprime arriba del cuerpo (`CONSTANCIA`, `REPOSO MÉDICO`…)
     * @param  string  $nombre  cómo se llama en las listas (`Constancia`, `Reposo médico`…)
     * @param  string  $fecha  `AAAA-MM-DD`: la que sale en los datos del paciente
     * @param  array{nombre:string, cedula:?string, edad:?int, direccion:?string}  $persona
     * @param  array<int,array{etiqueta:string, valor:string}>  $fijos
     * @param  array<int,array{texto:string, etiqueta:?string, aparte:bool}>  $parrafos
     */
    public function __construct(
        public readonly string $tipo,
        public readonly string $clave,
        public readonly string $titulo,
        public readonly string $nombre,
        public readonly string $fecha,
        public readonly array $persona,
        public readonly array $fijos,
        public readonly array $parrafos,
    ) {
    }

    /**
     * El texto como párrafos: un renglón del texto es un párrafo (el médico los separa a propósito; las líneas en blanco
     * se descartan). El rótulo, si hay, va solo en el primero.
     *
     * @return array<int,array{texto:string, etiqueta:?string, aparte:bool}>
     */
    public static function parrafosDe(?string $texto, ?string $etiqueta = null, bool $aparte = false): array
    {
        $parrafos = [];

        foreach (preg_split('/\R/u', (string) $texto) ?: [] as $renglon) {
            $renglon = trim((string) preg_replace('/[ \t]+/u', ' ', $renglon));

            if ($renglon === '') {
                continue;
            }

            $parrafos[] = ['texto' => $renglon, 'etiqueta' => $parrafos === [] ? $etiqueta : null, 'aparte' => $parrafos === [] && $aparte];
        }

        return $parrafos;
    }
}
