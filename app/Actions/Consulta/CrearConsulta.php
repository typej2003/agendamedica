<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Crear la consulta de una historia**, con el `nroconsulta` que le toca.
 *
 * Es la regla del **correlativo de la consulta**: la última de **esa historia** + 1 (wiki
 * `04-historia-clinica.md`, "Numeración correlativa por médico": la historia es la última del médico + 1
 * y la consulta, la última de esa historia + 1). Estaba adentro del sync del móvil
 * (`App\Sync\CreacionesClinicas::crearConsulta`, ROADMAP.md Paso 18.B) y **se extrajo acá** al abrir la
 * consulta desde la web ("Atender" abre la consulta, F4/WEB-4.1), con el mismo patrón que WEB-2.11 hizo
 * con `AsignarHistoria` (PLAN-WEB.md, R3: ninguna regla de negocio se escribe dos veces).
 *
 * Las tres cosas que **no** hace y son de quien la llama:
 *
 *  - **No busca la historia ni la crea**: recibe la `Historia` ya resuelta. Sin historia no hay consulta
 *    ("no se atiende sin historia", wiki) y eso lo decide `AbrirConsulta`.
 *  - **No marca la cita como atendida**: eso es `App\Actions\Agenda\AtenderCita`. El legado lo hace en
 *    el **mismo** evento (`UPDATE cola SET atendido = 1` en el `open` de `w_consulta_principal`) pero
 *    son dos escrituras distintas, y la web las ordena: primero la consulta, después la marca.
 *  - **No toca `clave_escritorio`**: la misma decisión que traía el sync. El escritorio se la pone
 *    cuando sube la consulta que él mismo creó (`CambiosEscritorio::guardarConsulta`), y con la clave
 *    vacía la fila del app y la del escritorio se encuentran por `numhistoria` + `nroconsulta` sin
 *    pisarse.
 *
 * **El doctor de la consulta** (2026-10-20) lo trae quien la abre: la web pasa el médico del contexto —el
 * doctor que está atendiendo— y **el sync del móvil el médico autenticado** (`CreacionesClinicas`, el dueño de
 * la sesión del teléfono), así que las dos superficies sellan la consulta con su doctor. Se escribe en las
 * **dos** columnas que entienden las dos superficies: `medico_id` (el `medicos.id`, la fuente de verdad de
 * `App\Clinica\Impresion\MedicoQueFirma`) y `medico` (la `clave` de `evolucion` que sigue leyendo el
 * escritorio, resuelta con `Medico::claveDeEvolucion()` sobre sus registros de trabajo, la misma de la Fase 2).
 * Sin médico —el parámetro sigue siendo opcional para quien no lo tenga a mano— la consulta nace con las dos
 * en `null`, como antes, y el firmante cae en la clave o en el médico del contexto.
 *
 * Las demás columnas son las que el sync ya escribía —`reg_medico`, `numhistoria`, `nroconsulta` y `fecha`—
 * sobre la tabla legada real, para que el escritorio la baje como si la hubiera creado él (ver
 * PENDIENTES-POWERBUILDER.md).
 *
 * El correlativo se mide sobre el `reg_medico` **de la historia** y no sobre todos los registros del
 * médico: una consulta cuelga de una historia, y la historia tiene un solo registro. El resultado es el
 * mismo —la `Historia` que llega siempre pertenece a uno de los registros del médico— y la consulta
 * queda del mismo registro que su historia, que es lo que el escritorio espera encontrar al bajarla.
 */
final class CrearConsulta
{
    /**
     * @param  Historia  $historia  la historia a la que cuelga la consulta (ya resuelta y del médico)
     * @param  CarbonInterface  $fecha  el **día de la consulta**, en la zona del consultorio
     * @param  Medico|null  $medico  **el doctor que la atiende** (el del contexto de la web): queda escrito en
     *                               `medico_id` y, como clave legacy, en `medico`. Sin él, la consulta nace sin doctor.
     */
    public function ejecutar(Historia $historia, CarbonInterface $fecha, ?Medico $medico = null): Consulta
    {
        $numhistoria = (int) $historia->numhistoria;

        // La clave legacy es la del doctor en cualquiera de sus registros: la fila de `evolucion` del médico
        // vive bajo su registro, no necesariamente bajo el del consultorio donde atiende (Fase 2).
        $clave = $medico === null ? null : $medico->claveDeEvolucion($medico->registrosDeTrabajo());

        return DB::transaction(function () use ($historia, $numhistoria, $fecha, $medico, $clave) {
            $ultima = Consulta::where('numhistoria', $numhistoria)
                ->where('reg_medico', $historia->reg_medico)
                ->max('nroconsulta');

            return Consulta::create([
                'reg_medico' => $historia->reg_medico,
                'numhistoria' => $numhistoria,
                'nroconsulta' => ((int) $ultima) + 1,
                // La fecha se guarda como `Y-m-d` (el modelo la castea a `date` y la escribe con hora).
                'fecha' => $fecha->toDateString(),
                'medico_id' => $medico?->id,
                'medico' => $clave,
            ]);
        });
    }
}
