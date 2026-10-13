<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Embarazo\GestasDeLaHistoria;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Las gestas de una historia** (WEB-6.6): la lectura de `pre_natal_observaciones`, la tabla de la pestaña
 * *Prenatal* del control de embarazo.
 *
 * Lo que fija: la clave **`historia` + `gesta_clave`** con `gesta_clave` nullable (una fila sin número es
 * la gesta **1**), el orden de la lista, cuál es la gesta en curso, el número que le toca a la próxima y
 * que cada gesta trae su **edad gestacional** y su **FPP con 270 días** (la de esta pantalla, no la del eco
 * obstétrico).
 */
class GestasDeLaHistoriaTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::parse('2026-10-13 09:00:00');
    }

    private function medico(): Medico
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'gestas-test-' . uniqid();

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'gestas-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Gestas',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    private function gesta(Medico $medico, int $historia, ?int $clave, array $extra = []): void
    {
        DB::table('pre_natal_observaciones')->insert(array_merge([
            'reg_medico' => $medico->reg_medico,
            'historia'   => $historia,
            'gesta_clave' => $clave,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    public function test_lee_las_gestas_ordenadas_y_distingue_la_actual(): void
    {
        $medico = $this->medico();
        // La primera gesta del legado viene sin número (nullable): es la 1.
        $this->gesta($medico, 1001, null, ['fur' => '2024-01-10', 'menarquia' => '12 años', 'gestas' => 2, 'parto' => '1 cesárea']);
        $this->gesta($medico, 1001, 2, ['fur' => '2026-05-20', 'tipiaje' => 'O+', 'tipiaje_cony' => 'A+', 'observaciones' => 'Embarazo en curso.']);

        $gestas = (new GestasDeLaHistoria())->de($medico->reg_medico, 1001, $this->hoy);

        $this->assertCount(2, $gestas);
        $this->assertSame([1, 2], array_map(fn ($gesta) => $gesta->clave(), $gestas));

        $primera = $gestas[0];
        $this->assertSame('2024-01-10', $primera->fur());
        $this->assertSame('12 años', $primera->menarquia());
        $this->assertSame('2', $primera->gestas());
        $this->assertSame('1 cesárea', $primera->parto());
        $this->assertFalse($primera->esLaActual(2));

        $segunda = $gestas[1];
        $this->assertSame('O+', $segunda->tipiaje());
        $this->assertSame('A+', $segunda->tipiajeDeLaPareja());
        $this->assertSame('Embarazo en curso.', $segunda->observaciones());
        $this->assertTrue($segunda->esLaActual(2));

        // La actual es la de mayor número, y la FUR que el escritorio busca en el eco es la de esa gesta.
        $this->assertSame(2, (new GestasDeLaHistoria())->actual($medico->reg_medico, 1001)->clave());
        $this->assertSame('2026-05-20', (new GestasDeLaHistoria())->actual($medico->reg_medico, 1001)->fur());
    }

    public function test_cada_gesta_trae_su_edad_gestacional_y_su_fpp_con_270_dias(): void
    {
        $medico = $this->medico();
        // 146 días antes del 2026-10-13: 20 semanas y 6 días (146 = 20 * 7 + 6).
        $this->gesta($medico, 1002, 1, ['fur' => '2026-05-20']);

        $gesta = (new GestasDeLaHistoria())->de($medico->reg_medico, 1002, $this->hoy)[0];

        $this->assertSame(146, $gesta->edadGestacional()->diasTotales());
        $this->assertSame('20 semanas y 6 días', $gesta->edadGestacional()->texto());
        // La FPP de esta pantalla suma 270: 2026-05-20 + 270 = 2027-02-14.
        $this->assertSame('2027-02-14', $gesta->fpp());
    }

    public function test_una_gesta_sin_fur_no_tiene_edad_gestacional_ni_fpp(): void
    {
        $medico = $this->medico();
        $this->gesta($medico, 1003, 1, ['fur' => null]);

        $gesta = (new GestasDeLaHistoria())->de($medico->reg_medico, 1003, $this->hoy)[0];

        $this->assertNull($gesta->edadGestacional());
        $this->assertNull($gesta->fpp());
        $this->assertNull($gesta->fur());
    }

    public function test_la_proxima_gesta_es_la_ultima_mas_uno_y_la_primera_es_la_uno(): void
    {
        $medico = $this->medico();

        // Sin gestas: la próxima es la 1.
        $this->assertSame(1, (new GestasDeLaHistoria())->siguienteClave($medico->reg_medico, 1004));
        $this->assertNull((new GestasDeLaHistoria())->actual($medico->reg_medico, 1004));

        // Con la primera sin número (nullable) y una segunda: la próxima es la 3.
        $this->gesta($medico, 1004, null);
        $this->gesta($medico, 1004, 2);
        $this->assertSame(3, (new GestasDeLaHistoria())->siguienteClave($medico->reg_medico, 1004));
    }

    public function test_la_lectura_se_acota_al_medico_y_a_la_historia(): void
    {
        $medico = $this->medico();
        $otro = $this->medico();

        $this->gesta($medico, 1005, 1, ['fur' => '2026-01-01']);
        $this->gesta($otro, 1005, 1, ['fur' => '2025-01-01']);
        $this->gesta($medico, 1006, 1, ['fur' => '2024-01-01']);

        $gestas = (new GestasDeLaHistoria())->de($medico->reg_medico, 1005, $this->hoy);

        $this->assertCount(1, $gestas);
        $this->assertSame('2026-01-01', $gestas[0]->fur());

        // `fila()` encuentra la gesta por su número, contando la nullable como la 1.
        $this->assertSame('2026-01-01', (new GestasDeLaHistoria())->fila($medico->reg_medico, 1005, 1)->fur);
        $this->assertNull((new GestasDeLaHistoria())->fila($medico->reg_medico, 1005, 9));
    }
}
