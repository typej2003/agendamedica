<?php

namespace App\Actions\Consultorio;

use App\Models\MedicalCenter;
use App\Models\Office;

/**
 * Alta y edición del **consultorio de un médico en una sede** (`offices`) — WEB-2.8b.2.
 *
 * Es la pieza que la agenda necesita para funcionar: la **modalidad** (orden de llegada u hora de
 * cita), la **duración** de la cita y el lugar salen de acá (ver `ArmadorDeAgenda` y `Jornada`). Por
 * eso la Action garantiza lo que la web y el móvil necesitan para verlo:
 *
 *  - **`reg_medico` y `medico_id` siempre se escriben** desde el contexto. El delta del móvil filtra
 *    por `reg_medico`: un consultorio cargado sin él viajaría en la web y no llegaría al teléfono
 *    (es justo lo que pasa con las filas que sembró `MedicalDataSeeder`).
 *  - La **sede tiene que estar activa**: no se cuelga un consultorio de un lugar cerrado.
 *  - La **baja es `activo = false`**, nunca borrar: el consultorio define la jornada de las citas que
 *    ya tiene (modalidad, duración y bloques), y borrarlo dejaría esas citas sin explicación.
 */
class GuardarConsultorio
{
    /**
     * @param  array{medical_center_id: int, office_number: string, phone?: ?string, modalidad: string,
     *                duracion_cita?: ?int, activo?: bool}  $datos
     */
    public function ejecutar(
        array $datos,
        int $medicoId,
        string $regMedico,
        ?Office $consultorio = null
    ): Office {
        // La sede se valida contra las **activas**: `findOrFail` sobre el scope devuelve 404 si está
        // cerrada, y el controlador ya comprobó que existe para dar un error de validación mejor.
        $sede = MedicalCenter::activos()->findOrFail($datos['medical_center_id']);

        $consultorio ??= new Office();

        $consultorio->fill([
            'medical_center_id' => $sede->id,
            'office_number'     => trim((string) $datos['office_number']),
            'phone'             => $this->texto($datos['phone'] ?? null),
            'modalidad'         => $datos['modalidad'],
            // Sin duración cargada queda **nula**, no en un default inventado: el esquema la admite
            // nula y el móvil ya cae a su propio valor cuando no está (igual que el cupo, que se
            // expone sin default para no avisar de un tope que nadie configuró).
            'duracion_cita'     => $datos['duracion_cita'] ?? null,
            'activo'            => (bool) ($datos['activo'] ?? true),
        ]);

        // De quién es: sale del contexto, jamás del formulario (no se carga un consultorio ajeno).
        $consultorio->medico_id = $medicoId;
        $consultorio->reg_medico = $regMedico;

        $consultorio->save();

        return $consultorio;
    }

    private function texto($valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
