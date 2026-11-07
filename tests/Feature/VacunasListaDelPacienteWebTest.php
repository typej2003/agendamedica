<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **La lista de vacunas del paciente** (O-68 y O-69, Alexander 2026-11-06): la pantalla principal son las filas de
 * `vacuna_paciente` del niño —lo aplicado y lo programado— como la solapa *Inmunizaciones* del escritorio, y el esquema del
 * catálogo quedó como un panel plegado que sólo propone.
 *
 * Se fija que: la pantalla lista las filas con sus columnas y su segunda línea (vía, zona, vence, próxima, notas); el panel
 * está plegado y cada dosis se puede **programar** (nunca solo); «Agregar una vacuna» abre el catálogo para elegir vacuna +
 * dosis; programar escribe la fila planeada con el rastro de la web y no duplica; aplicar una programada **completa esa
 * fila** sin crear otra ni perder su «F. a aplicar»; quitar borra sólo lo programado; y los permisos son los de siempre.
 */
class VacunasListaDelPacienteWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-17 09:00:00'));
        DB::table('vacunas')->whereNull('reg_medico')->delete();
        DB::table('vacunas_dosis')->whereNull('reg_medico')->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $pediatria = Specialty::firstOrCreate(['slug' => 'pediatria'], ['name' => 'Pediatría']);
        $reg = 'lista-vac-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'lista-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Lista', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($pediatria->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico, $pediatria];
    }

    private function conContexto(Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => ['reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null]]);
    }

    /** Un niño nacido el 2026-06-17 (4 meses hoy). Con `$historia = 0` queda sin número de historia. */
    private function nino(Medico $medico, int $historia = 9801, ?string $nacimiento = '2026-06-17'): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres' => 'Mateo', 'apellidos' => 'Lista', 'sexo' => 'M', 'fnacimiento' => $nacimiento, 'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create([
            'medico_id' => $medico->id, 'paciente_id' => $id, 'reg_medico' => $medico->reg_medico,
            'numhistoria' => $historia === 0 ? null : (string) $historia,
        ]);

        return $id;
    }

    private function vacuna(Medico $medico, string $codigo, string $nombre, ?int $edad = 2, ?int $edadFinal = null): void
    {
        DB::table('vacunas')->insert([
            'reg_medico' => $medico->reg_medico, 'codi_vacuna' => $codigo, 'vacuna' => $nombre, 'edad_inicial_meses' => $edad,
            'edad_final_meses' => $edadFinal, 'fija' => 'O', 'sexo' => 'A', 'prioridad' => 'A', 'tipo_vacu' => 'INY',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function dosis(Medico $medico, string $codigo, int $numero, string $rotulo, float $meses): void
    {
        DB::table('vacunas_dosis')->insert([
            'reg_medico' => $medico->reg_medico, 'codi_vacuna' => $codigo, 'dosis' => $numero, 'dosis_descri' => $rotulo, 'meses' => $meses,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Una vacuna con sus dosis, como la deja el catálogo del médico. */
    private function catalogo(Medico $medico): void
    {
        $this->vacuna($medico, 'POLI', 'Polio');
        $this->dosis($medico, 'POLI', 1, '1era. Dosis', 2);
        $this->dosis($medico, 'POLI', 2, '2da. Dosis', 4);
        $this->vacuna($medico, 'ROTA', 'Rotavirus');
        $this->dosis($medico, 'ROTA', 1, 'Dosis única', 4);
    }

    private function filas(Medico $medico, int $historia = 9801)
    {
        return DB::table('vacuna_paciente')->where('reg_medico', $medico->reg_medico)->where('historia', $historia)->orderBy('id')->get();
    }

    private function url(int $paciente, array $query = []): string
    {
        return route('clinica.pacientes.vacunas', array_merge([$paciente], $query));
    }

    // ------------------------------------------------------------------------------------------ La pantalla

    public function test_la_pantalla_lista_las_filas_del_nino_con_su_segunda_linea_y_el_esquema_plegado(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = $this->nino($medico);

        DB::table('vacuna_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'historia' => 9801, 'codi_vacuna' => 'POLI', 'dosis' => '1era. Dosis', 'dosis_numero' => 1,
            'fecha_planeada' => '2026-08-17', 'fecha_ejecutada' => '2026-08-20', 'ejecutada' => 'S', 'descripcion' => 'Polio',
            'lote' => 'L-77', 'via' => 'Oral', 'zona' => 'Muslo derecho', 'nota' => 'Sin reacción', 'fecha_vence' => '2027-01-01',
            'fecha_proxima_dosis' => '2026-10-20', 'aplicada_aca' => 'S', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('vacuna_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'historia' => 9801, 'codi_vacuna' => 'ROTA', 'dosis' => 'Dosis única', 'dosis_numero' => 1,
            'fecha_planeada' => '2026-11-17', 'fecha_ejecutada' => '2026-10-17', 'ejecutada' => 'N', 'descripcion' => 'Rotavirus',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($id))
            ->assertOk()
            ->assertSee('id="vacunas-del-paciente"', false)
            // La fila aplicada, con sus dos líneas.
            ->assertSeeInOrder(['Polio', '1era. Dosis', '17/08/2026', '20/08/2026', 'Aplicada'])
            ->assertSee('Lote L-77')->assertSee('Vía Oral')->assertSee('Zona Muslo derecho')
            ->assertSee('Vence 01/01/2027')->assertSee('Próxima 20/10/2026')->assertSee('Sin reacción')
            // «Propia» (`aplicada_aca`) no se muestra: qué significa no está relevado (ver el docblock de la clase).
            ->assertDontSee('Aplicada acá')
            // La programada: su «F. a aplicar» está, su «F. aplicada» no (esa fecha es la de la carga, no la de una aplicación).
            ->assertSeeInOrder(['Rotavirus', '17/11/2026', 'Programada'])
            ->assertSee('1 aplicada')->assertSee('1 programada')
            // El esquema del catálogo sigue estando, plegado, con su botón de programar cada dosis.
            ->assertSee('Esquema del catálogo')
            ->assertDontSee('<details class="vacunas-esquema" id="vacunas-calendario" open', false)
            ->assertSee('name="planeada" value="2026-10-17"', false);
    }

    public function test_agregar_una_vacuna_trae_el_catalogo_del_medico_para_elegir_vacuna_y_dosis(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = $this->nino($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($id))
            ->assertOk()
            ->assertSee('data-agregar-vacuna', false)
            ->assertSee('Agregar una vacuna')
            ->assertSee('id="dialogo-registrar-dosis"', false)
            // Los dos desplegables del *Escoger* del escritorio: la vacuna y su dosis.
            ->assertSee('id="dosis-vacuna"', false)->assertSee('id="dosis-dosis"', false)
            ->assertSee('<option value="POLI">Polio</option>', false)
            ->assertSee('data-rotulo="2da. Dosis"', false)
            // La decisión primero, sin opción marcada.
            ->assertSee('id="dosis-modo"', false)->assertSee('Ya se la apliqué')->assertSee('Queda programada');
    }

    // ------------------------------------------------------------------------------------------ Programar

    public function test_programar_una_dosis_escribe_la_fila_planeada_con_el_rastro_de_la_web(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = $this->nino($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->post(route('clinica.pacientes.vacunas.programar', $id), [
                'codi_vacuna' => 'POLI', 'dosis_numero' => 2, 'rotulo' => '2da. Dosis', 'planeada' => '2026-11-20', 'nota' => 'Control en un mes',
            ])
            ->assertRedirect($this->url($id))
            ->assertSessionHas('estado');

        $fila = $this->filas($medico)->first();
        $this->assertSame('N', $fila->ejecutada);
        $this->assertSame('POLI', $fila->codi_vacuna);
        $this->assertSame('2da. Dosis', $fila->dosis);
        $this->assertSame(2, (int) $fila->dosis_numero);
        $this->assertSame('2026-11-20', Carbon::parse($fila->fecha_planeada)->toDateString());
        // `fecha_ejecutada` es NOT NULL y el escritorio la llena con el día de la carga (`w_escoger_razon_vacuna_consu.srw:1249-1250`).
        $this->assertSame('2026-10-17', Carbon::parse($fila->fecha_ejecutada)->toDateString());
        $this->assertSame('Polio', $fila->descripcion);
        $this->assertSame('A', $fila->prioridad);
        $this->assertSame('Control en un mes', $fila->nota);

        $rastro = SyncChange::where('table_name', 'vacuna_paciente')->where('record_id', $fila->id)->first();
        $this->assertSame('created', $rastro->operation);
        $this->assertSame('web', $rastro->source);

        // Y la pantalla ya la muestra como programada, con su fecha a aplicar.
        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($id))
            ->assertOk()->assertSeeInOrder(['Polio', '2da. Dosis', '20/11/2026', 'Programada']);
    }

    public function test_programar_no_duplica_ni_acepta_lo_que_no_se_puede(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        DB::table('vacunas')->insert(['reg_medico' => 'otro', 'codi_vacuna' => 'AJEN', 'vacuna' => 'De otro', 'created_at' => now(), 'updated_at' => now()]);
        $id = $this->nino($medico);
        $post = route('clinica.pacientes.vacunas.programar', $id);
        $sesion = $this->conContexto($medico, $pediatria)->actingAs($user);

        $sesion->post($post, ['codi_vacuna' => 'POLI', 'dosis_numero' => 1, 'rotulo' => '1era. Dosis', 'planeada' => '2026-10-10'])
            ->assertRedirect($this->url($id))->assertSessionHas('estado');

        $casos = [
            [['codi_vacuna' => 'POLI', 'dosis_numero' => 1, 'rotulo' => '1era. Dosis', 'planeada' => '2026-10-10'], 'Esa dosis ya está en la lista del paciente.'],
            [['codi_vacuna' => 'AJEN', 'dosis_numero' => 1, 'planeada' => '2026-10-10'], 'Esa vacuna no está en el catálogo de este consultorio.'],
            [['codi_vacuna' => 'POLI', 'dosis_numero' => 2, 'planeada' => '2026-06-01'], 'La fecha a aplicar no puede ser anterior al nacimiento del niño.'],
        ];

        foreach ($casos as [$datos, $mensaje]) {
            $sesion->post($post, $datos)->assertRedirect($this->url($id))->assertSessionHas('error');
            $this->assertSame($mensaje, session('error'), 'Mensaje inesperado: ' . session('error'));
        }

        // Lo que falta lo frena la validación del formulario, en español.
        $sesion->post($post, ['codi_vacuna' => 'POLI', 'rotulo' => '1era. Dosis', 'planeada' => '2026-10-10'])
            ->assertSessionHasErrors('dosis_numero');
        $sesion->post($post, ['codi_vacuna' => 'POLI', 'dosis_numero' => 3, 'planeada' => ''])
            ->assertSessionHasErrors('planeada');

        // Una dosis ya aplicada tampoco se programa.
        DB::table('vacuna_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'historia' => 9801, 'codi_vacuna' => 'ROTA', 'dosis' => 'Dosis única', 'dosis_numero' => 1,
            'fecha_ejecutada' => '2026-10-01', 'ejecutada' => 'S', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sesion->post($post, ['codi_vacuna' => 'ROTA', 'dosis_numero' => 1, 'planeada' => '2026-10-10'])
            ->assertSessionHas('error', 'Esa dosis ya está registrada como aplicada.');

        $this->assertCount(2, $this->filas($medico));
    }

    // ------------------------------------------------------------------------------------------ Aplicar y quitar

    public function test_aplicar_una_programada_completa_esa_fila_y_conserva_su_fecha_a_aplicar(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = $this->nino($medico);

        DB::table('vacuna_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'historia' => 9801, 'codi_vacuna' => 'POLI', 'dosis' => '1era. Dosis', 'dosis_numero' => 1,
            'fecha_planeada' => '2026-08-17', 'fecha_ejecutada' => '2026-08-01', 'ejecutada' => 'N', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->post(route('clinica.pacientes.vacunas.registrar', $id), [
                'codi_vacuna' => 'POLI', 'dosis_numero' => 1, 'rotulo' => '1era. Dosis', 'fecha_ejecutada' => '2026-08-20', 'lote' => 'L-9',
            ])
            ->assertRedirect($this->url($id))->assertSessionHas('estado');

        $filas = $this->filas($medico);
        $this->assertCount(1, $filas, 'aplicar una programada no puede crear otra fila');
        $this->assertSame('S', $filas[0]->ejecutada);
        $this->assertSame('2026-08-20', Carbon::parse($filas[0]->fecha_ejecutada)->toDateString());
        // La «F. a aplicar» que había programado el médico no se pierde al aplicarla.
        $this->assertSame('2026-08-17', Carbon::parse($filas[0]->fecha_planeada)->toDateString());
        $this->assertSame('L-9', $filas[0]->lote);
    }

    public function test_quitar_borra_solo_lo_programado(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = $this->nino($medico);
        $sesion = $this->conContexto($medico, $pediatria)->actingAs($user);

        $programada = DB::table('vacuna_paciente')->insertGetId([
            'reg_medico' => $medico->reg_medico, 'historia' => 9801, 'codi_vacuna' => 'POLI', 'dosis' => '2da. Dosis', 'dosis_numero' => 2,
            'fecha_planeada' => '2026-11-20', 'fecha_ejecutada' => '2026-10-17', 'ejecutada' => 'N', 'descripcion' => 'Polio',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $aplicada = DB::table('vacuna_paciente')->insertGetId([
            'reg_medico' => $medico->reg_medico, 'historia' => 9801, 'codi_vacuna' => 'POLI', 'dosis' => '1era. Dosis', 'dosis_numero' => 1,
            'fecha_ejecutada' => '2026-08-20', 'ejecutada' => 'S', 'descripcion' => 'Polio', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Se ofrece quitar la programada (y no la aplicada).
        $sesion->get($this->url($id))->assertOk()
            ->assertSee(route('clinica.pacientes.vacunas.quitar', [$id, $programada]), false)
            ->assertDontSee(route('clinica.pacientes.vacunas.quitar', [$id, $aplicada]), false);

        $sesion->delete(route('clinica.pacientes.vacunas.quitar', [$id, $programada]))
            ->assertRedirect($this->url($id))->assertSessionHas('estado');

        $this->assertNull(DB::table('vacuna_paciente')->where('id', $programada)->first());
        $this->assertSame('deleted', SyncChange::where('table_name', 'vacuna_paciente')->where('record_id', $programada)->first()->operation);

        // Una aplicada no se borra: es historia clínica.
        $sesion->delete(route('clinica.pacientes.vacunas.quitar', [$id, $aplicada]))
            ->assertRedirect($this->url($id))->assertSessionHas('error', 'Una dosis aplicada no se quita de la lista.');
        $this->assertNotNull(DB::table('vacuna_paciente')->where('id', $aplicada)->first());
    }

    public function test_una_fila_ajena_no_se_quita(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = $this->nino($medico);
        $ajena = DB::table('vacuna_paciente')->insertGetId([
            'reg_medico' => 'otro-consultorio', 'historia' => 9801, 'codi_vacuna' => 'POLI', 'dosis' => '2da. Dosis', 'dosis_numero' => 2,
            'fecha_ejecutada' => '2026-10-17', 'ejecutada' => 'N', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->delete(route('clinica.pacientes.vacunas.quitar', [$id, $ajena]))
            ->assertRedirect($this->url($id))->assertSessionHas('error', 'Esa vacuna ya no está en la lista del paciente.');

        $this->assertNotNull(DB::table('vacuna_paciente')->where('id', $ajena)->first());
    }

    // ------------------------------------------------------------------------------------------ Permisos y bordes

    public function test_sin_historia_no_se_ofrece_agregar_ni_programar(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = $this->nino($medico, 0);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($id))
            ->assertOk()
            ->assertDontSee('data-agregar-vacuna', false)
            ->assertDontSee('data-registrar-dosis', false)
            ->assertDontSee('Programar', false);

        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->post(route('clinica.pacientes.vacunas.programar', $id), ['codi_vacuna' => 'POLI', 'dosis_numero' => 1, 'planeada' => '2026-10-10'])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, DB::table('vacuna_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_esquema_no_sugiere_una_dosis_cuya_ventana_ya_vencio(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->vacuna($medico, 'NEUMO', 'Neumococo', 6, 9);
        $this->dosis($medico, 'NEUMO', 1, '1era. Dosis', 6);
        // Un niño de 2 años: la ventana de 9 meses ya cerró (`vacunas.edad_final_meses`).
        $id = $this->nino($medico, 9801, '2024-06-17');

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($id))
            ->assertOk()
            ->assertSee('Neumococo')
            ->assertSee('Vencida')
            ->assertSee('Vencía el 17/03/2025')
            // No se ofrece programar lo que ya no se aplica.
            ->assertDontSee('name="planeada" value="2024-12-17"', false);
    }

    public function test_la_secretaria_no_entra_y_el_paciente_ajeno_da_404(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = $this->nino($medico);

        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'lista-secre-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $this->conContexto($medico, $pediatria)->actingAs($secretaria)->get($this->url($id))->assertForbidden();
        $this->conContexto($medico, $pediatria)->actingAs($secretaria)
            ->post(route('clinica.pacientes.vacunas.programar', $id), ['codi_vacuna' => 'POLI', 'dosis_numero' => 1, 'planeada' => '2026-10-10'])
            ->assertForbidden();
        $this->conContexto($medico, $pediatria)->actingAs($secretaria)
            ->delete(route('clinica.pacientes.vacunas.quitar', [$id, 1]))->assertForbidden();

        [$otroUser, $otro, $otraEsp] = $this->medicoConAcceso();
        $this->conContexto($otro, $otraEsp)->actingAs($otroUser)->get($this->url($id))->assertNotFound();
        $this->conContexto($otro, $otraEsp)->actingAs($otroUser)
            ->post(route('clinica.pacientes.vacunas.programar', $id), ['codi_vacuna' => 'POLI', 'dosis_numero' => 1, 'planeada' => '2026-10-10'])
            ->assertNotFound();
    }
}
