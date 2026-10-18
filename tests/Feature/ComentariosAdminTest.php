<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Comentarios;
use App\Models\Comentario;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La **bandeja de "Ayúdanos a mejorar"** (`/admin/comentarios`, ROADMAP §9): donde recibimos lo que nos mandan
 * desde el consultorio, lo respondemos o lo descartamos, con el filtro para ver lo que falta y lo ya
 * atendido.
 *
 * Es del panel (`role:Root`), y el permiso se comprueba además en cada acción de Livewire: la secretaría y el
 * médico no entran.
 */
class ComentariosAdminTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol): User
    {
        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'comentarios-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    /** Un comentario con el estado y el texto que se le pidan. */
    private function comentario(string $mensaje, string $estado = Comentario::NUEVO, ?User $autor = null): Comentario
    {
        return Comentario::create([
            'user_id' => $autor?->id,
            'rol' => $autor?->getRoleNames()->first(),
            'origen' => 'web',
            'tipo' => Comentario::BUG,
            'mensaje' => $mensaje,
            'estado' => $estado,
            'respuesta' => $estado === Comentario::RESPONDIDO ? 'Ya lo arreglamos.' : null,
            'respondido_el' => $estado === Comentario::RESPONDIDO ? now() : null,
        ]);
    }

    public function test_la_bandeja_abre_en_los_nuevos_y_no_mezcla_los_ya_atendidos(): void
    {
        $autor = $this->usuario('Medico');
        $this->comentario('MENSAJE-SIN-RESPONDER', Comentario::NUEVO, $autor);
        $this->comentario('MENSAJE-YA-RESPONDIDO', Comentario::RESPONDIDO, $autor);
        $this->comentario('MENSAJE-DESCARTADO', Comentario::DESCARTADO, $autor);

        $this->actingAs($this->usuario('Root'))
            ->get('/admin/comentarios')
            ->assertOk()
            ->assertSee('MENSAJE-SIN-RESPONDER')
            ->assertDontSee('MENSAJE-YA-RESPONDIDO')
            ->assertDontSee('MENSAJE-DESCARTADO');
    }

    public function test_el_filtro_por_estado_cambia_lo_que_se_ve(): void
    {
        $autor = $this->usuario('Medico');
        $this->comentario('MENSAJE-SIN-RESPONDER', Comentario::NUEVO, $autor);
        $this->comentario('MENSAJE-YA-RESPONDIDO', Comentario::RESPONDIDO, $autor);
        $this->comentario('MENSAJE-DESCARTADO', Comentario::DESCARTADO, $autor);

        $this->actingAs($this->usuario('Root'));

        Livewire::test(Comentarios::class)
            ->assertSet('estado', Comentario::NUEVO)
            ->assertSee('MENSAJE-SIN-RESPONDER')
            ->set('estado', Comentario::RESPONDIDO)
            ->assertSee('MENSAJE-YA-RESPONDIDO')
            ->assertDontSee('MENSAJE-SIN-RESPONDER')
            ->set('estado', Comentario::DESCARTADO)
            ->assertSee('MENSAJE-DESCARTADO')
            ->set('estado', 'todos')
            ->assertSee('MENSAJE-SIN-RESPONDER')
            ->assertSee('MENSAJE-YA-RESPONDIDO')
            ->assertSee('MENSAJE-DESCARTADO');
    }

    public function test_responder_deja_el_comentario_respondido_con_quien_y_cuando(): void
    {
        $autor = $this->usuario('Medico');
        $comentario = $this->comentario('El récipe sale sin el sello.', Comentario::NUEVO, $autor);
        $root = $this->usuario('Root');

        $this->actingAs($root);

        Livewire::test(Comentarios::class)
            ->call('abrir', $comentario->id)
            ->assertSet('comentarioId', $comentario->id)
            ->set('respuesta', 'Actualizá el app: eso ya está corregido.')
            ->call('responder')
            ->assertHasNoErrors();

        $comentario->refresh();
        $this->assertSame(Comentario::RESPONDIDO, $comentario->estado);
        $this->assertTrue($comentario->fueRespondido());
        $this->assertSame('Actualizá el app: eso ya está corregido.', $comentario->respuesta);
        $this->assertSame($root->id, $comentario->respondido_por);
        $this->assertNotNull($comentario->respondido_el);

        // Que el usuario vea la respuesta en su pantalla se prueba en AyudaWebTest: acá no hay contexto
    }

    public function test_la_respuesta_necesita_texto(): void
    {
        $comentario = $this->comentario('Algo que reportar.');

        $this->actingAs($this->usuario('Root'));

        Livewire::test(Comentarios::class)
            ->call('abrir', $comentario->id)
            ->set('respuesta', 'ok')
            ->call('responder')
            ->assertHasErrors(['respuesta']);

        $this->assertTrue($comentario->fresh()->estaNuevo(), 'sin respuesta válida no se toca el comentario');
    }

    public function test_descartar_lo_saca_de_los_nuevos_pero_lo_guarda(): void
    {
        $comentario = $this->comentario('Podrían poner un modo oscuro.');

        $this->actingAs($this->usuario('Root'));

        Livewire::test(Comentarios::class)->call('descartar', $comentario->id);

        $comentario->refresh();
        $this->assertSame(Comentario::DESCARTADO, $comentario->estado);
        $this->assertTrue($comentario->fueDescartado());
        $this->assertNull($comentario->respuesta, 'un descartado no lleva respuesta');
        // No se borra: es el registro de lo que nos dijeron y de que lo atendimos.
        $this->assertDatabaseHas('comentarios', ['id' => $comentario->id, 'estado' => Comentario::DESCARTADO]);
    }

    public function test_se_puede_reabrir_uno_atendido(): void
    {
        $comentario = $this->comentario('MENSAJE-YA-RESPONDIDO', Comentario::RESPONDIDO);

        $this->actingAs($this->usuario('Root'));

        Livewire::test(Comentarios::class)->call('reabrir', $comentario->id);

        $comentario->refresh();
        $this->assertTrue($comentario->estaNuevo());
        $this->assertNull($comentario->respuesta);
        $this->assertNull($comentario->respondido_el);
    }

    public function test_la_secretaria_y_el_medico_no_entran_a_la_bandeja(): void
    {
        $this->comentario('Un comentario cualquiera.');

        foreach (['Secretaria', 'Medico'] as $rol) {
            $this->actingAs($this->usuario($rol))->get('/admin/comentarios')->assertForbidden();
        }
    }
}
