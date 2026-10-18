<?php

namespace App\Clinica\Pacientes;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **La familia del paciente pediátrico** (WEB-7.4.2a): lo que el escritorio de pediatría guarda aparte de la ficha.
 *
 * En PediaReport cada niño (`paciente_chamo`, clave `historia`) pertenece a una **familia** (`paciente_chamo.familia` →
 * `familia_bebe`): nombre, nacimiento y ocupación del padre y de la madre, dirección, **tres teléfonos** (madre, padre y otro) y la
 * cédula del representante; varios niños (los hermanos) comparten la misma `familia`. Es distinta de la tabla `representante` de
 * WEB-3.4 (una fila por historia, del legado compartido), que en pediatría llega casi vacía.
 *
 * **Sólo lectura** (la escritura es WEB-7.4.2b). Las dos tablas son del legado y se leen tal cual; el tenant es `reg_medico`.
 * Un paciente que no es un `paciente_chamo` (cualquier paciente de ginecología) no tiene familia: devuelve `null` y la ficha no
 * muestra nada.
 */
class FamiliaDelPaciente
{
    /**
     * @return array{chamo:object, familia:?object, hermanos:Collection<int,object>}|null
     */
    public function de(string $regMedico, ?string $numhistoria): ?array
    {
        $historia = CamposDePaciente::historiaNumerica($numhistoria);

        if ($historia === null) {
            return null;
        }

        $chamo = DB::table('paciente_chamo')
            ->where('reg_medico', $regMedico)
            ->where('historia', $historia)
            ->orderBy('id')
            ->first();

        if ($chamo === null) {
            return null;
        }

        $familia = DB::table('familia_bebe')
            ->where('reg_medico', $regMedico)
            ->where('familia', $chamo->familia)
            ->orderBy('id')
            ->first();

        return [
            'chamo' => $chamo,
            'familia' => $familia,
            'hermanos' => $this->hermanos($regMedico, $chamo),
        ];
    }

    /**
     * Los otros niños de la misma familia, del más viejo al más joven, con el `id` de su ficha (el del paciente, por el vínculo
     * `medico_pacientes`) para poder enlazarlos. Un hermano sin ficha en la web se lista igual, sin enlace.
     *
     * @return Collection<int,object>
     */
    private function hermanos(string $regMedico, object $chamo): Collection
    {
        $hermanos = DB::table('paciente_chamo')
            ->where('reg_medico', $regMedico)
            ->where('familia', $chamo->familia)
            ->where('historia', '<>', $chamo->historia)
            ->orderBy('f_nacimiento')
            ->orderBy('historia')
            ->get(['historia', 'nombre', 'sexo', 'f_nacimiento']);

        if ($hermanos->isEmpty()) {
            return $hermanos;
        }

        $fichas = DB::table('medico_pacientes')
            ->where('reg_medico', $regMedico)
            ->whereIn('numhistoria', $hermanos->map(fn ($h) => (string) $h->historia)->all())
            ->pluck('paciente_id', 'numhistoria');

        return $hermanos->map(function ($hermano) use ($fichas) {
            $hermano->paciente_id = $fichas[(string) $hermano->historia] ?? null;

            return $hermano;
        });
    }
}
