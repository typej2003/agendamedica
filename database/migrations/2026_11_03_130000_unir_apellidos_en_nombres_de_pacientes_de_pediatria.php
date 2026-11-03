<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **Los pacientes de pediatría quedan con un solo nombre** (2026-11-03).
 *
 * PediaReport guarda el nombre completo en una sola columna (`paciente_chamo.nombre`, que el sync trae a
 * `pacientes.nombres`) y **no tiene apellidos**: la web, en cambio, mostraba dos campos y exigía el
 * apellido, así que los médicos tenían que cortar el nombre a mano para poder guardar.
 *
 * Esta migración **junta lo que había en `apellidos` con `nombres`**, con un espacio en blanco en medio, y
 * deja `apellidos` en `null`, **sólo para los pacientes vinculados a un consultorio de pediatría**
 * (`medico_pacientes.reg_medico` de un `medicos` con la especialidad `pediatria`). Los pacientes de las
 * demás especialidades no se tocan.
 *
 * Es idempotente: una ficha que ya tiene `apellidos` vacío no se vuelve a tocar. El `nombres` se recorta al
 * ancho de la columna (200) por si un nombre excepcionalmente largo se pasara; en la práctica no ocurre.
 */
return new class extends Migration
{
    /** El ancho de `pacientes.nombres` después de la migración del 2026-10-14. */
    private const ANCHO_NOMBRES = 200;

    public function up(): void
    {
        if (! Schema::hasTable('pacientes') || ! Schema::hasTable('medico_pacientes')
            || ! Schema::hasTable('medicos') || ! Schema::hasTable('medico_specialty')
            || ! Schema::hasTable('specialties')) {
            return;
        }

        // Los consultorios cuya especialidad es pediatría: el `reg_medico` del médico, no el `id`.
        $registros = DB::table('medicos')
            ->join('medico_specialty', 'medico_specialty.medico_id', '=', 'medicos.id')
            ->join('specialties', 'specialties.id', '=', 'medico_specialty.specialty_id')
            ->where('specialties.slug', 'pediatria')
            ->whereNotNull('medicos.reg_medico')
            ->where('medicos.reg_medico', '<>', '')
            ->distinct()
            ->pluck('medicos.reg_medico');

        if ($registros->isEmpty()) {
            return;
        }

        $pacientes = DB::table('medico_pacientes')
            ->whereIn('reg_medico', $registros->all())
            ->distinct()
            ->pluck('paciente_id');

        foreach ($pacientes->chunk(500) as $ids) {
            $filas = DB::table('pacientes')
                ->whereIn('id', $ids->all())
                ->whereNotNull('apellidos')
                ->where('apellidos', '<>', '')
                ->get(['id', 'nombres', 'apellidos']);

            foreach ($filas as $fila) {
                $nombres = trim((string) $fila->nombres);
                $apellidos = trim((string) $fila->apellidos);

                // El espacio en blanco en medio es deliberado: el apellido no se pega al nombre.
                $completo = trim($nombres . ' ' . $apellidos);

                DB::table('pacientes')->where('id', $fila->id)->update([
                    'nombres' => mb_substr($completo, 0, self::ANCHO_NOMBRES),
                    'apellidos' => null,
                ]);
            }
        }
    }

    /**
     * No se puede deshacer sin saber dónde terminaba el nombre y dónde empezaba el apellido. Se deja
     * documentado: revertir esta migración no vuelve a separar lo que se unió.
     */
    public function down(): void
    {
        // Sin vuelta atrás: el nombre completo no se puede partir con certeza.
    }
};
