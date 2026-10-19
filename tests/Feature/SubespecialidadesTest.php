<?php

namespace Tests\Feature;

use App\Clinica\ContextoTrabajo;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\ModuloClinico;
use App\Models\Specialty;
use App\Models\Subspecialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Las subespecialidades (postgrados) de una especialidad** (decisión del usuario, 2026-10-19).
 *
 * Pediatría puede tener nefrología pediátrica, neumología pediátrica, gastroenterología, cirugía y
 * ecografía; un médico puede tener una, varias o ninguna, y las de la especialidad que está trabajando se
 * **suman** para encender módulos (menú) y secciones (consulta).
 *
 * Lo que se fija acá: el catálogo lo siembra el manifiesto, el postgrado cuelga de su especialidad, el
 * médico sólo ve los de la especialidad del contexto y sus módulos se suman a los de la especialidad.
 */
class SubespecialidadesTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $slug = 'pediatria'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $especialidad = Specialty::where('slug', $slug)->firstOrFail();
        $regMedico = 'sub-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora de prueba', 'email' => 'sub-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doctora', 'lastname' => 'De Prueba', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($especialidad->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $especialidad];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    public function test_el_manifiesto_siembra_las_subespecialidades_de_pediatria(): void
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();
        $subespecialidades = $pediatria->subespecialidades;

        $this->assertSame([
            'nefrologia-pediatrica', 'neumologia-pediatrica', 'gastroenterologia-pediatrica',
            'cirugia-pediatrica', 'ecografia-pediatrica',
        ], $subespecialidades->pluck('slug')->all());

        // El catálogo de la consulta que enciende cada una sale del manifiesto, no se inventa acá.
        $manifiesto = require config_path('especialidades/pediatria.php');
        $this->assertSame(['indicadores'], $manifiesto['subespecialidades']['nefrologia-pediatrica']['secciones']);
    }

    public function test_ginecologia_no_queda_con_subespecialidades_inventadas(): void
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $this->assertCount(0, Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail()->subespecialidades);
    }

    public function test_el_medico_puede_tener_varios_postgrados_y_mas_de_una_especialidad(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();

        $nefro = Subspecialty::where('slug', 'nefrologia-pediatrica')->firstOrFail();
        $neumo = Subspecialty::where('slug', 'neumologia-pediatrica')->firstOrFail();
        $medico->subespecialidades()->attach([$nefro->id, $neumo->id]);

        $otraEspecialidad = Specialty::firstOrCreate(['slug' => 'cardiologia'], ['name' => 'Cardiología']);
        $medico->specialties()->attach($otraEspecialidad->id);

        $this->assertEqualsCanonicalizing(
            [$nefro->id, $neumo->id],
            $medico->fresh()->subespecialidadesDe($pediatria)->pluck('id')->all(),
        );
        $this->assertCount(0, $medico->fresh()->subespecialidadesDe($otraEspecialidad));
    }

    public function test_el_contexto_lleva_los_postgrados_de_la_especialidad_activa(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();

        $nefro = Subspecialty::where('slug', 'nefrologia-pediatrica')->firstOrFail();
        $medico->subespecialidades()->attach($nefro->id);

        $contexto = app(ContextoTrabajo::class)->fijar($user->fresh(), $medico->reg_medico, $pediatria->id);

        $this->assertSame([$nefro->id], $contexto['subespecialidades']->pluck('id')->all());
    }

    public function test_los_modulos_del_postgrado_se_suman_a_los_de_la_especialidad(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $nefro = Subspecialty::where('slug', 'nefrologia-pediatrica')->firstOrFail();
        $medico->subespecialidades()->attach($nefro->id);

        // Un módulo del menú que enciende el postgrado (hoy ninguno lo hace: sus pantallas son secciones de
        // la consulta; la tabla `subespecialidad_modulo` existe para el día que sumen una entrada propia).
        $modulo = ModuloClinico::create(['slug' => 'indicadores-menu', 'nombre' => 'Indicadores (menú)', 'orden' => 200]);
        $nefro->modulos()->attach($modulo->id, ['implementado' => true, 'visible_para' => ['Medico'], 'orden' => 200]);

        $trabajo = app(ContextoTrabajo::class);
        $contexto = $trabajo->fijar($user->fresh(), $medico->reg_medico, $pediatria->id);
        $slugs = $trabajo->modulos($contexto, $trabajo->roles($user->fresh()))->pluck('slug')->all();

        $this->assertContains('indicadores-menu', $slugs);
        // Y no se pierde nada de lo que ya daba la especialidad.
        $this->assertContains('agenda', $slugs);
        $this->assertContains('crecimiento', $slugs);

        // El menú de la web lo muestra: es un módulo implementado del postgrado.
        $html = $this->conContexto($user, $medico, $pediatria)->get('/clinica')->assertOk()->getContent();
        $this->assertStringContainsString('Indicadores (menú)', $html);
    }

    public function test_un_modulo_del_postgrado_sin_pantalla_no_aparece(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $neumo = Subspecialty::where('slug', 'neumologia-pediatrica')->firstOrFail();
        $medico->subespecialidades()->attach($neumo->id);

        $modulo = ModuloClinico::create(['slug' => 'espirometria', 'nombre' => 'Espirometría', 'orden' => 210]);
        $neumo->modulos()->attach($modulo->id, ['implementado' => false, 'visible_para' => ['Medico'], 'orden' => 210]);

        $trabajo = app(ContextoTrabajo::class);
        $contexto = $trabajo->fijar($user->fresh(), $medico->reg_medico, $pediatria->id);

        $this->assertNotContains('espirometria', $trabajo->modulos($contexto, $trabajo->roles($user->fresh()))->pluck('slug')->all());
    }

    public function test_dos_especialidades_no_pueden_declarar_la_misma_subespecialidad(): void
    {
        $directorio = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manifiestos-' . uniqid();
        mkdir($directorio);

        file_put_contents($directorio . DIRECTORY_SEPARATOR . 'uno.php', "<?php return ['nombre' => 'Uno', "
            . "'subespecialidades' => ['misma' => ['nombre' => 'Misma']]];");
        file_put_contents($directorio . DIRECTORY_SEPARATOR . 'dos.php', "<?php return ['nombre' => 'Dos', "
            . "'subespecialidades' => ['misma' => ['nombre' => 'Misma']]];");

        $registro = new \App\Especialidades\RegistroEspecialidades($directorio);

        $this->assertNotEmpty($registro->conflictos());
        $this->assertStringContainsString("subespecialidad 'misma'", implode(' ', $registro->conflictos()));

        foreach (glob($directorio . DIRECTORY_SEPARATOR . '*') as $archivo) {
            unlink($archivo);
        }
        rmdir($directorio);
    }
}
