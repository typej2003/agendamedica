<?php

namespace Tests\Feature;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Embarazo\Vistas\PestanasDelEmbarazo;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\PreNatalObservacion;
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
 * **El control prenatal en la consulta** (WEB-6.6, ítem 6 del panel del legado): la pestaña *Prenatal*.
 *
 * Lo que fijan estos casos:
 *
 *  - **El dato es de la historia, no de la consulta**: `pre_natal_observaciones` se llavea por `historia` +
 *    `gesta_clave`, y la consulta sólo aporta su `numhistoria`.
 *  - La **EG y la FPP se calculan de la FUR** (la FPP con los 270 días de esta pantalla, no con los 283 del
 *    eco obstétrico) y **no se guardan**.
 *  - **Nueva gesta**: el formulario sin `gesta` crea la siguiente, con el número que asigna el servidor.
 *  - El rastro por columna en `sync_changes` (`source = web`) y el rechazo de una gesta **sin FUR**.
 *  - El **404** de la consulta de otro consultorio, el **403** de la secretaría y el invitado al login.
 */
class ConsultaPrenatalWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        // 2026-10-13 menos 146 días (20 semanas y 6 días) es la FUR de las gestas de estos casos.
        $this->hoy = Carbon::parse('2026-10-13 09:00:00');
        Carbon::setTestNow($this->hoy->copy());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'prenatal-test-' . uniqid();

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'prenatal-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Prenatal',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function secretariaConAcceso(Medico $medico): User
    {
        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'prenatal-s-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
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

    /** @return array{0:int, 1:Consulta} */
    private function pacienteYConsulta(Medico $medico, int $numhistoria = 1001): array
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'F', 'fnacimiento' => '1990-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);

        MedicoPaciente::create([
            'medico_id' => $medico->id, 'paciente_id' => $pacienteId,
            'reg_medico' => $medico->reg_medico, 'numhistoria' => (string) $numhistoria,
        ]);

        Historia::create([
            'numhistoria' => (string) $numhistoria, 'reg_medico' => $medico->reg_medico,
            'paciente_id' => $pacienteId, 'medico_id' => $medico->id,
        ]);

        $consulta = Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => $numhistoria,
            'nroconsulta' => 1, 'fecha' => $this->hoy->toDateString(),
        ]);

        return [$pacienteId, $consulta];
    }

    private function urlSeccion(Consulta $consulta, string $extra = ''): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EMBARAZO, 'pestana' => PestanasDelEmbarazo::PRENATAL]) . $extra;
    }

    private function urlGuardar(Consulta $consulta): string
    {
        return route('clinica.consulta.embarazo.prenatal.guardar', $consulta->id);
    }

    /** @return array<string,string> */
    private function formulario(array $valores = []): array
    {
        $campos = ['gesta' => '', 'fur' => '', 'menarquia' => '', 'gestas' => '', 'parto' => '', 'tipiaje' => '', 'tipiaje_cony' => '', 'observaciones' => ''];

        return array_merge($campos, $valores);
    }

    /** La FUR de estos casos: 146 días antes de hoy, o sea 20 semanas y 6 días. */
    private function fur(): string
    {
        return $this->hoy->copy()->subDays(146)->toDateString();
    }

    // ------------------------------------------------------------------------------------- La sección

    public function test_la_pestana_abre_con_sus_campos_y_las_dos_cuentas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        // El panel ya no dice "Próximamente" de este ítem: pasó a 12 de 13.
        $respuesta->assertSee('12 de 13 disponibles');
        $respuesta->assertSee('Control de embarazo');

        // La pestaña y sus campos, con los rótulos del escritorio.
        $respuesta->assertSee('Control prenatal');
        $respuesta->assertSee('Prenatal');
        foreach (['FUR', 'Menarquia', 'Gestas', 'Parto', 'Tipiaje (madre)', 'Tipiaje (padre)', 'Observaciones'] as $rotulo) {
            $respuesta->assertSee($rotulo);
        }

        // Las dos cuentas: sin FUR no hay ninguna, y la pantalla lo dice.
        $respuesta->assertSee('Edad gestacional');
        $respuesta->assertSee('FPP');
        $respuesta->assertSee('Sin FUR');

        // Una historia sin gestas lo dice.
        $respuesta->assertSee('Esta historia todavía no tiene ninguna gesta');
        $respuesta->assertSee($this->urlGuardar($consulta), false);
    }

    public function test_el_item_6_del_panel_enlaza_a_la_seccion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $panel = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(route('clinica.consulta', $consulta->id));

        $panel->assertOk();
        $panel->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EMBARAZO]), false);
    }

    // ------------------------------------------------------------------------------------- El guardado

    public function test_guardar_crea_la_primera_gesta_y_muestra_sus_dos_cuentas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'fur' => $this->fur(),
            'menarquia' => '12 años',
            'gestas' => '2',
            'parto' => '1 cesárea',
            'tipiaje' => 'O+',
            'tipiaje_cony' => 'A+',
            'observaciones' => 'Embarazo en curso.',
        ]));

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('estado');

        $gesta = PreNatalObservacion::where('reg_medico', $medico->reg_medico)->where('historia', 1001)->first();

        $this->assertNotNull($gesta);
        $this->assertSame(1, $gesta->gesta_clave);
        $this->assertSame($this->fur(), $gesta->fur);
        $this->assertSame('12 años', $gesta->menarquia);
        $this->assertSame(2, $gesta->gestas);
        $this->assertSame('O+', $gesta->tipiaje);

        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'pre_natal_observaciones', 'record_id' => $gesta->id,
            'operation' => 'created', 'source' => 'web',
        ]);

        // La pestaña muestra la edad gestacional y la FPP de esa FUR (270 días, la de esta pantalla).
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('20 semanas y 6 días')
            ->assertSee('Gesta 1')
            // La ayuda de la FPP deja claro cuál de los dos números usa esta pantalla.
            ->assertSee('270 días');
    }

    public function test_nueva_gesta_crea_la_siguiente_con_el_numero_que_asigna_el_servidor(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => '2024-01-10',
        ]);

        // El formulario sin `gesta` (el que abre "Nueva gesta") crea la 2.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'fur' => $this->fur(), 'tipiaje' => 'B+',
        ]))->assertSessionHas('estado');

        $nueva = PreNatalObservacion::where('reg_medico', $medico->reg_medico)->where('gesta_clave', 2)->first();

        $this->assertNotNull($nueva);
        $this->assertSame('B+', $nueva->tipiaje);

        // Las dos gestas se ofrecen como enlaces, y la actual (la 2) es la que se muestra.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('Gesta 1')->assertSee('Gesta 2');
    }

    public function test_editar_una_gesta_corrige_solo_lo_que_cambio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $gesta = PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1,
            'fur' => $this->fur(), 'menarquia' => '12 años',
        ]);

        Carbon::setTestNow($this->hoy->copy()->addMinute());

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'gesta' => '1', 'fur' => $this->fur(), 'menarquia' => '13 años',
        ]))->assertSessionHas('estado');

        $this->assertSame('13 años', $gesta->fresh()->menarquia);
        $this->assertSame(1, SyncChange::where('table_name', 'pre_natal_observaciones')->where('column_name', 'menarquia')->count());
        // La FUR no se tocó (el formulario mandó la misma): ni rastro ni cambio.
        $this->assertSame(0, SyncChange::where('table_name', 'pre_natal_observaciones')->where('column_name', 'fur')->count());
        $this->assertSame($this->fur(), $gesta->fresh()->fur);
    }

    public function test_una_gesta_sin_fur_no_se_guarda_y_lo_dice(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // La FUR es obligatoria en el formulario: el error vuelve al campo.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'menarquia' => '12 años',
        ]))->assertSessionHasErrors('fur');

        $this->assertSame(0, PreNatalObservacion::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_la_gesta_se_mira_por_su_numero_en_la_url(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        PreNatalObservacion::create(['reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => '2024-01-10', 'tipiaje' => 'O+']);
        PreNatalObservacion::create(['reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 2, 'fur' => $this->fur(), 'tipiaje' => 'B+']);

        // Sin `gesta` se muestra la actual (la 2)…
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta))
            ->assertOk()->assertSee('value="' . $this->fur() . '"', false);

        // …y con `&gesta=1`, la primera (su FUR es la de 2024).
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, '&gesta=1'))
            ->assertOk()->assertSee('value="2024-01-10"', false);

        // Y `&nueva=1` abre el formulario en blanco, sin gesta que guardar todavía.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, '&nueva=1'))
            ->assertOk()->assertDontSee('value="' . $this->fur() . '"', false);
    }

    // -------------------------------------------------------------------------------------- Permisos

    public function test_la_secretaria_no_entra_a_la_seccion_ni_puede_guardar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $secretaria = $this->secretariaConAcceso($medico);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get($this->urlSeccion($consulta))->assertForbidden();

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post($this->urlGuardar($consulta), $this->formulario(['fur' => $this->fur()]))->assertForbidden();

        $this->assertSame(0, PreNatalObservacion::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_invitado_va_al_login(): void
    {
        [$user, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->post($this->urlGuardar($consulta), $this->formulario(['fur' => $this->fur()]))
            ->assertRedirect(route('login'));
    }

    public function test_la_consulta_de_otro_medico_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();

        $this->conContexto($otroUser, $otroMedico, $otraGineco)->actingAs($otroUser)
            ->get($this->urlSeccion($consulta))->assertNotFound();

        $this->conContexto($otroUser, $otroMedico, $otraGineco)->actingAs($otroUser)
            ->post($this->urlGuardar($consulta), $this->formulario(['fur' => $this->fur()]))->assertNotFound();
    }
}
