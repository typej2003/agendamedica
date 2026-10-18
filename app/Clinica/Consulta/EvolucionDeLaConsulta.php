<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use Illuminate\Support\Collection;

/**
 * **La evolución de una consulta** (WEB-4.6, ítem 9 del panel del legado): el dato y las **plantillas** que
 * el escritorio ofrece al lado.
 *
 * ## Dónde vive el dato y de qué cuelga (verificado en la fuente)
 *
 * La evolución **es una columna de la consulta**: `consultas.evolucion` (`longText nullable`, migración
 * `2026_08_25_055351`, línea 147). No hay tabla de evoluciones ni fila que cuelgue de otra parte —lo que
 * hay es una tabla llamada `evolucion` que **no tiene nada que ver**: es la configuración del médico (su
 * logo, su horario, su contraseña del sistema viejo, la clave con la que `cola.medico` lo identifica),
 * una fila por médico—. La confusión es real y está anotada en la wiki `04-historia-clinica.md`; acá se
 * lee y se escribe `consultas.evolucion` y nada más.
 *
 * La fuente lo dice campo por campo: la pestaña *Evolución* de `w_consulta_principal` es un
 * `multilineedit` (`mle_evolucion`, `:12067-12082`) cuyo texto va a la columna en el `INSERT`/`UPDATE`
 * de la ventana (`:2738` lo toma, `:2835` lo escribe en `consultas`, `:2957` y `:4477` lo vuelven a
 * leer) y que se carga al abrir la ventana con `SELECT evolucion, fecha FROM consultas WHERE numhistoria
 * = :historia AND nroconsulta = :alNewRow` (`:4218-4242`, el `ifcambialo`). O sea: **cuelga de
 * `numhistoria` + `nroconsulta`**, igual que la enfermedad actual y la evaluación física.
 *
 * ## `observaciones` NO es de esta pestaña (y por eso la sección no la toca)
 *
 * La columna vecina `consultas.observaciones` (migración, línea 148) parece el segundo campo de este
 * ítem —el panel del legado se llama "Evolución" en la wiki y el plan de la web lo lista como "evolución,
 * observaciones" (`PLAN-WEB.md` §2, "Núcleo")—, pero en la fuente **no lo es**: el `ue_guardar` la
 * escribe desde el campo *Colposcopia* de la solapa *Examen Ginecológico*
 * (`s_obs = tab_1.tabpage_examen.tab_7.tabpage_31.mle_4.text`, `:2757`; `"observaciones" = :s_obs`,
 * `:2836`; y el comentario de `:2766` dice `SET "colposcopia" = :s_obs`, que es la misma escritura contra
 * la tabla del examen) y al recargar la ventana la vuelve a poner en **ese** campo
 * (`:2958` → `tabpage_31.mle_4`). `tabpage_31` es la solapa de colposcopia: sus botones de imprimir
 * llaman a `d_colposcopia_1`/`d_colposcopia_2` (`:10608`, `:10614`) y su datawindow de catálogo se
 * `retrieve('CO')` (`:3043`).
 *
 * Conclusión: **`observaciones` es una copia de la colposcopia** (que es de F6/WEB-4.7 y tiene informe
 * propio) y **no un campo de la evolución**. La sección escribe **sólo `evolucion`**; `observaciones`
 * queda afuera y así se documenta (TODO-WEB.md §WEB-4.6 y la wiki `04-historia-clinica.md`, ítem 9).
 *
 * ## Las plantillas
 *
 * El `dw_84` de la pestaña es el **buscador de plantillas del médico**: su dataobject es
 * `d_l_enfe_actu_para`, que consulta `formato_print` filtrando por `tipo = :para` (`codigo`, `titulo`,
 * `descripcion`, `tipo`; el `titulo` es la única columna visible), la ventana lo `retrieve('EV')`
 * (`:3055` y `:4612`) y su doble clic pone **la `descripcion`** en el campo de la evolución
 * (`mle_evolucion.text = desch`, `:12002`, donde `desch = dw_84.object.descripcion[…]`). Su caja
 * *Buscar:* es `sle_15`, cuyo `ue_tecla` (declarado `pbm_keydown`, `:12021`) arma `titulo like '%…%'`,
 * filtra el DataWindow y lo vuelve a traer (`:12038-12048`).
 *
 * La web reproduce eso con el catálogo que el escritorio **sube por su sync** (`formato_print` está en la
 * lista blanca de `config/sync_legado.php`, línea 39): lista las plantillas de *este* médico y al elegir
 * una copia su texto **en el campo**, que es donde el médico lo termina de editar y lo guarda con el
 * botón. No se guarda nada por elegir una plantilla.
 *
 * Es **sólo lectura**: la escritura es `App\Actions\Consulta\GuardarEvolucionDeLaConsulta`.
 */
final class EvolucionDeLaConsulta
{
    /** El `tipo` de `formato_print` del que salen las plantillas de evolución: el `retrieve('EV')` de la fuente. */
    public const TIPO_PLANTILLA = 'EV';

    /**
     * El texto de la evolución tal como quedó guardado (los "vacíos" del legado se leen como "sin texto").
     */
    public function de(Consulta $consulta): ?string
    {
        return TextoDeLaConsulta::limpio($consulta->evolucion);
    }

    /**
     * Las plantillas de evolución del médico, por título.
     *
     * Se leen con el constructor de consultas y no con un modelo Eloquent porque `formato_print` **no es
     * un catálogo que la web administre**: es una tabla del escritorio que sólo se lee para armar esta
     * lista (a diferencia de `diagnosticos` o `motivos_consulta`, que sí tienen alta desde la web).
     *
     * Una fila sin texto no es una plantilla: el escritorio la muestra con su `titulo` y al elegirla
     * copiaría un vacío, así que se descarta. El criterio es el mismo que el de los "vacíos" del legado.
     *
     * @return Collection<int,object{codigo:string, titulo:string, texto:string}>
     */
    public function plantillas(string $regMedico): Collection
    {
        // La lista (y la regla de los "vacíos") es la misma en todas las secciones de la consulta y vive en
        // `PlantillasDelTexto`: acá sólo se dice de qué tipo son las de la evolución.
        return PlantillasDelTexto::de($regMedico, self::TIPO_PLANTILLA);
    }
}
