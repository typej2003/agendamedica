<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\Historia;
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
 * Las columnas son las que el sync ya escribía —`reg_medico`, `numhistoria`, `nroconsulta` y `fecha`—
 * sobre la tabla legada real, para que el escritorio la baje como si la hubiera creado él (ver
 * PENDIENTES-POWERBUILDER.md). `medico` (la clave de `evolucion`) queda nula, igual que en el sync: el
 * médico de la consulta se identifica por `reg_medico`.
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
     */
    public function ejecutar(Historia $historia, CarbonInterface $fecha): Consulta
    {
        $numhistoria = (int) $historia->numhistoria;

        return DB::transaction(function () use ($historia, $numhistoria, $fecha) {
            $ultima = Consulta::where('numhistoria', $numhistoria)
                ->where('reg_medico', $historia->reg_medico)
                ->max('nroconsulta');

            return Consulta::create([
                'reg_medico' => $historia->reg_medico,
                'numhistoria' => $numhistoria,
                'nroconsulta' => ((int) $ultima) + 1,
                // La fecha se guarda como `Y-m-d` (el modelo la castea a `date` y la escribe con hora).
                'fecha' => $fecha->toDateString(),
            ]);
        });
    }
}
