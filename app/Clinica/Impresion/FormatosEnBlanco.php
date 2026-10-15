<?php

namespace App\Clinica\Impresion;

/**
 * **Los formatos en blanco** (WEB-5.5b): el "Imprimir Formatos" de `w_configurar_recipes` del escritorio. Cada documento de la consulta
 * (récipe, informe, constancia, reposo, referencia, hospitalización, dieta, laboratorio, estudios, —desde WEB-6.2— los dos informes del
 * eco obstétrico y —desde WEB-6.3— el del eco Doppler perinatal; el del **ecocardiograma fetal**, también de WEB-6.3, **no** está:
 * el escritorio no lo tiene) impreso **sin datos de paciente**, con el membrete, la firma, el sello y el pie
 * del médico, para rellenarlo a mano cuando la impresora normal está fuera de servicio.
 *
 * Esta clase es **el catálogo**: qué formatos hay, cómo se llaman y qué lleva cada uno en blanco (los datos cortos con su línea y las
 * secciones de renglones). Los **títulos** no se repiten: salen de `DocumentoImpreso` (los mismos que imprime la hoja real, con el sufijo
 * del trimestre de los ecos). Sin estado y sin base de datos: no escribe ni lee nada.
 *
 * Quedan **fuera**, a propósito: el **eco pélvico** (WEB-6.1, que el escritorio sí tiene en
 * `d_eco_pelvico_print_vacio.srd`: es la única ecografía con formato en blanco en el legado que todavía no lo
 * tiene en la web), el **ecocardiograma fetal** (WEB-6.3, que el escritorio **no** tiene: su panel
 * `miconsultat/w_panel_impresion_formatos.srw` lista seis formatos y el fetal no está entre ellos, así que no
 * es un olvido de la web), el **eco mamario** (WEB-6.4, por lo mismo: ese panel tampoco lo lista), el **eco
 * abdominal** (WEB-6.4, por lo mismo: el panel lista seis formatos y el abdominal no está —ver
 * `DocumentoImpreso::ECO_ABDOMINAL`—) y el récipe de la pareja.
 */
final class FormatosEnBlanco
{
    public const RECIPE = 'recipe';

    /**
     * Lo que lleva cada documento, en el orden en que se ofrecen. `fijos` son los datos cortos (rótulo y línea para escribir) y
     * `secciones` los bloques de renglones, cada uno con su rótulo opcional y su `peso`: cómo se reparten el alto que sobra.
     *
     * @var array<string,array{descripcion:string, fijos:array<int,string>, secciones:array<int,array{etiqueta:?string, peso:int}>}>
     */
    private const DOCUMENTOS = [
        DocumentoImpreso::INFORME => [
            'descripcion' => 'Informe libre, con el destinatario.',
            'fijos' => ['PARA:'],
            'secciones' => [['etiqueta' => null, 'peso' => 1]],
        ],
        DocumentoImpreso::CONSTANCIA => [
            'descripcion' => 'Constancia de asistencia, con lugar para el diagnóstico.',
            'fijos' => [],
            'secciones' => [['etiqueta' => null, 'peso' => 2], ['etiqueta' => 'IDX:', 'peso' => 1]],
        ],
        DocumentoImpreso::REPOSO => [
            'descripcion' => 'Con los días, el desde, el hasta y el reintegro.',
            'fijos' => ['REPOSO DURANTE:', 'A PARTIR DE LA FECHA:', 'HASTA EL DÍA:', 'REINTEGRO A SUS LABORES EL DÍA:'],
            'secciones' => [['etiqueta' => null, 'peso' => 1]],
        ],
        DocumentoImpreso::REFERENCIA => [
            'descripcion' => 'Con el médico referido y el motivo.',
            'fijos' => ['REFERIDO A:', 'ESPECIALIDAD:', 'CLÍNICA:', 'DIRECCIÓN:', 'TELÉFONO:'],
            'secciones' => [['etiqueta' => 'MOTIVO DE LA REFERENCIA:', 'peso' => 1]],
        ],
        DocumentoImpreso::HOSPITALIZACION => [
            'descripcion' => 'Con el lugar, el diagnóstico provisional y las recomendaciones.',
            'fijos' => ['HOSPITALIZAR EN:'],
            'secciones' => [['etiqueta' => 'DIAGNÓSTICO PROVISIONAL:', 'peso' => 1], ['etiqueta' => 'RECOMENDACIONES:', 'peso' => 2]],
        ],
        DocumentoImpreso::DIETA => [
            'descripcion' => 'Con el título de la dieta y sus recomendaciones.',
            'fijos' => ['DIETA:'],
            'secciones' => [['etiqueta' => null, 'peso' => 1]],
        ],
        DocumentoImpreso::LABORATORIO => [
            'descripcion' => 'Con los exámenes pedidos y el resumen clínico.',
            'fijos' => [],
            'secciones' => [['etiqueta' => 'EXÁMENES SOLICITADOS:', 'peso' => 2], ['etiqueta' => 'RESUMEN CLÍNICO:', 'peso' => 1]],
        ],
        DocumentoImpreso::ESTUDIOS => [
            'descripcion' => 'Con los estudios pedidos y el resumen clínico.',
            'fijos' => [],
            'secciones' => [['etiqueta' => 'ESTUDIOS SOLICITADOS:', 'peso' => 2], ['etiqueta' => 'RESUMEN CLÍNICO:', 'peso' => 1]],
        ],
        // Los dos informes del eco obstétrico (WEB-6.2). Los **datos cortos** son la ficha del embarazo del
        // encabezado de su DataWindow en blanco (`d_eco_obstetrico_print_vacio_2` / `…_trimestral_print_vacio`),
        // y las **secciones**, los bloques del informe —de su mapa del dato— sin el bloque «El embarazo», que
        // es esa ficha: así el médico escribe cada hallazgo donde lo lee la hoja impresa. Un unit test
        // (`FormatosEnBlancoVistaTest`) fija que las secciones sean esos bloques.
        //
        // Todos los bloques pesan lo mismo **a propósito**: el alto del cuerpo es el de media hoja (decisión 17
        // del plan: la web no reproduce el layout del DataWindow, que es una hoja entera) y repartirlo por la
        // cantidad de campos dejaba a los bloques chicos sin ningún renglón. Así cada bloque tiene su rótulo y
        // su línea, y lo que no entra en la mitad izquierda se escribe en la derecha, que va en blanco.
        DocumentoImpreso::ECO_OBSTETRICO => [
            'descripcion' => 'El embarazo temprano: la ficha, el útero, el saco y las medidas.',
            'fijos' => ['Gesta:', 'FUR:', 'EG:'],
            'secciones' => [
                ['etiqueta' => 'El útero y los ovarios:', 'peso' => 2],
                ['etiqueta' => 'El saco y la vitalidad:', 'peso' => 2],
                ['etiqueta' => 'Las medidas y los marcadores:', 'peso' => 2],
                ['etiqueta' => 'El riesgo del embarazo temprano:', 'peso' => 2],
                ['etiqueta' => 'La conclusión:', 'peso' => 2],
            ],
        ],
        DocumentoImpreso::ECO_OBSTETRICO_TERCERO => [
            'descripcion' => 'La biometría, la anatomía, la placenta y la función fetal.',
            'fijos' => ['Gesta:', 'FUR:', 'EG:', 'FPP:'],
            'secciones' => [
                ['etiqueta' => 'Datos biométricos:', 'peso' => 2],
                ['etiqueta' => 'Datos anatómicos:', 'peso' => 2],
                ['etiqueta' => 'Placenta y líquido:', 'peso' => 2],
                ['etiqueta' => 'El feto:', 'peso' => 2],
                ['etiqueta' => 'La conclusión:', 'peso' => 2],
            ],
        ],
        // El eco Doppler perinatal (WEB-6.3). Sus **datos cortos** son su ficha (la gesta, la FUR, la EG y el
        // destinatario) y sus **secciones**, el cuerpo de su hoja en el mismo orden: el memo grande —que no
        // lleva rótulo, como en el DataWindow—, la grilla de cuatro vasos con su título, los tres
        // desplegables y las dos áreas del final.
        //
        // ⚠️ **El diseño del legado era OTRO y a propósito no se copia.** El escritorio **sí** tiene este
        // formato en blanco (`d_eco_doppler_print_vacio`, administrado por
        // `miconsultat/w_panel_impresion_formatos.srw:153`), pero es un diseño distinto del de su hoja real:
        // encabezado condensado (Paciente/Cédula/Edad en una franja), la grilla de 8 columnas repartida en 2,
        // sin `relacion_*` ni `pico_*`, «Indice S/D» en lugar de «Relación S / D» y «Vena Umbilical:» en
        // lugar de «Señal Venoso Umbilical:». Como la web hace sus propios documentos (decisión 17 del plan:
        // de la fuente se leen los **datos**, no se copia el layout), su formato en blanco usa **los mismos
        // bloques que su hoja real**, vacíos, como los dos del eco obstétrico. Que falte un rótulo del legado
        // acá no es un olvido: es la decisión de que la hoja en blanco y la hoja real se lean igual.
        DocumentoImpreso::ECO_DOPPLER => [
            'descripcion' => 'Las cuatro arterias, los ductos y la conclusión.',
            'fijos' => ['Gesta:', 'FUR:', 'EG:', 'PARA:'],
            'secciones' => [
                ['etiqueta' => null, 'peso' => 2],
                ['etiqueta' => 'Eco Doppler Obstétrico en 4 Vasos:', 'peso' => 2],
                ['etiqueta' => 'Ductos Venosos:', 'peso' => 1],
                ['etiqueta' => 'Señal Venoso Umbilical:', 'peso' => 1],
                ['etiqueta' => 'Relación Aorta-Cava:', 'peso' => 1],
                ['etiqueta' => 'Sugerencias:', 'peso' => 2],
                ['etiqueta' => 'Conclusión:', 'peso' => 2],
            ],
        ],
    ];

    /**
     * Los formatos que se ofrecen, en orden: el récipe primero.
     *
     * @return array<int,array{clave:string, nombre:string, descripcion:string}>
     */
    public static function catalogo(): array
    {
        $catalogo = [[
            'clave' => self::RECIPE,
            'nombre' => 'Récipe',
            'descripcion' => 'Las dos mitades de la hoja: «Rp.» e «Indicaciones».',
        ]];

        foreach (self::DOCUMENTOS as $clave => $documento) {
            $catalogo[] = ['clave' => $clave, 'nombre' => self::nombre($clave), 'descripcion' => $documento['descripcion']];
        }

        return $catalogo;
    }

    /** @return array<int,string> */
    public static function claves(): array
    {
        return array_merge([self::RECIPE], array_keys(self::DOCUMENTOS));
    }

    public static function existe(string $clave): bool
    {
        return in_array($clave, self::claves(), true);
    }

    public static function nombre(string $clave): string
    {
        return $clave === self::RECIPE ? 'Récipe' : DocumentoImpreso::TITULOS[$clave][1];
    }

    /** ¿Es el récipe (que lleva las dos mitades «Rp.» e «Indicaciones») y no un documento de una sola mitad? */
    public static function esRecipe(string $clave): bool
    {
        return $clave === self::RECIPE;
    }

    /** El título que se imprime arriba del cuerpo de un documento (el mismo de la hoja real, con su trimestre si lo lleva). */
    public static function titulo(string $clave): string
    {
        return DocumentoImpreso::tituloConSufijo($clave);
    }

    /**
     * Lo que lleva en blanco un documento (no el récipe).
     *
     * @return array{fijos:array<int,string>, secciones:array<int,array{etiqueta:?string, peso:int}>}
     */
    public static function plano(string $clave): array
    {
        $documento = self::DOCUMENTOS[$clave];

        return ['fijos' => $documento['fijos'], 'secciones' => $documento['secciones']];
    }
}
