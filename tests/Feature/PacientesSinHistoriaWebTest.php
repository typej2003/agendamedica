<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Historia;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\MotivoCita;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * El flujo del escritorio **"Pacientes sin historia a atender"** y **"Pasar a Cita Formal"** en la web
 * clínica (WEB-2.11).
 *
 * El escritorio lo resuelve sobre `paciente_no_regi` (`dw_3` de `w_hacer_cita`, `:807-819`) y su botón
 * `pb_3` (`:801-805`) abre `w_pasar_pacientes_n_reg_consulta_cita`, que **crea un paciente nuevo** con
 * número de historia y otra cita. AppDDR no usa esa tabla (deuda del núcleo, anotada): la identidad "sin
 * historia" es una cita anclada por `cola.paciente_sinhistoria_id` sobre un vínculo
 * `medico_pacientes` sin número, y "pasar a cita formal" es **asignarle la historia** con la Action
 * compartida —la misma del sync del móvil—, sin duplicar ni el paciente ni la cita.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class PacientesSinHistoriaWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $lunes;

    protected function setUp(): void
    {
        parent::setUp();

        // Un lunes cualquiera: los bloques se siembran con `dia_semana = 1`.
        $this->lunes = Carbon::parse('2026-10-05')->startOfWeek(Carbon::MONDAY);
    }

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'sinhistoria-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'sinhistoria-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'SinHistoria',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty, ?int $officeId = null): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => $officeId,
        ]]);
    }

    private function centro(string $nombre): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => DB::table('estados')->value('id'),
            'city_id'    => DB::table('cities')->value('id'),
            'name'       => $nombre,
            'address'    => 'Dirección de prueba',
        ]);
    }

    private function sede(Medico $medico, int $centroId, string $numero = 'C1'): Office
    {
        $sede = Office::create([
            'medical_center_id' => $centroId,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => $numero,
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

    /** Un paciente del médico: con historia (`$numhistoria`) o dado de alta sin número. */
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

    /**
     * Una cita del día: la de un paciente con historia se ancla por `numhistoria` y la de uno sin
     * historia por `paciente_sinhistoria_id`, que es como las deja el alta de la web (WEB-2.2).
     */
    private function cita(Medico $medico, Office $sede, string $hora, array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico'        => $medico->reg_medico,
            'fecha'             => $this->lunes->toDateString(),
            'hora_ini'          => $hora,
            'medical_center_id' => $sede->medical_center_id,
            'numorden'          => 1,
            'atendido'          => 0,
            'estado'            => Cola::ESTADO_NO_CONFIRMADA,
        ], $extra));
    }

    private function urlDeLaAgenda(?int $sedeId = null): string
    {
        return route('clinica.agenda', [
            'vista' => 'dia',
            'fecha' => $this->lunes->toDateString(),
            'sede'  => $sedeId ?? 'todas',
        ]);
    }

    // ------------------------------------------------------------------------------------- El panel

    public function test_el_panel_lista_solo_los_pacientes_sin_historia_del_dia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);

        $conHistoria = $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $sinHistoria = $this->paciente($medico, 'Beto', 'Blanco');
        // El que no está en el panel: su cita es de otro día.
        $otroDia = $this->paciente($medico, 'Carla', 'DelOtroDia');

        $this->cita($medico, $sede, '08:00:00', ['numhistoria' => 1001]);
        $this->cita($medico, $sede, '09:00:00', ['paciente_sinhistoria_id' => $sinHistoria]);
        Cola::create([
            'reg_medico'              => $medico->reg_medico,
            'fecha'                   => $this->lunes->copy()->addDay()->toDateString(),
            'hora_ini'                => '09:00:00',
            'paciente_sinhistoria_id' => $otroDia,
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlDeLaAgenda());

        $respuesta->assertOk();
        $respuesta->assertSee('Pacientes sin historia a atender');
        $respuesta->assertSee('Blanco, Beto');
        $respuesta->assertSee('1 paciente');
        $respuesta->assertSee('Pasar a cita formal');
        // La acción apunta a la ruta que asigna la historia a esa cita (no a otra).
        $respuesta->assertSee(
            route('clinica.agenda.pasar-a-cita-formal', Cola::where('paciente_sinhistoria_id', $sinHistoria)->value('id')),
            false
        );

        // El que ya tiene historia y el del otro día no entran al panel (sí a la agenda, el primero).
        $panel = $this->panel($respuesta->content());
        $this->assertStringNotContainsString('Alvarez, Ana', $panel);
        $this->assertStringNotContainsString('DelOtroDia', $panel);
    }

    public function test_sin_pacientes_sin_historia_el_panel_no_se_pinta(): void
    {
        // Revisión de Alexander (2026-10-17): sin pacientes sin historia no hay tarjeta, ni conteo, ni
        // estado vacío. Antes el panel se veía siempre y decía "Ese día no hay pacientes sin historia".
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $this->cita($medico, $sede, '08:00:00', ['numhistoria' => 1001]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlDeLaAgenda());

        $respuesta->assertOk();
        $respuesta->assertDontSee('Pacientes sin historia a atender');
        $respuesta->assertDontSee('id="panel-sin-historia"', false);
        $respuesta->assertDontSee('no hay pacientes sin historia');
        $respuesta->assertDontSee('Pasar a cita formal');
    }

    public function test_el_panel_no_muestra_los_pacientes_sin_historia_de_otro_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('Propia')->id);
        $propio = $this->paciente($medico, 'Propia', 'Paciente');
        $this->cita($medico, $sede, '08:00:00', ['paciente_sinhistoria_id' => $propio]);

        // Otro médico, el mismo día, con una cita de un paciente suyo sin historia.
        [, $otro] = $this->medicoConAcceso();
        $ajeno = $this->paciente($otro, 'Ajena', 'Persona');
        Cola::create([
            'reg_medico'              => $otro->reg_medico,
            'fecha'                   => $this->lunes->toDateString(),
            'hora_ini'                => '09:00:00',
            'paciente_sinhistoria_id' => $ajeno,
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlDeLaAgenda());

        $respuesta->assertOk();
        // El armador nombra "Apellidos, Nombres" (como el resto de la agenda).
        $respuesta->assertSee('Paciente, Propia');
        $this->assertStringNotContainsString('Persona, Ajena', $this->panel($respuesta->content()));
    }

    public function test_el_panel_respeta_la_sede_que_se_esta_mirando(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sanJose = $this->sede($medico, $this->centro('San José')->id, 'C1');
        $metropolitana = $this->sede($medico, $this->centro('Clínica Metropolitana')->id, 'C2');

        $deSanJose = $this->paciente($medico, 'DeSan', 'José');
        $deLaOtra = $this->paciente($medico, 'DeLa', 'Metropolitana');

        $this->cita($medico, $sanJose, '08:00:00', ['paciente_sinhistoria_id' => $deSanJose]);
        $this->cita($medico, $metropolitana, '09:00:00', ['paciente_sinhistoria_id' => $deLaOtra]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlDeLaAgenda($sanJose->id));

        $respuesta->assertOk();
        $panel = $this->panel($respuesta->content());
        $this->assertStringContainsString('José, DeSan', $panel);
        $this->assertStringNotContainsString('Metropolitana, DeLa', $panel);

        // Y con "todas las sedes" están los dos.
        $todas = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlDeLaAgenda());
        $this->assertStringContainsString('José, DeSan', $this->panel($todas->content()));
        $this->assertStringContainsString('Metropolitana, DeLa', $this->panel($todas->content()));
    }

    public function test_una_cita_sin_paciente_identificable_no_se_ofrece_para_formalizar(): void
    {
        // Filas del legado con `numhistoria` nulo y sin ancla: no hay a quién numerarle la historia.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->sede($medico, $this->centro('San José')->id);
        Cola::create([
            'reg_medico' => $medico->reg_medico,
            'fecha'      => $this->lunes->toDateString(),
            'hora_ini'   => '08:00:00',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlDeLaAgenda());

        $respuesta->assertOk();
        // No hay a quién numerarle la historia: el panel no se pinta (2026-10-17).
        $respuesta->assertDontSee('id="panel-sin-historia"', false);
        $respuesta->assertDontSee('no hay pacientes sin historia');
    }

    public function test_el_panel_es_de_la_vista_de_dia_y_no_de_semana_ni_mes(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);
        $this->cita($medico, $sede, '09:00:00', ['paciente_sinhistoria_id' => $this->paciente($medico, 'Beto', 'Blanco')]);

        foreach (['semana', 'mes'] as $vista) {
            $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(
                route('clinica.agenda', ['vista' => $vista, 'fecha' => $this->lunes->toDateString(), 'sede' => 'todas'])
            );

            $respuesta->assertOk();
            $respuesta->assertDontSee('Pacientes sin historia a atender');
        }
    }

    // ---------------------------------------------------------------- Pasar a cita formal (la acción)

    public function test_pasar_a_cita_formal_asigna_el_correlativo_y_deja_la_misma_cita_enlazada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);

        // El correlativo del médico: la última historia es la 1001.
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $nuevo = $this->paciente($medico, 'Beto', 'Blanco');
        $cita = $this->cita($medico, $sede, '09:00:00', ['paciente_sinhistoria_id' => $nuevo]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.agenda.pasar-a-cita-formal', $cita->id));

        $respuesta->assertRedirect($this->urlDeLaAgenda($sede->id));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'H. 1002') && str_contains($estado, 'Cita formal'));

        // El paciente tiene su historia y el vínculo quedó con el número.
        $historia = Historia::where('paciente_id', $nuevo)->where('medico_id', $medico->id)->firstOrFail();
        $this->assertSame('1002', $historia->numhistoria);
        $this->assertSame('1002', MedicoPaciente::where('paciente_id', $nuevo)->value('numhistoria'));

        // Es **la misma cita** (no se insertó otra) y ahora se enlaza por `numhistoria`, que es lo que
        // leen la agenda y el sync del escritorio.
        $this->assertSame(1, Cola::where('reg_medico', $medico->reg_medico)
            ->where('paciente_sinhistoria_id', $nuevo)->count());
        $this->assertSame(1002, (int) $cita->fresh()->numhistoria);

        // Y la cita dejó de estar "sin historia": el panel ya no se pinta.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlDeLaAgenda())
            ->assertOk()
            ->assertDontSee('id="panel-sin-historia"', false)
            ->assertDontSee('no hay pacientes sin historia');
    }

    public function test_pasar_a_cita_formal_dos_veces_no_numera_dos_veces(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);
        $nuevo = $this->paciente($medico, 'Beto', 'Blanco');
        $cita = $this->cita($medico, $sede, '09:00:00', ['paciente_sinhistoria_id' => $nuevo]);

        $peticion = $this->conContexto($user, $medico, $gineco)->actingAs($user);
        $peticion->post(route('clinica.agenda.pasar-a-cita-formal', $cita->id))->assertRedirect();

        $segunda = $peticion->post(route('clinica.agenda.pasar-a-cita-formal', $cita->id));
        $segunda->assertRedirect();
        // La segunda vez lo dice: no volvió a numerar, devolvió la que ya tenía.
        $segunda->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'ya tenía historia') && str_contains($estado, 'H. 1'));

        $this->assertSame(1, Historia::where('paciente_id', $nuevo)->count());
        $this->assertSame(1, Historia::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_pasar_a_cita_formal_sobre_un_paciente_que_ya_tenia_historia_no_rompe(): void
    {
        // Caso borde real: la cita apunta al paciente por el ancla "sin historia" pero el paciente ya
        // tiene historia (se la asignó el teléfono, por ejemplo). La Action es idempotente: devuelve la
        // que tiene y **no numera otra vez** — que es lo que la regla promete. La cita no se enlaza en
        // este camino: el enlace lo hacen las filas que sí estaban esperando número.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);
        $paciente = $this->paciente($medico, 'Ana', 'Alvarez', 4001);
        $cita = $this->cita($medico, $sede, '09:00:00', ['paciente_sinhistoria_id' => $paciente]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.agenda.pasar-a-cita-formal', $cita->id));

        $respuesta->assertRedirect($this->urlDeLaAgenda($sede->id));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'H. 4001'));

        $this->assertSame(1, Historia::where('paciente_id', $paciente)->count());
        $this->assertSame('4001', Historia::where('paciente_id', $paciente)->value('numhistoria'));
    }

    public function test_una_cita_de_otro_medico_no_se_puede_pasar_a_cita_formal(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->sede($medico, $this->centro('Propia')->id);

        [, $otro] = $this->medicoConAcceso();
        $ajeno = $this->paciente($otro, 'Ajena', 'Persona');
        $citaAjena = Cola::create([
            'reg_medico'              => $otro->reg_medico,
            'fecha'                   => $this->lunes->toDateString(),
            'hora_ini'                => '09:00:00',
            'paciente_sinhistoria_id' => $ajeno,
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.agenda.pasar-a-cita-formal', $citaAjena->id))
            ->assertNotFound();

        // No se numeró nada: ni historia ni vínculo.
        $this->assertSame(0, Historia::where('paciente_id', $ajeno)->count());
        $this->assertNull($citaAjena->fresh()->numhistoria);
    }

    public function test_el_numero_de_historia_sale_del_medico_del_contexto_y_no_del_formulario(): void
    {
        // `reg_medico` es el tenant: no viaja en el POST y una cita de otro registro ni se toca (404).
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);
        $nuevo = $this->paciente($medico, 'Beto', 'Blanco');
        $cita = $this->cita($medico, $sede, '09:00:00', ['paciente_sinhistoria_id' => $nuevo]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.agenda.pasar-a-cita-formal', $cita->id), ['reg_medico' => 'otro-registro'])
            ->assertRedirect();

        $this->assertSame($medico->reg_medico, Historia::where('paciente_id', $nuevo)->value('reg_medico'));
    }

    public function test_la_secretaria_tambien_puede_pasar_a_cita_formal(): void
    {
        // Es la acción del mostrador: la hace la secretaría, no solo el médico.
        $this->seed(EspecialidadesYModulosSeeder::class);
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();

        $medico = Medico::create(['name' => 'Dr. Dueño', 'lastname' => 'Datos', 'reg_medico' => 'sinhistoria-secre-' . uniqid()]);
        $medico->specialties()->attach($gineco->id);

        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'sinhistoria-secre-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $sede = $this->sede($medico, $this->centro('San José')->id);
        $nuevo = $this->paciente($medico, 'Beto', 'Blanco');
        $cita = $this->cita($medico, $sede, '09:00:00', ['paciente_sinhistoria_id' => $nuevo]);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post(route('clinica.agenda.pasar-a-cita-formal', $cita->id))
            ->assertRedirect()
            ->assertSessionHas('estado');

        $this->assertSame(1, Historia::where('paciente_id', $nuevo)->count());
    }

    public function test_un_invitado_no_puede_pasar_a_cita_formal(): void
    {
        [$user, $medico] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);
        $nuevo = $this->paciente($medico, 'Beto', 'Blanco');
        $cita = $this->cita($medico, $sede, '09:00:00', ['paciente_sinhistoria_id' => $nuevo]);

        $this->post(route('clinica.agenda.pasar-a-cita-formal', $cita->id))->assertRedirect(route('login'));

        $this->assertSame(0, Historia::where('paciente_id', $nuevo)->count());
    }

    // ------------------------------------------------------------------ La razón de la cita (WEB-2.16)

    public function test_la_fila_del_panel_muestra_la_razon_del_catalogo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);
        MotivoCita::create([
            'reg_medico'    => $medico->reg_medico,
            'codigo'        => 'ECO',
            'tipo_atencion' => 'Ecografía',
        ]);
        $nuevo = $this->paciente($medico, 'Beto', 'Blanco');
        $this->cita($medico, $sede, '09:00:00', ['paciente_sinhistoria_id' => $nuevo, 'tipo' => 'ECO']);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlDeLaAgenda());

        $respuesta->assertOk();
        $this->assertStringContainsString('Ecografía', $this->panel($respuesta->content()));
    }

    /**
     * El **texto** del panel, para afirmar sobre él y no sobre la página entera: el nombre de un
     * paciente aparece también en la jornada, y la fila "sin historia" de la agenda trae un estado que
     * dice lo mismo. Se acota a la tarjeta del panel (su `id` es lo que lo identifica).
     */
    private function panel(string $html): string
    {
        $inicio = strpos($html, 'id="panel-sin-historia"');
        $this->assertNotFalse($inicio, 'el panel no está en la página');

        $cierre = strpos($html, '</section>', $inicio);
        $this->assertNotFalse($cierre, 'el panel no cierra');

        $fragmento = substr($html, $inicio, $cierre - $inicio);

        return html_entity_decode(strip_tags($fragmento), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
