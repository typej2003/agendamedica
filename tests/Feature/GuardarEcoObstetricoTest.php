<?php

namespace Tests\Feature;

use App\Actions\Consulta\GuardarEcoObstetrico;
use App\Clinica\Consulta\EcoObstetrico;
use App\Models\Consulta;
use App\Models\EcoObstetrico as EcoObstetricoModelo;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\PreNatalObservacion;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * **Guardar el eco obstétrico** (WEB-6.2): la Action que escribe `eco_obstetrico`.
 *
 * Lo que fijan estos casos: **una fila por consulta** con el rastro por columna y `source = web`; que la
 * corrección escriba **sólo lo que cambió**; que **un eco sin datos no se guarde** (la fecha sola no lo hace
 * documento); y lo propio de este estudio: la **FUR y la gesta** se completan del **control prenatal** de la
 * historia cuando el formulario no las trae.
 */
class GuardarEcoObstetricoTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::parse('2026-10-13 09:00:00');
        Carbon::setTestNow($this->hoy->copy());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function medico(): Medico
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'eco-obs-w-' . uniqid();

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'eco-obs-w-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Obstétrico',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    private function consulta(Medico $medico, int $historia = 1001): Consulta
    {
        return Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => $historia,
            'nroconsulta' => 1, 'fecha' => $this->hoy->toDateString(),
        ]);
    }

    /** @return array<string,string> */
    private function formulario(array $valores = []): array
    {
        $campos = [];
        foreach (EcoObstetrico::columnas() as $columna) {
            $campos[$columna] = '';
        }

        return array_merge($campos, $valores);
    }

    public function test_crea_el_estudio_con_la_fur_y_la_gesta_del_control_prenatal(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        // La gesta que el médico está siguiendo, con su FUR (es la que el eco tiene que heredar).
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 2,
            'fur' => '2026-05-20',
        ]);

        $resultado = app(GuardarEcoObstetrico::class)->ejecutar($consulta, $this->formulario([
            'lcc' => '45', 'sa_vit' => 'Sí', 't_nucal' => '1.8', 'actividad_cardiaca' => 'S',
            'saco_gestacional' => '1', 'observacion' => 'Embarazo temprano normal.',
        ]));

        $this->assertTrue($resultado->creado);

        $fila = EcoObstetricoModelo::where('reg_medico', $medico->reg_medico)->where('consulta', 1)->first();

        $this->assertNotNull($fila);
        $this->assertSame(1001, $fila->historia);
        $this->assertSame('45', (string) $fila->lcc);
        $this->assertSame('1', (string) $fila->saco_gestacional);
        $this->assertSame('Embarazo temprano normal.', $fila->observacion);
        // Lo que el escritorio completa solo: la fecha de hoy, la FUR y la gesta del prenatal.
        $this->assertSame($this->hoy->toDateString(), (string) $fila->fecha);
        $this->assertSame('2026-05-20', (string) $fila->fur);
        $this->assertSame(2, $fila->gesta);

        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'eco_obstetrico', 'record_id' => $fila->id, 'operation' => 'created', 'source' => 'web',
        ]);
    }

    public function test_corrige_solo_lo_que_cambio(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $fila = EcoObstetricoModelo::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'fecha' => '2026-08-14', 'lcc' => '45', 'dbp' => '20',
        ]);

        Carbon::setTestNow($this->hoy->copy()->addMinute());

        $resultado = app(GuardarEcoObstetrico::class)->ejecutar($consulta, $this->formulario([
            'fecha' => '2026-08-14', 'lcc' => '46', 'dbp' => '20',
        ]));

        $this->assertSame(['lcc'], $resultado->columnas);
        $this->assertSame('46', (string) $fila->fresh()->lcc);
        $this->assertSame(1, SyncChange::where('table_name', 'eco_obstetrico')->where('column_name', 'lcc')->count());
        $this->assertSame(0, SyncChange::where('table_name', 'eco_obstetrico')->where('column_name', 'dbp')->count());
    }

    public function test_un_eco_sin_datos_no_se_guarda(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        // Con la FUR del prenatal pero sin ningún dato del estudio: no es un documento.
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => '2026-05-20',
        ]);

        $this->expectException(InvalidArgumentException::class);

        try {
            app(GuardarEcoObstetrico::class)->ejecutar($consulta, $this->formulario(['fur' => '2026-05-20']));
        } finally {
            $this->assertSame(0, EcoObstetricoModelo::where('reg_medico', $medico->reg_medico)->count());
        }
    }

    public function test_el_estudio_es_de_una_sola_consulta(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $otra = Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 2,
            'fecha' => $this->hoy->toDateString(),
        ]);

        $accion = app(GuardarEcoObstetrico::class);
        $accion->ejecutar($consulta, $this->formulario(['lcc' => '45']));
        $accion->ejecutar($otra, $this->formulario(['lcc' => '70']));

        // Dos consultas de la misma historia: dos filas (la clave incluye la consulta).
        $this->assertSame(2, EcoObstetricoModelo::where('historia', 1001)->count());
        $this->assertSame('45', (new EcoObstetrico())->de($consulta)->valor('lcc'));
        $this->assertSame('70', (new EcoObstetrico())->de($otra)->valor('lcc'));
    }
}
