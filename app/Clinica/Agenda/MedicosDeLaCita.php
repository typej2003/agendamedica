<?php

namespace App\Clinica\Agenda;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use Illuminate\Support\Collection;

/**
 * **Los médicos de una cita** (Fase 3 del cambio de `reg_medico`, 2026-10-20): quién es el **centro** del
 * consultorio, quiénes son los **doctores** que atienden en él y, con eso, qué se escribe en la cita.
 *
 * La decisión del usuario es una sola y se lee en `deUnaCitaNueva()`:
 *
 *  - **la cita del centro nace a nombre del centro**, no de un doctor: `cola.medico_id` = `null` y en la
 *    columna legacy `cola.medico` (la `evolucion.clave` que lee el escritorio) va el **`medicos.id` del
 *    centro**, para que el PowerBuilder no se confunda mientras no hay doctor. El doctor **no se elige al
 *    agendar**: lo asigna la secretaría desde la agenda o lo toma el médico al atender;
 *  - **la cita del consultorio personal** (el doctor en su propio `reg_medico`) sí es del doctor:
 *    `medico_id` = el doctor y `medico` = su clave (Fase 2: `Medico::claveDeEvolucion()`).
 *
 * ⚠️ **`medico_id` es la fuente de verdad**; `cola.medico` es la clave legacy (un `medicos.id` del centro
 * sólo mientras no hay doctor). Ver `App\Models\Cola`.
 *
 * El **centro** es una fila de `medicos` con `es_centro = 1` y su propio `reg_medico` (Fase 2,
 * `CuentaService::crearCentro()`); un doctor convertido en centro desde el panel también lo es. Un centro
 * **no atiende**: no entra en la lista de doctores asignables.
 */
final class MedicosDeLaCita
{
    /**
     * La ficha del **centro** dueña de ese `reg_medico`, o `null` si el consultorio es personal (el
     * registro de un doctor). Es lo que distingue los dos casos de `deUnaCitaNueva()`.
     */
    public function centro(string $regMedico): ?Medico
    {
        return Medico::where('reg_medico', $regMedico)
            ->where('es_centro', true)
            ->orderBy('id')
            ->first();
    }

    public function esCentro(string $regMedico): bool
    {
        return $this->centro($regMedico) !== null;
    }

    /**
     * Los **doctores que atienden** en ese `reg_medico`: los vinculados por `medico_registros` más el dueño
     * del registro cuando es un doctor (el consultorio personal no siempre tiene esa fila). Nunca el centro,
     * que no atiende, y ordenados por apellido y nombre para que el menú de la agenda sea estable.
     *
     * @return Collection<int,Medico>
     */
    public function doctores(string $regMedico)
    {
        $ids = MedicoRegistro::where('reg_medico', $regMedico)
            ->whereNotNull('medico_id')
            ->pluck('medico_id');

        $duenio = Medico::where('reg_medico', $regMedico)->orderBy('id')->first();
        if ($duenio !== null) {
            $ids->push($duenio->id);
        }

        return Medico::whereIn('id', $ids->unique()->values()->all())
            ->where(function ($consulta) {
                $consulta->whereNull('es_centro')->orWhere('es_centro', false);
            })
            ->orderBy('lastname')
            ->orderBy('name')
            ->get();
    }

    /**
     * `medicos.id` => nombre para mostrar: lo que la fila de la agenda ofrece en su menú y lo que el nombre
     * de la cita necesita. Vacío cuando el consultorio no tiene doctores vinculados (datos incompletos).
     *
     * @return array<int,string>
     */
    public function nombres(string $regMedico): array
    {
        return $this->doctores($regMedico)
            ->mapWithKeys(fn (Medico $doctor) => [(int) $doctor->id => $doctor->nombreMostrar])
            ->all();
    }

    /**
     * **¿La agenda tiene que decir el médico de cada cita?** Sólo cuando hay **más de un doctor** en el
     * consultorio del contexto (decisión de Alexander): en un consultorio personal el nombre del doctor se
     * repetiría en todas las filas, así que no se muestra.
     */
    public function varios(string $regMedico): bool
    {
        return $this->doctores($regMedico)->count() > 1;
    }

    /** El doctor asignable del consultorio, o `null` si no es suyo (ni de otro consultorio ni el centro). */
    public function asignable(string $regMedico, int $medicoId): ?Medico
    {
        return $this->doctores($regMedico)->firstWhere('id', $medicoId);
    }

    /**
     * **Lo que se escribe en una cita nueva**: `cola.medico_id` y `cola.medico` (la clave legacy).
     *
     * En un consultorio de centro la cita es **del centro** y nace **sin doctor** (`medico_id = null`), con
     * el `medicos.id` del centro en la columna legacy. En el consultorio personal la cita es del doctor, con
     * su clave en `medico` —la de la Fase 2, buscada en sus registros de trabajo—.
     *
     * @return array{medico: ?int, medico_id: ?int}
     */
    public function deUnaCitaNueva(string $regMedico, Medico $medicoDelContexto): array
    {
        $centro = $this->centro($regMedico);

        if ($centro !== null) {
            return ['medico' => (int) $centro->id, 'medico_id' => null];
        }

        return [
            'medico'    => $medicoDelContexto->claveDeEvolucion($medicoDelContexto->registrosDeTrabajo()),
            'medico_id' => (int) $medicoDelContexto->id,
        ];
    }
}
