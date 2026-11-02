<?php

namespace App\Clinica\Impresion;

/**
 * **El informe de evaluación pre-operatoria** (O-47, 2026-11-02): la hoja de **dos páginas** que arma
 * `PreoperatorioDeLaConsulta` con todo lo cargado en la consulta y que imprime `_informe-preoperatorio`.
 *
 * Es un objeto de valores ya resuelto, sin base de datos y sin HTTP: la vista no decide nada, sólo pinta. La
 * forma es la misma para las dos páginas, así que la hoja no sabe de qué fuente viene cada bloque:
 *
 *  - `encabezado`: los datos cortos de arriba (`REFERIDO POR`, `INTERVENCIÓN`, `DIAGNÓSTICO`), cada uno con su
 *    rótulo; los que no tienen dato no vienen.
 *  - `bloques`: los cuerpos de la primera página, en orden. `tipo` es `texto` (un párrafo, con el texto ya
 *    recortado) o `lista` (el laboratorio: cada examen con su resultado, uno por renglón).
 *  - `escalas`: los cinco bloques de la segunda página (espirometría, trombosis, infección, situaciones
 *    especiales y la clase ASA), cada uno con su rótulo corto y **el texto de la opción**.
 *  - `recomendaciones` y `riesgo`: el cierre de la segunda página; `riesgo` ya viene armado
 *    («Clase I - Individuo Sano - Puntos: 3»).
 *
 * **Cada bloque aparece sólo si tiene dato**: una consulta sin E.K.G. no imprime el bloque vacío.
 */
final class InformePreoperatorio
{
    /**
     * @param  array<int,array{etiqueta:string, valor:string}>  $encabezado
     * @param  array<int,array{titulo:string, tipo:string, texto?:?string, items?:array<int,array{nombre:string, resultado:?string}>}>  $bloques
     * @param  array<int,array{titulo:string, etiqueta:?string, texto:?string}>  $escalas
     */
    public function __construct(
        public readonly array $encabezado = [],
        public readonly array $bloques = [],
        public readonly array $escalas = [],
        public readonly ?string $recomendaciones = null,
        public readonly ?string $riesgo = null,
    ) {
    }

    /** ¿Hay algo que imprimir? Es lo que decide si el documento se ofrece. */
    public function tieneDatos(): bool
    {
        return $this->encabezado !== []
            || $this->bloques !== []
            || $this->escalas !== []
            || $this->recomendaciones !== null
            || $this->riesgo !== null;
    }
}
