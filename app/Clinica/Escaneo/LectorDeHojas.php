<?php

namespace App\Clinica\Escaneo;

/**
 * **Quien lee una hoja escaneada** (WEB-13.15): recibe la foto o el PDF de una hoja de resultados y devuelve su
 * texto transcrito. La implementación de producción es `LectorDeHojasConClaude`; los tests la reemplazan en el
 * contenedor.
 *
 * La lectura **propone**: el texto vuelve al campo de la solapa, el médico lo revisa y lo guarda con el guardado
 * de siempre. Nadie escribe en la base desde acá.
 */
interface LectorDeHojas
{
    /** ¿Hay con qué leer? (Sin clave del proveedor la solapa no ofrece escanear.) */
    public function disponible(): bool;

    /**
     * @param  string  $contenido  los bytes del archivo
     * @param  string  $mime  el tipo **real** del contenido (`HojaEscaneada::tipo()`)
     *
     * @throws LecturaFallida
     */
    public function leer(string $contenido, string $mime): string;
}
