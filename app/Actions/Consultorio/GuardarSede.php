<?php

namespace App\Actions\Consultorio;

use App\Models\City;
use App\Models\MedicalCenter;

/**
 * Alta y edición de una **sede** (`medical_centers`) — el lugar donde atiende un médico.
 *
 * Es la primera mitad de la configuración de agenda (WEB-2.8b.1): sin sedes no hay dónde colgar un
 * consultorio. El **lugar es de la clínica** (el nombre y la dirección son los mismos para todos los
 * médicos que atienden ahí), así que no lleva `reg_medico` ni médico: la fila es del catálogo.
 *
 * El **país y el estado no se preguntan**: se derivan de la ciudad elegida
 * (`cities.state_id` → `estados.country_id`). Son obligatorios en la tabla por herencia del esquema
 * migrado, y pedir en el formulario un estado que ya se deduce de la ciudad es pedir el mismo dato
 * dos veces (y darle la oportunidad de contradecirse).
 *
 * La **baja es `activo = false`**, nunca borrar: `cola.medical_center_id` referencia la sede y una
 * cita vieja no puede perder su jornada. Una sede inactiva deja de ofrecerse al agendar y al crear un
 * consultorio, pero el historial sigue leyéndola.
 */
class GuardarSede
{
    /**
     * @param  array{name: string, address: string, phone?: ?string, city_id: int, activo?: bool}  $datos
     */
    public function ejecutar(array $datos, ?MedicalCenter $sede = null): MedicalCenter
    {
        $ciudad = City::findOrFail($datos['city_id']);

        $sede ??= new MedicalCenter();

        $sede->fill([
            'name'    => trim((string) $datos['name']),
            'address' => trim((string) $datos['address']),
            'phone'   => $this->texto($datos['phone'] ?? null),
            'city_id' => $ciudad->id,
            // Derivados: la ciudad manda sobre el estado y el país.
            'state_id'   => $ciudad->state_id,
            'country_id' => $ciudad->state->country_id,
            'activo'     => (bool) ($datos['activo'] ?? true),
        ])->save();

        return $sede;
    }

    private function texto($valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
