<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\MedicalCenter;
use App\Models\Medico;
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
 * La excepción de citas múltiples (WEB-2.17): la marca **del motivo** que hace que agendar dos veces al
 * mismo paciente en el mismo consultorio no pregunte, la pantalla que la administra
 * (`/clinica/catalogos/motivos-cita`, que absorbió la de WEB-8.1c) y la regla común de la cita única, que no cambia.
 *
 * La marca vive en `motivo_cita.admite_varias_citas_pendientes` (columna nullable nueva): `null` —lo que
 * traen las filas que ya existían y las que sube el escritorio— se comporta como `false`.
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class MotivosDeCitaWebTest extends TestCase
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
        $regMedico = 'motivos-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'motivos-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Motivos',
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
        return Office::create([
            'medical_center_id' => $centroId,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => $numero,
            'modalidad'         => Office::MODALIDAD_HORA,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);
    }

    private function bloque(Office $sede, string $ini, string $fin): OfficeSchedule
    {
        return OfficeSchedule::create([
            'office_id'   => $sede->id,
            'reg_medico'  => $sede->reg_medico,
            'dia_semana'  => 1,
            'hora_inicio' => $ini,
            'hora_fin'    => $fin,
            'cupo'        => null,
        ]);
    }

    private function pacienteConHistoria(Medico $medico, int $numhistoria, string $nombres, string $apellidos = 'Paciente'): int
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

        return $id;
    }

    private function cita(Medico $medico, Office $sede, string $fecha, string $hora, int $numorden, array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico'        => $medico->reg_medico,
            'fecha'             => $fecha,
            'hora_ini'          => $hora,
            'medical_center_id' => $sede->medical_center_id,
            'numorden'          => $numorden,
            'atendido'          => 0,
            'estado'            => Cola::ESTADO_NO_CONFIRMADA,
        ], $extra));
    }

    private function motivo(Medico $medico, string $codigo, array $extra = []): MotivoCita
    {
        return MotivoCita::create(array_merge([
            'reg_medico'   => $medico->reg_medico,
            'codigo'       => $codigo,
            'tipo_atencion' => $codigo === 'MASA' ? 'Masaje terapéutico' : 'Control',
        ], $extra));
    }

    /** Lo mínimo del formulario de "Nueva cita": la sede elegida en el paso 1 y el día. */
    private function datosDeCita(Office $sede, array $extra = []): array
    {
        return array_merge([
            'sede'  => $sede->id,
            'fecha' => $this->lunes->toDateString(),
        ], $extra);
    }

    // ------------------------------------------------------------------------ La pantalla de motivos

    public function test_un_invitado_no_entra(): void
    {
        $this->get('/clinica/catalogos/motivos-cita')->assertRedirect(route('login'));
    }

    public function test_sin_contexto_no_se_puede_marcar_motivos(): void
    {
        // El catálogo es de un médico: sin saber cuál, la pantalla no tiene sujeto.
        [$user] = $this->medicoConAcceso();

        $this->actingAs($user)->get('/clinica/catalogos/motivos-cita')->assertRedirect(route('clinica.contexto'));
    }

    public function test_la_pantalla_lista_los_motivos_del_medico_del_contexto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);

        // Uno global del médico (como los que sube el escritorio) y uno de una sede, con precio.
        $this->motivo($medico, 'ECO', ['tipo_atencion' => 'Ecografía', 'precio' => 120.0]);
        $this->motivo($medico, 'MASA', ['office_id' => $sede->id, 'precio' => 90.5]);
        // El de otro médico no se lista ni se puede tocar.
        [, $otro] = $this->medicoConAcceso();
        $this->motivo($otro, 'AJENO');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/catalogos/motivos-cita');

        $respuesta->assertOk();
        $respuesta->assertSee('Motivos de cita');
        $respuesta->assertSee('Ecografía');
        $respuesta->assertSee('ECO');
        $respuesta->assertSee('Todas las sedes');       // el motivo sin sede se ofrece en todas
        $respuesta->assertSee('120,00');
        $respuesta->assertSee('Masaje terapéutico');
        $respuesta->assertSee('San José');
        $respuesta->assertDontSee('AJENO');
        $respuesta->assertSee('2 motivos');
    }

    public function test_sin_motivos_la_pantalla_muestra_el_estado_vacio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/catalogos/motivos-cita');

        $respuesta->assertOk();
        $respuesta->assertSee('Todavía no tienes motivos de cita');
        $respuesta->assertSee('Crear el primer motivo');
    }

    public function test_la_agenda_lleva_a_la_pantalla_de_motivos(): void
    {
        // Es una herramienta de la agenda —como los días no laborables—, no un módulo del menú.
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString() . '&sede=todas');

        $respuesta->assertOk();
        $respuesta->assertSee(route('clinica.catalogos.motivos-cita'), false);
        $respuesta->assertSee('Motivos de cita');
    }

    // ------------------------------------------------------------------- Marcar y desmarcar la marca

    public function test_marcar_un_motivo_lo_deja_admitiendo_varias_citas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $motivo = $this->motivo($medico, 'MASA');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->patch('/clinica/catalogos/motivos-cita/' . $motivo->id . '/alternar');

        $respuesta->assertRedirect(route('clinica.catalogos.motivos-cita'));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'varias citas pendientes'));
        $this->assertTrue($motivo->refresh()->admite_varias_citas_pendientes);

        // Y la pantalla lo muestra marcado, con la acción para volver atrás.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/catalogos/motivos-cita')
            ->assertSee('Varias citas')
            ->assertSee('Volver a una sola');
    }

    public function test_desmarcar_el_motivo_lo_devuelve_a_la_regla_comun(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $motivo = $this->motivo($medico, 'MASA', ['admite_varias_citas_pendientes' => true]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->patch('/clinica/catalogos/motivos-cita/' . $motivo->id . '/alternar')
            ->assertRedirect(route('clinica.catalogos.motivos-cita'));

        // Desmarcar vuelve a dejar la columna en `null` (como las filas que nunca se marcaron), no en `false`.
        $this->assertNull($motivo->refresh()->admite_varias_citas_pendientes);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/catalogos/motivos-cita')
            ->assertSee('Una sola cita')
            ->assertSee('Admitir varias');
    }

    public function test_el_motivo_de_otro_medico_no_se_marca(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $ajeno = $this->motivo($otro, 'ECO', ['admite_varias_citas_pendientes' => false]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->patch('/clinica/catalogos/motivos-cita/' . $ajeno->id . '/alternar')
            ->assertNotFound();

        $this->assertFalse((bool) $ajeno->refresh()->admite_varias_citas_pendientes);
    }

    // ----------------------------------------------------- La regla: con la marca no se pregunta (criterio)

    public function test_con_el_motivo_marcado_agendar_dos_veces_no_pregunta_y_crea_la_segunda_cita(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);
        $this->bloque($sede, '08:00', '12:00');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');

        $this->motivo($medico, 'MASA', ['admite_varias_citas_pendientes' => true]);
        $this->cita($medico, $sede, Carbon::today()->addDay()->toDateString(), '09:00:00', 1, ['numhistoria' => 2001]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, [
                'hora'        => '10:00',
                'paciente_id' => $pacienteId,
                'motivo'      => 'MASA',
            ]));

        // No vuelve el formulario con la pregunta: va derecho a la agenda y la segunda cita existe.
        $respuesta->assertRedirect(route('clinica.agenda', [
            'vista' => 'dia',
            'fecha' => $this->lunes->toDateString(),
            'sede'  => $sede->id,
        ]));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'Cita agendada'));

        $citas = Cola::where('reg_medico', $medico->reg_medico)->orderBy('hora_ini')->get();
        $this->assertCount(2, $citas);
        $this->assertSame('10:00', substr($citas->last()->hora_ini, 0, 5));
        // La razón se sigue guardando donde la lee el escritorio (WEB-2.16), que es lo que la marca resuelve.
        $this->assertSame('MASA', $citas->last()->tipo);
    }

    public function test_sin_la_marca_agendar_dos_veces_pregunta_como_siempre(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);
        $this->bloque($sede, '08:00', '12:00');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');

        // Un motivo común del catálogo, sin la marca.
        $this->motivo($medico, 'CTRL');
        $this->cita($medico, $sede, Carbon::today()->addDay()->toDateString(), '09:00:00', 1, ['numhistoria' => 2001]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, [
                'hora'        => '10:00',
                'paciente_id' => $pacienteId,
                'motivo'      => 'CTRL',
            ]));

        // El comportamiento de WEB-2.2 no cambia: se pregunta y no se escribió nada.
        $respuesta->assertOk();
        $respuesta->assertSee('Ya tiene una cita pendiente');
        $respuesta->assertSee('Mover esa cita a la nueva fecha');
        $this->assertSame(1, Cola::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_sin_motivo_la_pregunta_sigue_apareciendo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);
        $this->bloque($sede, '08:00', '12:00');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');

        // Aunque el médico tenga motivos marcados, sin motivo elegido la regla común se aplica igual.
        $this->motivo($medico, 'MASA', ['admite_varias_citas_pendientes' => true]);
        $this->cita($medico, $sede, Carbon::today()->addDay()->toDateString(), '09:00:00', 1, ['numhistoria' => 2001]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, [
                'hora'        => '10:00',
                'paciente_id' => $pacienteId,
            ]))
            ->assertOk()
            ->assertSee('Ya tiene una cita pendiente');

        $this->assertSame(1, Cola::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_la_marca_de_otro_medico_no_cambia_nada(): void
    {
        // La marca se resuelve contra el catálogo del médico **del contexto**: el mismo código marcado en
        // el catálogo de otro no exime a este.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);
        $this->bloque($sede, '08:00', '12:00');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');

        [, $otro] = $this->medicoConAcceso();
        $this->motivo($otro, 'CTRL', ['admite_varias_citas_pendientes' => true]);
        $this->motivo($medico, 'CTRL');
        $this->cita($medico, $sede, Carbon::today()->addDay()->toDateString(), '09:00:00', 1, ['numhistoria' => 2001]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, [
                'hora'        => '10:00',
                'paciente_id' => $pacienteId,
                'motivo'      => 'CTRL',
            ]))
            ->assertOk()
            ->assertSee('Ya tiene una cita pendiente');
    }

    public function test_la_marca_es_la_de_la_fila_que_esa_sede_ofrece(): void
    {
        // El mismo `codigo` puede estar cargado dos veces —una por sede y otra sin sede— y el `<select>`
        // ofrece **una**: la de la sede gana. La marca que aplica es la de esa fila, no la de la otra.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $conMarca = $this->sede($medico, $this->centro('San José')->id, 'C1');
        $sinMarca = $this->sede($medico, $this->centro('Clínica Metropolitana')->id, 'C2');
        $this->bloque($conMarca, '08:00', '12:00');
        $this->bloque($sinMarca, '08:00', '12:00');

        $this->motivo($medico, 'ECO', ['office_id' => $conMarca->id, 'admite_varias_citas_pendientes' => true]);
        $this->motivo($medico, 'ECO', ['office_id' => $sinMarca->id, 'admite_varias_citas_pendientes' => false]);
        $this->motivo($medico, 'ECO', ['office_id' => null, 'admite_varias_citas_pendientes' => false]);
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');

        foreach ([[$conMarca, false], [$sinMarca, true]] as [$sede, $pregunta]) {
            Cola::where('reg_medico', $medico->reg_medico)->delete();
            $this->cita($medico, $sede, Carbon::today()->addDay()->toDateString(), '09:00:00', 1, ['numhistoria' => 2001]);

            $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
                ->post('/clinica/agenda', $this->datosDeCita($sede, [
                    'hora'        => '10:00',
                    'paciente_id' => $pacienteId,
                    'motivo'      => 'ECO',
                ]));

            if ($pregunta) {
                $respuesta->assertOk();
                $respuesta->assertSee('Ya tiene una cita pendiente');
                continue;
            }

            // La sede que ofrece la fila marcada: agenda directo y quedan las dos citas.
            $respuesta->assertRedirect();
            $this->assertSame(2, Cola::where('reg_medico', $medico->reg_medico)->count());
        }
    }

    // ------------------------------------------------------------------------- Lo que dice el formulario

    public function test_el_formulario_de_edicion_avisa_cuando_el_motivo_admite_varias(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);
        $this->bloque($sede, '08:00', '12:00');

        $this->motivo($medico, 'MASA', ['admite_varias_citas_pendientes' => true]);
        $this->motivo($medico, 'CTRL');

        $conMarca = $this->cita($medico, $sede, $this->lunes->toDateString(), '09:00:00', 1, ['tipo' => 'MASA']);
        $comun = $this->cita($medico, $sede, $this->lunes->toDateString(), '10:00:00', 2, ['tipo' => 'CTRL']);

        $conContexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        // Con el motivo marcado la pantalla lo dice: es lo que explica que no se pregunte.
        $conContexto->get('/clinica/agenda/' . $conMarca->id . '/editar')
            ->assertOk()
            ->assertSee('Admite varias citas pendientes del mismo paciente');

        // Con un motivo común no lo afirma.
        $conContexto->get('/clinica/agenda/' . $comun->id . '/editar')
            ->assertOk()
            ->assertDontSee('Admite varias citas pendientes');
    }

    public function test_la_pregunta_por_la_cita_pendiente_lleva_a_marcar_los_motivos(): void
    {
        // Es donde el usuario se entera de que la excepción se puede marcar, justo cuando le preguntan.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id);
        $this->bloque($sede, '08:00', '12:00');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');

        $this->motivo($medico, 'CTRL');
        $this->cita($medico, $sede, Carbon::today()->addDay()->toDateString(), '09:00:00', 1, ['numhistoria' => 2001]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, [
                'hora'        => '10:00',
                'paciente_id' => $pacienteId,
                'motivo'      => 'CTRL',
            ]));

        $respuesta->assertOk();
        $respuesta->assertSee('Ya tiene una cita pendiente');
        $respuesta->assertSee('márcalo en los motivos de cita');
        $respuesta->assertSee(route('clinica.catalogos.motivos-cita'), false);
        $respuesta->assertSee('Agendar otra de todos modos');
    }
}
