<?php

namespace App\Clinica\Pacientes;

use App\Models\Historia;
use App\Models\MedicoPaciente;

/**
 * **De quién es un número de historia en un registro del médico.**
 *
 * El número de historia es único **por registro** (`historias` tiene la clave única
 * `(reg_medico, numhistoria)`), pero el vínculo `medico_pacientes` guarda el número como **texto** y
 * **no** tiene esa unicidad: dos pacientes pueden terminar con el mismo número en el mismo registro
 * (el importador de SQL del `/admin`, un script, o el panel viejo que escribía sólo el vínculo). Cuando
 * eso pasa, «quién es el paciente de la historia 21» tiene **una sola respuesta correcta**: el que la
 * registró en `historias`, que es la tabla dueña del número (y la que usan `AsignarHistoria` y el sync
 * para decidir si un número está libre).
 *
 * Existe porque las pantallas resolvían lo mismo de dos maneras y **nombraban a dos pacientes distintos
 * en el mismo flujo** (informe del usuario, 2026-10-19): la agenda leía el vínculo y, con dos filas para
 * el mismo número, se quedaba con la última; «Atender» y el encabezado de la consulta se quedaban con la
 * primera. El médico veía «DIAZ, ALEXANDER · H. 21» en la agenda y «KARLA RONDON · H. 21» al atenderlo.
 *
 * La regla, en este orden y **nunca saliendo del registro** que se pide:
 *
 *  1. la fila de `historias` de ese número con su `paciente_id` (el dueño);
 *  2. si el número **sólo vive en el vínculo** —la trampa 143: una pantalla o un script que escribió el
 *     pivote sin la fila— ese paciente es el dueño, porque es el número que la ficha y las citas ya
 *     muestran.
 *
 * El registro es obligatorio: un número de otro registro del mismo médico es **otra historia** y otro
 * paciente, aunque el número coincida.
 */
final class DuenoDeLaHistoria
{
    /**
     * `pacientes.id` dueño del número en ese registro, o `null` si no hay ninguno (número vacío, basura
     * del legado como `S/H`, o un número que no está ni en `historias` ni en el vínculo).
     */
    public function de(?string $regMedico, $numhistoria): ?int
    {
        $numero = CamposDePaciente::historiaNumerica($numhistoria);

        if ($regMedico === null || $regMedico === '' || $numero === null) {
            return null;
        }

        $formas = $this->formas($numhistoria, $numero);

        $duenio = Historia::where('reg_medico', $regMedico)
            ->whereIn('numhistoria', $formas)
            ->whereNotNull('paciente_id')
            ->orderBy('id')
            ->value('paciente_id');

        if ($duenio !== null) {
            return (int) $duenio;
        }

        $enElVinculo = MedicoPaciente::where('reg_medico', $regMedico)
            ->whereIn('numhistoria', $formas)
            ->orderBy('id')
            ->value('paciente_id');

        return $enElVinculo !== null ? (int) $enElVinculo : null;
    }

    /**
     * La misma regla, en lote: `[numero => pacientes.id]` para los números que tengan dueño.
     *
     * La usa la agenda, que resuelve el nombre de cada cita de una jornada en **una** consulta (no una
     * por fila). El número que devuelve como clave es el **entero**, que es como lo comparan las citas.
     *
     * @param  array<int,int|string>  $numeros
     * @return array<int,int>
     */
    public function paraNumeros(?string $regMedico, array $numeros): array
    {
        if ($regMedico === null || $regMedico === '') {
            return [];
        }

        // `formas` por número: el texto del legado y su forma numérica pueden convivir (`01001` / `1001`).
        $pedidos = [];
        foreach ($numeros as $numero) {
            $limpio = CamposDePaciente::historiaNumerica($numero);

            if ($limpio !== null) {
                $pedidos[$limpio] = $this->formas($numero, $limpio);
            }
        }

        if ($pedidos === []) {
            return [];
        }

        $formas = array_values(array_unique(array_merge(...array_values($pedidos))));

        $salida = [];

        // 1) El dueño registrado.
        $duenios = Historia::where('reg_medico', $regMedico)
            ->whereIn('numhistoria', $formas)
            ->whereNotNull('paciente_id')
            ->orderBy('id')
            ->get(['numhistoria', 'paciente_id']);

        foreach ($duenios as $fila) {
            $numero = (int) $fila->numhistoria;
            $salida[$numero] ??= (int) $fila->paciente_id;
        }

        // 2) El número que sólo vive en el vínculo (trampa 143), para los que quedaron sin dueño.
        $faltan = array_values(array_diff(array_keys($pedidos), array_keys($salida)));

        if ($faltan !== []) {
            $formasQueFaltan = array_values(array_unique(array_merge(
                ...array_map(fn (int $numero) => $pedidos[$numero], $faltan)
            )));

            $vinculos = MedicoPaciente::where('reg_medico', $regMedico)
                ->whereIn('numhistoria', $formasQueFaltan)
                ->orderBy('id')
                ->get(['numhistoria', 'paciente_id']);

            foreach ($vinculos as $fila) {
                $numero = (int) $fila->numhistoria;

                if (in_array($numero, $faltan, true) && $fila->paciente_id !== null) {
                    $salida[$numero] ??= (int) $fila->paciente_id;
                }
            }
        }

        return $salida;
    }

    /**
     * Las dos formas en que el legado escribe el mismo número: el texto del vínculo tal cual (puede traer
     * ceros a la izquierda: `01001`) y su forma numérica (`1001`). Sin las dos, la misma historia se
     * buscaría con una sola y la otra no la encontraría (trampa 143).
     *
     * @return array<int,string>
     */
    private function formas($numhistoria, int $numero): array
    {
        return array_values(array_unique(array_filter([
            (string) CamposDePaciente::numeroDeHistoria($numhistoria),
            (string) $numero,
        ], fn ($forma) => $forma !== '')));
    }
}
