<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * **Guardar la enfermedad actual de una consulta** (`consultas.enfermedadactual`, WEB-4.2).
 *
 * Es el ítem 2 del panel del legado: el texto libre de lo que el paciente tiene hoy (wiki
 * `04-historia-clinica.md`). **Es editable** por pedido de Alexander (2026-10-09, TODO-WEB.md WEB-4.2) —
 * el móvil la muestra de sólo lectura— y se guarda con un **botón explícito**, no con autoguardado: es
 * texto clínico y el médico tiene que poder decidir cuándo queda escrito.
 *
 * **`consultas` es una tabla legada que el móvil sincroniza**, así que la escritura de la web deja su
 * rastro en `sync_changes` **por columna** (`updated`, con `source = web`), igual que las acciones de la
 * agenda (`AtenderCita`, `CobrarCita`): sin ese rastro, una edición vieja del teléfono llegaría más
 * tarde, no tendría con qué compararse y pisaría el cambio nuevo (PLAN-WEB.md, R3).
 *
 * El guard es el **last-write-wins por columna** del sync (`RegistroDeCambios::permite`): si ya hay un
 * cambio igual o más nuevo sobre `enfermedadactual` —de otro dispositivo o del escritorio—, esta
 * escritura no se aplica. La Action devuelve si guardó, y quien la llama lo dice en pantalla en vez de
 * mentir con un "Guardado" sobre algo que no se guardó.
 */
final class GuardarEnfermedadActual
{
    /**
     * @param  CarbonInterface|null  $cuando  el momento de la edición; por defecto, ahora
     * @param  string  $origen  `web` o `mobile`: quién escribe, para el rastro de `sync_changes`
     * @return bool  `true` si la consulta quedó con el texto nuevo; `false` si una edición más nueva la pisa
     */
    public function ejecutar(
        Consulta $consulta,
        ?string $enfermedadActual,
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): bool {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();

        // El `""` del formulario y el `null` de la columna son el mismo estado: sin texto. Se guarda
        // `null` para no dejar dos formas de decir lo mismo (el middleware ya convierte `""` en `null`).
        $texto = $enfermedadActual === null ? null : trim($enfermedadActual);
        $texto = $texto === '' ? null : $texto;

        if (! RegistroDeCambios::permite('consultas', $consulta->id, 'enfermedadactual', $cuando)) {
            return false;
        }

        $consulta->enfermedadactual = $texto;
        $consulta->save();

        RegistroDeCambios::registrar(
            'consultas',
            $consulta->id,
            $consulta->reg_medico,
            'enfermedadactual',
            $texto,
            $cuando,
            $origen,
        );

        return true;
    }
}
