<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Cuentas;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\RecipeFormato;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **La impresión con el formato del centro médico y la firma del doctor** (Fase 5 del cambio de `reg_medico`).
 *
 * La decisión del usuario, que es lo que estos casos fijan:
 *
 *  - el **membrete, el logo y el pie** salen del **formato** que elige el centro (`medicos.formato_reportes`): el del
 *    **centro** —sus filas de `recipe_formatos` y `evolucion` bajo su `reg_medico`— o el del **doctor**, como hasta
 *    ahora. En el consultorio personal siempre el del doctor;
 *  - **la firma y el sello son siempre del doctor que atendió**, nunca los del centro;
 *  - la **secretaría** imprime desde la cita atendida con el doctor asignado (`cola.medico_id`), y el formato sale del
 *    `reg_medico` de la cita;
 *  - la **historia** del paciente usa el formato del consultorio de la historia;
 *  - la **pantalla** `/clinica/formato` deja que un centro configure **su** formato, y el `Root` la abre para un centro
 *    con `?reg_medico=` desde el panel de cuentas; la del doctor sigue en su registro personal.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ImpresionConElFormatoDelCentroWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fijarHoy();
        Storage::fake('public');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Evolucion::flushEventListeners();
        RecipeFormato::flushEventListeners();
        $this->soltarHoy();

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------ Ayudas

    /** El logo, la firma y el sello que se imprimen (y los que no). */
    private function archivos(): void
    {
        foreach (['logos/centro.png', 'recipe/firmas/doctor.png', 'recipe/sellos/doctor.png'] as $ruta) {
            Storage::disk('public')->put($ruta, 'png');
        }
    }

    /**
     * El escenario completo de un **centro**: su formato y el de un doctor que trabaja en él, un paciente con su
     * consulta y una cita atendida con el doctor asignado.
     *
     * @param  string  $formatoDelCentro  lo que el centro eligió en `medicos.formato_reportes`
     * @return array{doctor:Medico, centro:Medico, secretaria:User, doctorUser:User, cita:Cola, consulta:Consulta, paciente:int, gineco:\App\Models\Specialty}
     */
    private function escenarioDelCentro(string $formatoDelCentro = Medico::FORMATO_DEL_CENTRO, array $citaExtra = []): array
    {
        $this->archivos();

        [$doctorUser, $doctor, $gineco] = $this->medicoConAcceso();

        $centro = Medico::create([
            'name'             => 'Centro',
            'lastname'         => 'Médico',
            'reg_medico'       => 'centro-' . substr(uniqid(), -8),
            'es_centro'        => true,
            'formato_reportes' => $formatoDelCentro,
            'is_active'        => true,
        ]);

        // El doctor trabaja en el centro y la secretaría entra con el centro como contexto.
        MedicoRegistro::create(['medico_id' => $doctor->id, 'reg_medico' => $centro->reg_medico]);
        $secretaria = $this->secretariaConAcceso($centro);

        // **El formato del centro**: su membrete, su logo, su pie y el aspecto de sus bloques.
        $this->membrete($centro, [
            'especialidad' => 'Servicio del centro',
            'rif'          => 'J-CENTRO-1',
            'linea_1'      => 'Pie del centro',
            'linea_2'      => '0251-0000000',
            'linea_3'      => 'centro@example.com',
            'logo'         => 'logos/centro.png',
        ], [
            'color_linea' => 'azul',
            'elementos'   => json_encode(['medico' => ['alineacion' => 'derecha']]),
        ]);

        // **El formato del doctor**: su membrete (que no se imprime con el formato del centro) y **su firma y su sello**,
        // que se imprimen siempre. La `clave` y el `correo_med` son los que el escritorio deja en `cola.medico`.
        $this->membrete($doctor, [
            'correo_med' => $doctor->email,
            'clave'      => 7,
        ], [
            'firma'          => 'recipe/firmas/doctor.png',
            'sello'          => 'recipe/sellos/doctor.png',
            'sello_posicion' => 'izquierda',
        ]);

        $this->vademecum($centro);
        [$pacienteId, $consulta] = $this->pacienteYConsulta($centro, 8801, 1, ['fnacimiento' => '1990-10-06']);
        $this->recipe($centro, 8801, 1, 1, [['0001', 2, 'Una cápsula cada 6 horas.']], '2026-10-05');

        $cita = $this->cita($centro, 8801, '2026-10-07', array_merge([
            'atendido'  => 1,
            'estado'    => 1,
            'medico_id' => $doctor->id,
            'medico'    => 7,
        ], $citaExtra));

        return compact('doctor', 'centro', 'secretaria', 'doctorUser', 'cita', 'consulta', 'gineco') + ['paciente' => $pacienteId];
    }

    private function como(User $user, Medico $medico, $specialty): self
    {
        return $this->conContexto($user, $medico, $specialty)->actingAs($user);
    }

    /** La hoja del récipe 1 de la cita atendida, que es lo que imprime la secretaría. */
    private function hojaDeLaCita(User $user, Medico $contexto, $specialty, Cola $cita): string
    {
        return $this->como($user, $contexto, $specialty)
            ->get(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => 'recipe-1']))
            ->assertOk()
            ->getContent();
    }

    /** Un usuario nuevo con ese rol, que es lo que el panel y la pantalla del formato miran. */
    private function usuario(string $rol): User
    {
        $user = User::create([
            'name'                 => 'Usuario ' . $rol,
            'email'                => 'centro-' . strtolower($rol) . '-' . substr(uniqid(), -6) . '@example.com',
            'password'             => bcrypt('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    // -------------------------------------------------------------------- El formato del centro

    public function test_con_formato_del_centro_el_membrete_es_el_del_centro_y_la_firma_la_del_doctor(): void
    {
        $datos = $this->escenarioDelCentro();

        $html = $this->hojaDeLaCita($datos['secretaria'], $datos['centro'], $datos['gineco'], $datos['cita']);

        // **El membrete, el logo y el pie, del centro.**
        $this->assertStringContainsString('Centro Médico', $html);
        $this->assertStringContainsString('Servicio del centro', $html);
        $this->assertStringContainsString('J-CENTRO-1', $html);
        $this->assertStringContainsString('Pie del centro', $html);
        $this->assertStringContainsString('/storage/logos/centro.png', $html);
        $this->assertStringContainsString('linea-azul', $html);
        $this->assertMatchesRegularExpression('/membrete-medico[^"]*alin-derecha/', $html);

        // **La firma y el sello, del doctor que atendió** (no los del centro, que no tiene).
        $this->assertStringContainsString('/storage/recipe/firmas/doctor.png', $html);
        $this->assertStringContainsString('/storage/recipe/sellos/doctor.png', $html);
        $this->assertStringContainsString('sello-izquierda', $html);
        // El nombre sobre la línea de la firma es el del doctor; el del centro quedó arriba, en el membrete.
        $this->assertStringContainsString('firma-nombre">Dra. Ana Imprime', $html);
        $this->assertStringNotContainsString('firma-nombre">Centro Médico', $html);
        $this->assertSame(2, substr_count($html, 'Dra. Ana Imprime'), 'Firma las dos mitades de la hoja.');

        // **El membrete del doctor no se mezcla**: con el formato del centro no sale nada de su `evolucion`.
        $this->assertStringNotContainsString('Ginecología y Obstetricia', $html);
        $this->assertStringNotContainsString('J-12345678-9', $html);
        $this->assertStringNotContainsString('Av. Principal, Torre Salud', $html);
    }

    public function test_con_formato_medico_el_centro_imprime_con_el_formato_del_doctor(): void
    {
        $datos = $this->escenarioDelCentro(Medico::FORMATO_DEL_MEDICO);

        $html = $this->hojaDeLaCita($datos['secretaria'], $datos['centro'], $datos['gineco'], $datos['cita']);

        // El formato del doctor: su especialidad, su RIF y su pie.
        $this->assertStringContainsString('Ginecología y Obstetricia', $html);
        $this->assertStringContainsString('J-12345678-9', $html);
        $this->assertStringContainsString('Av. Principal, Torre Salud', $html);
        // Y su firma y su sello, igual que con el formato del centro.
        $this->assertStringContainsString('/storage/recipe/firmas/doctor.png', $html);
        $this->assertStringContainsString('sello-izquierda', $html);
        // Nada del centro: ni su membrete, ni su logo, ni su color de línea.
        $this->assertStringNotContainsString('Servicio del centro', $html);
        $this->assertStringNotContainsString('J-CENTRO-1', $html);
        $this->assertStringNotContainsString('/storage/logos/centro.png', $html);
        $this->assertStringNotContainsString('linea-azul', $html);
    }

    public function test_sin_el_formato_elegido_tambien_imprime_el_del_doctor(): void
    {
        $datos = $this->escenarioDelCentro('');
        $datos['centro']->forceFill(['formato_reportes' => null])->save();

        $html = $this->hojaDeLaCita($datos['secretaria'], $datos['centro'], $datos['gineco'], $datos['cita']);

        $this->assertStringContainsString('J-12345678-9', $html);
        $this->assertStringNotContainsString('J-CENTRO-1', $html);
    }

    public function test_el_doctor_que_atiende_en_el_centro_imprime_con_el_membrete_del_centro_y_su_firma(): void
    {
        $datos = $this->escenarioDelCentro();

        // El doctor imprime desde la consulta, con el centro en su contexto (Fase 2: el médico del contexto es él).
        $html = $this->como($datos['doctorUser'], $datos['centro'], $datos['gineco'])
            ->get(route('clinica.consulta.recipes.imprimir', [$datos['consulta']->id, 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Servicio del centro', $html);
        $this->assertStringContainsString('/storage/recipe/firmas/doctor.png', $html);
        $this->assertStringContainsString('firma-nombre">Dra. Ana Imprime', $html);
        $this->assertStringNotContainsString('J-12345678-9', $html);
    }

    public function test_sin_doctor_asignado_la_cita_usa_el_membrete_del_centro_y_no_inventa_una_firma(): void
    {
        // La cita de un centro que el escritorio atendió sin doctor: `medico_id` nulo y, en `cola.medico`, el
        // `medicos.id` del centro (Fase 3). No hay doctor que firmar, así que firma el del contexto (el centro).
        $datos = $this->escenarioDelCentro(Medico::FORMATO_DEL_CENTRO, ['medico_id' => null]);
        // En `cola.medico` queda el `medicos.id` del centro (Fase 3), no una clave de `evolucion`.
        $datos['cita']->forceFill(['medico' => $datos['centro']->id])->save();

        $html = $this->hojaDeLaCita($datos['secretaria'], $datos['centro'], $datos['gineco'], $datos['cita']);

        $this->assertStringContainsString('Servicio del centro', $html);
        $this->assertStringContainsString('firma-nombre">Centro Médico', $html);
        $this->assertStringNotContainsString('/storage/recipe/firmas/doctor.png', $html);
    }

    public function test_la_firma_sale_del_doctor_aunque_la_cita_solo_tenga_su_clave_legacy(): void
    {
        $datos = $this->escenarioDelCentro(Medico::FORMATO_DEL_CENTRO, ['medico_id' => null]);

        // Sin `medico_id`: la clave 7 de `evolucion` bajo el `reg_medico` de la cita es la del doctor.
        $html = $this->hojaDeLaCita($datos['secretaria'], $datos['centro'], $datos['gineco'], $datos['cita']);

        $this->assertStringContainsString('firma-nombre">Dra. Ana Imprime', $html);
        $this->assertStringContainsString('/storage/recipe/firmas/doctor.png', $html);
    }

    public function test_el_qr_del_recipe_queda_avalado_por_el_doctor_y_no_por_el_centro(): void
    {
        $datos = $this->escenarioDelCentro();

        $this->hojaDeLaCita($datos['secretaria'], $datos['centro'], $datos['gineco'], $datos['cita']);

        $verificacion = DB::table('recipe_verificaciones')->where('reg_medico', $datos['centro']->reg_medico)->first();

        $this->assertNotNull($verificacion, 'Imprimir el récipe le crea su QR de verificación.');
        $this->assertSame($datos['doctor']->id, (int) $verificacion->medico_id);
    }

    // ------------------------------------------------------------------------- La historia del paciente

    public function test_la_historia_imprime_el_encabezado_del_formato_del_centro(): void
    {
        $datos = $this->escenarioDelCentro();

        $html = $this->como($datos['secretaria'], $datos['centro'], $datos['gineco'])
            ->get(route('clinica.pacientes.historia.imprimir', $datos['paciente']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('class="membrete">Centro Médico', $html);
        $this->assertStringNotContainsString('>Dra. Ana Imprime<', $html);
    }

    public function test_la_historia_de_un_consultorio_personal_sigue_con_el_nombre_del_doctor(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->membrete($medico);
        [$paciente] = $this->pacienteYConsulta($medico, 8802, 1);

        $html = $this->como($user, $medico, $gineco)
            ->get(route('clinica.pacientes.historia.imprimir', $paciente))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('class="membrete">Dra. Ana Imprime', $html);
    }

    // ------------------------------------------------------------------- La pantalla del formato

    public function test_el_root_abre_el_formato_de_un_centro_con_el_parametro(): void
    {
        $datos = $this->escenarioDelCentro();
        $root = $this->usuario('Root');

        // El centro no inicia sesión: el `Root` lo abre desde el panel, sin contexto propio.
        $respuesta = $this->actingAs($root)->get(route('clinica.formato', ['reg_medico' => $datos['centro']->reg_medico]));

        $respuesta->assertOk();
        $respuesta->assertSee('Formato de impresión');
        // Lo guardado es del centro, y se ve.
        $respuesta->assertSee('Centro Médico');
        $respuesta->assertSee('Servicio del centro');
        $respuesta->assertSee('J-CENTRO-1');
        // El aviso dice de quién es el formato y quién firma.
        $respuesta->assertSee('Este es el formato con el que imprime el centro', false);
        $respuesta->assertSee('La firma y el sello de cada reporte son los del doctor que atendió', false);
        // **La firma y el sello no se ofrecen**: son del doctor.
        $respuesta->assertDontSee('Firma y sello');
        $respuesta->assertDontSee('Dónde va el sello');
        // Las secciones que sí se editan llevan el parámetro, para guardar bajo el centro.
        $respuesta->assertSee('reg_medico=' . $datos['centro']->reg_medico, false);
    }

    public function test_el_formato_del_centro_se_guarda_bajo_el_registro_del_centro(): void
    {
        $datos = $this->escenarioDelCentro();
        $root = $this->usuario('Root');
        $centro = $datos['centro']->reg_medico;

        $this->actingAs($root)
            ->post(route('clinica.formato.membrete', ['reg_medico' => $centro]), [
                'especialidad' => 'Centro de diagnóstico',
                'rif'          => 'J-NUEVO-1',
                'linea_1'      => 'Nueva dirección del centro',
                'linea_2'      => null,
                'linea_3'      => null,
            ])
            ->assertRedirect(route('clinica.formato', ['reg_medico' => $centro]) . '#formato-membrete')
            ->assertSessionHas('estado');

        $this->assertSame('Centro de diagnóstico', Evolucion::where('reg_medico', $centro)->value('especialidad'));
        // El membrete del doctor no se toca.
        $this->assertSame('Ginecología y Obstetricia', Evolucion::where('reg_medico', $datos['doctor']->reg_medico)->value('especialidad'));

        // El estilo (el aspecto de los bloques y el color de la línea) también va a la fila del centro.
        $this->actingAs($root)
            ->post(route('clinica.formato.estilo', ['reg_medico' => $centro]), [
                'color_linea' => 'verde',
                'elementos'   => ['medico' => ['alineacion' => 'centro', 'fuente' => 'tinos']],
            ])
            ->assertRedirect(route('clinica.formato', ['reg_medico' => $centro]) . '#formato-estilo');

        $this->assertSame('verde', RecipeFormato::where('reg_medico', $centro)->value('color_linea'));
    }

    public function test_la_firma_y_el_sello_no_se_configuran_en_el_formato_de_un_centro(): void
    {
        $datos = $this->escenarioDelCentro();
        $root = $this->usuario('Root');

        $this->actingAs($root)
            ->from(route('clinica.formato', ['reg_medico' => $datos['centro']->reg_medico]))
            ->post(route('clinica.formato.firma', ['reg_medico' => $datos['centro']->reg_medico]), [
                'firma'          => \Illuminate\Http\UploadedFile::fake()->image('firma.png'),
                'sello_posicion' => 'centro',
            ])
            ->assertRedirect(route('clinica.formato', ['reg_medico' => $datos['centro']->reg_medico]) . '#formato-firma')
            ->assertSessionHas('error');

        $this->assertNull(RecipeFormato::where('reg_medico', $datos['centro']->reg_medico)->value('firma'));
    }

    public function test_el_formato_de_un_centro_solo_lo_abre_el_root(): void
    {
        $datos = $this->escenarioDelCentro();
        $url = route('clinica.formato', ['reg_medico' => $datos['centro']->reg_medico]);

        // El doctor, aunque trabaje en el centro, no edita el formato del centro desde su contexto.
        $this->como($datos['doctorUser'], $datos['centro'], $datos['gineco'])->get($url)->assertForbidden();
        // Y la secretaría tampoco entra a la pantalla (es de `role:Root|Medico`).
        $this->como($datos['secretaria'], $datos['centro'], $datos['gineco'])->get($url)->assertForbidden();
    }

    public function test_por_esa_puerta_no_se_edita_el_formato_de_un_doctor(): void
    {
        $datos = $this->escenarioDelCentro();
        $root = $this->usuario('Root');

        $this->actingAs($root)
            ->get(route('clinica.formato', ['reg_medico' => $datos['doctor']->reg_medico]))
            ->assertNotFound();
    }

    public function test_el_root_sin_contexto_ni_centro_elegido_va_a_elegir_consultorio(): void
    {
        $root = $this->usuario('Root');

        $this->actingAs($root)->get(route('clinica.formato'))->assertRedirect(route('clinica.contexto'));
    }

    public function test_el_contexto_de_un_centro_abre_su_propio_formato_sin_parametro(): void
    {
        $datos = $this->escenarioDelCentro();
        // Un centro con cuenta propia (el caso raro): su contexto ya es el centro y la pantalla edita el suyo.
        $root = $this->usuario('Root');
        MedicoRegistro::create(['user_id' => $root->id, 'reg_medico' => $datos['centro']->reg_medico]);

        $this->como($root, $datos['centro'], $datos['gineco'])
            ->get(route('clinica.formato'))
            ->assertOk()
            ->assertSee('Servicio del centro')
            ->assertDontSee('Firma y sello');
    }

    public function test_la_hoja_de_prueba_del_centro_es_la_del_centro(): void
    {
        $datos = $this->escenarioDelCentro();
        $root = $this->usuario('Root');

        $html = $this->actingAs($root)
            ->get(route('clinica.formato.vista-previa', ['reg_medico' => $datos['centro']->reg_medico, 'incrustada' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Servicio del centro', $html);
        $this->assertStringContainsString('Centro Médico', $html);
        $this->assertStringContainsString('linea-azul', $html);
    }

    public function test_los_formatos_en_blanco_del_centro_salen_con_su_membrete(): void
    {
        $datos = $this->escenarioDelCentro();
        $root = $this->usuario('Root');

        $html = $this->actingAs($root)
            ->get(route('clinica.formato.en-blanco', ['formato' => 'recipe', 'reg_medico' => $datos['centro']->reg_medico]))
            ->assertOk()
            ->getContent();

        // El formato en blanco no tiene doctor que firme: sale con el membrete del centro.
        $this->assertStringContainsString('Servicio del centro', $html);
        $this->assertStringContainsString('/storage/logos/centro.png', $html);
    }

    public function test_el_panel_de_cuentas_enlaza_el_formato_de_un_centro(): void
    {
        $datos = $this->escenarioDelCentro();
        $root = $this->usuario('Root');

        Livewire::actingAs($root)->test(Cuentas::class)
            ->assertOk()
            ->assertSee('Formato de impresión del centro')
            ->assertSee(route('clinica.formato', ['reg_medico' => $datos['centro']->reg_medico]), false);
    }
}
