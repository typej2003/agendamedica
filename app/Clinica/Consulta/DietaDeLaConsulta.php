<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\DietaPaciente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **La dieta / recomendaciones de una consulta** (WEB-5.3b, pestaña *Dietas / Recomendaciones* de las *Solicitudes*, ítem 11).
 *
 * ## Una por consulta (verificado en la fuente y en el esquema)
 *
 * `dieta_paciente` se llavea por `histroria` + `consulta` —la errata de la primera columna es del esquema— y es el **número** de la
 * consulta, no su id. El DataWindow `d_dietas_paciente` declara esas dos como clave (`key=yes`) y el índice único del legado es
 * `("histroria", "consulta")` (`esquemas-asa/cardiologia.sql`, `cirugia.sql`): **una por consulta**.
 *
 * ## Cómo la maneja el escritorio, y qué se llevó a la web
 *
 *  - **La fila no se crea al abrir la consulta.** Sólo nace al «pasar» una plantilla: `pb_132` hace un `INSERT INTO dieta_paciente
 *    (histroria, consulta, cod_dieta, descripcion, ejecutada, dieta) VALUES (…, código de la plantilla, su texto, 'S', su título)`
 *    (`w_consulta_principal.srw:19689-19702`) y pone el texto en el campo. Por eso la base de desarrollo tiene 0 filas.
 *  - El texto se edita en `mle_dieta` y se guarda sobre la fila actual (`ue_guardar_dieta`: `dw_89.object.descripcion[…] =
 *    mle_dieta.text` + `update()`, `:19406-19410`). **Sin fila no se guarda nada**: el escritorio no deja escribir dieta libre.
 *    La web sí: «texto libre o por plantilla» es lo pedido.
 *  - El título (`dieta`, hasta 200) es el de la plantilla elegida, y la hoja (`d_recipe_dieta`) lo imprime en negrita sobre el
 *    texto. La web lo ofrece como campo opcional que la plantilla llena.
 *  - **Las plantillas** son la lista `d_l_enfe_actu_para` con `retrieve('DI')` (`:3058`, `:4615`): las filas de `formato_print` con
 *    `tipo = 'DI'`, las mismas del resto de las pestañas distinguidas por el tipo (`EA`, `EV`, `IM`). El «Eliminar» (`pb_131`)
 *    borra la fila.
 *  - **`cod_dieta`, `ejecutada` y `calorias` no se editan en ninguna pantalla** (`calorias` no se escribe jamás; `ejecutada` sólo
 *    nace como `'S'`). La web no los toca al guardar; al crear una fila deja `ejecutada = 'S'` —lo único que el escritorio escribe
 *    en toda fila que crea— y `cod_dieta` en `NULL` (el de la plantilla no cabe: `char(2)` contra códigos de 4 y 5 caracteres).
 *
 * Es de **sólo lectura**: la escritura son `GuardarDietaDeLaConsulta` y `EliminarDietaDeLaConsulta`.
 */
final class DietaDeLaConsulta
{
    /** El `tipo` de `formato_print` del que salen las plantillas: el `retrieve('DI')` de la fuente. */
    public const TIPO_PLANTILLA = 'DI';

    /** `dieta_paciente.dieta` es `char(200)` (`edit.limit=200`). */
    public const LARGO_TITULO = 200;

    /** El tope del texto: el escritorio admite 32.000; la web, 20.000 como el informe. */
    public const LARGO_TEXTO = 20000;

    /** Lo que el escritorio escribe en `ejecutada` de toda fila que crea (`:19697`). */
    public const EJECUTADA = 'S';

    /** La fila de `dieta_paciente` de la consulta, o `null`. */
    public function fila(Consulta $consulta): ?DietaPaciente
    {
        return DietaPaciente::where('reg_medico', $consulta->reg_medico)
            ->where('histroria', (int) $consulta->numhistoria)
            ->where('consulta', (int) $consulta->nroconsulta)
            ->orderBy('id')
            ->first();
    }

    /** ¿La fila es una dieta con texto? */
    public function esReal(?DietaPaciente $fila): bool
    {
        return $fila !== null && QueEsUnDocumento::dieta($fila->descripcion);
    }

    /**
     * Las plantillas del médico (`formato_print`, `tipo = 'DI'`), por título. Una fila sin texto no es una plantilla (copiaría un
     * vacío); una sin título se identifica por su código. Mismo criterio que las del informe y la evolución.
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
}
