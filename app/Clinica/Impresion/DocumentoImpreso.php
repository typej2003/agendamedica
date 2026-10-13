<?php

namespace App\Clinica\Impresion;

/**
 * **Un documento de la consulta listo para imprimir en media hoja** (WEB-5.3a, WEB-5.3b, WEB-5.4): constancia, reposo, referencia,
 * informe, orden de hospitalización, dieta / recomendaciones, orden de laboratorio o solicitud de estudios. Lo arma `SolicitudesParaImprimir` y lo reparte en mitades `DistribuidorDeDocumento`.
 *
 * Es un objeto de valores ya resuelto, sin base de datos: de quién es (`persona`, la cabecera de
 * `PacienteImpreso`), de qué fecha y qué lleva el cuerpo:
 *
 *  - `fijos`: los datos cortos de arriba, uno por línea, con su rótulo en negrita (`REPOSO DURANTE: 8 día(s)`). **No se
 *    parten** entre mitades: van siempre en la primera.
 *  - `parrafos`: el texto, que **sí se parte por palabra** si no entra. Cada uno puede llevar un rótulo en negrita
 *    (`IDX:`, inline, o `MOTIVO DE LA REFERENCIA:`, en su propia línea). Un párrafo **de lista** (WEB-5.4, los exámenes de un tipo) lleva
 *    además `items`: el texto es esos elementos unidos por ` · ` y, si no entra, se parte **entre elementos** y nunca por palabra (un
 *    examen no queda cortado en dos mitades); la parte que sigue repite el rótulo con «(cont.)».
 *
 * `clave` es con lo que se pide el documento (`constancia`, `reposo`, `referencia-3`, `informe`, `hospitalizacion`, `dieta`, `laboratorio`, `estudios`): es la misma que usan la
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

    public const LABORATORIO = 'laboratorio';

    public const ESTUDIOS = 'estudios';

    /**
     * El **informe del estudio ecográfico** (WEB-6.1). Es el informe de un estudio, no una solicitud: la hoja lleva los
     * datos del estudio (medidas, hallazgos y conclusiones), no una orden.
     *
     * El título sale del DataWindow de impresión del escritorio (`d_eco_pelvico_print.srd:54`), con la tilde que a la
     * fuente le falta («ECOSONOGRAFICO»): la hoja es de la web y va con la ortografía correcta.
     */
    public const ECO_PELVICO = 'eco-pelvico';

    /**
     * El título que se imprime arriba del cuerpo y el nombre con que aparece en las listas, de cada documento. Es **la** fuente de
     * esos textos: la hoja real (`SolicitudesParaImprimir`) y la hoja en blanco (`FormatosEnBlanco`, WEB-5.5b) leen de acá.
     *
     * @var array<string,array{0:string,1:string}>
     */
    public const TITULOS = [
        self::CONSTANCIA => ['CONSTANCIA', 'Constancia'],
        self::REPOSO => ['REPOSO MÉDICO', 'Reposo médico'],
        self::REFERENCIA => ['REFERENCIA', 'Referencia'],
        self::INFORME => ['INFORME MÉDICO', 'Informe médico'],
        self::HOSPITALIZACION => ['ORDEN DE HOSPITALIZACIÓN', 'Orden de hospitalización'],
        self::DIETA => ['DIETA / RECOMENDACIONES', 'Dieta / Recomendaciones'],
        self::LABORATORIO => ['SOLICITUD DE EXÁMENES DE LABORATORIO', 'Orden de laboratorio'],
        self::ESTUDIOS => ['SOLICITUD DE ESTUDIOS', 'Solicitud de estudios'],
        self::ECO_PELVICO => ['INFORME ECOSONOGRÁFICO GINECOLÓGICO', 'Eco pélvico'],
    ];

    /** Lo que separa los elementos de un párrafo de lista (los exámenes de un tipo). */
    public const SEPARADOR_DE_ITEMS = ' · ';

    /**
     * @param  string  $titulo  lo que se imprime arriba del cuerpo (`CONSTANCIA`, `REPOSO MÉDICO`…)
     * @param  string  $nombre  cómo se llama en las listas (`Constancia`, `Reposo médico`…)
     * @param  string  $fecha  `AAAA-MM-DD`: la que sale en los datos del paciente
     * @param  array{nombre:string, cedula:?string, edad:?int, direccion:?string}  $persona
     * @param  array<int,array{etiqueta:string, valor:string}>  $fijos
     * @param  array<int,array{texto:string, etiqueta:?string, aparte:bool, items?:array<int,string>}>  $parrafos
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
     * Un párrafo de lista (WEB-5.4): un rótulo en su propia línea y debajo los elementos, unidos por ` · `. Se parte entre elementos, no por palabra.
     *
     * @param  array<int,string>  $items  cada elemento en una sola línea de texto (los espacios de más ya colapsados)
     * @return array{texto:string, etiqueta:string, aparte:bool, items:array<int,string>}
     */
    public static function parrafoDeLista(string $etiqueta, array $items): array
    {
        $items = array_values(array_map(fn (string $item) => trim((string) preg_replace('/\s+/u', ' ', $item)), $items));

        return ['texto' => implode(self::SEPARADOR_DE_ITEMS, $items), 'etiqueta' => $etiqueta, 'aparte' => true, 'items' => $items];
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
