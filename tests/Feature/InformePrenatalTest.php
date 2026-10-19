<?php

namespace Tests\Feature;

use App\Clinica\Embarazo\InformePrenatal;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\PreNatalDesarrolloFino;
use App\Models\PreNatalObservacion;
use App\Models\PrenaExamenB;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **La lectura del informe del control de embarazo** (WEB-6.6, la pestaña *Reporte*): las tres partes que
 * imprime la hoja «CONTROL DE EMBARAZO» —la gesta, sus controles y su laboratorio—.
 *
 * Lo que fijan estos casos: que las tres partes salgan **de esa gesta y de ese médico**, ordenadas por
 * fecha; que sin gestas no haya informe; que se pueda pedir una gesta por su número; y —el dato que más
 * fácil se copia mal— que la **FPP del informe suma 283**, no los 281 de la pantalla del control prenatal.
 */
class InformePrenatalTest extends TestCase
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
        $regMedico = 'informe-pre-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'informe-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Informe',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    public function test_arma_el_informe_con_las_tres_partes_de_esa_gesta(): void
    {
        $medico = $this->medico();

        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1,
            'fur' => '2026-05-20', 'gestas' => 1, 'tipiaje' => 'O+', 'observaciones' => 'Sin particularidades.',
        ]);
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 2, 'fur' => '2024-01-10',
        ]);

        // Dos controles, cargados en desorden para probar el orden por fecha; y uno de la otra gesta.
        PreNatalDesarrolloFino::create(['reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fecha' => '2026-08-14', 'peso' => '68']);
        PreNatalDesarrolloFino::create(['reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fecha' => '2026-06-01', 'peso' => '65']);
        PreNatalDesarrolloFino::create(['reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 2, 'fecha' => '2024-02-01', 'peso' => '60']);
        PrenaExamenB::create(['reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fecha' => '2026-06-02', 'hemoglobina' => '11.5']);

        $informe = (new InformePrenatal())->de($medico->reg_medico, 1001, null, $this->hoy);

        $this->assertNotNull($informe);
        // Sin gesta pedida, la actual es la de mayor número… que acá es la 2 (la más vieja en datos).
        $this->assertSame(2, $informe['gesta']->clave());

        // Pidiendo la 1: sus partes, y sólo las suyas.
        $informe = (new InformePrenatal())->de($medico->reg_medico, 1001, 1, $this->hoy);

        $this->assertSame(1, $informe['gesta']->clave());
        $this->assertSame('O+', $informe['gesta']->tipiaje());
        $this->assertSame('Sin particularidades.', $informe['gesta']->observaciones());
        $this->assertSame(['2026-06-01', '2026-08-14'], $informe['controles']->pluck('fecha')->all());
        $this->assertSame(['2026-06-02'], $informe['laboratorios']->pluck('fecha')->all());
    }

    public function test_sin_gestas_no_hay_informe(): void
    {
        $medico = $this->medico();

        $this->assertNull((new InformePrenatal())->de($medico->reg_medico, 1001, null, $this->hoy));
        // Y una gesta que no existe tampoco.
        PreNatalObservacion::create(['reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => '2026-05-20']);
        $this->assertNull((new InformePrenatal())->de($medico->reg_medico, 1001, 9, $this->hoy));
    }

    public function test_la_lectura_se_acota_al_medico(): void
    {
        $medico = $this->medico();
        $otro = $this->medico();

        PreNatalObservacion::create(['reg_medico' => $otro->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => '2026-05-20']);

        $this->assertNull((new InformePrenatal())->de($medico->reg_medico, 1001, null, $this->hoy));
    }

    public function test_la_fpp_del_informe_es_la_misma_de_toda_la_web(): void
    {
        $informe = new InformePrenatal();

        // 2026-05-20 + 280 = 2027-02-24: el informe y la pantalla del control suman lo mismo (WEB-11.16).
        $this->assertSame('2027-02-24', $informe->fpp('2026-05-20'));
        $this->assertSame(280, InformePrenatal::DIAS_DE_LA_FPP_DEL_INFORME);
        $this->assertSame(\App\Clinica\Embarazo\EdadGestacional::fpp('2026-05-20', \App\Clinica\Embarazo\EdadGestacional::DIAS_DE_LA_FPP), $informe->fpp('2026-05-20'));

        // Y la edad gestacional es la misma fórmula de todas las pantallas.
        $gesta = new \App\Clinica\Embarazo\Gesta(['fur' => '2026-05-20', 'gesta_clave' => 1], null, $this->hoy);
        $this->assertSame('20 semanas y 6 días', $informe->edadGestacional($gesta, $this->hoy)->texto());
    }
}
