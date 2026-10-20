<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Citas del paciente" (`/clinica/pacientes/{paciente}/citas`, WEB-2.15): el botón "Cita x Paciente" del
 * escritorio (`w_hacer_cita.srw:1039` → `w_listado_pacientes_a_citas`).
 *
 * Se prueba con `DatabaseTransactions`, como el resto de los tests de la web clínica: que el listado sea
 * sólo del paciente y del `reg_medico` del contexto, que un paciente ajeno dé 404, que el cambio de
 * cédula aplique la regla de la fuente (incluido el caso "ya existe") y que desde la agenda se llegue.
 */
class CitasDelPacienteWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'citas-pac-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'citas-pac-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dra. de prueba',
            'lastname'   => 'Citas',
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

    /** Un paciente con su vínculo con el médico; `$numhistoria` nulo = paciente sin historia. */
    private function paciente(
        ?Medico $medico,
        string $nombres,
        string $apellidos = 'Apellido',
        string $cedula = '',
        ?int $numhistoria = null,
        string $regMedico = ''
    ): int {
        $id = DB::table('pacientes')->insertGetId([
            'nombres'    => $nombres,
            'apellidos'  => $apellidos,
            'cedula'     => $cedula !== '' ? $cedula : null,
            'sexo'       => 'F',
            'fnacimiento'=> '1990-05-04',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($medico !== null) {
            DB::table('medico_pacientes')->insert([
                'medico_id'   => $medico->id,
                'paciente_id' => $id,
                'numhistoria' => $numhistoria,
                'reg_medico'  => $regMedico !== '' ? $regMedico : $medico->reg_medico,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        return $id;
    }

    private function cita(Medico $medico, string $fecha, string $hora, array $extra = []): int
    {
        return DB::table('cola')->insertGetId(array_merge([
            'reg_medico'  => $medico->reg_medico,
            'fecha'       => $fecha,
            'hora_ini'    => $hora,
            'numorden'    => 1,
            'atendido'    => 0,
            'estado'      => 0,
            'monto'       => null,
            'monto_pagado'=> null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ], $extra));
    }

    // ------------------------------------------------------------------------------------ El listado

    public function test_lista_todas_las_citas_del_paciente_de_la_mas_reciente_a_la_mas_vieja(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $paciente = $this->paciente($medico, 'Ana', 'Pérez', '12345678', 1001);
        $este = $this->cita($medico, '2026-10-05', '08:30:00', ['numhistoria' => 1001, 'estado' => 0]);
        $futura = $this->cita($medico, '2026-11-20', '10:00:00', ['numhistoria' => 1001, 'estado' => 1, 'monto' => 50, 'monto_pagado' => 50]);
        $pasada = $this->cita($medico, '2026-08-01', '09:00:00', ['numhistoria' => 1001, 'atendido' => 1]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $paciente . '/citas');

        $respuesta->assertOk();
        $respuesta->assertSee('Pérez, Ana');
        $respuesta->assertSee('Historia N.º 1001');
        $respuesta->assertSee('Cambiar la cédula');

        // Los cuatro estados de la cita, en palabras.
        $respuesta->assertSee('Por atender');
        $respuesta->assertSee('Confirmada');
        $respuesta->assertSee('Atendida');
        $respuesta->assertSee('Pagado');

        // El orden es por fecha descendente: la más reciente primero.
        $html = $respuesta->getContent();
        $this->assertGreaterThan(strpos($html, '20/11/2026'), strpos($html, '05/10/2026'), 'la futura va primero');
        $this->assertGreaterThan(strpos($html, '05/10/2026'), strpos($html, '01/08/2026'), 'la atendida va última');

        // Y cada fila trae sus acciones: modificar siempre, eliminar sólo donde la regla lo permite.
        $respuesta->assertSee('Modificar');
        $this->assertStringContainsString(route('clinica.agenda.editar', $este), $html);
        $this->assertStringContainsString(route('clinica.pacientes.citas.eliminar', ['paciente' => $paciente, 'cola' => $este]), $html);
        $this->assertStringNotContainsString(route('clinica.pacientes.citas.eliminar', ['paciente' => $paciente, 'cola' => $pasada]), $html);
        $this->assertStringNotContainsString(route('clinica.pacientes.citas.eliminar', ['paciente' => $paciente, 'cola' => $futura]), $html);
    }

    public function test_la_movida_por_el_escritorio_se_muestra_marcada_y_sin_acciones(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $paciente = $this->paciente($medico, 'Lucía', 'Movida', '22333444', 1002);
        $movida = $this->cita($medico, '2026-09-10', '11:00:00', ['numhistoria' => 1002, 'movida_escritorio' => 1]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $paciente . '/citas');

        $respuesta->assertOk();
        $respuesta->assertSee('Movida');
        $respuesta->assertSee('La cita se pasó a otro día');
        // La fuente la escondía (`atendido <> 2`); acá se ve, pero no se toca.
        $this->assertStringNotContainsString(route('clinica.agenda.editar', $movida), $respuesta->getContent());
    }

    public function test_un_paciente_de_otro_registro_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        // Sin vínculo con este médico: la ficha no se devuelve.
        $ajeno = $this->paciente(null, 'Ajeno', 'Deotromedico', '33444555', null);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $ajeno . '/citas')
            ->assertNotFound();

        // Y el cambio de cédula tampoco se puede: no es un paciente del contexto.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/pacientes/' . $ajeno . '/cedula', ['cedula' => '99999999'])
            ->assertNotFound();
    }

    public function test_las_citas_de_otro_medico_no_aparecen_aunque_el_numero_de_historia_coincida(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();

        $paciente = $this->paciente($medico, 'Ana', 'Pérez', '12345678', 1003);
        $propia = $this->cita($medico, '2026-10-05', '08:30:00', ['numhistoria' => 1003]);
        // Mismo `numhistoria`, otro registro: es de otro médico y no se puede ver desde acá.
        $this->cita($otro, '2026-10-06', '09:30:00', ['numhistoria' => 1003, 'motivo' => 'AJENA']);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $paciente . '/citas');

        $respuesta->assertOk();
        $this->assertStringContainsString(route('clinica.agenda.editar', $propia), $respuesta->getContent());
        $respuesta->assertDontSee('AJENA');
    }

    public function test_una_cita_de_un_paciente_sin_historia_tambien_se_lista(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        // El vínculo existe con `numhistoria` nulo: la cita se ancla por `paciente_sinhistoria_id`.
        $paciente = $this->paciente($medico, 'Sin', 'Historia', '44555666', null);
        $this->cita($medico, '2026-10-05', '08:30:00', ['numhistoria' => null, 'paciente_sinhistoria_id' => $paciente]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $paciente . '/citas');

        $respuesta->assertOk();
        $respuesta->assertSee('Todavía sin número de historia');
        $respuesta->assertSee('Por atender');
    }

    public function test_sin_citas_la_pantalla_lo_dice(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Sin', 'Citas', '55667788', 1004);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $paciente . '/citas')
            ->assertOk()
            ->assertSee('Todavía no tiene citas')
            ->assertSee('No tiene citas registradas');
    }

    // ---------------------------------------------------------------------------- Cambio de cédula

    public function test_el_cambio_de_cedula_se_guarda_y_avisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Ana', 'Pérez', '12345678', 1005);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/pacientes/' . $paciente . '/cedula', ['cedula' => '87654321'])
            ->assertRedirect(route('clinica.pacientes.citas', $paciente))
            ->assertSessionHas('estado', 'Cédula actualizada a 87654321.');

        $this->assertSame('87654321', DB::table('pacientes')->where('id', $paciente)->value('cedula'));
    }

    public function test_una_cedula_que_ya_es_de_otro_paciente_se_rechaza(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Ana', 'Pérez', '12345678', 1006);
        $this->paciente($medico, 'Otra', 'Persona', '99887766', 1007);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->from('/clinica/pacientes/' . $paciente . '/citas')
            ->post('/clinica/pacientes/' . $paciente . '/cedula', ['cedula' => '99887766'])
            ->assertRedirect('/clinica/pacientes/' . $paciente . '/citas')
            ->assertSessionHasErrors('cedula');

        // Sin cambios: la cédula del paciente sigue siendo la suya.
        $this->assertSame('12345678', DB::table('pacientes')->where('id', $paciente)->value('cedula'));
    }

    public function test_una_cedula_vacia_o_con_letras_se_rechaza(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Ana', 'Pérez', '12345678', 1008);

        foreach (['', 'V-12345678', '12345678901'] as $invalida) {
            $this->conContexto($user, $medico, $gineco)->actingAs($user)
                ->from('/clinica/pacientes/' . $paciente . '/citas')
                ->post('/clinica/pacientes/' . $paciente . '/cedula', ['cedula' => $invalida])
                ->assertSessionHasErrors('cedula');
        }

        $this->assertSame('12345678', DB::table('pacientes')->where('id', $paciente)->value('cedula'));
    }

    public function test_cambiar_la_cedula_por_la_misma_avisa_y_no_escribe(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Ana', 'Pérez', '12345678', 1009);
        $antes = DB::table('pacientes')->where('id', $paciente)->value('updated_at');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->from('/clinica/pacientes/' . $paciente . '/citas')
            ->post('/clinica/pacientes/' . $paciente . '/cedula', ['cedula' => '12345678'])
            ->assertSessionHasErrors('cedula');

        $this->assertSame($antes, DB::table('pacientes')->where('id', $paciente)->value('updated_at'));
    }

    public function test_sin_numero_de_historia_el_boton_de_la_cedula_esta_deshabilitado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Sin', 'Historia', '66778899', null);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $paciente . '/citas');

        $respuesta->assertOk();
        $respuesta->assertSee('Todavía no tiene número de historia: el cambio de cédula');
        // El botón está, pero no es el que abre el diálogo (`id="abrir-cedula"`): el script no lo engancha.
        $respuesta->assertSee('Cambiar la cédula');
        $this->assertStringNotContainsString('id="abrir-cedula"', $respuesta->getContent());
    }

    // -------------------------------------------------------------------------------------- Eliminar

    public function test_eliminar_una_cita_desde_la_pantalla_la_borra_y_vuelve_a_la_misma_pantalla(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Ana', 'Pérez', '12345678', 1010);
        $cita = $this->cita($medico, '2026-10-05', '08:30:00', ['numhistoria' => 1010]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.citas.eliminar', ['paciente' => $paciente, 'cola' => $cita]))
            ->assertRedirect(route('clinica.pacientes.citas', $paciente))
            ->assertSessionHas('estado', 'Cita eliminada.');

        $this->assertNull(DB::table('cola')->where('id', $cita)->first());
    }

    public function test_una_cita_con_pago_no_se_elimina_y_lo_dice(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Ana', 'Pérez', '12345678', 1011);
        $cita = $this->cita($medico, '2026-10-05', '08:30:00', ['numhistoria' => 1011, 'monto' => 50, 'monto_pagado' => 20]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.citas.eliminar', ['paciente' => $paciente, 'cola' => $cita]))
            ->assertRedirect(route('clinica.pacientes.citas', $paciente))
            ->assertSessionHas('error');

        $this->assertNotNull(DB::table('cola')->where('id', $cita)->first());
    }

    public function test_no_se_puede_eliminar_la_cita_de_otro_paciente_desde_esta_pantalla(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Ana', 'Pérez', '12345678', 1012);
        $otro = $this->paciente($medico, 'Otra', 'Persona', '99887766', 1013);
        $citaAjena = $this->cita($medico, '2026-10-05', '08:30:00', ['numhistoria' => 1013]);

        // Se pasa el id del paciente propio con la cita del otro: 404 y la cita sigue existiendo.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.citas.eliminar', ['paciente' => $paciente, 'cola' => $citaAjena]))
            ->assertNotFound();

        $this->assertNotNull(DB::table('cola')->where('id', $citaAjena)->first());
    }

    // ------------------------------------------------------------------- El punto de entrada (agenda)

    public function test_desde_la_agenda_se_llega_a_las_citas_del_paciente(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Ana', 'Pérez', '12345678', 1014);
        $hoy = now()->toDateString();
        $this->cita($medico, $hoy, '08:30:00', ['numhistoria' => 1014]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=dia&fecha=' . $hoy . '&sede=todas');

        $respuesta->assertOk();
        $respuesta->assertSee('Citas del paciente');
        $this->assertStringContainsString(
            route('clinica.pacientes.citas', $paciente),
            $respuesta->getContent()
        );
    }

    public function test_una_cita_sin_ficha_de_paciente_no_ofrece_el_enlace(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $hoy = now()->toDateString();
        // Cita de un `numhistoria` que no está en el pivote del médico: no hay a qué ficha ir.
        $this->cita($medico, $hoy, '08:30:00', ['numhistoria' => 9999]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=dia&fecha=' . $hoy . '&sede=todas')
            ->assertOk()
            ->assertDontSee('Citas del paciente');
    }
}
