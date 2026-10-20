<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * El **armazón** de la web clínica (`clinica/layout`): lo que tiene toda pantalla, sin importar cuál (revisión
 * de la web, 2026-10-11).
 *
 *  - **Cerrar sesión**: no había cómo salir desde el consultorio (el cierre solo existía en el panel de
 *    administración) ni cómo cambiar la contraseña. Va en un menú "Mi cuenta" del encabezado.
 *  - **Saltar al contenido**: el encabezado y el menú son una docena de enlaces antes del contenido de cada
 *    pantalla; con teclado o lector de pantalla hay que atravesarlos siempre.
 *  - **El buscador de pacientes** de la cita es un combobox: se escribe, aparece una lista y se elige una
 *    opción. Sin los roles, un lector de pantalla ni anuncia que hay una lista.
 *
 * El comportamiento del autocompletado en el navegador (la lista que abre y cierra) lo recorre
 * `.agent-tools/verificar-accesibilidad-web.js`; acá se guarda lo que el servidor entrega.
 */
class ShellDeLaWebClinicaTest extends TestCase
{
    use DatabaseTransactions;

    private function medicoConAcceso(string $slugEspecialidad = 'ginecologia-y-obstetricia'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', $slugEspecialidad)->firstOrFail();
        $regMedico = 'shell-web-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Carlos Mendoza',
            'email'                => 'shell-web-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Carlos',
            'lastname'   => 'Mendoza',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->actingAs($user)->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    /**
     * Le carga **dos** consultorios al médico, para que `/clinica/contexto` muestre la pantalla.
     *
     * Desde el 2026-10-19 esa pantalla **se salta** cuando el médico tiene una sola especialidad y uno o
     * ningún consultorio (redirige a `/clinica`), así que un test que la mire tiene que dejar algo que elegir.
     */
    private function conDosConsultorios(Medico $medico): void
    {
        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => City::firstOrFail()->state_id,
            'city_id'    => City::firstOrFail()->id,
            'name'       => 'Sede del armazón ' . substr(uniqid(), -6),
            'address'    => 'Av. Principal',
            'activo'     => true,
        ]);

        foreach (['701', '702'] as $numero) {
            Office::create([
                'medical_center_id' => $centro->id,
                'medico_id'         => $medico->id,
                'reg_medico'        => $medico->reg_medico,
                'office_number'     => $numero,
                'modalidad'         => Office::MODALIDAD_ORDEN,
                'duracion_cita'     => 30,
                'activo'            => true,
            ]);
        }
    }

    // ------------------------------------------------------------------------------- Cerrar sesión

    public function test_el_encabezado_ofrece_cambiar_la_contrasena_y_cerrar_sesion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->get('/clinica')
            ->assertOk()
            ->assertSee('aria-label="Mi cuenta"', false)
            ->assertSee('Carlos')
            ->assertSee('href="' . route('cuenta.password') . '"', false)
            ->assertSee('Cambiar contraseña')
            ->assertSee('action="' . route('logout') . '"', false)
            ->assertSee('Cerrar sesión');
    }

    public function test_cerrar_sesion_desde_el_consultorio_saca_al_usuario(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->post(route('logout'))->assertRedirect();

        $this->assertGuest();
    }

    /**
     * Elegir el consultorio es lo primero que ve quien entra: si esa pantalla no deja salir, un usuario con
     * la cuenta equivocada queda atrapado en ella.
     */
    public function test_tambien_se_puede_cerrar_sesion_desde_la_pantalla_de_elegir_consultorio(): void
    {
        [$user, $medico] = $this->medicoConAcceso();
        $this->conDosConsultorios($medico);

        $this->actingAs($user)->get('/clinica/contexto')
            ->assertOk()
            ->assertSee('action="' . route('logout') . '"', false)
            ->assertSee('Cerrar sesión');
    }

    // ------------------------------------------------------------------- Menú del consultorio

    /**
     * El menú del consultorio sale de los módulos **implementados** de la especialidad del contexto, y el
     * núcleo compartido (agenda y pacientes) es de **todas** (PLAN-WEB.md, R1).
     *
     * Medido el 2026-10-11: una especialidad del catálogo sin manifiesto (traumatología, pediatría,
     * medicina general…) no recibía ninguna fila de `especialidad_modulo`, así que su médico entraba a un
     * consultorio con el menú vacío y el aviso "No hay módulos habilitados en este consultorio todavía",
     * aunque tuviera la especialidad activa y su consultorio cargado. No depende del consultorio: acá el
     * contexto va sin sede y los accesos tienen que estar igual.
     */
    public function test_el_nucleo_del_menu_esta_en_cualquier_especialidad(): void
    {
        [$user, $medico, $traumatologia] = $this->medicoConAcceso('traumatologia-y-ortopedia');

        $this->conContexto($user, $medico, $traumatologia)->get('/clinica')
            ->assertOk()
            ->assertSee('Agenda')
            ->assertSee('Pacientes')
            ->assertDontSee('No hay módulos habilitados')
            ->assertDontSee('no tiene una especialidad asignada');
    }

    // ------------------------------------------------------------------- Logo de la especialidad

    /**
     * El logo de la especialidad del consultorio, el mismo del escritorio de esa especialidad, va en la barra
     * lateral **encima del pie**, que es la barra donde está el botón de plegar.
     */
    public function test_la_barra_lateral_muestra_el_logo_de_la_especialidad_encima_del_pie(): void
    {
        $conLogo = [
            'pediatria' => 'pediatria.jpg',
            'ginecologia-y-obstetricia' => 'ginecologia-y-obstetricia.jpg',
        ];

        foreach ($conLogo as $slug => $archivo) {
            [$user, $medico, $especialidad] = $this->medicoConAcceso($slug);

            $html = $this->conContexto($user, $medico, $especialidad)->get('/clinica')->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/<div class="app-sidebar-logo">\s*<img src="' . preg_quote(asset('img/especialidades/' . $archivo), '/') . '"[^>]*alt="' . preg_quote($especialidad->name, '/') . '"/s',
                $html,
                "Falta el logo de {$especialidad->name} en la barra lateral.",
            );

            // Encima del pie: el logo se dibuja antes de la barra del botón de plegar.
            $this->assertLessThan(strpos($html, 'app-sidebar-pie'), strpos($html, 'app-sidebar-logo'));
        }
    }

    /**
     * Una especialidad que todavía no tiene su logo no dibuja nada: ni la imagen ni un hueco vacío. Hoy lo
     * tienen pediatría y ginecología y obstetricia (`public/img/especialidades/<slug>.jpg`).
     */
    public function test_una_especialidad_sin_logo_no_dibuja_nada_en_la_barra(): void
    {
        [$user, $medico, $traumatologia] = $this->medicoConAcceso('traumatologia-y-ortopedia');

        $this->conContexto($user, $medico, $traumatologia)->get('/clinica')
            ->assertOk()
            ->assertDontSee('app-sidebar-logo');
    }

    /** Con la barra plegada a 72 px el logo no entra: se esconde, como el nombre de la marca. */
    public function test_el_logo_se_esconde_con_la_barra_plegada(): void
    {
        $css = file_get_contents(public_path('css/doctorisimo-ui.css'));

        $this->assertMatchesRegularExpression(
            '/\.app-sidebar\.minimizado \.app-sidebar-logo[^{]*\{[^}]*display:\s*none/s',
            $css,
            'El logo tiene que apagarse con la barra plegada.',
        );
    }

    public function test_cada_pantalla_ofrece_saltar_al_contenido(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        // La pantalla de elegir consultorio sólo existe si hay algo que elegir (2026-10-19).
        $this->conDosConsultorios($medico);

        foreach (['/clinica', '/clinica/pacientes', '/clinica/agenda', '/clinica/contexto'] as $ruta) {
            $respuesta = $this->conContexto($user, $medico, $gineco)->get($ruta)->assertOk();

            $respuesta->assertSee('<a class="saltar-al-contenido" href="#contenido-principal">Saltar al contenido</a>', false);
            // El destino existe y puede recibir el foco (`tabindex="-1"`), si no el salto no mueve el teclado.
            $respuesta->assertSee('id="contenido-principal" tabindex="-1"', false);
        }
    }

    public function test_el_enlace_de_saltar_es_el_primero_de_la_pagina_y_sale_de_pantalla_sin_foco(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $html = $this->conContexto($user, $medico, $gineco)->get('/clinica')->getContent();

        // Primero en el orden de tabulación: antes que cualquier otro enlace del encabezado o del menú.
        $this->assertLessThan(strpos($html, '<header'), strpos($html, 'saltar-al-contenido'));

        $css = file_get_contents(public_path('css/doctorisimo-ui.css'));
        $this->assertMatchesRegularExpression('/\.saltar-al-contenido\s*\{[^}]*top:\s*-4rem/s', $css, 'Fuera de pantalla hasta recibir el foco.');
        $this->assertMatchesRegularExpression('/\.saltar-al-contenido:focus\s*\{[^}]*top:\s*\.75rem/s', $css, 'Visible al recibir el foco.');
    }

    // ------------------------------------------------------------------- Buscador de pacientes

    public function test_el_buscador_de_pacientes_de_la_cita_es_un_combobox_con_su_lista(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $html = $this->conContexto($user, $medico, $gineco)->get('/clinica/agenda/nueva')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="paciente-busqueda"[^>]*role="combobox"[^>]*aria-autocomplete="list"[^>]*aria-expanded="false"/s',
            $html,
        );
        $this->assertMatchesRegularExpression('/<input[^>]*id="paciente-busqueda"[^>]*aria-controls="paciente-resultados"/s', $html);
        $this->assertMatchesRegularExpression('/<div[^>]*id="paciente-resultados"[^>]*role="listbox"/s', $html);
    }

    /** El script es el que abre y cierra la lista: tiene que mantener `aria-expanded` y marcar cada opción. */
    public function test_el_script_de_la_cita_mantiene_aria_expanded_y_marca_las_opciones(): void
    {
        $js = file_get_contents(public_path('js/clinica/cita-formulario.js'));

        $this->assertStringContainsString("setAttribute('aria-expanded', 'true')", $js);
        $this->assertStringContainsString("setAttribute('aria-expanded', 'false')", $js);
        $this->assertStringContainsString("setAttribute('role', 'option')", $js);
        $this->assertStringContainsString("'Escape'", $js, 'Escape cierra la lista sin elegir.');
    }

    /**
     * En el buscador del día cada resultado es una fila con su botón "Hacer cita", no una opción que se
     * elige: se anuncia como lista (no como combobox) y el conteo de resultados se anuncia solo.
     */
    public function test_el_buscador_del_dia_se_anuncia_como_lista_y_avisa_cuantos_hay(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $html = $this->conContexto($user, $medico, $gineco)->get('/clinica/agenda')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="agenda-paciente-texto"[^>]*role="combobox"/s', $html);
        $this->assertMatchesRegularExpression('/<div[^>]*id="agenda-paciente-resultados"[^>]*role="list"/s', $html);
        $this->assertMatchesRegularExpression('/<p[^>]*id="agenda-paciente-ayuda"[^>]*role="status"/s', $html);
    }
}
