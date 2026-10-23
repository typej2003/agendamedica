<?php

namespace App\Clinica\Pacientes;

use Illuminate\Support\Facades\DB;

/**
 * **El representante de los niños de una familia, desde `familia_bebe`**: el escritorio de pediatría guarda al representante en la familia
 * (`ced_representante`, la madre o el padre según `status`, sus teléfonos) y no en `representante`, que es de donde la web lee la ficha,
 * el teléfono del recordatorio (`TelefonoDeContacto`) y las hojas impresas. Sin este puente, un niño con la familia completa aparece «sin
 * representante cargado».
 *
 * **Completa, nunca pisa.** Para cada niño de la familia sin fila en `representante` la crea con lo que dice la familia; si ya tiene fila,
 * sólo llena las columnas que estén vacías. Lo que escribió alguien en la ficha manda siempre. Los datos salen de la misma regla que ya usa
 * `RepresentantePorCedula::datosDeLaFamilia()` (qué padre es el representante, su teléfono, parentesco `M`/`P`).
 *
 * Lo llama `GuardarFamilia` al guardar y la migración de relleno una vez por familia. Idempotente.
 */
final class RepresentanteDesdeLaFamilia
{
    /**
     * @return int  cuántos niños quedaron con la fila creada o completada
     */
    public function completar(string $regMedico, int $familia): int
    {
        $bebe = DB::table('familia_bebe')
            ->where('reg_medico', $regMedico)->where('familia', $familia)
            ->orderBy('id')->first();

        $chamos = DB::table('paciente_chamo')
            ->where('reg_medico', $regMedico)->where('familia', $familia)
            ->orderBy('id')->get(['historia', 'representante']);

        if ($bebe === null || $chamos->isEmpty()) {
            return 0;
        }

        $datos = (new RepresentantePorCedula())->datosDeLaFamilia($bebe);
        $tocados = 0;

        foreach ($chamos as $chamo) {
            $historia = CamposDePaciente::historiaNumerica($chamo->historia);
            $cedula = CamposDePaciente::texto($bebe->ced_representante) ?? CamposDePaciente::texto($chamo->representante);

            $valores = array_filter([
                'nombre' => $this->cortar($datos['nombre'], 40),
                'codeparentesco' => $this->cortar($datos['codeparentesco'], 1),
                'cedula' => $this->cortar($cedula, 10),
                'telefono' => $this->cortar($datos['telefono'], 20),
                'email' => $this->cortar($datos['email'], 100),
                'direccion' => $this->cortar($datos['direccion'], 60),
            ], fn ($v) => $v !== null);

            // Sin historia o sin nada que decir (ni nombre, ni cédula, ni teléfono) no hay representante que crear.
            if ($historia === null || ! array_intersect_key($valores, array_flip(['nombre', 'cedula', 'telefono']))) {
                continue;
            }

            $existente = DB::table('representante')
                ->where('reg_medico', $regMedico)->where('numhistoria', $historia)
                ->orderBy('id')->first();

            if ($existente === null) {
                DB::table('representante')->insert($valores + [
                    'reg_medico' => $regMedico, 'numhistoria' => $historia, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $tocados++;

                continue;
            }

            $faltan = array_filter($valores, fn ($v, $columna) => CamposDePaciente::texto($existente->{$columna}) === null, ARRAY_FILTER_USE_BOTH);

            if ($faltan !== []) {
                DB::table('representante')->where('id', $existente->id)->update($faltan + ['updated_at' => now()]);
                $tocados++;
            }
        }

        return $tocados;
    }

    /** Relleno de todo el consultorio: una pasada por cada familia con niños. @return int niños tocados */
    public function completarTodo(?string $regMedico = null): int
    {
        $familias = DB::table('paciente_chamo')
            ->when($regMedico !== null, fn ($q) => $q->where('reg_medico', $regMedico))
            ->whereNotNull('familia')
            ->distinct()->get(['reg_medico', 'familia']);

        $total = 0;
        foreach ($familias as $f) {
            $total += $this->completar((string) $f->reg_medico, (int) $f->familia);
        }

        return $total;
    }

    private function cortar(?string $valor, int $largo): ?string
    {
        $texto = CamposDePaciente::texto($valor);

        return $texto === null ? null : mb_substr($texto, 0, $largo);
    }
}
