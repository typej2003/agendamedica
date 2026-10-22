<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaSesion;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El candado suave de la consulta** («abierta en otro dispositivo»): `POST /clinica/consulta/{id}/sesion`.
 *
 * Es un aviso, no un bloqueo: lo que se prueba es la regla de la Action `RegistrarSesionDeConsulta` (una fila vigente
 * por consulta, ventana de 3 minutos, «tomar el control») y que la ruta sea sólo del médico del consultorio.
 */
class ConsultaSesionWebTest extends TestCase
{
    use DatabaseTransactions;

    private const DISP_A = 'aaaaaaaa-1111-2222-3333-444444444444';
    private const DISP_B = 'bbbbbbbb-1111-2222-3333-444444444444';

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
        $regMedico = 'candado-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'candado-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Candado',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    private function consultaDe(Medico $medico, int $numhistoria): Consulta
    {
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
            'numhistoria' => (string) $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'paciente_id' => $pacienteId,
            'medico_id'   => $medico->id,
        ]);

        return Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'nroconsulta' => 1,
            'fecha'       => now()->toDateString(),
        ]);
    }

    private function registrar(User $user, Medico $medico, Specialty $gineco, Consulta $consulta, string $dispositivo, bool $tomar = false)
    {
        return $this->conContexto($medico, $gineco)->actingAs($user)
            ->postJson(route('clinica.consulta.sesion', $consulta->id), ['dispositivo_id' => $dispositivo, 'tomar' => $tomar]);
    }

    public function test_abrir_la_consulta_registra_el_candado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consultaDe($medico, 5001);

        $this->registrar($user, $medico, $gineco, $consulta, self::DISP_A)
            ->assertOk()->assertJsonPath('estado', 'ok');

        $fila = ConsultaSesion::where('consulta_id', $consulta->id)->firstOrFail();
        $this->assertSame(self::DISP_A, $fila->dispositivo_id);
        $this->assertSame($medico->reg_medico, $fila->reg_medico);
        $this->assertSame($user->id, (int) $fila->user_id);
        $this->assertNotNull($fila->abierta_en);
        $this->assertNotNull($fila->ultima_actividad_en);
    }

    public function test_el_mismo_dispositivo_renueva_sin_conflicto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consultaDe($medico, 5002);

        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00:00'));
        $this->registrar($user, $medico, $gineco, $consulta, self::DISP_A)->assertJsonPath('estado', 'ok');

        Carbon::setTestNow(Carbon::parse('2026-10-22 10:01:00'));
        $this->registrar($user, $medico, $gineco, $consulta, self::DISP_A)->assertOk()->assertJsonPath('estado', 'ok');

        $this->assertSame(1, ConsultaSesion::where('consulta_id', $consulta->id)->count());
        $fila = ConsultaSesion::where('consulta_id', $consulta->id)->firstOrFail();
        $this->assertSame('2026-10-22 10:01:00', $fila->ultima_actividad_en->format('Y-m-d H:i:s'));
        // Sigue siendo la misma apertura.
        $this->assertSame('2026-10-22 10:00:00', $fila->abierta_en->format('Y-m-d H:i:s'));
    }

    public function test_otro_dispositivo_dentro_de_la_ventana_recibe_conflicto_y_no_cambia_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consultaDe($medico, 5003);

        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00:00'));
        $this->registrar($user, $medico, $gineco, $consulta, self::DISP_A);

        Carbon::setTestNow(Carbon::parse('2026-10-22 10:02:00'));
        $this->registrar($user, $medico, $gineco, $consulta, self::DISP_B)
            ->assertOk()->assertJsonPath('estado', 'conflicto');

        $this->assertSame(self::DISP_A, ConsultaSesion::where('consulta_id', $consulta->id)->value('dispositivo_id'));
    }

    public function test_tomar_el_control_desplaza_al_dispositivo_anterior(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consultaDe($medico, 5004);

        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00:00'));
        $this->registrar($user, $medico, $gineco, $consulta, self::DISP_A);

        Carbon::setTestNow(Carbon::parse('2026-10-22 10:01:00'));
        $this->registrar($user, $medico, $gineco, $consulta, self::DISP_B, true)
            ->assertOk()->assertJsonPath('estado', 'ok');

        $this->assertSame(self::DISP_B, ConsultaSesion::where('consulta_id', $consulta->id)->value('dispositivo_id'));

        // El anterior lo descubre en su siguiente latido.
        Carbon::setTestNow(Carbon::parse('2026-10-22 10:02:00'));
        $this->registrar($user, $medico, $gineco, $consulta, self::DISP_A)->assertJsonPath('estado', 'conflicto');
    }

    public function test_un_candado_vencido_deja_entrar_sin_preguntar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consultaDe($medico, 5005);

        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00:00'));
        $this->registrar($user, $medico, $gineco, $consulta, self::DISP_A);

        // Pasaron más de 3 minutos sin latidos.
        Carbon::setTestNow(Carbon::parse('2026-10-22 10:03:30'));
        $this->registrar($user, $medico, $gineco, $consulta, self::DISP_B)
            ->assertOk()->assertJsonPath('estado', 'ok');

        $this->assertSame(self::DISP_B, ConsultaSesion::where('consulta_id', $consulta->id)->value('dispositivo_id'));
    }

    public function test_una_consulta_de_otro_consultorio_es_404_y_no_deja_candado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $ajena = $this->consultaDe($otro, 5006);

        $this->registrar($user, $medico, $gineco, $ajena, self::DISP_A)->assertNotFound();

        $this->assertSame(0, ConsultaSesion::where('consulta_id', $ajena->id)->count());
    }

    public function test_la_secretaria_no_puede_registrar_el_candado(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consultaDe($medico, 5007);

        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'candado-secre-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $this->registrar($secretaria, $medico, $gineco, $consulta, self::DISP_A)->assertForbidden();

        $this->assertSame(0, ConsultaSesion::where('consulta_id', $consulta->id)->count());
    }

    public function test_el_dispositivo_debe_ser_un_identificador_valido(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consultaDe($medico, 5008);

        $this->registrar($user, $medico, $gineco, $consulta, 'corto')->assertStatus(422);
        $this->registrar($user, $medico, $gineco, $consulta, 'con espacios y <script>')->assertStatus(422);
    }

    public function test_terminar_la_consulta_libera_el_candado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consultaDe($medico, 5009);

        $this->registrar($user, $medico, $gineco, $consulta, self::DISP_A);
        $this->assertSame(1, ConsultaSesion::where('consulta_id', $consulta->id)->count());

        $this->conContexto($medico, $gineco)->actingAs($user)->post(route('clinica.consulta.cerrar', $consulta->id));

        $this->assertSame(0, ConsultaSesion::where('consulta_id', $consulta->id)->count());
    }

    public function test_la_pantalla_declara_el_candado_y_su_dialogo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consultaDe($medico, 5010);

        $this->conContexto($medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', $consulta->id))
            ->assertOk()
            ->assertSee('js/clinica/consulta-candado.js', false)
            ->assertSee('data-candado-url="' . route('clinica.consulta.sesion', $consulta->id) . '"', false)
            ->assertSee('La consulta está activa en otro dispositivo. Si editas aquí podrías perder datos.', false);
    }
}
