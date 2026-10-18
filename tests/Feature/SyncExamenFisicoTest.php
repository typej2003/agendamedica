<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ExamenFisico;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **A2 de PLAN-MOVIL.md: que la evaluación física de la consulta baje al teléfono.**
 *
 * `examen_fisico_nuevo` existía en el API y el escritorio la subía, pero el volcado del app no la mandaba:
 * el médico podía bajar la consulta y no su evaluación física. Lo que se fija acá:
 *
 *  1. Que baje por **delta** (lo que cambió desde el `since`) con sus columnas clínicas.
 *  2. Que **no** baje la fila de otro `reg_medico`, aunque tenga el mismo número de historia (el número es
 *     por médico, trampa del Paso 18.B).
 *  3. Que baje en el **volcado completo** (sin `since`) y en el **paginado** (con ventana: sólo la clínica
 *     de los pacientes que se atienden en los últimos 60 días).
 *
 * ⚠️ La trampa de esta tabla está en los nombres de sus columnas y por eso los tests las nombran a mano:
 * el número de historia es **`historia`** (no `numhistoria`) y `consulta` es **el número de la consulta
 * dentro de la historia** (`consultas.nroconsulta`), no el id de `consultas`.
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncExamenFisicoTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-examen-' . uniqid();
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);
        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Doctora',
            'lastname' => 'De Prueba',
            'email' => $user->email,
            'password' => $user->password,
            'reg_medico' => $regMedico,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        if ($autenticar) {
            Sanctum::actingAs($user, ['*'], 'api');
        }

        return $medico;
    }

    /** Un paciente del médico con su historia y una consulta. El número es único: se elige uno libre. */
    private function consulta(Medico $medico): Consulta
    {
        $numhistoria = random_int(900000000, 999999999);

        $paciente = Paciente::create([
            'cedula' => (string) random_int(1000000, 99999999),
            'nombres' => 'Paciente',
            'apellidos' => 'De Prueba',
        ]);
        MedicoPaciente::create([
            'medico_id' => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => (string) $numhistoria,
        ]);
        Historia::create([
            'numhistoria' => (string) $numhistoria,
            'reg_medico' => $medico->reg_medico,
            'paciente_id' => $paciente->id,
            'medico_id' => $medico->id,
        ]);

        return Consulta::create([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'nroconsulta' => 1,
        ]);
    }

    /**
     * La fila del examen físico, con la clave del legado `(historia, consulta)`.
     *
     * ⚠️ `historia` es el número de historia y `consulta` el **número** de la consulta, no su id: es la
     * trampa del §7.4 de PLAN-MOVIL.md (buscar por id crea filas huérfanas).
     */
    private function examen(Medico $medico, Consulta $consulta, array $valores = []): ExamenFisico
    {
        return ExamenFisico::create(array_merge([
            'reg_medico' => $medico->reg_medico,
            'historia' => $consulta->numhistoria,
            'consulta' => $consulta->nroconsulta,
            'tension' => '120/80',
            'general' => 'BUEN ESTADO GENERAL',
            'cardiopulmonar' => 'RUIDOS RESPIRATORIOS NORMALES',
        ], $valores));
    }

    private function sync(array $cuerpo = [])
    {
        return $this->postJson('/api/app/sync-app-data', $cuerpo)->assertOk();
    }

    public function test_el_examen_fisico_baja_por_delta_con_sus_columnas(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        // El delta se calcula por `updated_at`, así que el `since` tiene que ser anterior a la fila.
        $desde = now()->subMinute()->toIso8601String();
        $examen = $this->examen($medico, $consulta);

        $r = $this->sync(['since' => $desde]);

        $this->assertCount(1, $r->json('examen_fisico_nuevo'), 'la evaluación física de la consulta viaja');
        $fila = $r->json('examen_fisico_nuevo.0');
        $this->assertSame($examen->id, $fila['id']);
        $this->assertSame('120/80', $fila['tension']);
        $this->assertSame('BUEN ESTADO GENERAL', $fila['general']);
        $this->assertSame('RUIDOS RESPIRATORIOS NORMALES', $fila['cardiopulmonar']);
        // La clave del legado, tal como la guarda el API: `historia` (no `numhistoria`) y el **número**
        // de la consulta (no el id de `consultas`).
        $this->assertSame($consulta->numhistoria, $fila['historia']);
        $this->assertSame($consulta->nroconsulta, $fila['consulta']);
        $this->assertNotSame($consulta->id, $fila['consulta']);
    }

    public function test_no_baja_el_examen_de_otro_medico_con_el_mismo_numero_de_historia(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $examen = $this->examen($medico, $consulta);

        // El número de historia es **por médico**: la fila del otro tiene el mismo `historia` y el mismo
        // `consulta`, y aun así no puede bajar a este teléfono.
        $ajeno = ExamenFisico::create([
            'reg_medico' => 'otro-registro-' . uniqid(),
            'historia' => $consulta->numhistoria,
            'consulta' => $consulta->nroconsulta,
            'tension' => '180/110',
            'general' => 'DEL OTRO MEDICO',
        ]);

        $r = $this->sync();

        $this->assertCount(1, $r->json('examen_fisico_nuevo'), 'sólo el examen propio');
        $this->assertSame($examen->id, $r->json('examen_fisico_nuevo.0.id'));
        $this->assertNotSame($ajeno->id, $r->json('examen_fisico_nuevo.0.id'));
    }

    public function test_el_volcado_completo_tambien_lo_trae(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $examen = $this->examen($medico, $consulta);

        // Sin `since` es la primera sincronización (o la descarga completa de un app viejo).
        $r = $this->sync();

        $this->assertCount(1, $r->json('examen_fisico_nuevo'));
        $this->assertSame($examen->id, $r->json('examen_fisico_nuevo.0.id'));
        $this->assertSame('120/80', $r->json('examen_fisico_nuevo.0.tension'));
    }

    /**
     * **El volcado paginado** aplica la ventana (`DIAS_DE_VENTANA`): lo clínico sólo viaja para los
     * pacientes que se atienden en esos días, y "se atiende" se mide por la cita (`cola.fecha`). Por eso
     * acá hace falta una cita reciente: con el examen solo, la historia no está en la ventana y la tabla
     * viene vacía (que es lo correcto, y es la razón de que este test monte la cita).
     */
    public function test_el_volcado_paginado_lo_incluye_cuando_le_toca_el_tramo(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $examen = $this->examen($medico, $consulta);
        DB::table('cola')->insert([
            'reg_medico' => $medico->reg_medico,
            'fecha' => now()->subDay()->toDateString(),
            'hora_ini' => '08:00',
            'numhistoria' => $consulta->numhistoria,
            'atendido' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vistos = [];
        $cursor = 'inicio';
        $paginas = 0;
        while ($cursor !== null && $paginas < 200) {
            $cuerpo = $this->sync(['volcado' => $cursor, 'volcado_limite' => 1])->json();

            // La clave tiene que venir siempre, aunque en este tramo no toque (el app recorre una lista
            // fija de tablas y no puede distinguir "vacía" de "no implementada").
            $this->assertArrayHasKey('examen_fisico_nuevo', $cuerpo);

            foreach ($cuerpo['examen_fisico_nuevo'] as $fila) {
                $vistos[] = $fila;
            }
            $cursor = $cuerpo['volcado_siguiente'];
            $paginas++;
        }

        $this->assertLessThan(200, $paginas, 'el volcado tiene que terminar');
        $this->assertCount(1, $vistos, 'la evaluación física entra en uno de los tramos');
        $this->assertSame($examen->id, $vistos[0]['id']);
        $this->assertSame('BUEN ESTADO GENERAL', $vistos[0]['general']);
    }
}
