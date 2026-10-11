<?php

namespace App\Actions\Pacientes;

use App\Clinica\Pacientes\CamposDePaciente;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar el representante del paciente** (WEB-3.4): el adulto que responde por él, con su parentesco y
 * su contacto. Se usa desde la ficha del paciente; el escritorio sigue subiendo la tabla por su cuenta
 * (`App\Sync\Escritorio\CambiosEscritorio`), así que esta Action **no es el único camino** que escribe
 * `representante`, pero **sí es el único de la web**.
 *
 * Tres cosas que son la regla:
 *
 *  - **La clave es el `numhistoria`.** El esquema real del legado declara `PRIMARY KEY (numhistoria)` en
 *    `representante` (los siete `esquemas-asa/*.sql`), así que **hay un representante por historia** y no
 *    una lista: guardar es un `updateOrCreate` por historia, no un `insert` (que dejaría dos filas y la
 *    pantalla mostraría una de las dos). Por eso la Action **exige el número**: un paciente sin historia
 *    no tiene dónde guardarlo, y lo dice con `InvalidArgumentException` —la ficha, por eso, no le ofrece
 *    el formulario y le muestra el camino de WEB-3.3—.
 *  - **La tabla del legado no se reestructura.** Sólo se escriben columnas que existen: `nombre`,
 *    `codeparentesco`, `direccion` (las del legado) más `cedula`, `telefono` y `email` (las nullable
 *    nuevas). Nada de `id` ni de fechas a mano: las lleva el query builder.
 *  - **El `codeparentesco` se guarda tal cual y no se traduce.** El catálogo de parentescos es **de
 *    AppDDR** y los códigos que haya usado el legado son **desconocidos** (ver `ParentescoSeeder`), así
 *    que la web no puede decidir que un código heredado significa "Madre": lo escribe como vino. Un
 *    `codeparentesco` **vacío** se guarda `null` ("no se dijo el parentesco" es un estado válido) y un
 *    código que no esté en el catálogo se conserva, porque **borrarlo sería perder un dato del escritorio
 *    por abrir un formulario**. Lo que el catálogo sí decide es el **nombre** que se muestra; el código
 *    crudo se muestra cuando no está (misma decisión que el `codesegemp` que no está en `seg_emp`).
 */
final class GuardarRepresentante
{
    /**
     * @param  array<string,mixed>  $datos  `nombre`, `codeparentesco`, `cedula`, `telefono`, `email`, `direccion`
     *
     * @throws InvalidArgumentException si el paciente no tiene número de historia
     */
    public function ejecutar(string $regMedico, ?string $numhistoria, array $datos): object
    {
        $numero = CamposDePaciente::historiaNumerica($numhistoria);

        if ($numero === null) {
            throw new InvalidArgumentException(
                'Este paciente todavía no tiene número de historia y el representante se guarda con ese número.'
            );
        }

        $columnas = [
            'nombre'         => CamposDePaciente::texto($datos['nombre'] ?? null),
            'codeparentesco' => CamposDePaciente::texto($datos['codeparentesco'] ?? null),
            'cedula'         => CamposDePaciente::texto($datos['cedula'] ?? null),
            'telefono'       => CamposDePaciente::texto($datos['telefono'] ?? null),
            'email'          => CamposDePaciente::texto($datos['email'] ?? null),
            'direccion'      => CamposDePaciente::texto($datos['direccion'] ?? null),
            'updated_at'     => now(),
        ];

        $existente = DB::table('representante')
            ->where('reg_medico', $regMedico)
            ->where('numhistoria', $numero)
            ->orderBy('id')
            ->first(['id']);

        if ($existente !== null) {
            DB::table('representante')->where('id', $existente->id)->update($columnas);

            return (object) array_merge(['id' => (int) $existente->id, 'numhistoria' => $numero], $columnas);
        }

        $columnas['reg_medico'] = $regMedico;
        $columnas['numhistoria'] = $numero;
        $columnas['created_at'] = now();

        $id = DB::table('representante')->insertGetId($columnas);

        return (object) array_merge(['id' => (int) $id], $columnas);
    }
}
