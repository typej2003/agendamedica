<?php

namespace App\Actions\Catalogos;

use App\Clinica\Catalogos\MotivosDeCitaSugeridos;
use App\Models\Medico;
use InvalidArgumentException;

/**
 * **Agregar al catálogo del médico los motivos de cita que su especialidad sugiere** (WEB-7.4.4f): hoy, «Control de niño sano» en pediatría. Pasa por
 * `CrearMotivoDeCita` (el código lo da el servidor, queda su rastro, sin precio y para todas las sedes), así que es la misma regla del alta
 * a mano. **Idempotente**: lo que el médico ya tiene no se repite ni se toca. Devuelve las razones que agregó.
 */
final class AgregarMotivosSugeridos
{
    public function __construct(private CrearMotivoDeCita $crear, private MotivosDeCitaSugeridos $sugeridos)
    {
    }

    /** @return array<int,string> */
    public function ejecutar(Medico $medico, string $regMedico, ?string $slugDeLaEspecialidad): array
    {
        $agregados = [];

        foreach ($this->sugeridos->faltantes($regMedico, $slugDeLaEspecialidad) as $razon) {
            try {
                $this->crear->ejecutar($medico, $regMedico, ['razon' => $razon]);
                $agregados[] = mb_strtoupper($razon);
            } catch (InvalidArgumentException) {
                // Ya había uno igual en esa sede: no se repite.
            }
        }

        return $agregados;
    }
}
