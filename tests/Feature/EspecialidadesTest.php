<?php

namespace Tests\Feature;

use App\Especialidades\RegistroEspecialidades;
use App\Models\ModuloClinico;
use App\Models\Specialty;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * Marco multi-especialidad (PLAN-WEB.md, R2): manifiestos, validador de colisiones y catálogo de
 * módulos. `DatabaseTransactions` por la razón de siempre (ver ConfiguracionMedicoTest).
 */
class EspecialidadesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_carga_el_manifiesto_de_ginecologia(): void
    {
        $registro = new RegistroEspecialidades();
        $registro->validar();

        $gineco = $registro->porSlug('ginecologia');

        $this->assertNotNull($gineco);
        $this->assertSame('GIN', $gineco->codigo);
        $this->assertSame('ginecologia-y-obstetricia', $gineco->specialtySlug);
        $this->assertContains('ecografias', $gineco->modulos);
        $this->assertContains('eco_pelvico', $gineco->propias);
        $this->assertContains('ecografias', array_keys($gineco->pantallas));
        $this->assertNotEmpty($gineco->pantallas['consulta']);
    }

    public function test_reconoce_como_llama_el_legado_a_ginecologia(): void
    {
        $gineco = (new RegistroEspecialidades())->porSlug('ginecologia');

        // El catálogo real trae el punto final y mayúsculas; el `codeespecial` de esa instalación es 020.
        $this->assertTrue($gineco->reconoceLegado('GINECOLOGIA Y OBSTETRICIA.', '020'));
        $this->assertTrue($gineco->reconoceLegado('ginecología y obstetricia'));
        $this->assertFalse($gineco->reconoceLegado('PEDIATRIA', '033'));
    }

    public function test_el_validador_falla_si_dos_manifiestos_se_disputan_una_tabla(): void
    {
        $registro = new RegistroEspecialidades($this->manifiestosDePrueba([
            'uno' => ['nombre' => 'Uno', 'propias' => ['tabla_disputada']],
            'dos' => ['nombre' => 'Dos', 'propias' => ['tabla_disputada']],
        ]));

        $this->assertNotEmpty($registro->conflictos());

        $this->expectException(RuntimeException::class);
        $registro->validar();
    }

    public function test_una_tabla_compartida_declarada_en_dos_manifiestos_no_es_conflicto(): void
    {
        $registro = new RegistroEspecialidades($this->manifiestosDePrueba([
            'uno' => ['nombre' => 'Uno', 'compartidas' => ['ultra_renal']],
            'dos' => ['nombre' => 'Dos', 'compartidas' => ['ultra_renal']],
        ]));

        $this->assertSame([], $registro->conflictos());
    }

    public function test_el_validador_detecta_una_tabla_propia_y_compartida_en_el_mismo_manifiesto(): void
    {
        $registro = new RegistroEspecialidades($this->manifiestosDePrueba([
            'uno' => ['nombre' => 'Uno', 'propias' => ['eco_pelvico'], 'compartidas' => ['eco_pelvico']],
        ]));

        $this->assertNotEmpty($registro->conflictos());
    }

    public function test_el_seeder_deja_ginecologia_con_sus_modulos_y_solo_agenda_y_pacientes_implementados(): void
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->first();

        $this->assertNotNull($gineco);
        $this->assertSame('GIN', $gineco->codigo);
        $this->assertTrue($gineco->activo);
        $this->assertSame(15, $gineco->modulos()->count()); // 9 del núcleo + 6 propios

        // Los implementados, en el orden del menú (agenda = 10, pacientes = 20).
        $this->assertSame(['agenda', 'pacientes'], $gineco->modulosVisibles(['Medico'])->pluck('slug')->all());
        $this->assertSame(1, ModuloClinico::where('slug', 'ecografias')->count());
        $this->assertSame(0, ModuloClinico::where('slug', 'no_existe')->count());
    }

    public function test_la_secretaria_ve_los_modulos_marcados_para_su_rol(): void
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->first();

        $this->assertSame(['agenda', 'pacientes'], $gineco->modulosVisibles(['Secretaria'])->pluck('slug')->all());
        // Un rol que no es del consultorio no ve los módulos restringidos a médico/secretaría.
        $this->assertSame([], $gineco->modulosVisibles(['Paciente'])->pluck('slug')->all());
    }

    /** Crea un directorio temporal con manifiestos y lo borra al terminar el test. */
    private function manifiestosDePrueba(array $manifiestos): string
    {
        $dir = storage_path('framework/testing/especialidades-' . uniqid());
        File::makeDirectory($dir, 0755, true);

        foreach ($manifiestos as $slug => $datos) {
            File::put($dir . DIRECTORY_SEPARATOR . $slug . '.php', '<?php return ' . var_export($datos, true) . ';');
        }

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($dir));

        return $dir;
    }
}
