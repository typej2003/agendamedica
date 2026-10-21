<?php

namespace Tests\Feature;

use App\Http\Requests\SyncAppDataRequest;
use App\Models\Antecedente;
use App\Models\AntecedenteMovimiento;
use App\Models\AntecePaciente;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\TipoAntecedente;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **El registro de movimientos de los antecedentes desde los dos sync** (`antecedente_movimientos`, decisión
 * del usuario 2026-10-20): el móvil (`/api/app/sync-app-data`: alta, edición y baja) y la subida del
 * escritorio (`/api/sync/cambios/subir`: `I`, `U` y `D`, por Query Builder). La carga inicial **no** anota y
 * la tabla nueva **no** viaja al teléfono.
 */
class AntecedenteMovimientosSyncTest extends TestCase
{
    use DatabaseTransactions;

    private const API_KEY = 'MiClaveSecreta123!';

    // ------------------------------------------------------------------------------------------- Móvil

    /** @return array{0:Medico, 1:User} */
    private function medicoMovil(): array
    {
        $regMedico = 'movmov-' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Doctora movil',
            'email' => 'movmov-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doctora', 'lastname' => 'Movil', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);
        Sanctum::actingAs($user, ['*'], 'api');

        TipoAntecedente::create(['reg_medico' => $regMedico, 'codetipo' => '01', 'descripcion' => 'PERSONAL', 'tipoantecedente' => 'P']);
        Antecedente::create(['reg_medico' => $regMedico, 'codantecedente' => '0001', 'descripcion' => 'CANCER', 'codtipo' => '01']);

        return [$medico, $user];
    }

    private function historia(Medico $medico): Historia
    {
        $numhistoria = random_int(900000000, 999999999);
        $paciente = Paciente::create(['cedula' => (string) random_int(1000000, 99999999), 'nombres' => 'Paciente', 'apellidos' => 'Movil']);
        MedicoPaciente::create([
            'medico_id' => $medico->id, 'paciente_id' => $paciente->id, 'reg_medico' => $medico->reg_medico,
            'numhistoria' => (string) $numhistoria,
        ]);

        return Historia::create([
            'numhistoria' => (string) $numhistoria, 'reg_medico' => $medico->reg_medico,
            'paciente_id' => $paciente->id, 'medico_id' => $medico->id,
        ]);
    }

    private function enviar(array $changes)
    {
        return $this->postJson('/api/app/sync-app-data', ['changes' => $changes])->assertOk();
    }

    public function test_el_movil_anota_el_alta_la_edicion_y_la_baja_sin_consulta_y_con_su_usuario(): void
    {
        [$medico, $user] = $this->medicoMovil();
        $historia = $this->historia($medico);

        $this->enviar([[
            'table' => 'antece_paciente', 'operation' => 'created', 'temp_id' => -70,
            'numhistoria' => (int) $historia->numhistoria, 'codeantecedente' => '0001',
            'columns' => ['detalles' => 'MADRE'],
        ]]);
        $fila = AntecePaciente::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $this->enviar([[
            'table' => 'antece_paciente', 'operation' => 'updated', 'record_id' => $fila->id,
            'column' => 'detalles', 'value' => 'MADRE Y ABUELA', 'occurred_at' => now()->addMinute()->toIso8601String(),
        ]]);
        // La misma edición otra vez no es un movimiento.
        $this->enviar([[
            'table' => 'antece_paciente', 'operation' => 'updated', 'record_id' => $fila->id,
            'column' => 'detalles', 'value' => 'MADRE Y ABUELA', 'occurred_at' => now()->addMinutes(2)->toIso8601String(),
        ]]);
        $this->enviar([[
            'table' => 'antece_paciente', 'operation' => 'deleted', 'record_id' => $fila->id,
            'occurred_at' => now()->addMinutes(3)->toIso8601String(),
        ]]);

        $movimientos = AntecedenteMovimiento::where('reg_medico', $medico->reg_medico)->orderBy('id')->get();

        $this->assertSame(['agregado', 'editado', 'quitado'], $movimientos->pluck('operacion')->all());
        $this->assertSame(['MADRE', 'MADRE Y ABUELA', 'MADRE Y ABUELA'], $movimientos->pluck('detalles')->all());
        foreach ($movimientos as $movimiento) {
            $this->assertSame('movil', $movimiento->origen);
            $this->assertNull($movimiento->consulta_id, 'el móvil no dice desde qué consulta');
            $this->assertSame($user->id, $movimiento->user_id);
            $this->assertSame('0001', $movimiento->codeantecedente);
            $this->assertSame('CANCER', $movimiento->descripcion);
            $this->assertSame((int) $historia->numhistoria, $movimiento->numhistoria);
        }
    }

    public function test_la_tabla_de_movimientos_no_viaja_al_telefono(): void
    {
        [$medico] = $this->medicoMovil();
        $historia = $this->historia($medico);
        AntecedenteMovimiento::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => (int) $historia->numhistoria,
            'codeantecedente' => '0001', 'operacion' => 'agregado', 'origen' => 'web', 'ocurrido_en' => now(),
        ]);

        $cuerpo = $this->postJson('/api/app/sync-app-data', [])->assertOk()->json();

        $this->assertArrayNotHasKey('antecedente_movimientos', $cuerpo);
        $this->assertStringNotContainsString('antecedente_movimientos', json_encode($cuerpo));
        $this->assertNotContains('antecedente_movimientos', SyncAppDataRequest::TABLAS_EDITABLES);
        $this->assertNotContains('antecedente_movimientos', SyncAppDataRequest::TABLAS_BORRABLES);
        $this->assertNotContains('antecedente_movimientos', (array) config('sync_legado.tablas'));
    }

    // -------------------------------------------------------------------------------------- Escritorio

    private function cargaDelEscritorio(string $reg): void
    {
        $user = User::create(['name' => 'Médico escritorio', 'email' => 'movesc-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('x')]);
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Médico', 'lastname' => 'Escritorio', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        $carga = $this->escritorio('carga-inicial/iniciar', ['reg_medico' => $reg, 'tablas' => [
            'pacientes' => 1, 'antece_paciente' => 1,
        ]])->assertOk()->json('carga_id');
        $lote = fn ($tabla, $filas) => $this->escritorio('carga-inicial/lote', ['carga_id' => $carga, 'tabla' => $tabla, 'desde' => 0, 'filas' => $filas])->assertOk();
        $lote('pacientes', [['numhistoria' => 1, 'cedula' => 'MOVESC-' . substr(uniqid(), -6), 'apellidos' => 'PRUEBA', 'nombres' => 'ANA', 'nac' => 'V', 'sexo' => 'F']]);
        $lote('antece_paciente', [['numhistoria' => 1, 'codeantecedente' => 'A1', 'descripcion' => 'ASMA', 'detalles' => 'original']]);
        $this->escritorio('carga-inicial/finalizar', ['carga_id' => $carga])->assertOk();
    }

    private function escritorio(string $ruta, array $cuerpo)
    {
        return $this->postJson("/api/sync/{$ruta}", $cuerpo, ['X-API-KEY' => self::API_KEY]);
    }

    public function test_la_carga_inicial_no_anota_movimientos(): void
    {
        $reg = 'movesc-' . substr(uniqid(), -6);
        $this->cargaDelEscritorio($reg);

        $this->assertSame(1, DB::table('antece_paciente')->where('reg_medico', $reg)->count());
        $this->assertSame(0, AntecedenteMovimiento::where('reg_medico', $reg)->count(), 'las filas de la carga quedan «sin fecha»');
    }

    public function test_la_subida_del_escritorio_anota_el_alta_la_edicion_y_la_baja_con_la_fecha_del_cambio(): void
    {
        $reg = 'movesc-' . substr(uniqid(), -6);
        $this->cargaDelEscritorio($reg);
        $subir = fn (array $cambios) => $this->escritorio('cambios/subir', ['reg_medico' => $reg, 'cambios' => $cambios])->assertOk();

        $subir([
            ['id' => 1, 'tabla' => 'antece_paciente', 'op' => 'U', 'fecha' => '2026-10-02 10:00:00',
             'clave' => ['numhistoria' => '1', 'codeantecedente' => 'A1'], 'fila' => ['numhistoria' => 1, 'codeantecedente' => 'A1', 'descripcion' => 'ASMA', 'detalles' => 'editado', 'tipo2' => 'A']],
            ['id' => 2, 'tabla' => 'antece_paciente', 'op' => 'I', 'fecha' => '2026-10-02 10:05:00',
             'clave' => ['numhistoria' => '1', 'codeantecedente' => 'B1'], 'fila' => ['numhistoria' => 1, 'codeantecedente' => 'B1', 'descripcion' => 'ALERGIA', 'detalles' => 'nuevo']],
        ]);
        // La misma fila vuelve a subir sin cambios: no es un movimiento.
        $subir([['id' => 3, 'tabla' => 'antece_paciente', 'op' => 'U', 'fecha' => '2026-10-02 10:10:00',
            'clave' => ['numhistoria' => '1', 'codeantecedente' => 'A1'], 'fila' => ['numhistoria' => 1, 'codeantecedente' => 'A1', 'descripcion' => 'ASMA', 'detalles' => 'editado', 'tipo2' => 'A']]]);
        $subir([['id' => 4, 'tabla' => 'antece_paciente', 'op' => 'D', 'fecha' => '2026-10-02 11:00:00',
            'clave' => ['numhistoria' => '1', 'codeantecedente' => 'A1']]]);

        $movimientos = AntecedenteMovimiento::where('reg_medico', $reg)->orderBy('id')->get();

        $this->assertSame(
            [['editado', 'A1', 'editado', true], ['agregado', 'B1', 'nuevo', false], ['quitado', 'A1', 'editado', true]],
            $movimientos->map(fn ($m) => [$m->operacion, $m->codeantecedente, $m->detalles, $m->alerta])->all(),
        );
        $this->assertSame(
            ['2026-10-02 10:00:00', '2026-10-02 10:05:00', '2026-10-02 11:00:00'],
            $movimientos->map(fn ($m) => $m->ocurrido_en->format('Y-m-d H:i:s'))->all(),
        );
        foreach ($movimientos as $movimiento) {
            $this->assertSame('escritorio', $movimiento->origen);
            $this->assertNull($movimiento->consulta_id);
            $this->assertNull($movimiento->user_id);
            $this->assertSame(1, $movimiento->numhistoria);
        }
        $this->assertSame('ASMA', $movimientos[2]->descripcion, 'la baja guarda lo que tenía la fila antes del DELETE');
    }
}
