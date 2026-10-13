<?php

namespace Tests\Feature;

use App\Models\ModuloClinico;
use App\Models\Specialty;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `especialidades:sembrar`: el paso del final del despliegue que asegura el catálogo multi-especialidad.
 *
 * Lo que fijan estos tests: **arregla** una base a la que le falta el núcleo (el bug medido el 2026-10-11,
 * que dejaba el menú del consultorio vacío), **sólo agrega** (no borra ni pisa lo que el seeder no
 * administra), es **idempotente** (correrlo dos veces no cambia una fila) y `--simular` **no escribe**.
 */
class SembrarEspecialidadesCommandTest extends TestCase
{
    use DatabaseTransactions;

    private function especialidad(string $slug): Specialty
    {
        return Specialty::where('slug', $slug)->firstOrFail();
    }

    /** Deja una especialidad como está en un servidor con el bug: existe (con sus consultorios) y sin módulos. */
    private function romperElNucleo(string $slug): Specialty
    {
        $this->especialidad($slug)->modulos()->detach();

        return $this->especialidad($slug);
    }

    public function test_agrega_el_nucleo_a_una_especialidad_que_no_lo_tenia(): void
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $traumatologia = $this->romperElNucleo('traumatologia-y-ortopedia');

        $this->assertSame([], $traumatologia->modulosVisibles(['Medico'])->pluck('slug')->all(), 'Sin filas, el menú sale vacío.');

        $this->artisan('especialidades:sembrar')->assertSuccessful();

        $this->assertSame(
            ['agenda', 'pacientes'],
            $this->especialidad('traumatologia-y-ortopedia')->modulosVisibles(['Medico'])->pluck('slug')->all()
        );
    }

    public function test_solo_agrega_y_no_borra_lo_que_no_administra(): void
    {
        $this->artisan('especialidades:sembrar')->assertSuccessful();

        // Una fila que el seeder no declara (un módulo que esa especialidad no tiene en su manifiesto): es
        // una decisión tomada a mano y correr el comando no puede llevársela puesta.
        $general = $this->especialidad('medicina-general');
        $ecografias = ModuloClinico::where('slug', 'ecografias')->firstOrFail();

        DB::table('especialidad_modulo')->insert([
            'specialty_id' => $general->id,
            'modulo_id'    => $ecografias->id,
            'implementado' => true,
            'visible_para' => null,
            'orden'        => 5,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $filas = DB::table('especialidad_modulo')->count();

        $this->artisan('especialidades:sembrar')->assertSuccessful();

        $this->assertSame($filas, DB::table('especialidad_modulo')->count(), 'No se duplican ni se borran filas.');
        $this->assertTrue(
            (bool) DB::table('especialidad_modulo')
                ->where('specialty_id', $general->id)->where('modulo_id', $ecografias->id)
                ->value('implementado'),
            'Lo que el seeder no administra queda como estaba.'
        );
    }

    public function test_es_idempotente(): void
    {
        $this->artisan('especialidades:sembrar')->assertSuccessful();

        $filas = DB::table('especialidad_modulo')->count();
        $implementadas = DB::table('especialidad_modulo')->where('implementado', true)->count();

        $this->artisan('especialidades:sembrar')
            ->expectsOutput('El catálogo ya estaba al día: no había nada que agregar.')
            ->assertSuccessful();

        $this->assertSame($filas, DB::table('especialidad_modulo')->count());
        $this->assertSame($implementadas, DB::table('especialidad_modulo')->where('implementado', true)->count());
    }

    public function test_simular_informa_lo_que_falta_sin_escribir(): void
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $this->romperElNucleo('medicina-general');
        $filas = DB::table('especialidad_modulo')->count();

        $this->artisan('especialidades:sembrar', ['--simular' => true])
            ->expectsOutput('· Medicina General: sin fila de agenda, sin fila de pacientes')
            ->assertSuccessful();

        $this->assertSame($filas, DB::table('especialidad_modulo')->count(), '--simular no escribe.');
        $this->assertSame([], $this->especialidad('medicina-general')->modulosVisibles(['Medico'])->pluck('slug')->all());
    }

    /** Un módulo del núcleo dado de baja en el catálogo no lo arregla el seeder: el comando lo dice y falla. */
    public function test_falla_si_el_modulo_del_nucleo_esta_inactivo(): void
    {
        $this->artisan('especialidades:sembrar')->assertSuccessful();

        ModuloClinico::where('slug', 'agenda')->update(['activo' => false]);

        $this->artisan('especialidades:sembrar')
            ->expectsOutput('Quedaron especialidades sin el núcleo compartido del consultorio:')
            ->assertFailed();
    }
}
