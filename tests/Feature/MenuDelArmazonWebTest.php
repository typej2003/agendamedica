<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El menú lateral del armazón** (O-32, 2026-10-17): pediatría ya **no** ofrece «Crecimiento» ni «Vacunas».
 *
 * Los dos son **entradas de un paciente**, no pantallas: su acción `entrada()` redirige a Pacientes («Elige al
 * niño y abre su ficha»), así que desde el menú parecían botones que no hacían nada. Se quitan del menú con la
 * lista `menu.ocultos` del manifiesto (`config/especialidades/pediatria.php`), **sin** sacarlos de `modulos`:
 * el módulo, sus rutas y sus pantallas siguen existiendo (los usa la historia, la consulta y los permisos).
 *
 * Lo que se fija acá:
 *  - con pediatría, el menú lateral no trae los dos ítems y sí el resto (Inicio, Agenda, Pacientes, Listados,
 *    Catálogos, Configuración y «Ayúdanos a mejorar»);
 *  - las dos rutas **siguen respondiendo** y redirigen como hoy;
 *  - los dos módulos **siguen implementados** (el filtro es del menú, no del catálogo de módulos);
 *  - ginecología, que no declara nada en `menu.ocultos`, no cambia.
 */
class MenuDelArmazonWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $slug = 'pediatria'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $especialidad = Specialty::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $regMedico = 'menu-test-' . uniqid();

        $user = User::create([
            'name' => 'Doctora de prueba', 'email' => 'menu-' . uniqid() . '@example.com',
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

    /** El HTML del menú lateral, para no confundir un ítem con un enlace del contenido de la pantalla. */
    private function barraLateral(string $html): string
    {
        preg_match('/<nav class="app-sidebar".*?<\/nav>/s', $html, $coincidencia);

        $this->assertNotEmpty($coincidencia, 'No se encontró la barra lateral en la pantalla.');

        return $coincidencia[0];
    }

    public function test_pediatria_no_ofrece_crecimiento_ni_vacunas_en_el_menu_lateral(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();

        $barra = $this->barraLateral(
            $this->conContexto($user, $medico, $pediatria)->get('/clinica')->assertOk()->getContent()
        );

        $this->assertStringNotContainsString(route('clinica.crecimiento'), $barra, 'El menú no debe enlazar la entrada de Crecimiento.');
        $this->assertStringNotContainsString(route('clinica.vacunas'), $barra, 'El menú no debe enlazar la entrada de Vacunas.');

        // El resto del menú queda igual: el núcleo, los grupos y la ayuda.
        foreach (['Inicio', 'Agenda', 'Pacientes', 'Listados', 'Catálogos', 'Configuración', 'Ayúdanos a mejorar'] as $item) {
            $this->assertStringContainsString($item, $barra, "Falta «{$item}» en el menú de pediatría.");
        }
    }

    public function test_las_rutas_de_crecimiento_y_vacunas_siguen_respondiendo_y_redirigen_como_hoy(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $pediatria)->get(route('clinica.crecimiento'))
            ->assertRedirect(route('clinica.pacientes'))
            ->assertSessionHas('estado');

        $this->conContexto($user, $medico, $pediatria)->get(route('clinica.vacunas'))
            ->assertRedirect(route('clinica.pacientes'))
            ->assertSessionHas('estado');
    }

    /**
     * El filtro es **del menú**, no del catálogo de módulos: pediatría sigue declarándolos e implementándolos
     * para la historia, la consulta y los permisos. El manifiesto declara los dos ocultos con su motivo.
     */
    public function test_los_dos_modulos_siguen_en_el_manifiesto_y_en_el_catalogo(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();

        $manifiesto = require config_path('especialidades/pediatria.php');
        $this->assertSame(['crecimiento', 'vacunas'], array_keys($manifiesto['menu']['ocultos']));
        $this->assertContains('crecimiento', $manifiesto['modulos']);
        $this->assertContains('vacunas', $manifiesto['modulos']);

        $implementados = $pediatria->modulosVisibles(['Medico'])->pluck('slug')->all();
        $this->assertContains('crecimiento', $implementados);
        $this->assertContains('vacunas', $implementados);
    }

    /** Ginecología no declara ocultos: su menú no cambia (el mecanismo no toca los ítems de otras especialidades). */
    public function test_ginecologia_conserva_su_menu(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso('ginecologia-y-obstetricia');

        $barra = $this->barraLateral(
            $this->conContexto($user, $medico, $gineco)->get('/clinica')->assertOk()->getContent()
        );

        foreach (['Inicio', 'Agenda', 'Pacientes', 'Listados', 'Catálogos', 'Configuración', 'Ayúdanos a mejorar'] as $item) {
            $this->assertStringContainsString($item, $barra, "Falta «{$item}» en el menú de ginecología.");
        }

        $this->assertStringNotContainsString(route('clinica.crecimiento'), $barra);
        $this->assertStringNotContainsString(route('clinica.vacunas'), $barra);
    }
}
