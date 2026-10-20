<?php

namespace App\Clinica;

use App\Models\User;

/**
 * **El contexto que no hay que elegir** (decisión del usuario, 2026-10-19): cuando no queda nada que decidir
 * —un solo médico, una sola especialidad y uno o ningún consultorio— la pantalla de elegir consultorio se
 * **salta** y se entra directo al consultorio. La pantalla queda para quien sí tiene algo que elegir (dos
 * especialidades, dos consultorios o varios médicos con los que trabaja).
 *
 * Es lo contrario de preguntar: la decisión de qué se puede elegir de verdad la sigue teniendo
 * `ContextoTrabajo::disponibles()` —es la misma lectura que pinta la pantalla—, y acá sólo se comprueba si
 * queda una sola respuesta posible para cada cosa.
 *
 * ⚠️ Ojo con **0 especialidades**: no es «una sola», es una configuración incompleta (el médico no tiene
 * ninguna asignada), así que no se salta la pantalla: es donde se ve el aviso.
 */
final class ContextoAutomatico
{
    public function __construct(private ContextoTrabajo $contexto)
    {
    }

    /**
     * El contexto que hay que fijar sin preguntar, o `null` si hay algo que elegir.
     *
     * @return array{reg_medico:string, specialty_id:int, office_id:?int}|null
     */
    public function de(User $user): ?array
    {
        $disponibles = $this->contexto->disponibles($user);

        // Varios médicos = varios centros/registros: hay que decir con cuál se entra.
        if (count($disponibles) !== 1) {
            return null;
        }

        $acceso = reset($disponibles);
        $especialidades = $acceso['especialidades'];
        $consultorios = $acceso['sedes'];

        if ($especialidades->count() !== 1 || $consultorios->count() > 1) {
            return null;
        }

        return [
            'reg_medico'   => (string) $acceso['reg_medico'],
            'specialty_id' => (int) $especialidades->first()->id,
            // Sin consultorios no se fija ninguno: el contexto queda en «todos» (que es lo mismo cuando no
            // hay ninguno, y es lo que la agenda sabe leer).
            'office_id'    => $consultorios->count() === 1 ? (int) $consultorios->first()->id : null,
        ];
    }
}
