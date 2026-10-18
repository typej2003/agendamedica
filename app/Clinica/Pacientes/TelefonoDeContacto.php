<?php

namespace App\Clinica\Pacientes;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **El teléfono al que se le escribe a un paciente que no tiene el suyo** (WEB-7.4.2c): el de quien responde por él.
 *
 * Un niño casi nunca tiene celular propio. Los recordatorios, los saludos de cumpleaños, la marca «sin teléfono» de la agenda y el dato
 * obligatorio de pediatría (`DatosObligatoriosDelPaciente`) tienen que resolver **el mismo** teléfono, así que la regla vive acá y nadie
 * la repite. Se usa **sólo como respaldo**: si el paciente tiene el suyo, ése manda (no se le quita a nadie un número que ya funcionaba).
 *
 * El orden, por defecto de la pregunta 12 de `preguntas-para-alexander.md` (se confirma con Alexander):
 *  1. el teléfono del **representante** cargado a mano (`representante.telefono`, WEB-3.4);
 *  2. el de la **familia** del escritorio de pediatría (`familia_bebe`): celular de la madre, del padre y el otro teléfono.
 *
 * Es lectura pura: no escribe nada, y el tenant es `reg_medico`.
 */
class TelefonoDeContacto
{
    /** El teléfono de contacto de una historia, o `null` si nadie tiene uno. */
    public function de(string $regMedico, ?string $numhistoria): ?string
    {
        $historia = CamposDePaciente::historiaNumerica($numhistoria);

        return $historia === null ? null : ($this->deLasHistorias($regMedico, [$historia])[$historia] ?? null);
    }

    /**
     * Varias historias de una vez (la agenda las resuelve por día, no una a una).
     *
     * @param  iterable<int|string>  $numhistorias
     * @return array<int,string> `numhistoria` entero => teléfono; sólo las que tienen alguno
     */
    public function deLasHistorias(string $regMedico, iterable $numhistorias): array
    {
        $historias = Collection::make($numhistorias)
            ->map(fn ($n) => CamposDePaciente::historiaNumerica((string) $n))
            ->filter(fn ($n) => $n !== null)
            ->unique()
            ->values();

        if ($historias->isEmpty()) {
            return [];
        }

        $telefonos = [];

        // 2.º (se escribe primero para que el 1.º lo pise): la familia del escritorio de pediatría.
        $chamos = DB::table('paciente_chamo')
            ->where('reg_medico', $regMedico)
            ->whereIn('historia', $historias->all())
            ->orderBy('id')
            ->get(['historia', 'familia']);

        if ($chamos->isNotEmpty()) {
            $familias = DB::table('familia_bebe')
                ->where('reg_medico', $regMedico)
                ->whereIn('familia', $chamos->pluck('familia')->unique()->all())
                ->orderBy('id')
                ->get(['familia', 'celu_madre', 'cel_padre', 'telefono'])
                ->unique('familia')
                ->keyBy('familia');

            foreach ($chamos as $chamo) {
                $fila = $familias[$chamo->familia] ?? null;
                $telefono = $fila === null ? null : $this->primero([$fila->celu_madre, $fila->cel_padre, $fila->telefono]);

                if ($telefono !== null) {
                    $telefonos[(int) $chamo->historia] = $telefono;
                }
            }
        }

        // 1.º: el representante cargado a mano.
        $representantes = DB::table('representante')
            ->where('reg_medico', $regMedico)
            ->whereIn('numhistoria', $historias->all())
            ->orderByDesc('id')
            ->get(['numhistoria', 'telefono']);

        foreach ($representantes as $representante) {
            $telefono = $this->primero([$representante->telefono]);

            if ($telefono !== null) {
                $telefonos[(int) $representante->numhistoria] = $telefono;
            }
        }

        return $telefonos;
    }

    /** El primer valor con algo escrito (los vacíos y el `none` heredado del legado no cuentan). */
    private function primero(array $valores): ?string
    {
        foreach ($valores as $valor) {
            $texto = CamposDePaciente::texto($valor);

            if ($texto !== null) {
                return $texto;
            }
        }

        return null;
    }
}
