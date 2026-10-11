<?php

namespace App\Clinica\Impresion;

use App\Models\Consulta;

/**
 * **Todo lo que se puede imprimir de una consulta** (WEB-5.3a): los récipes (WEB-5.2) y las solicitudes —constancia,
 * reposo, referencias e informe—, con **una sola lista** y **una sola forma de pedir cada uno**.
 *
 * Es lo que usa la pantalla «Imprimir documentos» de la cita de la agenda (la secretaría elige uno o imprime todos), el
 * botón de cada pestaña de la consulta y el enlace de la historia del paciente. No sabe nada de HTTP ni de hojas: junta
 * `RecipesParaImprimir` y `SolicitudesParaImprimir`, que ya saben qué es cada documento, y les pone una clave común:
 *
 *  - `recipe-1`, `recipe-2`… los récipes del paciente y `recipe-pareja` el de la pareja;
 *  - `constancia`, `reposo`, `referencia-<id del médico>` e `informe`.
 *
 * **Extensible**: hospitalización y dietas (WEB-5.3b) y laboratorio y estudios (WEB-5.4) suman su propia clase `…ParaImprimir`
 * y una línea acá; lo que sale en pantalla y la ruta no cambian.
 *
 * Es de **sólo lectura**.
 */
final class DocumentosParaImprimir
{
    /** El prefijo de la clave de un récipe. */
    public const PREFIJO_RECIPE = 'recipe-';

    /** El valor de `?documento=` que pide todo lo imprimible de la consulta. */
    public const TODOS = 'todos';

    private const ICONOS = [
        DocumentoImpreso::CONSTANCIA => 'bi-file-earmark-medical',
        DocumentoImpreso::REPOSO => 'bi-bandaid',
        DocumentoImpreso::REFERENCIA => 'bi-person-up',
        DocumentoImpreso::INFORME => 'bi-file-earmark-text',
    ];

    public function __construct(
        private RecipesParaImprimir $recipes,
        private SolicitudesParaImprimir $solicitudes,
    ) {
    }

    /**
     * Lo imprimible de la consulta, en el orden en que se ofrece: los récipes y después las solicitudes.
     *
     * @param  array<int,string>  $registros  los `reg_medico` del médico del contexto
     * @param  bool  $constanciaBase  `true` ofrece la constancia aunque no tenga nada propio (lo que ve el médico en la
     *                                consulta); la secretaría la ve sólo si el médico escribió algo propio
     * @return array<int,array{clave:string, tipo:string, titulo:string, resumen:string, fecha:?string, icono:string, de_la_pareja:bool}>
     */
    public function disponibles(Consulta $consulta, array $registros, bool $constanciaBase = false): array
    {
        $disponibles = [];

        foreach ($this->recipes->disponibles($consulta) as $recipe) {
            $disponibles[] = [
                'clave' => self::PREFIJO_RECIPE . $recipe['clave'],
                'tipo' => 'recipe',
                'titulo' => $recipe['titulo'],
                'resumen' => $recipe['resumen'],
                'fecha' => $recipe['fecha'],
                'icono' => $recipe['de_la_pareja'] ? 'bi-people' : 'bi-prescription2',
                'de_la_pareja' => $recipe['de_la_pareja'],
            ];
        }

        foreach ($this->solicitudes->disponibles($consulta, $registros, $constanciaBase) as $solicitud) {
            $disponibles[] = $solicitud + ['icono' => self::ICONOS[$solicitud['tipo']] ?? 'bi-file-earmark', 'de_la_pareja' => false];
        }

        return $disponibles;
    }

    /**
     * El documento pedido por su clave, o `null` si la consulta no lo tiene.
     *
     * @param  array<int,string>  $registros
     */
    public function documento(Consulta $consulta, int $pacienteId, array $registros, string $clave): DocumentoDeRecipe|DocumentoImpreso|null
    {
        if (str_starts_with($clave, self::PREFIJO_RECIPE)) {
            return $this->recipes->documento($consulta, $pacienteId, substr($clave, strlen(self::PREFIJO_RECIPE)));
        }

        return $this->solicitudes->documento($consulta, $pacienteId, $registros, $clave);
    }
}
