<?php

namespace Tests\Feature;

use App\Actions\Embarazo\GuardarGestaDelEmbarazo;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\PreNatalObservacion;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * **Guardar una gesta del control prenatal** (WEB-6.6, la pestaña *Prenatal*): la Action que escribe
 * `pre_natal_observaciones`.
 *
 * Lo que fijan estos casos: el número de la gesta lo pone el servidor (`última + 1`), la corrección escribe
 * **sólo lo que cambió** con el rastro por columna y `source = web`, una gesta **sin FUR no se guarda**, y
 * las siete columnas que esta pestaña no edita quedan como estaban.
 */
class GuardarGestaDelEmbarazoTest extends TestCase
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
        $regMedico = 'gesta-write-' . uniqid();

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'gesta-w-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Gesta',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    /** @return array<string,string> lo que manda el formulario de la pestaña */
    private function formulario(array $valores = []): array
    {
        $campos = [];
        foreach (GuardarGestaDelEmbarazo::COLUMNAS as $columna) {
            $campos[$columna] = $valores[$columna] ?? '';
        }

        return $campos;
    }

    public function test_crea_la_gesta_con_el_numero_que_le_toca_y_su_rastro(): void
    {
        $medico = $this->medico();
        // Una gesta que ya estaba (la primera, sin número: es la 1).
        DB::table('pre_natal_observaciones')->insert([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => null,
            'fur' => '2024-01-10', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $resultado = app(GuardarGestaDelEmbarazo::class)->ejecutar($medico->reg_medico, 1001, $this->formulario([
            'fur' => '2026-05-20',
            'menarquia' => '12 años',
            'gestas' => '2',
            'parto' => '1 cesárea',
            'tipiaje' => 'O+',
            'tipiaje_cony' => 'A+',
            'observaciones' => 'Embarazo en curso.',
        ]));

        $this->assertTrue($resultado->creado);

        $nueva = PreNatalObservacion::where('reg_medico', $medico->reg_medico)->where('historia', 1001)
            ->where('gesta_clave', 2)->first();

        $this->assertNotNull($nueva);
        $this->assertSame('2026-05-20', $nueva->fur);
        $this->assertSame('12 años', $nueva->menarquia);
        $this->assertSame(2, $nueva->gestas);
        $this->assertSame('O+', $nueva->tipiaje);
        $this->assertSame('A+', $nueva->tipiaje_cony);

        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'pre_natal_observaciones', 'record_id' => $nueva->id,
            'operation' => 'created', 'source' => 'web',
        ]);
    }

    public function test_corrige_solo_lo_que_cambio(): void
    {
        $medico = $this->medico();
        $fila = PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1002, 'gesta_clave' => 1,
            'fur' => '2026-05-20', 'menarquia' => '12 años', 'gestas' => 1,
        ]);
        $antes = $fila->updated_at;

        Carbon::setTestNow($this->hoy->copy()->addMinute());

        // El formulario manda todo: lo único distinto es la menarquia.
        $resultado = app(GuardarGestaDelEmbarazo::class)->ejecutar($medico->reg_medico, 1002, $this->formulario([
            'fur' => '2026-05-20', 'menarquia' => '13 años', 'gestas' => '1',
        ]), clave: 1);

        $this->assertSame(['menarquia'], $resultado->columnas);
        $this->assertSame('13 años', $fila->fresh()->menarquia);
        $this->assertSame(1, SyncChange::where('table_name', 'pre_natal_observaciones')->where('column_name', 'menarquia')->count());
        $this->assertSame(0, SyncChange::where('table_name', 'pre_natal_observaciones')->where('column_name', 'fur')->count());
        $this->assertTrue($fila->fresh()->updated_at->greaterThan($antes));
    }

    public function test_una_gesta_sin_fur_no_se_guarda(): void
    {
        $medico = $this->medico();

        $this->expectException(InvalidArgumentException::class);

        try {
            app(GuardarGestaDelEmbarazo::class)->ejecutar($medico->reg_medico, 1003, $this->formulario([
                'menarquia' => '12 años',
            ]));
        } finally {
            $this->assertSame(0, PreNatalObservacion::where('reg_medico', $medico->reg_medico)->count());
        }
    }

    public function test_no_toca_las_columnas_que_esta_pestana_no_edita(): void
    {
        $medico = $this->medico();
        $fila = PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1004, 'gesta_clave' => 1,
            'fur' => '2026-05-20', 'partos' => 2, 'cesarea' => 1, 'abortos' => 0,
            'otros' => 'gemelos', 'final' => 'V', 'multiple' => 'S', 'cantidad' => 2,
        ]);

        app(GuardarGestaDelEmbarazo::class)->ejecutar($medico->reg_medico, 1004, $this->formulario([
            'fur' => '2026-05-21', 'gestas' => '3',
        ]), clave: 1);

        $fresca = $fila->fresh();
        $this->assertSame('2026-05-21', $fresca->fur);
        $this->assertSame(3, $fresca->gestas);
        // Lo que el escritorio escribe en otras pantallas queda como esté (y el número de la gesta no se toca).
        $this->assertSame(2, $fresca->partos);
        $this->assertSame(1, $fresca->cesarea);
        $this->assertSame('gemelos', $fresca->otros);
        $this->assertSame('V', $fresca->final);
        $this->assertSame('S', $fresca->multiple);
        $this->assertSame(2, $fresca->cantidad);
        $this->assertSame(1, $fresca->gesta_clave);
    }

    public function test_lo_que_no_es_numero_en_el_campo_de_gestas_queda_vacio(): void
    {
        $medico = $this->medico();

        app(GuardarGestaDelEmbarazo::class)->ejecutar($medico->reg_medico, 1005, $this->formulario([
            'fur' => '2026-05-20', 'gestas' => 'no sé',
        ]));

        $this->assertNull(PreNatalObservacion::where('reg_medico', $medico->reg_medico)->where('historia', 1005)->first()->gestas);
    }
}
