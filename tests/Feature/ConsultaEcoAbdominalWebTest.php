<?php

namespace Tests\Feature;

use App\Clinica\Consulta\EcoAbdominal;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\UltraAbdominal;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El eco abdominal de la consulta** (WEB-6.4, ítem 5 del panel del legado), dentro del shell: el séptimo y
 * último tipo de la sección.
 *
 * Lo que estos casos fijan:
 *
 *  - **La clave del legado**: una fila por consulta, por `historia` + `consulta` (`d_ultasonido_abdominal.srd`
 *    y `d_ultasonido_abdominal_print.srd`), con `fecha` propuesta en hoy. Es la misma trampa de los otros ecos:
 *    buscar por el id de la consulta dejaría la fila huérfana.
 *  - **Los cuatro desplegables con sus códigos** (`Si→N`/`No→A` y `Normal→N`/`Anormal→A`) y el desplegable de
 *    médicos que guarda la cédula del colega.
 *  - **`prostata` y `vejiga_urina` no se tocan**: son de urología y en ginecología van ocultas (decisión del
 *    usuario, 2026-10-14); una fila que las tuviera cargadas las conserva.
 *  - **Nada de filas vacías**: un estudio sin ningún dato (la fecha sola no cuenta) no es un documento y no se
 *    guarda.
 *  - El **404** de la consulta de otro consultorio, el **403** de la secretaría y el invitado al login.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultaEcoAbdominalWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::parse('2026-10-14 09:00:00');
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
        $regMedico = 'abdominal-tes' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'abdominal-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Abdominal',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function secretariaConAcceso(Medico $medico): User
    {
        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'abdominal-sec' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        return $secretaria;
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

    private function urlSeccion(Consulta $consulta, string $tipo = 'abdominal'): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO, 'tipo' => $tipo]);
    }

    private function urlGuardar(Consulta $consulta, string $tipo = 'abdominal'): string
    {
        return route('clinica.consulta.estudio.guardar', [$consulta->id, $tipo]);
    }

    /**
     * Lo que manda el formulario: **las 15 columnas**, vacías las que no se cargaron. Es lo que hace el
     * navegador (el `formulario()` del eco pélvico), y las de urología **no** viajan: la pantalla no las ofrece.
     *
     * @param  array<string,string>  $valores
     * @return array<string,string>
     */
    private function formulario(array $valores = []): array
    {
        $campos = [];
        foreach (EcoAbdominal::columnas() as $columna) {
            $campos[$columna] = $valores[$columna] ?? '';
        }

        return $campos;
    }

    /** Un médico de la libreta, para el `referido`. */
    private function doctor(Medico $medico, int $cedula = 10432, string $nombres = 'José', string $apellidos = 'Pérez'): void
    {
        DB::table('doctores')->insert([
            'reg_medico' => $medico->reg_medico,
            'cedula' => $cedula,
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'codeespecial' => '01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------------------------ La sección

    public function test_la_seccion_abre_con_su_formulario_y_sus_bloques(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->doctor($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();

        // El tipo se ofrece como enlace, como los otros seis del ítem 5.
        $respuesta->assertSee('Eco abdominal');

        // Sus cuatro bloques y sus rótulos propios (los del DataWindow vivo `d_ultasonido_abdominal`).
        foreach (['El encabezado', 'Los órganos', 'El resto del abdomen',
            'Las observaciones y las conclusiones'] as $bloque) {
            $respuesta->assertSee($bloque);
        }
        foreach (['Transductor', 'Referido por', 'Hígado', 'Vesícula biliar', 'Porta y colédoco', 'Páncreas',
            'Rinón derecho', 'Rinón izquierdo', 'Volumen residual vesical Post-Micción', 'Ascitis',
            'Retroperitoneo', 'Vasos sanguíneos', 'Otros', 'Conclusiones'] as $rotulo) {
            $respuesta->assertSee($rotulo);
        }

        // Los textos de las opciones de los cuatro desplegables (lo que guarda es su código).
        $respuesta->assertSee('Sí')->assertSee('Normal')->assertSee('Anormal');

        // El desplegable de médicos ofrece la libreta, y la opción vacía va adelante.
        $respuesta->assertSee('José Pérez');
        $respuesta->assertSee('<option value=""', false);

        // ⚠️ Próstata y vejiga urinaria son de urología: en ginecología no se ofrecen.
        $respuesta->assertDontSee('Próstata');
        $respuesta->assertDontSee('Vejiga urinaria');
        $respuesta->assertDontSee('name="prostata"', false);
        $respuesta->assertDontSee('name="vejiga_urina"', false);

        // Y el formulario apunta a la acción de guardar del tipo que se está mirando.
        $respuesta->assertSee($this->urlGuardar($consulta), false);
        $respuesta->assertSee('Guardar el estudio');

        // Las plantillas de texto del escritorio (revisión de Alexander, 2026-10-17): el tipo `UA` y las
        // conclusiones, que es el área libre que edita esta pantalla (la tabla no tiene columna `texto`).
        $respuesta->assertSee('Administrar plantillas')
            ->assertSee('data-tipo="UA"', false)
            ->assertSee('data-destino="#conclusiones"', false)
            ->assertSee(route('clinica.catalogos.plantillas-texto', ['filtro' => 'UA']), false);
    }

    // ------------------------------------------------------------------------------------ El guardado

    public function test_guardar_crea_la_fila_con_la_clave_del_datawindow(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'transductor' => 'Convexo 3,5 MHz',
            'referido' => '10432',
            'higado' => 'De tamaño y ecogenicidad normales.',
            'vesicula_bilial' => 'Sin litiasis.',
            'vol_res_v_p_m' => 'N',
            'ascitis' => 'A',
            'retroperitoneo' => 'N',
            'vasos_sanguineos' => 'A',
            'otros' => 'Sin otros hallazgos.',
            'conclusiones' => 'Estudio dentro de límites normales.',
        ]));

        $respuesta->assertRedirect($this->urlSeccion($consulta));
        $respuesta->assertSessionHas('estado');

        $fila = UltraAbdominal::where('reg_medico', $medico->reg_medico)
            ->where('historia', 1001)->where('consulta', 1)->first();

        $this->assertNotNull($fila);
        // La fecha la propone hoy, como el escritorio al crear la fila.
        $this->assertSame($this->hoy->toDateString(), $fila->fecha->toDateString());
        $this->assertSame('Convexo 3,5 MHz', $fila->transductor);
        $this->assertSame(10432, $fila->referido);
        $this->assertSame('De tamaño y ecogenicidad normales.', $fila->higado);
        $this->assertSame('Sin litiasis.', $fila->vesicula_bilial);
        // Los cuatro códigos se guardan tal cual (Si→N, No→A, Normal→N, Anormal→A).
        $this->assertSame('N', $fila->vol_res_v_p_m);
        $this->assertSame('A', $fila->ascitis);
        $this->assertSame('N', $fila->retroperitoneo);
        $this->assertSame('A', $fila->vasos_sanguineos);
        $this->assertSame('Estudio dentro de límites normales.', $fila->conclusiones);

        // El rastro: el `created` de la fila con `source = web`.
        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'ultra_abdominal',
            'record_id'  => $fila->id,
            'operation'  => 'created',
            'source'     => 'web',
        ]);
    }

    /** **Una fila por consulta**: guardar dos veces corrige la misma, no duplica. */
    public function test_guardar_dos_veces_no_duplica_la_fila(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta), $this->formulario(['higado' => 'Normal.']))
            ->assertSessionHas('estado');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta), $this->formulario(['higado' => 'Normal.', 'pancreas' => 'Sin lesiones.']))
            ->assertSessionHas('estado');

        $this->assertSame(1, UltraAbdominal::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame('Sin lesiones.', UltraAbdominal::where('historia', 1001)->first()->pancreas);
    }

    /**
     * ⚠️ **`prostata` y `vejiga_urina` no se pisan**: son de urología y la pantalla no las ofrece. Si otro
     * build las cargó, la web las deja como están (decisión del usuario, 2026-10-14).
     */
    public function test_prostata_y_vejiga_urinaria_no_se_pisan_al_guardar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Una fila como la pudo dejar otro build (cirugía o urología) en la misma tabla.
        $fila = UltraAbdominal::create([
            'reg_medico' => $medico->reg_medico,
            'historia' => 1001,
            'consulta' => 1,
            'fecha' => $this->hoy->toDateString(),
            'prostata' => 'Aumento del tamaño prostático.',
            'vejiga_urina' => 'A',
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta), $this->formulario([
                'higado' => 'Normal.',
                'otros' => 'Cargado desde la web.',
            ]))
            ->assertSessionHas('estado');

        $fresca = $fila->fresh();
        $this->assertSame('Aumento del tamaño prostático.', $fresca->prostata);
        $this->assertSame('A', $fresca->vejiga_urina);
        $this->assertSame('Cargado desde la web.', $fresca->otros);
    }

    public function test_un_eco_sin_datos_no_se_guarda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario());

        $respuesta->assertSessionHas('error');
        $this->assertSame(0, UltraAbdominal::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_informe_en_texto_libre_del_escritorio_se_muestra_y_no_se_edita(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        UltraAbdominal::create([
            'reg_medico' => $medico->reg_medico,
            'historia' => 1001,
            'consulta' => 1,
            'fecha' => $this->hoy->toDateString(),
            'higado' => 'Normal.',
            'puro_texto' => 'INFORME EN TEXTO LIBRE DEL ESCRITORIO',
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('INFORME EN TEXTO LIBRE DEL ESCRITORIO')
            ->assertSee('Informe en texto libre');

        // Y sigue ahí después de guardar (la web no edita esa columna).
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta), $this->formulario(['higado' => 'Normal.']))
            ->assertSessionHas('estado');

        $this->assertSame('INFORME EN TEXTO LIBRE DEL ESCRITORIO', UltraAbdominal::where('historia', 1001)->first()->puro_texto);
    }

    // ------------------------------------------------------------------------------------ Permisos

    public function test_la_secretaria_no_entra_a_la_seccion_ni_puede_guardar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $secretaria = $this->secretariaConAcceso($medico);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get($this->urlSeccion($consulta))->assertForbidden();

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post($this->urlGuardar($consulta), $this->formulario(['higado' => 'Normal.']))->assertForbidden();

        $this->assertSame(0, UltraAbdominal::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_invitado_va_al_login(): void
    {
        [$user, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->post($this->urlGuardar($consulta), $this->formulario(['higado' => 'Normal.']))
            ->assertRedirect(route('login'));
    }

    public function test_la_consulta_de_otro_medico_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();

        $this->conContexto($otroUser, $otroMedico, $otraGineco)->actingAs($otroUser)
            ->get($this->urlSeccion($consulta))->assertNotFound();

        $this->conContexto($otroUser, $otroMedico, $otraGineco)->actingAs($otroUser)
            ->post($this->urlGuardar($consulta), $this->formulario(['higado' => 'Normal.']))->assertNotFound();
    }
}
