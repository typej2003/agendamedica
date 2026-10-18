<?php

namespace Tests\Feature;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Models\Consulta;
use App\Models\FormatoPrint;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
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
 * **Las plantillas del formato del eco** (O-17, revisión de Alexander del 2026-10-17).
 *
 * Lo que fijan estos casos:
 *
 *  - la plantilla guarda los **campos descriptivos** del estudio (opciones, textos y áreas) y **no** las
 *    medidas ni las fechas, que son de cada paciente;
 *  - se guarda en `formato_print` con la columna `formato` (la clave del estudio) y `tipo` en `NULL`, así que
 *    **las plantillas de texto siguen funcionando** y la pantalla de administración no cambia;
 *  - el panel dice qué hace y qué campos toca, y avisa que no pisa lo escrito;
 *  - un estudio sin campos descriptivos no se guarda como plantilla, y un mismo título se actualiza en vez de
 *    duplicarse;
 *  - el alta deja su rastro en `sync_changes` (`source = web`), que es lo que impide que el escritorio borre
 *    la fila cuando sube `formato_print` entera.
 *
 * La **aplicación** (sólo completar los vacíos) es del navegador: la regla está en
 * `PlantillasDelFormato::aplicar()` y se prueba en `tests/Unit/Clinica/PlantillasDelFormatoTest.php`; acá se
 * comprueba que el panel recibe los valores y la frase, y que la pantalla no pisa nada al aplicar.
 */
class PlantillasDelFormatoWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::parse('2026-10-06 09:00:00');
        Carbon::setTestNow($this->hoy->copy());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'formato-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'formato-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Formato',
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
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    /** @return array{0:int, 1:Consulta} */
    private function pacienteYConsulta(Medico $medico, int $numhistoria = 1001, int $nroconsulta = 1): array
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres'     => 'Ana',
            'apellidos'   => 'Alvarez',
            'cedula'      => (string) random_int(10000000, 30000000),
            'sexo'        => 'F',
            'fnacimiento' => '1990-05-04',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $pacienteId,
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => (string) $numhistoria,
        ]);

        Historia::create([
            'numhistoria' => (string) $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'paciente_id' => $pacienteId,
            'medico_id'   => $medico->id,
        ]);

        $consulta = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'nroconsulta' => $nroconsulta,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        return [$pacienteId, $consulta];
    }

    private function urlSeccion(Consulta $consulta, string $tipo = 'ginecologico'): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO, 'tipo' => $tipo]);
    }

    private function urlPlantilla(Consulta $consulta, string $tipo = 'ginecologico'): string
    {
        return route('clinica.consulta.estudio.plantilla.guardar', [$consulta->id, 'tipo' => $tipo]);
    }

    /** Los valores de un formato de eco ginecológico: dos descriptivos, una medida y una fecha. */
    private function valores(): array
    {
        return [
            'posicionavf'    => '1',
            'bordeutero'     => '2',
            'observacion'    => 'Anexos sin particularidades.',
            'observacionovario' => 'Sin hallazgos.',
            'sugerencias'    => 'Útero en anteversoflexión.',
            'cuerpo'         => '62',
            'volumen_der'    => '4.2',
            'fecha'          => '2026-10-06',
            'fur'            => '2026-06-01',
        ];
    }

    // ------------------------------------------------------------------------------------ La pantalla

    public function test_el_panel_del_formato_dice_que_hace_y_que_campos_toca(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk()
            ->assertSee('Plantillas del formato')
            // La frase que pide O-17: aplica a los campos vacíos y no pisa lo escrito.
            ->assertSee('Aplica la plantilla a los campos vacíos')
            ->assertSee('No toca las medidas ni las fechas')
            ->assertSee('No guarda ni cambia las medidas ni las fechas')
            // Los bloques que toca, dichos en la pantalla.
            ->assertSee('Toca los campos de')
            ->assertSee('Útero')
            // A dónde guarda la plantilla nueva.
            ->assertSee($this->urlPlantilla($consulta), false)
            ->assertSee('data-formato-campos', false);
    }

    // ------------------------------------------------------------------------------------ El guardado

    public function test_guardar_una_plantilla_del_formato_guarda_solo_los_campos_descriptivos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->postJson($this->urlPlantilla($consulta), [
                'titulo'  => 'Eco normal',
                'valores' => $this->valores(),
            ]);

        $respuesta->assertCreated()->assertJsonPath('titulo', 'ECO NORMAL');

        $fila = FormatoPrint::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $this->assertSame('ginecologico', $fila->formato);
        $this->assertNull($fila->tipo);
        $this->assertSame('ECO NORMAL', $fila->titulo);
        // El código es el correlativo de cuatro dígitos del médico, como en las plantillas de texto.
        $this->assertSame('0001', $fila->codigo);

        $guardado = json_decode((string) $fila->descripcion, true)['campos'];

        // Los descriptivos están…
        $this->assertSame('1', $guardado['posicionavf']);
        $this->assertSame('Anexos sin particularidades.', $guardado['observacion']);
        $this->assertSame('Útero en anteversoflexión.', $guardado['sugerencias']);
        // …y las medidas y las fechas **no** (son de cada paciente).
        $this->assertArrayNotHasKey('cuerpo', $guardado);
        $this->assertArrayNotHasKey('volumen_der', $guardado);
        $this->assertArrayNotHasKey('fecha', $guardado);
        $this->assertArrayNotHasKey('fur', $guardado);

        // El rastro: `created` bajo `formato_print` (la tabla que el escritorio sube entera).
        $this->assertSame(1, DB::table('sync_changes')
            ->where('table_name', 'formato_print')
            ->where('operation', 'created')
            ->where('source', 'web')
            ->count());
    }

    public function test_el_panel_lista_la_plantilla_guardada_con_sus_valores(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->postJson($this->urlPlantilla($consulta), ['titulo' => 'Eco normal', 'valores' => $this->valores()])
            ->assertCreated();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk()
            ->assertSee('ECO NORMAL')
            ->assertSee('data-formato-valores', false)
            // El valor de las opciones y el texto de los anexos viajan al panel para completar los vacíos.
            ->assertSee('Anexos sin particularidades.')
            ->assertSee('Plantillas del formato <span data-formato-cuenta>(1)', false);
    }

    public function test_un_mismo_titulo_actualiza_la_plantilla_en_vez_de_duplicarla(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->postJson($this->urlPlantilla($consulta), ['titulo' => 'Eco normal', 'valores' => $this->valores()])
            ->assertCreated();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->postJson($this->urlPlantilla($consulta), [
                'titulo'  => 'eco normal',
                'valores' => ['sugerencias' => 'Útero en retroversoflexión.'],
            ])
            ->assertCreated();

        $this->assertSame(1, FormatoPrint::where('reg_medico', $medico->reg_medico)->count());

        $fila = FormatoPrint::where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame('Útero en retroversoflexión.', json_decode((string) $fila->descripcion, true)['campos']['sugerencias']);
    }

    public function test_un_estudio_sin_campos_descriptivos_no_se_guarda_como_plantilla(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->postJson($this->urlPlantilla($consulta), [
                'titulo'  => 'Sólo medidas',
                'valores' => ['cuerpo' => '62', 'fecha' => '2026-10-06'],
            ]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('ningún campo descriptivo', (string) $respuesta->json('mensaje'));
        $this->assertSame(0, FormatoPrint::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_guardar_sin_titulo_lo_dice_en_espanol(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->postJson($this->urlPlantilla($consulta), ['titulo' => '', 'valores' => $this->valores()])
            ->assertStatus(422)
            ->assertJsonPath('errors.titulo.0', 'Escribe el título de la plantilla.');
    }

    // ------------------------------------------------------- Las plantillas de texto no se rompen (O-17)

    public function test_las_plantillas_de_texto_siguen_funcionando_y_no_se_mezclan(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Una plantilla de TEXTO del tipo del eco ginecológico (la que se copia al campo del informe).
        FormatoPrint::create([
            'reg_medico'  => $medico->reg_medico,
            'codigo'      => '0001',
            'titulo'      => 'TEXTO DEL ESCRITORIO',
            'descripcion' => 'Útero en anteversoflexión.',
            'tipo'        => 'UG',
        ]);

        // Y una del FORMATO, del mismo estudio.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->postJson($this->urlPlantilla($consulta), ['titulo' => 'Eco normal', 'valores' => $this->valores()])
            ->assertCreated();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        // La de texto se sigue ofreciendo en su cajón, con su tipo y su destino.
        $respuesta->assertOk()
            ->assertSee('TEXTO DEL ESCRITORIO')
            ->assertSee('data-tipo="UG"', false)
            ->assertSee('data-destino="#observacion"', false);

        // Y la pantalla de administración sigue mostrando la de texto y no la del formato.
        $catalogo = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.catalogos.plantillas-texto', ['filtro' => 'UG']));

        $catalogo->assertOk()->assertSee('TEXTO DEL ESCRITORIO')->assertDontSee('ECO NORMAL');
    }
}
