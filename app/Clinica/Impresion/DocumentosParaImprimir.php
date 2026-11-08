<?php

namespace App\Clinica\Impresion;

use App\Clinica\Consulta\AlcanceDeLaEspecialidad;
use App\Models\Consulta;

/**
 * **Todo lo que se puede imprimir de una consulta** (WEB-5.3a): los récipes (WEB-5.2) y las solicitudes —constancia,
 * reposo, referencias, hospitalización, informe y dieta—, con **una sola lista** y **una sola forma de pedir cada uno**.
 *
 * Es lo que usa la pantalla «Imprimir documentos» de la cita de la agenda (la secretaría elige uno o imprime todos), el
 * botón de cada pestaña de la consulta y el enlace de la historia del paciente. No sabe nada de HTTP ni de hojas: junta
 * `RecipesParaImprimir` y `SolicitudesParaImprimir`, que ya saben qué es cada documento, y les pone una clave común:
 *
 *  - `recipe-1`, `recipe-2`… los récipes del paciente y `recipe-pareja` el de la pareja;
 *  - `constancia`, `reposo`, `referencia-<id del médico>`, `hospitalizacion`, `informe` y `dieta`.
 *
 * **Extensible**: una solicitud nueva entra por `SolicitudesParaImprimir`, con las demás de las pestañas, y suma su icono acá; lo que sale en
 * pantalla y la ruta no cambian. Hospitalización y dieta (WEB-5.3b) y laboratorio y estudios (WEB-5.4) entraron así (claves `laboratorio` y `estudios`).
 *
 * **La especialidad del contexto decide qué se ofrece** (Alexander, 2026-11-06: «Es importante separar los imprimibles por
 * especialidad, no debemos ofrecer documentos de otras especialidades. ¿Un eco ginecológico a un niño?»). Lo que se
 * ofrece lo da `ofrecidos()`, con la lista de qué es de otra especialidad —los documentos de ginecología y los de la
 * pareja— que dan `AlcanceDeLaEspecialidad::documentosQueNoSeOfrecen()` y sus hermanos, la misma que usa la historia del
 * paciente (O-72); las listas que arman los documentos son las que la aplican. En ginecología no cambia nada y sin
 * especialidad tampoco se ofrecen (2026-10-30). `disponibles()` no filtra: es «lo que la consulta tiene», y por eso la
 * hoja pedida por URL directa sigue abriendo.
 *
 * Es de **sólo lectura**.
 */
final class DocumentosParaImprimir
{
    /** El prefijo de la clave de un récipe. */
    public const PREFIJO_RECIPE = 'recipe-';

    /** El valor de `?documento=` que pide todo lo imprimible de la consulta. */
    public const TODOS = 'todos';

    /**
     * La clave del documento que imprime una URL de hoja (`…/documentos/imprimir?documento=eco-obstetrico&gesta=2` →
     * `eco-obstetrico`), o `''` si la URL no la lleva.
     *
     * La usan los ViewModels de las secciones para decirle al botón «Imprimir» **qué** documento guarda e imprime (O-16)
     * sin repetir la clave al lado de la URL: la URL ya la tiene.
     */
    public static function claveDeLaUrl(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parametros);

        return (string) ($parametros['documento'] ?? '');
    }

    private const ICONOS = [
        DocumentoImpreso::LABORATORIO => 'bi-droplet',
        DocumentoImpreso::LABORATORIO_PAREJA => 'bi-people',
        DocumentoImpreso::ESTUDIOS => 'bi-image',
        DocumentoImpreso::ECO_PELVICO => 'bi-activity',
        DocumentoImpreso::ECO_DOPPLER => 'bi-soundwave',
        DocumentoImpreso::ECO_CARDIOGRAMA_FETAL => 'bi-heart',
        DocumentoImpreso::ECO_MAMARIO => 'bi-clipboard2-pulse',
        DocumentoImpreso::ECO_ABDOMINAL => 'bi-clipboard2-data',
        DocumentoImpreso::COLPOSCOPIA => 'bi-search-heart',
        DocumentoImpreso::HISTEROSCOPIA => 'bi-camera',
        DocumentoImpreso::CITOLOGIA => 'bi-clipboard2-pulse',
        // Los informes de las endoscopías digestivas (gastroenterología pediátrica, 2026-10-19).
        DocumentoImpreso::ENDOSCOPIA_SUPERIOR => 'bi-camera-reels',
        DocumentoImpreso::ENDOSCOPIA_INFERIOR => 'bi-camera-reels',
        DocumentoImpreso::EVALUACION_PREOPERATORIA => 'bi-scissors',
        DocumentoImpreso::CONSTANCIA => 'bi-file-earmark-medical',
        DocumentoImpreso::REPOSO => 'bi-bandaid',
        DocumentoImpreso::REFERENCIA => 'bi-person-up',
        DocumentoImpreso::INFORME => 'bi-file-earmark-text',
        DocumentoImpreso::RESUMEN_MEDICO => 'bi-journal-medical',
        DocumentoImpreso::HOSPITALIZACION => 'bi-hospital',
        DocumentoImpreso::DIETA => 'bi-egg-fried',
        DocumentoImpreso::PREFACTURA => 'bi-receipt',
    ];

    public function __construct(
        private RecipesParaImprimir $recipes,
        private SolicitudesParaImprimir $solicitudes,
    ) {
    }

    /**
     * Lo imprimible de la consulta, en el orden en que se ofrece: los récipes y después las solicitudes.
     *
     * Es **todo lo que la consulta tiene**, sin mirar la especialidad: lo usa el controlador para saber si la hoja que se
     * pide existe —la pedida por URL directa sigue abriendo aunque el alcance la esconda (2026-10-22)—. Lo que se **ofrece**
     * en «Imprimir documentos» es `ofrecidos()`.
     *
     * @param  array<int,string>  $registros  los `reg_medico` del médico del contexto
     * @param  bool  $constanciaBase  `true` ofrece la constancia aunque no tenga nada propio (lo que ve el médico en la
     *                                consulta); la secretaría la ve sólo si el médico escribió algo propio
     * @return array<int,array{clave:string, tipo:string, titulo:string, resumen:string, fecha:?string, icono:string, de_la_pareja:bool}>
     */
    public function disponibles(Consulta $consulta, array $registros, bool $constanciaBase = false): array
    {
        return $this->armar($consulta, $registros, $constanciaBase, null);
    }

    /**
     * **Lo que se ofrece** en «Imprimir documentos» (la consulta y la cita): lo mismo, con la especialidad del contexto,
     * que deja afuera lo que no es de ella (Alexander, 2026-11-06: «Es importante separar los imprimibles por
     * especialidad, no debemos ofrecer documentos de otras especialidades. ¿Un eco ginecológico a un niño?»).
     *
     * *Qué* documento es de otra especialidad **no se decide acá**: lo dicen
     * `documentosDeGinecologiaQueNoSeOfrecen()` y `documentosDeLaParejaQueNoSeOfrecen()` —la misma respuesta que usa la
     * historia del paciente (O-72) y la misma que esconde esos ecos y esas solapas dentro de la consulta—, y las listas
     * que arman los documentos (`RecipesParaImprimir` y `SolicitudesParaImprimir`) son las que las aplican. Sólo se dejan
     * de ofrecer: la hoja pedida por URL directa sigue abriendo (2026-10-22) y no se borra ningún dato.
     *
     * Lo que **no** es de otra especialidad —constancia, reposo, referencia, informe, hospitalización, dieta, laboratorio,
     * estudios, resumen médico, récipes y pre-factura— se sigue ofreciendo siempre, y **en ginecología no cambia nada**.
     *
     * @param  array<int,string>  $registros  los `reg_medico` del médico del contexto
     * @param  bool  $constanciaBase  `true` ofrece la constancia aunque no tenga nada propio (lo que ve el médico)
     * @return array<int,array{clave:string, tipo:string, titulo:string, resumen:string, fecha:?string, icono:string, de_la_pareja:bool}>
     */
    public function ofrecidos(Consulta $consulta, array $registros, AlcanceDeLaEspecialidad $alcance, bool $constanciaBase = false): array
    {
        return $this->armar($consulta, $registros, $constanciaBase, $alcance);
    }

    /**
     * La lista armada, con la especialidad del contexto si la hay: es la única diferencia entre lo que la consulta tiene
     * (`$alcance` nulo) y lo que se ofrece. Cada lista de documentos aplica la suya.
     *
     * @param  array<int,string>  $registros
     * @return array<int,array{clave:string, tipo:string, titulo:string, resumen:string, fecha:?string, icono:string, de_la_pareja:bool}>
     */
    private function armar(Consulta $consulta, array $registros, bool $constanciaBase, ?AlcanceDeLaEspecialidad $alcance): array
    {
        $disponibles = [];

        foreach ($this->recipes->disponibles($consulta, $alcance) as $recipe) {
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

        foreach ($this->solicitudes->disponibles($consulta, $registros, $constanciaBase, $alcance) as $solicitud) {
            $disponibles[] = $solicitud + ['icono' => self::ICONOS[$solicitud['tipo']] ?? 'bi-file-earmark', 'de_la_pareja' => false];
        }

        return $disponibles;
    }

    /**
     * El documento pedido por su clave, o `null` si la consulta no lo tiene.
     *
     * @param  array<int,string>  $registros
     * @param  bool  $pediatria  si el contexto de impresión es pediatría (lo decide el controlador): sus documentos llevan la
     *                           cabecera del escritorio —sexo, representante, historia y edad compacta—
     */
    public function documento(Consulta $consulta, int $pacienteId, array $registros, string $clave, ?int $gesta = null, bool $pediatria = false): DocumentoDeRecipe|DocumentoImpreso|null
    {
        if (str_starts_with($clave, self::PREFIJO_RECIPE)) {
            return $this->recipes->documento($consulta, $pacienteId, substr($clave, strlen(self::PREFIJO_RECIPE)), $pediatria);
        }

        // La gesta del control de embarazo (`&gesta=2`) tiene que cruzar esta fachada: es la única de las
        // solicitudes que no es de la consulta, y sin pasarla el informe salía siempre con la gesta actual.
        return $this->solicitudes->documento($consulta, $pacienteId, $registros, $clave, $gesta, $pediatria);
    }
}
