<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\Informe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **El informe médico de una consulta** (WEB-5.3a, pestaña *Informe médico* de las *Solicitudes*, ítem 11 del panel),
 * con sus dos ayudas para escribirlo: las **plantillas** del médico y los **informes anteriores** del mismo paciente.
 *
 * ## Uno por consulta (verificado en la fuente y en el esquema)
 *
 * `informe` se llavea por `nrohistoria` + `nroconsulta` (`PRIMARY KEY` de las dos en `esquemas-asa/*.sql`) y la
 * pestaña trabaja la fila de esa consulta (`dw_27.retrieve(historia, consulta)`, `w_consulta_principal.srw:3338`): **un
 * informe por consulta**. Si no hay, el escritorio crea la plantilla `('INFORME MÉDICO', '.', hoy)` (`:3344-3349`), que
 * no es un documento (`QueEsUnDocumento::informe`). Los campos son los del `d_informe_paciente`: *Para* (`para`,
 * `char(50)`), el texto (`descripcion`, **obligatorio**: la columna es `NOT NULL`) y la fecha (`fe_cha`). La pestaña
 * guarda con `ue_salvar_inf` (`:18368-18378`): `dw_27.object.descripcion[1] = mle_6.text` + `dw_27.update()`.
 *
 * ## Las tres formas de escribirlo
 *
 *  1. **Escritura libre**: *Para*, el texto y la fecha.
 *  2. **Plantilla**: el `dw_85` de la pestaña es la lista de plantillas del médico (`d_l_enfe_actu_para` con
 *     `retrieve('IM')`, `:3052`, `:18501`): las mismas filas de `formato_print` que usan la enfermedad actual
 *     (`'EA'`) y la evolución (`'EV'`), distinguidas por `tipo = 'IM'`. Su doble clic copia la `descripcion` en el campo
 *     (`mle_6.text = desch`, `:18459`): **escribe en el editor, no guarda**.
 *  3. **Copiar un informe anterior**: no es una función del escritorio sino una de las pedidas para la web; lleva al
 *     editor el *Para* y el texto de otro informe del mismo paciente, también sin guardar.
 *
 * El *Resumen médico* (`d_resumen_medico_gra`, el otro botón de la pestaña) es **otra hoja** (WEB-5.3c): se arma sola
 * con datos de toda la consulta —no es una fila de `informe` y no se edita acá— y vive en `ResumenMedicoDeLaConsulta` y
 * `ResumenMedico`.
 *
 * Es de **sólo lectura**: la escritura son `GuardarInformeDeLaConsulta` y `EliminarInformeDeLaConsulta`.
 */
final class InformeDeLaConsulta
{
    /** El `tipo` de `formato_print` del que salen las plantillas del informe: el `retrieve('IM')` de la fuente. */
    public const TIPO_PLANTILLA = 'IM';

    /** El *Para* con el que el escritorio crea el informe (`:3347`). */
    public const PARA_POR_DEFECTO = 'INFORME MÉDICO';

    /** `informe.para` es `char(50)`. */
    public const LARGO_PARA = 50;

    /** Los informes anteriores que se ofrecen para copiar: son para elegir, no para recorrer toda la historia. */
    public const MAX_ANTERIORES = 20;

    /** La fila de `informe` de la consulta, o `null`. */
    public function fila(Consulta $consulta): ?Informe
    {
        return Informe::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->orderBy('id')
            ->first();
    }

    /** ¿La fila es un informe de verdad o la plantilla `.` del escritorio? */
    public function esReal(?Informe $fila): bool
    {
        return $fila !== null && QueEsUnDocumento::informe($fila->descripcion);
    }

    /**
     * Las plantillas de informe del médico (`formato_print`, `tipo = 'IM'`), por título. Una fila sin texto no es una
     * plantilla (copiaría un vacío); una sin título se identifica por su código. Mismo criterio que las plantillas de
     * la evolución (`EvolucionDeLaConsulta::plantillas`).
     *
     * @return Collection<int,object{codigo:string, titulo:string, texto:string}>
     */
    public function plantillas(string $regMedico): Collection
    {
        return DB::table('formato_print')
            ->where('reg_medico', $regMedico)
            ->where('tipo', self::TIPO_PLANTILLA)
            ->orderBy('titulo')
            ->orderBy('codigo')
            ->get(['codigo', 'titulo', 'descripcion'])
            ->map(function ($fila) {
                $texto = TextoDeLaConsulta::limpio($fila->descripcion);

                if ($texto === null) {
                    return null;
                }

                return (object) [
                    'codigo' => trim((string) $fila->codigo),
                    'titulo' => TextoDeLaConsulta::limpio($fila->titulo) ?? trim((string) $fila->codigo),
                    'texto' => $texto,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Los informes **de otras consultas del mismo paciente** que son documentos de verdad, el más reciente primero:
     * lo que se ofrece para copiar al editor. Se filtra por el `reg_medico` **y** el número de historia (el número es
     * único por médico, no global).
     *
     * @return Collection<int,object{consulta:int, fecha:?string, para:?string, texto:string}>
     */
    public function anteriores(Consulta $consulta): Collection
    {
        $fechas = DB::table('consultas')
            ->where('reg_medico', $consulta->reg_medico)
            ->where('numhistoria', (int) $consulta->numhistoria)
            ->pluck('fecha', 'nroconsulta');

        return Informe::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', '<>', (int) $consulta->nroconsulta)
            ->get()
            ->filter(fn (Informe $informe) => QueEsUnDocumento::informe($informe->descripcion))
            ->map(function (Informe $informe) use ($fechas) {
                $fecha = $informe->fe_cha !== null ? $informe->fe_cha->toDateString() : ($fechas[(int) $informe->nroconsulta] ?? null);

                return (object) [
                    'consulta' => (int) $informe->nroconsulta,
                    'fecha' => $fecha === null ? null : substr((string) $fecha, 0, 10),
                    'para' => TextoDeLaConsulta::limpio($informe->para),
                    'texto' => trim((string) $informe->descripcion),
                ];
            })
            ->sortByDesc(fn (object $informe) => ($informe->fecha ?? '') . sprintf('%08d', $informe->consulta))
            ->values()
            ->take(self::MAX_ANTERIORES);
    }
}
