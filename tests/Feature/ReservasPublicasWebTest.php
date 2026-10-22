<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Paciente;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El **portal público de reservas** (2026-10-19): catálogo de médicos con agenda publicada, horario,
 * verificación del teléfono con un código de 6 dígitos y cita confirmada por el paciente.
 *
 * Lo que se fija acá, además del camino feliz:
 *  - la cita sale por `AgendamientoDeCitas` (rastro `created`/`source = web`), nace **sin número de
 *    historia** (`paciente_sinhistoria_id`), con la sede del bloque y en estado 2;
 *  - el paciente se enlaza por cédula: no se duplica la ficha;
 *  - si la cédula existe con otro teléfono, no se reserva (el código dejaría de probar nada);
 *  - el horario se vuelve a comprobar: si se ocupó, no se crea la cita;
 *  - una cita pendiente con ese médico no se duplica.
 *
 * `DatabaseTransactions` por la razón de siempre (ver MedicAPI/AGENTS.md).
 */
class ReservasPublicasWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $fecha;

    private Medico $medico;

    private Office $sede;

    protected function setUp(): void
    {
        parent::setUp();

        // Domingo 18 de octubre de 2026, 07:00 UTC = 03:00 en Caracas: el bloque del lunes 19 a las 08:00
        // queda a 29 h, dentro de la anticipación mínima de 24 h del portal.
        Carbon::setTestNow(Carbon::create(2026, 10, 18, 7, 0, 0));

        $this->fecha = Carbon::createFromFormat('!Y-m-d', '2026-10-19');

        $this->medico = Medico::create([
            'name' => 'Carlos',
            'lastname' => 'Reservas',
            'email' => 'reservas-' . substr(uniqid(), -6) . '@example.com',
            'reg_medico' => 'test-reservas' . substr(uniqid(), -6),
        ]);
        MedicoRegistro::create(['medico_id' => $this->medico->id, 'reg_medico' => $this->medico->reg_medico]);

        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'),
            'name' => 'Sede de reservas',
            'address' => 'Calle de prueba',
        ]);

        $this->sede = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id' => $this->medico->id,
            'reg_medico' => $this->medico->reg_medico,
            'office_number' => '1',
            'modalidad' => Office::MODALIDAD_HORA,
            'duracion_cita' => 30,
            'activo' => true,
        ]);

        OfficeSchedule::create([
            'office_id' => $this->sede->id,
            'reg_medico' => $this->medico->reg_medico,
            'dia_semana' => 1,
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
            'cupo' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string,string> */
    private function datos(string $cedula = null, string $telefono = '0414-5556677'): array
    {
        return [
            'horario' => $this->fecha->toDateString() . '|08:00|' . $this->sede->id,
            'cedula' => $cedula ?? (string) random_int(10000000, 30000000),
            'nombres' => 'Ana',
            'apellidos' => 'Prueba',
            'telefono' => $telefono,
            'email' => 'ana.prueba@example.com',
        ];
    }

    private function codigo(): string
    {
        return (string) session('reservas.pendiente.codigo');
    }

    public function test_el_catalogo_solo_muestra_medicos_con_agenda_publicada(): void
    {
        $sinAgenda = Medico::create([
            'name' => 'Sin',
            'lastname' => 'Agenda',
            'email' => 'sin-agenda-' . substr(uniqid(), -6) . '@example.com',
            'reg_medico' => 'test-sin-agen' . substr(uniqid(), -6),
        ]);
        $sinAgenda->specialties()->attach(\App\Models\Specialty::firstOrFail());

        $this->medico->specialties()->attach(\App\Models\Specialty::firstOrFail());

        $this->get(route('reservas.catalogo'))
            ->assertOk()
            ->assertSee('Reserva tu cita')
            ->assertSee($this->medico->nombre_mostrar)
            ->assertDontSee($sinAgenda->nombre_mostrar);

        $this->get(route('reservas.medico', $this->medico))
            ->assertOk()
            ->assertSee('Elige el horario')
            ->assertSee('8:00 AM');

        $this->get(route('reservas.medico', $sinAgenda))
            ->assertOk()
            ->assertSee('no tiene horarios publicados');
    }

    public function test_la_reserva_completa_crea_la_cita_sin_historia_y_confirmada(): void
    {
        $datos = $this->datos();

        $this->post(route('reservas.codigo.enviar', $this->medico), $datos)
            ->assertRedirect(route('reservas.codigo', $this->medico));

        $this->assertSame(0, Cola::where('reg_medico', $this->medico->reg_medico)->count());
        $this->assertDatabaseMissing('pacientes', ['cedula' => $datos['cedula']]);
        $this->assertNotEmpty($this->codigo());

        $this->post(route('reservas.confirmar', $this->medico), ['codigo' => $this->codigo()])
            ->assertRedirect(route('reservas.confirmada'));

        $cita = Cola::where('reg_medico', $this->medico->reg_medico)->firstOrFail();
        $paciente = Paciente::where('cedula', $datos['cedula'])->firstOrFail();

        $this->assertSame($this->fecha->toDateString(), Carbon::parse($cita->fecha)->toDateString());
        $this->assertSame('08:00', substr((string) $cita->hora_ini, 0, 5));
        $this->assertSame((int) $this->sede->medical_center_id, (int) $cita->medical_center_id);
        $this->assertSame(Cola::ESTADO_CONFIRMADA_PACIENTE, (int) $cita->estado);
        $this->assertNull($cita->numhistoria, 'El portal no numera la historia: la asigna el consultorio.');
        $this->assertSame($paciente->id, (int) $cita->paciente_sinhistoria_id);
        $this->assertSame('584145556677', $paciente->telefono);
        $this->assertSame(0, DB::table('historias')->where('paciente_id', $paciente->id)->count());
        $this->assertDatabaseHas('medico_pacientes', [
            'medico_id' => $this->medico->id,
            'paciente_id' => $paciente->id,
        ]);
        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'cola',
            'record_id' => $cita->id,
            'operation' => 'created',
            'source' => 'web',
        ]);

        $this->get(route('reservas.confirmada'))
            ->assertOk()
            ->assertSee('¡Cita reservada!')
            ->assertSee('Ana Prueba')
            ->assertSee($this->medico->nombre_mostrar);
    }

    public function test_un_codigo_equivocado_no_crea_la_cita_y_deja_reintentar(): void
    {
        $datos = $this->datos();
        $this->post(route('reservas.codigo.enviar', $this->medico), $datos);

        $errado = $this->codigo() === '000000' ? '111111' : '000000';

        $this->from(route('reservas.codigo', $this->medico))
            ->post(route('reservas.confirmar', $this->medico), ['codigo' => $errado])
            ->assertRedirect(route('reservas.codigo', $this->medico))
            ->assertSessionHasErrors('codigo');

        $this->assertSame(0, Cola::where('reg_medico', $this->medico->reg_medico)->count());
        $this->assertSame(1, (int) session('reservas.pendiente.intentos'));

        $this->post(route('reservas.confirmar', $this->medico), ['codigo' => $this->codigo()])
            ->assertRedirect(route('reservas.confirmada'));

        $this->assertSame(1, Cola::where('reg_medico', $this->medico->reg_medico)->count());
    }

    public function test_una_cedula_existente_con_otro_telefono_no_reserva(): void
    {
        $otro = Paciente::create([
            'nombres' => 'Otra',
            'apellidos' => 'Persona',
            'cedula' => '12345678',
            'telefono' => '0412-1112233',
        ]);

        $antes = Paciente::count();

        $this->from(route('reservas.medico', $this->medico))
            ->post(route('reservas.codigo.enviar', $this->medico), $this->datos('12345678', '0414-5556677'))
            ->assertRedirect(route('reservas.medico', $this->medico))
            ->assertSessionHasErrors('telefono');

        $this->assertSame(0, Cola::where('reg_medico', $this->medico->reg_medico)->count());
        $this->assertSame($antes, Paciente::count());
        $this->assertNull(session('reservas.pendiente'));
        $this->assertSame('0412-1112233', $otro->fresh()->telefono);
    }

    public function test_una_cedula_existente_con_el_mismo_telefono_enlaza_la_ficha(): void
    {
        $paciente = Paciente::create([
            'nombres' => 'Otra',
            'apellidos' => 'Persona',
            'cedula' => '12345678',
            'telefono' => '0412-1112233',
        ]);

        $antes = Paciente::count();

        $this->post(route('reservas.codigo.enviar', $this->medico), $this->datos('12345678', '0412-1112233'));
        $this->post(route('reservas.confirmar', $this->medico), ['codigo' => $this->codigo()])
            ->assertRedirect(route('reservas.confirmada'));

        $this->assertSame($antes, Paciente::count(), 'No se duplica la ficha.');
        $this->assertDatabaseHas('cola', [
            'reg_medico' => $this->medico->reg_medico,
            'paciente_sinhistoria_id' => $paciente->id,
        ]);
    }

    public function test_una_cita_pendiente_con_el_medico_no_se_duplica(): void
    {
        $paciente = Paciente::create([
            'nombres' => 'Ana',
            'apellidos' => 'Prueba',
            'cedula' => '12345678',
            'telefono' => '0414-5556677',
        ]);

        Cola::create([
            'reg_medico' => $this->medico->reg_medico,
            'fecha' => '2026-10-20',
            'hora_ini' => '08:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_NO_CONFIRMADA,
            'paciente_sinhistoria_id' => $paciente->id,
            'medical_center_id' => $this->sede->medical_center_id,
        ]);

        $this->post(route('reservas.codigo.enviar', $this->medico), $this->datos('12345678'));

        $this->post(route('reservas.confirmar', $this->medico), ['codigo' => $this->codigo()])
            ->assertRedirect(route('reservas.medico', $this->medico))
            ->assertSessionHas('error');

        $this->assertSame(1, Cola::where('paciente_sinhistoria_id', $paciente->id)->count());
        $this->assertNull(session('reservas.pendiente'));
    }

    public function test_si_el_horario_se_ocupo_no_se_crea_la_cita(): void
    {
        $this->post(route('reservas.codigo.enviar', $this->medico), $this->datos());

        // Otro paciente toma el horario ofrecido (la agenda del consultorio se puede sobrevender; el
        // portal no: al confirmar vuelve a preguntar cuál es el primer lugar).
        Cola::create([
            'reg_medico' => $this->medico->reg_medico,
            'fecha' => $this->fecha->toDateString(),
            'hora_ini' => '08:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA,
            'medical_center_id' => $this->sede->medical_center_id,
        ]);

        $this->post(route('reservas.confirmar', $this->medico), ['codigo' => $this->codigo()])
            ->assertRedirect(route('reservas.medico', $this->medico))
            ->assertSessionHas('error');

        $this->assertSame(1, Cola::where('reg_medico', $this->medico->reg_medico)->count());
    }

    public function test_el_horario_a_23_horas_no_se_ofrece_y_a_24_o_25_si(): void
    {
        $disp = app(\App\Reservas\DisponibilidadPublica::class);

        // Lunes 19 a las 08:00 Caracas (12:00 UTC). Caracas es UTC-4.
        Carbon::setTestNow(Carbon::create(2026, 10, 18, 13, 0, 0)); // 23 h antes
        $this->assertNull($disp->opcionEn($this->medico, '2026-10-19'));
        $this->assertNotContains('2026-10-19', array_map(fn ($o) => $o->fecha, $disp->opciones($this->medico)));

        Carbon::setTestNow(Carbon::create(2026, 10, 18, 12, 0, 0)); // 24 h exactas
        $this->assertNotNull($disp->opcionEn($this->medico, '2026-10-19'));
        $this->assertSame('2026-10-19', $disp->opciones($this->medico)[0]->fecha);

        Carbon::setTestNow(Carbon::create(2026, 10, 18, 11, 0, 0)); // 25 h
        $this->assertNotNull($disp->opcionEn($this->medico, '2026-10-19'));
    }

    public function test_la_lista_se_completa_con_dias_posteriores_hasta_la_cantidad_habitual(): void
    {
        $disp = app(\App\Reservas\DisponibilidadPublica::class);

        foreach ([2, 3, 4, 5] as $dia) { // martes a viernes, además del lunes del setUp
            OfficeSchedule::create([
                'office_id' => $this->sede->id,
                'reg_medico' => $this->medico->reg_medico,
                'dia_semana' => $dia,
                'hora_inicio' => '08:00',
                'hora_fin' => '12:00',
                'cupo' => 10,
            ]);
        }

        Carbon::setTestNow(Carbon::create(2026, 10, 19, 7, 0, 0)); // lunes 03:00 Caracas: el 19 a las 08:00 queda a 5 h
        $fechas = array_map(fn ($o) => $o->fecha, $disp->opciones($this->medico));

        $this->assertCount(5, $fechas);
        $this->assertNotContains('2026-10-19', $fechas);
        $this->assertSame('2026-10-20', $fechas[0]); // martes 08:00: a 29 h
    }

    public function test_confirmar_un_horario_de_menos_de_24_horas_es_rechazado(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 18, 13, 0, 0)); // 23 h antes del lunes 08:00

        $this->post(route('reservas.codigo.enviar', $this->medico), $this->datos())
            ->assertSessionHasErrors('fecha');

        $this->assertNull(session('reservas.pendiente'));
    }

    public function test_un_codigo_vencido_no_crea_la_cita(): void
    {
        $this->post(route('reservas.codigo.enviar', $this->medico), $this->datos());

        $pendiente = session('reservas.pendiente');
        $pendiente['expira'] = now()->subMinute()->timestamp;
        session(['reservas.pendiente' => $pendiente]);

        $this->from(route('reservas.codigo', $this->medico))
            ->post(route('reservas.confirmar', $this->medico), ['codigo' => $this->codigo()])
            ->assertRedirect(route('reservas.codigo', $this->medico))
            ->assertSessionHasErrors('codigo');

        $this->assertSame(0, Cola::where('reg_medico', $this->medico->reg_medico)->count());
        $this->assertNull(session('reservas.pendiente'));
    }

    public function test_un_telefono_invalido_no_pide_codigo(): void
    {
        $this->from(route('reservas.medico', $this->medico))
            ->post(route('reservas.codigo.enviar', $this->medico), $this->datos(null, '123'))
            ->assertRedirect(route('reservas.medico', $this->medico))
            ->assertSessionHasErrors('telefono');

        $this->assertNull(session('reservas.pendiente'));
        $this->assertSame(0, Cola::where('reg_medico', $this->medico->reg_medico)->count());
    }

    public function test_sin_reserva_en_curso_las_pantallas_vuelven_al_catalogo(): void
    {
        $this->get(route('reservas.codigo', $this->medico))
            ->assertRedirect(route('reservas.medico', $this->medico));

        $this->get(route('reservas.confirmada'))
            ->assertRedirect(route('reservas.catalogo'));
    }

    public function test_un_medico_inexistente_da_404(): void
    {
        $this->get('/reservar/999999')->assertNotFound();
    }
}
