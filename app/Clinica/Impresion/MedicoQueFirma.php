<?php

namespace App\Clinica\Impresion;

use App\Clinica\Agenda\MedicosDeLaCita;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Evolucion;
use App\Models\Medico;

/**
 * **Quién firma un documento que se imprime** (Fase 5 del cambio de `reg_medico`): el **doctor que atendió**, no el
 * centro ni el dueño del consultorio. Es la decisión del usuario y el complemento de `MembreteDelMedico`: el membrete
 * puede ser el del centro, la firma y el sello son siempre del doctor.
 *
 * De dónde sale, en orden (el primero que exista gana):
 *
 *  1. **`consultas.medico_id` / `cola.medico_id`** — el `medicos.id` del doctor. Es la fuente de verdad desde la
 *     Fase 3, la escribe `AsignarMedicoACita` y es el único camino a la cita de un centro.
 *  2. **`consultas.medico` / `cola.medico`** — la **clave legacy** de `evolucion` que sigue leyendo el escritorio.
 *     La clave identifica al doctor dentro de un consultorio, así que se buscan los doctores que atienden en el
 *     consultorio de la cita y, entre sus filas de `evolucion` (en cualquiera de sus registros), la que tiene esa
 *     clave. La comprobación de «atiende en este consultorio» no es un adorno: en una cita de centro **sin doctor**,
 *     `cola.medico` guarda el `medicos.id` del centro —no una clave— y sin ella se podría resolver a un doctor
 *     cualquiera cuyo número de clave coincida.
 *  3. **el médico que se pasa como respaldo** —el del contexto—, que es lo que resuelve el consultorio personal y
 *     las consultas y citas viejas, que no tienen ninguna de las dos columnas.
 *
 * Es de **sólo lectura**.
 */
final class MedicoQueFirma
{
    public function __construct(private MedicosDeLaCita $medicos)
    {
    }

    /** El doctor de la **cita** (la secretaría imprime desde la agenda), o el de respaldo. */
    public function deUnaCita(Cola $cita, ?Medico $porDefecto = null): ?Medico
    {
        return $this->porId($cita->medico_id)
            ?? $this->porClave((string) $cita->reg_medico, $cita->medico)
            ?? $porDefecto;
    }

    /**
     * El doctor de la **consulta** (el médico imprime desde la consulta), o el de respaldo. La consulta no siempre
     * dice quién la atendió —las que crea la web dejan `medico_id` y `medico` nulos (`CrearConsulta`)—: en ese caso
     * el que firma es el médico del contexto, que es el que está imprimiendo.
     */
    public function deUnaConsulta(Consulta $consulta, ?Medico $porDefecto = null): ?Medico
    {
        return $this->porId($consulta->medico_id)
            ?? $this->porClave((string) $consulta->reg_medico, $consulta->medico)
            ?? $porDefecto;
    }

    private function porId(mixed $medicoId): ?Medico
    {
        $id = (int) $medicoId;

        return $id <= 0 ? null : Medico::find($id);
    }

    /**
     * El doctor cuya `evolucion.clave` es ese número: la clave identifica al doctor **dentro de un consultorio**, así
     * que se buscan los doctores que atienden en el consultorio de la cita y, entre sus filas de `evolucion`, la que
     * tiene esa clave. La fila puede estar bajo **cualquiera** de sus registros —el escritorio la escribe en el
     * registro donde el médico se configuró, que no siempre es el del centro donde atiende— y por eso la búsqueda no se
     * limita al `reg_medico` de la cita.
     */
    private function porClave(string $regMedico, mixed $clave): ?Medico
    {
        $clave = (int) $clave;

        // `0` es el relleno del esquema en las filas que crea la web, no una clave: con él, cualquier consultorio
        // resolvería a un doctor al azar.
        if ($clave <= 0) {
            return null;
        }

        $correos = $this->medicos->doctores($regMedico)
            ->pluck('email')
            ->filter(fn ($correo) => CamposDePaciente::texto($correo) !== null)
            ->all();

        if ($correos === []) {
            return null;
        }

        $correo = CamposDePaciente::texto(
            Evolucion::whereIn('correo_med', $correos)->where('clave', $clave)->value('correo_med'),
        );

        return $correo === null ? null : Medico::where('email', $correo)->first();
    }
}
