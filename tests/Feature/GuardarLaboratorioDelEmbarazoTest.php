<?php

namespace Tests\Feature;

use App\Actions\Embarazo\GuardarLaboratorioDelEmbarazo;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\PrenaExamenB;
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
 * **Guardar un laboratorio del embarazo** (WEB-6.6, la pestaña *Laboratorio*): la Action que escribe
 * `prena_exames_b`.
 *
 * Lo que fijan estos casos: la clave **`historia` + `gesta_clave` + `fecha`** (una fila por fecha de
 * laboratorio de cada gesta), que dos fechas distintas son dos filas, que la corrección escribe **sólo lo
 * que cambió** con el rastro por columna y `source = web`, que **sin fecha no se guarda**, y que las
 * columnas que el DataWindow tiene ocultas (plaquetas, TSH…) quedan como estén.
 */
class GuardarLaboratorioDelEmbarazoTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(): Medico
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'lab-write-' . uniqid();

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'lab-w-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Laboratorio',
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

        foreach (PrenaExamenB::campos() as $campo) {
            $campos[$campo['columna']] = $valores[$campo['columna']] ?? '';
        }

        return array_merge($campos, $valores);
    }

    public function test_crea_la_fila_de_esa_fecha_con_su_rastro(): void
    {
        $medico = $this->medico();

        $resultado = app(GuardarLaboratorioDelEmbarazo::class)->ejecutar($medico->reg_medico, 1001, 2, $this->formulario([
            'fecha' => '2026-08-14', 'hemoglobina' => '11,5', 'hematoc' => '34', 'glob_blanco' => '8200',
            'hiv' => 'Negativo', 'vdrl' => 'No reactivo', 'orina' => 'Normal', 'otros' => 'Sin particularidades',
        ]));

        $this->assertTrue($resultado->creado);

        $fila = PrenaExamenB::where('reg_medico', $medico->reg_medico)->where('historia', 1001)->first();

        $this->assertNotNull($fila);
        $this->assertSame(2, $fila->gesta_clave);
        $this->assertSame('2026-08-14', $fila->fecha);
        // La coma decimal se guarda con punto, como en el escritorio.
        $this->assertSame(11.5, (float) $fila->hemoglobina);
        $this->assertSame('Negativo', $fila->hiv);
        $this->assertSame('No reactivo', $fila->vdrl);
        $this->assertSame('Normal', $fila->orina);

        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'prena_exames_b', 'record_id' => $fila->id, 'operation' => 'created', 'source' => 'web',
        ]);
    }

    public function test_cada_fecha_de_la_misma_gesta_es_una_fila_y_la_misma_fecha_se_corrige(): void
    {
        $medico = $this->medico();
        $accion = app(GuardarLaboratorioDelEmbarazo::class);

        $accion->ejecutar($medico->reg_medico, 1002, 1, $this->formulario(['fecha' => '2026-06-01', 'urea' => '25']));
        // Otra fecha: es otra fila, no una corrección.
        $accion->ejecutar($medico->reg_medico, 1002, 1, $this->formulario(['fecha' => '2026-07-01', 'urea' => '30']));
        // Y otra gesta con la misma fecha: también es otra fila (la clave incluye la gesta).
        $accion->ejecutar($medico->reg_medico, 1002, 2, $this->formulario(['fecha' => '2026-06-01', 'urea' => '28']));

        $this->assertSame(3, PrenaExamenB::where('historia', 1002)->count());

        // La misma clave (gesta + fecha) corrige: sólo lo que cambió.
        $resultado = $accion->ejecutar($medico->reg_medico, 1002, 1, $this->formulario(['fecha' => '2026-06-01', 'urea' => '26']));

        $this->assertSame(['urea'], $resultado->columnas);
        $this->assertSame(3, PrenaExamenB::where('historia', 1002)->count());
        $this->assertSame('26', PrenaExamenB::where('historia', 1002)->where('gesta_clave', 1)->where('fecha', '2026-06-01')->first()->urea);
        $this->assertSame(1, SyncChange::where('table_name', 'prena_exames_b')->where('column_name', 'urea')->where('operation', 'updated')->count());
    }

    public function test_sin_fecha_no_se_guarda(): void
    {
        $medico = $this->medico();

        $this->expectException(InvalidArgumentException::class);

        try {
            app(GuardarLaboratorioDelEmbarazo::class)->ejecutar($medico->reg_medico, 1003, 1, $this->formulario(['hemoglobina' => '11']));
        } finally {
            $this->assertSame(0, PrenaExamenB::where('reg_medico', $medico->reg_medico)->count());
        }
    }

    public function test_no_toca_las_columnas_ocultas_del_datawindow(): void
    {
        $medico = $this->medico();
        $fila = PrenaExamenB::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1004, 'gesta_clave' => 1, 'fecha' => '2026-06-01',
            'plaquetas' => '250000', 'tsh' => '2.1', 'urocultivo' => 'negativo', 'insulina_basal' => '8',
        ]);

        app(GuardarLaboratorioDelEmbarazo::class)->ejecutar($medico->reg_medico, 1004, 1, $this->formulario([
            'fecha' => '2026-06-01', 'hemoglobina' => '12',
        ]));

        $fresca = $fila->fresh();
        $this->assertSame('12', $fresca->hemoglobina);
        $this->assertSame('250000', $fresca->plaquetas);
        $this->assertSame('2.1', $fresca->tsh);
        $this->assertSame('negativo', $fresca->urocultivo);
        $this->assertSame('8', $fresca->insulina_basal);
    }

    public function test_lo_que_no_es_numero_en_un_campo_de_numero_queda_vacio(): void
    {
        $medico = $this->medico();

        app(GuardarLaboratorioDelEmbarazo::class)->ejecutar($medico->reg_medico, 1005, 1, $this->formulario([
            'fecha' => '2026-06-01', 'hemoglobina' => 'menos de 10', 'glob_blanco' => '8200',
        ]));

        $fila = PrenaExamenB::where('historia', 1005)->first();
        $this->assertNull($fila->hemoglobina);
        $this->assertSame('8200', $fila->glob_blanco);
    }
}
