<?php

namespace Tests\Feature;

use App\Clinica\FechaDelConsultorio;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\OfficeSchedule;
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
 * **Terminar la consulta** (WEB-4.8, PLAN-WEB.md §3.13): la acción primaria del shell.
 *
 * "Cerrar" **no crea un estado nuevo**: es terminar la atención —la cita de la agenda queda atendida— y
 * volver a la agenda del día, que es lo único que la fuente escribe al respecto (`w_consulta_principal`
 * marca `cola.atendido = 1` en su `open`, `:4416-4421`, y sus botones de salida sólo cierran la ventana;
 * ver `App\Actions\Consulta\CerrarConsulta`). Lo que la tarea agrega de verdad es la **regla del
 * servidor** "cita atendida ⇒ tiene historia" (TODO.md §9), que vive en `App\Actions\Agenda\AtenderCita`
 * y se prueba acá desde la web y en `AccionesDeAgendaTest` desde el sync.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultaCierreWebTest extends TestCase
{
    use DatabaseTransactions;

    /** El "hoy" del consultorio para toda la clase. */
    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = FechaDelConsultorio::dia('2026-10-05');
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
        $regMedico = 'cierre-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'cierre-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Cierre',
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
            'email'                => 'cierre-secre-' . uniqid() . '@example.com',
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

    private function sede(Medico $medico): Office
    {
        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => DB::table('estados')->value('id'),
            'city_id'    => DB::table('cities')->value('id'),
            'name'       => 'San José',
            'address'    => 'Dirección de prueba',
        ]);

        $sede = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => 'C1',
            'modalidad'         => Office::MODALIDAD_HORA,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);

        OfficeSchedule::create([
            'office_id'   => $sede->id,
            'reg_medico'  => $medico->reg_medico,
            'dia_semana'  => 1,
            'hora_inicio' => '08:00',
            'hora_fin'    => '12:00',
            'cupo'        => null,
        ]);

        return $sede;
    }

    /** El paciente del médico, con historia, y su consulta. */
    private function pacienteConConsulta(
        Medico $medico,
        int $numhistoria,
        int $nroconsulta = 1,
        ?string $fecha = null,
        ?int $sede = null,
    ): Consulta {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres'     => 'Ana',
            'apellidos'   => 'Alvarez',
            'cedula'      => (string) random_int(10000000, 30000000),
            'sexo'        => 'F',
            'fnacimiento' => '1990-05-04',
            'telefono'    => '04141234567',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('medico_pacientes')->insert([
            'medico_id'   => $medico->id,
            'paciente_id' => $pacienteId,
            'numhistoria' => (string) $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        Historia::create([
            'numhistoria'       => (string) $numhistoria,
            'reg_medico'        => $medico->reg_medico,
            'paciente_id'       => $pacienteId,
            'medico_id'         => $medico->id,
            'medical_center_id' => $sede,
        ]);

        return Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'nroconsulta' => $nroconsulta,
            'fecha'       => $fecha ?? $this->hoy->toDateString(),
        ]);
    }

    private function cita(Medico $medico, Office $sede, array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico'        => $medico->reg_medico,
            'fecha'             => $this->hoy->toDateString(),
            'hora_ini'          => '09:00:00',
            'medical_center_id' => $sede->medical_center_id,
            'numorden'          => 1,
            'atendido'          => 0,
            'estado'            => Cola::ESTADO_CONFIRMADA,
        ], $extra));
    }

    private function urlCerrar(Consulta $consulta): string
    {
        return route('clinica.consulta.cerrar', $consulta->id);
    }

    private function urlAgenda(Office $sede): string
    {
        return route('clinica.agenda', [
            'vista' => 'dia', 'fecha' => $this->hoy->toDateString(), 'sede' => $sede->medical_center_id,
        ]);
    }

    // ------------------------------------------------------------------- El camino bueno y su rastro

    public function test_el_shell_ofrece_terminar_la_consulta_de_hoy(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $consulta = $this->pacienteConConsulta($medico, 4001, 1, null, $sede->medical_center_id);
        $this->cita($medico, $sede, ['numhistoria' => 4001, 'atendido' => 1]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', $consulta->id));

        $respuesta->assertOk();
        $respuesta->assertSee('Terminar la consulta');
        $respuesta->assertSee($this->urlCerrar($consulta), false);
        // La confirmación viaja en el `data-confirmar` que resuelve `shell.js`: la acción es irreversible
        // y la pantalla lo dice antes de hacerla.
        $respuesta->assertSee('data-confirmar=', false);
    }

    public function test_terminar_la_consulta_marca_la_cita_atendida_y_vuelve_a_la_agenda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $consulta = $this->pacienteConConsulta($medico, 4001, 3, null, $sede->medical_center_id);
        $cita = $this->cita($medico, $sede, [
            'numhistoria' => 4001, 'atendido' => 0, 'estado' => Cola::ESTADO_NO_CONFIRMADA,
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlCerrar($consulta));

        $respuesta->assertRedirect($this->urlAgenda($sede));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'Consulta N.º 3 terminada')
            && str_contains($estado, 'atendida'));

        $fresca = $cita->fresh();
        $this->assertSame(1, (int) $fresca->atendido);
        // Atender implica confirmada: es la regla del móvil y del mostrador, no una nueva.
        $this->assertSame(Cola::ESTADO_CONFIRMADA, (int) $fresca->estado);

        // El rastro del sync, con `source = web`, como el resto de la web.
        $this->assertSame(1, SyncChange::where('table_name', 'cola')
            ->where('record_id', $cita->id)->where('column_name', 'atendido')
            ->where('source', 'web')->count());
    }

    /**
     * La trazabilidad de la regla, desde la web: la cita que nació **anclada al vínculo y sin número**
     * —el alta rápida del móvil (`SyncAppDataController::crearCola`)— se encuentra igual, se numera con la
     * historia que su paciente ya tiene y eso también deja rastro (si no, una edición vieja del teléfono
     * lo pisaría sin con qué compararse).
     */
    public function test_terminar_la_consulta_encuentra_y_numera_la_cita_anclada_al_vinculo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);

        // El paciente tiene historia, pero la cita nació anclada al vínculo y sin número.
        $consulta = $this->pacienteConConsulta($medico, 4001, 1, null, $sede->medical_center_id);
        $pacienteId = (int) MedicoPaciente::where('reg_medico', $medico->reg_medico)->value('paciente_id');

        $cita = $this->cita($medico, $sede, ['paciente_sinhistoria_id' => $pacienteId]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlCerrar($consulta))
            ->assertRedirect($this->urlAgenda($sede));

        $fresca = $cita->fresh();
        $this->assertSame(1, (int) $fresca->atendido);
        $this->assertSame(4001, (int) $fresca->numhistoria);
        $this->assertSame(1, SyncChange::where('table_name', 'cola')
            ->where('record_id', $cita->id)->where('column_name', 'numhistoria')
            ->where('source', 'web')->count());
    }

    /**
     * **La regla rechaza**: una cita sin historia no se marca atendida. Es el caso del servidor —desde la
     * web no se llega porque `AbrirConsulta` exige historia para abrir una consulta—, así que se prueba
     * sobre la Action, que es donde la regla vive y donde la usan las dos superficies.
     */
    public function test_la_regla_rechaza_marcar_atendida_una_cita_sin_historia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);

        // Un paciente del médico, dado de alta sin número (WEB-2.11): su vínculo no tiene historia.
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Beto', 'apellidos' => 'Blanco',
            'cedula' => (string) random_int(10000000, 30000000), 'sexo' => 'M',
            'fnacimiento' => '1985-01-01', 'telefono' => '04141234567', 'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create([
            'medico_id' => $medico->id, 'paciente_id' => $pacienteId,
            'reg_medico' => $medico->reg_medico,
        ]);

        $cita = $this->cita($medico, $sede, ['paciente_sinhistoria_id' => $pacienteId]);

        $this->expectException(\App\Actions\Agenda\CitaNoAtendible::class);

        try {
            app(\App\Actions\Agenda\AtenderCita::class)->ejecutar($cita, true, FechaDelConsultorio::ahora(), 'web');
        } finally {
            // La cita quedó intacta: la regla no deja media marca.
            $this->assertSame(0, (int) $cita->fresh()->atendido);
            $this->assertNull($cita->fresh()->numhistoria);
        }
    }

    /** Y el camino bueno de la regla: con historia, la misma Action marca. */
    public function test_la_regla_deja_pasar_la_cita_con_historia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->pacienteConConsulta($medico, 4001, 1, null, $sede->medical_center_id);
        $cita = $this->cita($medico, $sede, ['numhistoria' => 4001]);

        $aplicado = app(\App\Actions\Agenda\AtenderCita::class)
            ->ejecutar($cita, true, FechaDelConsultorio::ahora(), 'web');

        $this->assertTrue($aplicado);
        $this->assertSame(1, (int) $cita->fresh()->atendido);
    }

    /**
     * El flujo de **WEB-2.11** sigue funcionando: "Pasar a Cita Formal" asigna la historia **antes** de
     * dejar la cita lista, así que cuando después se la atiende la regla se cumple sola.
     */
    public function test_web_2_11_sigue_funcionando_y_la_cita_queda_atendible(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);

        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Beto', 'apellidos' => 'Blanco',
            'cedula' => (string) random_int(10000000, 30000000), 'sexo' => 'M',
            'fnacimiento' => '1985-01-01', 'telefono' => '04141234567', 'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create([
            'medico_id' => $medico->id, 'paciente_id' => $pacienteId,
            'reg_medico' => $medico->reg_medico,
        ]);

        $cita = $this->cita($medico, $sede, ['paciente_sinhistoria_id' => $pacienteId]);

        // 1. Pasar a cita formal: le asigna el número y enlaza **esa misma** cita.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.agenda.pasar-a-cita-formal', $cita->id))
            ->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'Cita formal'));

        $fresca = $cita->fresh();
        $this->assertNotNull($fresca->numhistoria);

        // 2. Y ahora la cita sí se puede atender: es lo que la web hace al abrir la consulta.
        $aplicado = app(\App\Actions\Agenda\AtenderCita::class)
            ->ejecutar($fresca, true, FechaDelConsultorio::ahora(), 'web');

        $this->assertTrue($aplicado);
        $this->assertSame(1, (int) $cita->fresh()->atendido);
    }

    // ------------------------------------------------------------------------ Los bordes del cierre

    public function test_una_consulta_sin_cita_de_ese_dia_se_termina_igual_y_lo_dice(): void
    {
        // Una consulta abierta desde la historia del paciente, sin agenda detrás (o con la cita borrada
        // por el escritorio): se termina y se informa, sin romper nada.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $consulta = $this->pacienteConConsulta($medico, 4001, 2, null, $sede->medical_center_id);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlCerrar($consulta));

        $respuesta->assertRedirect($this->urlAgenda($sede));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'No se encontró una cita'));
        $this->assertSame(0, Cola::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_la_cita_movida_por_el_escritorio_no_se_marca(): void
    {
        // La fila del día es la vieja: el paciente ya está en la fecha nueva. Se termina la consulta pero
        // no se marca nada (misma vara que el mostrador, `citaPorAtender`).
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $consulta = $this->pacienteConConsulta($medico, 4001, 1, null, $sede->medical_center_id);
        $cita = $this->cita($medico, $sede, ['numhistoria' => 4001, 'movida_escritorio' => true]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlCerrar($consulta));

        $respuesta->assertRedirect($this->urlAgenda($sede));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'movido el escritorio'));
        $this->assertSame(0, (int) $cita->fresh()->atendido);
    }

    public function test_el_shell_no_ofrece_terminar_una_consulta_vieja(): void
    {
        // El escritorio marca `atendido` sólo la cita del día (`w_consulta_principal.srw:4418`), así que
        // estrenar una consulta pasada no es "terminar una atención".
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $consulta = $this->pacienteConConsulta($medico, 4001, 1, '2026-09-01', $sede->medical_center_id);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', $consulta->id));

        $respuesta->assertOk();
        $respuesta->assertDontSee('Terminar la consulta');
        $respuesta->assertDontSee($this->urlCerrar($consulta), false);
        // Y la vuelta sigue estando: la pantalla no se queda sin salida.
        $respuesta->assertSee('Volver a la agenda');
    }

    public function test_una_consulta_de_otro_medico_no_se_puede_terminar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->sede($medico);

        [, $otro] = $this->medicoConAcceso();
        $sedeAjena = $this->sede($otro);
        $ajena = $this->pacienteConConsulta($otro, 5001, 1, null, $sedeAjena->medical_center_id);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlCerrar($ajena))
            ->assertNotFound();

        // Y no se marcó nada de la consulta ajena.
        $this->assertSame(0, Cola::where('reg_medico', $otro->reg_medico)->count());
    }

    public function test_la_secretaria_no_puede_terminar_la_consulta(): void
    {
        // La consulta es del médico (PLAN-WEB.md §3.14): la ruta vive en el grupo `role:Root|Medico`, así
        // que la secretaría recibe **403**, no un redirect silencioso.
        [, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        $sede = $this->sede($medico);
        $consulta = $this->pacienteConConsulta($medico, 4001, 1, null, $sede->medical_center_id);
        $cita = $this->cita($medico, $sede, ['numhistoria' => 4001]);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post($this->urlCerrar($consulta))
            ->assertForbidden();

        $this->assertSame(0, (int) $cita->fresh()->atendido);
    }

    public function test_un_invitado_no_puede_terminar_la_consulta(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $consulta = $this->pacienteConConsulta($medico, 4001, 1, null, $sede->medical_center_id);

        $this->post($this->urlCerrar($consulta))->assertRedirect(route('login'));
    }

    public function test_no_se_termina_la_consulta_si_faltan_datos_obligatorios_del_paciente(): void
    {
        // WEB-11.25: sin teléfono la consulta no se cierra y se lleva al médico a «Datos personales».
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteConConsulta($medico, 1001, 1);
        DB::table('pacientes')->whereIn('id', MedicoPaciente::where('reg_medico', $medico->reg_medico)->pluck('paciente_id'))->update(['telefono' => null]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(route('clinica.consulta.cerrar', $consulta->id));

        $respuesta->assertRedirect(route('clinica.consulta', [$consulta->id, 'seccion' => 'datos']));
        $respuesta->assertSessionHas('error', fn ($e) => str_contains($e, 'teléfono'));
    }

    public function test_la_seccion_de_datos_personales_se_edita_dentro_de_la_consulta_y_vuelve_a_ella(): void
    {
        // WEB-11.25: los campos de la ficha, lo de sólo lectura, el aviso de lo que falta y el guardado que vuelve a la consulta.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteConConsulta($medico, 1001, 1);
        $pacienteId = MedicoPaciente::where('reg_medico', $medico->reg_medico)->value('paciente_id');
        DB::table('pacientes')->where('id', $pacienteId)->update(['telefono' => null]);

        $url = route('clinica.consulta', [$consulta->id, 'seccion' => 'datos']);
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($url)
            ->assertOk()->assertSee('Datos personales')->assertSee('H. 1001')
            ->assertSee('Para terminar la consulta falta completar: teléfono.')
            ->assertSee('name="volver_a_consulta"', false)->assertSee('data-imprimir-ficha', false);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->put(route('clinica.pacientes.actualizar', $pacienteId), [
            'nac' => 'V', 'cedula' => '12345678', 'apellidos' => 'Alvarez', 'nombres' => 'Ana', 'telefono' => '04141112222',
            'volver_a_consulta' => $consulta->id,
        ])->assertRedirect($url);

        $this->assertSame('04141112222', DB::table('pacientes')->where('id', $pacienteId)->value('telefono'));
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($url)->assertOk()->assertDontSee('Para terminar la consulta falta completar');
    }
}
