<?php

namespace App\Actions\Pacientes;

use App\Clinica\Pacientes\CamposDePaciente;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar la familia de un niño** (WEB-7.4.2b): los padres, los teléfonos y el representante que el escritorio de pediatría guarda en
 * `familia_bebe` y que la web muestra en la ficha (WEB-7.4.2a). Se usa desde la ficha; el escritorio sigue subiendo las tablas por su
 * cuenta (`App\Sync\Escritorio\CambiosEscritorio`), así que esta Action no es el único camino que las escribe, pero **sí el único de la web**
 * (y el que reutilizará el móvil, R3).
 *
 * Las reglas:
 *
 *  - **La clave es la historia del niño** (`paciente_chamo.historia`), que lleva el número de la **familia** (`paciente_chamo.familia`,
 *    obligatorio en el legado). La familia se guarda en `familia_bebe` por ese número y por `reg_medico`.
 *  - **Si el niño todavía no es un `paciente_chamo`** (se dio de alta en la web), se crea su fila con una **familia nueva** (el mayor
 *    número de familia del consultorio + 1) y los datos que ya tiene la ficha. Si ya lo es, su número de familia **no se cambia**.
 *  - **Sólo columnas que existen.** Las tablas son del legado y no se reestructuran. Un campo vacío se guarda `null` y borra lo que había
 *    **si el formulario lo mandó**; un campo que no viaja no se toca.
 *  - **`situacion_pareja`** (código de un carácter sin significado confirmado) **no se escribe** hasta que Alexander lo defina.
 *
 * El escritorio no verá nunca estos cambios (no tiene bajada): se anota en `PENDIENTES-POWERBUILDER.md`.
 */
final class GuardarFamilia
{
    /** Las columnas de `familia_bebe` que el formulario de la ficha puede escribir. */
    public const CAMPOS = [
        'nom_madre', 'fecn_madre', 'ocupa_madre', 'celu_madre',
        'nom_padre', 'fecn_padre', 'ocupa_padre', 'cel_padre',
        'telefono', 'ced_representante', 'email', 'direccion', 'localidad',
        'dinamica_familiar', 'familia_texto',
    ];

    /**
     * @param  array<string,mixed>  $datos  las claves de `CAMPOS` que viajaron
     * @param  object|null  $nino  lo que ya sabe la ficha del niño (`nombres`, `apellidos`, `sexo`, `fnacimiento`), para crear su
     *                             fila de `paciente_chamo` si todavía no la tiene
     * @return object  `familia` (el número) y `historia`
     *
     * @throws InvalidArgumentException si el niño no tiene número de historia
     */
    public function ejecutar(string $regMedico, ?string $numhistoria, array $datos, ?object $nino = null): object
    {
        $historia = CamposDePaciente::historiaNumerica($numhistoria);

        if ($historia === null) {
            throw new InvalidArgumentException(
                'Este paciente todavía no tiene número de historia y la familia se guarda con ese número.'
            );
        }

        return DB::transaction(function () use ($regMedico, $historia, $datos, $nino) {
            $chamo = DB::table('paciente_chamo')
                ->where('reg_medico', $regMedico)
                ->where('historia', $historia)
                ->orderBy('id')
                ->first(['id', 'familia']);

            $numeroDeFamilia = $chamo === null
                ? $this->crearAlNino($regMedico, $historia, $nino)
                : (int) $chamo->familia;

            $columnas = [];
            foreach (self::CAMPOS as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $columnas[$campo] = CamposDePaciente::texto($datos[$campo]);
                }
            }
            $columnas['updated_at'] = now();

            $existente = DB::table('familia_bebe')
                ->where('reg_medico', $regMedico)
                ->where('familia', $numeroDeFamilia)
                ->orderBy('id')
                ->first(['id']);

            if ($existente !== null) {
                DB::table('familia_bebe')->where('id', $existente->id)->update($columnas);
            } else {
                DB::table('familia_bebe')->insert($columnas + [
                    'reg_medico' => $regMedico,
                    'familia' => $numeroDeFamilia,
                    'fecha_ingreso' => now()->toDateString(),
                    'created_at' => now(),
                ]);
            }

            return (object) ['familia' => $numeroDeFamilia, 'historia' => $historia];
        });
    }

    /** Crea la fila de `paciente_chamo` del niño con una familia nueva y devuelve el número de esa familia. */
    private function crearAlNino(string $regMedico, int $historia, ?object $nino): int
    {
        $familia = max(
            (int) DB::table('paciente_chamo')->where('reg_medico', $regMedico)->max('familia'),
            (int) DB::table('familia_bebe')->where('reg_medico', $regMedico)->max('familia'),
        ) + 1;

        $nombre = CamposDePaciente::nombre($nino->apellidos ?? null, $nino->nombres ?? null);

        DB::table('paciente_chamo')->insert([
            'reg_medico' => $regMedico,
            'familia' => $familia,
            'historia' => $historia,
            'nombre' => $nombre === 'Paciente sin nombre' ? null : mb_substr($nombre, 0, 200),
            'sexo' => CamposDePaciente::texto($nino->sexo ?? null),
            'f_nacimiento' => CamposDePaciente::texto($nino->fnacimiento ?? null),
            'f_ingreso' => now()->toDateString(),
            'pediatrico' => 'S',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $familia;
    }
}
