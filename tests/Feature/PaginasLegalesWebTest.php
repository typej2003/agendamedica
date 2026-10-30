<?php

namespace Tests\Feature;

use App\Mail\ConfirmacionDeBajaDeCuenta;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\SolicitudDeBaja;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * **Las páginas legales públicas y la baja de cuenta** (2026-10-30).
 *
 * Google Play exige que la política de privacidad y la página de eliminación de cuenta se abran **sin
 * sesión**, y que la eliminación sea un procedimiento real. Acá se prueba lo que se promete: el pedido,
 * la confirmación por correo, el plazo de 30 días, las dos formas de cancelar y la ejecución.
 */
class PaginasLegalesWebTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $sufijo = substr(uniqid(), -6);
        $regMedico = 'baja-' . $sufijo;

        $user = User::create([
            'name'                 => 'Dra. Baja',
            'email'                => 'baja-' . $sufijo . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dra.',
            'lastname'   => 'Baja',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico, 'user_id' => $user->id]);

        DB::table('consultas')->insert([
            'reg_medico'  => $regMedico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
        ]);

        return [$user, $medico, $regMedico];
    }

    // ------------------------------------------------------------------ Las páginas

    public function test_las_tres_paginas_legales_se_abren_sin_sesion(): void
    {
        foreach (['/privacidad', '/terminos', '/eliminar-cuenta'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_la_politica_y_los_terminos_muestran_al_responsable(): void
    {
        foreach (['/privacidad', '/terminos'] as $url) {
            $respuesta = $this->get($url);

            $respuesta->assertOk();
            $respuesta->assertSee('DDR Sistemas, C.A.');
            $respuesta->assertSee('J-31512955-8');
            $respuesta->assertSee('ddrsistemas@gmail.com');
        }
    }

    /**
     * Los tres puntos que pide la ficha de Google Play: el nombre de la app y del desarrollador, los pasos
     * para pedir el borrado y qué datos se borran o se conservan.
     */
    public function test_la_pagina_de_baja_dice_la_app_el_desarrollador_los_pasos_y_que_se_borra(): void
    {
        $respuesta = $this->get('/eliminar-cuenta');

        $respuesta->assertOk();
        $respuesta->assertSee('Doctorísimo');
        $respuesta->assertSee('DDR Sistemas, C.A.');
        $respuesta->assertSee('Cómo pedirla, paso a paso');
        $respuesta->assertSee('Qué se elimina y qué se conserva');
        $respuesta->assertSee('30 días');
        $respuesta->assertSee('historias clínicas');
    }

    // ------------------------------------------------------------------ El pedido

    public function test_un_correo_que_no_es_de_una_cuenta_no_crea_nada_y_responde_igual(): void
    {
        Mail::fake();

        $this->post('/eliminar-cuenta', ['email' => 'nadie@example.com'])
            ->assertRedirect(route('legal.baja.formulario'))
            ->assertSessionHas('baja_enviada', true);

        $this->assertDatabaseCount('solicitudes_de_baja', 0);
        Mail::assertNothingSent();
    }

    public function test_pedir_la_baja_crea_una_solicitud_pendiente_y_manda_el_correo(): void
    {
        Mail::fake();
        [$user] = $this->medico();

        $this->post('/eliminar-cuenta', ['email' => mb_strtoupper($user->email)])
            ->assertRedirect(route('legal.baja.formulario'));

        $solicitud = SolicitudDeBaja::where('user_id', $user->id)->firstOrFail();

        $this->assertSame(SolicitudDeBaja::PENDIENTE, $solicitud->estado);
        $this->assertNull($solicitud->ejecutar_despues_de);
        Mail::assertSent(ConfirmacionDeBajaDeCuenta::class);
    }

    /** Un pedido nuevo invalida el anterior: vale el último enlace que llegó al correo. */
    public function test_un_pedido_nuevo_cancela_el_anterior(): void
    {
        Mail::fake();
        [$user] = $this->medico();

        $this->post('/eliminar-cuenta', ['email' => $user->email]);
        $primera = SolicitudDeBaja::where('user_id', $user->id)->firstOrFail();

        $this->post('/eliminar-cuenta', ['email' => $user->email]);

        $this->assertSame(SolicitudDeBaja::CANCELADA, $primera->refresh()->estado);
        $this->assertSame(1, SolicitudDeBaja::where('user_id', $user->id)->where('estado', SolicitudDeBaja::PENDIENTE)->count());
    }

    // ------------------------------------------------------------------ Confirmar y cancelar

    public function test_confirmar_programa_la_baja_a_treinta_dias(): void
    {
        [$user] = $this->medico();
        $solicitud = SolicitudDeBaja::create([
            'user_id' => $user->id, 'email' => $user->email, 'token' => str_repeat('a', 64),
            'estado'  => SolicitudDeBaja::PENDIENTE,
        ]);

        $this->get('/eliminar-cuenta/confirmar/' . $solicitud->token)->assertOk();

        $solicitud->refresh();
        $this->assertSame(SolicitudDeBaja::PROGRAMADA, $solicitud->estado);
        $this->assertNotNull($solicitud->confirmada_en);
        $this->assertTrue($solicitud->ejecutar_despues_de->between(now()->addDays(29), now()->addDays(31)));
    }

    public function test_un_token_inventado_no_confirma_nada(): void
    {
        $this->get('/eliminar-cuenta/confirmar/' . str_repeat('b', 64))
            ->assertOk()
            ->assertSee('Este enlace no sirve');
    }

    public function test_el_enlace_del_correo_cancela_la_baja(): void
    {
        [$user] = $this->medico();
        $solicitud = SolicitudDeBaja::create([
            'user_id' => $user->id, 'email' => $user->email, 'token' => str_repeat('c', 64),
            'estado'  => SolicitudDeBaja::PROGRAMADA, 'confirmada_en' => now(),
            'ejecutar_despues_de' => now()->addDays(30),
        ]);

        $this->post('/eliminar-cuenta/cancelar/' . $solicitud->token)->assertOk();

        $solicitud->refresh();
        $this->assertSame(SolicitudDeBaja::CANCELADA, $solicitud->estado);
        $this->assertSame(SolicitudDeBaja::MOTIVO_ENLACE, $solicitud->motivo);
    }

    /** **Iniciar sesión cancela la baja programada** (decisión del usuario): el que vuelve, se queda. */
    public function test_iniciar_sesion_cancela_la_baja_programada(): void
    {
        [$user] = $this->medico();
        $solicitud = SolicitudDeBaja::create([
            'user_id' => $user->id, 'email' => $user->email, 'token' => str_repeat('d', 64),
            'estado'  => SolicitudDeBaja::PROGRAMADA, 'confirmada_en' => now(),
            'ejecutar_despues_de' => now()->addDays(30),
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'secreto123']);

        $this->assertAuthenticated();
        $this->assertSame(SolicitudDeBaja::CANCELADA, $solicitud->refresh()->estado);
        $this->assertSame(SolicitudDeBaja::MOTIVO_INICIO_DE_SESION, $solicitud->motivo);
    }

    // ------------------------------------------------------------------ La ejecución

    public function test_el_comando_simula_por_defecto_y_solo_borra_con_ejecutar(): void
    {
        [$user, $medico, $regMedico] = $this->medico();
        $solicitud = SolicitudDeBaja::create([
            'user_id' => $user->id, 'email' => $user->email, 'token' => str_repeat('e', 64),
            'estado'  => SolicitudDeBaja::PROGRAMADA, 'confirmada_en' => now()->subDays(31),
            'ejecutar_despues_de' => now()->subDay(),
        ]);

        $this->artisan('cuentas:ejecutar-bajas')->assertSuccessful();

        // La simulación no toca nada.
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertSame(SolicitudDeBaja::PROGRAMADA, $solicitud->refresh()->estado);

        $this->artisan('cuentas:ejecutar-bajas', ['--ejecutar' => true])->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('medicos', ['id' => $medico->id]);
        $this->assertDatabaseMissing('medico_registros', ['medico_id' => $medico->id]);
        $this->assertSame(SolicitudDeBaja::EJECUTADA, $solicitud->refresh()->estado);

        // **Lo clínico no se toca**: las consultas del registro quedan (decisión del usuario, ver `BajasDeCuenta`).
        $this->assertSame(1, DB::table('consultas')->where('reg_medico', $regMedico)->count());
    }

    public function test_una_baja_cancelada_no_se_ejecuta(): void
    {
        [$user] = $this->medico();
        SolicitudDeBaja::create([
            'user_id' => $user->id, 'email' => $user->email, 'token' => str_repeat('f', 64),
            'estado'  => SolicitudDeBaja::CANCELADA, 'cancelada_en' => now(),
            'ejecutar_despues_de' => now()->subDay(),
        ]);

        $this->artisan('cuentas:ejecutar-bajas', ['--ejecutar' => true])->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
