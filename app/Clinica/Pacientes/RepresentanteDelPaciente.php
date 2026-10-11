<?php

namespace App\Clinica\Pacientes;

use Illuminate\Support\Facades\DB;

/**
 * El **representante del paciente** (WEB-3.4): el adulto que responde por él y **el que presta el
 * teléfono para el recordatorio** (PLAN-WEB.md §6, pediatría).
 *
 * **La tabla es del legado y se lee, no se reestructura.** `representante` tiene
 * `reg_medico`, `numhistoria`, `nombre`, `codeparentesco char(1)`, `direccion` y —desde WEB-3.4— las
 * columnas nullable `cedula`, `telefono` y `email`. El escritorio la sube por la lista blanca
 * (`config/sync_legado.php:49`); acá se lee del `reg_medico` del contexto, como todo lo demás.
 *
 * **Una fila por historia, no una lista.** El esquema real del legado declara
 * `PRIMARY KEY (numhistoria)` en `representante` (los siete `esquemas-asa/*.sql`), así que el vínculo es
 * el **número de historia** del paciente —el mismo que vive en `medico_pacientes` y en `historias`— y no
 * hay "varios representantes" que listar. La migración del API no recreó ese índice y acá no se asume:
 * si una instalación tuviera dos filas para la misma historia, se devuelve una sola —la del `id` más bajo,
 * que es la primera que se guardó— para que la pantalla no muestre una cosa y guarde otra.
 *
 * **Sin número de historia no hay representante**: no hay clave con la que guardarlo. Eso lo resuelve
 * `App\Actions\Pacientes\GuardarRepresentante`, que exige el número; la ficha, por eso, no ofrece el
 * formulario y explica el camino (WEB-3.3, asignar la historia).
 *
 * `representante.numhistoria` es **entero** en el esquema del API (así lo creó
 * `2026_09_27_110000_create_tablas_legado_faltantes`, línea 598), a diferencia de
 * `medico_pacientes.numhistoria`, que es texto: por eso acá se cruza con `historiaNumerica()` y no con el
 * texto del pivote.
 */
class RepresentanteDelPaciente
{
    /** Las columnas de la ficha: nombre, parentesco, documento y contacto. */
    private const COLUMNAS = ['id', 'nombre', 'codeparentesco', 'cedula', 'telefono', 'email', 'direccion'];

    /**
     * El representante de una historia, o `null` si todavía no se cargó (o si el paciente no tiene
     * historia, que es la misma respuesta para la pantalla: no hay nada que mostrar).
     */
    public function de(string $regMedico, ?string $numhistoria): ?object
    {
        $numero = CamposDePaciente::historiaNumerica($numhistoria);

        if ($numero === null) {
            return null;
        }

        return DB::table('representante')
            ->where('reg_medico', $regMedico)
            ->where('numhistoria', $numero)
            ->orderBy('id')
            ->first(self::COLUMNAS);
    }
}
