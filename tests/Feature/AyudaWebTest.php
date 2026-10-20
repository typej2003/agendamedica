<?php

namespace Tests\Feature;

use App\Models\Comentario;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **"Ayúdanos a mejorar"** en la web clínica (ROADMAP §9): el médico y la secretaría mandan comentarios —un
 * error, una sugerencia, algo que falta— y ven **los suyos**, con la respuesta cuando se la damos.
 *
 * La bandeja donde los recibimos nosotros es otra pantalla: el panel (`/admin/comentarios`).
 */
class AyudaWebTest extends TestCase
{
    use DatabaseTransactions;

    /** Un médico con su consultorio y su especialidad, como en el resto de las pruebas de la web. */
    private function medicoDePrueba(): array
    {
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $reg = 'ayuda-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Dr. de prueba', 'email' => "{$reg}@example.com",
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Ayuda',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico, $gineco];
    }

    /** La secretaría: sin ficha de médico y con el `reg_medico` asignado (por `medico_registros`). */
    private function secretariaDePrueba(Medico $medico): User
    {
        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'ayuda-secre-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        return $secretaria;
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id' => null,
        ]]);
    }

    public function test_la_pantalla_abre_con_el_formulario_y_sin_comentarios(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.ayuda'))
            ->assertOk()
            ->assertSee('Ayúdanos a mejorar')
            ->assertSee('Todavía no mandaste ningún comentario.');
    }

    public function test_el_medico_manda_un_comentario_y_queda_con_su_rol_y_su_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.ayuda.guardar'), [
                'tipo' => Comentario::BUG,
                'mensaje' => 'Cuando imprimo el récipe sale sin el sello.',
            ])
            ->assertRedirect(route('clinica.ayuda'));

        $comentario = Comentario::firstOrFail();
        $this->assertSame($user->id, $comentario->user_id);
        $this->assertSame($medico->id, $comentario->medico_id);
        $this->assertSame($medico->reg_medico, $comentario->reg_medico);
        $this->assertSame('Medico', $comentario->rol);
        $this->assertSame('web', $comentario->origen);
        $this->assertSame('web', $comentario->plataforma, 'de dónde salió, para la bandeja del panel');
        $this->assertSame(Comentario::NUEVO, $comentario->estado);
        $this->assertTrue($comentario->estaNuevo());

        // Y lo ve en sus comentarios, con el tipo legible.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.ayuda'))
            ->assertOk()
            ->assertSee('Un error')
            ->assertSee('Cuando imprimo el récipe sale sin el sello.')
            ->assertSee('Recibido');
    }

    public function test_la_plataforma_de_la_web_la_pone_el_servidor_y_no_el_formulario(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.ayuda.guardar'), [
                'tipo' => Comentario::BUG,
                'mensaje' => 'El botón de imprimir no hace nada.',
                // El formulario no manda la plataforma: si alguien la colara, se ignora.
                'plataforma' => 'android',
            ])
            ->assertRedirect(route('clinica.ayuda'));

        $this->assertSame('web', Comentario::firstOrFail()->plataforma);
    }

    public function test_la_secretaria_manda_el_suyo_y_no_ve_el_del_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();
        $secretaria = $this->secretariaDePrueba($medico);

        // El del médico, ya existente.
        Comentario::create([
            'user_id' => $user->id, 'medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico,
            'rol' => 'Medico', 'origen' => 'web', 'tipo' => Comentario::SUGERENCIA,
            'mensaje' => 'SE-CRETO-DEL-MEDICO', 'estado' => Comentario::NUEVO,
        ]);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post(route('clinica.ayuda.guardar'), [
                'tipo' => Comentario::CARENCIA,
                'mensaje' => 'Me falta ver la agenda de la semana en una sola pantalla.',
            ])
            ->assertRedirect(route('clinica.ayuda'));

        $this->assertSame(2, Comentario::count(), 'los dos comentarios quedaron guardados');
        $this->assertSame(
            'Secretaria',
            Comentario::where('mensaje', 'like', 'Me falta%')->firstOrFail()->rol,
        );

        // Cada uno ve el suyo: el del médico no aparece en la pantalla de la secretaría.
        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get(route('clinica.ayuda'))
            ->assertOk()
            ->assertSee('Me falta ver la agenda')
            ->assertDontSee('SE-CRETO-DEL-MEDICO');
    }

    public function test_el_comentario_necesita_tipo_y_mensaje(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.ayuda.guardar'), ['tipo' => 'no-existe', 'mensaje' => 'ok'])
            ->assertSessionHasErrors(['tipo', 'mensaje']);

        $this->assertSame(0, Comentario::count(), 'no se guardó nada a medias');
    }

    public function test_si_el_envio_vuelve_con_errores_avisa_que_las_capturas_se_perdieron(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();
        $sesion = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $sesion->from(route('clinica.ayuda'))
            ->post(route('clinica.ayuda.guardar'), ['tipo' => 'bug', 'mensaje' => '', 'con_capturas' => '1'])
            ->assertRedirect(route('clinica.ayuda'));

        $sesion->get(route('clinica.ayuda'))
            ->assertSee('Tus capturas no se conservaron');

        // Sin capturas el aviso no aparece: no es ruido en un error cualquiera.
        $this->flushSession();
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->from(route('clinica.ayuda'))
            ->post(route('clinica.ayuda.guardar'), ['tipo' => 'bug', 'mensaje' => '', 'con_capturas' => '0'])
            ->assertRedirect(route('clinica.ayuda'));
        $this->get(route('clinica.ayuda'))->assertDontSee('Tus capturas no se conservaron');
    }

    public function test_el_medico_ve_la_respuesta_y_el_descarte_se_explica(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();

        $respondido = Comentario::create([
            'user_id' => $user->id, 'medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico,
            'rol' => 'Medico', 'origen' => 'web', 'tipo' => Comentario::BUG,
            'mensaje' => 'El buscador de pacientes no encuentra por cédula.',
            'estado' => Comentario::RESPONDIDO, 'respuesta' => 'Ya lo arreglamos, actualizá el app.',
            'respondido_el' => now(),
        ]);
        Comentario::create([
            'user_id' => $user->id, 'medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico,
            'rol' => 'Medico', 'origen' => 'web', 'tipo' => Comentario::SUGERENCIA,
            'mensaje' => 'Podrían poner un modo oscuro.', 'estado' => Comentario::DESCARTADO,
        ]);

        $this->assertTrue($respondido->fresh()->fueRespondido());

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.ayuda'))
            ->assertOk()
            ->assertSee('Ya lo arreglamos, actualizá el app.')
            ->assertSee('Respondido')
            ->assertSee('Revisado')
            ->assertSee('por ahora no vamos a avanzar');
    }
}
