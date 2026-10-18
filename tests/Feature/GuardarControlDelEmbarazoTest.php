<?php

namespace Tests\Feature;

use App\Actions\Embarazo\GuardarControlDelEmbarazo;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\PreNatalDesarrolloFino;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * **Guardar un control del embarazo** (WEB-6.6, la pestaña *Controles*): la Action que escribe
 * `pre_natal_desarrollo_fino`.
 *
 * Lo que fijan estos casos: la clave **`historia` + `gesta_clave` + `fecha`** (una fila por fecha de
 * control de cada gesta), que la corrección escribe **sólo lo que cambió** con su rastro `source = web`,
 * que **sin fecha no se guarda**, que las columnas ocultas del DataWindow quedan intactas y que
 * `presentacion` guarda el **código** del escritorio (C, P, T, CC…), no el texto.
 */
class GuardarControlDelEmbarazoTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(): Medico
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'control-write' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'control-w-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Controles',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    /** @return array<string,string> */
    private function formulario(array $valores = []): array
    {
        $campos = ['fecha' => ''];

        foreach (PreNatalDesarrolloFino::campos() as $campo) {
            $campos[$campo['columna']] = '';
        }

        return array_merge($campos, $valores);
    }

    public function test_crea_el_control_con_los_valores_del_escritorio(): void
    {
        $medico = $this->medico();

        $resultado = app(GuardarControlDelEmbarazo::class)->ejecutar($medico->reg_medico, 1001, 1, $this->formulario([
            'fecha' => '2026-08-14', 'peso' => '68.5', 'ta' => '120/80', 'au' => '24',
            'presentacion' => 'CC', 'mf' => 'Fúndica', 'edemas' => 'Grado I', 'dbp' => '52',
            'femur' => '38', 'foco_f' => '142', 'peso_fetal' => '650', 'eg_fur' => '20s + 6d', 'eg_eco' => '21s + 0d',
        ]));

        $this->assertTrue($resultado->creado);

        $fila = PreNatalDesarrolloFino::where('reg_medico', $medico->reg_medico)->where('historia', 1001)->first();

        $this->assertNotNull($fila);
        $this->assertSame(1, $fila->gesta_clave);
        $this->assertSame('2026-08-14', $fila->fecha);
        $this->assertSame('120/80', $fila->ta);
        // La presentación guarda el código (CC), no "Cefálica - Cefálica": es lo que lee el escritorio.
        $this->assertSame('CC', $fila->presentacion);
        // `mf` es la placenta en esta tabla (el rótulo y los valores del DataWindow lo confirman).
        $this->assertSame('Fúndica', $fila->mf);
        $this->assertSame('20s + 6d', $fila->eg_fur);

        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'pre_natal_desarrollo_fino', 'record_id' => $fila->id,
            'operation' => 'created', 'source' => 'web',
        ]);
    }

    public function test_cada_fecha_es_una_fila_y_la_misma_fecha_corrige_solo_lo_que_cambio(): void
    {
        $medico = $this->medico();
        $accion = app(GuardarControlDelEmbarazo::class);

        $accion->ejecutar($medico->reg_medico, 1002, 1, $this->formulario(['fecha' => '2026-06-01', 'peso' => '60']));
        $accion->ejecutar($medico->reg_medico, 1002, 1, $this->formulario(['fecha' => '2026-07-01', 'peso' => '62']));

        $this->assertSame(2, PreNatalDesarrolloFino::where('historia', 1002)->count());

        $resultado = $accion->ejecutar($medico->reg_medico, 1002, 1, $this->formulario(['fecha' => '2026-06-01', 'peso' => '61', 'ta' => '110/70']));

        $this->assertEqualsCanonicalizing(['peso', 'ta'], $resultado->columnas);
        $this->assertSame(2, PreNatalDesarrolloFino::where('historia', 1002)->count());
        $this->assertSame('61', PreNatalDesarrolloFino::where('historia', 1002)->where('fecha', '2026-06-01')->first()->peso);
        $this->assertSame(2, SyncChange::where('table_name', 'pre_natal_desarrollo_fino')->where('operation', 'updated')->count());
    }

    public function test_sin_fecha_no_se_guarda(): void
    {
        $medico = $this->medico();

        $this->expectException(InvalidArgumentException::class);

        try {
            app(GuardarControlDelEmbarazo::class)->ejecutar($medico->reg_medico, 1003, 1, $this->formulario(['peso' => '60']));
        } finally {
            $this->assertSame(0, PreNatalDesarrolloFino::where('reg_medico', $medico->reg_medico)->count());
        }
    }

    public function test_no_toca_las_columnas_ocultas_del_datawindow(): void
    {
        $medico = $this->medico();
        $fila = PreNatalDesarrolloFino::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1004, 'gesta_clave' => 1, 'fecha' => '2026-06-01',
            'lf' => 'Normal', 'plac_grado' => 'II', 'lcc' => '45', 'varice' => 'S', 'cantidad' => 1,
        ]);

        app(GuardarControlDelEmbarazo::class)->ejecutar($medico->reg_medico, 1004, 1, $this->formulario([
            'fecha' => '2026-06-01', 'peso' => '70',
        ]));

        $fresca = $fila->fresh();
        $this->assertSame('70', $fresca->peso);
        $this->assertSame('Normal', $fresca->lf);
        $this->assertSame('II', $fresca->plac_grado);
        $this->assertSame('45', $fresca->lcc);
        $this->assertSame('S', $fresca->varice);
        $this->assertSame(1, $fresca->cantidad);
        $this->assertNull($fresca->consulta);
    }
}
