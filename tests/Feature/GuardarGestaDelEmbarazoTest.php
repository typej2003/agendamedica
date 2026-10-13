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

    public function test_la_unica_columna_que_esta_pestana_no_edita_es_parto(): void
    {
        // ⚠️ La trampa del módulo: `parto` (*Parto:*) está en `d_pre_natal_observa.srd`, el DataWindow
        // **huérfano** que ninguna ventana usa; en la pestaña viva (`d_pre_natal_observa_2.srd:28`) está
        // `visible="0"`. Las otras siete columnas que antes se creían "de otras pantallas" (`partos`,
        // `cesarea`, `abortos`, `otros`, `final`, `multiple`, `cantidad`) **sí** son de esta pestaña y el
        // formulario las manda.
        $medico = $this->medico();
        $fila = PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1004, 'gesta_clave' => 1,
            'fur' => '2026-05-20', 'parto' => '1 cesárea',
            'partos' => 2, 'cesarea' => 1, 'abortos' => 0,
            'otros' => 'gemelos', 'final' => 'V', 'multiple' => 'S', 'cantidad' => 2,
        ]);

        app(GuardarGestaDelEmbarazo::class)->ejecutar($medico->reg_medico, 1004, $this->formulario([
            'fur' => '2026-05-21', 'gestas' => '3',
            'partos' => '3', 'cesarea' => '2', 'abortos' => '1', 'otros' => 'un solo feto',
            'final' => 'S', 'multiple' => 'N', 'cantidad' => '1',
        ]), clave: 1);

        $fresca = $fila->fresh();
        $this->assertSame('2026-05-21', $fresca->fur);
        $this->assertSame(3, $fresca->gestas);

        // Las siete de la pestaña siguen al formulario.
        $this->assertSame(3, $fresca->partos);
        $this->assertSame(2, $fresca->cesarea);
        $this->assertSame(1, $fresca->abortos);
        $this->assertSame('un solo feto', $fresca->otros);
        $this->assertSame('S', $fresca->final);
        $this->assertSame('N', $fresca->multiple);
        $this->assertSame(1, $fresca->cantidad);

        // Y `parto` (la del DataWindow huérfano) queda como estaba, y el número de la gesta no se toca.
        $this->assertSame('1 cesárea', $fresca->parto);
        $this->assertSame(1, $fresca->gesta_clave);
    }

    public function test_al_crear_la_gesta_el_campo_gestas_arranca_con_su_numero(): void
    {
        // Como el escritorio al crear una gesta (`w_agregar_nueva_gesta.srw:171-178`): estar en la gesta 2
        // quiere decir que hubo 2 embarazos. Si el formulario trae otro número, manda el formulario.
        $medico = $this->medico();

        app(GuardarGestaDelEmbarazo::class)->ejecutar($medico->reg_medico, 1006, $this->formulario([
            'fur' => '2026-05-20',
        ]));
        app(GuardarGestaDelEmbarazo::class)->ejecutar($medico->reg_medico, 1006, $this->formulario([
            'fur' => '2026-06-20',
        ]));
        app(GuardarGestaDelEmbarazo::class)->ejecutar($medico->reg_medico, 1007, $this->formulario([
            'fur' => '2026-05-20', 'gestas' => '7',
        ]));

        $gestas = PreNatalObservacion::where('reg_medico', $medico->reg_medico)->where('historia', 1006)
            ->orderBy('gesta_clave')->get();

        $this->assertSame(1, $gestas[0]->gestas);
        $this->assertSame(2, $gestas[1]->gestas);
        $this->assertSame(7, PreNatalObservacion::where('historia', 1007)->first()->gestas);
    }

    public function test_lo_que_no_es_numero_en_los_campos_de_numeros_queda_vacio(): void
    {
        $medico = $this->medico();
        // Al crear, el campo vacío toma el número de la gesta (1), así que la basura se prueba corrigiendo.
        $fila = PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1005, 'gesta_clave' => 1,
            'fur' => '2026-05-20', 'gestas' => 2, 'partos' => 2, 'cantidad' => 2,
        ]);

        app(GuardarGestaDelEmbarazo::class)->ejecutar($medico->reg_medico, 1005, $this->formulario([
            'fur' => '2026-05-20', 'gestas' => 'no sé', 'partos' => '1,5', 'cantidad' => '',
        ]), clave: 1);

        $fresca = $fila->fresh();
        $this->assertNull($fresca->gestas);
        $this->assertNull($fresca->partos);
        $this->assertNull($fresca->cantidad);
    }
}
