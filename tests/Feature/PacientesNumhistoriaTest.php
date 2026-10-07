<?php

namespace Tests\Feature;

use App\Models\Paciente;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * HTTP 500 al crear pacientes en bases armadas con el dump del legado: `pacientes.numhistoria INT NOT NULL`
 * sin valor por defecto (visto el 2026-09-27 al subir pacientes nuevos). La migración
 * `2026_10_06_100000_pacientes_numhistoria_admite_null` la deja opcional.
 *
 * MySQL no está en el entorno local: lo que se prueba acá es que la migración es inofensiva donde no hay
 * nada que cambiar (SQLite, columna ausente) y la sentencia que arma. El ALTER real se comprueba en la base
 * desplegada (`SHOW CREATE TABLE pacientes`).
 */
class PacientesNumhistoriaTest extends TestCase
{
    use DatabaseTransactions;

    private function migracion(): object
    {
        return require base_path('database/migrations/2026_10_06_100000_pacientes_numhistoria_admite_null.php');
    }

    public function test_la_migracion_no_hace_nada_donde_no_hay_nada_que_cambiar(): void
    {
        $this->assertFalse(Schema::hasColumn('pacientes', 'numhistoria')); // el esquema del repo no la trae

        $this->migracion()->up();

        $this->assertFalse(Schema::hasColumn('pacientes', 'numhistoria'));
    }

    public function test_la_sentencia_conserva_el_tipo_de_la_columna_y_solo_admite_null(): void
    {
        $this->assertSame('ALTER TABLE pacientes MODIFY numhistoria int(11) NULL', $this->migracion()->sentencia('int(11)'));
        $this->assertSame('ALTER TABLE pacientes MODIFY numhistoria int NULL', $this->migracion()->sentencia('int'));
    }

    public function test_un_paciente_se_crea_sin_numero_de_historia(): void
    {
        $paciente = Paciente::create(['cedula' => 'nh-' . uniqid(), 'nombres' => 'SIN', 'apellidos' => 'HISTORIA']);

        $this->assertNotNull($paciente->id);
        $this->assertSame(1, DB::table('pacientes')->where('id', $paciente->id)->count());
    }
}
