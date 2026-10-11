<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * **Guardar la evolución de una consulta** (`consultas.evolucion`, WEB-4.6, ítem 9 del panel del legado).
 *
 * Es el texto de seguimiento de la atención —cómo sigue el paciente, qué se le indicó, el plan— y es una
 * **columna de la consulta**: cuelga de `numhistoria` + `nroconsulta` y la escribe el `INSERT`/`UPDATE` de
 * `w_consulta_principal` desde el campo de su pestaña *Evolución* (`:2738`, `:2835`). El mapa completo, con
 * las citas de por qué `observaciones` **no** entra acá, está en
 * `App\Clinica\Consulta\EvolucionDeLaConsulta`.
 *
 * Se guarda con un **botón explícito**, no con autoguardado, por la misma razón que la enfermedad actual
 * (WEB-4.2): es texto clínico y el médico tiene que poder decidir cuándo queda escrito. Elegir una
 * plantilla del escritorio **no** escribe nada por su cuenta: copia el texto al campo y el guardado sigue
 * siendo el botón (es lo que hace el doble clic del `dw_84`, `w_consulta_principal.srw:12002`).
 *
 * **`consultas` es una tabla legada que el escritorio sincroniza**, así que la escritura de la web deja su
 * rastro en `sync_changes` **por columna** (`updated`, con `source = web`), igual que las demás secciones
 * de la consulta: sin ese rastro, una edición vieja del escritorio o del teléfono llegaría más tarde, no
 * tendría con qué compararse y pisaría el cambio (PLAN-WEB.md, R3). El guard es el **last-write-wins por
 * columna** del sync (`RegistroDeCambios::permite`), y la Action devuelve si guardó para que quien la
 * llama lo diga en pantalla en vez de mentir con un "Guardado".
 *
 * **De quién es la Action y por qué el sync no la usa:** vive en `Actions\Consulta\` porque el dato es de la
 * consulta (la vara de WEB-4.5, no la de los antecedentes de WEB-4.4) y **sólo la usa la web**. El sync del
 * **móvil no escribe `evolucion`**: sus creaciones clínicas (`App\Sync\CreacionesClinicas` →
 * `App\Actions\Consulta\CrearConsulta`) crean la fila de `consultas` con `reg_medico`, `numhistoria`,
 * `nroconsulta` y `fecha` y nada más, `evolucion` no está en las columnas aceptadas de `SyncAppDataRequest`
 * y el app Flutter no la menciona. El sync del **escritorio** la sube por su camino genérico
 * (`CambiosEscritorio::guardarConsulta` → `filtrar('consultas', …)` y un `UPDATE` con **todas** las columnas
 * del lote), que es table-agnóstico y **no es esta regla**: no hay ninguna regla que extraer, a diferencia de
 * `AsignarHistoria` (WEB-2.11), `CrearConsulta` (WEB-4.1) y `GuardarEnfermedadActual` (WEB-4.2). La Action
 * queda igual en `Actions\Consulta\` para que el día que el móvil u otra superficie escriba la evolución
 * tenga dónde vivir, y su forma es la de las otras acciones por columna.
 */
final class GuardarEvolucionDeLaConsulta
{
    /**
     * @param  CarbonInterface|null  $cuando  el momento de la edición; por defecto, ahora
     * @param  string  $origen  `web` o `mobile`: quién escribe, para el rastro de `sync_changes`
     * @return bool  `true` si la consulta quedó con el texto nuevo; `false` si una edición más nueva la pisa
     */
    public function ejecutar(
        Consulta $consulta,
        ?string $evolucion,
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): bool {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();

        // `''` y el literal `none` que deja el DataWindow del escritorio son el mismo estado que `null`:
        // sin texto. Se guarda `null` para no dejar tres formas de decir lo mismo (el middleware ya
        // convierte `""` en `null`, así que `''` sólo puede llegar por una llamada interna).
        $texto = TextoDeLaConsulta::limpio($evolucion);

        if (! RegistroDeCambios::permite('consultas', (int) $consulta->id, 'evolucion', $cuando)) {
            return false;
        }

        $consulta->evolucion = $texto;
        $consulta->save();

        RegistroDeCambios::registrar(
            'consultas',
            (int) $consulta->id,
            $consulta->reg_medico,
            'evolucion',
            $texto,
            $cuando,
            $origen,
        );

        return true;
    }
}
