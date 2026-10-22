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
 * **El doctor es uno solo** (corrección del usuario, 2026-10-20): sus accesos —el registro personal y los
 * centros donde trabaja— se agrupan por médico, igual que la tarjeta de la pantalla. Su menú de consultorios
 * es la unión de los suyos y los del centro, y el consultorio elegido fija el `reg_medico` del contexto.
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

        // Los accesos se agrupan por **médico**: el doctor con su consultorio personal y los centros donde
        // trabaja es **un solo médico** (una sola tarjeta), no varios. La secretaría sí tiene una tarjeta por
        // centro, porque ahí el médico del contexto es la fila del centro.
        $grupos = [];
        foreach ($disponibles as $acceso) {
            $grupos[$acceso['medico']->id][] = $acceso;
        }

        if (count($grupos) !== 1) {
            return null; // varios médicos/centros: hay que decir con cuál se entra
        }

        $accesos = reset($grupos);
        $especialidades = collect($accesos)->flatMap(fn ($acceso) => $acceso['especialidades'])->unique('id')->values();
        $consultorios = collect($accesos)->flatMap(fn ($acceso) => $acceso['sedes'])->unique('id')->values();

        if ($especialidades->count() !== 1 || $consultorios->count() > 1) {
            return null;
        }

        $consultorio = $consultorios->first();
        $accesoDelConsultorio = $consultorio !== null
            ? collect($accesos)->first(fn ($acceso) => $acceso['sedes']->contains('id', $consultorio->id))
            : null;

        return [
            'reg_medico'   => (string) ($accesoDelConsultorio['reg_medico'] ?? $accesos[0]['reg_medico']),
            'specialty_id' => (int) $especialidades->first()->id,
            // Sin consultorios no se fija ninguno: el contexto queda en «todos» (que es lo mismo cuando no
            // hay ninguno, y es lo que la agenda sabe leer).
            'office_id'    => $consultorio !== null ? (int) $consultorio->id : null,
        ];
    }

    /**
     * El contexto que alcanza para **cargar un consultorio** sin haber elegido nada, o `null` si hay que elegir.
     *
     * Es más permisivo que `de()` **a propósito**: dar de alta un consultorio sólo necesita saber **de quién
     * es**, y eso no depende de la especialidad (un médico recién creado desde el panel suele no tener ninguna
     * todavía) ni de que ya tenga consultorios. La regla es una sola: **un registro**. Con dos —el propio y el
     * centro donde trabaja— hay que decir en cuál se carga, así que no se resuelve solo.
     *
     * Se usa desde la pantalla «Sedes y consultorios» (`clinica.contexto:consultorios`): antes un médico nuevo
     * llegaba ahí sin contexto, la pantalla no le ofrecía cargar nada y el menú lateral quedaba sólo en
     * «Inicio», sin forma de salir del paso.
     *
     * @return array{reg_medico:string, specialty_id:?int}|null
     */
    public function paraCargarUnConsultorio(User $user): ?array
    {
        $disponibles = $this->contexto->disponibles($user);

        if (count($disponibles) !== 1) {
            return null;
        }

        $regMedico = (string) array_key_first($disponibles);

        // La especialidad es opcional: con una sola se toma (es la que el menú va a mostrar), y sin ninguna
        // se fija el registro igual, que es lo que hace falta para dar de alta el consultorio.
        return [
            'reg_medico'   => $regMedico,
            'specialty_id' => $disponibles[$regMedico]['especialidades']->first()?->id,
        ];
    }
}
