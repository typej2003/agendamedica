<?php

namespace Tests\Feature;

use App\Actions\Catalogos\CopiarVacunasIniciales;
use App\Clinica\Catalogos\NovedadesDeVacunas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La copia del catalogo inicial de vacunas al consultorio (WEB-12.8): idempotente, no pisa lo del medico, no mezcla consultorios y ofrece
 * las novedades. El catalogo inicial es el de las filas sin dueño; aca se vacia (dentro de la transaccion de la prueba) y se arma uno propio.
 */
class CopiarVacunasInicialesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('vacunas')->whereNull('reg_medico')->delete();
        DB::table('vacunas_dosis')->whereNull('reg_medico')->delete();
    }

    private function inicial(string $codigo, string $nombre, array $dosis = [], array $extra = []): void
    {
        DB::table('vacunas')->insert(array_merge([
            'reg_medico' => null, 'codi_vacuna' => $codigo, 'vacuna' => $nombre, 'edad_inicial_meses' => 2, 'fija' => 'O', 'sexo' => 'A',
            'cobertura' => 'Cobertura ' . $nombre, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        foreach ($dosis as $numero => $rotulo) {
            DB::table('vacunas_dosis')->insert([
                'reg_medico' => null, 'codi_vacuna' => $codigo, 'dosis' => $numero, 'dosis_descri' => $rotulo, 'meses' => $numero * 2,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function accion(): CopiarVacunasIniciales
    {
        return app(CopiarVacunasIniciales::class);
    }

    public function test_copia_las_vacunas_y_sus_dosis_al_consultorio(): void
    {
        $this->inicial('BCG', 'BCG', [1 => '1era. Dosis']);
        $this->inicial('POLI', 'Polio', [1 => '1era. Dosis', 2 => '2da. Dosis', 3 => 'Refuerzo']);

        $this->assertSame(['vacunas' => 2, 'dosis' => 4], $this->accion()->ejecutar('med-a'));

        $this->assertSame(2, DB::table('vacunas')->where('reg_medico', 'med-a')->count());
        $this->assertSame(3, DB::table('vacunas_dosis')->where('reg_medico', 'med-a')->where('codi_vacuna', 'POLI')->count());
        $copia = DB::table('vacunas')->where('reg_medico', 'med-a')->where('codi_vacuna', 'POLI')->first();
        $this->assertSame('Polio', $copia->vacuna);
        $this->assertSame('Cobertura Polio', $copia->cobertura);
        $this->assertSame('O', $copia->fija);
        // El catalogo inicial queda intacto.
        $this->assertSame(2, DB::table('vacunas')->whereNull('reg_medico')->count());
        $this->assertSame(4, DB::table('vacunas_dosis')->whereNull('reg_medico')->count());
    }

    public function test_es_idempotente(): void
    {
        $this->inicial('BCG', 'BCG', [1 => '1era. Dosis']);

        $this->accion()->ejecutar('med-a');
        $this->assertSame(['vacunas' => 0, 'dosis' => 0], $this->accion()->ejecutar('med-a'));

        $this->assertSame(1, DB::table('vacunas')->where('reg_medico', 'med-a')->count());
        $this->assertSame(1, DB::table('vacunas_dosis')->where('reg_medico', 'med-a')->count());
    }

    public function test_no_pisa_ni_completa_lo_que_el_medico_ya_tiene(): void
    {
        $this->inicial('BCG', 'BCG inicial', [1 => 'Unica']);
        $this->inicial('POLI', 'Polio', [1 => '1era. Dosis']);
        DB::table('vacunas')->insert(['reg_medico' => 'med-a', 'codi_vacuna' => 'BCG', 'vacuna' => 'BCG del medico', 'fija' => 'R', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['vacunas' => 1, 'dosis' => 1], $this->accion()->ejecutar('med-a'));

        $bcg = DB::table('vacunas')->where('reg_medico', 'med-a')->where('codi_vacuna', 'BCG')->first();
        $this->assertSame('BCG del medico', $bcg->vacuna);
        $this->assertSame('R', $bcg->fija);
        // No le copia las dosis iniciales de una vacuna que ya tenia.
        $this->assertSame(0, DB::table('vacunas_dosis')->where('reg_medico', 'med-a')->where('codi_vacuna', 'BCG')->count());
    }

    public function test_no_mezcla_consultorios(): void
    {
        $this->inicial('BCG', 'BCG', [1 => 'Unica']);
        DB::table('vacunas')->insert(['reg_medico' => 'med-b', 'codi_vacuna' => 'BCG', 'vacuna' => 'BCG de B', 'created_at' => now(), 'updated_at' => now()]);

        // Que B ya la tenga no impide que A la reciba, y lo de A no toca lo de B.
        $this->assertSame(['vacunas' => 1, 'dosis' => 1], $this->accion()->ejecutar('med-a'));
        $this->assertSame(1, DB::table('vacunas')->where('reg_medico', 'med-b')->count());
        $this->assertSame('BCG de B', DB::table('vacunas')->where('reg_medico', 'med-b')->value('vacuna'));
        $this->assertSame(0, DB::table('vacunas_dosis')->where('reg_medico', 'med-b')->count());
    }

    public function test_las_novedades_son_las_iniciales_que_el_medico_no_tiene(): void
    {
        $this->inicial('BCG', 'BCG', [1 => 'Unica']);
        $this->inicial('POLI', 'Polio', [1 => 'a', 2 => 'b']);
        DB::table('vacunas')->insert(['reg_medico' => 'med-a', 'codi_vacuna' => 'BCG', 'vacuna' => 'BCG', 'created_at' => now(), 'updated_at' => now()]);

        $novedades = app(NovedadesDeVacunas::class)->de('med-a');

        $this->assertSame(['POLI'], $novedades->pluck('codi_vacuna')->all());
        $this->assertSame(2, $novedades->first()->dosis_total);

        // Lo que el admin agrega despues aparece como novedad y, ya copiado todo, no queda ninguna.
        $this->accion()->ejecutar('med-a');
        $this->assertCount(0, app(NovedadesDeVacunas::class)->de('med-a'));
        $this->inicial('ROTA', 'Rotavirus');
        $this->assertSame(['ROTA'], app(NovedadesDeVacunas::class)->de('med-a')->pluck('codi_vacuna')->all());
    }

    public function test_sin_catalogo_inicial_no_copia_nada(): void
    {
        $this->assertSame(['vacunas' => 0, 'dosis' => 0], $this->accion()->ejecutar('med-a'));
    }
}
