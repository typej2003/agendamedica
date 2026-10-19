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
 * **"Atender" abre la consulta** y el shell de la consulta (WEB-4.1, PLAN-WEB.md §3.10 y §3.14).
 *
 * El flujo del legado —el `open` de `w_consulta_principal`: abrir la consulta del día, crearla si no
 * existe y dejar la cita atendida— portado al mostrador de la web, con los cuatro casos:
 *
 *  1. sin historia no se atiende (manda a asignarla);
 *  2. la cita sin atender abre la consulta y la marca (implica confirmada);
 *  3. la cita ya atendida entra a editar la consulta del día sin volver a marcar;
 *  4. si el paciente ya tiene consulta hoy, "re-atender" reusa esa y "consulta nueva" numera la siguiente.
 *
 * Y el recorte de roles: **la consulta es sólo del médico**, así que la secretaría no entra al shell
 * (responde 403, no un redirect silencioso) ni recibe el botón que la abre. Desde **WEB-4.9** (decisión
 * del usuario, 2026-10-11) eso incluye **"Atender"**: la secretaría no ve la acción en la fila y el POST
 * a mano responde 403 **sin marcar la cita** (antes lo marcaba y volvía con un aviso).
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultaWebTest extends TestCase
{
    use DatabaseTransactions;

    /** El "hoy" del consultorio para toda la clase: la fecha de la cita y la de la consulta son la misma. */
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
        $regMedico = 'consulta-test' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'consulta-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Consulta',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    /** La secretaría: sin ficha de médico, con el registro asignado (como en la web real). */
    private function secretariaConAcceso(Medico $medico): User
    {
        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'consulta-secr' . substr(uniqid(), -6) . '@example.com',
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

    /** Un paciente del médico: con historia (y su fila en `historias`) o dado de alta sin número. */
    private function paciente(Medico $medico, string $nombres, string $apellidos, ?int $numhistoria = null): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres'     => $nombres,
            'apellidos'   => $apellidos,
            'cedula'      => (string) random_int(10000000, 30000000),
            'sexo'        => 'F',
            'fnacimiento' => '1990-05-04',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('medico_pacientes')->insert([
            'medico_id'   => $medico->id,
            'paciente_id' => $id,
            'numhistoria' => $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        if ($numhistoria !== null) {
            Historia::create([
                'numhistoria' => (string) $numhistoria,
                'reg_medico'  => $medico->reg_medico,
                'paciente_id' => $id,
                'medico_id'   => $medico->id,
            ]);
        }

        return $id;
    }

    /** La cita de hoy del paciente (por historia o por el ancla "sin historia"). */
    private function cita(Medico $medico, Office $sede, array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico'        => $medico->reg_medico,
            'fecha'             => $this->hoy->toDateString(),
            'hora_ini'          => '09:00:00',
            'medical_center_id' => $sede->medical_center_id,
            'numorden'          => 1,
            'atendido'          => 0,
            'estado'            => Cola::ESTADO_NO_CONFIRMADA,
        ], $extra));
    }

    private function urlAtender(Cola $cita): string
    {
        return route('clinica.agenda.atender', $cita->id);
    }

    private function urlConsulta(Consulta $consulta): string
    {
        return route('clinica.consulta', $consulta->id);
    }

    // ------------------------------------------------------------------- Atender abre la consulta

    public function test_atender_sin_historia_lleva_a_asignarla_y_no_marca_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $pacienteId = $this->paciente($medico, 'Beto', 'Blanco');
        $cita = $this->cita($medico, $sede, ['paciente_sinhistoria_id' => $pacienteId]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlAtender($cita));

        // A la ficha, que es donde vive la acción de asignar la historia (WEB-3.3).
        $respuesta->assertRedirect(route('clinica.pacientes.ver', $pacienteId));
        $respuesta->assertSessionHas('error', fn ($error) => str_contains($error, 'historia'));

        // Nada se marcó y nada se abrió: sin historia no hay consulta.
        $this->assertSame(0, (int) $cita->fresh()->atendido);
        $this->assertSame(0, Consulta::where('reg_medico', $medico->reg_medico)->count());
        $this->assertNull(MedicoPaciente::where('paciente_id', $pacienteId)->value('numhistoria'));
    }

    public function test_atender_abre_la_consulta_del_dia_y_marca_la_cita_atendida_y_confirmada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $cita = $this->cita($medico, $sede, ['numhistoria' => 1001]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlAtender($cita));

        $consulta = Consulta::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $respuesta->assertRedirect($this->urlConsulta($consulta));

        // La cita quedó atendida y —porque atender implica confirmado— confirmada.
        $fresca = $cita->fresh();
        $this->assertSame(1, (int) $fresca->atendido);
        $this->assertSame(Cola::ESTADO_CONFIRMADA, (int) $fresca->estado);

        // Y la consulta es la primera de esa historia, del día de la cita.
        $this->assertSame(1001, (int) $consulta->numhistoria);
        $this->assertSame(1, (int) $consulta->nroconsulta);
        $this->assertSame($this->hoy->toDateString(), $consulta->fecha->toDateString());
    }

    public function test_la_segunda_atencion_del_dia_reusa_la_consulta_de_hoy(): void
    {
        // "Re-atender" (la respuesta por defecto del legado): el paciente ya tiene una consulta hoy y
        // volver a atenderlo **no** numera otra.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);

        $primera = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        $cita = $this->cita($medico, $sede, ['numhistoria' => 1001]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlAtender($cita))
            ->assertRedirect($this->urlConsulta($primera));

        $this->assertSame(1, Consulta::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(1, (int) $cita->fresh()->atendido);
    }

    public function test_consulta_nueva_numera_la_siguiente_aunque_haya_una_hoy(): void
    {
        // La otra mitad de la pregunta del legado: "consulta nueva" numera la siguiente de la historia.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);

        Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        $cita = $this->cita($medico, $sede, ['numhistoria' => 1001]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlAtender($cita), ['nueva' => 1]);

        $nueva = Consulta::where('reg_medico', $medico->reg_medico)->where('nroconsulta', 2)->first();
        $this->assertNotNull($nueva, 'consulta nueva tiene que numerar la siguiente');
        $respuesta->assertRedirect($this->urlConsulta($nueva));
        $this->assertSame(2, Consulta::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_la_numeracion_sigue_el_correlativo_de_la_historia(): void
    {
        // La consulta es la última **de esa historia** + 1, no la última del médico.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $this->paciente($medico, 'Beto', 'Blanco', 1002);

        // Ana tiene dos consultas viejas; Beto, una.
        Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 1, 'fecha' => '2026-09-01']);
        Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 2, 'fecha' => '2026-09-02']);
        Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1002, 'nroconsulta' => 1, 'fecha' => '2026-09-03']);

        $cita = $this->cita($medico, $sede, ['numhistoria' => 1002]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlAtender($cita));

        // La consulta se busca **por el reg_medico de esta prueba**, no sólo por el número de historia:
        // `FakeClinicalDataSeeder` siembra `gineco-00001` con las historias 1001–1050 y reparte 80
        // consultas al azar entre ellas, así que una consulta de la base sembrada puede tener el mismo
        // `numhistoria` y un `nroconsulta` más alto. Sin el filtro, la aserción medía la fila sembrada
        // (el test fallaba ~1 de cada 4 corridas, según cuántas consultas le tocaran al azar a la 1002).
        $nueva = Consulta::where('reg_medico', $medico->reg_medico)
            ->where('numhistoria', 1002)
            ->orderByDesc('nroconsulta')
            ->first();
        $this->assertSame(2, (int) $nueva->nroconsulta, 'la consulta sigue el correlativo de la historia');
        $this->assertSame($this->hoy->toDateString(), $nueva->fecha->toDateString());
    }

    public function test_con_la_cita_ya_atendida_entra_a_editar_la_consulta_y_no_vuelve_a_marcar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);

        $consulta = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        // La cita ya atendida: no se puede volver a marcar (ni se deshace).
        $cita = $this->cita($medico, $sede, ['numhistoria' => 1001, 'atendido' => 1, 'estado' => Cola::ESTADO_CONFIRMADA]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlAtender($cita));

        $respuesta->assertRedirect($this->urlConsulta($consulta));

        $fresca = $cita->fresh();
        $this->assertSame(1, (int) $fresca->atendido);
        $this->assertSame(Cola::ESTADO_CONFIRMADA, (int) $fresca->estado);
        $this->assertSame(1, Consulta::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_la_agenda_ofrece_editar_la_consulta_cuando_la_cita_ya_esta_atendida(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $this->cita($medico, $sede, ['numhistoria' => 1001, 'atendido' => 1, 'estado' => Cola::ESTADO_CONFIRMADA]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(
            route('clinica.agenda', ['vista' => 'dia', 'fecha' => $this->hoy->toDateString(), 'sede' => 'todas'])
        );

        $respuesta->assertOk();
        $respuesta->assertSee('Editar consulta');
        // Y el formulario de la fila le avisa al diálogo que es el caso "ya atendida": `shell.js` muestra
        // entonces «Consulta nueva» (revisión de Alexander, 2026-10-17).
        $respuesta->assertSee('data-editar="1"', false);
    }

    /**
     * Revisión de Alexander (2026-10-17): con la cita **sin atender** el diálogo de "Atender" ofrece
     * **sólo** Cancelar y Atender. «Consulta nueva» —numerar la siguiente consulta de la historia— sólo
     * aparece con la cita ya atendida ("Editar consulta"), cuando el médico ya no tiene nada que marcar.
     *
     * El botón viaja siempre en el HTML porque lo muestra `shell.js` según `data-editar`; sin JavaScript
     * queda oculto, que es el comportamiento correcto por defecto.
     */
    public function test_el_dialogo_de_atender_no_ofrece_consulta_nueva_si_la_cita_no_esta_atendida(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $this->cita($medico, $sede, ['numhistoria' => 1001]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(
            route('clinica.agenda', ['vista' => 'dia', 'fecha' => $this->hoy->toDateString(), 'sede' => 'todas'])
        );

        $respuesta->assertOk();
        $respuesta->assertSee('id="dialogo-atender"', false);
        $respuesta->assertSee('Atender y abrir la consulta');
        // El caso normal: el formulario de la fila no es "editar".
        $respuesta->assertSee('data-editar="0"', false);
        // «Consulta nueva» está en el HTML pero **oculta**: sin JS no se manda `nueva=1`.
        $respuesta->assertSee('name="nueva" value="1" id="atender-nueva" hidden', false);
    }

    public function test_una_cita_ya_atendida_sin_consulta_del_dia_abre_una_al_editar(): void
    {
        // Borde real: la cita está atendida (el escritorio la marcó) y no hay consulta de ese día. La
        // pantalla tiene que existir para poder editar, así que se abre una —sin marcar nada—.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $cita = $this->cita($medico, $sede, ['numhistoria' => 1001, 'atendido' => 1, 'estado' => Cola::ESTADO_CONFIRMADA]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlAtender($cita));

        $consulta = Consulta::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $respuesta->assertRedirect($this->urlConsulta($consulta));
        $this->assertSame(1, (int) $consulta->nroconsulta);
        $this->assertSame(1, (int) $cita->fresh()->atendido);
    }

    // -------------------------------------------------------------------------- El shell de la consulta

    public function test_el_shell_muestra_el_encabezado_y_los_trece_items_del_legado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->sede($medico);
        $pacienteId = $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $consulta = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 3,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlConsulta($consulta));

        $respuesta->assertOk();

        // El encabezado: paciente, consulta, historia y fecha.
        $respuesta->assertSee('Alvarez, Ana');
        $respuesta->assertSee('Consulta N');
        $respuesta->assertSee('H. 1001');
        $respuesta->assertSee($this->hoy->format('d/m/Y'));

        // El panel completo: los 13 ítems del legado, con su tarea y su estado.
        foreach ([
            'Motivos de consulta', 'Enfermedad actual', 'Antecedentes', 'Evaluación física',
            'Estudio ecográfico', 'Control de embarazo', 'Exámenes', 'Diagnóstico', 'Evolución',
            'Récipes', 'Solicitudes', 'Datos personales', 'Pre-factura',
        ] as $item) {
            $respuesta->assertSee($item, false);
        }

        // El único ítem que todavía no existe (la pre-factura) va **deshabilitado** en el grupo «Consulta» del menú
        // lateral: sin enlace y con `aria-disabled`, para que la lista no se rompa cuando F9 lo habilite. Los ítems de
        // WEB-4.2 (motivos y enfermedad actual), WEB-4.3 (evaluación física), WEB-4.4 (antecedentes), WEB-4.5
        // (diagnóstico) y WEB-4.6 (evolución) ya están disponibles y se abren con `?seccion=` dentro de esta misma pantalla.
        $respuesta->assertSee('aria-disabled="true"', false);
        // Los disponibles no llevan pastilla "Disponible" ni el contador "N de 14 disponibles", que se fue con el panel.
        $respuesta->assertDontSee('>Disponible<', false);
        $respuesta->assertDontSee('de 14 disponibles', false);
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'motivos']), false);
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'enfermedad']), false);
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'evaluacion']), false);
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'antecedentes']), false);
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'diagnostico']), false);
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'evolucion']), false);
        // A-11: el paciente se alcanza por la sección «Datos personales»; el encabezado ya no enlaza su ficha (el
        // «Ver resumen» que llevaba a la historia se fue de la consulta).
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'datos']), false);

        // Y la vuelta a la agenda del día de la consulta (con el `&` de la query ya escapado por Blade).
        $respuesta->assertSee(route('clinica.agenda', [
            'vista' => 'dia', 'fecha' => $this->hoy->toDateString(), 'sede' => 'todas',
        ]));

        // A-11: «Ver resumen» se fue de la consulta (el resumen del paciente se ve desde su ficha).
        $respuesta->assertDontSee('Ver resumen');
    }

    /**
     * **La barra de contexto no se pinta dentro de la consulta** (A-11, decisión del usuario). La consulta es una
     * pantalla de trabajo: ni las fichas del médico/sede, ni «Sedes», ni «Consultorios», ni «Cambiar consultorio», ni el
     * menú de la cuenta (cerrar sesión). Es una **regla**: dentro de la consulta no queda ningún enlace ni atajo a esa
     * pantalla. En la agenda la barra sigue igual.
     */
    public function test_la_consulta_no_pinta_la_barra_de_contexto_y_la_agenda_si(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $consulta = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        $enLaConsulta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlConsulta($consulta));

        $enLaConsulta->assertOk();
        $enLaConsulta->assertDontSee('Cambiar consultorio');
        $enLaConsulta->assertDontSee(route('clinica.contexto'), false);
        $enLaConsulta->assertDontSee('Cerrar sesión');
        $enLaConsulta->assertDontSee('aria-label="Mi cuenta"', false);
        $enLaConsulta->assertDontSee(route('clinica.sedes'), false);

        // La salida sigue estando: la flecha del principio, que guarda lo escrito antes de volver.
        $enLaConsulta->assertSee('Volver a la agenda');
        $enLaConsulta->assertSee('data-barra-salir', false);

        // En la agenda, la barra de contexto no cambió.
        $enLaAgenda = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(route('clinica.agenda'));

        $enLaAgenda->assertOk();
        $enLaAgenda->assertSee('Cambiar consultorio');
        $enLaAgenda->assertSee(route('clinica.contexto'), false);
        $enLaAgenda->assertSee('Cerrar sesión');
        $enLaAgenda->assertSee('aria-label="Mi cuenta"', false);
    }

    public function test_una_consulta_de_otro_medico_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->sede($medico);

        // Otro médico, con su paciente y su consulta del mismo número de historia.
        [, $otro] = $this->medicoConAcceso();
        $this->paciente($otro, 'Ajena', 'Persona', 1001);
        $ajena = Consulta::create([
            'reg_medico'  => $otro->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlConsulta($ajena))
            ->assertNotFound();
    }

    public function test_un_invitado_no_entra_a_la_consulta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $consulta = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        $this->get($this->urlConsulta($consulta))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------------- Sólo el médico (§3.14)

    public function test_la_secretaria_no_entra_a_la_consulta(): void
    {
        // 403, no un redirect silencioso: la ruta del shell está fuera de su alcance (la ficha clínica
        // es del médico, PLAN-WEB.md §3.14).
        [, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $consulta = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get($this->urlConsulta($consulta))
            ->assertForbidden();
    }

    /**
     * **La secretaría no puede atender** (WEB-4.9, decisión del usuario del 2026-10-11: "Atender es sólo
     * del médico"). El POST a mano responde **403** —el mismo "no podés hacer esto" que la ruta del shell
     * le da por la misma regla (`User::trabajaLaConsulta()`)— y **no deja media marca**: la cita no queda
     * atendida, ni confirmada por el "atender implica confirmada", ni con rastro de sync, ni con una
     * consulta abierta al lado.
     *
     * Antes este test afirmaba lo contrario (la cita quedaba atendida y la secretaría volvía a la agenda
     * con un aviso): era el comportamiento del 2026-10-10, que el usuario cambió al decir que el
     * `atendido = 1` de una cita sólo lo produce el médico.
     */
    public function test_la_secretaria_no_puede_marcar_la_cita_por_post(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        $sede = $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $cita = $this->cita($medico, $sede, ['numhistoria' => 1001]);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post($this->urlAtender($cita))
            ->assertForbidden();

        // Nada se marcó: ni la atención, ni la confirmación que "atender" implica, ni el rastro del sync.
        $fresca = $cita->fresh();
        $this->assertSame(0, (int) $fresca->atendido);
        $this->assertSame(Cola::ESTADO_NO_CONFIRMADA, (int) $fresca->estado);
        $this->assertSame(0, SyncChange::where('table_name', 'cola')->where('record_id', $cita->id)->count());

        // Y no se abrió ninguna consulta: la atención no ocurrió.
        $this->assertSame(0, Consulta::where('reg_medico', $medico->reg_medico)->count());
    }

    /** El médico, en cambio, sigue viendo la acción en su agenda (y la usa: ver los tests de arriba). */
    public function test_la_agenda_le_ofrece_atender_al_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $cita = $this->cita($medico, $sede, ['numhistoria' => 1001]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(
            route('clinica.agenda', ['vista' => 'dia', 'fecha' => $this->hoy->toDateString(), 'sede' => 'todas'])
        );

        $respuesta->assertOk();
        $respuesta->assertSee('Atender');
        $respuesta->assertSee($this->urlAtender($cita), false);
        // La cita sin confirmar: el botón principal es Confirmar y "Atender" viaja en el menú.
        $respuesta->assertSee('Confirmar');
        $respuesta->assertSee('Cobrar');
    }

    public function test_la_agenda_no_le_ofrece_a_la_secretaria_ninguna_accion_sobre_una_cita_atendida(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        $sede = $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $this->cita($medico, $sede, ['numhistoria' => 1001, 'atendido' => 1, 'estado' => Cola::ESTADO_CONFIRMADA]);

        $respuesta = $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get(
            route('clinica.agenda', ['vista' => 'dia', 'fecha' => $this->hoy->toDateString(), 'sede' => 'todas'])
        );

        $respuesta->assertOk();
        $respuesta->assertDontSee('Editar consulta');
        $respuesta->assertDontSee('Atender');
    }

    /**
     * Y tampoco sobre una cita pendiente (WEB-4.9): la acción no se le muestra **ni como botón ni dentro
     * del menú de acciones** —donde vivía cuando la cita todavía no estaba confirmada—, porque atender es
     * abrir la consulta. Lo que sí conserva es lo suyo: Confirmar, Cobrar y el menú con lo demás.
     */
    public function test_la_agenda_no_le_ofrece_atender_a_la_secretaria_sobre_una_cita_pendiente(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        $sede = $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $cita = $this->cita($medico, $sede, ['numhistoria' => 1001]);

        $respuesta = $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get(
            route('clinica.agenda', ['vista' => 'dia', 'fecha' => $this->hoy->toDateString(), 'sede' => 'todas'])
        );

        $respuesta->assertOk();
        $respuesta->assertDontSee('Atender');
        // La marca por la URL es la que dice de verdad que la acción no está: el texto podría estar en
        // cualquier otro lado de la página (la trampa 18 es exactamente esto).
        $respuesta->assertDontSee($this->urlAtender($cita), false);

        // Lo que la secretaría sí conserva sobre la misma cita.
        $respuesta->assertSee('Confirmar');
        $respuesta->assertSee('Cobrar');
        $respuesta->assertSee(route('clinica.agenda.editar', $cita->id), false);
    }

    public function test_la_consulta_no_es_un_modulo_del_menu_mientras_no_tenga_pantalla_propia(): void
    {
        // A la consulta se entra por "Atender" (o desde la historia del paciente); el menú lateral no
        // ofrece un enlace que no llevaría a ninguna parte. Cuando F4 tenga una lista de consultas, el
        // módulo se marca implementado en `EspecialidadesYModulosSeeder`.
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(route('clinica.inicio'));

        $respuesta->assertOk();
        // `Route::has('clinica.consulta')` es verdadero, así que la prueba tiene que ser sobre la página:
        // el menú no lleva al shell (que necesita una consulta concreta) y sí a los módulos de verdad.
        $respuesta->assertSee('Pacientes');
        $respuesta->assertDontSee(route('clinica.consulta', 1), false);
    }
}
