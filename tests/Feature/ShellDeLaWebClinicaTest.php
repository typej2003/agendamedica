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

    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'shell-web-' . uniqid();

        $user = User::create([
            'name'                 => 'Carlos Mendoza',
            'email'                => 'shell-web-' . uniqid() . '@example.com',
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
        [$user] = $this->medicoConAcceso();

        $this->actingAs($user)->get('/clinica/contexto')
            ->assertOk()
            ->assertSee('action="' . route('logout') . '"', false)
            ->assertSee('Cerrar sesión');
    }

    // ----------------------------------------------------------------------- Saltar al contenido

    public function test_cada_pantalla_ofrece_saltar_al_contenido(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

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
